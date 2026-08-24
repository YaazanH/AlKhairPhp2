<?php

namespace App\Support;

use Illuminate\Support\Str;

final class ArabicUsernameTransliterator
{
    /**
     * Common Arabic personal names and Syrian family names use their familiar
     * English spelling instead of a character-by-character approximation.
     * Keys are stored in the normalized form produced by normalizeArabicWord().
     *
     * @var array<string, string>
     */
    private const WORDS = [
        'ادم' => 'adam',
        'ادهم' => 'adham',
        'اسامه' => 'osama',
        'اسماعيل' => 'ismail',
        'اشرف' => 'ashraf',
        'اكرم' => 'akram',
        'امين' => 'amin',
        'انس' => 'anas',
        'اياد' => 'iyad',
        'ايمن' => 'ayman',
        'ابراهيم' => 'ibrahim',
        'احمد' => 'ahmad',
        'باسل' => 'basel',
        'بسام' => 'bassam',
        'بشار' => 'bashar',
        'بلال' => 'bilal',
        'تامر' => 'tamer',
        'جمال' => 'jamal',
        'حازم' => 'hazem',
        'حبيب' => 'habib',
        'حسام' => 'hossam',
        'حسن' => 'hasan',
        'حسين' => 'hussein',
        'حمزه' => 'hamza',
        'خالد' => 'khaled',
        'رامي' => 'rami',
        'رائد' => 'raed',
        'سامر' => 'samer',
        'سامي' => 'sami',
        'سعيد' => 'saeed',
        'سليم' => 'salim',
        'سليمان' => 'suleiman',
        'سيف' => 'saif',
        'شادي' => 'shadi',
        'طارق' => 'tareq',
        'عادل' => 'adel',
        'عامر' => 'amer',
        'عباس' => 'abbas',
        'عبد' => 'abd',
        'عبدالله' => 'abdullah',
        'عبدالرحمن' => 'abdulrahman',
        'عبدالعزيز' => 'abdulaziz',
        'عبدالكريم' => 'abdulkarim',
        'عبداللطيف' => 'abdullatif',
        'عبدالمجيد' => 'abdulmajid',
        'عبدالهادي' => 'abdulhadi',
        'علي' => 'ali',
        'عمار' => 'ammar',
        'عمر' => 'omar',
        'عمرو' => 'amr',
        'فادي' => 'fadi',
        'فراس' => 'firas',
        'قاسم' => 'qasem',
        'كرم' => 'karam',
        'كريم' => 'karim',
        'لؤي' => 'loay',
        'مازن' => 'mazen',
        'مجد' => 'majd',
        'محمد' => 'mohammad',
        'محمود' => 'mahmoud',
        'مصطفي' => 'mustafa',
        'معاذ' => 'muath',
        'معتز' => 'moataz',
        'منذر' => 'munther',
        'مهند' => 'mohannad',
        'نادر' => 'nader',
        'ناصر' => 'nasser',
        'نبيل' => 'nabil',
        'نجم' => 'najm',
        'هادي' => 'hadi',
        'هاني' => 'hani',
        'هيثم' => 'haitham',
        'وائل' => 'wael',
        'وليد' => 'walid',
        'ياسر' => 'yasser',
        'يحيي' => 'yahya',
        'يزن' => 'yazan',
        'يوسف' => 'yusuf',

        'البكري' => 'albakri',
        'الحلبي' => 'alhalabi',
        'الحمصي' => 'alhomsi',
        'الحموي' => 'alhamwi',
        'الخير' => 'alkhair',
        'الدرويش' => 'aldarwish',
        'الشرجي' => 'alsharji',
        'الصفدي' => 'alsafadi',
        'الصالح' => 'alsaleh',
        'العشره' => 'alashara',
        'قدار' => 'qaddar',
        'حمصي' => 'homsi',
        'حموي' => 'hamwi',
        'درويش' => 'darwish',
        'صفدي' => 'safadi',
        'عرفه' => 'arafa',
        'غنام' => 'ghannam',
    ];

    /** @var array<string, string> */
    private const LETTERS = [
        'ا' => 'a',
        'ب' => 'b',
        'ت' => 't',
        'ث' => 'th',
        'ج' => 'j',
        'ح' => 'h',
        'خ' => 'kh',
        'د' => 'd',
        'ذ' => 'th',
        'ر' => 'r',
        'ز' => 'z',
        'س' => 's',
        'ش' => 'sh',
        'ص' => 's',
        'ض' => 'd',
        'ط' => 't',
        'ظ' => 'z',
        'ع' => 'a',
        'غ' => 'gh',
        'ف' => 'f',
        'ق' => 'q',
        'ك' => 'k',
        'ل' => 'l',
        'م' => 'm',
        'ن' => 'n',
        'ه' => 'h',
        'و' => 'w',
        'ي' => 'y',
        'ء' => '',
        'ؤ' => 'w',
        'ئ' => 'y',
    ];

    public static function toUsername(string $value): string
    {
        $value = self::normalizeText($value);

        if ($value === '') {
            return '';
        }

        $rawWords = preg_split('/[\s._-]+/u', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $parts = [];

        for ($index = 0, $count = count($rawWords); $index < $count; $index++) {
            $word = self::normalizeArabicWord($rawWords[$index]);

            if ($word === 'عبد' && isset($rawWords[$index + 1])) {
                $compound = $word.self::normalizeArabicWord($rawWords[$index + 1]);

                if (isset(self::WORDS[$compound])) {
                    $parts[] = self::WORDS[$compound];
                    $index++;

                    continue;
                }
            }

            $part = self::transliterateWord($word);

            if ($part !== '') {
                $parts[] = $part;
            }
        }

        return implode('.', $parts);
    }

    private static function transliterateWord(string $word): string
    {
        if (isset(self::WORDS[$word])) {
            return self::WORDS[$word];
        }

        if (! preg_match('/\p{Arabic}/u', $word)) {
            return Str::of($word)
                ->lower()
                ->replaceMatches('/[^a-z0-9]+/', '')
                ->value();
        }

        $prefix = '';

        if (str_starts_with($word, 'ال') && mb_strlen($word) > 2) {
            $prefix = 'al';
            $word = mb_substr($word, 2);
        }

        return Str::of($prefix.strtr($word, self::LETTERS))
            ->replaceMatches('/[^a-z0-9]+/', '')
            ->value();
    }

    private static function normalizeText(string $value): string
    {
        $value = trim($value);
        $value = preg_replace('/[\x{064B}-\x{065F}\x{0670}\x{06D6}-\x{06ED}]/u', '', $value) ?? $value;

        return strtr($value, [
            'ـ' => '',
            '٠' => '0',
            '١' => '1',
            '٢' => '2',
            '٣' => '3',
            '٤' => '4',
            '٥' => '5',
            '٦' => '6',
            '٧' => '7',
            '٨' => '8',
            '٩' => '9',
            '۰' => '0',
            '۱' => '1',
            '۲' => '2',
            '۳' => '3',
            '۴' => '4',
            '۵' => '5',
            '۶' => '6',
            '۷' => '7',
            '۸' => '8',
            '۹' => '9',
        ]);
    }

    private static function normalizeArabicWord(string $word): string
    {
        return strtr($word, [
            'أ' => 'ا',
            'إ' => 'ا',
            'آ' => 'ا',
            'ٱ' => 'ا',
            'ة' => 'ه',
            'ى' => 'ي',
        ]);
    }
}
