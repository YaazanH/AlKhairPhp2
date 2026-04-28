<?php

namespace App\Services;

use App\Models\AppSetting;
use App\Models\Student;

class StudentNumberService
{
    public const GROUP = 'general';

    public const PREFIX_KEY = 'student_number_prefix';

    public const LENGTH_KEY = 'student_number_length';

    public function prefix(): string
    {
        $settings = AppSetting::groupValues(self::GROUP);

        return trim((string) ($settings->get(self::PREFIX_KEY) ?? ''));
    }

    public function length(): int
    {
        $settings = AppSetting::groupValues(self::GROUP);
        $length = $settings->get(self::LENGTH_KEY);

        return is_numeric($length) ? max(0, (int) $length) : 0;
    }

    public function formatForId(int $studentId): string
    {
        $number = (string) $studentId;
        $length = $this->length();

        if ($length > 0) {
            $number = str_pad($number, $length, '0', STR_PAD_LEFT);
        }

        return $this->prefix().$number;
    }

    public function syncStudent(Student $student): void
    {
        $expectedStudentNumber = $this->formatForId((int) $student->id);

        if ($student->student_number !== $expectedStudentNumber) {
            $student->forceFill([
                'student_number' => $expectedStudentNumber,
            ])->saveQuietly();
        }
    }

    public function syncAll(): void
    {
        Student::query()
            ->select(['id', 'student_number'])
            ->orderBy('id')
            ->chunkById(200, function ($students): void {
                foreach ($students as $student) {
                    $expectedStudentNumber = $this->formatForId((int) $student->id);

                    if ($student->student_number !== $expectedStudentNumber) {
                        Student::query()
                            ->whereKey($student->id)
                            ->update(['student_number' => $expectedStudentNumber]);
                    }
                }
            });
    }
}
