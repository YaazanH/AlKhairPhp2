<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\Landlord\PlatformAuditEvent;
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

        $query = PlatformSupportCase::query()->with('tenant')->whereIn('type', $types);

        return view('platform.support.index', [
            'cases' => $query->latest('forwarded_at')->get(),
            'attentionCount' => (clone $query)->whereIn('status', PlatformSupportCase::attentionStatuses())->count(),
            'statusOptions' => [
                'problem' => PlatformSupportCase::statusesForType('problem'),
                'suggestion' => PlatformSupportCase::statusesForType('suggestion'),
            ],
        ]);
    }

    public function update(Request $request, PlatformSupportCase $case): RedirectResponse
    {
        abort_unless(in_array($case->type, $this->allowedTypes($request), true), 403);

        $data = $request->validate([
            'status' => ['required', Rule::in(PlatformSupportCase::statusesForType($case->type))],
            'platform_note' => ['nullable', 'string', 'max:5000'],
        ]);

        $before = $case->status;
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
            ],
            'ip_address' => $request->ip(),
        ]);

        return back()->with('status', 'Platform case updated.');
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
