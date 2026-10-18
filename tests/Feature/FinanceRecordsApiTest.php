<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Activity;
use App\Models\ActivityExpense;
use App\Models\ActivityPayment;
use App\Models\ActivityRegistration;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\ExpenseCategory;
use App\Models\Group;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\ParentProfile;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use App\Services\ActivityAudienceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FinanceRecordsApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();
    }

    public function test_manager_can_load_activity_and_invoice_finance_details(): void
    {
        $context = $this->createFinanceContext();
        $manager = User::factory()->create();
        $manager->assignRole('manager');
        Sanctum::actingAs($manager);

        $this->getJson('/api/v1/activities/'.$context['activity']->id)
            ->assertOk()
            ->assertJsonPath('data.registrations_count', 2)
            ->assertJsonPath('data.payments_count', 2);
        $this->getJson('/api/v1/activities/'.$context['activity']->id.'/registrations')
            ->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/activities/'.$context['activity']->id.'/payments')
            ->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/activities/'.$context['activity']->id.'/expenses')
            ->assertOk()->assertJsonPath('data.0.id', $context['expense']->id);

        $this->getJson('/api/v1/invoices/'.$context['invoice']->id)
            ->assertOk()
            ->assertJsonCount(2, 'data.items')
            ->assertJsonPath('data.paid_total', 25)
            ->assertJsonPath('data.balance', 75);
        $this->getJson('/api/v1/invoices/'.$context['invoice']->id.'/payments')
            ->assertOk()->assertJsonPath('data.0.id', $context['invoicePayment']->id);
        $this->getJson('/api/v1/payments')
            ->assertOk()->assertJsonPath('data.0.invoice_id', $context['invoice']->id);
    }

    public function test_scoped_teacher_sees_only_accessible_finance_rows_and_cannot_mutate_other_rows(): void
    {
        $context = $this->createFinanceContext();
        $teacherUser = User::factory()->create();
        $teacherUser->assignRole('teacher');
        $teacherUser->givePermissionTo([
            'activities.finance.view',
            'activities.payments.manage',
            'activities.registrations.manage',
            'activities.view',
            'invoices.update',
            'invoices.view',
            'payments.view',
        ]);
        $context['teacher']->user()->associate($teacherUser);
        $context['teacher']->save();
        Sanctum::actingAs($teacherUser);

        $this->getJson('/api/v1/activities/'.$context['activity']->id.'/registrations')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.student.id', $context['student']->id);
        $this->getJson('/api/v1/activities/'.$context['activity']->id.'/payments')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.student.id', $context['student']->id);

        $this->getJson('/api/v1/invoices/'.$context['invoice']->id)
            ->assertOk()->assertJsonCount(1, 'data.items')->assertJsonPath('data.items.0.student.id', $context['student']->id);

        $this->patchJson('/api/v1/activities/'.$context['activity']->id.'/registrations/'.$context['otherRegistration']->id, [
            'enrollment_id' => $context['otherEnrollment']->id,
            'fee_amount' => 50,
            'status' => 'registered',
            'student_id' => $context['otherStudent']->id,
        ])->assertForbidden();

        $this->postJson('/api/v1/invoices/'.$context['invoice']->id.'/items', [
            'description' => 'Forbidden item',
            'enrollment_id' => $context['otherEnrollment']->id,
            'quantity' => 1,
            'student_id' => $context['otherStudent']->id,
            'unit_price' => 10,
        ])->assertUnprocessable()
            ->assertJsonPath('message', 'This legacy invoice needs one student owner before it can be changed.');
        $this->assertDatabaseMissing('invoice_items', ['description' => 'Forbidden item']);
    }

    public function test_finance_reads_require_matching_permissions(): void
    {
        $context = $this->createFinanceContext();
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/v1/activities/'.$context['activity']->id)->assertForbidden();
        $this->getJson('/api/v1/activities/'.$context['activity']->id.'/payments')->assertForbidden();
        $this->getJson('/api/v1/invoices/'.$context['invoice']->id)->assertForbidden();
        $this->getJson('/api/v1/payments')->assertForbidden();
    }

    protected function createFinanceContext(): array
    {
        $year = AcademicYear::query()->where('is_current', true)->firstOrFail();
        $course = Course::query()->create(['academic_year_id' => $year->id, 'is_active' => true, 'name' => 'Finance API Course']);
        $parent = ParentProfile::query()->create(['father_name' => 'Finance Parent']);
        $teacher = Teacher::query()->create(['first_name' => 'Finance', 'last_name' => 'Teacher', 'phone' => '0993000000', 'status' => 'active']);
        $otherTeacher = Teacher::query()->create(['first_name' => 'Other', 'last_name' => 'Teacher', 'phone' => '0994000000', 'status' => 'active']);
        $group = Group::query()->create([
            'academic_year_id' => $year->id,
            'capacity' => 20,
            'course_id' => $course->id,
            'is_active' => true,
            'name' => 'Finance API Group',
            'teacher_id' => $teacher->id,
        ]);
        $otherGroup = Group::query()->create([
            'academic_year_id' => $year->id,
            'capacity' => 20,
            'course_id' => $course->id,
            'is_active' => true,
            'name' => 'Other Finance Group',
            'teacher_id' => $otherTeacher->id,
        ]);
        $student = Student::query()->create(['birth_date' => '2014-01-01', 'first_name' => 'Finance', 'last_name' => 'Student', 'parent_id' => $parent->id, 'status' => 'active']);
        $otherStudent = Student::query()->create(['birth_date' => '2014-02-01', 'first_name' => 'Other', 'last_name' => 'Student', 'parent_id' => $parent->id, 'status' => 'active']);
        $enrollment = Enrollment::query()->create(['enrolled_at' => '2026-09-01', 'group_id' => $group->id, 'status' => 'active', 'student_id' => $student->id]);
        $otherEnrollment = Enrollment::query()->create(['enrolled_at' => '2026-09-01', 'group_id' => $otherGroup->id, 'status' => 'active', 'student_id' => $otherStudent->id]);
        $activity = Activity::query()->create([
            'activity_date' => '2026-10-20',
            'audience_scope' => ActivityAudienceService::SCOPE_ALL_GROUPS,
            'collected_revenue_cached' => 30,
            'expense_total_cached' => 8,
            'expected_revenue_cached' => 100,
            'fee_amount' => 50,
            'is_active' => true,
            'status' => 'planned',
            'title' => 'Finance Mobile Activity',
        ]);
        $registration = ActivityRegistration::query()->create(['activity_id' => $activity->id, 'enrollment_id' => $enrollment->id, 'fee_amount' => 50, 'status' => 'registered', 'student_id' => $student->id]);
        $otherRegistration = ActivityRegistration::query()->create(['activity_id' => $activity->id, 'enrollment_id' => $otherEnrollment->id, 'fee_amount' => 50, 'status' => 'registered', 'student_id' => $otherStudent->id]);
        $paymentMethod = PaymentMethod::query()->where('is_active', true)->firstOrFail();
        ActivityPayment::query()->create(['activity_registration_id' => $registration->id, 'amount' => 15, 'paid_at' => '2026-10-20', 'payment_method_id' => $paymentMethod->id]);
        ActivityPayment::query()->create(['activity_registration_id' => $otherRegistration->id, 'amount' => 15, 'paid_at' => '2026-10-20', 'payment_method_id' => $paymentMethod->id]);
        $expenseCategory = ExpenseCategory::query()->where('is_active', true)->firstOrFail();
        $expense = ActivityExpense::query()->create(['activity_id' => $activity->id, 'amount' => 8, 'description' => 'Activity supplies', 'expense_category_id' => $expenseCategory->id, 'spent_on' => '2026-10-19']);

        $invoice = Invoice::query()->create([
            'discount' => 0,
            'due_date' => '2026-11-01',
            'invoice_no' => 'INV-MOBILE-FIN-1',
            'invoice_type' => 'tuition',
            'issue_date' => '2026-10-20',
            'parent_id' => $parent->id,
            'status' => 'partial',
            'subtotal' => 100,
            'total' => 100,
        ]);
        InvoiceItem::query()->create(['amount' => 50, 'description' => 'Own tuition', 'enrollment_id' => $enrollment->id, 'invoice_id' => $invoice->id, 'quantity' => 1, 'student_id' => $student->id, 'unit_price' => 50]);
        InvoiceItem::query()->create(['amount' => 50, 'description' => 'Other tuition', 'enrollment_id' => $otherEnrollment->id, 'invoice_id' => $invoice->id, 'quantity' => 1, 'student_id' => $otherStudent->id, 'unit_price' => 50]);
        $invoicePayment = Payment::query()->create(['amount' => 25, 'invoice_id' => $invoice->id, 'paid_at' => '2026-10-21', 'payment_method_id' => $paymentMethod->id]);

        return compact('activity', 'enrollment', 'expense', 'group', 'invoice', 'invoicePayment', 'otherEnrollment', 'otherGroup', 'otherRegistration', 'otherStudent', 'otherTeacher', 'parent', 'registration', 'student', 'teacher');
    }
}
