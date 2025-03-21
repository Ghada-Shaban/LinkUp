<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

// ✅ جلب بيانات المستخدم المسجل الدخول
Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

// ✅ المسارات الخاصة بالمصادقة (تسجيل - تسجيل دخول - خروج)
Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
    Route::middleware('auth:sanctum')->post('/logout', [AuthController::class, 'logout']);
});

// ✅ المسارات الخاصة بإعادة تعيين كلمة المرور
Route::prefix('password')->group(function () {
    Route::post('/forgot', [\App\Http\Controllers\Api\PasswordResetController::class, 'sendOtp']); // إرسال OTP
    Route::post('/verify-otp', [\App\Http\Controllers\Api\PasswordResetController::class, 'verifyOtp']); // التحقق من OTP
    Route::post('/reset', [\App\Http\Controllers\Api\PasswordResetController::class, 'resetPassword']); // إعادة تعيين كلمة المرور
});