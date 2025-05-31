<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\EnumController;
use App\Http\Controllers\Api\NewSessionController;
use App\Http\Controllers\Api\MentorshipRequestController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\CoachServiceController;
use App\Http\Controllers\Api\ReviewController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\CoachController;
use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\BookingController;
use App\Http\Controllers\Api\PerformanceReportController;
use App\Http\Controllers\Api\CoachDashboardController;
use App\Http\Controllers\Api\TraineeDashboardController;
use App\Http\Controllers\Api\MentorshipPlanController;
use App\Http\Controllers\Api\LandingPageReviewController;
use App\Http\Controllers\Api\LandingPageCoachController; // إضافة الكونترولر الجديد

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
    Route::post('/forgot', [\App\Http\Controllers\Api\PasswordResetController::class, 'sendOtp']);
    Route::post('/verify-otp', [\App\Http\Controllers\Api\PasswordResetController::class, 'verifyOtp']);
    Route::post('/reset', [\App\Http\Controllers\Api\PasswordResetController::class, 'resetPassword']);
});

Route::get('service/enums', [EnumController::class, 'getServiceEnums']);

Route::prefix('coach/{coachId}')->middleware(['auth:api', 'check.coach.ownership'])->group(function () {
    Route::get('services/count', [CoachServiceController::class, 'getServicesCount']);
    Route::post('services', [CoachServiceController::class, 'createService']);
    Route::put('services/{serviceId}', [CoachServiceController::class, 'updateService']);
    Route::delete('services/{serviceId}', [CoachServiceController::class, 'deleteService']);
    Route::get('/reviews', [ReviewController::class, 'show']);
});

Route::prefix('coach/{coachId}')->middleware(['auth:api', 'check.trainee'])->group(function () {
    Route::post('group-mentorship/{groupMentorshipId}/join', [CoachServiceController::class, 'joinGroupMentorship']);
    Route::get('available-dates', [BookingController::class, 'getAvailableDates']);
    Route::get('available-slots', [BookingController::class, 'getAvailableSlots']);
    Route::post('book', [BookingController::class, 'bookService']);
});

// Routes الخاصة بـ NewSessionController
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/sessions', [NewSessionController::class, 'index'])->name('sessions.index');
    Route::post('/sessions/update-meeting-link/{sessionId}', [NewSessionController::class, 'updateMeetingLink']);
    Route::post('/sessions/{sessionId}/complete', [NewSessionController::class, 'completeSession']);
    Route::post('/sessions/{sessionId}/cancel', [NewSessionController::class, 'cancelSession']);
});

// Routes الخاصة بـ MentorshipRequestController
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/trainee/mentorship-requests', [MentorshipRequestController::class, 'requestMentorship']);
    Route::get('/traineerequest', [MentorshipRequestController::class, 'traineegetrequest']);
    Route::get('/coach/requests', [MentorshipRequestController::class, 'viewRequests']);
    Route::post('/coach/requests/{id}/accept', [MentorshipRequestController::class, 'acceptRequest']);
    Route::post('/coach/requests/{id}/reject', [MentorshipRequestController::class, 'rejectRequest']);
    Route::post('/trainee/reviews', [ReviewController::class, 'store']);
});

// Routes الخاصة بـ MentorshipPlanController
Route::prefix('mentorship-plan/{coachId}')->middleware(['auth:sanctum', 'check.trainee'])->group(function () {
    Route::get('/available-dates', [MentorshipPlanController::class, 'getAvailableDates']);
    Route::get('/available-slots', [MentorshipPlanController::class, 'getAvailableSlots']);
    Route::post('/book', [MentorshipPlanController::class, 'bookMentorshipPlan']);
});

// Payment routes for all types of services
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/payment/initiate/{type}/{id}', [PaymentController::class, 'initiatePayment']);
    Route::post('/payment/confirm/{type}/{id}', [PaymentController::class, 'confirmPayment']);
});

Route::get('coach/{coachId}/services', [CoachServiceController::class, 'getServices']);
Route::get('/coachprofile/{user_id}', [ProfileController::class, 'getCoachProfile2']);

// Route الخاصة بـ Explore Coaches (All coaches, requires authentication)
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/coaches/explore', [CoachController::class, 'exploreCoaches']);
});

// Route الخاصة بـ Explore Services (Filtered by service type, no authentication)
Route::get('/coaches/explore-services', [CoachController::class, 'exploreServices']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/coach/profile/update/{user_id}', [ProfileController::class, 'updateCoachProfile']);
    Route::post('/trainee/profile/update/{user_id}', [ProfileController::class, 'updateTraineeProfile']);
    Route::get('/coach/profile/{user_id}', [ProfileController::class, 'getCoachProfile']);
    Route::get('/trainee/profile/{user_id}', [ProfileController::class, 'getTraineeProfile']);
});

// Routes for Admin
Route::prefix('admin')->group(function () {
    Route::get('/coach-requests', [AdminController::class, 'getPendingCoachRequests']);
    Route::post('/coach-requests/{coachId}/handle', [AdminController::class, 'handleCoachRequest']);
    Route::get('/top-coaches', [AdminController::class, 'getTopCoaches']);
    Route::get('/Approved-coaches', [AdminController::class, 'getApprovedCoaches']);
    Route::get('/Approved-coaches-count', [AdminController::class, 'getApprovedCoachesCount']);
    Route::get('/Pending-coaches-count', [AdminController::class, 'getPendingCoachesCount']);
    Route::get('/DashboardStats', [AdminController::class, 'getDashboardStats']);
    Route::get('/trainees', [AdminController::class, 'getAllTrainees']);
    Route::get('/trainees-count', [AdminController::class, 'getTraineesCount']);
    Route::get('/session-trends', [AdminController::class, 'getSessionCompletionTrends']);
    Route::delete('/delete-users/{userId}', [AdminController::class, 'deleteUser']);
    Route::get('/trainees/search', [AdminController::class, 'searchTrainees']);
    Route::get('/coaches/search', [AdminController::class, 'searchCoaches']);
});

// Routes for performance reports
Route::group(['prefix' => 'performance-reports', 'middleware' => 'auth:api'], function () {
    Route::post('/sessions/{sessionId}/submit', [PerformanceReportController::class, 'submitPerformanceReport']);
    Route::get('/trainee', [PerformanceReportController::class, 'getPerformanceReports']); 
});

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/coach-dashboard/stats', [CoachDashboardController::class, 'getCoachDashboardStats']);
});

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/trainee-dashboard/stats', [TraineeDashboardController::class, 'getTraineeDashboardStats']);
});

// الراوت الجديد لـ Landing Page Reviews (بدون أي middleware عشان يكون متاح للجميع)
Route::get('/landing-reviews', [\App\Http\Controllers\Api\LandingPageReviewController::class, 'getReviews']);

// الراوت الجديد لـ Explore Coaches في الـ Landing Page (بدون Middleware)
Route::get('/landing-explore-coaches', [\App\Http\Controllers\Api\LandingPageCoachController::class, 'exploreCoaches']);
