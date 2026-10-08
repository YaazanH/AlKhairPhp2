<?php

namespace App\Support;

use App\Models\GradeLevel;

class GradeLevelFromAge
{
    public static function resolve(int $age): ?int
    {
        $grades = GradeLevel::query()->where('is_active', true)->get(['id', 'name', 'sort_order']);
        $number = $age - 5;
        $ordinals = [1 => 'الأول', 'الثاني', 'الثالث', 'الرابع', 'الخامس', 'السادس', 'السابع', 'الثامن', 'التاسع', 'العاشر', 'الحادي عشر', 'الثاني عشر'];
        $names = match (true) {
            $age <= 4 => ['حضانة', 'روضة أولى', 'KG 1'],
            $age === 5 => ['تمهيدي', 'روضة ثانية', 'KG 2'],
            $age >= 18 => ['المرحلة الجامعية', 'جامعي', 'University'],
            default => ['Grade '.$number, 'الصف '.($ordinals[$number] ?? ''), ...($number === 12 ? ['البكلوريا', 'البكالوريا', 'الثالث الثانوي'] : [])],
        };
        $names = array_map(fn ($name) => mb_strtolower(ArabicSearch::normalize($name)), $names);
        $namedGrade = $grades->first(fn ($grade) => in_array(mb_strtolower(ArabicSearch::normalize($grade->name)), $names, true));
        if ($namedGrade) {
            return $namedGrade->id;
        }

        // Legacy seed data placed school grades at 11–22. Only use that
        // convention when the records actually identify it; sort order is editable.
        $legacyLayout = $grades->contains(fn ($grade) => $grade->sort_order >= 21 && $grade->sort_order <= 22)
            || $grades->contains(fn ($grade) => $grade->sort_order === 11 && in_array(mb_strtolower(ArabicSearch::normalize($grade->name)), ['grade 1', 'الصف الاول'], true));
        if (! $legacyLayout) {
            return null;
        }

        $order = $age <= 4 ? 1 : ($age === 5 ? 2 : ($age >= 18 ? 30 : $age + 5));

        return $grades->firstWhere('sort_order', $order)?->id;
    }
}
