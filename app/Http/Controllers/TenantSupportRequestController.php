<?php

namespace App\Http\Controllers;

use App\Models\Landlord\PlatformSupportCase;
use App\Models\TenantSupportRequest;
use App\Services\Landlord\SupportCaseForwarder;
use App\Services\Landlord\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class TenantSupportRequestController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();

        return view('support.index', [
            'requests' => TenantSupportRequest::query()->where('submitted_by_user_id', $user->id)->with('messages.sender')->latest()->get(),
            'canSubmitProblem' => $user->can('support.problems.submit'),
            'canSubmitSuggestion' => $user->can('support.suggestions.submit'),
            'canManage' => $user->can('support.manage'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::in([TenantSupportRequest::TYPE_PROBLEM, TenantSupportRequest::TYPE_SUGGESTION])],
            'subject' => ['required', 'string', 'max:180'],
            'message' => ['required', 'string', 'max:5000'],
            'priority' => ['nullable', Rule::in(['normal', 'high', 'critical'])],
            'reported_url' => ['nullable', 'string', 'max:2048'],
        ]);

        $permission = $data['type'] === TenantSupportRequest::TYPE_PROBLEM
            ? 'support.problems.submit'
            : 'support.suggestions.submit';
        abort_unless($request->user()->can($permission), 403);

        if ($data['type'] !== TenantSupportRequest::TYPE_PROBLEM) {
            $data['priority'] = null;
        }

        $data['browser_info'] = mb_substr((string) $request->userAgent(), 0, 1000);
        TenantSupportRequest::query()->create($data + ['submitted_by_user_id' => $request->user()->id]);

        return back()->with('status', 'Your request was submitted.');
    }

    public function manage(): View
    {
        $tenant = app(TenantContext::class)->tenant();
        $requests = TenantSupportRequest::query()->with(['submittedBy', 'messages.sender'])->latest()->get();
        $cases = PlatformSupportCase::query()
            ->where('tenant_id', $tenant->id)
            ->get()
            ->keyBy('tenant_support_request_id');

        return view('support.manage', [
            'requests' => $requests,
            'platformCases' => $cases,
            'statusOptions' => [
                TenantSupportRequest::TYPE_PROBLEM => TenantSupportRequest::statusesForType(TenantSupportRequest::TYPE_PROBLEM),
                TenantSupportRequest::TYPE_SUGGESTION => TenantSupportRequest::statusesForType(TenantSupportRequest::TYPE_SUGGESTION),
            ],
            'openCount' => TenantSupportRequest::query()->whereIn('status', TenantSupportRequest::attentionStatuses())->count(),
        ]);
    }

    public function storeMessage(Request $request, TenantSupportRequest $supportRequest): RedirectResponse
    {
        $data = $request->validate(['message' => ['required', 'string', 'max:5000']]);
        $user = $request->user();
        $isTenantAdministrator = $user->can('support.manage');

        abort_unless(
            $isTenantAdministrator || ($supportRequest->submitted_by_user_id === $user->id && ! $supportRequest->isClosed()),
            403,
        );

        $supportRequest->messages()->create([
            'sender_user_id' => $user->id,
            'is_tenant_administrator' => $isTenantAdministrator,
            'message' => $data['message'],
        ]);

        return back()->with('status', 'Message added.');
    }

    public function update(Request $request, TenantSupportRequest $supportRequest, SupportCaseForwarder $forwarder): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(TenantSupportRequest::statusesForType($supportRequest->type))],
            'tenant_admin_note' => ['nullable', 'string', 'max:5000'],
            'forward' => ['nullable', 'boolean'],
        ]);

        $supportRequest->fill([
            'status' => $data['status'],
            'tenant_admin_note' => $data['tenant_admin_note'] ?? null,
        ]);

        if ($request->boolean('forward')) {
            $supportRequest->fill([
                'status' => TenantSupportRequest::STATUS_FORWARDED,
                'forwarded_by_user_id' => $request->user()->id,
                'forwarded_at' => now(),
            ]);
        }

        $supportRequest->save();

        if ($request->boolean('forward')) {
            $forwarder->forward(app(TenantContext::class)->tenant(), $supportRequest);
        }

        return back()->with('status', 'Request updated.');
    }
}
