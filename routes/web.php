<?php

use App\Http\Controllers\AdminExportController;
use App\Http\Controllers\BarcodeActionPrintController;
use App\Http\Controllers\IdCards\IdCardBarcodePreviewController;
use App\Http\Controllers\IdCards\IdCardPrintController;
use App\Http\Controllers\IdCards\IdCardTemplateController;
use App\Http\Controllers\PrintController;
use App\Http\Controllers\ReportExportController;
use App\Http\Controllers\WebsiteController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

Route::get('/', [WebsiteController::class, 'home'])->name('home');
Route::get('pages/{page:slug}', [WebsiteController::class, 'show'])->name('website.pages.show');

Route::get('locale/{locale}', function (Request $request, string $locale) {
    if (! array_key_exists($locale, config('app.supported_locales', []))) {
        abort(404);
    }

    $request->session()->put('locale', $locale);
    $request->session()->put('locale_user_selected', true);

    return redirect()->back();
})->name('locale.switch');

Volt::route('dashboard', 'dashboard')
    ->middleware(['auth', 'verified'])
    ->name('dashboard');

Route::middleware(['auth'])->group(function () {
    Volt::route('reports', 'reports.index')->middleware('permission:reports.view')->name('reports.index');
    Volt::route('users', 'users.index')->middleware('permission:users.view')->name('users.index');
    Route::get('users/export', [AdminExportController::class, 'users'])->middleware('permission:users.view')->name('users.export');
    Route::get('id-cards/templates', [IdCardTemplateController::class, 'index'])->middleware('permission:id-cards.view')->name('id-cards.templates.index');
    Route::get('id-cards/templates/create', [IdCardTemplateController::class, 'create'])->middleware('permission:id-cards.templates.manage')->name('id-cards.templates.create');
    Route::post('id-cards/templates', [IdCardTemplateController::class, 'store'])->middleware('permission:id-cards.templates.manage')->name('id-cards.templates.store');
    Route::get('id-cards/templates/{template}/edit', [IdCardTemplateController::class, 'edit'])->middleware('permission:id-cards.templates.manage')->name('id-cards.templates.edit');
    Route::put('id-cards/templates/{template}', [IdCardTemplateController::class, 'update'])->middleware('permission:id-cards.templates.manage')->name('id-cards.templates.update');
    Route::delete('id-cards/templates/{template}', [IdCardTemplateController::class, 'destroy'])->middleware('permission:id-cards.templates.manage')->name('id-cards.templates.destroy');
    Route::get('id-cards/barcode-preview', IdCardBarcodePreviewController::class)->middleware('permission:id-cards.view')->name('id-cards.barcode-preview');
    Route::get('id-cards/print', [IdCardPrintController::class, 'create'])->middleware('permission:id-cards.print')->name('id-cards.print.create');
    Route::post('id-cards/print/preview', [IdCardPrintController::class, 'preview'])->middleware('permission:id-cards.print')->name('id-cards.print.preview');
    Volt::route('barcode-actions', 'barcode-actions.index')->middleware('permission:barcode-actions.view')->name('barcode-actions.index');
    Route::post('barcode-actions/print/preview', [BarcodeActionPrintController::class, 'preview'])->middleware('permission:barcode-actions.view')->name('barcode-actions.print.preview');
    Volt::route('scanner-imports', 'barcode-actions.import')->middleware('permission:barcode-scans.import')->name('barcode-actions.import');
    Route::get('reports/export/attendance', [ReportExportController::class, 'attendance'])->middleware('permission:reports.view')->name('reports.exports.attendance');
    Route::get('reports/export/memorization', [ReportExportController::class, 'memorization'])->middleware('permission:reports.view')->name('reports.exports.memorization');
    Route::get('reports/export/points', [ReportExportController::class, 'points'])->middleware('permission:reports.view')->name('reports.exports.points');
    Route::get('reports/export/assessments', [ReportExportController::class, 'assessments'])->middleware('permission:reports.view')->name('reports.exports.assessments');
    Volt::route('settings/organization', 'settings.organization')->middleware('permission:settings.manage')->name('settings.organization');
    Volt::route('settings/tracking', 'settings.tracking')->middleware('permission:settings.manage')->name('settings.tracking');
    Volt::route('settings/points', 'settings.points')->middleware('permission:settings.manage')->name('settings.points');
    Volt::route('settings/finance', 'settings.finance')->middleware('permission:settings.manage')->name('settings.finance');
    Volt::route('settings/access-control', 'settings.access-control')->middleware('permission:roles.manage')->name('settings.access-control');
    Volt::route('settings/website', 'settings.website')->middleware('permission:website.manage')->name('settings.website');
    Volt::route('settings/website/pages', 'settings.website-pages')->middleware('permission:website.manage')->name('settings.website.pages');
    Volt::route('settings/website/navigation', 'settings.website-navigation')->middleware('permission:website.manage')->name('settings.website.navigation');
    Volt::route('parents', 'parents.index')->middleware('permission:parents.view')->name('parents.index');
    Route::get('parents/export', [AdminExportController::class, 'parents'])->middleware('permission:parents.view')->name('parents.export');
    Volt::route('teachers/attendance', 'teachers.attendance')->middleware('permission:attendance.teacher.view')->name('teachers.attendance');
    Volt::route('teachers', 'teachers.index')->middleware('permission:teachers.view')->name('teachers.index');
    Route::get('teachers/export', [AdminExportController::class, 'teachers'])->middleware('permission:teachers.view')->name('teachers.export');
    Volt::route('students', 'students.index')->middleware('permission:students.view')->name('students.index');
    Route::get('students/export', [AdminExportController::class, 'students'])->middleware('permission:students.view')->name('students.export');
    Volt::route('students/{student}/progress', 'students.progress')->middleware('permission:students.view')->name('students.progress');
    Volt::route('students/{student}/files', 'students.files')->middleware('permission:students.view')->name('students.files');
    Volt::route('courses', 'courses.index')->middleware('permission:courses.view')->name('courses.index');
    Route::get('courses/export', [AdminExportController::class, 'courses'])->middleware('permission:courses.view')->name('courses.export');
    Volt::route('groups/{group}/attendance', 'groups.attendance')->middleware('permission:attendance.student.view')->name('groups.attendance');
    Volt::route('student-attendance', 'student-attendance.index')->middleware('permission:attendance.student.view')->name('student-attendance.index');
    Volt::route('student-attendance/groups/{groupAttendanceDay}', 'student-attendance.mark')->middleware('permission:attendance.student.view')->name('student-attendance.mark');
    Volt::route('student-attendance/days/{studentAttendanceDay}', 'student-attendance.show')->middleware('permission:attendance.student.view')->name('student-attendance.show');
    Volt::route('groups', 'groups.index')->middleware('permission:groups.view')->name('groups.index');
    Volt::route('groups/{group}/schedules', 'groups.schedules')->middleware('permission:groups.view')->name('groups.schedules');
    Route::get('groups/export', [AdminExportController::class, 'groups'])->middleware('permission:groups.view')->name('groups.export');
    Volt::route('enrollments', 'enrollments.index')->middleware('permission:enrollments.view')->name('enrollments.index');
    Route::get('enrollments/export', [AdminExportController::class, 'enrollments'])->middleware('permission:enrollments.view')->name('enrollments.export');
    Volt::route('assessments', 'assessments.index')->middleware('permission:assessments.view')->name('assessments.index');
    Volt::route('assessments/bands', 'assessments.bands')->middleware('permission:assessment-score-bands.view')->name('assessments.bands');
    Volt::route('assessments/{assessment}/results', 'assessments.results')->middleware('permission:assessment-results.view')->name('assessments.results');
    Volt::route('student-notes', 'student-notes.index')->middleware('permission:student-notes.view')->name('student-notes.index');
    Volt::route('memorization', 'memorization.index')->middleware('permission:memorization.view')->name('memorization.index');
    Volt::route('quran-tests', 'quran-tests.index')->middleware('permission:quran-tests.view')->name('quran-tests.index');
    Volt::route('points', 'points.index')->middleware('permission:points.view')->name('points.index');
    Volt::route('enrollments/{enrollment}/memorization', 'enrollments.memorization')->middleware('permission:memorization.view')->name('enrollments.memorization');
    Volt::route('enrollments/{enrollment}/quran-tests', 'enrollments.quran-tests')->middleware('permission:quran-tests.view')->name('enrollments.quran-tests');
    Volt::route('enrollments/{enrollment}/points', 'enrollments.points')->middleware('permission:points.view')->name('enrollments.points');
    Volt::route('activities', 'activities.index')->middleware('permission:activities.view')->name('activities.index');
    Volt::route('activities/family', 'activities.family')->middleware('permission:activities.responses.view')->name('activities.family');
    Volt::route('activities/{activity}/finance', 'activities.finance')->middleware('permission:activities.finance.view')->name('activities.finance');
    Volt::route('invoices', 'invoices.index')->middleware('permission:invoices.view')->name('invoices.index');
    Volt::route('invoices/{invoice}/payments', 'invoices.payments')->middleware('permission:payments.view')->name('invoices.payments');
    Route::get('invoices/{invoice}/print', [PrintController::class, 'invoice'])->middleware('permission:invoices.view')->name('invoices.print');
    Route::get('payments/{payment}/receipt', [PrintController::class, 'receipt'])->middleware('permission:payments.view')->name('payments.receipt');

    Route::redirect('settings', 'settings/profile');

    Volt::route('settings/profile', 'settings.profile')->name('settings.profile');
    Volt::route('settings/password', 'settings.password')->name('settings.password');
    Volt::route('settings/appearance', 'settings.appearance')->name('settings.appearance');
});

require __DIR__.'/auth.php';
