<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\Landlord\PlatformAdministrator;
use App\Models\Landlord\PlatformPermission;
use App\Models\Landlord\PlatformRole;
use App\Services\Landlord\PlatformAccessManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use LogicException;

class PlatformAccessController extends Controller
{
    public function __construct(private readonly PlatformAccessManager $access) {}

    public function index(): View
    {
        return view('platform.access.index', [
            'roles' => PlatformRole::query()->with('permissions')->withCount('administrators')->orderByDesc('is_owner')->orderBy('name')->get(),
            'administrators' => PlatformAdministrator::query()->with('roles')->orderBy('name')->get(),
            'permissions' => PlatformPermission::query()->orderBy('name')->get(),
        ]);
    }

    public function storeRole(Request $request): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:100', 'unique:landlord.platform_roles,name'], 'description' => ['nullable', 'string', 'max:1000'], 'permissions' => ['array'], 'permissions.*' => ['integer', 'exists:landlord.platform_permissions,id']]);
        $this->access->createRole($data, $data['permissions'] ?? [], $request->user('platform'), $request->ip());

        return back()->with('status', 'Platform role created.');
    }

    public function updateRole(Request $request, PlatformRole $role): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:100', Rule::unique('landlord.platform_roles', 'name')->ignore($role->id)], 'description' => ['nullable', 'string', 'max:1000'], 'permissions' => ['array'], 'permissions.*' => ['integer', 'exists:landlord.platform_permissions,id']]);
        try { $this->access->updateRole($role, $data, $data['permissions'] ?? [], $request->user('platform'), $request->ip()); } catch (LogicException $exception) { return back()->withErrors(['role' => $exception->getMessage()]); }

        return back()->with('status', 'Platform role saved.');
    }

    public function destroyRole(Request $request, PlatformRole $role): RedirectResponse
    {
        try { $this->access->deleteRole($role, $request->user('platform'), $request->ip()); } catch (LogicException $exception) { return back()->withErrors(['role' => $exception->getMessage()]); }

        return back()->with('status', 'Platform role deleted.');
    }

    public function storeAdministrator(Request $request): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:255'], 'email' => ['required', 'email', 'max:255', 'unique:landlord.platform_administrators,email'], 'password' => ['required', 'string', 'min:12', 'confirmed'], 'roles' => ['array'], 'roles.*' => ['integer', 'exists:landlord.platform_roles,id']]);
        $this->access->createAdministrator($data, $data['roles'] ?? [], $request->user('platform'), $request->ip());

        return back()->with('status', 'Platform user created. Share the temporary password securely; it stays valid until the user changes it.');
    }

    public function updateAdministrator(Request $request, PlatformAdministrator $administrator): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:255'], 'email' => ['required', 'email', 'max:255', Rule::unique('landlord.platform_administrators', 'email')->ignore($administrator->id)]]);
        $this->access->updateAdministrator($administrator, $data, $request->user('platform'), $request->ip());

        return back()->with('status', 'Platform user details saved.');
    }

    public function syncAdministratorRoles(Request $request, PlatformAdministrator $administrator): RedirectResponse
    {
        $data = $request->validate(['roles' => ['array'], 'roles.*' => ['integer', 'exists:landlord.platform_roles,id']]);
        try { $this->access->syncAdministratorRoles($administrator, $data['roles'] ?? [], $request->user('platform'), $request->ip()); } catch (LogicException $exception) { return back()->withErrors(['roles' => $exception->getMessage()]); }

        return back()->with('status', 'Platform user roles saved.');
    }

    public function setAdministratorStatus(Request $request, PlatformAdministrator $administrator): RedirectResponse
    {
        $data = $request->validate(['is_active' => ['required', 'boolean']]);
        try { $this->access->setAdministratorActive($administrator, (bool) $data['is_active'], $request->user('platform'), $request->ip()); } catch (LogicException $exception) { return back()->withErrors(['status' => $exception->getMessage()]); }

        return back()->with('status', 'Platform user status saved.');
    }

    public function resetAdministratorPassword(Request $request, PlatformAdministrator $administrator): RedirectResponse
    {
        $data = $request->validate(['password' => ['required', 'string', 'min:12', 'confirmed']]);
        $this->access->resetAdministratorPassword($administrator, $data['password'], $request->user('platform'), $request->ip());

        return back()->with('status', 'Temporary password set. Share it securely; it stays valid until the user changes it.');
    }
}
