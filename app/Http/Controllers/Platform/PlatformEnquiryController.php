<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\Landlord\PlatformLandingEnquiry;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PlatformEnquiryController extends Controller
{
    public function index(Request $request): View
    {
        $search = trim((string) $request->query('search', ''));

        return view('platform.support.enquiries', [
            'enquiries' => PlatformLandingEnquiry::query()->when($search !== '', fn ($query) => $query->where(fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('organisation_name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%")))->latest()->orderByDesc('id')->paginate(20)->withQueryString(),
        ]);
    }

    public function show(PlatformLandingEnquiry $enquiry): View
    {
        return view('platform.support.enquiry', compact('enquiry'));
    }
}
