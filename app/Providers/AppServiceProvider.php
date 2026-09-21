<?php

namespace App\Providers;

use App\Http\Middleware\EnsureTenantModules;
use App\Models\AcademicYear;
use App\Models\Activity;
use App\Models\AppSetting;
use App\Models\Assessment;
use App\Models\AssessmentResult;
use App\Models\Course;
use App\Models\Curriculum;
use App\Models\DataQualityResolution;
use App\Models\Enrollment;
use App\Models\FinanceCashBox;
use App\Models\FinanceCashBoxTransfer;
use App\Models\FinanceCurrencyExchange;
use App\Models\FinanceRequest;
use App\Models\FinanceTransaction;
use App\Models\Group;
use App\Models\GroupAttendanceDay;
use App\Models\Invoice;
use App\Models\MemorizationSession;
use App\Models\ParentProfile;
use App\Models\Payment;
use App\Models\PointTransaction;
use App\Models\QuranFinalTest;
use App\Models\QuranPartialTest;
use App\Models\QuranTest;
use App\Models\Student;
use App\Models\StudentAttendanceDay;
use App\Models\StudentAttendanceRecord;
use App\Models\StudentNote;
use App\Models\SystemBackup;
use App\Models\Teacher;
use App\Models\TeacherAttendanceDay;
use App\Models\TeacherAttendanceRecord;
use App\Models\User;
use App\Observers\DataAuditObserver;
use App\Services\Landlord\CurrentModuleAccess;
use App\Services\Landlord\TenantContext;
use App\Support\ApplicationTimezone;
use App\Support\RoleRegistry;
use App\Validation\LocalizedValidator;
use Illuminate\Contracts\Translation\Translator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator as ValidatorFacade;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(TenantContext::class);
        $this->app->scoped(CurrentModuleAccess::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Livewire::addPersistentMiddleware([EnsureTenantModules::class]);
        app(ApplicationTimezone::class)->applyConfigured();

        ValidatorFacade::resolver(static function (Translator $translator, array $data, array $rules, array $messages, array $attributes): LocalizedValidator {
            return new LocalizedValidator($translator, $data, $rules, $messages, $attributes);
        });

        Gate::before(static function (User $user, string $ability): ?bool {
            if (! app(CurrentModuleAccess::class)->permissionAvailable($ability)) {
                return false;
            }

            return $user->hasRole(RoleRegistry::SUPER_ADMIN) ? true : null;
        });
        app(PermissionRegistrar::class)->registerPermissions(Gate::getFacadeRoot());

        foreach ([
            AcademicYear::class,
            Activity::class,
            AppSetting::class,
            Assessment::class,
            AssessmentResult::class,
            Course::class,
            Curriculum::class,
            DataQualityResolution::class,
            Enrollment::class,
            FinanceCashBox::class,
            FinanceCashBoxTransfer::class,
            FinanceCurrencyExchange::class,
            FinanceRequest::class,
            FinanceTransaction::class,
            Group::class,
            GroupAttendanceDay::class,
            Invoice::class,
            MemorizationSession::class,
            ParentProfile::class,
            Payment::class,
            PointTransaction::class,
            QuranFinalTest::class,
            QuranPartialTest::class,
            QuranTest::class,
            Student::class,
            StudentAttendanceDay::class,
            StudentAttendanceRecord::class,
            StudentNote::class,
            SystemBackup::class,
            Teacher::class,
            TeacherAttendanceDay::class,
            TeacherAttendanceRecord::class,
            User::class,
        ] as $model) {
            $model::observe(DataAuditObserver::class);
        }
    }
}
