<?php

namespace App\Http\Controllers;

use App\Models\Landlord\PlatformReportLibraryItem;
use App\Services\ReportLibraryInstaller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ReportLibraryInstallController extends Controller
{
    public function index(Request $request, ReportLibraryInstaller $installer): View
    {
        $items = PlatformReportLibraryItem::query()
            ->whereNotNull('published_revision_id')
            ->with('publishedRevision')
            ->latest('updated_at')
            ->get()
            ->map(function (PlatformReportLibraryItem $item) use ($installer, $request): PlatformReportLibraryItem {
                $item->setAttribute('tenant_compatibility', $installer->compatibility($item, $request->user()));

                return $item;
            });

        return view('reports.library', ['items' => $items]);
    }

    public function store(Request $request, PlatformReportLibraryItem $libraryItem, ReportLibraryInstaller $installer): RedirectResponse
    {
        abort_unless($libraryItem->published_revision_id !== null, 404);
        $definition = $installer->install($libraryItem->load('publishedRevision'), $request->user());

        return redirect()->route('reports.library.index')->with(
            'status',
            __('report_library.messages.installed', ['name' => $definition->name]),
        );
    }
}
