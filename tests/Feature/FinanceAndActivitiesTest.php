<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Activity;
use App\Models\ActivityPayment;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\ExpenseCategory;
use App\Models\Invoice;
use App\Models\ParentProfile;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\Group;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class FinanceAndActivitiesTest extends TestCase
{
    use RefreshDatabase;

    public function test_activity_components_support_registrations_payments_and_expenses(): void
    {
        $this->signIn();

        [$parent, $student, $group, $enrollment] = $this->financeContext();
        $paymentMethod = PaymentMethod::query()->where('code', 'cash')->firstOrFail();
        $expenseCategory = ExpenseCategory::query()->where('code', 'transport')->firstOrFail();

        Volt::test('activities.index')
            ->set('title', 'Spring Trip')
            ->set('activity_date', '2026-10-10')
            ->set('group_id', $group->id)
            ->set('fee_amount', '30')
            ->set('is_active', true)
            ->call('save')
            ->assertHasNoErrors();

        $activity = Activity::query()->firstOrFail();

        Volt::test('activities.finance', ['activity' => $activity])
            ->set('registration_student_id', $student->id)
            ->set('registration_enrollment_id', $enrollment->id)
            ->set('registration_fee_amount', '30')
            ->set('registration_status', 'registered')
            ->call('saveRegistration')
            ->assertHasNoErrors();

        $registration = $activity->registrations()->firstOrFail();

        Volt::test('activities.finance', ['activity' => $activity])
            ->set('payment_registration_id', $registration->id)
            ->set('payment_method_id', $paymentMethod->id)
            ->set('payment_paid_at', '2026-10-11')
            ->set('payment_amount', '20')
            ->call('savePayment')
            ->assertHasNoErrors();

        Volt::test('activities.finance', ['activity' => $activity])
            ->set('expense_category_id', $expenseCategory->id)
            ->set('expense_amount', '8')
            ->set('expense_spent_on', '2026-10-12')
            ->set('expense_description', 'Bus rental')
            ->call('saveExpense')
            ->assertHasNoErrors();

        $activity->refresh();

        $this->assertSame('30.00', $activity->expected_revenue_cached);
        $this->assertSame('20.00', $activity->collected_revenue_cached);
        $this->assertSame('8.00', $activity->expense_total_cached);

        $payment = ActivityPayment::query()->firstOrFail();

        Volt::test('activities.finance', ['activity' => $activity])
            ->call('voidPayment', $payment->id);

        $this->assertNotNull($payment->fresh()->voided_at);
        $this->assertSame('0.00', $activity->fresh()->collected_revenue_cached);
    }

    public function test_invoice_components_support_items_and_payments(): void
    {
        $this->signIn();

        [$parent, $student, $group, $enrollment] = $this->financeContext();
        $paymentMethod = PaymentMethod::query()->where('code', 'cash')->firstOrFail();

        $activity = Activity::create([
            'title' => 'Invoice Activity',
            'activity_date' => '2026-11-01',
            'group_id' => $group->id,
            'fee_amount' => 30,
            'is_active' => true,
        ]);

        Volt::test('invoices.index')
            ->set('parent_id', $parent->id)
            ->set('invoice_type', 'activity')
            ->set('issue_date', '2026-11-02')
            ->set('due_date', '2026-11-15')
            ->set('status', 'issued')
            ->set('discount', '5')
            ->call('save')
            ->assertHasNoErrors();

        $invoice = Invoice::query()->firstOrFail();

        Volt::test('invoices.payments', ['invoice' => $invoice])
            ->set('item_description', 'Activity fee')
            ->set('item_student_id', $student->id)
            ->set('item_enrollment_id', $enrollment->id)
            ->set('item_activity_id', $activity->id)
            ->set('item_quantity', '1')
            ->set('item_unit_price', '30')
            ->call('saveItem')
            ->assertHasNoErrors();

        $invoice->refresh();

        $this->assertSame('30.00', $invoice->subtotal);
        $this->assertSame('25.00', $invoice->total);
        $this->assertSame('issued', $invoice->status);

        Volt::test('invoices.payments', ['invoice' => $invoice])
            ->set('payment_method_id', $paymentMethod->id)
            ->set('paid_at', '2026-11-03')
            ->set('payment_amount', '25')
            ->call('savePayment')
            ->assertHasNoErrors();

        $invoice->refresh();

        $this->assertSame('paid', $invoice->status);

        $payment = Payment::query()->firstOrFail();

        Volt::test('invoices.payments', ['invoice' => $invoice])
            ->call('voidPayment', $payment->id);

        $this->assertNotNull($payment->fresh()->voided_at);
        $this->assertSame('issued', $invoice->fresh()->status);
    }

    private function financeContext(): array
    {
        $parent = ParentProfile::create([
            'father_name' => 'Finance Parent',
            'father_phone' => '0944000900',
        ]);

        $student = Student::create([
            'parent_id' => $parent->id,
            'first_name' => 'Finance',
            'last_name' => 'Student',
            'birth_date' => '2014-05-12',
            'status' => 'active',
        ]);

        $teacher = Teacher::create([
            'first_name' => 'Finance',
            'last_name' => 'Teacher',
            'phone' => '0944000901',
            'status' => 'active',
        ]);

        $course = Course::create([
            'name' => 'Finance Course',
            'is_active' => true,
        ]);

        $yearId = AcademicYear::query()->where('is_current', true)->value('id');

        $group = Group::create([
            'course_id' => $course->id,
            'academic_year_id' => $yearId,
            'teacher_id' => $teacher->id,
            'name' => 'Finance Group',
            'capacity' => 20,
            'is_active' => true,
        ]);

        $enrollment = Enrollment::create([
            'student_id' => $student->id,
            'group_id' => $group->id,
            'enrolled_at' => '2026-09-01',
            'status' => 'active',
        ]);

        return [$parent, $student, $group, $enrollment];
    }

    private function signIn(): void
    {
        $this->seed();

        $user = User::factory()->create([
            'name' => 'Manager User',
            'username' => 'finance-manager',
            'phone' => '0991111222',
        ]);

        $user->assignRole('manager');

        $this->actingAs($user);
    }
}
