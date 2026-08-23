@props([
    'title',
    'empty' => false,
    'emptyText',
    'viewAllAction',
])

<div class="surface-table student-progress-data-table">
    <div class="admin-grid-meta">
        <div class="admin-grid-meta__title">{{ $title }}</div>
        @unless ($empty)
            <button type="button" wire:click="showDetails('{{ $viewAllAction }}')" class="pill-link pill-link--compact">
                {{ __('workflow.student_progress.actions.view_all') }}
            </button>
        @endunless
    </div>

    @if ($empty)
        <div class="admin-empty-state">{{ $emptyText }}</div>
    @else
        <div class="overflow-x-auto">
            <table class="text-sm">
                <thead><tr>{{ $head }}</tr></thead>
                <tbody class="divide-y divide-white/6">{{ $slot }}</tbody>
            </table>
        </div>
    @endif
</div>
