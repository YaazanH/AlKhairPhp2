<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\Landlord\PlatformAuditEvent;
use App\Models\Landlord\PlatformSuggestionGroup;
use App\Models\Landlord\PlatformSupportCase;
use App\Models\Landlord\Tenant;
use App\Services\Landlord\SupportRequestConfiguration;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class PlatformSupportCaseController extends Controller
{
    public function index(Request $request, SupportRequestConfiguration $configuration): View|RedirectResponse
    {
        $types = $this->allowedTypes($request);
        if ($types === [] && $request->user('platform')->hasPlatformPermission('manage.landing-page')) {
            return redirect()->route('platform.enquiries.index');
        }
        abort_if($types === [], 403);
        $type = in_array($request->query('type'), $types, true) ? $request->query('type') : $types[0];
        $statuses = PlatformSupportCase::statusesForType($type);
        $search = trim((string) $request->query('search', ''));
        $query = PlatformSupportCase::query()->with(['tenant', 'suggestionGroup'])->where('type', $type);
        $attentionCount = (clone $query)->whereIn('status', PlatformSupportCase::attentionStatuses())->count();
        $query->when($search !== '', fn ($q) => $q->where(fn ($nested) => $nested->where('subject', 'like', "%{$search}%")->orWhere('message', 'like', "%{$search}%")->orWhere('incident_reference', 'like', "%{$search}%")->orWhereHas('tenant', fn ($tenant) => $tenant->where('name', 'like', "%{$search}%"))));
        $query->when(in_array($request->query('status'), $statuses, true), fn ($q) => $q->where('status', $request->query('status')));
        $query->when($request->query('status') === 'attention', fn ($q) => $q->whereIn('status', PlatformSupportCase::attentionStatuses()));
        $query->when($request->filled('tenant'), fn ($q) => $q->where('tenant_id', $request->query('tenant')));
        if ($type === 'suggestion') {
            $query->when($request->filled('group'), fn ($q) => $q->where('platform_suggestion_group_id', $request->query('group')));
        }

        return view('platform.support.index', [
            ...compact('type', 'types', 'statuses', 'attentionCount'),
            'cases' => $query->latest('forwarded_at')->orderByDesc('id')->paginate(20)->withQueryString(),
            'tenants' => Tenant::query()->whereIn('id', PlatformSupportCase::query()->whereIn('type', $types)->select('tenant_id'))->orderBy('name')->get(['id', 'name']),
            'suggestionGroups' => in_array('suggestion', $types, true) ? PlatformSuggestionGroup::query()->latest()->get() : collect(),
            'canManageSuggestions' => in_array('suggestion', $types, true),
            'problemReasonOptions' => $configuration->options(SupportRequestConfiguration::REASONS, false),
        ]);
    }

    public function show(Request $request, PlatformSupportCase $case, SupportRequestConfiguration $configuration): View
    {
        abort_unless(in_array($case->type, $this->allowedTypes($request), true), 403);

        return view('platform.support.show', [
            'case' => $case->load(['tenant', 'suggestionGroup']),
            'statuses' => PlatformSupportCase::statusesForType($case->type),
            'suggestionGroups' => $case->type === 'suggestion' ? PlatformSuggestionGroup::query()->orderBy('title')->get() : collect(),
            'problemReasonOptions' => $configuration->options(SupportRequestConfiguration::REASONS, false),
            'history' => PlatformAuditEvent::query()->with('platformAdministrator')->where('event', 'platform_support_case_updated')->where('tenant_id', $case->tenant_id)->where('properties->case_id', $case->id)->latest()->limit(20)->get(),
        ]);
    }

    public function groups(Request $request): View
    {
        abort_unless($request->user('platform')->hasPlatformPermission('manage.support.suggestions'), 403);

        return view('platform.support.groups', ['groups' => PlatformSuggestionGroup::query()->withCount(['cases' => fn ($query) => $query->where('type', 'suggestion')])->latest()->paginate(12)->withQueryString()]);
    }

    public function update(Request $request, PlatformSupportCase $case): RedirectResponse
    {
        abort_unless(in_array($case->type, $this->allowedTypes($request), true), 403);

        $data = $request->validate([
            'status' => ['required', Rule::in(PlatformSupportCase::statusesForType($case->type))],
            'platform_note' => ['nullable', 'string', 'max:5000'],
            'platform_suggestion_group_id' => ['nullable', 'integer'],
        ]);

        if ($case->type === 'suggestion') {
            $data['platform_suggestion_group_id'] = filled($data['platform_suggestion_group_id'] ?? null)
                ? PlatformSuggestionGroup::query()->findOrFail($data['platform_suggestion_group_id'])->id
                : null;
        } else {
            unset($data['platform_suggestion_group_id']);
        }

        $before = $case->status;
        $beforeGroupId = $case->platform_suggestion_group_id;
        $case->fill($data);

        if (filled($data['platform_note'] ?? null)) {
            $case->platform_replied_at = now();
        }

        $case->save();

        PlatformAuditEvent::query()->create([
            'uuid' => (string) Str::uuid(),
            'platform_administrator_id' => $request->user('platform')->id,
            'tenant_id' => $case->tenant_id,
            'event' => 'platform_support_case_updated',
            'properties' => [
                'case_id' => $case->id,
                'before_status' => $before,
                'after_status' => $case->status,
                'replied' => filled($data['platform_note'] ?? null),
                'before_suggestion_group_id' => $beforeGroupId,
                'after_suggestion_group_id' => $case->platform_suggestion_group_id,
            ],
            'ip_address' => $request->ip(),
        ]);

        return back()->with('status', __('support.messages.platform_updated'));
    }

    public function storeSuggestionGroup(Request $request): RedirectResponse
    {
        abort_unless($request->user('platform')->hasPlatformPermission('manage.support.suggestions'), 403);
        $data = $request->validate([
            'title' => ['required', 'string', 'max:180'],
            'summary' => ['nullable', 'string', 'max:5000'],
        ]);
        $group = PlatformSuggestionGroup::query()->create($data);

        PlatformAuditEvent::query()->create([
            'uuid' => (string) Str::uuid(),
            'platform_administrator_id' => $request->user('platform')->id,
            'event' => 'platform_suggestion_group_created',
            'properties' => ['suggestion_group_id' => $group->id],
            'ip_address' => $request->ip(),
        ]);

        return back()->with('status', __('support.messages.group_created'));
    }

    private function allowedTypes(Request $request): array
    {
        $user = $request->user('platform');
        $types = [];

        if ($user->hasPlatformPermission('manage.support.problems')) {
            $types[] = 'problem';
        }

        if ($user->hasPlatformPermission('manage.support.suggestions')) {
            $types[] = 'suggestion';
        }

        return $types;
    }
}
