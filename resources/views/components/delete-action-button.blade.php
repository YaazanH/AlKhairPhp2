@props([
    'label',
    'icon' => 'delete',
])

<button
    type="button"
    {{ $attributes->class(['admin-icon-button', 'admin-icon-button--danger', 'admin-modal-action-button']) }}
    title="{{ $label }}"
    aria-label="{{ $label }}"
    data-delete-action
>
    <x-admin-action-icon :name="$icon" class="admin-modal-action__icon" />
</button>
