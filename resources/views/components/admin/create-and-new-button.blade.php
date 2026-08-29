@props([
    'show' => true,
    'click' => 'saveAndNew',
])

@if ($show)
    <button
        type="button"
        wire:click="{{ $click }}"
        class="admin-icon-button admin-icon-button--accent admin-modal-action-button"
        title="{{ __('crud.common.actions.create_and_new') }}"
        aria-label="{{ __('crud.common.actions.create_and_new') }}"
        data-create-and-new-action
    >
        <x-admin-action-icon name="save-new" class="admin-modal-action__icon admin-modal-action__icon--save-new" />
    </button>
@endif
