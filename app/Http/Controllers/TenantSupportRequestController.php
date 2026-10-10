<?php

namespace App\Http\Controllers;

use App\Models\Landlord\PlatformSupportCase;
use App\Models\TenantSupportRequest;
use App\Services\Landlord\SupportCaseForwarder;
use App\Services\Landlord\SupportRequestConfiguration;
use App\Services\Landlord\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class TenantSupportRequestController extends Controller
{
    public function index(Request $request, SupportRequestConfiguration $configuration): View
    {
        $user = $request->user();

        return view('support.index', [
            'requests' => TenantSupportRequest::query()->where('submitted_by_user_id', $user->id)->with(['messages.sender', 'attachments'])->latest()->get(),
            'canSubmitProblem' => $user->can('support.problems.submit'),
            'canSubmitSuggestion' => $user->can('support.suggestions.submit'),
            'canManage' => $user->can('support.manage'),
            'problemReasonOptions' => $configuration->options(SupportRequestConfiguration::REASONS),
            'priorityOptions' => $configuration->options(SupportRequestConfiguration::PRIORITIES),
            'impactOptions' => $configuration->options(SupportRequestConfiguration::IMPACTS),
            'allProblemReasonOptions' => $configuration->options(SupportRequestConfiguration::REASONS, false),
            'allPriorityOptions' => $configuration->options(SupportRequestConfiguration::PRIORITIES, false),
            'allImpactOptions' => $configuration->options(SupportRequestConfiguration::IMPACTS, false),
        ]);
    }

    public function store(Request $request, SupportRequestConfiguration $configuration): RedirectResponse
    {
        $reasonOptions = $configuration->options(SupportRequestConfiguration::REASONS);
        $priorityOptions = $configuration->options(SupportRequestConfiguration::PRIORITIES);
        $impactOptions = $configuration->options(SupportRequestConfiguration::IMPACTS);
        $data = $request->validate([
            'type' => ['required', Rule::in([TenantSupportRequest::TYPE_PROBLEM, TenantSupportRequest::TYPE_SUGGESTION])],
            'problem_reason' => ['nullable', Rule::in(array_keys($reasonOptions)), 'required_if:type,problem'],
            'subject' => ['required', 'string', 'max:180'],
            'message' => ['required', 'string', 'max:5000'],
            'expected_result' => ['nullable', 'string', 'max:5000', 'required_if:type,problem'],
            'impact' => ['nullable', Rule::in(array_keys($impactOptions)), 'required_if:type,problem'],
            'priority' => ['nullable', Rule::in(array_keys($priorityOptions)), 'required_if:type,problem'],
            'desired_outcome' => ['nullable', 'string', 'max:5000', 'required_if:type,suggestion'],
            'current_workaround' => ['nullable', 'string', 'max:5000'],
            'affected_users' => ['nullable', 'string', 'max:500', 'required_if:type,suggestion'],
            'business_impact' => ['nullable', Rule::in(array_keys(TenantSupportRequest::businessImpactOptions())), 'required_if:type,suggestion'],
        ]);

        $permission = $data['type'] === TenantSupportRequest::TYPE_PROBLEM
            ? 'support.problems.submit'
            : 'support.suggestions.submit';
        abort_unless($request->user()->can($permission), 403);

        if ($data['type'] === TenantSupportRequest::TYPE_PROBLEM) {
            $data += [
                'incident_reference' => TenantSupportRequest::newIncidentReference(),
                'reported_url' => mb_substr($request->url(), 0, 2048),
                'browser_info' => mb_substr((string) $request->userAgent(), 0, 1000),
                'app_version' => mb_substr((string) config('app.version'), 0, 100),
            ];
            $data['desired_outcome'] = null;
            $data['current_workaround'] = null;
            $data['affected_users'] = null;
            $data['business_impact'] = null;
        } else {
            $data['problem_reason'] = null;
            $data['priority'] = null;
            $data['expected_result'] = null;
            $data['impact'] = null;
        }

        TenantSupportRequest::query()->create($data + ['submitted_by_user_id' => $request->user()->id]);

        return back()->with('status', __('support.messages.submitted'));
    }

    public function manage(SupportRequestConfiguration $configuration): View
    {
        $tenant = app(TenantContext::class)->tenant();
        $requests = TenantSupportRequest::query()->with(['submittedBy', 'messages.sender', 'attachments'])->latest()->get();
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
            'problemReasonOptions' => $configuration->options(SupportRequestConfiguration::REASONS),
            'priorityOptions' => $configuration->options(SupportRequestConfiguration::PRIORITIES),
            'allProblemReasonOptions' => $configuration->options(SupportRequestConfiguration::REASONS, false),
            'allPriorityOptions' => $configuration->options(SupportRequestConfiguration::PRIORITIES, false),
            'allImpactOptions' => $configuration->options(SupportRequestConfiguration::IMPACTS, false),
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

        return back()->with('status', __('support.messages.message_added'));
    }

    public function update(Request $request, TenantSupportRequest $supportRequest, SupportCaseForwarder $forwarder, SupportRequestConfiguration $configuration): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(TenantSupportRequest::statusesForType($supportRequest->type))],
            'problem_reason' => ['nullable', Rule::in(array_keys($configuration->options(SupportRequestConfiguration::REASONS)))],
            'priority' => ['nullable', Rule::in(array_keys($configuration->options(SupportRequestConfiguration::PRIORITIES)))],
            'tenant_admin_note' => ['nullable', 'string', 'max:5000'],
            'decline_reason' => ['nullable', 'string', 'max:5000'],
            'forward' => ['nullable', 'boolean'],
        ]);

        $supportRequest->fill([
            'status' => $data['status'],
            'tenant_admin_note' => $data['tenant_admin_note'] ?? null,
        ]);

        if ($supportRequest->type === TenantSupportRequest::TYPE_PROBLEM) {
            $supportRequest->problem_reason = $data['problem_reason'] ?? $supportRequest->problem_reason;
            $supportRequest->priority = $data['priority'] ?? $supportRequest->priority;
        }

        if ($supportRequest->type === TenantSupportRequest::TYPE_SUGGESTION) {
            $supportRequest->decline_reason = $data['status'] === TenantSupportRequest::STATUS_DECLINED
                ? ($data['decline_reason'] ?? null)
                : null;
        }

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

        return back()->with('status', __('support.messages.updated'));
    }
}
