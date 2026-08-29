<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Activity;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Group;
use App\Models\Invoice;
use App\Models\MemorizationSession;
use App\Models\ParentProfile;
use App\Models\QuranFinalTest;
use App\Models\QuranJuz;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use App\Services\SidebarNavigationService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ManagementPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_numeric_inputs_hide_native_stepper_controls_globally(): void
    {
        $styles = file_get_contents(resource_path('css/app.css'));

        $this->assertStringContainsString("input[type='number'] {", $styles);
        $this->assertStringContainsString('-moz-appearance: textfield;', $styles);
        $this->assertStringContainsString("input[type='number']::-webkit-inner-spin-button,", $styles);
        $this->assertStringContainsString("input[type='number']::-webkit-outer-spin-button", $styles);
        $this->assertStringContainsString('-webkit-appearance: none;', $styles);

        foreach ([
            'livewire/quran-tests/quick-entry.blade.php',
            'livewire/quran-partial-tests/show.blade.php',
            'livewire/quran-final-tests/show.blade.php',
            'livewire/students/index.blade.php',
            'livewire/assessments/results.blade.php',
        ] as $view) {
            $source = file_get_contents(resource_path('views/'.$view));

            $this->assertStringContainsString('type="number"', $source, $view);
        }
    }

    public function test_pdf_actions_use_the_shared_icon_without_visible_text(): void
    {
        $pdfActions = [
            'livewire/courses/end.blade.php' => ['courses.end.final-tests.pdf' => 1],
            'livewire/courses/point-market.blade.php' => ['courses.end.point-market.departments.pdf' => 1],
            'livewire/groups/show.blade.php' => ['groups.roster.pdf' => 1],
            'livewire/groups/index.blade.php' => ['groups.roster.pdf' => 1],
            'livewire/assessments/results.blade.php' => ['assessments.results.pdf' => 2],
        ];

        foreach ($pdfActions as $view => $routes) {
            $source = file_get_contents(resource_path('views/'.$view));

            foreach ($routes as $route => $expectedCount) {
                $needle = "route('{$route}'";
                $offset = 0;
                $actions = [];

                while (($routePosition = strpos($source, $needle, $offset)) !== false) {
                    $actionStart = strrpos(substr($source, 0, $routePosition), '<a ');
                    $actionEnd = strpos($source, '</a>', $routePosition);

                    $this->assertNotFalse($actionStart);
                    $this->assertNotFalse($actionEnd);

                    $actions[] = substr($source, $actionStart, $actionEnd + 4 - $actionStart);
                    $offset = $routePosition + strlen($needle);
                }

                $this->assertCount($expectedCount, $actions, $view.' should render each PDF action as an icon button.');

                foreach ($actions as $action) {
                    $this->assertStringContainsString('class="admin-icon-button', $action);
                    $this->assertStringContainsString('title="', $action);
                    $this->assertStringContainsString('aria-label="', $action);
                    $this->assertStringContainsString('<x-pdf-export-icon />', $action);
                    $this->assertStringNotContainsString('<span', $action);
                }
            }
        }

        $assessmentResults = file_get_contents(resource_path('views/livewire/assessments/results.blade.php'));
        $financeReports = file_get_contents(resource_path('views/livewire/finance/reports.blade.php'));
        $styles = file_get_contents(resource_path('css/app.css'));

        $this->assertStringContainsString('admin-icon-button assessment-results-filter-pdf-button', $assessmentResults);
        $this->assertStringContainsString('.assessment-results-filter-pdf-button {', $styles);
        $this->assertStringContainsString('flex-basis: 3.125rem;', $styles);
        $this->assertStringContainsString('data-finance-report-create-save-action', $financeReports);
        $this->assertStringContainsString('<x-admin-action-icon name="save" class="admin-modal-action__icon" />', $financeReports);
    }

    public function test_non_pdf_export_actions_use_the_shared_export_symbol_without_visible_text(): void
    {
        foreach ([
            'livewire/users/index.blade.php' => 1,
            'livewire/courses/index.blade.php' => 1,
            'livewire/enrollments/index.blade.php' => 1,
            'livewire/groups/index.blade.php' => 1,
            'livewire/parents/index.blade.php' => 1,
            'livewire/students/index.blade.php' => 1,
            'livewire/teachers/index.blade.php' => 1,
            'livewire/student-attendance/index.blade.php' => 2,
            'livewire/teachers/attendance.blade.php' => 2,
            'livewire/reports/student-activity-summary.blade.php' => 1,
            'livewire/reports/student-quran-tests.blade.php' => 1,
        ] as $view => $expectedCount) {
            $source = file_get_contents(resource_path('views/'.$view));

            $this->assertSame(
                $expectedCount,
                substr_count($source, '<x-export-action-button'),
                $view.' should render every non-PDF Export action as the shared icon button.',
            );
        }

        $button = file_get_contents(resource_path('views/components/export-action-button.blade.php'));
        $icon = file_get_contents(resource_path('views/components/admin-action-icon.blade.php'));

        $this->assertStringContainsString('$attributes->class(\'admin-icon-button\')', $button);
        $this->assertStringContainsString('title="{{ $label }}"', $button);
        $this->assertStringContainsString('aria-label="{{ $label }}"', $button);
        $this->assertStringContainsString('data-export-action', $button);
        $this->assertStringContainsString('<x-admin-action-icon name="export" />', $button);
        $this->assertSame(0, preg_match('/>\s*\{\{ \$label \}\}\s*</', $button));
        $this->assertStringContainsString("@case('export')", $icon);
        $this->assertStringContainsString('M12 15.5V4m0 0L7.75 8.25M12 4l4.25 4.25', $icon);

        $pdfIcon = file_get_contents(resource_path('views/components/pdf-export-icon.blade.php'));
        $this->assertStringNotContainsString('name="export"', $pdfIcon);
    }

    public function test_saved_financial_reports_use_the_view_file_icon(): void
    {
        $reports = file_get_contents(resource_path('views/livewire/finance/reports.blade.php'));
        $icons = file_get_contents(resource_path('views/components/admin-action-icon.blade.php'));

        $this->assertStringContainsString('data-financial-record-view-action', $reports);
        $this->assertStringContainsString('class="admin-icon-button"', $reports);
        $this->assertStringContainsString('<x-admin-action-icon name="view-file" />', $reports);
        $this->assertStringContainsString("@case('view-file')", $icons);
    }

    public function test_report_hub_excel_exports_keep_their_visible_names(): void
    {
        $reports = file_get_contents(resource_path('views/livewire/reports/index.blade.php'));
        $styles = file_get_contents(resource_path('css/app.css'));

        $this->assertSame(5, substr_count($reports, 'class="pill-link'));
        $this->assertSame(5, substr_count($reports, 'report-export-link'));
        $this->assertStringContainsString('class="report-export-list mt-4"', $reports);
        $this->assertStringContainsString("{{ __('reports.exports.attendance') }}</a>", $reports);
        $this->assertStringContainsString("{{ __('reports.exports.memorization') }}</a>", $reports);
        $this->assertStringContainsString("{{ __('reports.exports.points') }}</a>", $reports);
        $this->assertStringContainsString("{{ __('reports.student_activity.export') }}</a>", $reports);
        $this->assertStringContainsString("{{ __('reports.exports.assessments') }}</a>", $reports);
        $this->assertStringNotContainsString('<x-export-action-button', $reports);
        $this->assertStringContainsString(".report-export-list {\n    display: grid;\n    width: 100%;\n    grid-template-columns: repeat(5, minmax(0, 1fr));", $styles);
        $this->assertStringContainsString(".report-export-link {\n    width: 100%;\n    min-width: 0;", $styles);
    }

    public function test_add_new_launchers_use_the_shared_plus_symbol_without_visible_text(): void
    {
        foreach ([
            'livewire/students/index.blade.php' => 5,
            'livewire/teachers/index.blade.php' => 1,
            'livewire/groups/index.blade.php' => 1,
            'livewire/courses/index.blade.php' => 1,
            'livewire/enrollments/index.blade.php' => 1,
            'livewire/points/index.blade.php' => 1,
            'livewire/student-attendance/index.blade.php' => 1,
            'livewire/teachers/attendance.blade.php' => 1,
            'livewire/community-contacts/index.blade.php' => 1,
            'livewire/assessments/index.blade.php' => 1,
            'livewire/assessments/bands.blade.php' => 1,
            'livewire/activities/index.blade.php' => 1,
            'livewire/invoices/index.blade.php' => 1,
            'livewire/student-notes/index.blade.php' => 1,
            'livewire/finance/expense-requests.blade.php' => 1,
            'livewire/finance/revenue-requests.blade.php' => 1,
            'livewire/finance/pull-requests.blade.php' => 1,
            'livewire/finance/partials/requests-table.blade.php' => 1,
            'livewire/settings/finance-report-templates.blade.php' => 2,
            'livewire/settings/access-control.blade.php' => 1,
            'livewire/settings/points.blade.php' => 2,
            'livewire/settings/tracking.blade.php' => 2,
            'livewire/settings/finance.blade.php' => 5,
            'livewire/settings/organization.blade.php' => 5,
            'livewire/settings/website-pages.blade.php' => 3,
            'livewire/settings/website-navigation.blade.php' => 3,
            'livewire/settings/website.blade.php' => 2,
            'livewire/curricula/index.blade.php' => 2,
            'livewire/curricula/show.blade.php' => 2,
            'livewire/groups/show.blade.php' => 1,
            'livewire/student-attendance/show.blade.php' => 1,
            'livewire/teachers/attendance-show.blade.php' => 1,
            'livewire/students/progress.blade.php' => 0,
            'livewire/parents/index.blade.php' => 1,
            'livewire/settings/course-completion.blade.php' => 1,
            'livewire/settings/curriculum-subjects.blade.php' => 3,
            'livewire/settings/sidebar-navigation.blade.php' => 1,
            'id-cards/templates/index.blade.php' => 1,
            'print-templates/templates/index.blade.php' => 1,
            'print-templates/print/setup.blade.php' => 1,
        ] as $view => $expectedCount) {
            $source = file_get_contents(resource_path('views/'.$view));

            $this->assertSame($expectedCount, substr_count($source, '<x-add-action-button'), $view);
        }

        $button = file_get_contents(resource_path('views/components/add-action-button.blade.php'));

        $this->assertStringContainsString("'admin-icon-button'", $button);
        $this->assertStringContainsString("'admin-icon-button--accent' => \$accent", $button);
        $this->assertStringContainsString('title="{{ $label }}"', $button);
        $this->assertStringContainsString('aria-label="{{ $label }}"', $button);
        $this->assertStringContainsString('data-add-action', $button);
        $this->assertStringContainsString('<x-admin-action-icon name="add" />', $button);
        $this->assertSame(0, preg_match('/>\s*\{\{ \$label \}\}\s*</', $button));
    }

    public function test_back_links_are_clickable_non_selectable_navigation_targets(): void
    {
        $backLink = file_get_contents(resource_path('views/components/back-link.blade.php'));
        $styles = file_get_contents(resource_path('css/app.css'));

        $this->assertStringContainsString('href="{{ $href }}"', $backLink);
        $this->assertStringContainsString('draggable="false"', $backLink);
        $this->assertStringContainsString('@if ($navigate) wire:navigate @endif', $backLink);
        $this->assertStringContainsString(".app-back-link {\n    position: relative;\n    z-index: 2;\n    min-height: 2rem;\n    cursor: pointer;", $styles);
        $this->assertStringContainsString('touch-action: manipulation;', $styles);
        $this->assertStringContainsString('pointer-events: auto;', $styles);
        $this->assertStringContainsString('user-select: none;', $styles);
        $this->assertStringContainsString('.app-back-link > * {', $styles);
    }

    public function test_attendance_day_controls_use_scan_lock_and_delete_symbols(): void
    {
        $studentAttendance = file_get_contents(resource_path('views/livewire/student-attendance/show.blade.php'));
        $teacherAttendance = file_get_contents(resource_path('views/livewire/teachers/attendance-show.blade.php'));
        $icons = file_get_contents(resource_path('views/components/admin-action-icon.blade.php'));
        $quickAttendanceIcon = file_get_contents(resource_path('views/components/quick-attendance-icon.blade.php'));
        $styles = file_get_contents(resource_path('css/app.css'));

        $this->assertStringContainsString('data-student-quick-attendance-action', $studentAttendance);
        $this->assertStringContainsString('<x-quick-attendance-icon />', $studentAttendance);
        $this->assertStringContainsString('viewBox="50 50 454 454"', $quickAttendanceIcon);
        $this->assertStringContainsString('data-quick-attendance-icon="supplied-barcode-scanner"', $quickAttendanceIcon);
        $this->assertStringContainsString('stroke-width="16"', $quickAttendanceIcon);
        $this->assertStringContainsString('stroke-width="2"', $quickAttendanceIcon);
        $this->assertStringContainsString('stroke-linecap="round" stroke-linejoin="round"', $quickAttendanceIcon);
        $this->assertStringContainsString('M85.1,268.7h382.6', $quickAttendanceIcon);
        $this->assertStringContainsString('<rect x="273" y="148.7" width="14.1" height="102.3" />', $quickAttendanceIcon);
        $this->assertMatchesRegularExpression('/\.attendance-quick-action > svg\s*\{[^}]*width:\s*1\.15rem;[^}]*height:\s*1\.15rem;[^}]*flex-basis:\s*1\.15rem;/s', $styles);
        $this->assertStringContainsString('data-student-attendance-day-status-action', $studentAttendance);
        $this->assertStringContainsString('data-teacher-attendance-day-status-action', $teacherAttendance);
        $this->assertSame(2, substr_count($studentAttendance.$teacherAttendance, '<x-admin-action-icon name="unlock" />'));
        $this->assertSame(2, substr_count($studentAttendance.$teacherAttendance, '<x-admin-action-icon name="lock" />'));
        $this->assertStringContainsString('data-student-attendance-day-delete-action', $studentAttendance);
        $this->assertStringContainsString('data-teacher-attendance-day-delete-action', $teacherAttendance);
        $this->assertStringContainsString('data-student-attendance-day-date-metric', $studentAttendance);
        $this->assertStringContainsString("__('workflow.student_attendance.form.attendance_date')", $studentAttendance);
        $this->assertStringContainsString('data-teacher-attendance-day-date-metric', $teacherAttendance);
        $this->assertStringContainsString("__('workflow.teacher_attendance.form.attendance_date')", $teacherAttendance);
        $this->assertSame(2, substr_count($studentAttendance.$teacherAttendance, "attendance_date?->format('d-m-Y')"));
        $this->assertSame(2, substr_count($studentAttendance.$teacherAttendance, 'shrink-0 rounded-2xl border border-emerald-300/20 bg-emerald-400/10 px-5 py-3 text-center shadow-inner'));
        $this->assertStringNotContainsString('@case(\'scan-barcode\')', $icons);
        $this->assertStringContainsString('@case(\'lock\')', $icons);
        $this->assertStringContainsString('@case(\'unlock\')', $icons);
    }

    public function test_partial_and_final_saber_header_deletes_use_shared_square_buttons(): void
    {
        foreach ([
            'livewire/quran-partial-tests/show.blade.php' => 'data-partial-saber-delete',
            'livewire/quran-final-tests/show.blade.php' => 'data-final-saber-delete',
        ] as $view => $marker) {
            $source = file_get_contents(resource_path('views/'.$view));
            $buttonStart = strrpos(substr($source, 0, strpos($source, $marker)), '<button');
            $buttonEnd = strpos($source, '</button>', strpos($source, $marker));
            $button = substr($source, $buttonStart, $buttonEnd + 9 - $buttonStart);

            $this->assertStringContainsString('class="admin-icon-button admin-icon-button--danger"', $button);
            $this->assertStringContainsString('<x-admin-action-icon name="delete" />', $button);
            $this->assertStringNotContainsString('rounded-full', $button);
        }
    }

    public function test_row_open_actions_use_the_shared_open_symbol_without_visible_text(): void
    {
        foreach ([
            'livewire/student-attendance/index.blade.php',
            'livewire/teachers/attendance.blade.php',
            'livewire/student-attendance/show.blade.php',
            'livewire/assessments/index.blade.php',
            'livewire/curricula/index.blade.php',
            'livewire/groups/index.blade.php',
            'livewire/quran-partial-tests/index.blade.php',
            'livewire/quran-final-tests/index.blade.php',
            'print-templates/templates/index.blade.php',
        ] as $view) {
            $source = file_get_contents(resource_path('views/'.$view));

            $this->assertStringContainsString('<x-open-action-button', $source);
        }

        $button = file_get_contents(resource_path('views/components/open-action-button.blade.php'));

        $this->assertStringContainsString('$attributes->class(\'admin-icon-button\')', $button);
        $this->assertStringContainsString('title="{{ $label }}"', $button);
        $this->assertStringContainsString('aria-label="{{ $label }}"', $button);
        $this->assertStringContainsString('data-open-action', $button);
        $this->assertStringContainsString('<x-admin-action-icon name="open" />', $button);

        $icon = file_get_contents(resource_path('views/components/admin-action-icon.blade.php'));
        $this->assertStringNotContainsString('transform="translate(-0.5 0.75)"', $icon);
        $this->assertStringContainsString('<rect x="4" y="4" width="16" height="16" rx="3" />', $icon);

        $studentAttendance = file_get_contents(resource_path('views/livewire/student-attendance/index.blade.php'));
        $teacherAttendance = file_get_contents(resource_path('views/livewire/teachers/attendance.blade.php'));

        $this->assertStringNotContainsString('data-attendance-day-view-icon', $studentAttendance.$teacherAttendance);
        $this->assertStringNotContainsString('M2.25 12s3.75-6.75', $studentAttendance.$teacherAttendance);

        $reports = file_get_contents(resource_path('views/livewire/reports/index.blade.php'));

        $this->assertSame(2, substr_count($reports, 'data-report-nav-open-icon'));
        $this->assertSame(2, substr_count($reports, '<x-admin-action-icon name="open" />'));
        $this->assertStringNotContainsString(">{{ __('reports.navigation.open') }}</span>", $reports);
    }

    public function test_course_end_card_actions_use_print_and_open_symbols_without_visible_text(): void
    {
        $courseEnd = file_get_contents(resource_path('views/livewire/courses/end.blade.php'));

        $this->assertStringContainsString('data-course-students-xlsx-action', $courseEnd);
        $this->assertStringContainsString('<x-invoice-xlsx-export-icon />', $courseEnd);
        $this->assertStringContainsString("title=\"{{ __('finance.actions.export_excel') }}\"", $courseEnd);
        $this->assertStringNotContainsString('class="pill-link pill-link--accent">XLSX</a>', $courseEnd);

        $this->assertStringContainsString('data-course-report-cards-print-action', $courseEnd);
        $this->assertStringContainsString('<x-admin-action-icon name="print" />', $courseEnd);
        $this->assertStringContainsString("title=\"{{ __('course_end.print_cards') }}\"", $courseEnd);
        $this->assertStringNotContainsString("class=\"pill-link pill-link--accent\">{{ __('course_end.print_cards') }}", $courseEnd);

        $this->assertStringContainsString('data-course-point-market-open-action', $courseEnd);
        $this->assertStringContainsString('<x-open-action-button :href="route(\'courses.end.point-market\', $course)"', $courseEnd);
        $this->assertStringContainsString(":label=\"__('course_end.point_market.open')\"", $courseEnd);
        $this->assertStringNotContainsString("class=\"pill-link pill-link--accent\">{{ __('course_end.point_market.open') }}", $courseEnd);
    }

    public function test_view_all_actions_use_the_expand_symbol_without_visible_text(): void
    {
        foreach ([
            'livewire/dashboard.blade.php' => 'dashboard.teacher.group_dashboard.view_all',
            'livewire/finance/dashboard.blade.php' => 'finance.actions.view_all',
        ] as $view => $translation) {
            $source = file_get_contents(resource_path('views/'.$view));
            $buttonStart = strrpos(substr($source, 0, strpos($source, 'data-view-all-expand')), '<button');
            $buttonEnd = strpos($source, '</button>', $buttonStart);

            $this->assertNotFalse($buttonStart);
            $this->assertNotFalse($buttonEnd);

            $button = substr($source, $buttonStart, $buttonEnd + 9 - $buttonStart);

            $this->assertStringContainsString('class="admin-icon-button', $button);
            $this->assertStringContainsString("title=\"{{ __('{$translation}') }}\"", $button);
            $this->assertStringContainsString("aria-label=\"{{ __('{$translation}') }}\"", $button);
            $this->assertStringContainsString('<x-admin-action-icon name="expand" />', $button);
            $this->assertStringNotContainsString(">{{ __('{$translation}') }}<", $button);
        }

        $studentProgress = file_get_contents(resource_path('views/components/student-progress-table.blade.php'));
        $buttonStart = strrpos(substr($studentProgress, 0, strpos($studentProgress, 'data-view-all-expand')), '<button');
        $buttonEnd = strpos($studentProgress, '</button>', $buttonStart);
        $button = substr($studentProgress, $buttonStart, $buttonEnd + 9 - $buttonStart);

        $this->assertStringContainsString('class="student-progress-data-table__expand"', $button);
        $this->assertStringNotContainsString('admin-icon-button', $button);
        $this->assertStringContainsString("title=\"{{ __('workflow.student_progress.actions.view_all') }}\"", $button);
        $this->assertStringContainsString("aria-label=\"{{ __('workflow.student_progress.actions.view_all') }}\"", $button);
        $this->assertStringContainsString('<x-admin-action-icon name="expand" />', $button);
        $this->assertStringNotContainsString(">{{ __('workflow.student_progress.actions.view_all') }}<", $button);

        $styles = file_get_contents(resource_path('css/app.css'));
        $this->assertMatchesRegularExpression('/\.student-progress-data-table__expand\s*\{[^}]*height:\s*1\.5rem;[^}]*border:\s*0;[^}]*background:\s*transparent;/s', $styles);
        $this->assertMatchesRegularExpression('/\.student-progress-data-table__expand\s*\{[^}]*color:\s*#fff4db;/s', $styles);
        $this->assertStringContainsString('html:not(.dark) .student-progress-data-table__expand,', $styles);
        $this->assertMatchesRegularExpression('/\.student-progress-data-table__header \.admin-grid-meta__title\s*\{[^}]*line-height:\s*1\.5rem;/s', $styles);

        $icon = file_get_contents(resource_path('views/components/admin-action-icon.blade.php'));

        $this->assertStringContainsString("@case('expand')", $icon);
        $this->assertStringContainsString('m10 10-5.5-5.5m0 4.5V4.5H9m5 5.5 5.5-5.5', $icon);
        $this->assertStringContainsString('M10 14l-5.5 5.5m0-4.5v4.5H9m5-5.5 5.5 5.5', $icon);
    }

    public function test_finance_dashboard_header_actions_use_the_requested_symbols_without_visible_text(): void
    {
        $dashboard = file_get_contents(resource_path('views/livewire/finance/dashboard.blade.php'));
        $icon = file_get_contents(resource_path('views/components/admin-action-icon.blade.php'));

        foreach ([
            'data-finance-dashboard-details' => ['chart', 'finance.actions.details'],
            'data-finance-dashboard-request-history' => ['history', 'finance.dashboard.previous_requests'],
            'data-finance-dashboard-new-request' => ['add', 'finance.pull_requests.new'],
        ] as $marker => [$iconName, $translation]) {
            $this->assertStringContainsString($marker, $dashboard);
            $this->assertStringContainsString("title=\"{{ __('{$translation}') }}\"", $dashboard);
            $this->assertStringContainsString("aria-label=\"{{ __('{$translation}') }}\"", $dashboard);
            $this->assertStringContainsString("<x-admin-action-icon name=\"{$iconName}\" />", $dashboard);
        }

        $this->assertStringContainsString("@case('chart')", $icon);
        $this->assertStringContainsString('M4 3.5v15.25A1.75 1.75 0 0 0 5.75 20.5H21', $icon);
        $this->assertStringContainsString("@case('history')", $icon);
        $this->assertStringContainsString('<g transform="translate(0.5 0.75)">', $icon);
        $this->assertStringContainsString('M18.25 6.25A8.25 8.25 0 0 0 5.15 5L3.5 7.5', $icon);
        $this->assertStringContainsString("@case('add')", $icon);
        $this->assertStringContainsString('M12 5v14M5 12h14', $icon);
        $this->assertStringContainsString('data-finance-dashboard-create-request-save', $dashboard);
        $this->assertStringContainsString('data-finance-dashboard-transfer-action', $dashboard);
        $this->assertStringContainsString('<x-admin-action-icon name="transfer" class="admin-modal-action__icon" />', $dashboard);
        $this->assertStringContainsString("@case('transfer')", $icon);
        $this->assertStringContainsString('title="{{ __(\'crud.common.actions.save\') }}"', $dashboard);
        $this->assertStringContainsString('aria-label="{{ __(\'crud.common.actions.save\') }}"', $dashboard);
        $this->assertStringContainsString('<x-admin-action-icon name="save" class="admin-modal-action__icon" />', $dashboard);
        $this->assertStringNotContainsString('<button class="pill-link pill-link--accent">{{ __(\'finance.actions.create\') }}</button>', $dashboard);
    }

    public function test_expense_finalise_row_action_uses_the_confirmation_symbol_without_visible_text(): void
    {
        $expenses = file_get_contents(resource_path('views/livewire/finance/expense-requests.blade.php'));
        $icon = file_get_contents(resource_path('views/components/admin-action-icon.blade.php'));

        $this->assertStringContainsString('data-expense-finalise-action', $expenses);
        $this->assertStringContainsString('class="admin-icon-button admin-icon-button--accent"', $expenses);
        $this->assertStringContainsString("title=\"{{ __('finance.actions.finalise') }}\"", $expenses);
        $this->assertStringContainsString("aria-label=\"{{ __('finance.actions.finalise') }}\"", $expenses);
        $this->assertStringContainsString('<x-admin-action-icon name="finalise" />', $expenses);
        $this->assertStringNotContainsString("pill-link pill-link--compact pill-link--accent\">{{ __('finance.actions.finalise') }}", $expenses);
        $this->assertStringContainsString("@case('finalise')", $icon);
        $this->assertStringContainsString('M21 10.65V19a2 2 0 0 1-2 2H5', $icon);
        $this->assertStringContainsString('m9 11 3 3L22 4', $icon);
        $this->assertStringNotContainsString('<circle cx="12" cy="12" r="6.25" />', $icon);
    }

    public function test_expense_invoice_row_action_uses_the_receipt_symbol_without_visible_text(): void
    {
        $expenses = file_get_contents(resource_path('views/livewire/finance/expense-requests.blade.php'));
        $icon = file_get_contents(resource_path('views/components/admin-action-icon.blade.php'));

        $this->assertStringContainsString('data-expense-receipt-action', $expenses);
        $this->assertStringContainsString("title=\"{{ __('finance.actions.view_invoice') }}\"", $expenses);
        $this->assertStringContainsString("aria-label=\"{{ __('finance.actions.view_invoice') }}\"", $expenses);
        $this->assertStringContainsString('<x-admin-action-icon name="receipt" />', $expenses);
        $this->assertStringNotContainsString("pill-link pill-link--compact\">{{ __('finance.actions.view_invoice') }}", $expenses);
        $this->assertStringContainsString("@case('receipt')", $icon);
        $this->assertStringContainsString('data-receipt-icon="supplied-invoice-sheet"', $icon);
        $this->assertStringContainsString('M65.928 90H20.04', $icon);
        $this->assertStringContainsString('M74.635 55.709', $icon);
    }

    public function test_invoice_details_action_uses_the_supplied_receipt_symbol_without_visible_text(): void
    {
        $invoices = file_get_contents(resource_path('views/livewire/invoices/index.blade.php'));

        $this->assertStringContainsString('data-invoice-receipt-action', $invoices);
        $this->assertStringContainsString("title=\"{{ __('invoices.index.table.actions.detail') }}\"", $invoices);
        $this->assertStringContainsString("aria-label=\"{{ __('invoices.index.table.actions.detail') }}\"", $invoices);
        $this->assertStringContainsString('<x-admin-action-icon name="receipt" />', $invoices);
        $this->assertStringNotContainsString("pill-link pill-link--compact\">{{ __('invoices.index.table.actions.detail') }}", $invoices);
    }

    public function test_income_print_and_exchange_save_actions_use_symbols_without_visible_text(): void
    {
        $income = file_get_contents(resource_path('views/livewire/finance/revenue-requests.blade.php'));
        $exchange = file_get_contents(resource_path('views/livewire/finance/exchange.blade.php'));
        $icon = file_get_contents(resource_path('views/components/admin-action-icon.blade.php'));
        $styles = file_get_contents(resource_path('css/app.css'));

        $this->assertStringContainsString('class="admin-icon-button"', $income);
        $this->assertStringContainsString('data-income-direct-print', $income);
        $this->assertStringContainsString('<x-admin-action-icon name="print" />', $income);
        $this->assertStringNotContainsString("data-income-direct-print>{{ __('finance.actions.print') }}", $income);
        $this->assertStringContainsString('class="exchange-notes-action"', $exchange);
        $this->assertStringContainsString('data-exchange-save-action', $exchange);
        $this->assertStringContainsString('<x-admin-action-icon name="save" />', $exchange);
        $this->assertStringNotContainsString("pill-link pill-link--accent\">{{ __('finance.actions.post_exchange') }}", $exchange);
        $this->assertStringContainsString("@case('print')", $icon);
        $this->assertStringContainsString("@case('save')", $icon);
        $this->assertStringContainsString("grid-template-columns: minmax(0, 1fr) 3.125rem;\n    align-items: end;\n    gap: 0.4rem;", $styles);
        $this->assertStringContainsString(".exchange-notes-action input {\n    height: 3.125rem;", $styles);
    }

    public function test_eligible_awqaf_students_launcher_uses_the_students_check_symbol_without_visible_text(): void
    {
        $quranTests = file_get_contents(resource_path('views/livewire/quran-tests/index.blade.php'));
        $icon = file_get_contents(resource_path('views/components/admin-action-icon.blade.php'));
        $styles = file_get_contents(resource_path('css/app.css'));

        $this->assertStringContainsString('data-eligible-awqaf-action', $quranTests);
        $this->assertStringContainsString('class="eligible-awqaf-hero-layout flex flex-wrap items-center justify-between gap-4"', $quranTests);
        $this->assertStringContainsString('wire:click="openEligibleAwqafModal" class="admin-icon-button eligible-awqaf-action"', $quranTests);
        $this->assertStringContainsString("title=\"{{ __('workflow.quran_tests.workbench.eligible_awqaf_action') }}\"", $quranTests);
        $this->assertStringContainsString("aria-label=\"{{ __('workflow.quran_tests.workbench.eligible_awqaf_action') }}\"", $quranTests);
        $this->assertStringContainsString('<x-admin-action-icon name="eligible-students" />', $quranTests);
        $this->assertStringNotContainsString("class=\"pill-link\">\n                {{ __('workflow.quran_tests.workbench.eligible_awqaf_action') }}", $quranTests);
        $this->assertStringContainsString("@case('eligible-students')", $icon);
        $this->assertStringContainsString('data-eligible-awqaf-icon="students-check"', $icon);
        $this->assertStringContainsString('m9.75 17.3 1.55 1.6 3.15-3.45', $icon);
        $this->assertStringContainsString(".eligible-awqaf-action > svg {\n    width: 1.35rem;\n    height: 1.35rem;\n    flex-basis: 1.35rem;", $styles);
    }

    public function test_standalone_books_launcher_uses_the_book_symbol_without_visible_text(): void
    {
        $curriculumSubjects = file_get_contents(resource_path('views/livewire/settings/curriculum-subjects.blade.php'));
        $icon = file_get_contents(resource_path('views/components/admin-action-icon.blade.php'));

        $this->assertStringContainsString('data-standalone-books-action', $curriculumSubjects);
        $this->assertStringContainsString('wire:click="openStandaloneResources" class="admin-icon-button"', $curriculumSubjects);
        $this->assertStringContainsString("title=\"{{ __('curricula.fields.standalone_books') }}\"", $curriculumSubjects);
        $this->assertStringContainsString("aria-label=\"{{ __('curricula.fields.standalone_books') }}\"", $curriculumSubjects);
        $this->assertStringContainsString('<x-admin-action-icon name="book" />', $curriculumSubjects);
        $this->assertStringNotContainsString("class=\"pill-link\">{{ __('curricula.fields.standalone_books') }}", $curriculumSubjects);
        $this->assertStringContainsString("@case('book')", $icon);
        $this->assertStringContainsString('M12 7.1C9.6 5.35', $icon);
    }

    public function test_points_multiplier_save_action_uses_a_square_save_symbol_matching_the_date_fields(): void
    {
        $points = file_get_contents(resource_path('views/livewire/settings/points.blade.php'));
        $styles = file_get_contents(resource_path('css/app.css'));

        $this->assertStringContainsString('data-points-multiplier-save-action', $points);
        $this->assertStringContainsString('<x-admin-action-icon name="save" />', $points);
        $this->assertStringNotContainsString("class=\"pill-link pill-link--accent\">{{ __('crud.common.actions.save') }}", $points);
        $this->assertStringContainsString('.points-multiplier-save-button {', $styles);
        $this->assertStringContainsString("width: 3rem;\n    min-width: 3rem;\n    height: 3rem;\n    min-height: 3rem;\n    flex: 0 0 3rem;\n    aspect-ratio: 1 / 1;", $styles);
    }

    public function test_general_settings_editor_uses_the_shared_edit_symbol_without_visible_text(): void
    {
        $organization = file_get_contents(resource_path('views/livewire/settings/organization.blade.php'));

        $this->assertStringContainsString('data-organization-edit-action', $organization);
        $this->assertStringContainsString('<x-edit-action-button wire:click="openOrganizationModal"', $organization);
        $this->assertStringNotContainsString("class=\"pill-link\">{{ __('settings.organization.actions.save_settings') }}", $organization);
    }

    public function test_general_finance_settings_editor_uses_the_shared_edit_symbol_without_visible_text(): void
    {
        $finance = file_get_contents(resource_path('views/livewire/settings/finance.blade.php'));

        $this->assertStringContainsString('data-finance-settings-edit-action', $finance);
        $this->assertStringContainsString('<x-edit-action-button wire:click="openFinanceSettingsModal"', $finance);
        $this->assertStringNotContainsString("class=\"pill-link\">{{ __('finance.actions.edit') }}", $finance);
    }

    public function test_settings_tables_share_the_memorization_edit_icon_and_keep_delete_inside_edit_popups(): void
    {
        $editComponent = file_get_contents(resource_path('views/components/edit-action-button.blade.php'));
        $deleteComponent = file_get_contents(resource_path('views/components/delete-action-button.blade.php'));
        $saveComponent = file_get_contents(resource_path('views/components/admin/save-button.blade.php'));
        $memorization = file_get_contents(resource_path('views/livewire/memorization/index.blade.php'));
        $styles = file_get_contents(resource_path('css/app.css'));

        $this->assertStringContainsString('<x-admin-action-icon name="edit" />', $editComponent);
        $this->assertStringContainsString('<x-admin-action-icon name="edit" />', $memorization);
        $this->assertStringContainsString('<x-admin-action-icon :name="$icon" class="admin-modal-action__icon" />', $deleteComponent);
        $this->assertStringContainsString('admin-icon-button--danger', $deleteComponent);
        $this->assertStringContainsString('<x-admin-action-icon name="save" class="admin-modal-action__icon" />', $saveComponent);
        $this->assertStringContainsString('data-save-action', $saveComponent);
        $this->assertStringContainsString("html[dir='rtl'] :is(.admin-modal__body, dialog, [role='dialog']) [data-delete-action] {\n    order: 100 !important;", $styles);
        $this->assertStringContainsString('data-memorization-session-delete-action', $memorization);
        $this->assertStringContainsString('data-memorization-session-save-action', $memorization);
        $this->assertStringContainsString('<x-admin-action-icon name="save" class="admin-modal-action__icon" />', $memorization);
        $this->assertLessThan(
            strpos($memorization, 'data-memorization-session-save-action'),
            strpos($memorization, 'data-memorization-session-delete-action'),
        );
        $this->assertStringContainsString('class="admin-icon-button admin-icon-button--accent admin-modal-action-button"', $memorization);
        $this->assertStringNotContainsString('admin-modal-action-button ms-auto', $memorization);
        $this->assertStringNotContainsString('wire:click="closeFormModal" class="pill-link"', $memorization);

        foreach ([
            'livewire/settings/access-control.blade.php' => ['data-role-edit-action', 'data-role-save-action', 'data-role-delete-action'],
            'livewire/settings/curriculum-subjects.blade.php' => ['data-curriculum-subject-edit-action', 'data-curriculum-resource-edit-icon', 'data-curriculum-resource-delete-action'],
            'livewire/settings/finance-report-templates.blade.php' => ['data-finance-report-template-edit-action', 'data-finance-report-template-delete-action'],
            'livewire/settings/finance.blade.php' => ['data-finance-currency-edit-action', 'data-finance-currency-delete-action', 'data-finance-cash-box-edit-action', 'data-finance-cash-box-delete-action', 'data-finance-category-edit-action', 'data-finance-category-delete-action'],
            'livewire/settings/organization.blade.php' => ['data-settings-academic-year-edit-action', 'data-settings-academic-year-delete-action', 'data-settings-grade-level-edit-action', 'data-settings-grade-level-delete-action', 'data-school-reference-edit-icon', 'data-father-job-edit-icon', 'data-settings-student-gender-edit-action', 'data-settings-student-gender-delete-action'],
            'livewire/settings/points.blade.php' => ['data-settings-point-type-edit-action', 'data-settings-point-type-delete-action', 'data-settings-point-policy-edit-action', 'data-settings-point-policy-delete-action'],
            'livewire/settings/tracking.blade.php' => ['data-settings-attendance-status-edit-action', 'data-settings-attendance-status-delete-action', 'data-settings-assessment-type-edit-action', 'data-settings-assessment-type-delete-action'],
            'livewire/assessments/bands.blade.php' => ['data-settings-assessment-band-edit-action', 'data-settings-assessment-band-delete-action'],
        ] as $view => $markers) {
            $source = file_get_contents(resource_path('views/'.$view));

            foreach ($markers as $marker) {
                $this->assertStringContainsString($marker, $source, $view.' is missing '.$marker);
            }
        }

        foreach ([
            'livewire/settings/curriculum-subjects.blade.php' => ['data-curriculum-subject-save-action', 'data-curriculum-resource-save-action'],
            'livewire/settings/organization.blade.php' => ['data-settings-academic-year-save-action', 'data-settings-grade-level-save-action', 'data-school-reference-save-action', 'data-father-job-save-action', 'data-settings-student-gender-save-action'],
            'livewire/settings/points.blade.php' => ['data-settings-point-type-save-action', 'data-settings-point-policy-save-action'],
            'livewire/settings/tracking.blade.php' => ['data-settings-attendance-status-save-action', 'data-settings-assessment-type-save-action'],
            'livewire/assessments/bands.blade.php' => ['data-settings-assessment-band-save-action'],
        ] as $view => $markers) {
            $source = file_get_contents(resource_path('views/'.$view));

            foreach ($markers as $marker) {
                $this->assertStringContainsString($marker, $source, $view.' is missing '.$marker);
            }
        }

        $pointSettings = file_get_contents(resource_path('views/livewire/settings/points.blade.php'));
        $this->assertStringNotContainsString("__('settings.points.labels.manual_entry_automatic')", $pointSettings);

        foreach ([
            'livewire/settings/finance-report-templates.blade.php' => ['deleteTemplate({{ $template->id }})'],
            'livewire/settings/finance.blade.php' => ['deleteCurrency({{ $currency->id }})', 'deleteCashBox({{ $box->id }})', 'deleteFinanceCategory({{ $category->id }})'],
            'livewire/settings/organization.blade.php' => ['deleteGradeLevel({{ $gradeLevel->id }})', 'deleteStudentGender({{ $studentGender->id }})'],
            'livewire/settings/points.blade.php' => ['deletePointType({{ $pointType->id }})', 'deletePointPolicy({{ $pointPolicy->id }})'],
            'livewire/settings/tracking.blade.php' => ['deleteAttendanceStatus({{ $attendanceStatus->id }})', 'deleteAssessmentType({{ $assessmentType->id }})'],
            'livewire/assessments/bands.blade.php' => ['delete({{ $band->id }})'],
        ] as $view => $rowDeleteCalls) {
            $source = file_get_contents(resource_path('views/'.$view));

            foreach ($rowDeleteCalls as $rowDeleteCall) {
                $this->assertStringNotContainsString($rowDeleteCall, $source, $view.' still exposes delete in a table row');
            }
        }
    }

    public function test_student_attendance_day_create_modal_uses_the_save_symbol(): void
    {
        $studentAttendance = file_get_contents(resource_path('views/livewire/student-attendance/index.blade.php'));

        $this->assertStringContainsString('data-student-attendance-day-save-action', $studentAttendance);
        $this->assertStringContainsString('class="admin-icon-button admin-icon-button--accent admin-modal-action-button"', $studentAttendance);
        $this->assertStringContainsString('<x-admin-action-icon name="save" class="admin-modal-action__icon" />', $studentAttendance);
        $this->assertStringNotContainsString(
            '<button type="submit" class="pill-link pill-link--accent">{{ __(\'workflow.student_attendance.days.create\') }}</button>',
            $studentAttendance,
        );
    }

    public function test_transaction_maintenance_uses_search_save_and_receipt_symbols_matching_the_lookup_field(): void
    {
        $finance = file_get_contents(resource_path('views/livewire/settings/finance.blade.php'));
        $icon = file_get_contents(resource_path('views/components/admin-action-icon.blade.php'));
        $styles = file_get_contents(resource_path('css/app.css'));

        $this->assertStringContainsString('data-transaction-maintenance-search-action', $finance);
        $this->assertStringContainsString('<x-admin-action-icon name="search" />', $finance);
        $this->assertStringContainsString('data-transaction-maintenance-save-action', $finance);
        $this->assertStringContainsString('<x-admin-action-icon name="save" />', $finance);
        $this->assertStringContainsString('data-transaction-maintenance-receipt-action', $finance);
        $this->assertStringContainsString('<x-admin-action-icon name="receipt" />', $finance);
        $this->assertStringContainsString('data-transaction-maintenance-delete-action', $finance);
        $this->assertStringContainsString('class="admin-icon-button admin-icon-button--danger transaction-maintenance-action-button self-center"', $finance);
        $this->assertStringContainsString('<x-admin-action-icon name="delete" />', $finance);
        $this->assertStringNotContainsString("class=\"pill-link pill-link--danger\">{{ __('finance.actions.delete') }}", $finance);
        $this->assertStringContainsString("@case('search')", $icon);
        $this->assertStringContainsString("@case('delete')", $icon);
        $this->assertStringContainsString('.transaction-maintenance-lookup,', $styles);
        $this->assertStringContainsString("width: 3.125rem;\n    min-width: 3.125rem;\n    height: 3.125rem;\n    min-height: 3.125rem;\n    flex: 0 0 3.125rem;\n    aspect-ratio: 1 / 1;", $styles);
    }

    public function test_legacy_report_import_uses_the_standard_square_add_button(): void
    {
        $finance = file_get_contents(resource_path('views/livewire/settings/finance.blade.php'));
        $styles = file_get_contents(resource_path('css/app.css'));

        $this->assertStringContainsString('data-legacy-report-import-action', $finance);
        $this->assertStringContainsString('<x-add-action-button wire:click="openLegacyReportModal"', $finance);
        $this->assertStringContainsString('class="generated-report-import-action-button"', $finance);
        $this->assertStringNotContainsString('class="pill-link pill-link--accent" aria-label="{{ __(\'finance.reports.import_legacy_report\') }}">+</button>', $finance);
        $this->assertStringContainsString(".generated-report-import-action-button,\n.generated-report-maintenance-action-button {\n    width: 3.125rem;\n    min-width: 3.125rem;\n    height: 3.125rem;", $styles);
    }

    public function test_saved_report_maintenance_uses_a_square_delete_icon_and_localized_aligned_placeholder(): void
    {
        $finance = file_get_contents(resource_path('views/livewire/settings/finance.blade.php'));
        $styles = file_get_contents(resource_path('css/app.css'));

        $this->assertStringContainsString('data-generated-report-delete-action', $finance);
        $this->assertStringContainsString('class="admin-icon-button admin-icon-button--danger generated-report-maintenance-action-button"', $finance);
        $this->assertStringContainsString('<x-admin-action-icon name="delete" />', $finance);
        $this->assertStringNotContainsString("class=\"pill-link pill-link--danger\">{{ __('finance.reports.delete_saved_report') }}", $finance);
        $this->assertStringContainsString('data-generated-report-number', $finance);
        $this->assertStringContainsString("placeholder=\"{{ __('finance.settings.generated_report_placeholder') }}\"", $finance);
        $this->assertStringContainsString("app()->isLocale('ar') ? 'text-right' : 'text-left'", $finance);
        $this->assertStringContainsString("app()->isLocale('ar') ? 'rtl' : 'ltr'", $finance);
        $this->assertStringContainsString('.generated-report-lookup,', $styles);
        $this->assertStringContainsString('.generated-report-maintenance-action-button {', $styles);
        $this->assertSame('رقم التقرير', __('finance.settings.generated_report_placeholder', [], 'ar'));
        $this->assertSame('Report number', __('finance.settings.generated_report_placeholder', [], 'en'));
    }

    public function test_withdrawal_cleanup_requires_one_selected_request_number(): void
    {
        $finance = file_get_contents(resource_path('views/livewire/settings/finance.blade.php'));
        $service = file_get_contents(app_path('Services/FinanceService.php'));

        $this->assertStringContainsString('public string $withdrawal_cleanup_request_no', $finance);
        $this->assertStringContainsString('data-withdrawal-cleanup-request-number', $finance);
        $this->assertStringContainsString('wire:click="deleteWithdrawalRequest"', $finance);
        $this->assertStringNotContainsString('wire:click="deleteWithdrawalRequests"', $finance);
        $this->assertStringContainsString('public function deleteWithdrawalRequest(FinanceRequest $pullRequest', $service);
        $this->assertStringNotContainsString('public function deleteWithdrawalRequests(', $service);
        $this->assertStringContainsString("->whereKey(\$pullRequest->getKey())", $service);
        $this->assertStringContainsString("->where('metadata->parent_pull_request_id', \$pullRequestId)", $service);
    }

    public function test_popup_action_buttons_are_enhanced_to_accessible_square_icons(): void
    {
        $script = file_get_contents(resource_path('js/app.js'));
        $styles = file_get_contents(resource_path('css/app.css'));

        $this->assertStringContainsString('function initializeAdminModalActionIcons(root = document)', $script);
        $this->assertStringContainsString("const selector = '.admin-modal__body :is(button, a)';", $script);
        $this->assertStringContainsString("if (action.closest('.searchable-select')) {", $script);
        $this->assertStringContainsString('restoreSearchableSelectClear(action);', $script);
        $this->assertStringContainsString("clear.replaceChildren('×');", $script);
        $this->assertStringContainsString("clear.dataset.modalActionIconIgnore = 'true';", $script);
        $this->assertStringContainsString("if (action.closest('[role=\"tablist\"], [role=\"listbox\"]')) return;", $script);
        $this->assertStringContainsString("action.classList.add('admin-icon-button', 'admin-modal-action-button');", $script);
        $this->assertStringContainsString("action.setAttribute('aria-label', label);", $script);
        $this->assertStringContainsString("action.setAttribute('title', action.getAttribute('title') || label);", $script);
        $this->assertStringContainsString("accept.dataset.modalActionForceKind = options.actionKind ?? 'approve';", $script);
        $this->assertStringNotContainsString('initializeAdminModalActionIcons(accept);', $script);
        $this->assertStringContainsString("accept.setAttribute('aria-label', confirmLabel);", $script);
        $this->assertStringContainsString("if (/delete|remove|destroy|void|حذف|إزالة|ازالة/.test(descriptor)) return 'delete';", $script);
        $this->assertStringContainsString("if (/save|update|submit|confirm|apply|post|finish|حفظ|تحديث|تأكيد|تاكيد|تطبيق|إنهاء|انهاء/.test(descriptor)) return 'save';", $script);
        $this->assertStringContainsString("if (/create|add|new|إنشاء|انشاء|إضافة|اضافة|جديد/.test(descriptor)) return 'add';", $script);
        $this->assertStringContainsString("svg.setAttribute('viewBox', '300 280 720 720');", $script);
        $this->assertStringContainsString("svg.dataset.iconName = 'save';", $script);
        $this->assertStringContainsString("svg.setAttribute('stroke-width', '16');", $script);
        $this->assertStringContainsString("svg.setAttribute('stroke-linecap', 'round');", $script);
        $this->assertStringContainsString("svg.setAttribute('stroke-linejoin', 'round');", $script);
        $this->assertStringContainsString("addPath('M385.8,337.3l453.7-.2", $script);
        $this->assertStringContainsString("observer.observe(document.body, { childList: true, subtree: true });", $script);
        $this->assertStringContainsString(".admin-modal-action-button {\n    display: inline-flex !important;\n    width: 2.5rem !important;", $styles);
        $this->assertStringContainsString("border-radius: 0.85rem !important;", $styles);
        $this->assertStringContainsString(".admin-modal-action__icon {\n    display: block;\n    width: 1.15rem;", $styles);
    }

    public function test_save_and_new_is_the_only_create_submit_action_and_uses_the_save_asterisk_icon(): void
    {
        $button = file_get_contents(resource_path('views/components/admin/create-and-new-button.blade.php'));
        $icon = file_get_contents(resource_path('views/components/admin-action-icon.blade.php'));
        $styles = file_get_contents(resource_path('css/app.css'));
        $uses = 0;

        $views = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(resource_path('views/livewire'), \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($views as $view) {
            if (! $view->isFile() || ! str_ends_with($view->getFilename(), '.blade.php')) {
                continue;
            }

            $source = file_get_contents($view->getPathname());
            $uses += substr_count($source, '<x-admin.create-and-new-button');
            $this->assertStringNotContainsString(
                '<x-admin.create-and-new-button :show=',
                $source,
                $view->getPathname().' must render Save & New as the sole create-mode submit action.',
            );
        }

        $this->assertSame(25, $uses);
        $this->assertStringContainsString('class="admin-icon-button admin-icon-button--accent admin-modal-action-button"', $button);
        $this->assertStringContainsString('title="{{ __(\'crud.common.actions.create_and_new\') }}"', $button);
        $this->assertStringContainsString('aria-label="{{ __(\'crud.common.actions.create_and_new\') }}"', $button);
        $this->assertStringContainsString('data-create-and-new-action', $button);
        $this->assertStringContainsString('<x-admin-action-icon name="save-new" class="admin-modal-action__icon admin-modal-action__icon--save-new" />', $button);
        $this->assertStringNotContainsString("{{ __('crud.common.actions.create_and_new') }}\n", $button);
        $this->assertStringContainsString("@case('save-new')", $icon);
        $this->assertStringContainsString("'save' => '300 280 720 720'", $icon);
        $this->assertStringContainsString("'save-new' => '250 230 760 760'", $icon);
        $this->assertStringContainsString('data-supplied-save-disk', $icon);
        $this->assertStringContainsString('data-save-new-disk', $icon);
        $this->assertStringContainsString('data-save-new-disk mask="url(#{{ $saveNewClearanceMaskId }})"', $icon);
        $this->assertStringContainsString('<g transform="translate(-23 -51)">', $icon);
        $this->assertStringContainsString('data-save-new-artwork transform="translate(12 5)"', $icon);
        $this->assertStringContainsString('data-save-new-asterisk', $icon);
        $this->assertStringContainsString('data-save-new-asterisk-clearance cx="370" cy="369" rx="104" ry="112"', $icon);
        $this->assertStringContainsString('fill="var(--save-new-asterisk)" stroke="var(--save-new-asterisk)"', $icon);
        $this->assertStringContainsString('data-save-new-asterisk fill="var(--save-new-asterisk)" stroke="var(--save-new-asterisk)" transform="translate(4 -10) translate(366 379) scale(1.3) translate(-366 -379)"', $icon);
        $this->assertStringContainsString('--save-new-asterisk: #c2a05f;', $styles);
        $this->assertStringContainsString(".admin-icon-button > svg[data-icon-name='save'] {\n    width: 1.3rem;\n    height: 1.3rem;\n    flex-basis: 1.3rem;\n    transform: translateX(0.04rem);", $styles);
        $this->assertStringContainsString(".admin-icon-button > svg[data-icon-name='save-new'] {\n    width: 1.45rem;", $styles);
        $this->assertGreaterThanOrEqual(2, substr_count($icon, 'stroke-width="16"'));
        $this->assertGreaterThanOrEqual(2, substr_count($icon, 'stroke-linecap="round" stroke-linejoin="round"'));
        $this->assertStringContainsString('M385.8,337.3l453.7-.2', $icon);
        $this->assertStringContainsString('M437.4,932.2v-225.5', $icon);
        $this->assertStringContainsString('M370.7,362.6l43.8-25.9', $icon);
        $this->assertStringNotContainsString('transform="translate(-0.5 0.75) scale(0.92)"', $icon);

        $assessments = file_get_contents(resource_path('views/livewire/assessments/index.blade.php'));
        $this->assertStringNotContainsString('<x-admin.create-and-new-button', $assessments);
        $this->assertStringNotContainsString('SupportsCreateAndNew', $assessments);
        $this->assertStringContainsString('data-assessment-save-action', $assessments);
        $this->assertStringContainsString('<x-admin-action-icon name="save" class="admin-modal-action__icon" />', $assessments);

        foreach ([
            'livewire/finance/expense-requests.blade.php' => 'data-expense-request-save',
            'livewire/finance/revenue-requests.blade.php' => 'data-income-request-save',
            'livewire/community-contacts/index.blade.php' => 'data-community-contact-save-action',
        ] as $view => $marker) {
            $financeEntry = file_get_contents(resource_path('views/'.$view));
            $this->assertStringNotContainsString('<x-admin.create-and-new-button', $financeEntry);
            $this->assertStringNotContainsString('SupportsCreateAndNew', $financeEntry);
            $this->assertStringContainsString($marker, $financeEntry);
            $this->assertStringContainsString('<x-admin-action-icon name="save" class="admin-modal-action__icon" />', $financeEntry);
        }

        foreach ([
            'livewire/quran-partial-tests/index.blade.php' => 'workflow.quran_partial_tests.actions.create',
            'livewire/quran-final-tests/index.blade.php' => 'workflow.quran_final_tests.actions.create',
            'livewire/quran-tests/index.blade.php' => 'workflow.common.actions.save_quran_test',
            'livewire/finance/pull-requests.blade.php' => 'finance.actions.submit_request',
            'livewire/finance/expense-requests.blade.php' => 'finance.actions.save_expense',
            'livewire/finance/revenue-requests.blade.php' => 'finance.actions.save_revenue',
        ] as $view => $removedCreateSubmit) {
            $this->assertStringNotContainsString(
                $removedCreateSubmit,
                file_get_contents(resource_path('views/'.$view)),
                $view.' must not keep a second create-mode Save button beside Save & New.',
            );
        }
    }

    public function test_course_user_teacher_and_parent_row_actions_use_the_requested_symbol_layout(): void
    {
        $courses = file_get_contents(resource_path('views/livewire/courses/index.blade.php'));
        $users = file_get_contents(resource_path('views/livewire/users/index.blade.php'));
        $teachers = file_get_contents(resource_path('views/livewire/teachers/index.blade.php'));
        $parents = file_get_contents(resource_path('views/livewire/parents/index.blade.php'));
        $icon = file_get_contents(resource_path('views/components/admin-action-icon.blade.php'));

        $this->assertStringContainsString('data-course-end-column', $courses);
        $this->assertStringContainsString("__('crud.courses.table.headers.end_course')", $courses);
        $this->assertSame(1, substr_count($courses, 'data-course-end-action'));
        $this->assertSame(1, substr_count($courses, 'data-course-edit-action'));
        $this->assertSame(1, substr_count($courses, '<x-edit-action-button wire:click="edit({{ $course->id }})"'));
        $this->assertStringContainsString('data-course-form-save-action', $courses);
        $this->assertStringContainsString('<x-admin-action-icon name="save" class="admin-modal-action__icon" />', $courses);
        $this->assertStringContainsString('data-course-form-finish-action', $courses);
        $this->assertStringContainsString('<x-admin-action-icon name="finish-line" class="admin-modal-action__icon" />', $courses);
        $this->assertStringContainsString("@case('finish-line')", $icon);
        $this->assertStringContainsString('data-course-form-copy-action', $courses);
        $this->assertStringContainsString('<x-admin-action-icon name="copy" class="admin-modal-action__icon" />', $courses);
        $this->assertStringContainsString('data-course-form-delete-action', $courses);
        $this->assertStringContainsString("@case('copy')", $icon);

        $this->assertSame(2, substr_count($users, 'data-user-edit-action'));
        $this->assertSame(2, substr_count($users, '<x-edit-action-button wire:click="edit({{ $user->id }})"'));
        $this->assertStringContainsString('data-user-form-save-action', $users);
        $this->assertStringContainsString('<x-admin-action-icon name="save" class="admin-modal-action__icon" />', $users);
        $this->assertStringContainsString('data-user-form-delete-action', $users);

        $this->assertSame(2, substr_count($teachers, 'data-teacher-review-action'));
        $this->assertSame(2, substr_count($teachers, '<x-admin-action-icon name="review" />'));
        $this->assertSame(2, substr_count($teachers, 'data-teacher-edit-action'));
        $this->assertSame(2, substr_count($teachers, '<x-edit-action-button wire:click="edit({{ $teacher->id }})"'));
        $this->assertStringContainsString('data-teacher-form-save-action', $teachers);
        $this->assertStringContainsString('data-teacher-form-delete-action', $teachers);

        $this->assertSame(2, substr_count($parents, 'data-parent-account-action'));
        $this->assertSame(2, substr_count($parents, '<x-admin-action-icon name="account" />'));
        $this->assertSame(2, substr_count($parents, 'data-parent-children-action'));
        $this->assertSame(2, substr_count($parents, '<flux:icon.parents-couple />'));
        $this->assertSame(2, substr_count($parents, '@if ($parent->students_count > 0)'));
        $this->assertStringContainsString(
            'data-parents-icon="adult-holding-child-hand"',
            file_get_contents(resource_path('views/flux/icon/parents-couple.blade.php')),
        );
        $this->assertSame(0, substr_count($parents, 'data-parent-edit-action'));
        $this->assertSame(2, substr_count($parents, 'data-parent-delete-action'));
        $this->assertSame(2, substr_count($parents, '@if ($parent->students_count === 0)'));
        $this->assertStringContainsString('data-parent-form-save-action', $parents);
        $this->assertStringContainsString('data-parent-form-close-action', $parents);
        $this->assertStringContainsString('data-parent-form-account-action', $parents);
        $this->assertStringContainsString('data-parent-form-delete-action', $parents);

        $this->assertStringContainsString("@case('review')", $icon);
        $this->assertStringContainsString("@case('children')", $icon);
    }

    public function test_clear_filter_actions_use_the_funnel_x_symbol_without_visible_text(): void
    {
        foreach ([
            'livewire/reports/rankings.blade.php' => 'clearFilters',
            'livewire/reports/groups-ranking.blade.php' => 'clearFilters',
            'livewire/reports/student-quran-tests.blade.php' => 'clearFilters',
            'livewire/reports/student-activity-summary.blade.php' => 'clearFilters',
            'livewire/reports/students-ranking.blade.php' => 'clearFilters',
            'livewire/reports/index.blade.php' => 'clearFilters',
            'livewire/community-contacts/index.blade.php' => 'clearFilters',
            'livewire/finance/cash-box.blade.php' => 'resetFilters',
        ] as $view => $method) {
            $source = file_get_contents(resource_path('views/'.$view));

            $this->assertStringContainsString("<x-clear-filter-button wire:click=\"{$method}\"", $source);
            $this->assertStringNotContainsString("<button type=\"button\" wire:click=\"{$method}\"", $source);
        }

        $button = file_get_contents(resource_path('views/components/clear-filter-button.blade.php'));
        $icon = file_get_contents(resource_path('views/components/admin-action-icon.blade.php'));
        $script = file_get_contents(resource_path('js/app.js'));

        $this->assertStringContainsString("'admin-icon-button'", $button);
        $this->assertStringContainsString("'clear-filter-button'", $button);
        $this->assertStringContainsString('title="{{ $label }}"', $button);
        $this->assertStringContainsString('aria-label="{{ $label }}"', $button);
        $this->assertStringContainsString('<x-admin-action-icon name="clear-filter" />', $button);
        $this->assertStringContainsString("@case('clear-filter')", $icon);
        $this->assertStringContainsString('M4 4.5h16v3.6l-6.25 6.1v3.25L10.5 20v-5.5L4 8.1V4.5', $icon);
        $this->assertStringContainsString('<circle class="clear-filter-icon__badge" cx="8.65" cy="14.2" r="4" />', $icon);
        $this->assertStringContainsString('class="clear-filter-icon__mark"', $icon);
        $this->assertStringContainsString("addCircle('8.65', '14.2', '4').classList.add('clear-filter-icon__badge')", $script);
        $this->assertStringContainsString("addPath('m6.95 12.5 3.4 3.4m0-3.4-3.4 3.4').classList.add('clear-filter-icon__mark')", $script);

        $styles = file_get_contents(resource_path('css/app.css'));

        $this->assertStringContainsString(".clear-filter-button {\n    --clear-filter-button-size: 3.125rem;", $styles);
        $this->assertStringContainsString('aspect-ratio: 1 / 1;', $styles);
        $this->assertStringContainsString(".clear-filter-button > svg {\n    width: 1.5rem;\n    height: 1.5rem;", $styles);
        $this->assertStringContainsString(".clear-filter-button > .mobile-table-action__icon {\n    width: 1.5rem !important;", $styles);
        $this->assertStringContainsString(".clear-filter-icon__badge {\n    fill: #238654;\n    stroke: #238654;", $styles);
        $this->assertStringContainsString(".clear-filter-icon__mark {\n    stroke: #ffffff;\n    stroke-width: 2.15;", $styles);
    }

    public function test_table_action_headings_stay_centered_over_their_controls_in_ltr_and_rtl(): void
    {
        $styles = file_get_contents(resource_path('css/app.css'));
        $actionHeaderCount = 0;

        $views = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(resource_path('views'), \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($views as $view) {
            if (! $view->isFile() || ! str_ends_with($view->getFilename(), '.blade.php')) {
                continue;
            }

            $source = file_get_contents($view->getPathname());
            preg_match_all('/<th\b[^>]*>.*?<\/th>/', $source, $headers);

            foreach ($headers[0] as $header) {
                if (! preg_match('/__\([\'\"][^\'\"]*\.actions[\'\"]/', $header)) {
                    continue;
                }

                $actionHeaderCount++;
                $this->assertStringContainsString(
                    'admin-actions-column',
                    $header,
                    $view->getPathname().' should mark its Actions heading for shared centering.',
                );
                $this->assertStringContainsString(
                    'text-center',
                    $header,
                    $view->getPathname().' should explicitly center its Actions heading in LTR and RTL.',
                );
            }
        }

        $this->assertGreaterThanOrEqual(64, $actionHeaderCount);
        $this->assertStringContainsString(
            "th.admin-actions-column,\nhtml[dir] th.admin-actions-column,\nhtml[lang^='ar'] th.admin-actions-column,\nhtml[dir='rtl'] .surface-table th.admin-actions-column,\nhtml[dir='rtl'] .surface-panel th.admin-actions-column {\n    text-align: center !important;",
            $styles,
        );
        $this->assertStringContainsString(
            "html[dir='rtl'] .surface-table table:has(> thead .admin-actions-column) > :is(thead, tbody, tfoot) > tr > :last-child,",
            $styles,
        );
        $this->assertStringContainsString(
            "html[dir='rtl'] .surface-table table:has(> thead .admin-actions-column) > tbody > tr > td:last-child > :is(.flex, .admin-action-cluster),",
            $styles,
        );
        $this->assertStringContainsString(
            ":is(.surface-table, .surface-panel) table > tbody > tr > td:last-child > .admin-icon-button {\n    display: flex;\n    margin-inline: auto !important;",
            $styles,
        );
        $this->assertStringContainsString(
            ":is(.surface-table, .surface-panel) table > tbody > tr > td:last-child > :is(.flex, .admin-action-cluster) {\n    justify-content: center !important;",
            $styles,
        );
        $this->assertStringContainsString(".surface-table .student-notes-table__actions {\n    text-align: center !important;", $styles);
        $this->assertStringContainsString("html[lang^='ar'] .surface-table th", $styles);
        $this->assertStringContainsString("letter-spacing: 0;\n    text-transform: none;", $styles);
    }

    public function test_mobile_table_headers_use_symbol_actions_and_single_column_filter_dialogs(): void
    {
        $script = file_get_contents(resource_path('js/app.js'));
        $styles = file_get_contents(resource_path('css/app.css'));

        $this->assertStringContainsString('initializeMobileTableHeaderActions(toolbar)', $script);
        $this->assertStringContainsString("toolbar.classList.add('mobile-table-header-controls')", $script);
        $this->assertStringContainsString("action.classList.add('mobile-table-header-action')", $script);
        $this->assertStringContainsString("action.querySelectorAll(':scope > svg:not(.mobile-table-action__icon)')", $script);
        $this->assertStringContainsString("action.querySelectorAll(':scope > .mobile-table-action__icon').forEach((icon) => icon.remove())", $script);
        $this->assertStringContainsString("nativeIcons.slice(1).forEach((icon) => icon.remove())", $script);
        $this->assertStringContainsString("action.classList.add('mobile-table-header-action--native-icon')", $script);
        $this->assertStringContainsString("if (!action.querySelector('.mobile-table-action__icon'))", $script);
        $this->assertStringContainsString('.surface-table > .admin-grid-meta--controls', $styles);
        $this->assertStringContainsString('.mobile-table-filters--open::before', $styles);
        $this->assertStringContainsString('.mobile-table-filters--open > .mobile-table-filter-criterion', $styles);
        $this->assertStringContainsString('grid-column: 1 / -1 !important;', $styles);
        $this->assertStringContainsString(".mobile-table-header-action,\n    .mobile-table-header-controls:not(.mobile-table-filters--open) .admin-toolbar__actions .mobile-table-header-action {\n        width: 3.125rem !important;", $styles);
        $this->assertStringContainsString('flex: 0 0 3.125rem !important;', $styles);
        $this->assertStringContainsString('.mobile-table-header-action--native-icon > svg:not(.mobile-table-action__icon)', $styles);
        $this->assertStringContainsString('.mobile-table-header-action--native-icon > .mobile-table-action__icon', $styles);
    }

    public function test_table_header_symbol_buttons_match_filter_height_and_keep_a_compact_gap(): void
    {
        $styles = file_get_contents(resource_path('css/app.css'));
        $accessControl = file_get_contents(resource_path('views/livewire/settings/access-control.blade.php'));

        $this->assertStringContainsString(
            ':is(.surface-table, .surface-panel:has(table)) :is(.admin-grid-meta, .admin-toolbar, .soft-keyline) .admin-icon-button,',
            $styles,
        );
        $this->assertStringContainsString("width: 3.125rem;\n    min-width: 3.125rem;\n    height: 3.125rem;\n    min-height: 3.125rem;\n    flex: 0 0 3.125rem;\n    aspect-ratio: 1 / 1;", $styles);
        $this->assertStringContainsString('.access-role-table-controls > .admin-icon-button {', $styles);
        $this->assertStringContainsString('class="access-role-table-controls flex flex-wrap items-end gap-3"', $accessControl);
        $this->assertStringContainsString(
            ':is(.admin-toolbar__controls, .admin-toolbar__actions, [data-mobile-table-filter-controls])',
            $styles,
        );
        $this->assertStringContainsString('column-gap: 0.4rem !important;', $styles);
        $this->assertStringContainsString(
            ".admin-grid-meta--controls .admin-toolbar__controls:not(.student-activity-report-filters) {\n    display: flex;\n    flex: 0 1 auto;\n    flex-wrap: wrap;\n    align-items: center;\n    justify-content: flex-end;\n    gap: 0.4rem;\n    min-width: 0;\n    width: max-content;\n    max-width: 100%;",
            $styles,
        );
        $this->assertStringContainsString(
            ".admin-grid-meta--controls .admin-toolbar__controls:not(.student-activity-report-filters) > .admin-filter-field {\n    width: 12rem;\n    max-width: 100%;\n    flex: 0 0 12rem;",
            $styles,
        );
        $this->assertStringContainsString(
            ".id-card-selection-toolbar .admin-toolbar__controls:not(.student-activity-report-filters) > .admin-filter-field {\n    width: auto;\n    flex: 1 1 12rem;",
            $styles,
        );
        $this->assertStringContainsString(
            ".admin-grid-meta--controls .admin-toolbar__controls:not(.student-activity-report-filters) > .admin-toolbar__actions {\n    flex: 0 0 auto;\n    width: auto;",
            $styles,
        );
        $this->assertStringContainsString(
            ".admin-grid-meta--controls .admin-toolbar__actions {\n    gap: 0.4rem;",
            $styles,
        );
        $this->assertStringContainsString(
            ".print-template-source-toolbar .admin-toolbar__controls {\n    display: flex;\n    min-width: 0;\n    flex-wrap: wrap;\n    align-items: end;\n    gap: 0.4rem;",
            $styles,
        );
        $this->assertStringContainsString(
            ".print-template-source-toolbar__actions {\n    min-width: 0;\n    flex: 0 0 auto;",
            $styles,
        );
        $this->assertStringContainsString(
            ".id-card-filter-row > .admin-toolbar__controls {\n    display: grid !important;\n    width: 100% !important;\n    inline-size: 100% !important;\n    grid-template-columns: repeat(3, minmax(0, 1fr)) max-content !important;\n    align-items: end;\n    justify-self: stretch !important;\n    align-self: stretch !important;\n    max-width: none !important;\n    max-inline-size: none !important;\n    margin-inline: 0 !important;\n    gap: 0.4rem;",
            $styles,
        );
        $this->assertStringContainsString("flex: 0 1 auto;\n    width: max-content;", $styles);
        $this->assertStringContainsString(
            "html[dir='rtl'] .admin-grid-meta--controls .admin-toolbar__controls > :is(.admin-icon-button, .admin-toolbar__actions) {\n    justify-self: end;",
            $styles,
        );
        $this->assertStringNotContainsString(
            "html[dir='rtl'] .admin-grid-meta--controls .admin-toolbar__controls {\n    flex: 0 1 auto;",
            $styles,
        );
    }

    public function test_id_card_selection_filters_fill_the_toolbar_and_use_square_symbol_actions(): void
    {
        $printTemplateSetup = file_get_contents(resource_path('views/print-templates/print/setup.blade.php'));
        $idCardSetup = file_get_contents(resource_path('views/id-cards/print/setup.blade.php'));
        $icons = file_get_contents(resource_path('views/components/admin-action-icon.blade.php'));
        $printStatusIcon = file_get_contents(resource_path('views/components/id-card-print-status-icon.blade.php'));
        $styles = file_get_contents(resource_path('css/app.css'));

        foreach ([$printTemplateSetup, $idCardSetup] as $source) {
            $this->assertStringContainsString('class="admin-icon-button selection-toolbar-icon-button"', $source);
            $this->assertStringContainsString('<x-admin-action-icon name="select-visible" data-select-visible-icon />', $source);
            $this->assertStringContainsString('<x-admin-action-icon name="clear-selection" data-clear-selection-icon hidden />', $source);
            $this->assertStringContainsString('<x-admin-action-icon name="clear-filter" />', $source);
        }

        $this->assertStringContainsString("@case('select-visible')", $icons);
        $this->assertStringContainsString("@case('clear-selection')", $icons);
        $this->assertStringContainsString('data-clear-selection-frame', $icons);
        $this->assertStringContainsString('data-clear-selection-mark', $icons);
        $this->assertStringContainsString("button.dataset.sourceSelectionMode = hasSelection ? 'clear' : 'select';", $printTemplateSetup);
        $this->assertStringContainsString("selectVisibleButton.dataset.idCardSelectionMode = hasSelection ? 'clear' : 'select';", $idCardSetup);
        $this->assertStringContainsString("selectedCards.every((card) => card.dataset.cardPrinted === '1')", $printTemplateSetup);
        $this->assertStringContainsString("printStatusButton.dataset.printStatusAction = clearPrints ? 'mark-unprinted' : 'mark-printed';", $printTemplateSetup);
        $this->assertStringContainsString('<label for="student-card-course" class="sr-only">', $printTemplateSetup);
        $this->assertStringNotContainsString('stroke="#111111"', $printStatusIcon);
        $this->assertStringContainsString('stroke="currentColor"', $printStatusIcon);
        $this->assertStringContainsString(".print-template-source-toolbar .admin-toolbar__controls > .admin-filter-field {\n    min-width: 0;\n    flex: 1 1 0;", $styles);
        $this->assertStringContainsString(".selection-toolbar-icon-button {\n    width: 3.125rem;\n    min-width: 3.125rem;\n    height: 3.125rem;", $styles);
        $this->assertStringContainsString(".id-card-filter-row > .admin-toolbar__controls {\n    display: grid !important;\n    width: 100% !important;\n    inline-size: 100% !important;\n    grid-template-columns: repeat(3, minmax(0, 1fr)) max-content !important;", $styles);
        $this->assertStringContainsString('justify-self: stretch !important;', $styles);
        $this->assertStringContainsString('margin-inline: 0 !important;', $styles);
        $this->assertStringContainsString(".id-card-filter-row {\n    width: 100% !important;\n    inline-size: 100% !important;\n    max-width: none !important;", $styles);
        $this->assertStringContainsString('[data-source-panel]:has(.id-card-filter-row)', $styles);
        $this->assertStringContainsString(".id-card-filter-row > .admin-toolbar__controls > .admin-filter-field {\n    width: 100%;\n    min-width: 0;\n    max-width: none;", $styles);
        $this->assertLessThan(
            strpos($printTemplateSetup, 'data-source-select-visible="{{ $entity }}"'),
            strpos($printTemplateSetup, 'data-source-clear="{{ $entity }}"'),
        );
        $this->assertGreaterThan(
            strpos($printTemplateSetup, 'data-source-select-visible="{{ $entity }}"'),
            strpos($printTemplateSetup, 'data-toggle-selected-print-status'),
        );
        $this->assertLessThan(
            strpos($idCardSetup, 'data-id-card-select-visible'),
            strpos($idCardSetup, 'data-id-card-clear-selection'),
        );
    }

    public function test_role_permissions_and_edit_actions_use_symbols_without_visible_text(): void
    {
        $accessControl = file_get_contents(resource_path('views/livewire/settings/access-control.blade.php'));
        $icon = file_get_contents(resource_path('views/components/admin-action-icon.blade.php'));

        $this->assertStringContainsString('data-role-permissions-action', $accessControl);
        $this->assertStringContainsString('<x-admin-action-icon name="permissions" />', $accessControl);
        $this->assertStringContainsString('data-role-edit-action', $accessControl);
        $this->assertStringContainsString('<x-edit-action-button wire:click="openEditRoleModal', $accessControl);
        $this->assertStringNotContainsString("{{ __('access.roles.actions.permissions') }}\n                                        </button>", $accessControl);
        $this->assertStringNotContainsString("{{ __('access.roles.actions.edit') }}\n                                        </button>", $accessControl);
        $this->assertStringContainsString("@case('permissions')", $icon);
        $this->assertStringContainsString('m8.75 12 2.1 2.1 4.4-4.4', $icon);
    }

    public function test_mobile_card_tables_are_limited_to_assessments_and_workflow_table_add_actions_are_removed(): void
    {
        $styles = file_get_contents(resource_path('css/app.css'));

        $this->assertStringContainsString('.standard-mobile-table .responsive-records-mobile', $styles);
        $this->assertStringContainsString('.standard-mobile-table .points-ledger-mobile', $styles);
        $this->assertStringContainsString('.workflow-entry-action--hidden', $styles);

        foreach (['points', 'users', 'teachers', 'parents'] as $page) {
            $view = file_get_contents(resource_path("views/livewire/{$page}/index.blade.php"));

            $this->assertStringContainsString('standard-mobile-table', $view);
        }

        $assessmentView = file_get_contents(resource_path('views/livewire/assessments/index.blade.php'));
        $this->assertStringNotContainsString('standard-mobile-table', $assessmentView);

        $studentProgressView = file_get_contents(resource_path('views/livewire/students/progress.blade.php'));
        $this->assertStringContainsString('surface-table standard-mobile-table student-juz-progress-table', $studentProgressView);
        $this->assertStringContainsString('data-juz-progress-status-heading', $studentProgressView);
        $this->assertStringContainsString('data-juz-progress-status-cell', $studentProgressView);
        $this->assertStringContainsString('data-juz-progress-actions-heading', $studentProgressView);
        $this->assertStringContainsString('data-juz-progress-actions-cell', $studentProgressView);
        $this->assertStringContainsString('data-juz-progress-empty-action', $studentProgressView);
        $this->assertStringContainsString('data-student-progress-awqaf-save-action', $studentProgressView);
        $this->assertStringContainsString('.student-juz-progress-table [data-juz-progress-action]', $styles);
        $this->assertStringContainsString('.student-juz-progress-table [data-juz-progress-status]', $styles);
        $this->assertStringContainsString('grid-template-columns: 0.45rem minmax(0, 1fr) 0.45rem;', $styles);
        $this->assertStringContainsString('flex: 0 0 0.45rem;', $styles);
        $this->assertStringContainsString('data-student-progress-missing-pages', $studentProgressView);
        $this->assertStringContainsString('student-progress-missing-pages__table', $studentProgressView);
        $this->assertStringContainsString('missing_pages->values()->chunk(5)', $studentProgressView);
        $this->assertStringContainsString('.student-progress-missing-pages__table td {', $styles);

        foreach (['memorization', 'quran-partial-tests', 'quran-final-tests', 'quran-tests'] as $page) {
            $view = file_get_contents(resource_path("views/livewire/{$page}/index.blade.php"));

            $this->assertStringNotContainsString('wire:click="openCreateModal"', $view);
            $this->assertStringNotContainsString('workflow-entry-action--hidden', $view);
        }
    }

    public function test_sidebar_uses_the_approved_outline_icons_without_changing_reference_icons(): void
    {
        $items = app(SidebarNavigationService::class)->defaultItems();
        $expectedIcons = [
            'dashboard' => 'home',
            'reports' => 'chart-bar',
            'student_progress' => 'presentation-chart-line',
            'users' => 'user-group',
            'parents' => 'parents-couple',
            'teachers' => 'male-teacher',
            'students' => 'student-graduates',
            'community_contacts' => 'landline-phone',
            'courses' => 'mosque',
            'groups' => 'people-circle',
            'curricula' => 'books-leaning',
            'enrollments' => 'enrollment-add',
            'student_attendance' => 'clipboard-student',
            'teacher_attendance' => 'clipboard-person',
            'memorization' => 'quran-stand',
            'enter_memorize' => 'pencil-square',
            'quran_tests_quick_entry' => 'book-open-pencil',
            'quran_tests' => 'certificate-landscape',
            'assessments' => 'assessment-review',
            'point_ledger' => 'achievement-star',
            'student_notes' => 'note-document',
            'finance_dashboard' => 'finance-dashboard',
            'finance_expense_requests' => 'expense-receipt',
            'finance_revenue_requests' => 'income-hand',
            'finance_exchange' => 'arrows-right-left',
            'finance_reports' => 'document-chart-bar',
            'finance_pull_requests' => 'withdrawal-hand',
            'dashboard_settings' => 'cog-6-tooth',
            'finance_settings' => 'finance-settings',
            'public_website_settings' => 'globe-alt',
            'print_templates' => 'printing-template',
            'id_card_print' => 'student-id-card',
        ];

        $customIcons = [
            'achievement-star',
            'arrows-right-left',
            'assessment-review',
            'book-open-pencil',
            'books-leaning',
            'certificate-landscape',
            'clipboard-person',
            'clipboard-student',
            'enrollment-add',
            'expense-receipt',
            'finance-dashboard',
            'finance-settings',
            'income-hand',
            'landline-phone',
            'male-teacher',
            'mosque',
            'note-document',
            'parents-couple',
            'people-circle',
            'printing-template',
            'quran-stand',
            'student-id-card',
            'student-graduates',
            'withdrawal-hand',
        ];

        foreach ($expectedIcons as $key => $icon) {
            $this->assertSame($icon, $items[$key]['icon']);

            if (in_array($icon, $customIcons, true)) {
                $this->assertFileExists(resource_path("views/flux/icon/{$icon}.blade.php"));

                continue;
            }

            $this->assertFileExists(base_path("vendor/livewire/flux/stubs/resources/views/flux/icon/{$icon}.blade.php"));
        }

        $wrapper = file_get_contents(resource_path('views/components/sidebar-outline-icon.blade.php'));
        $this->assertStringContainsString('stroke-width="1.5"', $wrapper);
        $this->assertStringContainsString('stroke-linecap="round"', $wrapper);
        $this->assertStringContainsString('stroke-linejoin="round"', $wrapper);
        $this->assertStringContainsString('Str::uuid()', $wrapper);
        $this->assertStringContainsString('mask-type="luminance"', $wrapper);
        $this->assertStringContainsString('data-sidebar-icon-merged-paint', $wrapper);
        $this->assertStringContainsString('data-attendance-badge="person"', file_get_contents(resource_path('views/flux/icon/clipboard-person.blade.php')));
        $this->assertStringContainsString('data-attendance-badge="graduation-cap"', file_get_contents(resource_path('views/flux/icon/clipboard-student.blade.php')));
        $this->assertStringContainsString('data-enrollment-icon="profile-card-add"', file_get_contents(resource_path('views/flux/icon/enrollment-add.blade.php')));
        $this->assertStringContainsString('data-certificate-style="centered-medal"', file_get_contents(resource_path('views/flux/icon/certificate-landscape.blade.php')));

        $memorizationIcon = file_get_contents(resource_path('views/flux/icon/quran-stand.blade.php'));
        $this->assertStringContainsString('<x-sidebar-outline-icon', $memorizationIcon);
        $this->assertStringContainsString('data-memorization-icon="traced-quran-on-rehal"', $memorizationIcon);
        $this->assertStringNotContainsString("asset('images/sidebar/memorization.svg')", $memorizationIcon);
        $this->assertFileDoesNotExist(public_path('images/sidebar/memorization.svg'));

        $expenseIcon = file_get_contents(resource_path('views/flux/icon/expense-receipt.blade.php'));
        $this->assertStringContainsString('<x-sidebar-outline-icon', $expenseIcon);
        $this->assertStringContainsString('data-expense-icon="traced-rolled-receipt"', $expenseIcon);
        $this->assertStringNotContainsString("asset('images/sidebar/expenses.svg')", $expenseIcon);
        $this->assertFileDoesNotExist(public_path('images/sidebar/expenses.svg'));
    }

    public function test_secondary_student_tools_are_buttons_in_their_parent_pages(): void
    {
        $this->seed(RoleSeeder::class);
        $user = User::factory()->create(['username' => 'student-tools-manager', 'phone' => '9000099']);
        $user->assignRole('manager');
        $this->actingAs($user);

        $this->get(route('students.index', absolute: false))
            ->assertOk()
            ->assertDontSeeText(__('ui.nav.bulk_student_photos'));
        $this->get(route('student-attendance.index', absolute: false))
            ->assertOk()
            ->assertSeeText(__('ui.nav.scanner_import'));

        $items = app(SidebarNavigationService::class)->defaultItems();
        $this->assertArrayNotHasKey('bulk_student_photos', $items);
        $this->assertArrayNotHasKey('scanner_import', $items);
        $this->assertArrayNotHasKey('awqaf_subject_tests', $items);
        $this->assertFalse(Route::has('awqaf-subject-tests.index'));
    }

    public function test_lifecycle_and_confirmation_controls_keep_the_requested_icon_layout(): void
    {
        $layout = file_get_contents(resource_path('views/components/layouts/app/sidebar.blade.php'));
        $confirmation = substr($layout, strpos($layout, 'id="admin-confirm-modal"'));
        $styles = file_get_contents(resource_path('css/app.css'));
        $modal = file_get_contents(resource_path('views/components/admin/modal.blade.php'));
        $organization = file_get_contents(resource_path('views/livewire/settings/organization.blade.php'));
        $groupIndex = file_get_contents(resource_path('views/livewire/groups/index.blade.php'));
        $groupShow = file_get_contents(resource_path('views/livewire/groups/show.blade.php'));
        $icons = file_get_contents(resource_path('views/components/admin-action-icon.blade.php'));

        $this->assertStringNotContainsString('class="admin-modal__close"', $confirmation);
        $this->assertStringContainsString('id="admin-confirm-accept"', $confirmation);
        $this->assertStringContainsString('id="admin-confirm-deny"', $confirmation);
        $this->assertStringContainsString('admin-confirm-action--accept', $confirmation);
        $this->assertStringContainsString('admin-confirm-action--deny', $confirmation);
        $this->assertStringContainsString('grid-template-columns: repeat(2, minmax(0, 1fr));', $styles);
        $this->assertStringContainsString("'dismissible' => true", $modal);
        $this->assertStringContainsString("'hideHeader' => false", $modal);
        $this->assertStringContainsString('@if ($closeMethod && $dismissible)', $modal);
        $this->assertStringContainsString('@unless ($hideHeader)', $modal);
        $this->assertStringContainsString(':dismissible="! $academic_year_creation_required"', $organization);
        $this->assertStringContainsString('settings-academic-year-table', $organization);
        $this->assertStringNotContainsString('data-settings-academic-year-row-delete-action', $organization);
        $this->assertStringContainsString('data-settings-academic-year-delete-action', $organization);
        $this->assertStringContainsString("wire:confirm=\"{{ __('settings.organization.actions.finish_academic_year_confirm') }}\"", $organization);
        $this->assertStringNotContainsString('data-group-edit-action', $groupIndex);
        $this->assertStringContainsString('data-group-hero-edit-action', $groupShow);
        $this->assertStringContainsString('data-group-copy-summary data-group-hero-copy-action', $groupShow);
        $this->assertStringContainsString('M4.5 8.75h15v10.5', $icons);
        $this->assertStringContainsString('M20 8.5A8.25 8.25', $icons);
        $this->assertStringContainsString('M4.25 21 8.5 3', $icons);
        $this->assertStringContainsString(':hide-header="true"', file_get_contents(resource_path('views/livewire/students/progress.blade.php')));
        $this->assertStringContainsString('.awqaf-unavailable-warning__octagon {', $styles);
    }

    public function test_management_pages_require_authentication(): void
    {
        [$group, $student, $activity, $invoice] = $this->makeRouteModels();

        foreach ([
            route('reports.index', absolute: false),
            route('reports.student-activity-summary', absolute: false),
            route('reports.rankings.groups', absolute: false),
            route('reports.rankings.students', absolute: false),
            route('parents.index', absolute: false),
            route('teachers.index', absolute: false),
            route('students.index', absolute: false),
            route('students.files', $student, absolute: false),
            route('memorization.index', absolute: false),
            route('student-attendance.index', absolute: false),
            route('courses.index', absolute: false),
            route('groups.index', absolute: false),
            route('groups.show', $group, absolute: false),
            route('groups.schedules', $group, absolute: false),
            route('enrollments.index', absolute: false),
            route('student-notes.index', absolute: false),
            route('quran-partial-tests.index', absolute: false),
            route('quran-final-tests.index', absolute: false),
            route('quran-tests.index', absolute: false),
            route('points.index', absolute: false),
            route('activities.index', absolute: false),
            route('activities.family', absolute: false),
            route('activities.finance', $activity, absolute: false),
            route('invoices.index', absolute: false),
            route('invoices.payments', $invoice, absolute: false),
        ] as $path) {
            $this->get($path)->assertRedirect('/login');
        }
    }

    public function test_authenticated_users_can_open_management_pages(): void
    {
        $this->seed(RoleSeeder::class);
        [$group, $student, $activity, $invoice] = $this->makeRouteModels();

        $user = User::factory()->create([
            'username' => 'manager-user',
            'phone' => '9000000',
        ]);

        $user->assignRole('manager');

        $this->actingAs($user);

        foreach ([
            route('reports.index', absolute: false),
            route('reports.student-activity-summary', absolute: false),
            route('reports.rankings.groups', absolute: false),
            route('reports.rankings.students', absolute: false),
            route('parents.index', absolute: false),
            route('teachers.index', absolute: false),
            route('students.index', absolute: false),
            route('students.files', $student, absolute: false),
            route('memorization.index', absolute: false),
            route('student-attendance.index', absolute: false),
            route('courses.index', absolute: false),
            route('groups.index', absolute: false),
            route('groups.show', $group, absolute: false),
            route('groups.schedules', $group, absolute: false),
            route('enrollments.index', absolute: false),
            route('student-notes.index', absolute: false),
            route('quran-partial-tests.index', absolute: false),
            route('quran-final-tests.index', absolute: false),
            route('quran-tests.index', absolute: false),
            route('points.index', absolute: false),
            route('activities.index', absolute: false),
            route('activities.finance', $activity, absolute: false),
            route('invoices.index', absolute: false),
            route('invoices.payments', $invoice, absolute: false),
        ] as $path) {
            $this->get($path)->assertOk();
        }
    }

    public function test_authenticated_users_can_download_group_roster_export(): void
    {
        $this->seed(RoleSeeder::class);
        [$group] = $this->makeRouteModels();

        $user = User::factory()->create([
            'username' => 'manager-roster-export',
            'phone' => '9000001',
        ]);

        $user->assignRole('manager');

        $this->actingAs($user);

        $this->get(route('groups.roster.export', $group, absolute: false))
            ->assertOk()
            ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $pdfResponse = $this->get(route('groups.roster.pdf', $group, absolute: false));
        $pdfResponse
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
        $this->assertStringContainsString(
            rawurlencode(__('exports.pdf.group_roster')),
            (string) $pdfResponse->headers->get('content-disposition'),
        );
        $this->assertStringStartsWith('%PDF', (string) $pdfResponse->getContent());
    }

    public function test_authenticated_users_can_download_eligible_awqaf_students_export(): void
    {
        $this->seed(RoleSeeder::class);

        $user = User::factory()->create([
            'username' => 'manager-awqaf-export',
            'phone' => '9000002',
        ]);
        $user->assignRole('manager');
        $this->actingAs($user);

        $teacher = Teacher::create([
            'first_name' => 'Awqaf',
            'last_name' => 'Export Teacher',
            'phone' => '0944005100',
            'status' => 'active',
        ]);

        $course = Course::create([
            'name' => 'Awqaf Export Course',
            'is_active' => true,
        ]);

        $academicYear = AcademicYear::query()->where('is_current', true)->first()
            ?? AcademicYear::create([
                'name' => '2026 / 2027',
                'starts_on' => '2026-09-01',
                'ends_on' => '2027-06-30',
                'is_current' => true,
                'is_active' => true,
            ]);

        $group = Group::create([
            'course_id' => $course->id,
            'academic_year_id' => $academicYear->id,
            'teacher_id' => $teacher->id,
            'name' => 'Awqaf Export Group',
            'capacity' => 12,
            'is_active' => true,
        ]);

        $parent = ParentProfile::create([
            'father_name' => 'Export Father',
            'is_active' => true,
        ]);

        $student = Student::create([
            'parent_id' => $parent->id,
            'first_name' => 'Eligible',
            'last_name' => 'Student',
            'birth_date' => '2012-01-01',
            'status' => 'active',
        ]);

        $enrollment = Enrollment::create([
            'student_id' => $student->id,
            'group_id' => $group->id,
            'enrolled_at' => '2026-09-01',
            'status' => 'active',
        ]);

        $juz = QuranJuz::query()->first()
            ?? QuranJuz::create([
                'juz_number' => 1,
                'name' => 'Juz 1',
                'from_page' => 1,
                'to_page' => 20,
            ]);

        QuranFinalTest::create([
            'created_by' => $user->id,
            'enrollment_id' => $enrollment->id,
            'student_id' => $student->id,
            'juz_id' => $juz->id,
            'status' => 'passed',
            'passed_on' => '2026-09-10',
        ]);

        $this->get(route('quran-tests.eligible-awqaf.export', absolute: false))
            ->assertOk()
            ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    public function test_authenticated_users_without_management_permissions_are_forbidden(): void
    {
        $this->seed(RoleSeeder::class);
        [$teacherUser, $teacherGroup, $teacherStudent, $teacherEnrollment, $otherGroup, $otherEnrollment, $activity, $invoice] = $this->makeTeacherJourneyModels();

        $this->actingAs($teacherUser);

        foreach ([
            route('students.index', absolute: false),
            route('students.files', $teacherStudent, absolute: false),
            route('groups.attendance', $teacherGroup, absolute: false),
            route('student-attendance.index', absolute: false),
            route('groups.schedules', $teacherGroup, absolute: false),
            route('enrollments.index', absolute: false),
            route('memorization.index', absolute: false),
            route('enrollments.memorization', $teacherEnrollment, absolute: false),
            route('quran-partial-tests.index', absolute: false),
            route('quran-final-tests.index', absolute: false),
            route('enrollments.quran-tests', $teacherEnrollment, absolute: false),
            route('enrollments.points', $teacherEnrollment, absolute: false),
            route('quran-tests.index', absolute: false),
            route('points.index', absolute: false),
        ] as $path) {
            $this->get($path)->assertOk();
        }

        $this->get(route('groups.index', absolute: false))
            ->assertOk()
            ->assertSeeText($teacherGroup->name)
            ->assertSee('class="groups-index-table', false)
            ->assertSee('data-groups-course-column="20"', false)
            ->assertSee('data-groups-status-column="8"', false);

        foreach ([
            route('reports.index', absolute: false),
            route('reports.student-activity-summary', absolute: false),
            route('reports.rankings.groups', absolute: false),
            route('reports.rankings.students', absolute: false),
            route('parents.index', absolute: false),
            route('teachers.index', absolute: false),
            route('courses.index', absolute: false),
            route('activities.index', absolute: false),
            route('activities.finance', $activity, absolute: false),
            route('invoices.index', absolute: false),
            route('invoices.payments', $invoice, absolute: false),
            route('groups.attendance', $otherGroup, absolute: false),
            route('enrollments.memorization', $otherEnrollment, absolute: false),
        ] as $path) {
            $this->get($path)->assertForbidden();
        }
    }

    public function test_parent_users_can_open_their_read_only_student_enrollment_and_invoice_pages(): void
    {
        $this->seed(RoleSeeder::class);
        [$parentUser, $ownStudent, $ownEnrollment, $otherEnrollment, $ownInvoice, $otherInvoice] = $this->makeParentJourneyModels();

        $this->actingAs($parentUser);

        $this->get(route('students.index', absolute: false))
            ->assertOk()
            ->assertSeeText('Parent Student')
            ->assertDontSeeText('Other Student');

        $this->get(route('enrollments.index', absolute: false))
            ->assertOk()
            ->assertSeeText('Parent Group')
            ->assertDontSeeText('Other Group');

        MemorizationSession::create([
            'enrollment_id' => $ownEnrollment->id,
            'student_id' => $ownEnrollment->student_id,
            'teacher_id' => $ownEnrollment->group->teacher_id,
            'recorded_on' => '2026-09-10',
            'entry_type' => 'new',
            'from_page' => 3,
            'to_page' => 5,
            'pages_count' => 3,
        ]);

        MemorizationSession::create([
            'enrollment_id' => $otherEnrollment->id,
            'student_id' => $otherEnrollment->student_id,
            'teacher_id' => $otherEnrollment->group->teacher_id,
            'recorded_on' => '2026-09-11',
            'entry_type' => 'new',
            'from_page' => 6,
            'to_page' => 8,
            'pages_count' => 3,
        ]);

        $this->get(route('memorization.index', absolute: false))
            ->assertOk()
            ->assertSeeText('Parent Student')
            ->assertDontSeeText('Other Student');

        $this->get(route('invoices.index', absolute: false))
            ->assertOk()
            ->assertSeeText($ownInvoice->invoice_no)
            ->assertDontSeeText($otherInvoice->invoice_no);

        Activity::create([
            'title' => 'Parent Activity',
            'activity_date' => '2026-09-22',
            'audience_scope' => 'single_group',
            'group_id' => $ownEnrollment->group_id,
            'fee_amount' => 15,
            'is_active' => true,
        ]);

        Activity::create([
            'title' => 'Hidden Parent Activity',
            'activity_date' => '2026-09-23',
            'audience_scope' => 'single_group',
            'group_id' => $otherEnrollment->group_id,
            'fee_amount' => 18,
            'is_active' => true,
        ]);

        $this->get(route('activities.family', absolute: false))
            ->assertOk()
            ->assertSeeText('Parent Activity')
            ->assertDontSeeText('Hidden Parent Activity');

        foreach ([
            route('students.files', $ownStudent, absolute: false),
            route('quran-tests.index', absolute: false),
            route('points.index', absolute: false),
            route('enrollments.memorization', $ownEnrollment, absolute: false),
            route('enrollments.quran-tests', $ownEnrollment, absolute: false),
            route('enrollments.points', $ownEnrollment, absolute: false),
            route('invoices.payments', $ownInvoice, absolute: false),
        ] as $path) {
            $this->get($path)->assertOk();
        }

        foreach ([
            route('quran-partial-tests.index', absolute: false),
            route('quran-final-tests.index', absolute: false),
            route('enrollments.memorization', $otherEnrollment, absolute: false),
            route('invoices.payments', $otherInvoice, absolute: false),
        ] as $path) {
            $this->get($path)->assertForbidden();
        }
    }

    public function test_student_users_can_open_their_read_only_progress_pages(): void
    {
        $this->seed(RoleSeeder::class);
        [$studentUser, $studentRecord, $ownEnrollment, $otherEnrollment] = $this->makeStudentJourneyModels();

        $this->actingAs($studentUser);

        $this->get(route('students.index', absolute: false))
            ->assertOk()
            ->assertSeeText($studentRecord->first_name.' '.$studentRecord->last_name)
            ->assertDontSeeText('Other Student');

        $this->get(route('enrollments.index', absolute: false))
            ->assertOk()
            ->assertSeeText('Student Scope Group')
            ->assertDontSeeText('Other Group');

        MemorizationSession::create([
            'enrollment_id' => $ownEnrollment->id,
            'student_id' => $ownEnrollment->student_id,
            'teacher_id' => $ownEnrollment->group->teacher_id,
            'recorded_on' => '2026-09-12',
            'entry_type' => 'new',
            'from_page' => 9,
            'to_page' => 11,
            'pages_count' => 3,
        ]);

        MemorizationSession::create([
            'enrollment_id' => $otherEnrollment->id,
            'student_id' => $otherEnrollment->student_id,
            'teacher_id' => $otherEnrollment->group->teacher_id,
            'recorded_on' => '2026-09-13',
            'entry_type' => 'new',
            'from_page' => 12,
            'to_page' => 14,
            'pages_count' => 3,
        ]);

        $this->get(route('memorization.index', absolute: false))
            ->assertOk()
            ->assertSeeText($studentRecord->first_name.' '.$studentRecord->last_name)
            ->assertDontSeeText('Other Student');

        foreach ([
            route('students.files', $studentRecord, absolute: false),
            route('quran-tests.index', absolute: false),
            route('points.index', absolute: false),
            route('enrollments.memorization', $ownEnrollment, absolute: false),
            route('enrollments.quran-tests', $ownEnrollment, absolute: false),
            route('enrollments.points', $ownEnrollment, absolute: false),
        ] as $path) {
            $this->get($path)->assertOk();
        }

        foreach ([
            route('quran-partial-tests.index', absolute: false),
            route('quran-final-tests.index', absolute: false),
            route('students.files', $otherEnrollment->student, absolute: false),
            route('enrollments.memorization', $otherEnrollment, absolute: false),
        ] as $path) {
            $this->get($path)->assertForbidden();
        }
    }

    private function makeRouteModels(): array
    {
        $parent = ParentProfile::create([
            'father_name' => 'Route Parent',
        ]);

        $teacher = Teacher::create([
            'first_name' => 'Route',
            'last_name' => 'Teacher',
            'phone' => '0990000000',
            'status' => 'active',
        ]);

        $course = Course::create([
            'name' => 'Route Course',
            'is_active' => true,
        ]);

        $academicYear = AcademicYear::create([
            'name' => '2026/2027',
            'starts_on' => '2026-08-01',
            'ends_on' => '2027-07-31',
            'is_current' => true,
            'is_active' => true,
        ]);

        $group = Group::create([
            'course_id' => $course->id,
            'academic_year_id' => $academicYear->id,
            'teacher_id' => $teacher->id,
            'name' => 'Route Group',
            'capacity' => 10,
            'is_active' => true,
        ]);

        $student = Student::create([
            'parent_id' => $parent->id,
            'first_name' => 'Route',
            'last_name' => 'Student',
            'birth_date' => '2014-05-12',
            'status' => 'active',
        ]);

        $activity = Activity::create([
            'title' => 'Route Activity',
            'activity_date' => '2026-09-15',
            'group_id' => $group->id,
            'fee_amount' => 25,
            'is_active' => true,
        ]);

        $invoice = Invoice::create([
            'parent_id' => $parent->id,
            'invoice_no' => 'INV-ROUTE-0001',
            'invoice_type' => 'other',
            'issue_date' => '2026-09-20',
            'status' => 'issued',
            'subtotal' => 0,
            'discount' => 0,
            'total' => 0,
        ]);

        return [$group, $student, $activity, $invoice];
    }

    private function makeTeacherJourneyModels(): array
    {
        $teacherUser = User::factory()->create([
            'username' => 'teacher-user',
            'phone' => '9000001',
        ]);
        $teacherUser->assignRole('teacher');

        $teacher = Teacher::create([
            'user_id' => $teacherUser->id,
            'first_name' => 'Journey',
            'last_name' => 'Teacher',
            'phone' => '0991000001',
            'status' => 'active',
        ]);

        $otherTeacher = Teacher::create([
            'first_name' => 'Other',
            'last_name' => 'Teacher',
            'phone' => '0991000002',
            'status' => 'active',
        ]);

        $course = Course::create([
            'name' => 'Teacher Journey Course',
            'is_active' => true,
        ]);

        $academicYear = AcademicYear::create([
            'name' => '2026/2027',
            'starts_on' => '2026-08-01',
            'ends_on' => '2027-07-31',
            'is_current' => true,
            'is_active' => true,
        ]);

        $parent = ParentProfile::create([
            'father_name' => 'Journey Parent',
        ]);

        $student = Student::create([
            'parent_id' => $parent->id,
            'first_name' => 'Teacher',
            'last_name' => 'Student',
            'birth_date' => '2014-05-12',
            'status' => 'active',
        ]);

        $teacherGroup = Group::create([
            'course_id' => $course->id,
            'academic_year_id' => $academicYear->id,
            'teacher_id' => $teacher->id,
            'name' => 'Teacher Group',
            'capacity' => 10,
            'is_active' => true,
        ]);

        $otherGroup = Group::create([
            'course_id' => $course->id,
            'academic_year_id' => $academicYear->id,
            'teacher_id' => $otherTeacher->id,
            'name' => 'Other Group',
            'capacity' => 10,
            'is_active' => true,
        ]);

        $teacherEnrollment = Enrollment::create([
            'student_id' => $student->id,
            'group_id' => $teacherGroup->id,
            'enrolled_at' => '2026-09-01',
            'status' => 'active',
        ]);

        $otherEnrollment = Enrollment::create([
            'student_id' => $student->id,
            'group_id' => $otherGroup->id,
            'enrolled_at' => '2026-09-02',
            'status' => 'active',
        ]);

        $activity = Activity::create([
            'title' => 'Teacher Activity',
            'activity_date' => '2026-09-15',
            'group_id' => $otherGroup->id,
            'fee_amount' => 25,
            'is_active' => true,
        ]);

        $invoice = Invoice::create([
            'parent_id' => $parent->id,
            'invoice_no' => 'INV-TEACHER-0001',
            'invoice_type' => 'other',
            'issue_date' => '2026-09-20',
            'status' => 'issued',
            'subtotal' => 0,
            'discount' => 0,
            'total' => 0,
        ]);

        return [$teacherUser, $teacherGroup, $student, $teacherEnrollment, $otherGroup, $otherEnrollment, $activity, $invoice];
    }

    private function makeParentJourneyModels(): array
    {
        $parentUser = User::factory()->create([
            'username' => 'parent-user',
            'phone' => '9000002',
        ]);
        $parentUser->assignRole('parent');

        $parent = ParentProfile::create([
            'user_id' => $parentUser->id,
            'father_name' => 'Scoped Parent',
        ]);

        $otherParent = ParentProfile::create([
            'father_name' => 'Other Parent',
        ]);

        $teacher = Teacher::create([
            'first_name' => 'Parent',
            'last_name' => 'Teacher',
            'phone' => '0991000003',
            'status' => 'active',
        ]);

        $course = Course::create([
            'name' => 'Parent Journey Course',
            'is_active' => true,
        ]);

        $academicYear = AcademicYear::create([
            'name' => '2026/2027',
            'starts_on' => '2026-08-01',
            'ends_on' => '2027-07-31',
            'is_current' => true,
            'is_active' => true,
        ]);

        $ownStudent = Student::create([
            'parent_id' => $parent->id,
            'first_name' => 'Parent',
            'last_name' => 'Student',
            'birth_date' => '2014-05-12',
            'status' => 'active',
        ]);

        $otherStudent = Student::create([
            'parent_id' => $otherParent->id,
            'first_name' => 'Other',
            'last_name' => 'Student',
            'birth_date' => '2014-05-13',
            'status' => 'active',
        ]);

        $ownGroup = Group::create([
            'course_id' => $course->id,
            'academic_year_id' => $academicYear->id,
            'teacher_id' => $teacher->id,
            'name' => 'Parent Group',
            'capacity' => 10,
            'is_active' => true,
        ]);

        $otherGroup = Group::create([
            'course_id' => $course->id,
            'academic_year_id' => $academicYear->id,
            'teacher_id' => $teacher->id,
            'name' => 'Other Group',
            'capacity' => 10,
            'is_active' => true,
        ]);

        $ownEnrollment = Enrollment::create([
            'student_id' => $ownStudent->id,
            'group_id' => $ownGroup->id,
            'enrolled_at' => '2026-09-01',
            'status' => 'active',
        ]);

        $otherEnrollment = Enrollment::create([
            'student_id' => $otherStudent->id,
            'group_id' => $otherGroup->id,
            'enrolled_at' => '2026-09-02',
            'status' => 'active',
        ]);

        $ownInvoice = Invoice::create([
            'parent_id' => $parent->id,
            'invoice_no' => 'INV-PARENT-0001',
            'invoice_type' => 'tuition',
            'issue_date' => '2026-09-20',
            'status' => 'issued',
            'subtotal' => 100,
            'discount' => 0,
            'total' => 100,
        ]);

        $otherInvoice = Invoice::create([
            'parent_id' => $otherParent->id,
            'invoice_no' => 'INV-PARENT-0002',
            'invoice_type' => 'tuition',
            'issue_date' => '2026-09-21',
            'status' => 'issued',
            'subtotal' => 120,
            'discount' => 0,
            'total' => 120,
        ]);

        return [$parentUser, $ownStudent, $ownEnrollment, $otherEnrollment, $ownInvoice, $otherInvoice];
    }

    private function makeStudentJourneyModels(): array
    {
        $studentUser = User::factory()->create([
            'username' => 'student-user',
            'phone' => '9000003',
        ]);
        $studentUser->assignRole('student');

        $parent = ParentProfile::create([
            'father_name' => 'Student Parent',
        ]);

        $studentRecord = Student::create([
            'user_id' => $studentUser->id,
            'parent_id' => $parent->id,
            'first_name' => 'Scoped',
            'last_name' => 'Student',
            'birth_date' => '2014-05-12',
            'status' => 'active',
        ]);

        $otherStudent = Student::create([
            'parent_id' => $parent->id,
            'first_name' => 'Other',
            'last_name' => 'Student',
            'birth_date' => '2014-05-13',
            'status' => 'active',
        ]);

        $teacher = Teacher::create([
            'first_name' => 'Student',
            'last_name' => 'Teacher',
            'phone' => '0991000004',
            'status' => 'active',
        ]);

        $course = Course::create([
            'name' => 'Student Journey Course',
            'is_active' => true,
        ]);

        $academicYear = AcademicYear::create([
            'name' => '2026/2027',
            'starts_on' => '2026-08-01',
            'ends_on' => '2027-07-31',
            'is_current' => true,
            'is_active' => true,
        ]);

        $ownGroup = Group::create([
            'course_id' => $course->id,
            'academic_year_id' => $academicYear->id,
            'teacher_id' => $teacher->id,
            'name' => 'Student Scope Group',
            'capacity' => 10,
            'is_active' => true,
        ]);

        $otherGroup = Group::create([
            'course_id' => $course->id,
            'academic_year_id' => $academicYear->id,
            'teacher_id' => $teacher->id,
            'name' => 'Other Group',
            'capacity' => 10,
            'is_active' => true,
        ]);

        $ownEnrollment = Enrollment::create([
            'student_id' => $studentRecord->id,
            'group_id' => $ownGroup->id,
            'enrolled_at' => '2026-09-01',
            'status' => 'active',
        ]);

        $otherEnrollment = Enrollment::create([
            'student_id' => $otherStudent->id,
            'group_id' => $otherGroup->id,
            'enrolled_at' => '2026-09-02',
            'status' => 'active',
        ]);

        return [$studentUser, $studentRecord, $ownEnrollment, $otherEnrollment];
    }
}
