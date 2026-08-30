@props([
    'label' => __('crud.common.actions.save'),
    'type' => 'submit',
])

<button
    type="{{ $type }}"
    {{ $attributes->class(['admin-icon-button', 'admin-icon-button--accent', 'admin-modal-action-button']) }}
    title="{{ $label }}"
    aria-label="{{ $label }}"
    data-save-action
>
    <x-admin-action-icon name="save" class="admin-modal-action__icon" />
</button>
