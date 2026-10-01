<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\Landlord\PlatformSubscriptionLedgerEntry;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Mpdf\Mpdf;

class SubscriptionReceiptController extends Controller
{
    public function __invoke(Request $request, PlatformSubscriptionLedgerEntry $entry): Response
    {
        abort_unless($entry->type === PlatformSubscriptionLedgerEntry::TYPE_OFFLINE_PAYMENT, 404);

        $entry->load(['tenant', 'recordedBy']);
        $html = view('platform.billing.receipt', ['entry' => $entry])->render();
        $pdf = new Mpdf([
            'format' => 'A5',
            'tempDir' => storage_path('app/mpdf'),
            'default_font' => 'dejavusans',
        ]);
        $pdf->WriteHTML($html);
        $disposition = $request->boolean('download') ? 'attachment' : 'inline';

        return response($pdf->Output('', 'S'), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => $disposition.'; filename="subscription-receipt-'.$entry->receipt_number.'.pdf"',
        ]);
    }
}
