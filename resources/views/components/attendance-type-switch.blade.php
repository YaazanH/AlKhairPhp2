@props(['selected', 'action' => 'switchTab'])
<div class="quick-saber-type-switch attendance-navigation" role="group" aria-label="{{ __('attendance.title') }}">
    @foreach (['students' => 'student', 'teachers' => 'teacher'] as $type => $permission)
        @can('attendance.'.$permission.'.view')
            <button type="button" wire:click="{{ $action }}('{{ $type }}')" wire:loading.attr="disabled" data-modal-action-icon-ignore aria-pressed="{{ $selected === $type ? 'true' : 'false' }}" @class(['quick-saber-type-switch__option', 'is-active' => $selected === $type])>{{ __('attendance.'.$type) }}</button>
        @endcan
    @endforeach
</div>
