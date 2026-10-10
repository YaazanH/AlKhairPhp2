<?php

namespace App\Services\Landlord;

use App\Models\Landlord\PlatformSupportCase;
use App\Models\Landlord\Tenant;
use App\Models\TenantSupportRequest;

class SupportCaseForwarder
{
    public function forward(Tenant $tenant, TenantSupportRequest $request): PlatformSupportCase
    {
        $case = PlatformSupportCase::query()->firstOrNew([
            'tenant_id' => $tenant->id,
            'tenant_support_request_id' => $request->id,
        ]);

        $case->fill([
            'type' => $request->type,
            'problem_reason' => $request->problem_reason,
            'incident_reference' => $request->incident_reference,
            'subject' => $request->subject,
            'message' => $request->message,
            'forwarded_at' => now(),
        ]);

        if (! $case->exists) {
            $case->status = PlatformSupportCase::STATUS_FORWARDED;
        }

        $case->save();

        return $case;
    }
}
