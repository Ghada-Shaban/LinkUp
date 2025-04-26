<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\EnumController;
use App\Http\Controllers\Api\NewSessionController;
use App\Http\Controllers\Api\MentorshipRequestController;
use App\Http\Controllers\Api\CoachServiceController;
use App\Http\Controllers\Api\ReviewController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\CoachController;
use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\BookingController; // إضافة الـ BookingController

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

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
    Route::middleware('auth:sanctum')->post('/logout', [AuthController::class, 'logout']);
    Route::get('/Trainee-values', [AuthController::class, 'getTraineeRegistrationEnumValues']);
    Route::get('/Coach-values', [AuthController::class, 'getCoachRegistrationEnumValues']);
});

Route::prefix('password')->group(function () {
    Route::post('/forgot', [\App\Http\Controllers\Api\PasswordResetController::class, 'sendOtp']); // إرسال OTP
    Route::post('/verify-otp', [\App\Http\Controllers\Api\PasswordResetController::class, 'verifyOtp']); // التحقق من OTP
    Route::post('/reset', [\App\Http\Controllers\Api\PasswordResetController::class, 'resetPassword']); // إعادة تعيين كلمة المرور
});

Route::get('service/enums', [EnumController::class, 'getServiceEnums']);

Route::prefix('coach/{coachId}')->middleware(['auth:api', 'check.coach.ownership'])->group(function () {
    // باقي الـ Routes
    Route::get('services/count', [CoachServiceController::class, 'getServicesCount']);
    Route::post('services', [CoachServiceController::class, 'createService']);
    Route::put('services/{serviceId}', [CoachServiceController::class, 'updateService']);
    Route::delete('services/{serviceId}', [CoachServiceController::class, 'deleteService']);
    // reviews
    Route::get('/reviews', [ReviewController::class, 'show']);
});

Route::prefix('coach/{coachId}')->middleware(['auth:api', 'check.trainee'])->group(function () {
    Route::post('group-mentorship/{groupMentorshipId}/join', [CoachServiceController::class, 'joinGroupMentorship']);
    
    // الـ Routes الجديدة لـ BookingController
    Route::get('available-dates', [BookingController::class, 'getAvailableDates']);
    Route::get('available-slots', [BookingController::class, 'getAvailableSlots']);
});

// Routes الخاصة بـ NewSessionController
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/sessions', [NewSessionController::class, 'index'])->name('sessions.index'); // تم تعديل الاسم من /upcoming-sessions لدعم كل الأنواع
    Route::post('/sessions/update-meeting-link/{sessionId}', [NewSessionController::class, 'updateMeetingLink']);
    Route::post('/sessions/{sessionId}/complete', [NewSessionController::class, 'completeSession']);
    Route::post('/sessions/{sessionId}/cancel', [NewSessionController::class, 'cancelSession']);
});

// Routes الخاصة بـ MentorshipRequestController
Route::middleware('auth:sanctum')->group(function () {
    // Trainee routes
    Route::prefix('trainee')->group(function () {
        Route::post('/mentorship-requests', [MentorshipRequestController::class, 'create']);
        Route::get('/mentorship-requests', [MentorshipRequestController::class, 'traineeIndex']);

        // make review
        Route::post('/reviews', [ReviewController::class, 'store']);
    });

    // Coach routes
    Route::prefix('coach')->group(function () {
        Route::get('/mentorship-requests/pending', [MentorshipRequestController::class, 'coachPendingRequests']);
        Route::post('/mentorship-requests/{id}/accept', [MentorshipRequestController::class, 'acceptRequest']);
        Route::post('/mentorship-requests/{id}/reject', [MentorshipRequestController::class, 'rejectRequest']);
    });
});

// Route لجلب الخدمات بناءً على service_type
Route::get('coach/{coachId}/services', [CoachServiceController::class, 'getServices']);
// Get Coach profile for all
Route::get('/coachprofile/{user_id}', [ProfileController::class, 'getCoachProfile2']);

// Route الخاصة بـ Explore Coaches
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/coaches/explore', [CoachController::class, 'exploreCoaches']);
});

Route::middleware('auth:sanctum')->group(function () {
    // Update profile (Coach & Trainee)
    Route::put('/coach/profile/update/{user_id}', [ProfileController::class, 'updateCoachProfile']);
    Route::put('/trainee/profile/update/{user_id}', [ProfileController::class, 'updateTraineeProfile']);

    // Get Coach profile
    Route::get('/coach/profile/{user_id}', [ProfileController::class, 'getCoachProfile']);

    // Get Trainee profile
    Route::get('/trainee/profile/{user_id}', [ProfileController::class, 'getTraineeProfile']);
});
//admin
Route::get('/admin/coach-requests', [AdminController::class, 'getPendingCoachRequests']);
Route::post('/admin/coach-requests/{coachId}/handle', [AdminController::class, 'handleCoachRequest']);
//updated requests path
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/mentorship-request', [MentorshipRequestController::class, 'requestMentorship']);
    Route::get('/traineerequest', [MentorshipRequestController::class, 'traineegetrequest']);
    Route::get('/coach/requests', [MentorshipRequestController::class, 'viewRequests']);
    Route::post('/coach/requests/{id}/accept', [MentorshipRequestController::class, 'acceptRequest']);
    Route::post('/coach/requests/{id}/reject', [MentorshipRequestController::class, 'rejectRequest']);
    Route::post('/mentorship-requests/{id}/schedule', [MentorshipRequestController::class, 'scheduleSessions']);
    Route::post('/mentorship-requests/{id}/initiate-payment', [MentorshipRequestController::class, 'initiatePayment']);
    Route::get('/mentorship-requests/complete-payment', [MentorshipRequestController::class, 'completePayment']);
});
