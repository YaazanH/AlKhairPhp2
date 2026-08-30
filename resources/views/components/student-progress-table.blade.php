@props([
    'title',
    'empty' => false,
    'emptyText',
    'viewAllAction',
])

<div class="surface-table student-progress-data-table">
    <div class="admin-grid-meta student-progress-data-table__header">
        <div class="admin-grid-meta__title">{{ $title }}</div>
        @unless ($empty)
            <button
                type="button"
                wire:click="showDetails('{{ $viewAllAction }}')"
                class="student-progress-data-table__expand"
                title="{{ __('workflow.student_progress.actions.view_all') }}"
                aria-label="{{ __('workflow.student_progress.actions.view_all') }}"
                data-view-all-expand
            >
                <x-admin-action-icon name="expand" />
            </button>
        @endunless
    </div>

    @if ($empty)
        <div class="admin-empty-state">{{ $emptyText }}</div>
    @else
        <div class="table-scroll-region overflow-x-auto" data-table-scroll-region>
            <table class="text-sm">
                <thead><tr>{{ $head }}</tr></thead>
                <tbody class="divide-y divide-white/6">{{ $slot }}</tbody>
            </table>
        </div>
    @endif
</div>
