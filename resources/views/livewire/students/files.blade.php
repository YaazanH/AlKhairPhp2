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

<div class="page-stack">
    <section class="page-hero p-6 lg:p-8">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
            <div>
                <a href="{{ route('students.index') }}" wire:navigate class="text-sm font-medium text-neutral-200/80 hover:text-white">{{ __('media.student_files.back') }}</a>
                <div class="eyebrow mt-4">{{ __('ui.nav.people') }}</div>
                <h1 class="font-display mt-4 text-4xl leading-none text-white md:text-5xl">{{ __('media.student_files.heading') }}</h1>
                <p class="mt-4 max-w-3xl text-base leading-7 text-neutral-200">{{ __('media.student_files.subheading') }}</p>
            </div>

            <div class="surface-panel px-5 py-4">
                <div class="text-sm font-semibold text-white">{{ $studentRecord->first_name }} {{ $studentRecord->last_name }}</div>
                <div class="mt-1 text-sm text-neutral-400">{{ $studentRecord->parentProfile?->father_name ?: __('media.student_files.profile.no_parent') }}</div>
                <div class="mt-1 text-sm text-neutral-400">{{ $studentRecord->gradeLevel?->name ?: __('media.student_files.profile.no_grade') }}</div>
            </div>
        </div>
    </section>

    @if (session('status'))
        <div class="flash-success px-4 py-3 text-sm">
            {{ session('status') }}
        </div>
    @endif

    <section class="admin-kpi-grid">
        <article class="stat-card">
            <div class="kpi-label">{{ __('media.student_files.files.stored') }}</div>
            <div class="metric-value mt-3">{{ number_format($studentFiles->count()) }}</div>
        </article>
        <article class="stat-card">
            <div class="kpi-label">{{ __('media.student_files.photo.title') }}</div>
            <div class="mt-4 text-lg font-semibold text-white">{{ $photoUrl ? __('crud.common.status_options.active') : __('crud.common.not_available') }}</div>
        </article>
        <article class="stat-card">
            <div class="kpi-label">{{ __('crud.students.form.fields.grade_level') }}</div>
            <div class="mt-4 text-lg font-semibold text-white">{{ $studentRecord->gradeLevel?->name ?: __('media.student_files.profile.no_grade') }}</div>
        </article>
    </section>

    <div class="grid gap-6 xl:grid-cols-[24rem_minmax(0,1fr)]">
        <section class="space-y-6">
            <div class="surface-panel p-5 lg:p-6">
                <div class="admin-section-card__header">
                    <div class="admin-section-card__title">{{ __('media.student_files.photo.title') }}</div>
                    <p class="admin-section-card__copy">{{ __('media.student_files.photo.description') }}</p>
                </div>

                <div class="mt-5 flex justify-center">
                    @if ($photo_upload)
                        <img src="{{ $photo_upload->temporaryUrl() }}" alt="{{ __('media.student_files.photo.preview_alt') }}" class="h-44 w-44 rounded-3xl object-cover shadow-sm">
                    @elseif ($photoUrl)
                        <img src="{{ $photoUrl }}" alt="{{ __('media.student_files.photo.alt') }}" class="h-44 w-44 rounded-3xl object-cover shadow-sm">
                    @else
                        <div class="flex h-44 w-44 items-center justify-center rounded-3xl border border-dashed border-white/15 text-center text-sm text-neutral-400">
                            {{ __('media.student_files.photo.empty') }}
                        </div>
                    @endif
                </div>

                @if (auth()->user()->can('students.photo.update'))
                    <form wire:submit="savePhoto" class="mt-5 space-y-4">
                        <div>
                            <label for="student-photo-upload" class="mb-1 block text-sm font-medium">{{ __('media.student_files.photo.upload') }}</label>
                            <input id="student-photo-upload" wire:model="photo_upload" type="file" accept="image/*" class="block w-full text-sm text-neutral-300">
                            @error('photo_upload')
                                <div class="mt-1 text-sm text-red-400">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="flex flex-wrap items-center gap-3">
                            <button type="submit" class="pill-link pill-link--accent">
                                {{ __('media.student_files.photo.save') }}
                            </button>

                            @if ($photoUrl)
                                <button type="button" wire:click="removePhoto" wire:confirm="{{ __('crud.common.confirm_delete.message') }}" class="pill-link border-red-400/25 text-red-200 hover:border-red-300/35 hover:bg-red-500/12">
                                    {{ __('media.student_files.photo.remove') }}
                                </button>
                            @endif
                        </div>
                    </form>
                @else
                    <div class="mt-5 soft-callout px-4 py-4 text-sm leading-6">
                        {{ __('media.student_files.photo.readonly') }}
                    </div>
                @endif
            </div>
        </section>

        <section class="surface-table">
            <div class="admin-grid-meta">
                <div>
                    <div class="admin-grid-meta__title">{{ __('media.student_files.files.title') }}</div>
                    <div class="admin-grid-meta__summary">{{ __('crud.common.badges.in_view', ['count' => number_format($studentFiles->count())]) }}</div>
                </div>
            </div>

            <div class="space-y-6 p-5 lg:p-6">
                @if (auth()->user()->can('students.files.manage'))
                    <form wire:submit="uploadFile" class="rounded-3xl border border-white/10 bg-white/[0.03] p-4">
                        <div class="grid gap-4 md:grid-cols-[12rem_minmax(0,1fr)_auto] md:items-end">
                            <div>
                                <label for="student-file-type" class="mb-1 block text-sm font-medium">{{ __('media.student_files.files.fields.type') }}</label>
                                <input id="student-file-type" wire:model="file_type" type="text" placeholder="{{ __('media.student_files.files.placeholder') }}" class="w-full rounded-xl px-4 py-3 text-sm">
                                @error('file_type')
                                    <div class="mt-1 text-sm text-red-400">{{ $message }}</div>
                                @enderror
                            </div>

                            <div>
                                <label for="student-file-upload" class="mb-1 block text-sm font-medium">{{ __('media.student_files.files.fields.file') }}</label>
                                <input id="student-file-upload" wire:model="file_upload" type="file" class="block w-full text-sm text-neutral-300">
                                @error('file_upload')
                                    <div class="mt-1 text-sm text-red-400">{{ $message }}</div>
                                @enderror
                            </div>

                            <button type="submit" class="pill-link pill-link--accent">
                                {{ __('media.student_files.files.upload') }}
                            </button>
                        </div>
                    </form>
                @endif

                @if ($studentFiles->isEmpty())
                    <div class="admin-empty-state">
                        {{ __('media.student_files.files.empty') }}
                    </div>
                @else
                    <div class="overflow-x-auto">
                        <table class="text-sm">
                            <thead>
                                <tr>
                                    <th class="px-4 py-4 text-left lg:px-5">{{ __('media.student_files.files.headers.file') }}</th>
                                    <th class="px-4 py-4 text-left lg:px-5">{{ __('media.student_files.files.headers.type') }}</th>
                                    <th class="px-4 py-4 text-left lg:px-5">{{ __('media.student_files.files.headers.uploaded_by') }}</th>
                                    <th class="px-4 py-4 text-left lg:px-5">{{ __('media.student_files.files.headers.uploaded_at') }}</th>
                                    <th class="px-4 py-4 text-right lg:px-5">{{ __('media.student_files.files.headers.actions') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-white/6">
                                @foreach ($studentFiles as $studentFile)
                                    <tr>
                                        <td class="px-4 py-4 lg:px-5">
                                            <div class="font-medium text-white">{{ $studentFile->original_name }}</div>
                                            <div class="text-xs text-neutral-500">{{ $studentFile->file_path }}</div>
                                        </td>
                                        <td class="px-4 py-4 text-neutral-300 lg:px-5">{{ $studentFile->file_type }}</td>
                                        <td class="px-4 py-4 text-neutral-300 lg:px-5">{{ $studentFile->uploader?->name ?: __('media.student_files.files.system') }}</td>
                                        <td class="px-4 py-4 text-neutral-300 lg:px-5">{{ $studentFile->created_at?->format('Y-m-d H:i') }}</td>
                                        <td class="px-4 py-4 lg:px-5">
                                            <div class="admin-action-cluster admin-action-cluster--end">
                                                <a href="{{ asset('storage/'.ltrim($studentFile->file_path, '/')) }}" target="_blank" class="pill-link pill-link--compact">
                                                    {{ __('media.student_files.files.open') }}
                                                </a>
                                                @can('students.files.manage')
                                                    <button type="button" wire:click="deleteFile({{ $studentFile->id }})" wire:confirm="{{ __('crud.common.confirm_delete.message') }}" class="pill-link pill-link--compact border-red-400/25 text-red-200 hover:border-red-300/35 hover:bg-red-500/12">
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
