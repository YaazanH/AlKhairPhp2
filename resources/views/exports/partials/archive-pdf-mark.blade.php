@php
    $withKashidas = static fn (string $text, int $count = 1): string => preg_replace(
        '/(?<=[بتثجحخسشصضطظعغفقكلمنهئي])(?=[\p{Arabic}])/u',
        str_repeat('ـ', $count),
        $text,
    ) ?? $text;
    $archiveTitle = $withKashidas(__('course_archive_overview.pdf_mark.title'), 24);
@endphp

<div
    dir="rtl"
    data-archive-pdf-mark
    style="width:32mm;margin:0 auto;color:#a65a2e;font-family:dubai,sans-serif;line-height:1.25;text-align:justify;text-align-last:justify;text-justify:kashida;"
>
    <div data-archive-pdf-mark-title style="font-size:12px;font-weight:bold;white-space:nowrap;">{{ $archiveTitle }}</div>
    <div style="margin-top:1mm;font-size:9px;font-weight:500;white-space:nowrap;">{{ $withKashidas($course->name) }}</div>
</div>
