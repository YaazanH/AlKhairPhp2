<?php

namespace App\Services;

use App\Support\ArabicSearch;
use App\Support\PhoneNumberFormatter;

class ProfileDuplicateMatcher
{
    public function similarName(string $entered, string $existing): bool
    {
        if (mb_strlen($entered) > 255 || mb_strlen($existing) > 255) {
            return false;
        }

        $entered = mb_strtolower(ArabicSearch::normalizeForDuplicate($entered));
        $existing = mb_strtolower(ArabicSearch::normalizeForDuplicate($existing));

        if (mb_strlen($entered) < 4 || $existing === '') {
            return false;
        }

        if ($entered === $existing) {
            return true;
        }

        // Allow one spelling difference in a reasonably complete name, using
        // characters rather than bytes so Arabic letters count as one edit.
        if (mb_strlen($entered) < 7 || abs(mb_strlen($entered) - mb_strlen($existing)) > 1) {
            return false;
        }

        $left = mb_str_split($entered);
        $right = mb_str_split($existing);
        $previous = range(0, count($right));
        foreach ($left as $i => $letter) {
            $current = [$i + 1];
            foreach ($right as $j => $other) {
                $current[] = min($current[$j] + 1, $previous[$j + 1] + 1, $previous[$j] + ($letter === $other ? 0 : 1));
            }
            $previous = $current;
        }

        return end($previous) <= 1;
    }

    public function samePhone(?string $entered, ?string $existing): bool
    {
        $phone = PhoneNumberFormatter::normalize($entered);

        return $phone !== null && strlen(preg_replace('/\D/', '', $phone)) >= 9
            && $phone === PhoneNumberFormatter::normalize($existing);
    }
}
