<?php

use App\Http\Controllers\AddressSuggestionController;
use App\Http\Controllers\AdminExportController;
use App\Http\Controllers\AssessmentResultPdfController;
use App\Http\Controllers\BackupSettingsEntryController;
use App\Http\Controllers\BarcodeActionPrintController;
use App\Http\Controllers\CourseCalendarPdfController;
use App\Http\Controllers\CourseEndExportController;
use App\Http\Controllers\CoursePointMarketExportController;
use App\Http\Controllers\CurriculumResourceDownloadController;
use App\Http\Controllers\DubaiFontController;
use App\Http\Controllers\FinanceInvoiceItemsExportController;
use App\Http\Controllers\FinanceInvoicePrintController;
use App\Http\Controllers\FinanceRequestPrintController;
use App\Http\Controllers\IdCards\IdCardBarcodePreviewController;
use App\Http\Controllers\IdCards\IdCardTemplateController;
use App\Http\Controllers\Platform\PlanManagementController;
use App\Http\Controllers\Platform\PlatformAccessController;
use App\Http\Controllers\Platform\PlatformAuthenticatedSessionController;
use App\Http\Controllers\Platform\PlatformDashboardController;
use App\Http\Controllers\Platform\PlatformLandingPageController;
use App\Http\Controllers\Platform\PlatformRequiredPasswordChangeController;
use App\Http\Controllers\Platform\PlatformSubscriptionSettingController;
use App\Http\Controllers\Platform\PlatformSupportAttachmentController;
use App\Http\Controllers\Platform\PlatformSupportCaseController;
use App\Http\Controllers\Platform\PlatformSupportSettingController;
use App\Http\Controllers\Platform\ReportLibraryController;
use App\Http\Controllers\Platform\StorageUsageController;
use App\Http\Controllers\Platform\SubscriptionReceiptController;
use App\Http\Controllers\Platform\SubscriptionVoucherController;
use App\Http\Controllers\Platform\TenantBackupController as PlatformTenantBackupController;
use App\Http\Controllers\Platform\TenantManagementController;
use App\Http\Controllers\Platform\TenantModuleExtrasController;
use App\Http\Controllers\Platform\TenantProvisioningController;
use App\Http\Controllers\Platform\TenantSubscriptionController;
use App\Http\Controllers\Platform\TenantSupportAccessController;
use App\Http\Controllers\PlatformLandingController;
use App\Http\Controllers\PlatformLandingMediaController;
use App\Http\Controllers\PrintController;
use App\Http\Controllers\PrintTemplates\PrintTemplateController;
use App\Http\Controllers\PrintTemplates\PrintTemplatePrintController;
use App\Http\Controllers\ReportDefinitionViewController;
use App\Http\Controllers\ReportDesignerExportController;
use App\Http\Controllers\ReportExportController;
use App\Http\Controllers\ReportLibraryInstallController;
use App\Http\Controllers\RequiredPasswordChangeController;
use App\Http\Controllers\StudentAttendanceExportController;
use App\Http\Controllers\SystemBackupDownloadController;
use App\Http\Controllers\TeacherAttendanceExportController;
use App\Http\Controllers\TenantBackupController;
use App\Http\Controllers\TenantPublicMediaController;
use App\Http\Controllers\TenantSetupController;
use App\Http\Controllers\TenantStorageUsageController;
use App\Http\Controllers\TenantSupportAttachmentController;
use App\Http\Controllers\TenantSupportHandoffController;
use App\Http\Controllers\TenantSupportRequestController;
use App\Http\Controllers\TenantThemeController;
use App\Http\Controllers\WebsiteController;
use App\Http\Middleware\SetLocale;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Livewire\Volt\Volt;

Route::prefix('platform')->name('platform.')->group(function (): void {
    Route::middleware('guest:platform')->group(function (): void {
        Route::get('login', [PlatformAuthenticatedSessionController::class, 'create'])->name('login');
        Route::post('login', [PlatformAuthenticatedSessionController::class, 'store'])->name('login.store');
    });

    Route::middleware('platform.auth')->group(function (): void {
        Route::get('change-temporary-password', [PlatformRequiredPasswordChangeController::class, 'show'])->name('password-change.show');
        Route::put('change-temporary-password', [PlatformRequiredPasswordChangeController::class, 'update'])->name('password-change.update');
    });

    Route::middleware(['platform.auth', 'platform.password-change'])->group(function (): void {
        Route::get('access', [PlatformAccessController::class, 'index'])->middleware('platform.permission:manage.platform-users')->name('access.index');
        Route::post('access/users', [PlatformAccessController::class, 'storeAdministrator'])->middleware('platform.permission:manage.platform-users')->name('access.users.store');
        Route::put('access/users/{administrator}', [PlatformAccessController::class, 'updateAdministrator'])->middleware('platform.permission:manage.platform-users')->name('access.users.update');
        Route::patch('access/users/{administrator}/status', [PlatformAccessController::class, 'setAdministratorStatus'])->middleware('platform.permission:manage.platform-users')->name('access.users.status');
        Route::put('access/users/{administrator}/password', [PlatformAccessController::class, 'resetAdministratorPassword'])->middleware('platform.permission:manage.platform-users')->name('access.users.password');
        Route::post('access/roles', [PlatformAccessController::class, 'storeRole'])->middleware('platform.owner')->name('access.roles.store');
        Route::put('access/roles/{role}', [PlatformAccessController::class, 'updateRole'])->middleware('platform.owner')->name('access.roles.update');
        Route::delete('access/roles/{role}', [PlatformAccessController::class, 'destroyRole'])->middleware('platform.owner')->name('access.roles.destroy');
        Route::put('access/users/{administrator}/roles', [PlatformAccessController::class, 'syncAdministratorRoles'])->middleware('platform.owner')->name('access.users.roles');
        Route::get('/', PlatformDashboardController::class)->middleware('platform.permission:view.dashboard')->name('dashboard');
        Route::get('landing-page', [PlatformLandingPageController::class, 'edit'])->middleware('platform.permission:manage.landing-page,publish.landing-page')->name('landing.edit');
        Route::put('landing-page', [PlatformLandingPageController::class, 'update'])->middleware('platform.permission:manage.landing-page')->name('landing.update');
        Route::post('landing-page/publish', [PlatformLandingPageController::class, 'publish'])->middleware('platform.permission:publish.landing-page')->name('landing.publish');
        Route::post('landing-page/revisions/{revision}/restore', [PlatformLandingPageController::class, 'restore'])->middleware('platform.permission:publish.landing-page')->name('landing.restore');
        Route::get('report-library', [ReportLibraryController::class, 'index'])->middleware('platform.permission:manage.report-library,publish.report-library')->name('report-library.index');
        Route::get('report-library/create', [ReportLibraryController::class, 'create'])->middleware('platform.permission:manage.report-library')->name('report-library.create');
        Route::post('report-library', [ReportLibraryController::class, 'store'])->middleware('platform.permission:manage.report-library')->name('report-library.store');
        Route::get('report-library/{libraryItem}/edit', [ReportLibraryController::class, 'edit'])->middleware('platform.permission:manage.report-library,publish.report-library')->name('report-library.edit');
        Route::put('report-library/{libraryItem}', [ReportLibraryController::class, 'update'])->middleware('platform.permission:manage.report-library')->name('report-library.update');
        Route::post('report-library/{libraryItem}/publish', [ReportLibraryController::class, 'publish'])->middleware('platform.permission:publish.report-library')->name('report-library.publish');
        Route::get('support', [PlatformSupportCaseController::class, 'index'])->name('support.index');
        Route::get('support/settings', [PlatformSupportSettingController::class, 'edit'])->middleware('platform.permission:manage.support.problems')->name('support.settings.edit');
        Route::put('support/settings', [PlatformSupportSettingController::class, 'update'])->middleware('platform.permission:manage.support.problems')->name('support.settings.update');
        Route::put('support/{case}', [PlatformSupportCaseController::class, 'update'])->name('support.update');
        Route::post('support/suggestion-groups', [PlatformSupportCaseController::class, 'storeSuggestionGroup'])->name('support.suggestion-groups.store');
        Route::get('support/{case}/attachments', [PlatformSupportAttachmentController::class, 'index'])->name('support.attachments.index');
        Route::get('support/{case}/attachments/{attachmentId}', [PlatformSupportAttachmentController::class, 'download'])->whereNumber('attachmentId')->name('support.attachments.download');
        Route::get('backups', [PlatformTenantBackupController::class, 'index'])->middleware('platform.permission:view.backups')->name('backups.index');
        Route::get('storage', StorageUsageController::class)->middleware('platform.permission:view.storage')->name('storage.index');
        Route::put('backups/settings', [PlatformTenantBackupController::class, 'updateSettings'])->middleware('platform.permission:manage.backups')->name('backups.settings.update');
        Route::post('backups', [PlatformTenantBackupController::class, 'create'])->middleware('platform.permission:manage.backups')->name('backups.create');
        Route::get('backups/{tenantBackup}/download', [PlatformTenantBackupController::class, 'download'])->middleware('platform.permission:view.backups')->name('backups.download');
        Route::post('backups/{tenantBackup}/restore', [PlatformTenantBackupController::class, 'restore'])->middleware('platform.permission:restore.backups')->name('backups.restore');
        Route::get('vouchers', [SubscriptionVoucherController::class, 'index'])->middleware('platform.permission:view.subscriptions,manage.subscriptions')->name('vouchers.index');
        Route::post('vouchers', [SubscriptionVoucherController::class, 'store'])->middleware('platform.permission:manage.subscriptions')->name('vouchers.store');
        Route::patch('vouchers/{voucher}/status', [SubscriptionVoucherController::class, 'status'])->middleware('platform.permission:manage.subscriptions')->name('vouchers.status');
        Route::get('subscription-settings', [PlatformSubscriptionSettingController::class, 'edit'])->middleware('platform.permission:view.subscriptions,manage.subscriptions')->name('subscription-settings.edit');
        Route::put('subscription-settings', [PlatformSubscriptionSettingController::class, 'update'])->middleware('platform.permission:manage.subscriptions')->name('subscription-settings.update');
        Route::get('packages', [PlanManagementController::class, 'index'])->middleware('platform.permission:manage.plans')->name('plans.index');
        Route::get('packages/create', [PlanManagementController::class, 'create'])->middleware('platform.permission:manage.plans')->name('plans.create');
        Route::post('packages', [PlanManagementController::class, 'store'])->middleware('platform.permission:manage.plans')->name('plans.store');
        Route::get('packages/{plan}/edit', [PlanManagementController::class, 'edit'])->middleware('platform.permission:manage.plans')->name('plans.edit');
        Route::put('packages/{plan}/preview', [PlanManagementController::class, 'preview'])->middleware('platform.permission:manage.plans')->name('plans.preview');
        Route::put('packages/{plan}', [PlanManagementController::class, 'update'])->middleware('platform.permission:manage.plans')->name('plans.update');
        Route::post('packages/{plan}/duplicate', [PlanManagementController::class, 'duplicate'])->middleware('platform.permission:manage.plans')->name('plans.duplicate');
        Route::patch('packages/{plan}/status', [PlanManagementController::class, 'setStatus'])->middleware('platform.permission:manage.plans')->name('plans.status');
        Route::delete('packages/{plan}', [PlanManagementController::class, 'destroy'])->middleware('platform.permission:manage.plans')->name('plans.destroy');
        Route::post('tenants', [TenantProvisioningController::class, 'store'])->middleware('platform.permission:manage.tenants')->name('tenants.store');
        Route::get('tenants/create', [TenantManagementController::class, 'create'])->middleware('platform.permission:manage.tenants')->name('tenants.create');
        Route::get('tenants/{tenant}/edit', [TenantManagementController::class, 'edit'])->middleware('platform.permission:view.tenants')->name('tenants.edit');
        Route::get('tenants/{tenant}/organisation', [TenantManagementController::class, 'organisation'])->middleware('platform.permission:view.tenants')->name('tenants.organisation');
        Route::get('tenants/{tenant}/subscription', [TenantManagementController::class, 'subscription'])->middleware('platform.permission:view.subscriptions,manage.subscriptions')->name('tenants.subscription');
        Route::get('tenants/{tenant}/billing', [TenantManagementController::class, 'billing'])->middleware('platform.permission:view.subscriptions,manage.subscriptions')->name('tenants.billing');
        Route::get('tenants/{tenant}/storage', [TenantManagementController::class, 'storage'])->middleware('platform.permission:view.storage,manage.tenants')->name('tenants.storage');
        Route::get('tenants/{tenant}/modules', [TenantManagementController::class, 'modules'])->middleware('platform.permission:view.tenants')->name('tenants.modules');
        Route::put('tenants/{tenant}/storage', [TenantManagementController::class, 'updateStorage'])->middleware('platform.permission:manage.tenants')->name('tenants.storage.update');
        Route::get('tenants/{tenant}/activity', [TenantManagementController::class, 'activity'])->middleware('platform.permission:view.tenants')->name('tenants.activity');
        Route::post('tenants/{tenant}/support-access', [TenantSupportAccessController::class, 'store'])->middleware('platform.permission:support-access.read,support-access.edit,support-access.delete')->name('tenants.support-access.store');
        Route::put('tenants/{tenant}', [TenantManagementController::class, 'update'])->middleware('platform.permission:manage.tenants')->name('tenants.update');
        Route::patch('tenants/{tenant}/status', [TenantManagementController::class, 'setStatus'])->middleware('platform.permission:manage.tenants')->name('tenants.status');
        Route::put('tenants/{tenant}/administrator-password', [TenantManagementController::class, 'resetAdministratorPassword'])->middleware('platform.permission:manage.tenants')->name('tenants.administrator-password');
        Route::put('tenants/{tenant}/extras/preview', [TenantModuleExtrasController::class, 'preview'])->middleware('platform.permission:manage.tenants')->name('tenants.extras.preview');
        Route::put('tenants/{tenant}/extras', [TenantModuleExtrasController::class, 'update'])->middleware('platform.permission:manage.tenants')->name('tenants.extras.update');
        Route::delete('tenants/{tenant}', [TenantManagementController::class, 'destroy'])->middleware('platform.permission:manage.tenants')->name('tenants.destroy');
        Route::put('tenants/{tenant}/subscription', [TenantSubscriptionController::class, 'update'])->middleware('platform.permission:manage.subscriptions')->name('tenants.subscription.update');
        Route::post('tenants/{tenant}/subscription/cancel', [TenantSubscriptionController::class, 'cancel'])->middleware('platform.permission:manage.subscriptions')->name('tenants.subscription.cancel');
        Route::post('tenants/{tenant}/subscription/reactivate', [TenantSubscriptionController::class, 'reactivate'])->middleware('platform.permission:manage.subscriptions')->name('tenants.subscription.reactivate');
        Route::post('tenants/{tenant}/subscription/payments', [TenantSubscriptionController::class, 'recordOfflinePayment'])->middleware('platform.permission:manage.subscriptions')->name('tenants.subscription.payments.store');
        Route::get('subscription-payments/{entry}/receipt', SubscriptionReceiptController::class)->middleware('platform.permission:view.subscriptions,manage.subscriptions')->name('subscription-payments.receipt');
        Route::post('logout', [PlatformAuthenticatedSessionController::class, 'destroy'])->name('logout');
    });
});

Route::get('/', [WebsiteController::class, 'home'])->name('home');
Route::post('platform-enquiries', [PlatformLandingController::class, 'enquire'])->middleware(['no-tenant', 'throttle:5,1'])->name('platform-site.enquire');
Route::get('platform-site/media/{path}', PlatformLandingMediaController::class)->middleware('no-tenant')->where('path', '.*')->name('platform-site.media');
Route::get('support-access/{token}', TenantSupportHandoffController::class)
    ->middleware('throttle:10,1')
    ->name('tenant-support.consume');
Route::get('storage/{path}', TenantPublicMediaController::class)
    ->where('path', '.*')
    ->name('tenant.public-media');
Route::get('web-fonts/dubai/{weight}/{format}', DubaiFontController::class)
    ->whereIn('weight', ['light', 'regular', 'medium', 'bold'])
    ->whereIn('format', ['woff2', 'ttf'])
    ->withoutMiddleware([
        SetLocale::class,
        EncryptCookies::class,
        AddQueuedCookiesToResponse::class,
        StartSession::class,
        ShareErrorsFromSession::class,
        ValidateCsrfToken::class,
    ])
    ->name('web-fonts.dubai');
Route::get('pages/{page:slug}', [WebsiteController::class, 'show'])->name('website.pages.show');
Volt::route('teacher-signup', 'public.teacher-signup')->name('teacher-signup');

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
    Route::get('change-temporary-password', [RequiredPasswordChangeController::class, 'show'])->name('password.change-required.show');
    Route::put('change-temporary-password', [RequiredPasswordChangeController::class, 'update'])->name('password.change-required.update');

    Route::get('setup', [TenantSetupController::class, 'show'])->name('tenant-setup.show');
    Route::patch('setup/foundation', [TenantSetupController::class, 'foundation'])->name('tenant-setup.foundation');
    Route::patch('setup/modules/{module}', [TenantSetupController::class, 'module'])->name('tenant-setup.module');
    Route::post('setup/finish', [TenantSetupController::class, 'finish'])->name('tenant-setup.finish');
    Route::get('support', [TenantSupportRequestController::class, 'index'])->name('support.index');
    Route::post('support', [TenantSupportRequestController::class, 'store'])->name('support.store');
    Route::post('support/{supportRequest}/messages', [TenantSupportRequestController::class, 'storeMessage'])->name('support.messages.store');
    Route::post('support/{supportRequest}/attachments', [TenantSupportAttachmentController::class, 'store'])->name('support.attachments.store');
    Route::get('support/attachments/{attachment}', [TenantSupportAttachmentController::class, 'download'])->name('support.attachments.download');
    Route::get('support/manage', [TenantSupportRequestController::class, 'manage'])->middleware('permission:support.manage')->name('support.manage');
    Route::put('support/manage/{supportRequest}', [TenantSupportRequestController::class, 'update'])->middleware('permission:support.manage')->name('support.update');

    Route::get('address-suggestions', AddressSuggestionController::class)
        ->middleware(['permission:parents.create|parents.update|students.create|students.update', 'throttle:30,1'])
        ->name('address-suggestions');
    Volt::route('reports', 'reports.index')->middleware('permission:reports.view')->name('reports.index');
    Volt::route('reports/custom', 'reports.custom')->name('reports.custom');
    Volt::route('reports/designer', 'reports.designer')->middleware('permission:report-designer.view|report-dashboard-layout.manage')->name('reports.designer');
    Route::get('reports/library', [ReportLibraryInstallController::class, 'index'])->middleware('permission:report-library.install')->name('reports.library.index');
    Route::post('reports/library/{libraryItem}/install', [ReportLibraryInstallController::class, 'store'])->middleware('permission:report-library.install')->name('reports.library.install');
    Route::get('reports/designer/{reportDefinition}', ReportDefinitionViewController::class)->name('reports.designer.show');
    Route::get('reports/designer/{reportDefinition}/export.xlsx', [ReportDesignerExportController::class, 'xlsx'])->name('reports.designer.export.xlsx');
    Route::get('reports/designer/{reportDefinition}/export.pdf', [ReportDesignerExportController::class, 'pdf'])->name('reports.designer.export.pdf');
    Volt::route('reports/student-activity-summary', 'reports.student-activity-summary')->middleware('permission:reports.view')->name('reports.student-activity-summary');
    Route::redirect('reports/student-quran-tests', '/reports/student-activity-summary')
        ->middleware('permission:reports.view')
        ->name('reports.student-quran-tests');
    Volt::route('reports/rankings/groups', 'reports.groups-ranking')->middleware('permission:reports.view')->name('reports.rankings.groups');
    Volt::route('reports/rankings/students', 'reports.students-ranking')->middleware('permission:reports.view')->name('reports.rankings.students');
    Volt::route('reports/rankings', 'reports.rankings')->middleware('permission:reports.view')->name('reports.rankings');
    Volt::route('users', 'users.index')->middleware('permission:users.view')->name('users.index');
    Volt::route('community-contacts', 'community-contacts.index')->middleware('permission:community-contacts.view')->name('community-contacts.index');
    Route::get('users/export', [AdminExportController::class, 'users'])->middleware('permission:users.view')->name('users.export');
    Route::get('id-cards/templates', [IdCardTemplateController::class, 'index'])->middleware('permission:id-cards.view')->name('id-cards.templates.index');
    Route::get('id-cards/templates/create', [IdCardTemplateController::class, 'create'])->middleware('permission:id-cards.templates.manage')->name('id-cards.templates.create');
    Route::post('id-cards/templates', [IdCardTemplateController::class, 'store'])->middleware('permission:id-cards.templates.manage')->name('id-cards.templates.store');
    Route::get('id-cards/templates/{template}/edit', [IdCardTemplateController::class, 'edit'])->middleware('permission:id-cards.templates.manage')->name('id-cards.templates.edit');
    Route::put('id-cards/templates/{template}', [IdCardTemplateController::class, 'update'])->middleware('permission:id-cards.templates.manage')->name('id-cards.templates.update');
    Route::delete('id-cards/templates/{template}', [IdCardTemplateController::class, 'destroy'])->middleware('permission:id-cards.templates.manage')->name('id-cards.templates.destroy');
    Route::get('id-cards/barcode-preview', IdCardBarcodePreviewController::class)->middleware('permission:id-cards.view')->name('id-cards.barcode-preview');
    Route::get('id-cards/print', [PrintTemplatePrintController::class, 'createStudentCards'])->middleware('permission:id-cards.print')->name('id-cards.print.create');
    Route::post('id-cards/print/preview', [PrintTemplatePrintController::class, 'previewStudentCards'])->middleware('permission:id-cards.print')->name('id-cards.print.preview');
    Route::post('id-cards/print/record', [PrintTemplatePrintController::class, 'recordStudentCardPrints'])->middleware('permission:id-cards.print')->name('id-cards.print.record');
    Route::delete('id-cards/print/record', [PrintTemplatePrintController::class, 'clearStudentCardPrints'])->middleware('permission:id-cards.print')->name('id-cards.print.clear');
    Route::get('print-templates', [PrintTemplateController::class, 'index'])->middleware('permission:print-templates.view')->name('print-templates.templates.index');
    Route::get('print-templates/create', [PrintTemplateController::class, 'create'])->middleware('permission:print-templates.manage')->name('print-templates.templates.create');
    Route::post('print-templates', [PrintTemplateController::class, 'store'])->middleware('permission:print-templates.manage')->name('print-templates.templates.store');
    Route::post('print-templates/{template}/copy', [PrintTemplateController::class, 'copy'])->middleware('permission:print-templates.manage')->name('print-templates.templates.copy');
    Route::get('print-templates/{template}/edit', [PrintTemplateController::class, 'edit'])->middleware('permission:print-templates.manage')->name('print-templates.templates.edit');
    Route::put('print-templates/{template}', [PrintTemplateController::class, 'update'])->middleware('permission:print-templates.manage')->name('print-templates.templates.update');
    Route::delete('print-templates/{template}', [PrintTemplateController::class, 'destroy'])->middleware('permission:print-templates.manage')->name('print-templates.templates.destroy');
    Route::get('print-templates/print', [PrintTemplatePrintController::class, 'create'])->middleware('permission:print-templates.print')->name('print-templates.print.create');
    Route::post('print-templates/print/preview', [PrintTemplatePrintController::class, 'preview'])->middleware('permission:print-templates.print|finance.pull-requests.print|finance.expense-requests.print|finance.revenue-requests.print')->name('print-templates.print.preview');
    Volt::route('settings/barcode-actions', 'barcode-actions.index')->middleware('permission:barcode-actions.view')->name('barcode-actions.index');
    Route::post('settings/barcode-actions/print/preview', [BarcodeActionPrintController::class, 'preview'])->middleware('permission:barcode-actions.view')->name('barcode-actions.print.preview');
    Route::redirect('barcode-actions', '/settings/barcode-actions')->middleware('permission:barcode-actions.view')->name('legacy.barcode-actions.index');
    Volt::route('scanner-imports', 'barcode-actions.import')->middleware('permission:barcode-scans.import')->name('barcode-actions.import');
    Route::get('reports/export/attendance', [ReportExportController::class, 'attendance'])->middleware('permission:reports.view')->name('reports.exports.attendance');
    Route::get('reports/export/memorization', [ReportExportController::class, 'memorization'])->middleware('permission:reports.view')->name('reports.exports.memorization');
    Route::get('reports/export/points', [ReportExportController::class, 'points'])->middleware('permission:reports.view')->name('reports.exports.points');
    Route::get('reports/export/student-activity-summary', [ReportExportController::class, 'studentActivitySummary'])->middleware('permission:reports.view')->name('reports.exports.student-activity-summary');
    Route::get('reports/export/student-quran-tests', [ReportExportController::class, 'studentQuranTestSummary'])->middleware('permission:reports.view')->name('reports.exports.student-quran-tests');
    Route::get('reports/export/assessments', [ReportExportController::class, 'assessments'])->middleware('permission:reports.view')->name('reports.exports.assessments');
    Volt::route('settings/general', 'settings.organization')->middleware('permission:settings.manage')->name('settings.organization');
    Route::get('settings/theme', [TenantThemeController::class, 'edit'])->name('settings.theme.edit');
    Route::put('settings/theme', [TenantThemeController::class, 'update'])->name('settings.theme.update');
    Route::delete('settings/theme', [TenantThemeController::class, 'reset'])->name('settings.theme.reset');
    Route::redirect('settings/organization', '/settings/general')->middleware('permission:settings.manage')->name('legacy.settings.organization');
    Volt::route('settings/points', 'settings.tracking')->middleware('permission:settings.manage')->name('settings.points');
    Route::redirect('settings/tracking', '/settings/points')->middleware('permission:settings.manage')->name('settings.tracking');
    Volt::route('settings/learning-progression', 'settings.learning-progression')->middleware('permission:learning-progression.manage')->name('settings.learning-progression');
    Volt::route('settings/course-completion', 'settings.course-completion')->middleware('permission:course-completion-rules.manage')->name('settings.course-completion');
    Volt::route('settings/navigation', 'settings.sidebar-navigation')->middleware('permission:sidebar-navigation.manage')->name('settings.sidebar-navigation');
    Route::redirect('settings/sidebar-navigation', '/settings/navigation')->middleware('permission:sidebar-navigation.manage')->name('legacy.settings.sidebar-navigation');
    Volt::route('settings/finance', 'settings.finance')->middleware(['tenant.feature:finance', 'permission:finance.settings.manage'])->name('settings.finance');
    Volt::route('settings/permissions', 'settings.access-control')->middleware('permission:roles.manage')->name('settings.access-control');
    Route::redirect('settings/access-control', '/settings/permissions')->middleware('permission:roles.manage')->name('legacy.settings.access-control');
    Route::get('settings/backups', BackupSettingsEntryController::class)->middleware('permission:backups.manage')->name('settings.backups');
    Volt::route('settings/system-backups', 'settings.backups')->middleware(['permission:backups.manage', 'no-tenant'])->name('settings.system-backups');
    Route::get('settings/backups/{systemBackup}/download', SystemBackupDownloadController::class)->middleware(['permission:backups.manage', 'no-tenant'])->name('settings.backups.download');
    Route::get('settings/tenant-backups', [TenantBackupController::class, 'index'])->middleware('permission:backups.manage')->name('settings.tenant-backups');
    Route::post('settings/tenant-backups', [TenantBackupController::class, 'create'])->middleware('permission:backups.manage')->name('settings.tenant-backups.create');
    Route::get('settings/tenant-backups/{tenantBackup}/download', [TenantBackupController::class, 'download'])->middleware('permission:backups.manage')->name('settings.tenant-backups.download');
    Route::post('settings/tenant-backups/{tenantBackup}/restore', [TenantBackupController::class, 'restore'])->middleware('permission:backups.manage')->name('settings.tenant-backups.restore');
    Route::get('settings/storage', TenantStorageUsageController::class)->middleware('permission:storage.view')->name('settings.storage');
    Volt::route('settings/website', 'settings.website')->middleware('permission:website.manage')->name('settings.website');
    Volt::route('settings/website/pages', 'settings.website-pages')->middleware('permission:website.manage')->name('settings.website.pages');
    Volt::route('settings/website/navigation', 'settings.website-navigation')->middleware('permission:website.manage')->name('settings.website.navigation');
    Volt::route('data-quality', 'data-quality.index')->middleware('permission:data-quality.view')->name('data-quality.index');
    Volt::route('data-audit', 'data-audit.index')->middleware('permission:data-audit.view')->name('data-audit.index');
    Volt::route('parents', 'parents.index')->middleware('permission:parents.view')->name('parents.index');
    Route::get('parents/export', [AdminExportController::class, 'parents'])->middleware('permission:parents.view')->name('parents.export');
    Volt::route('attendance', 'attendance.index')->middleware('permission:attendance.student.view|attendance.teacher.view')->name('attendance.index');
    Volt::route('attendance/days/{type}/{day}', 'attendance.show')->middleware('permission:attendance.student.view|attendance.teacher.view')->name('attendance.show');
    Volt::route('teacher-attendance', 'teachers.attendance')->middleware('permission:attendance.teacher.view')->name('teacher-attendance.index');
    Route::get('teacher-attendance/export/pdf', TeacherAttendanceExportController::class)->middleware('permission:attendance.teacher.view')->name('teacher-attendance.export');
    Route::get('student-attendance/export/pdf', StudentAttendanceExportController::class)->middleware('permission:attendance.student.view')->name('student-attendance.export');
    Volt::route('teacher-attendance/days/{teacherAttendanceDay}', 'teachers.attendance-show')->middleware('permission:attendance.teacher.view')->name('teacher-attendance.show');
    Route::redirect('teachers/attendance', '/teacher-attendance')->name('legacy.teacher-attendance.index');
    Volt::route('teachers', 'teachers.index')->middleware('permission:teachers.view')->name('teachers.index');
    Route::get('teachers/export', [AdminExportController::class, 'teachers'])->middleware('permission:teachers.view')->name('teachers.export');
    Volt::route('students', 'students.index')->middleware('permission:students.view')->name('students.index');
    Route::get('students/export', [AdminExportController::class, 'students'])->middleware('permission:students.view')->name('students.export');
    Volt::route('students/photos/bulk', 'students.bulk-photos')->middleware('permission:students.update')->name('students.bulk-photos');
    Volt::route('students/progress/{student?}', 'students.progress')->middleware('permission:students.view')->name('students.progress');
    Volt::route('students/{student}/files', 'students.files')->middleware('permission:students.view')->name('students.files');
    Volt::route('courses', 'courses.index')->middleware('permission:courses.view')->name('courses.index');
    Route::get('courses/{course}/calendar.pdf', CourseCalendarPdfController::class)->middleware('permission:courses.view')->name('courses.calendar.pdf');
    Volt::route('courses/{course}/end', 'courses.end')->middleware('permission:courses.view')->name('courses.end');
    Volt::route('courses/{course}/end/point-market', 'courses.point-market')->middleware(['tenant.feature:finance', 'permission:courses.view', 'permission:finance.expense-requests.view'])->name('courses.end.point-market');
    Route::get('courses/{course}/end/students.xlsx', [CourseEndExportController::class, 'students'])->middleware('permission:courses.view')->name('courses.end.students.xlsx');
    Route::get('courses/{course}/end/final-tests.pdf', [CourseEndExportController::class, 'finalTests'])->middleware('permission:courses.view')->name('courses.end.final-tests.pdf');
    Route::get('courses/{course}/end/point-market/departments/{department}/pdf', CoursePointMarketExportController::class)->middleware(['tenant.feature:finance', 'permission:courses.view', 'permission:finance.expense-requests.view'])->name('courses.end.point-market.departments.pdf');
    Route::get('courses/{course}/end/report-cards', [PrintTemplatePrintController::class, 'createCourseReportCards'])->middleware(['permission:courses.view', 'permission:print-templates.print'])->name('courses.end.report-cards.create');
    Route::post('courses/{course}/end/report-cards/preview', [PrintTemplatePrintController::class, 'previewCourseReportCards'])->middleware(['permission:courses.view', 'permission:print-templates.print'])->name('courses.end.report-cards.preview');
    Route::patch('courses/{course}/end/report-cards/notes/{enrollment}', [PrintTemplatePrintController::class, 'updateCourseReportNote'])->middleware(['permission:courses.view', 'permission:print-templates.print'])->name('courses.end.report-cards.notes.update');
    Route::get('courses/export', [AdminExportController::class, 'courses'])->middleware('permission:courses.view')->name('courses.export');
    Volt::route('groups/{group}/attendance', 'groups.attendance')->middleware('permission:attendance.student.view')->name('groups.attendance');
    Volt::route('student-attendance', 'student-attendance.index')->middleware('permission:attendance.student.view')->name('student-attendance.index');
    Volt::route('student-attendance/center/days/{studentAttendanceDay}', 'student-attendance.center-show')->middleware('permission:attendance.student.view')->name('student-attendance.center.show');
    Volt::route('student-attendance/groups/{groupAttendanceDay}', 'student-attendance.mark')->middleware('permission:attendance.student.view')->name('student-attendance.mark');
    Volt::route('student-attendance/days/{studentAttendanceDay}', 'student-attendance.show')->middleware('permission:attendance.student.view')->name('student-attendance.show');
    Volt::route('student-attendance/days/{studentAttendanceDay}/quick', 'student-attendance.quick')->middleware('permission:attendance.student.view')->name('student-attendance.quick');
    Volt::route('groups', 'groups.index')->middleware('permission:groups.view')->name('groups.index');
    Volt::route('groups/{group}/schedules', 'groups.schedules')->middleware('permission:groups.view')->name('groups.schedules');
    Route::get('groups/export', [AdminExportController::class, 'groups'])->middleware('permission:groups.view')->name('groups.export');
    Route::get('groups/{group}/roster/export', [AdminExportController::class, 'groupRoster'])->middleware('permission:groups.view')->name('groups.roster.export');
    Route::get('groups/{group}/roster/pdf', [AdminExportController::class, 'groupRosterPdf'])->middleware('permission:groups.view')->name('groups.roster.pdf');
    Volt::route('groups/{group}', 'groups.show')->middleware('permission:groups.view')->name('groups.show');
    Volt::route('curricula', 'curricula.index')->name('curricula.index');
    Volt::route('curricula/{curriculum}', 'curricula.show')->name('curricula.show');
    Route::get('curriculum-resources/{resource}/download', CurriculumResourceDownloadController::class)->name('curriculum-resources.download');
    Volt::route('settings/subjects', 'settings.curriculum-subjects')->middleware('permission:curricula.manage')->name('settings.curriculum-subjects');
    Route::redirect('settings/curriculum-subjects', '/settings/subjects')->middleware('permission:curricula.manage')->name('legacy.settings.curriculum-subjects');
    Volt::route('enrollments', 'enrollments.index')->middleware('permission:enrollments.view')->name('enrollments.index');
    Route::get('enrollments/export', [AdminExportController::class, 'enrollments'])->middleware('permission:enrollments.view')->name('enrollments.export');
    Volt::route('assessments', 'assessments.index')->middleware('permission:assessments.view')->name('assessments.index');
    Volt::route('assessments/bands', 'assessments.bands')->middleware('permission:assessment-score-bands.view')->name('assessments.bands');
    Volt::route('assessments/{assessment}/results', 'assessments.results')->middleware('permission:assessment-results.view')->name('assessments.results');
    Route::get('assessments/{assessment}/results/pdf', AssessmentResultPdfController::class)->middleware('permission:assessment-results.view')->name('assessments.results.pdf');
    Volt::route('student-notes', 'student-notes.index')->middleware('permission:student-notes.view')->name('student-notes.index');
    Volt::route('memorization/quick-entry', 'memorization.quick-entry')->middleware('permission:memorization.record')->name('memorization.quick-entry');
    Volt::route('memorization', 'memorization.index')->middleware('permission:memorization.view')->name('memorization.index');
    Volt::route('saber-entry', 'quran-tests.quick-entry')->name('saber-entry.index');
    Volt::route('quran-partial-tests', 'quran-partial-tests.index')->middleware('permission:quran-partial-tests.view')->name('quran-partial-tests.index');
    Volt::route('quran-partial-tests/{partialTest}', 'quran-partial-tests.show')->middleware('permission:quran-partial-tests.view')->name('quran-partial-tests.show');
    Volt::route('quran-final-tests', 'quran-final-tests.index')->middleware('permission:quran-final-tests.view')->name('quran-final-tests.index');
    Volt::route('quran-final-tests/{finalTest}', 'quran-final-tests.show')->middleware('permission:quran-final-tests.view')->name('quran-final-tests.show');
    Volt::route('quran-tests', 'quran-tests.index')->middleware('permission:quran-awqaf-tests.view|quran-tests.view')->name('quran-tests.index');
    Route::get('quran-tests/eligible-awqaf/export', [AdminExportController::class, 'eligibleAwqafStudents'])->middleware('permission:quran-awqaf-tests.view|quran-tests.view')->name('quran-tests.eligible-awqaf.export');
    Volt::route('points', 'points.index')->middleware('permission:points.view')->name('points.index');
    Volt::route('enrollments/{enrollment}/memorization', 'enrollments.memorization')->middleware('permission:memorization.view')->name('enrollments.memorization');
    Volt::route('enrollments/{enrollment}/quran-tests', 'enrollments.quran-tests')->middleware('permission:quran-awqaf-tests.view|quran-tests.view')->name('enrollments.quran-tests');
    Volt::route('enrollments/{enrollment}/points', 'enrollments.points')->middleware('permission:points.view')->name('enrollments.points');
    Volt::route('activities', 'activities.index')->middleware('permission:activities.view')->name('activities.index');
    Volt::route('activities/family', 'activities.family')->middleware('permission:activities.responses.view')->name('activities.family');
    Volt::route('student-billing', 'student-billing.index')->middleware('permission:invoices.view')->name('student-billing.index');
    Route::middleware('tenant.feature:finance')->group(function (): void {
        Volt::route('activities/{activity}/finance', 'activities.finance')->middleware('permission:activities.finance.view')->name('activities.finance');
        Volt::route('finance', 'finance.dashboard')->middleware('permission:finance.reports.view')->name('finance.dashboard');
        Volt::route('finance/reports', 'finance.reports')->middleware('permission:finance.reports.view')->name('finance.reports.index');
        Route::get('finance/reports/ledger/export', [ReportExportController::class, 'financeLedger'])->middleware('permission:finance.reports.export')->name('finance.reports.ledger.export');
        Route::get('finance/reports/generated/{generatedReport}', [ReportExportController::class, 'generatedFinanceLedger'])->middleware('permission:finance.reports.export')->name('finance.reports.generated.show');
        Volt::route('finance/pull-requests', 'finance.pull-requests')->middleware('permission:finance.pull-requests.view')->name('finance.pull-requests.index');
        Volt::route('finance/cash-box', 'finance.cash-box')->middleware('permission:finance.cash-box.view')->name('finance.cash-box.index');
        Volt::route('finance/expense-requests', 'finance.expense-requests')->middleware('permission:finance.expense-requests.view')->name('finance.expense-requests.index');
        Volt::route('finance/revenue-requests', 'finance.revenue-requests')->middleware('permission:finance.revenue-requests.view')->name('finance.revenue-requests.index');
        Volt::route('finance/exchange', 'finance.exchange')->middleware('permission:finance.exchange.view')->name('finance.exchange.index');
        Route::get('finance/requests/{financeRequest}/print', FinanceRequestPrintController::class)->name('finance.requests.print');
        Route::get('finance/invoices/{invoice}/items.xlsx', FinanceInvoiceItemsExportController::class)->name('finance.invoices.items.xlsx');
        Route::get('finance/invoices/{invoice}/print', FinanceInvoicePrintController::class)->name('finance.invoices.print');
        Volt::route('invoices', 'invoices.index')->middleware('permission:finance.expense-requests.view')->name('invoices.index');
        Volt::route('invoices/{invoice}/payments', 'invoices.payments')->middleware('permission:finance.expense-requests.view|invoices.view')->name('invoices.payments');
        Route::get('invoices/{invoice}/print', [PrintController::class, 'invoice'])->middleware('permission:finance.expense-requests.view|invoices.view')->name('invoices.print');
        Route::get('payments/{payment}/receipt', [PrintController::class, 'receipt'])->middleware('permission:payments.view')->name('payments.receipt');
    });

    Route::redirect('settings', 'settings/profile');

    Volt::route('settings/profile', 'settings.profile')->name('settings.profile');
    Route::redirect('settings/password', '/settings/profile')->name('settings.password');
    Route::redirect('settings/appearance', '/settings/profile')->name('settings.appearance');
});

require __DIR__.'/auth.php';

// Run the web session and locale middleware for unknown website/dashboard URLs.
Route::fallback(fn () => abort(404));
