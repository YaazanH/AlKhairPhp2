<?php

namespace App\Livewire\Concerns;

use App\Models\User;
use App\Services\AccessScopeService;
use App\Services\ManagedUserService;
use App\Support\RoleRegistry;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

trait LinksExistingProfileAccounts
{
    public ?int $existingAccountId = null;

    public function availableExistingAccounts(string $profile): Collection
    {
        if ($this->editingId || ! auth()->user()?->can('users.update')) {
            return collect();
        }

        return User::query()->whereDoesntHave($profile.'Profile', fn ($query) => $query->withTrashed())
            ->orderBy('name')->get(['id', 'name', 'username']);
    }

    protected function selectedExistingAccount(string $profile): ?User
    {
        if (! $this->existingAccountId) {
            return null;
        }

        abort_if($this->editingId || ! auth()->user()?->can('users.update'), 403);
        $user = User::query()->whereDoesntHave($profile.'Profile', fn ($query) => $query->withTrashed())
            ->lockForUpdate()->find($this->existingAccountId);
        if (! $user) {
            throw ValidationException::withMessages(['existingAccountId' => __('access.profile_accounts.existing_unavailable')]);
        }

        return $user;
    }

    public function updatedExistingAccountId(): void
    {
        $this->resetValidation('existingAccountId');
        if (! $this->existingAccountId) {
            if (property_exists($this, 'access_roles') && ! $this->editingId) {
                $this->reset(['account_username', 'account_password', 'account_is_active', 'access_roles', 'direct_permissions', 'scope_student_progress_all', 'scope_groups', 'scope_parents', 'scope_students', 'scope_teachers']);
            }

            return;
        }

        abort_if($this->editingId, 403);
        abort_unless(auth()->user()?->can('users.update'), 403);
        $user = User::query()->with(['roles', 'permissions', 'scopeOverrides'])->findOrFail($this->existingAccountId);
        if (property_exists($this, 'access_roles')) {
            $this->account_username = $user->username ?? '';
            $this->account_password = '';
            $this->account_is_active = $user->is_active;
            $this->access_roles = $user->getRoleNames()->reject(fn ($role) => in_array($role, RoleRegistry::actorRoles(), true))->values()->all();
            $this->direct_permissions = $user->getDirectPermissions()->pluck('name')->all();
            $this->scope_student_progress_all = app(AccessScopeService::class)->canViewAllStudentProgress($user);
            foreach (['groups' => 'group', 'parents' => 'parent', 'students' => 'student', 'teachers' => 'teacher'] as $field => $type) {
                $this->{'scope_'.$field} = $user->scopeOverrides->where('scope_type', $type)->pluck('scope_id')->map(fn ($id) => (int) $id)->all();
            }
        }
    }

    public function canManageProfileLogin(?User $user, string $profile): bool
    {
        return ! $user || auth()->user()?->can('users.update')
            || ! app(ManagedUserService::class)->isSharedAccount($user, $profile);
    }
}
