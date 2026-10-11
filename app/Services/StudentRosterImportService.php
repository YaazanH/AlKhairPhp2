<?php

namespace App\Services;

use App\Models\ParentProfile;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentGender;
use App\Support\PhoneNumberFormatter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class StudentRosterImportService
{
    public function __construct(
        private readonly SpreadsheetImportReader $reader,
        private readonly ManagedUserService $managedUsers,
    ) {}

    /** @return array<int, string> */
    public function templateHeaders(bool $parentsEnabled): array
    {
        return array_values(array_filter([
            'first_name', 'last_name', 'student_phone', 'birth_date', 'school_name', 'joined_at', 'status',
            $parentsEnabled ? 'parent_phone' : null,
            $parentsEnabled ? 'father_name' : null,
            $parentsEnabled ? 'mother_name' : null,
            $parentsEnabled ? 'mother_phone' : null,
            $parentsEnabled ? 'home_phone' : null,
            $parentsEnabled ? 'address' : null,
        ]));
    }

    /** @return array<int, string> */
    public function templateExample(bool $parentsEnabled): array
    {
        return array_values(array_filter([
            'Ahmad', 'Al Khaled', '0999000000', '2014-01-15', 'Al Khair School', now()->toDateString(), 'active',
            $parentsEnabled ? '0999111111' : null,
            $parentsEnabled ? 'Khaled Al Khaled' : null,
            $parentsEnabled ? 'Mariam Al Khaled' : null,
            $parentsEnabled ? '0999222222' : null,
            $parentsEnabled ? '' : null,
            $parentsEnabled ? '' : null,
        ], fn ($value) => $value !== null));
    }

    /**
     * @return array{rows: array<int, array<string, mixed>>, valid_count: int, warning_count: int, error_count: int, has_parent_data: bool}
     */
    public function preview(string $path, string $extension, bool $parentsEnabled): array
    {
        $defaultGender = $this->defaultGenderCode();

        if ($defaultGender === null) {
            throw ValidationException::withMessages(['importFile' => __('crud.students.import.errors.default_gender_missing')]);
        }

        $records = $this->reader->read($path, $extension);
        $unknownHeaders = array_values(array_diff(array_keys($records[0]), [...$this->templateHeaders(true), '_line']));

        if ($unknownHeaders !== []) {
            throw ValidationException::withMessages([
                'importFile' => __('crud.students.import.errors.unknown_headers', ['headers' => implode(', ', $unknownHeaders)]),
            ]);
        }

        $rows = [];
        $signatures = [];
        $families = [];
        $parentIndex = $this->parentPhoneIndex();
        $schoolIndex = $this->schoolIndex();
        $hasParentData = false;

        foreach ($records as $record) {
            $record = $this->defaults($record);
            $errors = $this->validateRecord($record)->errors()->all();
            $warnings = [];
            $parentData = $this->hasParentData($record);
            $hasParentData = $hasParentData || $parentData;

            if ($parentData && ! $parentsEnabled) {
                $errors[] = __('crud.students.import.errors.parents_disabled');
            } elseif ($parentData) {
                $errors = [...$errors, ...$this->validateParent($record, $families, $parentIndex)];
            }

            $schoolName = $this->normalizeSchoolName($record['school_name']);
            if ($schoolName !== '') {
                $schoolKey = $this->normalizeSchoolKey($schoolName);
                $school = $schoolIndex[$schoolKey] ?? null;

                if ($school) {
                    $schoolName = $school->name;
                } else {
                    $warnings[] = __('crud.students.import.warnings.school_created', ['school' => $schoolName]);
                    $schoolIndex[$schoolKey] = new School(['name' => $schoolName, 'is_active' => true]);
                }
            }

            $signature = Str::lower(trim($record['first_name']).'|'.trim($record['last_name']).'|'.$record['birth_date']);
            if (isset($signatures[$signature])) {
                $errors[] = __('crud.students.import.errors.duplicate_file', ['line' => $signatures[$signature]]);
            } else {
                $signatures[$signature] = $record['_line'];
            }

            if ($errors === [] && Student::query()
                ->where('first_name', trim($record['first_name']))
                ->where('last_name', trim($record['last_name']))
                ->whereDate('birth_date', $record['birth_date'])
                ->exists()) {
                $errors[] = __('crud.students.import.errors.duplicate_existing');
            }

            $normalizedParentPhone = PhoneNumberFormatter::normalize($record['parent_phone']);
            $matchedParents = $normalizedParentPhone ? ($parentIndex[$normalizedParentPhone] ?? []) : [];
            $parentLabel = count($matchedParents) === 1
                ? $matchedParents[0]->father_name
                : ($record['father_name'] ?: $record['parent_phone']);

            $rows[] = [
                'line' => (int) $record['_line'],
                'first_name' => $record['first_name'],
                'last_name' => $record['last_name'],
                'birth_date' => $record['birth_date'],
                'gender' => $defaultGender,
                'school' => $schoolName,
                'parent' => $parentLabel,
                'status' => $errors === [] ? 'ready' : 'error',
                'errors' => array_values(array_unique($errors)),
                'warnings' => array_values(array_unique($warnings)),
            ];
        }

        return [
            'rows' => $rows,
            'valid_count' => collect($rows)->where('status', 'ready')->count(),
            'warning_count' => collect($rows)->sum(fn (array $row) => count($row['warnings'])),
            'error_count' => collect($rows)->where('status', 'error')->count(),
            'has_parent_data' => $hasParentData,
        ];
    }

    /** @return array<int, array{login: string|null, email: string|null, password: string|null, role: string, name: string, number: string|null}> */
    public function import(string $path, string $extension, bool $parentsEnabled): array
    {
        $preview = $this->preview($path, $extension, $parentsEnabled);

        if ($preview['error_count'] > 0) {
            throw ValidationException::withMessages(['importFile' => __('crud.students.import.errors.fix_rows')]);
        }

        $records = $this->reader->read($path, $extension);
        $defaultGender = $this->defaultGenderCode();

        return DB::transaction(function () use ($defaultGender, $parentsEnabled, $records): array {
            $credentials = [];
            $parents = [];
            $parentIndex = $this->parentPhoneIndex();
            $schools = $this->schoolIndex();
            $familyDetails = [];

            foreach ($records as $record) {
                $record = $this->defaults($record);
                $phone = PhoneNumberFormatter::normalize($record['parent_phone']);

                if ($phone) {
                    foreach (['father_name', 'mother_name', 'mother_phone', 'home_phone', 'address'] as $field) {
                        if (filled($record[$field])) {
                            $familyDetails[$phone][$field] = $record[$field];
                        }
                    }
                }
            }

            foreach ($records as $record) {
                $record = $this->defaults($record);
                $parent = null;

                if ($parentsEnabled && $this->hasParentData($record)) {
                    $parentPhone = PhoneNumberFormatter::normalize($record['parent_phone']);
                    $parent = $parents[$parentPhone] ?? (($parentIndex[$parentPhone] ?? [])[0] ?? null);

                    if (! $parent) {
                        $details = $familyDetails[$parentPhone];
                        $parent = ParentProfile::query()->create([
                            'father_name' => $details['father_name'],
                            'father_phone' => $parentPhone,
                            'mother_name' => $details['mother_name'] ?? null,
                            'mother_phone' => $details['mother_phone'] ?? null,
                            'home_phone' => $details['home_phone'] ?? null,
                            'address' => $details['address'] ?? null,
                            'is_active' => true,
                        ]);
                        $result = $this->managedUsers->syncLinkedUser($parent->user, [
                            'name' => $parent->father_name,
                            'username' => $parent->parent_number,
                            'phones' => [$parent->father_phone, $parent->mother_phone, $parent->home_phone],
                            'is_active' => true,
                        ], 'parent');
                        $parent->user()->associate($result['user']);
                        $parent->save();
                        $credentials[] = $this->credential($result['credentials'], $parent->father_name, $parent->parent_number);
                    }

                    $parents[$parentPhone] = $parent;
                }

                $schoolName = $this->normalizeSchoolName($record['school_name']);
                if ($schoolName !== '') {
                    $schoolKey = $this->normalizeSchoolKey($schoolName);
                    $school = $schools[$schoolKey] ?? null;

                    if (! $school) {
                        $school = School::query()->create(['name' => $schoolName, 'is_active' => true]);
                        $schools[$schoolKey] = $school;
                    }

                    $schoolName = $school->name;
                }

                $student = Student::query()->create([
                    'parent_id' => $parent?->id,
                    'first_name' => $record['first_name'],
                    'last_name' => $record['last_name'],
                    'birth_date' => $record['birth_date'],
                    'gender' => $defaultGender,
                    'school_name' => $schoolName ?: null,
                    'joined_at' => $record['joined_at'] ?: now()->toDateString(),
                    'status' => $record['status'],
                ]);
                $result = $this->managedUsers->syncLinkedUser($student->user, [
                    'name' => $student->full_name,
                    'username' => $student->student_number,
                    'phone' => $record['student_phone'] ?: null,
                    'is_active' => ! in_array($student->status, ['inactive', 'blocked'], true),
                ], 'student');
                $student->user()->associate($result['user']);
                $student->save();
                $credentials[] = $this->credential($result['credentials'], $student->full_name, $student->student_number);
            }

            return $credentials;
        });
    }

    private function defaults(array $record): array
    {
        foreach ($this->templateHeaders(true) as $header) {
            $record[$header] = trim((string) ($record[$header] ?? ''));
        }
        $record['status'] = $record['status'] ?: 'active';

        return $record;
    }

    private function validateRecord(array $record): \Illuminate\Contracts\Validation\Validator
    {
        return Validator::make($record, [
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'student_phone' => ['nullable', 'string', 'max:30'],
            'birth_date' => ['required', 'date_format:Y-m-d'],
            'school_name' => ['nullable', 'string', 'max:255'],
            'joined_at' => ['nullable', 'date_format:Y-m-d'],
            'status' => ['required', Rule::in(['active', 'inactive', 'graduated', 'blocked'])],
        ]);
    }

    /**
     * @param  array<string, array<string, string>>  $families
     * @param  array<string, array<int, ParentProfile>>  $parentIndex
     * @return array<int, string>
     */
    private function validateParent(array $record, array &$families, array $parentIndex): array
    {
        if (! $record['parent_phone']) {
            return [__('crud.students.import.errors.parent_phone_required')];
        }

        $phone = PhoneNumberFormatter::normalize($record['parent_phone']);
        if (! $phone) {
            return [__('crud.students.import.errors.parent_phone_invalid')];
        }

        $errors = [];
        $matchedParents = $parentIndex[$phone] ?? [];
        if (count($matchedParents) > 1) {
            $errors[] = __('crud.students.import.errors.parent_phone_ambiguous', ['phone' => $record['parent_phone']]);
        }

        $existingParent = count($matchedParents) === 1 ? $matchedParents[0] : null;
        if ($existingParent && $record['father_name'] && $this->normalizePersonName($record['father_name']) !== $this->normalizePersonName($existingParent->father_name)) {
            $errors[] = __('crud.students.import.errors.parent_existing_conflict', ['phone' => $record['parent_phone']]);
        }

        $knownFamily = isset($families[$phone]);
        if (! $existingParent && ! $knownFamily && ! $record['father_name']) {
            $errors[] = __('crud.students.import.errors.father_name');
        }

        $details = collect(['father_name', 'mother_name', 'mother_phone', 'home_phone', 'address'])
            ->mapWithKeys(fn (string $field) => [$field => $record[$field]])
            ->all();

        if ($knownFamily) {
            foreach ($details as $field => $value) {
                if ($value !== '' && $families[$phone][$field] !== '' && $this->normalizeComparable($value) !== $this->normalizeComparable($families[$phone][$field])) {
                    $errors[] = __('crud.students.import.errors.parent_phone_conflict', ['phone' => $record['parent_phone']]);
                    break;
                }

                if ($value !== '' && $families[$phone][$field] === '') {
                    $families[$phone][$field] = $value;
                }
            }
        } else {
            $families[$phone] = $details;
        }

        return $errors;
    }

    private function hasParentData(array $record): bool
    {
        return collect(['parent_phone', 'father_name', 'mother_name', 'mother_phone', 'home_phone', 'address'])
            ->contains(fn (string $field) => filled($record[$field] ?? null));
    }

    private function defaultGenderCode(): ?string
    {
        return StudentGender::query()->where('is_active', true)->where('is_default', true)->value('code');
    }

    /** @return array<string, School> */
    private function schoolIndex(): array
    {
        return School::query()->get()->mapWithKeys(
            fn (School $school) => [$this->normalizeSchoolKey($school->name) => $school],
        )->all();
    }

    private function normalizeSchoolName(string $name): string
    {
        return preg_replace('/\s+/u', ' ', trim($name)) ?? trim($name);
    }

    private function normalizeSchoolKey(string $name): string
    {
        return mb_strtolower($this->normalizeSchoolName($name));
    }

    /** @return array<string, array<int, ParentProfile>> */
    private function parentPhoneIndex(): array
    {
        $index = [];

        ParentProfile::query()->with('user')->get()->each(function (ParentProfile $parent) use (&$index): void {
            foreach ([$parent->father_phone, $parent->mother_phone, $parent->home_phone, $parent->user?->phone] as $candidate) {
                $phone = PhoneNumberFormatter::normalize($candidate);

                if ($phone && ! collect($index[$phone] ?? [])->contains(fn (ParentProfile $match) => $match->is($parent))) {
                    $index[$phone][] = $parent;
                }
            }
        });

        return $index;
    }

    private function normalizePersonName(string $name): string
    {
        return mb_strtolower(preg_replace('/\s+/u', ' ', trim($name)) ?? trim($name));
    }

    private function normalizeComparable(string $value): string
    {
        return PhoneNumberFormatter::normalize($value) ?? mb_strtolower(trim($value));
    }

    private function credential(array $credentials, string $name, ?string $number): array
    {
        return [...$credentials, 'name' => $name, 'number' => $number];
    }
}
