<?php

namespace App\Http\Controllers;

use App\Models\TenantSupportAttachment;
use App\Models\TenantSupportRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TenantSupportAttachmentController extends Controller
{
    private const MAX_FILE_KB = 10240;

    public function store(Request $request, TenantSupportRequest $supportRequest): RedirectResponse
    {
        abort_unless($supportRequest->type === TenantSupportRequest::TYPE_PROBLEM, 404);
        abort_unless($this->canUpload($request, $supportRequest), 403);

        $data = $request->validate([
            'attachment' => [
                'required',
                'file',
                'mimes:jpg,jpeg,png,webp,pdf,doc,docx,xls,xlsx,csv,txt',
                'max:'.self::MAX_FILE_KB,
            ],
        ]);
        $file = $data['attachment'];
        $path = $file->store('support/'.$supportRequest->id, 'local');

        $supportRequest->attachments()->create([
            'uploaded_by_user_id' => $request->user()->id,
            'path' => $path,
            'original_name' => $this->safeOriginalName($file->getClientOriginalName()),
            'mime_type' => (string) $file->getMimeType(),
            'size_bytes' => $file->getSize(),
        ]);

        return back()->with('status', __('support.messages.attachment_added'));
    }

    public function download(Request $request, TenantSupportAttachment $attachment): StreamedResponse
    {
        $supportRequest = $attachment->supportRequest;
        abort_unless($supportRequest && $this->canView($request, $supportRequest), 403);
        abort_unless(Storage::disk('local')->exists($attachment->path), 404);

        return Storage::disk('local')->download($attachment->path, $attachment->original_name, [
            'Cache-Control' => 'private, no-store, max-age=0',
            'Content-Type' => $attachment->mime_type,
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function canView(Request $request, TenantSupportRequest $supportRequest): bool
    {
        $user = $request->user();

        return $user->can('support.manage') || $supportRequest->submitted_by_user_id === $user->id;
    }

    private function canUpload(Request $request, TenantSupportRequest $supportRequest): bool
    {
        return $this->canView($request, $supportRequest)
            && ($request->user()->can('support.manage') || ! $supportRequest->isClosed());
    }

    private function safeOriginalName(string $name): string
    {
        $name = basename($name);
        $name = (string) Str::of($name)->replaceMatches('/[^\\pL\\pN._ -]/u', '_')->trim();

        return Str::limit($name, 180, '') ?: 'attachment';
    }
}
