<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\FinanceCashBox;
use App\Models\FinanceCurrency;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Landlord\Feature;
use App\Models\Landlord\Plan;
use App\Models\Landlord\Tenant;
use App\Models\PaymentMethod;
use App\Models\Student;
use App\Models\User;
use App\Services\InvoiceOwnershipService;
use App\Services\Landlord\TenantContext;
use App\Services\SidebarNavigationService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Livewire\Volt\Volt;
use Tests\TestCase;

class CommercialModulesTest extends TestCase
{
    use RefreshDatabase;

    private Plan $plan;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('database.connections.landlord', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]);
        DB::purge('landlord');
        Artisan::call('migrate', ['--database' => 'landlord', '--path' => database_path('migrations/landlord'), '--realpath' => true, '--force' => true]);
        $this->seed(RoleSeeder::class);
        $this->plan = Plan::create(['code' => 'commercial', 'name' => 'Commercial', 'is_active' => true]);
        $tenant = Tenant::create(['uuid' => (string) Str::uuid(), 'slug' => 'commercial', 'name' => 'Commercial', 'status' => 'active']);
        $tenant->subscription()->create(['plan_id' => $this->plan->id, 'status' => 'active']);
        app(TenantContext::class)->set($tenant);
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();
        DB::purge('landlord');
        parent::tearDown();
    }

    public function test_free_activity_registration_works_without_finance_but_financial_operations_do_not(): void
    {
        $this->modules(['students', 'activities']);
        $this->admin();
        $student = Student::create(['first_name' => 'Free', 'last_name' => 'Activity', 'birth_date' => '2015-01-01', 'status' => 'active']);
        $activity = Activity::create(['title' => 'Free trip', 'activity_date' => '2026-10-01', 'audience_scope' => 'all_groups', 'is_active' => true]);

        $this->postJson('/api/v1/activities/'.$activity->id.'/registrations', [
            'student_id' => $student->id,
            'fee_amount' => 0,
            'status' => 'registered',
        ])->assertCreated()->assertJsonPath('fee_amount', 0);

        $otherStudent = Student::create(['first_name' => 'Paid', 'last_name' => 'Blocked', 'birth_date' => '2015-01-01', 'status' => 'active']);
        $this->postJson('/api/v1/activities/'.$activity->id.'/registrations', [
            'student_id' => $otherStudent->id,
            'fee_amount' => 10,
            'status' => 'registered',
        ])->assertStatus(422);
        $this->postJson('/api/v1/activities/'.$activity->id.'/payments', [])->assertForbidden();
    }

    public function test_finance_and_student_billing_navigation_are_independent(): void
    {
        $this->modules(['finance']);
        $this->admin();

        $keys = collect(app(SidebarNavigationService::class)->sidebarFor(auth()->user()))
            ->flatMap(fn (array $group) => collect($group['items'])->pluck('key'))
            ->all();
        $this->assertContains('finance_dashboard', $keys);
        $this->assertNotContains('student_billing', $keys);
        $this->get('/student-billing')->assertForbidden();
        $this->get('/invoices')->assertOk();
    }

    public function test_student_invoice_is_owned_by_exactly_one_student_without_parents_module(): void
    {
        $this->modules(['students', 'finance', 'student_billing']);
        $this->admin();
        $student = Student::create(['first_name' => 'Single', 'last_name' => 'Owner', 'birth_date' => '2015-01-01', 'status' => 'active']);

        Volt::test('student-billing.index')
            ->call('create')
            ->set('student_id', $student->id)
            ->set('issue_date', '2026-09-23')
            ->set('description', 'Monthly tuition')
            ->set('quantity', '2')
            ->set('unit_price', '25')
            ->set('discount', '5')
            ->call('save')
            ->assertHasNoErrors();

        $invoice = Invoice::query()->where('student_id', $student->id)->firstOrFail();
        $this->assertNull($invoice->parent_id);
        $this->assertSame('student', $invoice->invoice_type);
        $this->assertSame('45.00', $invoice->total);
        $this->assertDatabaseHas('invoice_items', ['invoice_id' => $invoice->id, 'student_id' => $student->id, 'amount' => 50]);

        $response = $this->postJson('/api/v1/invoices', [
            'student_id' => $student->id,
            'issue_date' => '2026-10-01',
            'description' => 'Transport fee',
            'quantity' => 1,
            'unit_price' => 15,
        ])->assertCreated()
            ->assertJsonPath('student_id', $student->id)
            ->assertJsonPath('total', 15);
        $this->getJson('/api/v1/invoices')
            ->assertOk()
            ->assertJsonPath('data.0.student_id', $student->id);

        $currency = FinanceCurrency::query()->where('is_local', true)->first()
            ?? FinanceCurrency::create(['code' => 'TST', 'name' => 'Test', 'symbol' => 'T', 'rate_to_base' => 1, 'decimal_places' => 2, 'is_active' => true, 'show_in_dropdowns' => true, 'is_local' => true, 'is_base' => true]);
        $cashBox = FinanceCashBox::firstOrCreate(['code' => 'main'], ['name' => 'Main', 'is_active' => true]);
        $cashBox->currencies()->syncWithoutDetaching([$currency->id]);
        $paymentMethod = PaymentMethod::firstOrCreate(['code' => 'cash-test'], ['name' => 'Cash', 'is_active' => true]);
        $this->postJson('/api/v1/invoices/'.$response->json('id').'/payments', [
            'amount' => 15,
            'paid_at' => '2026-10-02',
            'payment_method_id' => $paymentMethod->id,
        ])->assertCreated()->assertJsonPath('amount', 15);
        $this->assertSame('paid', Invoice::query()->findOrFail($response->json('id'))->status);
        $this->assertDatabaseHas('finance_transactions', ['type' => 'income', 'signed_amount' => 15]);
    }

    public function test_legacy_classifier_only_applies_unambiguous_student_ownership(): void
    {
        $studentA = Student::create(['first_name' => 'One', 'last_name' => 'Student', 'birth_date' => '2015-01-01', 'status' => 'active']);
        $studentB = Student::create(['first_name' => 'Two', 'last_name' => 'Students', 'birth_date' => '2015-01-01', 'status' => 'active']);
        $safe = Invoice::create(['invoice_no' => 'LEG-1', 'invoice_type' => 'tuition', 'issue_date' => '2026-01-01', 'status' => 'draft']);
        InvoiceItem::create(['invoice_id' => $safe->id, 'line_no' => 1, 'student_id' => $studentA->id, 'description' => 'Fee', 'quantity' => 1, 'unit_price' => 10, 'amount' => 10]);
        $mixed = Invoice::create(['invoice_no' => 'LEG-2', 'invoice_type' => 'tuition', 'issue_date' => '2026-01-01', 'status' => 'draft']);
        foreach ([$studentA, $studentB] as $index => $student) {
            InvoiceItem::create(['invoice_id' => $mixed->id, 'line_no' => $index + 1, 'student_id' => $student->id, 'description' => 'Fee', 'quantity' => 1, 'unit_price' => 10, 'amount' => 10]);
        }

        $ownership = app(InvoiceOwnershipService::class);
        $this->assertSame(InvoiceOwnershipService::INFERABLE, $ownership->classify($safe)['classification']);
        $this->assertSame(InvoiceOwnershipService::MIXED, $ownership->classify($mixed)['classification']);

        $this->artisan('saas:classify-invoices', ['--apply-unambiguous' => true])->assertSuccessful();
        $this->assertSame($studentA->id, $safe->fresh()->student_id);
        $this->assertNull($mixed->fresh()->student_id);
    }

    private function modules(array $codes): void
    {
        $ids = collect($codes)->map(fn (string $code) => Feature::firstOrCreate(['code' => $code], ['name' => $code, 'is_active' => true])->id);
        $this->plan->features()->sync($ids);
    }

    private function admin(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('super_admin');
        $this->actingAs($user);
        Sanctum::actingAs($user, ['*'], 'web');

        return $user;
    }
}
