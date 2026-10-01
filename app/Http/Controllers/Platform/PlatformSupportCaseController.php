<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\Landlord\PlatformAuditEvent;
use App\Models\Landlord\PlatformSuggestionGroup;
use App\Models\Landlord\PlatformSupportCase;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class PlatformSupportCaseController extends Controller
{
    public function index(Request $request): View
    {
        $types = $this->allowedTypes($request);
        abort_if($types === [], 403);

        $query = PlatformSupportCase::query()->with(['tenant', 'suggestionGroup'])->whereIn('type', $types);

        return view('platform.support.index', [
            'cases' => $query->latest('forwarded_at')->get(),
            'attentionCount' => (clone $query)->whereIn('status', PlatformSupportCase::attentionStatuses())->count(),
            'statusOptions' => [
                'problem' => PlatformSupportCase::statusesForType('problem'),
                'suggestion' => PlatformSupportCase::statusesForType('suggestion'),
            ],
            'suggestionGroups' => in_array('suggestion', $types, true) ? PlatformSuggestionGroup::query()->latest()->get() : collect(),
            'canManageSuggestions' => in_array('suggestion', $types, true),
        ]);
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

        return back()->with('status', 'Platform case updated.');
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

        return back()->with('status', 'Suggestion group created.');
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
