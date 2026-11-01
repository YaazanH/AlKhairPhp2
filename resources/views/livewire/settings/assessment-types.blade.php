<?php

use App\Livewire\Concerns\AuthorizesPermissions;
use App\Services\Landlord\CurrentModuleAccess;
use Livewire\Volt\Component;

new class extends Component
{
    use AuthorizesPermissions;

    public function mount(): void
    {
        $this->authorizePermission('settings.manage');
        app(CurrentModuleAccess::class)->ensure('assessments');
    }
}; ?>

<div><livewire:settings.tracking section="assessments" /></div>
