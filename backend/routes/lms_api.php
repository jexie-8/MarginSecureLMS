<?php

use App\Http\Controllers\Api\AssignmentController;
use App\Http\Controllers\Api\CourseController;
use App\Http\Controllers\Api\CourseStaffController;
use App\Http\Controllers\Api\DepartmentController;
use App\Http\Controllers\Api\EnrollmentController;
use App\Http\Controllers\Api\GradeController;
use App\Http\Controllers\Api\PasswordController;
use App\Http\Controllers\Api\PasswordResetController;
use App\Http\Controllers\Api\ProgramController;
use App\Http\Controllers\Api\SubmissionController;
use App\Http\Controllers\Api\UniversityController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\LearningToolsController;
use App\Http\Controllers\Api\ProfileDataController;
use App\Http\Controllers\Api\MfaController;
use App\Http\Middleware\EnsureAccountIsActive;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->name('margin.')->group(function (): void {
    Route::post('/forgot-password', [PasswordResetController::class, 'requestLink'])
        ->middleware('throttle:password-reset')->name('password.email');
    Route::post('/reset-password', [PasswordResetController::class, 'reset'])
        ->middleware('throttle:password-reset')->name('password.reset');

    Route::middleware(['auth:web', EnsureAccountIsActive::class, 'throttle:margin-api'])->group(function (): void {
        Route::apiResource('universities', UniversityController::class)->only(['index', 'store', 'show', 'update']);
        Route::apiResource('departments', DepartmentController::class)->only(['index', 'store', 'show', 'update']);
        Route::apiResource('programs', ProgramController::class)->only(['index', 'store', 'show', 'update']);
        Route::apiResource('courses', CourseController::class)->only(['index', 'store', 'show', 'update']);
        Route::apiResource('users', UserController::class)->only(['index', 'store', 'show', 'update']);
        Route::get('/enrollments', [EnrollmentController::class, 'index'])->name('enrollments.index');
        Route::post('/enrollments', [EnrollmentController::class, 'store'])->name('enrollments.store');
        Route::patch('/enrollments/{enrollment}', [EnrollmentController::class, 'update'])->name('enrollments.update');
        Route::get('/course-staff', [CourseStaffController::class, 'index'])->name('course_staff.index');
        Route::post('/course-staff', [CourseStaffController::class, 'store'])->name('course_staff.store');
        Route::delete('/course-staff/{courseStaff}', [CourseStaffController::class, 'destroy'])->name('course_staff.destroy');
        Route::apiResource('assignments', AssignmentController::class)->only(['index', 'store', 'show', 'update']);
        Route::post('/assignments/{assignment}/publish', [AssignmentController::class, 'publish'])->name('assignments.publish');
        Route::get('/submissions', [SubmissionController::class, 'index'])->name('submissions.index');
        Route::get('/submissions/{submission}', [SubmissionController::class, 'show'])->name('submissions.show');
        Route::post('/assignments/{assignment}/submissions', [SubmissionController::class, 'store'])->name('submissions.store');
        Route::get('/grades', [GradeController::class, 'index'])->name('grades.index');
        Route::get('/grades/{grade}', [GradeController::class, 'show'])->name('grades.show');
        Route::put('/submissions/{submission}/grade', [GradeController::class, 'store'])->name('grades.store');
        Route::post('/grades/{grade}/publish', [GradeController::class, 'publish'])->name('grades.publish');
        Route::put('/password', [PasswordController::class, 'update'])->name('password.update');
        Route::get('/audit-logs', [UserController::class, 'auditLogs'])->name('audit_logs.index');

        Route::get('/materials', [LearningToolsController::class, 'materials'])->name('materials.index');
        Route::post('/materials', [LearningToolsController::class, 'storeMaterial'])->name('materials.store');
        Route::get('/materials/{material}/download', [LearningToolsController::class, 'downloadMaterial'])->name('materials.download');
        Route::get('/submission-files/{file}/download', [LearningToolsController::class, 'submissionFile'])->name('submission_files.download');
        Route::get('/announcements', [LearningToolsController::class, 'announcements'])->name('announcements.index');
        Route::post('/announcements', [LearningToolsController::class, 'storeAnnouncement'])->name('announcements.store');
        Route::get('/notifications', [LearningToolsController::class, 'notifications'])->name('notifications.index');
        Route::post('/notifications/read-all', [LearningToolsController::class, 'readAllNotifications'])->name('notifications.read_all');
        Route::post('/notifications/{notification}/read', [LearningToolsController::class, 'readNotification'])->name('notifications.read');
        Route::get('/attendance', [LearningToolsController::class, 'attendance'])->name('attendance.index');
        Route::post('/attendance/sessions', [LearningToolsController::class, 'storeAttendanceSession'])->name('attendance.sessions.store');
        Route::put('/attendance/sessions/{session}/records', [LearningToolsController::class, 'recordAttendance'])->name('attendance.records.store');
        Route::get('/calendar', [LearningToolsController::class, 'calendar'])->name('calendar.index');
        Route::post('/calendar', [LearningToolsController::class, 'storeCalendarEvent'])->name('calendar.store');
        Route::get('/search', [LearningToolsController::class, 'search'])->name('search');
        Route::get('/courses/{course}/grade-summary', [LearningToolsController::class, 'gradeSummary'])->name('courses.grade_summary');
        Route::get('/courses/{course}/grades.csv', [LearningToolsController::class, 'exportGrades'])->name('courses.grades_export');

        Route::put('/profile', [ProfileDataController::class, 'updateProfile'])->name('profile.update');
        Route::post('/profile/avatar', [ProfileDataController::class, 'uploadAvatar'])->name('profile.avatar.store');
        Route::get('/profile/avatar/{user}', [ProfileDataController::class, 'avatar'])->name('profile.avatar.show');
        Route::post('/imports/users', [ProfileDataController::class, 'importUsers'])->name('imports.users');
        Route::post('/imports/enrollments', [ProfileDataController::class, 'importEnrollments'])->name('imports.enrollments');
        Route::post('/mfa/setup', [MfaController::class, 'setup'])->name('mfa.setup');
        Route::post('/mfa/confirm', [MfaController::class, 'confirm'])->name('mfa.confirm');
        Route::delete('/mfa', [MfaController::class, 'disable'])->name('mfa.disable');
    });
});
