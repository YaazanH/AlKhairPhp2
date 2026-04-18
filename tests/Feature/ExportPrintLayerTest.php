<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\AppSetting;
use App\Models\Assessment;
use App\Models\AssessmentResult;
use App\Models\AssessmentType;
use App\Models\AttendanceStatus;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Group;
use App\Models\GroupAttendanceDay;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\MemorizationSession;
use App\Models\ParentProfile;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\PointTransaction;
use App\Models\PointType;
use App\Models\Student;
use App\Models\StudentAttendanceRecord;
use App\Models\Teacher;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use ZipArchive;

class ExportPrintLayerTest extends TestCase
{
    use RefreshDatabase;

    public function test_export_and_print_routes_require_authentication_and_permissions(): void
    {
        $context = $this->reportingContext();

        $teacherUser = User::factory()->create([
            'name' => 'Export Teacher',
            'phone' => '0777000202',
            'username' => 'export-teacher',
        ]);
        $teacherUser->assignRole('teacher');

        $this->get(route('reports.exports.attendance'))->assertRedirect(route('login'));
        $this->get(route('invoices.print', $context['invoice']))->assertRedirect(route('login'));
        $this->get(route('payments.receipt', $context['payment']))->assertRedirect(route('login'));

        $this->actingAs($teacherUser);
        $this->get(route('reports.exports.attendance'))->assertForbidden();
        $this->get(route('invoices.print', $context['invoice']))->assertForbidden();
        $this->get(route('payments.receipt', $context['payment']))->assertForbidden();
    }

    public function test_manager_can_download_report_exports(): void
    {
        $context = $this->reportingContext();
        $this->actingAs($context['manager']);

        $attendanceResponse = $this->get(route('reports.exports.attendance', [
            'academic_year_id' => $context['academicYear']->id,
            'date_from' => '2026-10-01',
            'date_to' => '2026-10-31',
            'group_id' => $context['group']->id,
        ]));

        $attendanceResponse->assertOk();
        $this->assertStringContainsString('spreadsheetml.sheet', $attendanceResponse->headers->get('content-type'));
        $this->assertXlsxContains($attendanceResponse->streamedContent(), ['Invoice Student']);

        $memorizationResponse = $this->get(route('reports.exports.memorization', [
            'group_id' => $context['group']->id,
        ]));
        $memorizationResponse->assertOk();
        $this->assertXlsxContains($memorizationResponse->streamedContent(), ['Invoice Student']);

        $pointsResponse = $this->get(route('reports.exports.points', [
            'group_id' => $context['group']->id,
        ]));
        $pointsResponse->assertOk();
        $this->assertXlsxContains($pointsResponse->streamedContent(), ['Invoice Student', 'Behavior Bonus']);

        $assessmentResponse = $this->get(route('reports.exports.assessments', [
            'group_id' => $context['group']->id,
        ]));
        $assessmentResponse->assertOk();
        $this->assertXlsxContains($assessmentResponse->streamedContent(), ['Midterm Quiz', 'Invoice Student']);
    }

    public function test_manager_can_render_invoice_and_receipt_print_views(): void
    {
        $context = $this->reportingContext();
        $this->actingAs($context['manager']);

        $this->get(route('invoices.print', $context['invoice']))
            ->assertOk()
            ->assertSeeText('Invoice')
            ->assertSeeText('Alkhair Center')
            ->assertSeeText($context['invoice']->invoice_no)
            ->assertSeeText('Invoice Student');

        $this->get(route('payments.receipt', $context['payment']))
            ->assertOk()
            ->assertSeeText(__('print.receipt.title'))
            ->assertSeeText($context['invoice']->invoice_no)
            ->assertSeeText('Invoice Parent')
            ->assertSeeText('Cash');
    }

    private function assertXlsxContains(string $content, array $needles): void
    {
        $path = tempnam(sys_get_temp_dir(), 'export-test-');
        file_put_contents($path, $content);

        $zip = new ZipArchive();
        $this->assertTrue($zip->open($path) === true);

        $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        @unlink($path);

        $this->assertIsString($sheetXml);

        foreach ($needles as $needle) {
            $this->assertStringContainsString($needle, $sheetXml);
        }
    }

    private function reportingContext(): array
    {
        $this->seed();
        $this->seed(RoleSeeder::class);

        $manager = User::factory()->create([
            'name' => 'Export Manager',
            'phone' => '0777000201',
            'username' => 'export-manager',
        ]);
        $manager->assignRole('manager');

        AppSetting::query()->updateOrCreate(
            ['group' => 'general', 'key' => 'school_name'],
            ['type' => 'string', 'value' => 'Alkhair Center'],
        );

        $academicYear = AcademicYear::query()->where('is_current', true)->firstOrFail();

        $parent = ParentProfile::query()->create([
            'father_name' => 'Invoice Parent',
            'father_phone' => '0944000200',
        ]);

        $student = Student::query()->create([
            'birth_date' => '2014-04-10',
            'first_name' => 'Invoice',
            'last_name' => 'Student',
            'parent_id' => $parent->id,
            'status' => 'active',
        ]);

        $teacher = Teacher::query()->create([
            'first_name' => 'Assigned',
            'last_name' => 'Teacher',
            'phone' => '0944000201',
            'status' => 'active',
        ]);

        $course = Course::query()->create([
            'is_active' => true,
            'name' => 'Export Course',
        ]);

        $group = Group::query()->create([
            'academic_year_id' => $academicYear->id,
            'capacity' => 20,
            'course_id' => $course->id,
            'is_active' => true,
            'name' => 'Export Group',
            'teacher_id' => $teacher->id,
        ]);

        $enrollment = Enrollment::query()->create([
            'enrolled_at' => '2026-10-01',
            'group_id' => $group->id,
            'status' => 'active',
            'student_id' => $student->id,
        ]);

        $attendanceStatus = AttendanceStatus::query()
            ->where('is_active', true)
            ->whereIn('scope', ['student', 'both'])
            ->firstOrFail();

        $attendanceDay = GroupAttendanceDay::query()->create([
            'attendance_date' => '2026-10-03',
            'created_by' => $manager->id,
            'group_id' => $group->id,
            'status' => 'completed',
        ]);

        StudentAttendanceRecord::query()->create([
            'attendance_status_id' => $attendanceStatus->id,
            'enrollment_id' => $enrollment->id,
            'group_attendance_day_id' => $attendanceDay->id,
            'notes' => 'Present and prepared.',
        ]);

        MemorizationSession::query()->create([
            'enrollment_id' => $enrollment->id,
            'entry_type' => 'new',
            'from_page' => 1,
            'notes' => 'Strong session.',
            'pages_count' => 3,
            'recorded_on' => '2026-10-04',
            'student_id' => $student->id,
            'teacher_id' => $teacher->id,
            'to_page' => 3,
        ]);

        $pointType = PointType::query()->create([
            'allow_manual_entry' => true,
            'allow_negative' => true,
            'category' => 'behavior',
            'code' => 'behavior-bonus-export',
            'default_points' => 4,
            'is_active' => true,
            'name' => 'Behavior Bonus',
        ]);

        PointTransaction::query()->create([
            'enrollment_id' => $enrollment->id,
            'entered_at' => now(),
            'entered_by' => $manager->id,
            'notes' => 'Export ledger entry.',
            'point_type_id' => $pointType->id,
            'points' => 4,
            'source_type' => 'manual',
            'student_id' => $student->id,
        ]);

        $assessmentType = AssessmentType::query()->where('is_active', true)->firstOrFail();
        $assessment = Assessment::query()->create([
            'assessment_type_id' => $assessmentType->id,
            'created_by' => $manager->id,
            'group_id' => $group->id,
            'is_active' => true,
            'pass_mark' => 50,
            'scheduled_at' => '2026-10-05 09:00:00',
            'title' => 'Midterm Quiz',
            'total_mark' => 100,
        ]);

        AssessmentResult::query()->create([
            'assessment_id' => $assessment->id,
            'attempt_no' => 1,
            'enrollment_id' => $enrollment->id,
            'score' => 88,
            'status' => 'passed',
            'student_id' => $student->id,
            'teacher_id' => $teacher->id,
        ]);

        $invoice = Invoice::query()->create([
            'discount' => 0,
            'due_date' => '2026-11-01',
            'invoice_no' => 'INV-PRINT-000001',
            'invoice_type' => 'tuition',
            'issue_date' => '2026-10-06',
            'parent_id' => $parent->id,
            'status' => 'issued',
            'subtotal' => 30,
            'total' => 30,
        ]);

        InvoiceItem::query()->create([
            'amount' => 30,
            'description' => 'October tuition',
            'enrollment_id' => $enrollment->id,
            'invoice_id' => $invoice->id,
            'quantity' => 1,
            'student_id' => $student->id,
            'unit_price' => 30,
        ]);

        $paymentMethod = PaymentMethod::query()->where('is_active', true)->firstOrFail();
        $paymentMethod->update(['name' => 'Cash', 'code' => 'cash']);

        $payment = Payment::query()->create([
            'amount' => 20,
            'invoice_id' => $invoice->id,
            'paid_at' => '2026-10-07',
            'payment_method_id' => $paymentMethod->id,
            'received_by' => $manager->id,
            'reference_no' => 'RCPT-001',
        ]);

        return [
            'academicYear' => $academicYear,
            'enrollment' => $enrollment,
            'group' => $group,
            'invoice' => $invoice,
            'manager' => $manager,
            'payment' => $payment,
        ];
    }
}
