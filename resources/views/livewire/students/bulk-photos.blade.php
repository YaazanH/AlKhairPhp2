<?php

use App\Livewire\Concerns\AuthorizesPermissions;
use App\Livewire\Concerns\AuthorizesTeacherAssignments;
use App\Models\Student;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;

new class extends Component {
    use AuthorizesPermissions, AuthorizesTeacherAssignments, WithFileUploads;

    public array $uploads = [];
    public array $matches = [];

    public function mount(): void
    {
        $this->authorizePermission('students.update');
    }

    public function with(): array
    {
        return [
            'studentOptions' => $this->scopeStudentsQuery(
                Student::query()->orderBy('first_name')->orderBy('last_name')
            )->get(['id', 'first_name', 'last_name', 'student_number']),
        ];
    }

    public function updatedUploads(): void
    {
        $this->authorizePermission('students.update');
        $this->validate([
            'uploads' => ['array'],
            'uploads.*' => ['image', 'max:5120'],
        ]);

        $students = $this->scopeStudentsQuery(Student::query())
            ->get(['id', 'first_name', 'last_name', 'student_number']);

        $this->matches = collect($this->uploads)
            ->map(function ($upload, int $index) use ($students) {
                $fileName = $upload->getClientOriginalName();
                $stem = (string) Str::of(pathinfo($fileName, PATHINFO_FILENAME))->trim()->lower();
                $normalizedStem = (string) Str::of($stem)->replaceMatches('/[^a-z0-9]+/i', '');

                $student = $students->first(function (Student $student) use ($stem, $normalizedStem) {
                    $studentNumber = (string) Str::of((string) $student->student_number)->lower();
                    $id = (string) $student->id;
                    $slug = (string) Str::of($student->full_name)->lower()->replaceMatches('/[^a-z0-9]+/i', '');

                    return $stem === $studentNumber
                        || $stem === $id
                        || ($normalizedStem !== '' && in_array($normalizedStem, [$studentNumber, $id, $slug], true));
                });

                return [
                    'index' => $index,
                    'file_name' => $fileName,
                    'student_id' => $student?->id,
                ];
            })
            ->values()
            ->all();
    }

    public function save(): void
    {
        $this->authorizePermission('students.update');
        $this->validate([
            'uploads' => ['required', 'array', 'min:1'],
            'uploads.*' => ['image', 'max:5120'],
            'matches' => ['array'],
            'matches.*.student_id' => ['nullable', 'integer'],
        ]);

        $saved = 0;

        foreach ($this->matches as $match) {
            $studentId = (int) ($match['student_id'] ?? 0);
            $upload = $this->uploads[(int) ($match['index'] ?? -1)] ?? null;

            if (! $studentId || ! $upload) {
                continue;
            }

            $student = $this->scopeStudentsQuery(Student::query())->findOrFail($studentId);
            $this->authorizeScopedStudentAccess($student);

            if ($student->photo_path) {
                Storage::disk('public')->delete($student->photo_path);
            }

            $extension = $upload->getClientOriginalExtension() ?: $upload->extension() ?: 'jpg';
            $student->update([
                'photo_path' => $upload->storeAs(
                    'students/photos/'.$student->id,
                    Str::uuid().'.'.Str::lower($extension),
                    'public'
                ),
            ]);

            $saved++;
        }

        $this->reset('uploads', 'matches');
        session()->flash('status', __('crud.students.bulk_photos.saved', ['count' => $saved]));
    }
}; ?>

<div class="page-stack">
    <section class="page-hero p-6 lg:p-8">
        <div class="eyebrow">{{ __('ui.nav.students') }}</div>
        <h1 class="font-display mt-4 text-4xl leading-none text-white md:text-5xl">{{ __('crud.students.bulk_photos.title') }}</h1>
        <p class="mt-4 max-w-3xl text-base leading-7 text-neutral-200">{{ __('crud.students.bulk_photos.subtitle') }}</p>
    </section>

    @if (session('status'))
        <div class="flash-success px-4 py-3 text-sm">{{ session('status') }}</div>
    @endif

    <section class="surface-panel p-6">
        <div class="grid gap-5 lg:grid-cols-[minmax(0,1fr)_18rem] lg:items-end">
            <div>
                <label class="form-label">{{ __('crud.students.bulk_photos.upload_label') }}</label>
                <input wire:model="uploads" type="file" multiple accept="image/*" class="mt-2 block w-full rounded-2xl border border-white/10 px-4 py-4 text-sm">
                <p class="mt-2 text-sm text-neutral-400">{{ __('crud.students.bulk_photos.upload_help') }}</p>
                @error('uploads.*') <div class="mt-2 text-sm text-red-500">{{ $message }}</div> @enderror
            </div>
            <button type="button" wire:click="save" class="pill-link pill-link--accent justify-center">
                {{ __('crud.students.bulk_photos.save') }}
            </button>
        </div>
    </section>

    <section class="surface-panel overflow-visible p-0">
        @if ($matches === [])
            <div class="p-8 text-center text-sm text-neutral-400">{{ __('crud.students.bulk_photos.empty') }}</div>
        @else
            <div class="surface-table-wrapper overflow-visible">
                <table class="text-sm">
                    <thead>
                        <tr>
                            <th>{{ __('crud.students.bulk_photos.headers.photo') }}</th>
                            <th>{{ __('crud.students.bulk_photos.headers.file') }}</th>
                            <th>{{ __('crud.students.bulk_photos.headers.match') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($matches as $match)
                            @php($upload = $uploads[$match['index']] ?? null)
                            <tr>
                                <td>
                                    @if ($upload)
                                        <img src="{{ $upload->temporaryUrl() }}" alt="{{ $match['file_name'] }}" class="h-16 w-16 rounded-2xl object-cover">
                                    @endif
                                </td>
                                <td class="font-medium">{{ $match['file_name'] }}</td>
                                <td>
                                    <select wire:model="matches.{{ $loop->index }}.student_id" class="min-w-72 rounded-2xl px-4 py-3 text-sm">
                                        <option value="">{{ __('crud.students.bulk_photos.no_match') }}</option>
                                        @foreach ($studentOptions as $student)
                                            <option value="{{ $student->id }}">{{ $student->student_number }} - {{ $student->full_name }}</option>
                                        @endforeach
                                    </select>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
</div>
