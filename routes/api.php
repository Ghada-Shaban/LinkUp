<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\EnumController;
use App\Http\Controllers\Api\CoachServiceController;/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});
// Route::post('/coach/set-availability', [AuthController::class, 'setAvailability']);
// Route::post('/register', [AuthController::class, 'register']);
// Route::post('/login', [AuthController::class, 'login']);
Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
    Route::middleware('auth:sanctum')->post('/logout', [AuthController::class, 'logout']);
    Route::get('/Trainee-values', [AuthController::class, 'getTraineeRegistrationEnumValues']);
    Route::get('/Coach-values', [AuthController::class, 'getCoachRegistrationEnumValues']);
    // Route::post('/coach/set-availability', [AuthController::class, 'setAvailability']);
});



Route::prefix('password')->group(function () {
    Route::post('/forgot', [\App\Http\Controllers\Api\PasswordResetController::class, 'sendOtp']); // إرسال OTP
    Route::post('/verify-otp', [\App\Http\Controllers\Api\PasswordResetController::class, 'verifyOtp']); // التحقق من OTP
    Route::post('/reset', [\App\Http\Controllers\Api\PasswordResetController::class, 'resetPassword']); // إعادة تعيين كلمة المرور
});

Route::get('service-enums', [CoachServiceController::class, 'getEnums']);
Route::prefix('coach/{coachId}')->middleware(['auth:api', 'check.coach.ownership'])->group(function () {
    
    
    // Route لجلب الخدمات بناءً على service_type
    Route::get('services', [EnumController::class, 'getServiceEnums']);

    // باقي الـ Routes
    Route::get('services/count', [CoachServiceController::class, 'getServicesCount']);
    Route::post('services', [CoachServiceController::class, 'createService']);
    Route::put('services/{serviceId}', [CoachServiceController::class, 'updateService']);
    Route::delete('services/{serviceId}', [CoachServiceController::class, 'deleteService']);
    Route::post('group-mentorship/{groupMentorshipId}/join', [CoachServiceController::class, 'joinGroupMentorship']);
});
