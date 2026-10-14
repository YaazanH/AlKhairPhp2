<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\Landlord\Tenant;
use App\Services\Landlord\TenantStorageUsage;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\View\View;
use Throwable;

class StorageUsageController extends Controller
{
    public function __invoke(Request $request, TenantStorageUsage $usage): View
    {
        $search = trim((string) $request->query('search'));
        $tenants = Tenant::query()->whereNotNull('database_name')->where('database_name', '!=', '')
            ->when($search !== '', fn ($query) => $query->where(fn ($match) => $match->where('name', 'like', '%'.$search.'%')->orWhere('slug', 'like', '%'.$search.'%')))
            ->orderBy('name')->get();
        $rows = $tenants->map(function (Tenant $tenant) use ($usage): array {
            try {
                return ['tenant' => $tenant, 'usage' => $usage->for($tenant)];
            } catch (Throwable) {
                // A failed scan must not be shown as zero usage or stop other tenants.
                return ['tenant' => $tenant, 'usage' => null];
            }
        })->sortByDesc(fn (array $row) => $row['usage']['total'] ?? -1)->values();
        $unavailable = $rows->whereNull('usage')->count();
        $total = $rows->sum(fn (array $row) => $row['usage']['total'] ?? 0);
        $overLimit = $rows->filter(fn (array $row) => $row['usage'] !== null && $row['tenant']->storage_limit_bytes !== null && $row['usage']['total'] >= $row['tenant']->storage_limit_bytes)->count();
        if ($request->query('attention') === 'limit') {
            $rows = $rows->filter(fn (array $row) => $row['usage'] !== null && $row['tenant']->storage_limit_bytes !== null && $row['usage']['total'] >= $row['tenant']->storage_limit_bytes)->values();
        } elseif ($request->query('attention') === 'unavailable') {
            $rows = $rows->whereNull('usage')->values();
        }
        $page = max(1, LengthAwarePaginator::resolveCurrentPage());
        $paginated = new LengthAwarePaginator($rows->forPage($page, 20)->values(), $rows->count(), 20, $page, ['path' => $request->url(), 'query' => $request->query()]);

        return view('platform.storage.index', ['rows' => $paginated, 'total' => $total, 'unavailable' => $unavailable, 'overLimit' => $overLimit, 'scanned' => $tenants->count() - $unavailable]);
    }
}
