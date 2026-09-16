<?php

namespace Tests\Feature;

use App\Models\FinancePullRequestKind;
use App\Models\FinanceRequest;
use App\Models\FinanceTransaction;
use App\Models\Teacher;
use App\Models\User;
use App\Services\FinanceService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Volt\Volt;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class WithdrawalRefusalTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private User $teacherUser;

    private Teacher $teacher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->manager = User::factory()->create();
        $this->manager->assignRole('manager');
        $this->teacherUser = User::factory()->create();
        $this->teacherUser->assignRole('teacher');
        $this->teacher = Teacher::create(['user_id' => $this->teacherUser->id, 'first_name' => 'Withdrawal', 'last_name' => 'Teacher', 'phone' => '0944000991', 'status' => 'active']);
        $this->actingAs($this->manager);
    }

    public static function reviewScreens(): array
    {
        return [
            ['finance.dashboard', 'ar'], ['finance.dashboard', 'en'],
            ['finance.pull-requests', 'ar'], ['finance.pull-requests', 'en'],
        ];
    }

    #[DataProvider('reviewScreens')]
    public function test_refusal_replaces_review_requires_a_reason_and_returns_to_the_dashboard(string $screen, string $locale): void
    {
        app()->setLocale($locale);
        $request = $this->request();
        $transactions = FinanceTransaction::count();
        $amountProperty = $screen === 'finance.dashboard' ? 'review_amount' : "review_amounts.{$request->id}";
        $component = Volt::test($screen)
            ->call('openReviewModal', $request->id)
            ->set($amountProperty, '90')
            ->assertSee('data-withdrawal-review', false)
            ->assertDontSee('wire:model="review_notes', false)
            ->call('openRefusalModal')
            ->assertSet('refusingRequestId', $request->id)
            ->assertSee('data-withdrawal-refusal-form', false)
            ->assertDontSee('data-withdrawal-review', false);
        $this->assertSame(1, substr_count($component->html(), 'class="admin-modal '));

        foreach (['', '   ', "\n\t", "\u{00A0}\u{200B}"] as $blank) {
            $component->set('refusal_reason', $blank)
                ->call('saveRefusal')
                ->assertHasErrors(['refusal_reason' => 'required'])
                ->assertSee(__('finance.refusal.required'))
                ->assertSet('refusingRequestId', $request->id)
                ->assertNoRedirect();
            $this->assertSame(FinanceRequest::STATUS_PENDING, $request->fresh()->status);
            $this->assertNull($request->fresh()->review_notes);
        }

        $component->set('refusal_reason', str_repeat('a', 2001))->call('saveRefusal')->assertHasErrors(['refusal_reason' => 'max']);
        $component->call('closeRefusalModal')
            ->assertSet('refusingRequestId', null)
            ->assertSet('refusal_reason', '')
            ->assertSet($amountProperty, '90')
            ->assertSee('data-withdrawal-review', false)
            ->assertDontSee('data-withdrawal-refusal-form', false)
            ->assertHasNoErrors();

        $reason = $locale === 'ar' ? "المبلغ المطلوب غير مبرر.\nيرجى إرفاق التفاصيل." : "The amount needs justification.\nPlease attach the details.";
        $component->call('openRefusalModal')
            ->set('refusal_reason', '  '.$reason.'  ')
            ->call('saveRefusal')
            ->assertHasNoErrors()
            ->assertSet('reviewingRequestId', null)
            ->assertSet('refusingRequestId', null)
            ->assertRedirect(route('finance.dashboard'));

        $request->refresh();
        $this->assertSame(FinanceRequest::STATUS_DECLINED, $request->status);
        $this->assertSame($reason, $request->review_notes);
        $this->assertSame($this->manager->id, $request->reviewed_by);
        $this->assertNotNull($request->declined_at);
        $this->assertSame($transactions, FinanceTransaction::count());
        Volt::test('finance.dashboard')->assertViewHas('pendingRequests', fn ($requests) => ! $requests->contains('id', $request->id));
    }

    public function test_history_opens_the_reason_in_a_separate_popup_and_returns_to_history(): void
    {
        $request = $this->request(['status' => FinanceRequest::STATUS_DECLINED, 'review_notes' => 'Reason visible from history.']);
        $component = Volt::test('finance.dashboard')
            ->set('showRequestHistoryModal', true)
            ->assertSee('data-withdrawal-refused-status', false)
            ->call('openRefusalReason', $request->id)
            ->assertSee('data-withdrawal-refusal-details', false)
            ->assertSee($request->review_notes)
            ->assertDontSee('data-withdrawal-history-table', false);
        $this->assertSame(1, substr_count($component->html(), 'class="admin-modal '));
        $component->call('closeRefusalReason')
            ->assertSee('data-withdrawal-history-table', false)
            ->assertDontSee('data-withdrawal-refusal-details', false);
    }

    public function test_teacher_sees_the_reason_in_their_request_table_and_can_open_it(): void
    {
        // Requests made on a teacher's behalf must also be visible to that teacher.
        $request = $this->request(['requested_by' => $this->manager->id, 'status' => FinanceRequest::STATUS_DECLINED, 'review_notes' => 'Please include quantities.']);
        $this->actingAs($this->teacherUser);
        Volt::test('finance.pull-requests')
            ->assertSee('data-withdrawal-refusal-reason', false)
            ->assertSee($request->review_notes)
            ->call('openRefusalReason', $request->id)
            ->assertSee('data-withdrawal-refusal-details', false)
            ->assertSee($request->review_notes);
    }

    public function test_teacher_cannot_read_another_teachers_reason(): void
    {
        $request = $this->request(['requested_by' => $this->manager->id, 'teacher_id' => null, 'status' => FinanceRequest::STATUS_DECLINED, 'review_notes' => 'Private refusal reason.']);
        $this->actingAs($this->teacherUser);
        $component = Volt::test('finance.pull-requests')->assertDontSee($request->review_notes);
        $this->expectException(ModelNotFoundException::class);
        $component->call('openRefusalReason', $request->id);
    }

    public function test_teacher_cannot_refuse_requests(): void
    {
        $request = $this->request();
        $this->actingAs($this->teacherUser);
        Volt::test('finance.pull-requests')->call('openRefusalModal')->assertForbidden();
        Volt::test('finance.pull-requests')->set('refusal_reason', 'Forged refusal')->call('saveRefusal')->assertForbidden();
        $this->assertSame(FinanceRequest::STATUS_PENDING, $request->fresh()->status);
        $this->assertNull($request->fresh()->review_notes);
    }

    public function test_a_request_accepted_by_another_reviewer_cannot_be_refused_from_a_stale_popup(): void
    {
        $request = $this->request();
        $component = Volt::test('finance.dashboard')->call('openReviewModal', $request->id)->call('openRefusalModal');
        $request->update(['status' => FinanceRequest::STATUS_ACCEPTED]);
        $component->set('refusal_reason', 'This is a stale review.')->call('saveRefusal')
            ->assertHasErrors(['refusal_reason'])
            ->assertSee(__('finance.refusal.already_reviewed'))
            ->assertNoRedirect();
        $this->assertSame(FinanceRequest::STATUS_ACCEPTED, $request->fresh()->status);
        $this->assertNull($request->fresh()->declined_at);
        $this->assertNull($request->fresh()->review_notes);
    }

    public function test_service_rejects_blank_reasons_even_without_the_review_ui(): void
    {
        $request = $this->request();
        try {
            app(FinanceService::class)->declineRequest($request, $this->manager, " \t\n");
            $this->fail('A withdrawal refusal must have a reason.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('refusal_reason', $exception->errors());
        }
        $this->assertSame(FinanceRequest::STATUS_PENDING, $request->fresh()->status);
    }

    public function test_old_refusals_without_a_reason_show_a_localized_placeholder(): void
    {
        $request = $this->request(['status' => FinanceRequest::STATUS_DECLINED]);
        app()->setLocale('ar');
        Volt::test('finance.dashboard')->call('openRefusalReason', $request->id)->assertSee(__('finance.refusal.not_recorded'));
    }

    private function request(array $attributes = []): FinanceRequest
    {
        return FinanceRequest::create(array_replace([
            'request_no' => app(FinanceService::class)->nextRequestNumber(FinanceRequest::TYPE_PULL),
            'type' => FinanceRequest::TYPE_PULL,
            'status' => FinanceRequest::STATUS_PENDING,
            'requested_currency_id' => app(FinanceService::class)->localCurrency()->id,
            'finance_pull_request_kind_id' => FinancePullRequestKind::where('mode', FinancePullRequestKind::MODE_INVOICE)->firstOrFail()->id,
            'requested_amount' => 100,
            'teacher_id' => $this->teacher->id,
            'requested_by' => $this->teacherUser->id,
            'requested_reason' => 'Class materials',
        ], $attributes));
    }
}
