<?php

namespace App\Services\IdCards;

use App\Models\Student;
use App\Models\StudentGender;
use App\Support\AvatarDefaults;
use Illuminate\Support\Arr;

class StudentCardFieldRegistry
{
    public function definitions(): array
    {
        return [
            'full_name' => [
                'label' => __('id_cards.fields.full_name'),
                'element_types' => ['text'],
                'preview' => fn (Student $student): string => $student->full_name,
            ],
            'student_number' => [
                'label' => __('id_cards.fields.student_number'),
                'element_types' => ['text', 'barcode'],
                'preview' => fn (Student $student): string => (string) ($student->student_number ?: $student->id),
            ],
            'first_name' => [
                'label' => __('id_cards.fields.first_name'),
                'element_types' => ['text'],
                'preview' => fn (Student $student): string => (string) $student->first_name,
            ],
            'last_name' => [
                'label' => __('id_cards.fields.last_name'),
                'element_types' => ['text'],
                'preview' => fn (Student $student): string => (string) $student->last_name,
            ],
            'school_name' => [
                'label' => __('id_cards.fields.school_name'),
                'element_types' => ['text'],
                'preview' => fn (Student $student): string => (string) ($student->school_name ?: __('id_cards.common.not_available')),
            ],
            'class_name' => [
                'label' => __('id_cards.fields.class_name'),
                'element_types' => ['text'],
                'preview' => fn (Student $student): string => (string) ($student->gradeLevel?->name ?: __('id_cards.common.not_available')),
            ],
            'group_name' => [
                'label' => __('id_cards.fields.group_name'),
                'element_types' => ['text'],
                'preview' => fn (Student $student): string => (string) ($student->currentGroupName() ?: __('id_cards.common.not_available')),
            ],
            'status' => [
                'label' => __('id_cards.fields.status'),
                'element_types' => ['text'],
                'preview' => fn (Student $student): string => __('crud.common.status_options.'.($student->status ?: 'inactive')),
            ],
            'birth_date' => [
                'label' => __('id_cards.fields.birth_date'),
                'element_types' => ['text'],
                'preview' => fn (Student $student): string => $student->birth_date?->format('Y-m-d') ?: __('id_cards.common.not_available'),
            ],
            'joined_at' => [
                'label' => __('id_cards.fields.joined_at'),
                'element_types' => ['text'],
                'preview' => fn (Student $student): string => $student->joined_at?->format('Y-m-d') ?: __('id_cards.common.not_available'),
            ],
            'gender' => [
                'label' => __('id_cards.fields.gender'),
                'element_types' => ['text'],
                'preview' => fn (Student $student): string => $student->gender
                    ? (StudentGender::query()->where('code', $student->gender)->value('name') ?: __('crud.common.gender_options.'.$student->gender))
                    : __('id_cards.common.not_available'),
            ],
            'current_juz' => [
                'label' => __('id_cards.fields.current_juz'),
                'element_types' => ['text'],
                'preview' => fn (Student $student): string => $student->quranCurrentJuz?->juz_number
                    ? __('crud.students.labels.juz_number', ['number' => $student->quranCurrentJuz->juz_number])
                    : __('id_cards.common.not_available'),
            ],
            'parent_name' => [
                'label' => __('id_cards.fields.parent_name'),
                'element_types' => ['text'],
                'preview' => fn (Student $student): string => (string) ($student->parentProfile?->father_name ?: __('id_cards.common.not_available')),
            ],
            'photo' => [
                'label' => __('id_cards.fields.photo'),
                'element_types' => ['image'],
                'preview' => fn (Student $student): ?string => $student->photo_path ? '/storage/'.ltrim($student->photo_path, '/') : AvatarDefaults::url('student'),
            ],
        ];
    }

    public function selectableFields(string $elementType): array
    {
        return collect($this->definitions())
            ->filter(fn (array $definition) => in_array($elementType, Arr::wrap($definition['element_types']), true))
            ->map(fn (array $definition, string $key) => [
                'key' => $key,
                'label' => $definition['label'],
            ])
            ->values()
            ->all();
    }

    public function firstFieldFor(string $elementType): ?string
    {
        return collect($this->selectableFields($elementType))->first()['key'] ?? null;
    }

    public function resolve(Student $student, string $field): mixed
    {
        $definition = $this->definitions()[$field] ?? null;

        if (! $definition) {
            return null;
        }

        return value($definition['preview'], $student);
    }

    public function previewPayload(Student $student): array
    {
        return collect($this->definitions())
            ->mapWithKeys(fn (array $definition, string $key) => [$key => value($definition['preview'], $student)])
            ->all();
    }
}
