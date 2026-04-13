<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\BarcodeAction;
use App\Models\BarcodeScanImport;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Group;
use App\Models\GroupAttendanceDay;
use App\Models\ParentProfile;
use App\Models\PointTransaction;
use App\Models\PointType;
use App\Models\Student;
use App\Models\StudentAttendanceDay;
use App\Models\StudentAttendanceRecord;
use App\Models\Teacher;
use App\Models\User;
use App\Services\BarcodeActions\BarcodeActionCatalogService;
use App\Services\BarcodeActions\ScannerDumpImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Volt\Volt;
use Tests\TestCase;

class BarcodeScannerWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_manager_can_open_barcode_pages_and_print_action_labels(): void
    {
        $this->seed();

        $manager = User::factory()->create(['username' => 'barcode-page-manager']);
        $manager->assignRole('manager');

        $this->actingAs($manager)
            ->get(route('barcode-actions.index', absolute: false))
            ->assertOk()
            ->assertSee('Action Barcodes');

        $this->actingAs($manager)
            ->get(route('barcode-actions.import', absolute: false))
            ->assertOk()
            ->assertSee('Scanner Import');

        Volt::test('barcode-actions.import')
            ->assertSet('attendance_date', now()->toDateString())
            ->set('attendance_date', '2026-01-01')
            ->call('resetAttendanceDateToToday')
            ->assertSet('attendance_date', now()->toDateString());

        $action = BarcodeAction::query()->where('code', 'ACT-ATT-PRESENT')->firstOrFail();

        $this->actingAs($manager)
            ->post(route('barcode-actions.print.preview', absolute: false), [
                'action_ids' => [$action->id],
                'label_width_mm' => 70,
                'label_height_mm' => 35,
                'page_width_mm' => 210,
                'page_height_mm' => 297,
                'margin_top_mm' => 10,
                'margin_right_mm' => 10,
                'margin_bottom_mm' => 10,
                'margin_left_mm' => 10,
                'gap_x_mm' => 6,
                'gap_y_mm' => 6,
            ])
            ->assertOk()
            ->assertSee('ACT-ATT-PRESENT')
            ->assertSee('<svg', false);
    }

    public function test_scanner_dump_applies_attendance_and_point_actions_in_fifo_order(): void
    {
        $this->seed();

        $manager = User::factory()->create(['username' => 'barcode-manager']);
        $manager->assignRole('manager');
        $this->actingAs($manager);

        $enrollment = $this->makeEnrollment();
        $catalog = app(BarcodeActionCatalogService::class);
        $bonusType = PointType::query()->create([
            'name' => 'Scanner Participation',
            'code' => 'scanner-participation',
            'category' => 'manual',
            'default_points' => 5,
            'allow_manual_entry' => true,
            'allow_negative' => false,
            'is_active' => true,
        ]);
        $catalog->syncReferenceActions();

        $this->assertDatabaseHas('barcode_actions', [
            'code' => $catalog->pointActionCode($bonusType),
            'point_type_id' => $bonusType->id,
            'points' => 5,
            'is_active' => true,
        ]);

        $rawDump = implode("\n", [
            'ACT-ATT-PRESENT',
            (string) $enrollment->student_id,
            'ACT-PTS-SCANNER-PARTICIPATION',
            (string) $enrollment->student_id,
        ]);

        $service = app(ScannerDumpImportService::class);
        $preview = $service->preview($enrollment->group->course_id, '2026-04-11', $rawDump, $manager);

        $this->assertSame(2, $preview['ready_count']);
        $this->assertSame(0, $preview['error_count']);

        $service->apply($enrollment->group->course_id, '2026-04-11', $rawDump, $manager);

        $this->assertDatabaseHas('student_attendance_records', [
            'enrollment_id' => $enrollment->id,
        ]);
        $this->assertDatabaseHas('point_transactions', [
            'student_id' => $enrollment->student_id,
            'enrollment_id' => $enrollment->id,
            'source_type' => 'barcode_scan',
            'points' => 5,
        ]);
        $this->assertSame(2, BarcodeScanImport::query()->firstOrFail()->processed_count);
        $this->assertSame(1, StudentAttendanceRecord::query()->where('enrollment_id', $enrollment->id)->count());
        $this->assertSame(1, PointTransaction::query()->where('source_type', 'barcode_scan')->where('enrollment_id', $enrollment->id)->count());
    }

    public function test_student_scan_before_action_is_rejected_without_writes(): void
    {
        $this->seed();

        $manager = User::factory()->create(['username' => 'barcode-manager-two']);
        $manager->assignRole('manager');
        $this->actingAs($manager);

        $enrollment = $this->makeEnrollment();
        app(BarcodeActionCatalogService::class)->syncReferenceActions();

        $service = app(ScannerDumpImportService::class);
        $result = $service->apply($enrollment->group->course_id, '2026-04-11', (string) $enrollment->student_id, $manager);

        $this->assertSame(1, $result['error_count']);
        $this->assertDatabaseCount('barcode_scan_imports', 0);
        $this->assertDatabaseCount('student_attendance_records', 0);
        $this->assertDatabaseMissing('point_transactions', [
            'source_type' => 'barcode_scan',
        ]);
    }

    public function test_scanner_attendance_reuses_day_when_second_course_is_imported_for_same_date(): void
    {
        $this->seed();

        $manager = User::factory()->create(['username' => 'barcode-manager-three']);
        $manager->assignRole('manager');
        $this->actingAs($manager);

        $firstEnrollment = $this->makeEnrollment();
        $secondEnrollment = $this->makeEnrollment();
        app(BarcodeActionCatalogService::class)->syncReferenceActions();

        $service = app(ScannerDumpImportService::class);
        $attendanceDate = '2026-04-13';

        $service->apply($firstEnrollment->group->course_id, $attendanceDate, "ACT-ATT-PRESENT\n{$firstEnrollment->student_id}", $manager);

        DB::table('student_attendance_days')
            ->whereDate('attendance_date', $attendanceDate)
            ->update(['attendance_date' => $attendanceDate.' 00:00:00']);
        DB::table('group_attendance_days')
            ->whereDate('attendance_date', $attendanceDate)
            ->update(['attendance_date' => $attendanceDate.' 00:00:00']);

        $service->apply($secondEnrollment->group->course_id, $attendanceDate, "ACT-ATT-PRESENT\n{$secondEnrollment->student_id}", $manager);

        $this->assertSame(1, StudentAttendanceDay::query()->whereDate('attendance_date', $attendanceDate)->count());
        $this->assertSame(2, GroupAttendanceDay::query()->whereDate('attendance_date', $attendanceDate)->count());
        $this->assertSame(1, StudentAttendanceRecord::query()->where('enrollment_id', $firstEnrollment->id)->count());
        $this->assertSame(1, StudentAttendanceRecord::query()->where('enrollment_id', $secondEnrollment->id)->count());
    }

    protected function makeEnrollment(): Enrollment
    {
        static $sequence = 0;

        $sequence++;

        $parent = ParentProfile::query()->create([
            'father_name' => 'Barcode Parent',
            'is_active' => true,
        ]);

        $student = Student::query()->create([
            'parent_id' => $parent->id,
            'first_name' => 'Barcode',
            'last_name' => 'Student',
            'birth_date' => '2015-01-01',
            'status' => 'active',
        ])->fresh();

        $teacher = Teacher::query()->create([
            'first_name' => 'Barcode',
            'last_name' => 'Teacher',
            'phone' => fake()->unique()->numerify('0998#######'),
            'status' => 'active',
        ]);

        $course = Course::query()->create([
            'name' => 'Barcode Course '.$sequence,
            'is_active' => true,
        ]);

        $academicYear = AcademicYear::query()->create([
            'name' => 'Barcode Year '.$sequence,
            'starts_on' => '2026-01-01',
            'ends_on' => '2026-12-31',
            'is_current' => true,
            'is_active' => true,
        ]);

        $group = Group::query()->create([
            'course_id' => $course->id,
            'academic_year_id' => $academicYear->id,
            'teacher_id' => $teacher->id,
            'name' => 'Barcode Group '.$sequence,
            'capacity' => 20,
            'starts_on' => '2026-01-01',
            'ends_on' => '2026-12-31',
            'monthly_fee' => 0,
            'is_active' => true,
        ]);

        return Enrollment::query()->create([
            'student_id' => $student->id,
            'group_id' => $group->id,
            'enrolled_at' => '2026-01-01',
            'status' => 'active',
        ])->fresh(['student', 'group.course']);
    }
}
