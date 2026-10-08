<?php

namespace App\Livewire\Concerns;

use App\Models\ParentProfile;
use App\Models\Student;
use App\Services\ProfileDuplicateMatcher;
use App\Support\ArabicSearch;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;

trait ReviewsStudentCreationDuplicates
{
    #[Locked]
    public ?array $reviewedDuplicate = null;

    #[Locked]
    public array $acceptedDuplicateNames = [];

    #[Computed]
    public function profileDuplicateWarnings(): array
    {
        if (! $this->showFormModal) {
            return [];
        }

        $matcher = app(ProfileDuplicateMatcher::class);
        $warnings = [];
        if (! $this->editingId && (filled($this->first_name) && filled($this->last_name) || filled($this->student_phone))) {
            $students = $this->scopeStudentsQuery(Student::query())->with('user:id,phone')->orderBy('id')->get();
            foreach ($students as $student) {
                $match = ['type' => 'student', 'id' => $student->id, 'name' => trim($student->first_name.' '.$student->last_name), 'reason' => 'name'];
                if (filled($this->first_name) && filled($this->last_name)
                    && $matcher->similarName(trim($this->first_name.' '.$this->last_name), trim($student->first_name.' '.$student->last_name))) {
                    $warnings['first_name'] ??= $match;
                    $warnings['last_name'] ??= $match;
                }
                if ($matcher->samePhone($this->student_phone, $student->user?->phone)) {
                    $warnings['student_phone'] ??= [...$match, 'reason' => 'phone'];
                }
            }
        }

        if ($this->showQuickParentForm && ! ($this->editingId && $this->parent_id)) {
            $fields = ['father_name', 'mother_name', 'father_phone', 'mother_phone', 'home_phone'];
            if (collect($fields)->contains(fn ($field) => filled($this->{'quick_parent_'.$field}))) {
                foreach ($this->scopeParentsQuery(ParentProfile::query())->orderBy('id')->get() as $parent) {
                    foreach ($fields as $field) {
                        $value = $this->{'quick_parent_'.$field};
                        $phone = str_ends_with($field, '_phone');
                        $matches = $phone
                            ? collect([$parent->father_phone, $parent->mother_phone, $parent->home_phone])->contains(fn ($existing) => $matcher->samePhone($value, $existing))
                            : $matcher->similarName($value, (string) $parent->{$field});
                        if ($matches) {
                            $warnings['quick_parent_'.$field] ??= ['type' => 'parent', 'id' => $parent->id, 'name' => $parent->father_name, 'reason' => $phone ? 'phone' : 'name'];
                        }
                    }
                }
            }
        }

        return $warnings;
    }

    public function reviewProfileDuplicate(string $field): void
    {
        $warning = $this->profileDuplicateWarnings[$field] ?? null;
        abort_unless($warning, 404);
        $this->authorizePermission($warning['type'] === 'student' ? 'students.create' : 'parents.create');
        $this->reviewedDuplicate = [...$warning, 'field' => $field];
    }

    public function dismissProfileDuplicate(): void
    {
        $this->reviewedDuplicate = null;
    }

    public function continueDuplicateDraft(): void
    {
        $type = $this->reviewedDuplicate['type'] ?? ($this->showDuplicateStudentModal ? 'student' : null);
        abort_unless(in_array($type, ['student', 'parent'], true), 404);
        $this->authorizePermission($type === 'student' ? 'students.create' : 'parents.create');
        $this->acceptedDuplicateNames[$type] = $this->duplicateNameFingerprint($type);
        $this->dismissProfileDuplicate();
        $this->closeDuplicateStudentModal();
        $this->resetValidation($type === 'student' ? 'first_name' : 'quick_parent_father_name');
    }

    public function useReviewedDuplicate(): void
    {
        $warning = $this->reviewedDuplicate;
        if (! $warning && $this->showDuplicateStudentModal) {
            $warning = ['type' => 'student', 'id' => $this->duplicateStudentId];
        }
        abort_unless($warning, 404);
        // Recheck both the match and access scope; never trust a supplied profile ID.
        unset($this->profileDuplicateWarnings);
        $matches = collect($this->profileDuplicateWarnings)->contains(fn ($match) => $match['type'] === $warning['type'] && $match['id'] === $warning['id']);
        if (! $matches && ! $this->reviewedDuplicate && $warning['type'] === 'student') {
            $candidate = $this->scopeStudentsQuery(Student::query())->find($warning['id']);
            $matches = $candidate && app(ProfileDuplicateMatcher::class)->similarName(
                trim($this->first_name.' '.$this->last_name), trim($candidate->first_name.' '.$candidate->last_name)
            );
        }
        abort_unless($matches, 404);

        if ($warning['type'] === 'student') {
            $this->authorizePermission('students.update');
            $student = $this->scopeStudentsQuery(Student::query())->findOrFail($warning['id']);
            $this->cancel();
            $this->edit($student->id);

            return;
        }

        $this->authorizePermission($this->editingId ? 'students.update' : 'students.create');
        $parent = $this->scopeParentsQuery(ParentProfile::query())->findOrFail($warning['id']);
        $this->parent_id = $parent->id;
        $this->closeQuickParentForm();
        $this->dismissProfileDuplicate();
        $this->resetValidation('parent_id');
    }

    #[Computed]
    public function duplicateReviewProfile(): Student|ParentProfile|null
    {
        if (! $this->reviewedDuplicate) {
            return null;
        }

        return $this->reviewedDuplicate['type'] === 'student'
            ? $this->scopeStudentsQuery(Student::query())->with(['user', 'parentProfile', 'gradeLevel', 'quranCurrentJuz'])->find($this->reviewedDuplicate['id'])
            : $this->scopeParentsQuery(ParentProfile::query())->with(['students' => fn ($query) => $this->scopeStudentsQuery($query->getQuery())])->find($this->reviewedDuplicate['id']);
    }

    protected function duplicateNameFingerprint(string $type): string
    {
        return hash('sha256', implode('|', array_map(fn ($name) => ArabicSearch::normalizeForDuplicate($name), $type === 'student'
            ? [$this->first_name, $this->last_name]
            : [$this->quick_parent_father_name, $this->quick_parent_mother_name])));
    }

    protected function duplicateNameAccepted(string $type): bool
    {
        return ($this->acceptedDuplicateNames[$type] ?? null) === $this->duplicateNameFingerprint($type);
    }
}
