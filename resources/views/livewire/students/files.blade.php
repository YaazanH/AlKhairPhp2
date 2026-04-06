<?php

use App\Livewire\Concerns\AuthorizesPermissions;
use App\Livewire\Concerns\AuthorizesTeacherAssignments;
use App\Models\Student;
use App\Models\StudentFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;

new class extends Component {
    use AuthorizesPermissions;
    use AuthorizesTeacherAssignments;
    use WithFileUploads;

    public Student $currentStudent;
    public $photo_upload = null;
    public $file_upload = null;
    public string $file_type = '';

    public function mount(Student $student): void
    {
        $this->authorizePermission('students.view');

        $this->currentStudent = Student::query()
            ->with(['parentProfile', 'gradeLevel'])
            ->findOrFail($student->id);

        $this->authorizeScopedStudentAccess($this->currentStudent);
    }

    public function with(): array
    {
        $studentRecord = $this->currentStudent->fresh(['parentProfile', 'gradeLevel']);

        return [
            'studentRecord' => $studentRecord,
            'studentFiles' => StudentFile::query()
                ->with('uploader')
                ->where('student_id', $this->currentStudent->id)
                ->latest()
                ->get(),
            'photoUrl' => $studentRecord?->photo_path
                ? asset('storage/'.ltrim($studentRecord->photo_path, '/'))
                : null,
        ];
    }

    public function savePhoto(): void
    {
        $this->authorizePermission('students.photo.update');

        $validated = $this->validate([
            'photo_upload' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ]);

        $path = $validated['photo_upload']->store('students/photos/'.$this->currentStudent->id, 'public');

        if ($this->currentStudent->photo_path) {
            Storage::disk('public')->delete($this->currentStudent->photo_path);
        }

        $this->currentStudent->update([
            'photo_path' => $path,
        ]);

        $this->reset('photo_upload');

        session()->flash('status', __('media.student_files.messages.photo_updated'));
    }

    public function removePhoto(): void
    {
        $this->authorizePermission('students.photo.update');

        if ($this->currentStudent->photo_path) {
            Storage::disk('public')->delete($this->currentStudent->photo_path);
        }

        $this->currentStudent->update([
            'photo_path' => null,
        ]);

        session()->flash('status', __('media.student_files.messages.photo_removed'));
    }

    public function uploadFile(): void
    {
        $this->authorizePermission('students.files.manage');

        $validated = $this->validate([
            'file_type' => ['required', 'string', 'max:50'],
            'file_upload' => ['required', 'file', 'max:10240'],
        ]);

        $path = $validated['file_upload']->store('students/files/'.$this->currentStudent->id, 'public');

        StudentFile::query()->create([
            'student_id' => $this->currentStudent->id,
            'file_type' => $validated['file_type'],
            'file_path' => $path,
            'original_name' => $validated['file_upload']->getClientOriginalName(),
            'uploaded_by' => Auth::id(),
        ]);

        $this->reset('file_upload', 'file_type');

        session()->flash('status', __('media.student_files.messages.file_uploaded'));
    }

    public function deleteFile(int $studentFileId): void
    {
        $this->authorizePermission('students.files.manage');

        $studentFile = StudentFile::query()
            ->where('student_id', $this->currentStudent->id)
            ->findOrFail($studentFileId);

        Storage::disk('public')->delete($studentFile->file_path);
        $studentFile->delete();

        session()->flash('status', __('media.student_files.messages.file_deleted'));
    }
}; ?>

<div class="flex w-full flex-1 flex-col gap-6 p-6 lg:p-8">
    <div class="flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
        <div>
            <a href="{{ route('students.index') }}" wire:navigate class="text-sm font-medium text-neutral-500 hover:text-neutral-900 dark:hover:text-white">{{ __('media.student_files.back') }}</a>
            <flux:heading size="xl" class="mt-2">{{ __('media.student_files.heading') }}</flux:heading>
            <flux:subheading>{{ __('media.student_files.subheading') }}</flux:subheading>
        </div>

        <div class="rounded-2xl border border-neutral-200 bg-white px-5 py-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
            <div class="text-sm font-medium">{{ $studentRecord->first_name }} {{ $studentRecord->last_name }}</div>
            <div class="mt-1 text-sm text-neutral-500">{{ $studentRecord->parentProfile?->father_name ?: __('media.student_files.profile.no_parent') }}</div>
            <div class="mt-1 text-sm text-neutral-500">{{ $studentRecord->gradeLevel?->name ?: __('media.student_files.profile.no_grade') }}</div>
        </div>
    </div>

    @if (session('status'))
        <div class="rounded-xl border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-700">
            {{ session('status') }}
        </div>
    @endif

    <div class="grid gap-6 xl:grid-cols-[24rem_minmax(0,1fr)]">
        <section class="space-y-6">
            <div class="rounded-xl border border-neutral-200 p-5 dark:border-neutral-700">
                <div class="mb-4">
                    <h2 class="text-lg font-semibold">{{ __('media.student_files.photo.title') }}</h2>
                    <p class="text-sm text-neutral-500">{{ __('media.student_files.photo.description') }}</p>
                </div>

                <div class="mb-4 flex justify-center">
                    @if ($photo_upload)
                        <img src="{{ $photo_upload->temporaryUrl() }}" alt="{{ __('media.student_files.photo.preview_alt') }}" class="h-44 w-44 rounded-2xl object-cover shadow-sm">
                    @elseif ($photoUrl)
                        <img src="{{ $photoUrl }}" alt="{{ __('media.student_files.photo.alt') }}" class="h-44 w-44 rounded-2xl object-cover shadow-sm">
                    @else
                        <div class="flex h-44 w-44 items-center justify-center rounded-2xl border border-dashed border-neutral-300 text-center text-sm text-neutral-500 dark:border-neutral-700">
                            {{ __('media.student_files.photo.empty') }}
                        </div>
                    @endif
                </div>

                @if (auth()->user()->can('students.photo.update'))
                    <form wire:submit="savePhoto" class="space-y-4">
                        <div>
                            <label for="student-photo-upload" class="mb-1 block text-sm font-medium">{{ __('media.student_files.photo.upload') }}</label>
                            <input id="student-photo-upload" wire:model="photo_upload" type="file" accept="image/*" class="block w-full text-sm">
                            @error('photo_upload')
                                <div class="mt-1 text-sm text-red-600">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="flex items-center gap-3">
                            <button type="submit" class="rounded-lg bg-neutral-900 px-4 py-2 text-sm font-medium text-white dark:bg-white dark:text-neutral-900">
                                {{ __('media.student_files.photo.save') }}
                            </button>

                            @if ($photoUrl)
                                <button type="button" wire:click="removePhoto" wire:confirm="{{ __('crud.common.confirm_delete.message') }}" class="rounded-lg border border-red-300 px-4 py-2 text-sm font-medium text-red-700 dark:border-red-800 dark:text-red-300">
                                    {{ __('media.student_files.photo.remove') }}
                                </button>
                            @endif
                        </div>
                    </form>
                @else
                    <p class="text-sm text-neutral-500">{{ __('media.student_files.photo.readonly') }}</p>
                @endif
            </div>

            <div class="rounded-xl border border-neutral-200 p-5 dark:border-neutral-700">
                <div class="mb-2 text-sm text-neutral-500">{{ __('media.student_files.files.stored') }}</div>
                <div class="text-3xl font-semibold">{{ number_format($studentFiles->count()) }}</div>
            </div>
        </section>

        <section class="overflow-hidden rounded-xl border border-neutral-200 dark:border-neutral-700">
            <div class="border-b border-neutral-200 px-5 py-4 dark:border-neutral-700">
                <h2 class="text-lg font-semibold">{{ __('media.student_files.files.title') }}</h2>
                <p class="mt-1 text-sm text-neutral-500">{{ __('media.student_files.files.description') }}</p>
            </div>

            <div class="space-y-6 p-5">
                @if (auth()->user()->can('students.files.manage'))
                    <form wire:submit="uploadFile" class="grid gap-4 rounded-xl border border-neutral-200 p-4 dark:border-neutral-700 md:grid-cols-[12rem_minmax(0,1fr)_auto] md:items-end">
                        <div>
                            <label for="student-file-type" class="mb-1 block text-sm font-medium">{{ __('media.student_files.files.fields.type') }}</label>
                            <input id="student-file-type" wire:model="file_type" type="text" placeholder="{{ __('media.student_files.files.placeholder') }}" class="w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-900">
                            @error('file_type')
                                <div class="mt-1 text-sm text-red-600">{{ $message }}</div>
                            @enderror
                        </div>

                        <div>
                            <label for="student-file-upload" class="mb-1 block text-sm font-medium">{{ __('media.student_files.files.fields.file') }}</label>
                            <input id="student-file-upload" wire:model="file_upload" type="file" class="block w-full text-sm">
                            @error('file_upload')
                                <div class="mt-1 text-sm text-red-600">{{ $message }}</div>
                            @enderror
                        </div>

                        <button type="submit" class="rounded-lg bg-neutral-900 px-4 py-2 text-sm font-medium text-white dark:bg-white dark:text-neutral-900">
                            {{ __('media.student_files.files.upload') }}
                        </button>
                    </form>
                @endif

                @if ($studentFiles->isEmpty())
                    <div class="rounded-xl border border-dashed border-neutral-300 px-4 py-10 text-center text-sm text-neutral-500 dark:border-neutral-700">
                        {{ __('media.student_files.files.empty') }}
                    </div>
                @else
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-neutral-200 text-sm dark:divide-neutral-700">
                            <thead class="bg-neutral-50 dark:bg-neutral-900/60">
                                <tr>
                                    <th class="px-4 py-3 text-left font-medium">{{ __('media.student_files.files.headers.file') }}</th>
                                    <th class="px-4 py-3 text-left font-medium">{{ __('media.student_files.files.headers.type') }}</th>
                                    <th class="px-4 py-3 text-left font-medium">{{ __('media.student_files.files.headers.uploaded_by') }}</th>
                                    <th class="px-4 py-3 text-left font-medium">{{ __('media.student_files.files.headers.uploaded_at') }}</th>
                                    <th class="px-4 py-3 text-right font-medium">{{ __('media.student_files.files.headers.actions') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-neutral-200 dark:divide-neutral-700">
                                @foreach ($studentFiles as $studentFile)
                                    <tr>
                                        <td class="px-4 py-3">
                                            <div class="font-medium">{{ $studentFile->original_name }}</div>
                                            <div class="text-xs text-neutral-500">{{ $studentFile->file_path }}</div>
                                        </td>
                                        <td class="px-4 py-3">{{ $studentFile->file_type }}</td>
                                        <td class="px-4 py-3">{{ $studentFile->uploader?->name ?: __('media.student_files.files.system') }}</td>
                                        <td class="px-4 py-3">{{ $studentFile->created_at?->format('Y-m-d H:i') }}</td>
                                        <td class="px-4 py-3">
                                            <div class="flex justify-end gap-2">
                                                <a href="{{ asset('storage/'.ltrim($studentFile->file_path, '/')) }}" target="_blank" class="rounded-lg border border-neutral-300 px-3 py-1.5 dark:border-neutral-700">
                                                    {{ __('media.student_files.files.open') }}
                                                </a>
                                                @can('students.files.manage')
                                                    <button type="button" wire:click="deleteFile({{ $studentFile->id }})" wire:confirm="{{ __('crud.common.confirm_delete.message') }}" class="rounded-lg border border-red-300 px-3 py-1.5 text-red-700 dark:border-red-800 dark:text-red-300">
                                                        {{ __('crud.common.actions.delete') }}
                                                    </button>
                                                @endcan
                                            </div>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </section>
    </div>
</div>
