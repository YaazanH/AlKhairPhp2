@php
    $logo = 'data:image/jpeg;base64,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';
    $logo = $logoImage ?? null;
    $teacherName = $group->teacher ? trim($group->teacher->first_name.' '.$group->teacher->last_name) : null;
    $groupLine = trim(implode(' - ', array_filter([
        $group->name,
        $teacherName,
    ])));
@endphp
<!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 35mm 10mm 18mm; header: roster-header; footer: roster-footer; }
        body {
            color: #000;
            direction: rtl;
            font-family: dubai, sans-serif;
            font-size: 11px;
            line-height: 1.35;
        }

        .page-header {
            background: #cfe7d6;
            border: 0;
            padding: 2mm 3mm;
        }

        .title-row {
            background: #cfe7d6;
            border-collapse: collapse;
            table-layout: fixed;
            width: 100%;
        }

        .logo {
            text-align: right;
            vertical-align: middle;
            width: 22%;
        }

        .logo img {
            height: auto;
            margin: 1mm;
            max-height: 23mm;
            max-width: 35mm;
            width: auto;
        }

        .header-copy {
            text-align: center;
            vertical-align: middle;
            width: 56%;
        }

        h1 {
            font-size: 20px;
            font-weight: bold;
            margin: 0;
            text-align: center;
        }

        .group-line {
            font-size: 13px;
            font-weight: 500;
            margin-top: 1mm;
            text-align: center;
        }

        .header-spacer { width: 22%; }

        .title-row td {
            border: 0;
        }

        .meta-label {
            font-weight: bold;
        }

        table {
            border-collapse: collapse;
            table-layout: fixed;
            width: 100%;
        }

        thead {
            display: table-header-group;
        }

        th {
            background: #dfece2;
            border: 1px solid #aebbb1;
            border-bottom: 3px double #aebbb1;
            font-size: 11.5px;
            font-weight: bold;
            padding: 7px 4px;
            text-align: center;
        }

        .roster-page-gap th {
            background: #fff;
            border: 0;
            font-size: 0;
            height: 4mm;
            line-height: 4mm;
            padding: 0;
        }

        td {
            border: 1px solid #aebbb1;
            font-size: 10.5px;
            padding: 7px 4px;
            text-align: center;
            vertical-align: middle;
        }

        tbody tr:nth-child(even) td {
            background: #f1f7f2;
        }

        .name {
            font-weight: bold;
            text-align: right;
        }

        .empty {
            color: #666;
            padding: 34px 8px;
            text-align: center;
        }

        .footer {
            background: #cfe7d6;
            color: #555;
            font-size: 9px;
            padding: 2mm 0;
            text-align: center;
        }
    </style>
</head>
<body>
    <htmlpageheader name="roster-header"><div class="page-header"><table class="title-row" dir="ltr"><tr><td class="header-spacer"></td><td class="header-copy" dir="rtl"><h1>قائمة بيانات الطلاب</h1><div class="group-line">{{ $groupLine !== '' ? $groupLine : $group->name }}</div></td><td class="logo" dir="rtl">@if ($logo)<img src="{{ $logo }}" alt="">@endif</td></tr></table></div></htmlpageheader>
    <htmlpagefooter name="roster-footer"><div class="footer">صفحة {PAGENO} من {nbpg}</div></htmlpagefooter>

    <table class="report-table">
        <thead>
            <tr class="roster-page-gap"><th colspan="7">&nbsp;</th></tr>
            <tr>
                <th style="width: 13%;">رقم الطالب</th>
                <th style="width: 18%;">اسم الطالب</th>
                <th style="width: 15%;">الصف</th>
                <th style="width: 10%;">الجزء</th>
                <th style="width: 15%;">جوال الطالب</th>
                <th style="width: 16%;">جوال الأب</th>
                <th style="width: 13%;">الرقم الأرضي</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($enrollments as $enrollment)
                @php
                    $student = $enrollment->student;
                    $parent = $student?->parentProfile;
                    $juz = $student?->quranCurrentJuz;
                @endphp
                <tr>
                    <td>{{ $student?->student_number ?: '-' }}</td>
                    <td class="name">{{ $student?->full_name ?: '-' }}</td>
                    <td>{{ $student?->gradeLevel?->name ?: '-' }}</td>
                    <td>{{ $juz?->juz_number ?: '-' }}</td>
                    <td dir="ltr">{{ $student?->user?->phone ?: '-' }}</td>
                    <td dir="ltr">{{ $parent?->father_phone ?: ($parent?->mother_phone ?: '-') }}</td>
                    <td dir="ltr">{{ $parent?->home_phone ?: '-' }}</td>
                </tr>
            @empty
                <tr>
                    <td class="empty" colspan="7">لا توجد بيانات طلاب في هذه المجموعة.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

</body>
</html>
