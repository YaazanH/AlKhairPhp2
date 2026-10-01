<?php
namespace App\Http\Controllers\Platform;use App\Http\Controllers\Controller;use App\Models\Landlord\PlatformSupportCase;use Illuminate\View\View;
class PlatformSupportCaseController extends Controller {public function __invoke():View{return view('platform.support.index',['cases'=>PlatformSupportCase::query()->with('tenant')->latest('forwarded_at')->get()]);}}
