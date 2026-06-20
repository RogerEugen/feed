<?php
use App\Http\Controllers\FeedbackController;
use App\Http\Controllers\TrackingController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\EvaluationWindowController;
use App\Http\Controllers\CourseEvaluationController;
use App\Http\Controllers\EvaluationAnalyticsController;
use App\Http\Controllers\CommunicationController;

// ── Submit feedback (anonymous token required) ─────────────────
Route::post('/feedback/submit',   [FeedbackController::class, 'submit']);
Route::post('/feedback/suggestions', [FeedbackController::class, 'suggestResolutions']);
Route::get('/feedback/recurring-groups', [FeedbackController::class, 'recurringGroups']);

// ── Track feedback by code (no auth needed) ────────────────────
Route::get('/feedback/track/{code}', [TrackingController::class, 'track']);
Route::post('/feedback/followup',    [TrackingController::class, 'followup']);
Route::get('/feedback/lecturer-thread/{code}', [TrackingController::class, 'lecturerThread']);

Route::post('/communications/overview', [CommunicationController::class, 'overview']);
Route::get('/communications/{room}', [CommunicationController::class, 'index']);
Route::post('/communications/{room}', [CommunicationController::class, 'store']);
Route::post('/communications/{room}/read', [CommunicationController::class, 'markRead']);

// ── HOD endpoints ──────────────────────────────────────────────
Route::prefix('hod')->group(function () {
    Route::get('/feedbacks',                   [FeedbackController::class, 'hodList']);
    Route::get('/feedbacks/{id}',              [FeedbackController::class, 'show']);
    Route::post('/feedbacks/{id}/respond',     [FeedbackController::class, 'respond']);
    Route::post('/feedbacks/{id}/escalate',    [FeedbackController::class, 'escalate']);
    Route::post('/feedbacks/{id}/resolve',     [FeedbackController::class, 'resolve']);
});

// ── Dean endpoints ─────────────────────────────────────────────
Route::prefix('dean')->group(function () {
    Route::get('/feedbacks',                   [FeedbackController::class, 'deanList']);
    Route::get('/feedbacks/{id}',              [FeedbackController::class, 'show']);
    Route::post('/feedbacks/{id}/respond',     [FeedbackController::class, 'respond']);
    Route::post('/feedbacks/{id}/escalate',    [FeedbackController::class, 'escalate']);
    Route::post('/feedbacks/{id}/resolve',     [FeedbackController::class, 'resolve']);
});

// ── Rector endpoints ───────────────────────────────────────────
Route::prefix('rector')->group(function () {
    Route::get('/reports/feedback', [FeedbackController::class, 'rectorReport']);
    Route::get('/lecturer-threads', [TrackingController::class, 'rectorThreads']);
    Route::get('/lecturer-threads/{code}', [TrackingController::class, 'rectorThread']);
    Route::post('/lecturer-threads/{code}/reply', [TrackingController::class, 'rectorReply']);
    Route::get('/feedbacks',    [FeedbackController::class, 'rectorList']);
    Route::get('/feedbacks/{id}', [FeedbackController::class, 'show']);
    Route::post('/feedbacks/{id}/respond',    [FeedbackController::class, 'respond']);
    Route::post('/feedbacks/{id}/resolve', [FeedbackController::class, 'resolve']);
});


// ── Evaluation Windows ─────────────────────────────────────────
Route::prefix('evaluation-windows')->group(function () {
    Route::get('/',           [EvaluationWindowController::class, 'index']);
    Route::get('/active',     [EvaluationWindowController::class, 'active']);
    Route::post('/',          [EvaluationWindowController::class, 'store']);
    Route::post('/{id}/toggle', [EvaluationWindowController::class, 'toggle']);
    Route::delete('/{id}',    [EvaluationWindowController::class, 'destroy']);
});

// ── Course Evaluations ─────────────────────────────────────────
Route::prefix('evaluations')->group(function () {
    Route::post('/submit',             [CourseEvaluationController::class, 'submit']);
    Route::get('/check',               [CourseEvaluationController::class, 'checkSubmitted']);
    Route::get('/course',              [CourseEvaluationController::class, 'courseResults']);
    Route::get('/department',          [CourseEvaluationController::class, 'departmentResults']);
    Route::get('/faculty',             [CourseEvaluationController::class, 'facultyResults']);
    Route::get('/lecturer',            [CourseEvaluationController::class, 'lecturerResults']); // ✅ NEW
});

// ── Evaluation Analytics (Rector/Admin) ───────────────────────
Route::prefix('analytics')->group(function () {
    Route::get('/overview', [EvaluationAnalyticsController::class, 'systemOverview']);
    Route::get('/by-faculty', [EvaluationAnalyticsController::class, 'byFaculty']);
    Route::get('/trends',    [EvaluationAnalyticsController::class, 'trends']);
});

// ── Categories (public — needed for form) ──────────────────────
Route::get('/categories', [FeedbackController::class, 'categories']);
Route::post('/categories', [FeedbackController::class, 'storeCategory']);
Route::put('/categories/{id}', [FeedbackController::class, 'updateCategory']);
Route::delete('/categories/{id}', [FeedbackController::class, 'deleteCategory']);

// Get lecturers from auth service — proxied through feedback service
Route::get('/lecturers/{departmentId}', [CourseEvaluationController::class, 'getLecturers']);
