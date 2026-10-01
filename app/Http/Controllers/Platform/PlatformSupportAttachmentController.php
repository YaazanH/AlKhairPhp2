<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\Landlord\PlatformAuditEvent;
use App\Models\Landlord\PlatformSupportCase;
use App\Models\TenantSupportAttachment;
use App\Services\Landlord\TenantScopedContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;

class PlatformSupportAttachmentController extends Controller
{
    public function index(Request $request, PlatformSupportCase $case, TenantScopedContext $tenantContext): View
    {
        $this->authorizeProblemAccess($request, $case);
        $case->load('tenant');
        $attachments = $tenantContext->run($case->tenant, fn () => TenantSupportAttachment::query()
            ->where('tenant_support_request_id', $case->tenant_support_request_id)
            ->oldest()
            ->get(['id', 'original_name', 'mime_type', 'size_bytes', 'created_at']));

        return view('platform.support.attachments', compact('case', 'attachments'));
    }

    public function download(Request $request, PlatformSupportCase $case, int $attachmentId, TenantScopedContext $tenantContext): Response
    {
        $this->authorizeProblemAccess($request, $case);
        $case->load('tenant');
        $attachment = $tenantContext->run($case->tenant, function () use ($case, $attachmentId): array {
            $attachment = TenantSupportAttachment::query()
                ->where('tenant_support_request_id', $case->tenant_support_request_id)
                ->findOrFail($attachmentId);
            abort_unless(Storage::disk('local')->exists($attachment->path), 404);

            return [
                'contents' => Storage::disk('local')->get($attachment->path),
                'name' => $attachment->original_name,
                'mime_type' => $attachment->mime_type,
            ];
        });

        PlatformAuditEvent::query()->create([
            'uuid' => (string) Str::uuid(),
            'platform_administrator_id' => $request->user('platform')->id,
            'tenant_id' => $case->tenant_id,
            'event' => 'platform_support_attachment_downloaded',
            'properties' => ['case_id' => $case->id, 'attachment_id' => $attachmentId],
            'ip_address' => $request->ip(),
        ]);

        return response($attachment['contents'], 200, [
            'Cache-Control' => 'private, no-store, max-age=0',
            'Content-Type' => $attachment['mime_type'],
            'Content-Disposition' => HeaderUtils::makeDisposition(
                HeaderUtils::DISPOSITION_ATTACHMENT,
                $attachment['name'],
                Str::ascii($attachment['name']) ?: 'attachment',
            ),
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function authorizeProblemAccess(Request $request, PlatformSupportCase $case): void
    {
        abort_unless(
            $case->type === 'problem' && $request->user('platform')->hasPlatformPermission('manage.support.problems'),
            403,
        );
    }
}
