<?php

use App\Http\Controllers\Api\V1\AuthTokenController;
use App\Http\Controllers\Api\V1\FinanceWriteController;
use App\Http\Controllers\Api\V1\OperationalWriteController;
use App\Http\Controllers\Api\V1\RecordsController;
use App\Http\Controllers\Api\V1\ReportOverviewController;
use App\Http\Controllers\Api\V1\TeacherDailySummaryController;
use App\Http\Controllers\Api\V1\WriteRecordsController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::post('/v1/auth/token', [AuthTokenController::class, 'store']);

Route::middleware('auth:sanctum')->prefix('v1')->group(function () {
    Route::delete('auth/token', [AuthTokenController::class, 'destroy']);
    Route::get('reports/overview', ReportOverviewController::class);
    Route::get('reports/teachers/daily-summary', TeacherDailySummaryController::class);
    Route::controller(RecordsController::class)->group(function () {
        Route::get('students', 'students');
        Route::get('groups', 'groups');
        Route::get('enrollments', 'enrollments');
        Route::get('assessments', 'assessments');
        Route::get('activities', 'activities');
        Route::get('invoices', 'invoices');
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
        Route::post('teacher-attendance', 'storeTeacherAttendance');
        Route::post('enrollments/{enrollment}/memorization', 'storeMemorization');
        Route::post('enrollments/{enrollment}/quran-tests', 'storeQuranTest');
        Route::post('enrollments/{enrollment}/points/manual', 'storeManualPoint');
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

        Route::post('invoices/{invoice}/items', 'storeInvoiceItem');
        Route::match(['put', 'patch'], 'invoices/{invoice}/items/{invoiceItem}', 'updateInvoiceItem');
        Route::delete('invoices/{invoice}/items/{invoiceItem}', 'destroyInvoiceItem');
        Route::post('invoices/{invoice}/payments', 'storeInvoicePayment');
        Route::post('invoices/{invoice}/payments/{payment}/void', 'voidInvoicePayment');
    });
});
