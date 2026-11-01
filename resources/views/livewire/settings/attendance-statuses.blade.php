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
        $access = app(CurrentModuleAccess::class);
        abort_unless($access->enabled('student_attendance') || $access->enabled('teacher_attendance'), 403);
    }
}; ?>

<div><livewire:settings.tracking section="attendance" /></div>
