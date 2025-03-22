<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Hash, Mail, Validator, DB, Log};
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Mail\ResetPasswordMail;
use Carbon\Carbon;

class PasswordResetController extends Controller
{
    // 1️⃣ إرسال OTP عبر البريد الإلكتروني
    public function sendOtp(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email|exists:users,email',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 422);
        }

        // منع الطلبات المتكررة بسرعة
        $recentOtp = DB::table('password_resets')
            ->where('email', $request->email)
            ->where('created_at', '>', Carbon::now()->subMinutes(1))
            ->first();

        if ($recentOtp) {
            return response()->json(['error' => 'You can request a new OTP after 1 minute.'], 429);
        }

        $otp = random_int(100000, 999999);

        // حذف أي OTP سابق
        DB::table('password_resets')->where('email', $request->email)->delete();

        // حفظ OTP في قاعدة البيانات
        DB::table('password_resets')->insert([
            'email' => $request->email,
            'token' => $otp,
            'created_at' => Carbon::now(),
            'expires_at' => Carbon::now()->addMinutes(10), // ضبط تاريخ الانتهاء
        ]);

        try {
            Mail::to($request->email)->send(new ResetPasswordMail($otp));
            Log::info('OTP sent to: ' . $request->email);
        } catch (\Exception $e) {
            Log::error('Failed to send OTP email: ' . $e->getMessage());
            return response()->json(['error' => 'Failed to send email. Please try again later.'], 500);
        }

        return response()->json(['message' => 'OTP sent successfully'], 200);
    }

    // 2️⃣ التحقق من صحة OTP
    public function verifyOtp(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
            'otp' => 'required|numeric',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 422);
        }

        // التحقق من صلاحية OTP
        $otpRecord = DB::table('password_resets')
            ->where('email', $request->email)
            ->where('token', $request->otp)
            ->where('expires_at', '>=', Carbon::now()) // التحقق من تاريخ الانتهاء
            ->first();

        if (!$otpRecord) {
            return response()->json(['error' => 'Invalid or expired OTP'], 422);
        }

        return response()->json(['message' => 'OTP is valid'], 200);
    }

    // 3️⃣ إعادة تعيين كلمة المرور
    public function resetPassword(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email|exists:users,email',
            'password' => 'required|min:8|confirmed',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 422);
        }

        $user = User::where('email', $request->email)->first();
        if (!$user) {
            return response()->json(['error' => 'User not found'], 404);
        }

        // تحديث كلمة المرور
        $user->password = Hash::make($request->password);
        $user->save();

        // حذف سجل OTP
        DB::table('password_resets')->where('email', $request->email)->delete();

        Log::info('Password reset for: ' . $request->email);

        return response()->json(['message' => 'Password has been reset successfully'], 200);
    }
}