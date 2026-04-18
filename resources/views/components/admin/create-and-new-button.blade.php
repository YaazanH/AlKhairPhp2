@props([
    'show' => true,
    'click' => 'saveAndNew',
])

@if ($show)
    <button type="button" wire:click="{{ $click }}" class="pill-link">
        {{ __('crud.common.actions.create_and_new') }}
    </button>
@endif
