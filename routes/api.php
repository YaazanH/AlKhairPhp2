<?php

use App\Http\Controllers\Api\V1\AcademicSupportController;
use App\Http\Controllers\Api\V1\AdvancedQuranController;
use App\Http\Controllers\Api\V1\AuthTokenController;
use App\Http\Controllers\Api\V1\BootstrapController;
use App\Http\Controllers\Api\V1\CapabilitiesController;
use App\Http\Controllers\Api\V1\CoreRecordsController;
use App\Http\Controllers\Api\V1\CoreWriteController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\FinanceOperationsController;
use App\Http\Controllers\Api\V1\FinanceRecordsController;
use App\Http\Controllers\Api\V1\FinanceWriteController;
use App\Http\Controllers\Api\V1\OperationalRecordsController;
use App\Http\Controllers\Api\V1\OperationalWriteController;
use App\Http\Controllers\Api\V1\ParentMobileController;
use App\Http\Controllers\Api\V1\ProfileController;
use App\Http\Controllers\Api\V1\RecordsController;
use App\Http\Controllers\Api\V1\ReferenceDataController;
use App\Http\Controllers\Api\V1\ReportOverviewController;
use App\Http\Controllers\Api\V1\StudentProgressFilesController;
use App\Http\Controllers\Api\V1\TeacherDailySummaryController;
use App\Http\Controllers\Api\V1\WriteRecordsController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    $user = $request->user();

    return array_merge($user->toArray(), [
        'teacher_id' => $user->teacherProfile?->id,
    ]);
})->middleware(['auth:sanctum', 'api.active']);

Route::post('/v1/auth/token', [AuthTokenController::class, 'store'])->middleware('throttle:10,1');

Route::middleware(['auth:sanctum', 'api.active'])->prefix('v1')->group(function () {
    Route::get('capabilities', CapabilitiesController::class);
    Route::get('me', [ProfileController::class, 'show']);
    Route::patch('me', [ProfileController::class, 'update']);
    Route::post('me/profile-photo', [ProfileController::class, 'updatePhoto']);
    Route::patch('me/password', [ProfileController::class, 'updatePassword']);
    Route::get('bootstrap', BootstrapController::class);
    Route::get('dashboard', DashboardController::class);
    Route::prefix('reference')->controller(ReferenceDataController::class)->group(function () {
        Route::get('academic-years', 'academicYears');
        Route::get('grade-levels', 'gradeLevels');
        Route::get('attendance-statuses', 'attendanceStatuses');
        Route::get('assessment-types', 'assessmentTypes');
        Route::get('assessment-score-bands', 'assessmentScoreBands');
        Route::get('quran-juz', 'quranJuz');
        Route::get('quran-test-types', 'quranTestTypes');
        Route::get('point-types', 'pointTypes');
        Route::get('payment-methods', 'paymentMethods');
        Route::get('expense-categories', 'expenseCategories');
        Route::get('currencies', 'currencies');
        Route::get('teacher-job-titles', 'teacherJobTitles');
        Route::get('schools', 'schools');
    });
    Route::delete('auth/token', [AuthTokenController::class, 'destroy']);
    Route::delete('auth/tokens', [AuthTokenController::class, 'destroyAll']);
    Route::get('reports/overview', ReportOverviewController::class);
    Route::get('reports/teachers/daily-summary', TeacherDailySummaryController::class);
    Route::controller(RecordsController::class)->group(function () {
        Route::get('students', 'students');
        Route::get('students/{student}', 'student');
        Route::get('groups', 'groups');
        Route::get('groups/{group}', 'group');
        Route::get('enrollments', 'enrollments');
        Route::get('enrollments/{enrollment}', 'enrollment');
        Route::get('assessments', 'assessments');
        Route::get('activities', 'activities');
        Route::get('invoices', 'invoices');
    });
    Route::controller(OperationalRecordsController::class)->group(function () {
        Route::get('student-attendance/days', 'studentAttendanceDays');
        Route::get('student-attendance/days/{studentAttendanceDay}', 'studentAttendanceDay');
        Route::patch('student-attendance/days/{studentAttendanceDay}/status', 'updateStudentAttendanceDayStatus');
        Route::get('teacher-attendance/days', 'teacherAttendanceDays');
        Route::get('teacher-attendance/days/{teacherAttendanceDay}', 'teacherAttendanceDay');
        Route::get('memorization', 'memorization');
        Route::get('enrollments/{enrollment}/memorization', 'enrollmentMemorization');
        Route::get('quran-tests', 'quranTests');
        Route::get('enrollments/{enrollment}/quran-tests', 'enrollmentQuranTests');
        Route::get('points', 'points');
        Route::get('enrollments/{enrollment}/points', 'enrollmentPoints');
        Route::get('assessments/{assessment}', 'assessment');
    });
    Route::controller(FinanceRecordsController::class)->group(function () {
        Route::get('activities/{activity}', 'activity');
        Route::get('activities/{activity}/registrations', 'activityRegistrations');
        Route::get('activities/{activity}/payments', 'activityPayments');
        Route::get('activities/{activity}/expenses', 'activityExpenses');
        Route::get('invoices/{invoice}', 'invoice');
        Route::get('invoices/{invoice}/payments', 'invoicePayments');
        Route::get('payments', 'payments');
    });
    Route::prefix('finance')->controller(FinanceOperationsController::class)->group(function () {
        Route::get('summary', 'summary');
        Route::get('cash-boxes', 'cashBoxes');
        Route::get('transactions', 'transactions');
        Route::get('transactions/{transaction}', 'transaction');
        Route::post('adjustments', 'storeAdjustment');
        Route::get('transfers', 'transfers');
        Route::post('transfers', 'storeTransfer');
        Route::get('exchanges', 'exchanges');
        Route::post('exchanges', 'storeExchange');
        Route::get('requests', 'financeRequests');
        Route::get('requests/{financeRequest}', 'financeRequest');
    });
    Route::controller(AcademicSupportController::class)->group(function () {
        Route::get('groups/{group}/schedules', 'groupSchedules');
        Route::post('groups/{group}/schedules', 'storeGroupSchedule');
        Route::match(['put', 'patch'], 'groups/{group}/schedules/{schedule}', 'updateGroupSchedule');
        Route::delete('groups/{group}/schedules/{schedule}', 'destroyGroupSchedule');
        Route::get('student-notes', 'studentNotes');
        Route::get('student-notes/{studentNote}', 'studentNote');
        Route::post('student-notes', 'storeStudentNote');
        Route::match(['put', 'patch'], 'student-notes/{studentNote}', 'updateStudentNote');
        Route::delete('student-notes/{studentNote}', 'destroyStudentNote');
        Route::get('curricula', 'curricula');
        Route::get('curricula/{curriculum}', 'curriculum');
        Route::get('groups/{group}/curriculum', 'groupCurriculum');
        Route::get('curriculum-resources/{resource}/download', 'downloadCurriculumResource');
    });
    Route::controller(AdvancedQuranController::class)->group(function () {
        Route::get('quran-partial-tests', 'partialTests');
        Route::get('quran-partial-tests/{partialTest}', 'partialTest');
        Route::get('enrollments/{enrollment}/quran-partial-tests/eligible-juz', 'partialEligibleJuz');
        Route::post('enrollments/{enrollment}/quran-partial-tests', 'storePartialTest');
        Route::post('quran-partial-tests/{partialTest}/parts/{part}/attempts', 'storePartialAttempt');
        Route::get('quran-final-tests', 'finalTests');
        Route::get('quran-final-tests/{finalTest}', 'finalTest');
        Route::get('enrollments/{enrollment}/quran-final-tests/eligible-juz', 'finalEligibleJuz');
        Route::post('enrollments/{enrollment}/quran-final-tests', 'storeFinalTest');
        Route::post('quran-final-tests/{finalTest}/attempts', 'storeFinalAttempt');
    });
    Route::controller(StudentProgressFilesController::class)->group(function () {
        Route::get('students/{student}/progress', 'progress');
        Route::get('students/{student}/files', 'files');
        Route::post('students/{student}/files', 'storeFile');
        Route::get('students/{student}/files/{studentFile}/download', 'downloadFile');
        Route::delete('students/{student}/files/{studentFile}', 'destroyFile');
        Route::get('students/{student}/photo', 'showPhoto');
        Route::post('students/{student}/photo', 'updatePhoto');
        Route::delete('students/{student}/photo', 'destroyPhoto');
    });
    Route::controller(CoreRecordsController::class)->group(function () {
        Route::get('parents', 'parents');
        Route::get('parents/{parentProfile}', 'parent');
        Route::get('teachers', 'teachers');
        Route::get('teachers/{teacher}', 'teacher');
        Route::get('courses', 'courses');
        Route::get('courses/{course}', 'course');
    });
    Route::controller(CoreWriteController::class)->group(function () {
        Route::post('parents', 'storeParent');
        Route::match(['put', 'patch'], 'parents/{parentProfile}', 'updateParent');
        Route::delete('parents/{parentProfile}', 'destroyParent');

        Route::post('teachers', 'storeTeacher');
        Route::match(['put', 'patch'], 'teachers/{teacher}', 'updateTeacher');
        Route::delete('teachers/{teacher}', 'destroyTeacher');

        Route::post('courses', 'storeCourse');
        Route::match(['put', 'patch'], 'courses/{course}', 'updateCourse');
        Route::delete('courses/{course}', 'destroyCourse');
        Route::post('courses/{course}/finish', 'finishCourse');
        Route::post('courses/{course}/reactivate', 'reactivateCourse');
    });
    Route::prefix('parent')->controller(ParentMobileController::class)->group(function () {
        Route::get('profile', 'profile');
        Route::get('summary', 'summary');
        Route::get('children', 'children');
        Route::get('children/{student}', 'child');
        Route::get('children/{student}/attendance', 'attendance');
        Route::get('children/{student}/memorization', 'memorization');
        Route::get('children/{student}/points', 'points');
        Route::get('children/{student}/assessments', 'assessments');
        Route::get('children/{student}/quran-tests', 'quranTests');
        Route::get('children/{student}/notes', 'notes');
        Route::get('invoices', 'invoices');
        Route::get('invoices/{invoice}', 'invoice');
        Route::get('activities', 'activities');
        Route::post('activities/{activity}/responses', 'respondToActivity');
    });
    Route::controller(WriteRecordsController::class)->group(function () {
        Route::post('students', 'storeStudent');
        Route::match(['put', 'patch'], 'students/{student}', 'updateStudent');
        Route::delete('students/{student}', 'destroyStudent');

        Route::post('groups', 'storeGroup');
        Route::match(['put', 'patch'], 'groups/{group}', 'updateGroup');
        Route::delete('groups/{group}', 'destroyGroup');

        Route::post('enrollments', 'storeEnrollment');
        Route::match(['put', 'patch'], 'enrollments/{enrollment}', 'updateEnrollment');
        Route::delete('enrollments/{enrollment}', 'destroyEnrollment');
    });
    Route::controller(OperationalWriteController::class)->group(function () {
        Route::post('groups/{group}/attendance', 'storeGroupAttendance');
        Route::post('student-attendance/days', 'openStudentAttendanceDay');
        Route::post('student-attendance/quick-scan', 'quickScanStudentAttendance');
        Route::post('student-attendance/days/{studentAttendanceDay}/scan', 'scanStudentAttendance');
        Route::post('teacher-attendance', 'storeTeacherAttendance');
        Route::post('enrollments/{enrollment}/memorization', 'storeMemorization');
        Route::post('enrollments/{enrollment}/quran-tests', 'storeQuranTest');
        Route::post('enrollments/{enrollment}/points/manual', 'storeManualPoint');
        Route::post('students/{student}/points/manual', 'storeStudentManualPoint');
        Route::post('points/{pointTransaction}/void', 'voidPoint');
        Route::post('assessments/{assessment}/results', 'storeAssessmentResults');
    });
    Route::controller(FinanceWriteController::class)->group(function () {
        Route::post('activities/{activity}/registrations', 'storeActivityRegistration');
        Route::match(['put', 'patch'], 'activities/{activity}/registrations/{registration}', 'updateActivityRegistration');
        Route::delete('activities/{activity}/registrations/{registration}', 'destroyActivityRegistration');
        Route::post('activities/{activity}/payments', 'storeActivityPayment');
        Route::post('activities/{activity}/payments/{activityPayment}/void', 'voidActivityPayment');
        Route::post('activities/{activity}/expenses', 'storeActivityExpense');
        Route::match(['put', 'patch'], 'activities/{activity}/expenses/{activityExpense}', 'updateActivityExpense');
        Route::delete('activities/{activity}/expenses/{activityExpense}', 'destroyActivityExpense');

        Route::post('invoices', 'storeStudentInvoice');
        Route::post('invoices/{invoice}/items', 'storeInvoiceItem');
        Route::match(['put', 'patch'], 'invoices/{invoice}/items/{invoiceItem}', 'updateInvoiceItem');
        Route::delete('invoices/{invoice}/items/{invoiceItem}', 'destroyInvoiceItem');
        Route::post('invoices/{invoice}/payments', 'storeInvoicePayment');
        Route::post('invoices/{invoice}/payments/{payment}/void', 'voidInvoicePayment');
    });
});
