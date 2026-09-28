<label class="flex items-start gap-3 text-sm text-neutral-200" data-student-progress-all-scope>
    <input wire:model="scope_student_progress_all" type="checkbox" class="mt-0.5 rounded">
    <span>
        <span class="block">{{ __('access.users.scopes.student_progress_all') }}</span>
        <span class="mt-1 block text-xs text-neutral-400">{{ __('access.users.scopes.student_progress_all_help') }}</span>
    </span>
</label>
@error('scope_student_progress_all') <div class="text-sm text-red-400">{{ $message }}</div> @enderror
