<?php
namespace App\Http\Controllers\Platform;
use App\Http\Controllers\Controller;use App\Models\Landlord\PlatformSubscriptionLedgerEntry;use Mpdf\Mpdf;use Illuminate\Http\Response;
class SubscriptionReceiptController extends Controller { public function __invoke(PlatformSubscriptionLedgerEntry $entry): Response { abort_unless($entry->type===PlatformSubscriptionLedgerEntry::TYPE_OFFLINE_PAYMENT,404);$entry->load(['tenant','recordedBy']);$html=view('platform.billing.receipt',['entry'=>$entry])->render();$pdf=new Mpdf(['format'=>'A5','tempDir'=>storage_path('app/mpdf')]);$pdf->WriteHTML($html);return response($pdf->Output('', 'S'),200,['Content-Type'=>'application/pdf','Content-Disposition'=>'inline; filename="subscription-receipt-'.$entry->id.'.pdf"']);}}
