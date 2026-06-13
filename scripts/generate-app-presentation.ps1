param(
    [string]$OutputPath = (Join-Path (Join-Path (Split-Path -Parent $PSScriptRoot) 'docs\presentations') 'AlKhair-App-Overview.pptx'),
    [switch]$ExportPdf
)

$ErrorActionPreference = 'Stop'

Add-Type -AssemblyName System.Drawing

$repoRoot = Split-Path -Parent $PSScriptRoot
$visualIdentityPath = Join-Path $repoRoot 'visual identity'
$logoPath = Join-Path $visualIdentityPath 'logo.jpeg'
$coverPhotoPath = Join-Path $visualIdentityPath 'WhatsApp Image 2026-03-22 at 2.14.18 PM.jpeg'
$communityPhotoPath = Join-Path $visualIdentityPath 'WhatsApp Image 2026-03-22 at 2.14.17 PM.jpeg'

foreach ($assetPath in @($logoPath, $coverPhotoPath, $communityPhotoPath)) {
    if (-not (Test-Path -LiteralPath $assetPath)) {
        throw "Missing presentation asset: $assetPath"
    }
}

$outputDirectory = Split-Path -Parent $OutputPath
New-Item -ItemType Directory -Force -Path $outputDirectory | Out-Null

$slideWidth = 960
$slideHeight = 540

function New-RgbColor {
    param(
        [int]$Red,
        [int]$Green,
        [int]$Blue
    )

    return $Red + ($Green * 256) + ($Blue * 65536)
}

$palette = @{
    DarkGreen = New-RgbColor 0 71 31
    Green = New-RgbColor 0 107 45
    Emerald = New-RgbColor 11 143 67
    Gold = New-RgbColor 187 151 78
    Cream = New-RgbColor 245 241 232
    Pale = New-RgbColor 232 242 236
    Ink = New-RgbColor 34 48 34
    Muted = New-RgbColor 89 104 91
    White = New-RgbColor 255 255 255
    SoftGray = New-RgbColor 214 220 216
}

function Set-SolidFill {
    param(
        $Shape,
        [int]$Color,
        [double]$Transparency = 0
    )

    $Shape.Fill.Visible = -1
    $Shape.Fill.Solid()
    $Shape.Fill.ForeColor.RGB = $Color
    $Shape.Fill.Transparency = $Transparency
}

function Set-ShapeLine {
    param(
        $Shape,
        [Nullable[int]]$Color = $null,
        [double]$Weight = 1,
        [double]$Transparency = 0
    )

    if ($null -eq $Color) {
        $Shape.Line.Visible = 0
        return
    }

    $Shape.Line.Visible = -1
    $Shape.Line.ForeColor.RGB = $Color
    $Shape.Line.Weight = $Weight
    $Shape.Line.Transparency = $Transparency
}

function Add-Rectangle {
    param(
        $Slide,
        [double]$Left,
        [double]$Top,
        [double]$Width,
        [double]$Height,
        [int]$FillColor,
        [Nullable[int]]$LineColor = $null,
        [double]$LineWeight = 1,
        [double]$Transparency = 0,
        [int]$ShapeType = 1
    )

    $shape = $Slide.Shapes.AddShape($ShapeType, $Left, $Top, $Width, $Height)
    Set-SolidFill -Shape $shape -Color $FillColor -Transparency $Transparency
    Set-ShapeLine -Shape $shape -Color $LineColor -Weight $LineWeight
    return $shape
}

function Add-TextBox {
    param(
        $Slide,
        [double]$Left,
        [double]$Top,
        [double]$Width,
        [double]$Height,
        [string]$Text,
        [string]$FontName = 'Aptos',
        [double]$FontSize = 18,
        [int]$FontColor = 0,
        [switch]$Bold,
        [switch]$Italic,
        [int]$ParagraphAlignment = 1,
        [double]$MarginLeft = 4,
        [double]$MarginRight = 4,
        [double]$MarginTop = 4,
        [double]$MarginBottom = 4
    )

    $shape = $Slide.Shapes.AddTextbox(1, $Left, $Top, $Width, $Height)
    $shape.TextFrame.MarginLeft = $MarginLeft
    $shape.TextFrame.MarginRight = $MarginRight
    $shape.TextFrame.MarginTop = $MarginTop
    $shape.TextFrame.MarginBottom = $MarginBottom
    $shape.TextFrame.WordWrap = -1
    $shape.TextFrame.AutoSize = 0
    $range = $shape.TextFrame.TextRange
    $range.Text = $Text
    $range.Font.Name = $FontName
    $range.Font.Size = $FontSize
    $range.Font.Color.RGB = $FontColor
    $range.Font.Bold = if ($Bold.IsPresent) { -1 } else { 0 }
    $range.Font.Italic = if ($Italic.IsPresent) { -1 } else { 0 }
    $range.ParagraphFormat.Alignment = $ParagraphAlignment
    $shape.Line.Visible = 0
    return $shape
}

function Add-PictureContain {
    param(
        $Slide,
        [string]$Path,
        [double]$Left,
        [double]$Top,
        [double]$Width,
        [double]$Height
    )

    $picture = $Slide.Shapes.AddPicture($Path, $false, $true, 0, 0, -1, -1)
    $picture.LockAspectRatio = -1

    $scale = [Math]::Min($Width / $picture.Width, $Height / $picture.Height)
    $picture.Width = $picture.Width * $scale
    $picture.Height = $picture.Height * $scale
    $picture.Left = $Left + (($Width - $picture.Width) / 2)
    $picture.Top = $Top + (($Height - $picture.Height) / 2)

    return $picture
}

function Add-Footer {
    param(
        $Slide,
        [string]$SlideLabel
    )

    Add-TextBox -Slide $Slide -Left 42 -Top 507 -Width 420 -Height 18 `
        -Text "Generated from the current AlKhairPhp codebase | 2026-05-14" `
        -FontName 'Aptos' -FontSize 9 -FontColor $palette.Muted | Out-Null

    Add-TextBox -Slide $Slide -Left 840 -Top 506 -Width 80 -Height 20 `
        -Text $SlideLabel -FontName 'Aptos' -FontSize 10 -FontColor $palette.Green `
        -ParagraphAlignment 3 | Out-Null
}

function Add-SectionHeader {
    param(
        $Slide,
        [string]$Eyebrow,
        [string]$Title,
        [string]$Subtitle
    )

    Add-Rectangle -Slide $Slide -Left 0 -Top 0 -Width $slideWidth -Height $slideHeight -FillColor $palette.Cream | Out-Null
    Add-Rectangle -Slide $Slide -Left 0 -Top 0 -Width $slideWidth -Height 74 -FillColor $palette.DarkGreen | Out-Null
    Add-Rectangle -Slide $Slide -Left 0 -Top 74 -Width $slideWidth -Height 8 -FillColor $palette.Gold | Out-Null
    Add-Rectangle -Slide $Slide -Left 756 -Top 26 -Width 156 -Height 22 -FillColor $palette.Emerald -Transparency 0.1 -ShapeType 5 | Out-Null

    Add-TextBox -Slide $Slide -Left 42 -Top 18 -Width 180 -Height 16 `
        -Text $Eyebrow -FontName 'Aptos' -FontSize 10 -FontColor $palette.Pale | Out-Null

    Add-TextBox -Slide $Slide -Left 42 -Top 30 -Width 590 -Height 30 `
        -Text $Title -FontName 'Bahnschrift SemiBold' -FontSize 24 -FontColor $palette.White -Bold | Out-Null

    Add-TextBox -Slide $Slide -Left 42 -Top 86 -Width 640 -Height 36 `
        -Text $Subtitle -FontName 'Aptos' -FontSize 12 -FontColor $palette.Muted | Out-Null
}

function Add-Card {
    param(
        $Slide,
        [double]$Left,
        [double]$Top,
        [double]$Width,
        [double]$Height,
        [string]$Title,
        [string]$Body,
        [int]$AccentColor
    )

    Add-Rectangle -Slide $Slide -Left $Left -Top $Top -Width $Width -Height $Height `
        -FillColor $palette.White -LineColor $palette.SoftGray -LineWeight 1 -ShapeType 5 | Out-Null
    Add-Rectangle -Slide $Slide -Left ($Left + 18) -Top ($Top + 18) -Width 34 -Height 6 `
        -FillColor $AccentColor -ShapeType 5 | Out-Null
    Add-TextBox -Slide $Slide -Left ($Left + 18) -Top ($Top + 28) -Width ($Width - 36) -Height 22 `
        -Text $Title -FontName 'Bahnschrift SemiBold' -FontSize 15 -FontColor $palette.Ink -Bold | Out-Null
    Add-TextBox -Slide $Slide -Left ($Left + 18) -Top ($Top + 56) -Width ($Width - 36) -Height ($Height - 70) `
        -Text $Body -FontName 'Aptos' -FontSize 11.5 -FontColor $palette.Muted | Out-Null
}

function Add-Step {
    param(
        $Slide,
        [double]$Left,
        [double]$Top,
        [double]$Width,
        [double]$Height,
        [string]$Number,
        [string]$Title,
        [string]$Body
    )

    Add-Rectangle -Slide $Slide -Left $Left -Top $Top -Width $Width -Height $Height `
        -FillColor $palette.White -LineColor $palette.SoftGray -LineWeight 1 -ShapeType 5 | Out-Null
    Add-Rectangle -Slide $Slide -Left ($Left + 14) -Top ($Top + 14) -Width 34 -Height 34 `
        -FillColor $palette.Green -ShapeType 5 | Out-Null
    Add-TextBox -Slide $Slide -Left ($Left + 15) -Top ($Top + 17) -Width 32 -Height 24 `
        -Text $Number -FontName 'Bahnschrift SemiBold' -FontSize 16 -FontColor $palette.White -Bold -ParagraphAlignment 2 | Out-Null
    Add-TextBox -Slide $Slide -Left ($Left + 58) -Top ($Top + 15) -Width ($Width - 70) -Height 24 `
        -Text $Title -FontName 'Bahnschrift SemiBold' -FontSize 14 -FontColor $palette.Ink -Bold | Out-Null
    Add-TextBox -Slide $Slide -Left ($Left + 15) -Top ($Top + 56) -Width ($Width - 30) -Height ($Height - 68) `
        -Text $Body -FontName 'Aptos' -FontSize 10.5 -FontColor $palette.Muted | Out-Null
}

function Release-ComObject {
    param($Object)

    if ($null -ne $Object) {
        [void][System.Runtime.InteropServices.Marshal]::FinalReleaseComObject($Object)
    }
}

$powerPoint = $null
$presentation = $null

try {
    $powerPoint = New-Object -ComObject PowerPoint.Application
    $powerPoint.Visible = -1
    $presentation = $powerPoint.Presentations.Add()
    $presentation.PageSetup.SlideWidth = $slideWidth
    $presentation.PageSetup.SlideHeight = $slideHeight

    $slide = $presentation.Slides.Add(1, 12)
    Add-Rectangle -Slide $slide -Left 0 -Top 0 -Width $slideWidth -Height $slideHeight -FillColor $palette.DarkGreen | Out-Null
    Add-Rectangle -Slide $slide -Left 520 -Top 0 -Width 440 -Height $slideHeight -FillColor $palette.Green | Out-Null
    Add-Rectangle -Slide $slide -Left 36 -Top 36 -Width 18 -Height 420 -FillColor $palette.Gold -Transparency 0.12 | Out-Null
    Add-Rectangle -Slide $slide -Left 480 -Top 42 -Width 426 -Height 438 -FillColor $palette.White -Transparency 0.92 -ShapeType 5 | Out-Null
    Add-PictureContain -Slide $slide -Path $coverPhotoPath -Left 500 -Top 58 -Width 392 -Height 392 | Out-Null
    Add-PictureContain -Slide $slide -Path $logoPath -Left 62 -Top 54 -Width 92 -Height 92 | Out-Null
    Add-TextBox -Slide $slide -Left 62 -Top 166 -Width 370 -Height 60 `
        -Text 'AlKhair App' -FontName 'Bahnschrift SemiBold' -FontSize 28 -FontColor $palette.White -Bold | Out-Null
    Add-TextBox -Slide $slide -Left 62 -Top 226 -Width 360 -Height 76 `
        -Text 'A management platform for Quran education, community operations, and finance.' `
        -FontName 'Aptos' -FontSize 18 -FontColor $palette.Cream | Out-Null
    Add-TextBox -Slide $slide -Left 62 -Top 320 -Width 360 -Height 60 `
        -Text "Built from the current Laravel 12 + Livewire codebase in AlKhairPhp.`r`nFocus: students, memorization, operations, and reporting." `
        -FontName 'Aptos' -FontSize 12.5 -FontColor $palette.Pale | Out-Null
    Add-Rectangle -Slide $slide -Left 62 -Top 404 -Width 236 -Height 44 -FillColor $palette.Emerald -Transparency 0.08 -ShapeType 5 | Out-Null
    Add-TextBox -Slide $slide -Left 74 -Top 413 -Width 214 -Height 24 `
        -Text 'Presentation overview deck' -FontName 'Bahnschrift SemiBold' -FontSize 12 -FontColor $palette.White -Bold -ParagraphAlignment 2 | Out-Null
    Add-Footer -Slide $slide -SlideLabel '01'

    $slide = $presentation.Slides.Add(2, 12)
    Add-SectionHeader -Slide $slide -Eyebrow 'Product Summary' -Title 'What the app mainly does' `
        -Subtitle 'The current repository implements a single system for running the daily academic and administrative workflow of AlKhair.'
    Add-Rectangle -Slide $slide -Left 42 -Top 136 -Width 412 -Height 278 -FillColor $palette.White -LineColor $palette.SoftGray -ShapeType 5 | Out-Null
    Add-TextBox -Slide $slide -Left 66 -Top 160 -Width 360 -Height 52 `
        -Text 'Main functionality:' -FontName 'Bahnschrift SemiBold' -FontSize 19 -FontColor $palette.Green -Bold | Out-Null
    Add-TextBox -Slide $slide -Left 66 -Top 196 -Width 350 -Height 170 `
        -Text "Run the end-to-end operations of a Quran and mosque education center in one place.`r`n`r`n- People and access`r`n- Teaching groups and enrollments`r`n- Daily learning and attendance tracking`r`n- Family billing, activities, and finance" `
        -FontName 'Aptos' -FontSize 14 -FontColor $palette.Ink | Out-Null
    Add-Rectangle -Slide $slide -Left 486 -Top 136 -Width 430 -Height 278 -FillColor $palette.Pale -LineColor $palette.SoftGray -ShapeType 5 | Out-Null
    Add-TextBox -Slide $slide -Left 512 -Top 158 -Width 374 -Height 188 `
        -Text "The repo shows this scope clearly:`r`n`r`n• students, parents, teachers, users, and permissions`r`n• courses, groups, schedules, and enrollments`r`n• memorization sessions, Quran tests, assessments, and points`r`n• invoices, payments, finance requests, cash boxes, and reports`r`n• website pages, ID cards, print templates, barcodes, and APIs" `
        -FontName 'Aptos' -FontSize 13 -FontColor $palette.Ink | Out-Null
    Add-Rectangle -Slide $slide -Left 512 -Top 358 -Width 242 -Height 36 -FillColor $palette.Green -Transparency 0.05 -ShapeType 5 | Out-Null
    Add-TextBox -Slide $slide -Left 524 -Top 366 -Width 220 -Height 20 `
        -Text 'Not a generic CMS or only a website' -FontName 'Aptos' -FontSize 11 -FontColor $palette.Green -Bold | Out-Null
    Add-Footer -Slide $slide -SlideLabel '02'

    $slide = $presentation.Slides.Add(3, 12)
    Add-SectionHeader -Slide $slide -Eyebrow 'Feature Map' -Title 'Main modules in the app' `
        -Subtitle 'These are the major functional areas that appear in routes, models, Livewire screens, and services.'
    Add-Card -Slide $slide -Left 42 -Top 132 -Width 274 -Height 112 -Title 'People and access' `
        -Body "Users, roles, permissions, parents, teachers, students, profile photos, and scoped dashboards." -AccentColor $palette.Green
    Add-Card -Slide $slide -Left 342 -Top 132 -Width 274 -Height 112 -Title 'Academic structure' `
        -Body "Courses, running groups, schedules, academic years, grade levels, and student enrollments." -AccentColor $palette.Emerald
    Add-Card -Slide $slide -Left 642 -Top 132 -Width 274 -Height 112 -Title 'Quran tracking' `
        -Body "Page-level memorization, quick entry, partial tests, final tests, awqaf tests, and progression rules." -AccentColor $palette.Gold
    Add-Card -Slide $slide -Left 42 -Top 264 -Width 274 -Height 112 -Title 'Attendance and performance' `
        -Body "Student attendance, teacher attendance, assessments, results, points ledger, and student notes." -AccentColor $palette.Green
    Add-Card -Slide $slide -Left 342 -Top 264 -Width 274 -Height 112 -Title 'Finance and activities' `
        -Body "Invoices, receipts, payments, activity registrations, request workflows, cash boxes, transfers, and exchange." -AccentColor $palette.Emerald
    Add-Card -Slide $slide -Left 642 -Top 264 -Width 274 -Height 112 -Title 'Identity, website, and API' `
        -Body "ID cards, print templates, barcodes, bilingual public website, API tokens, and integration endpoints." -AccentColor $palette.Gold
    Add-Rectangle -Slide $slide -Left 42 -Top 404 -Width 874 -Height 72 -FillColor $palette.White -LineColor $palette.SoftGray -ShapeType 5 | Out-Null
    Add-TextBox -Slide $slide -Left 62 -Top 424 -Width 832 -Height 32 `
        -Text 'The strongest center of gravity is the combination of student operations, Quran progress tracking, and finance.' `
        -FontName 'Bahnschrift SemiBold' -FontSize 15 -FontColor $palette.Ink -Bold -ParagraphAlignment 2 | Out-Null
    Add-Footer -Slide $slide -SlideLabel '03'

    $slide = $presentation.Slides.Add(4, 12)
    Add-SectionHeader -Slide $slide -Eyebrow 'Core Workflow' -Title 'How the main business flow works' `
        -Subtitle 'The application is organized around the journey from student onboarding to daily tracking, evaluation, and billing.'
    Add-Step -Slide $slide -Left 42 -Top 156 -Width 132 -Height 160 -Number '01' -Title 'Register' `
        -Body 'Create parent, teacher, and student records. Assign permissions and profile data.'
    Add-Step -Slide $slide -Left 194 -Top 156 -Width 132 -Height 160 -Number '02' -Title 'Set up' `
        -Body 'Set up courses, groups, schedules, academic year context, and active enrollments.'
    Add-Step -Slide $slide -Left 346 -Top 156 -Width 132 -Height 160 -Number '03' -Title 'Track' `
        -Body 'Capture attendance, memorization sessions, Quran tests, and supporting notes.'
    Add-Step -Slide $slide -Left 498 -Top 156 -Width 132 -Height 160 -Number '04' -Title 'Assess' `
        -Body 'Record assessments, exam results, pass or fail status, and progression milestones.'
    Add-Step -Slide $slide -Left 650 -Top 156 -Width 132 -Height 160 -Number '05' -Title 'Reward' `
        -Body 'Apply point policies, manual adjustments, and cached progress summaries.'
    Add-Step -Slide $slide -Left 802 -Top 156 -Width 114 -Height 160 -Number '06' -Title 'Bill' `
        -Body 'Issue invoices, collect payments, manage requests, and review finance reports.'
    Add-Rectangle -Slide $slide -Left 42 -Top 360 -Width 874 -Height 96 -FillColor $palette.Pale -LineColor $palette.SoftGray -ShapeType 5 | Out-Null
    Add-TextBox -Slide $slide -Left 68 -Top 382 -Width 818 -Height 48 `
        -Text 'Presentation takeaway: the app is not a collection of unrelated CRUD screens. It models one operational lifecycle for a learning center.' `
        -FontName 'Aptos' -FontSize 15 -FontColor $palette.Ink -ParagraphAlignment 2 | Out-Null
    Add-Footer -Slide $slide -SlideLabel '04'

    $slide = $presentation.Slides.Add(5, 12)
    Add-SectionHeader -Slide $slide -Eyebrow 'Differentiators' -Title 'What makes this app specific to AlKhair' `
        -Subtitle 'The Quran workflow and learning rules are where the product becomes specialized.'
    Add-Rectangle -Slide $slide -Left 42 -Top 136 -Width 420 -Height 320 -FillColor $palette.White -LineColor $palette.SoftGray -ShapeType 5 | Out-Null
    Add-TextBox -Slide $slide -Left 66 -Top 158 -Width 370 -Height 244 `
        -Text "- Memorization is stored page by page, not as a loose summary.`r`n`r`n- Duplicate lifetime pages are detected and prevented unless explicitly overridden.`r`n`r`n- Final and awqaf tests follow progression rules based on passed earlier stages.`r`n`r`n- Student progress screens combine pages, tests, attendance, points, and notes.`r`n`r`n- A daily teacher summary API already exists for Telegram or n8n automation." `
        -FontName 'Aptos' -FontSize 13.5 -FontColor $palette.Ink | Out-Null
    Add-Rectangle -Slide $slide -Left 494 -Top 136 -Width 422 -Height 320 -FillColor $palette.Pale -LineColor $palette.SoftGray -ShapeType 5 | Out-Null
    Add-PictureContain -Slide $slide -Path $communityPhotoPath -Left 512 -Top 156 -Width 220 -Height 248 | Out-Null
    Add-Rectangle -Slide $slide -Left 748 -Top 164 -Width 144 -Height 70 -FillColor $palette.White -LineColor $palette.SoftGray -ShapeType 5 | Out-Null
    Add-TextBox -Slide $slide -Left 764 -Top 176 -Width 112 -Height 18 -Text 'Memorization' `
        -FontName 'Bahnschrift SemiBold' -FontSize 13 -FontColor $palette.Green -Bold -ParagraphAlignment 2 | Out-Null
    Add-TextBox -Slide $slide -Left 764 -Top 196 -Width 112 -Height 24 -Text 'Page-level history' `
        -FontName 'Aptos' -FontSize 11.5 -FontColor $palette.Ink -ParagraphAlignment 2 | Out-Null
    Add-Rectangle -Slide $slide -Left 748 -Top 246 -Width 144 -Height 70 -FillColor $palette.White -LineColor $palette.SoftGray -ShapeType 5 | Out-Null
    Add-TextBox -Slide $slide -Left 764 -Top 258 -Width 112 -Height 18 -Text 'Progression' `
        -FontName 'Bahnschrift SemiBold' -FontSize 13 -FontColor $palette.Emerald -Bold -ParagraphAlignment 2 | Out-Null
    Add-TextBox -Slide $slide -Left 764 -Top 278 -Width 112 -Height 24 -Text 'Rule-driven tests' `
        -FontName 'Aptos' -FontSize 11.5 -FontColor $palette.Ink -ParagraphAlignment 2 | Out-Null
    Add-Rectangle -Slide $slide -Left 748 -Top 328 -Width 144 -Height 70 -FillColor $palette.White -LineColor $palette.SoftGray -ShapeType 5 | Out-Null
    Add-TextBox -Slide $slide -Left 764 -Top 340 -Width 112 -Height 18 -Text 'Automation' `
        -FontName 'Bahnschrift SemiBold' -FontSize 13 -FontColor $palette.Gold -Bold -ParagraphAlignment 2 | Out-Null
    Add-TextBox -Slide $slide -Left 764 -Top 360 -Width 112 -Height 24 -Text 'Daily teacher digest' `
        -FontName 'Aptos' -FontSize 11.5 -FontColor $palette.Ink -ParagraphAlignment 2 | Out-Null
    Add-Footer -Slide $slide -SlideLabel '05'

    $slide = $presentation.Slides.Add(6, 12)
    Add-SectionHeader -Slide $slide -Eyebrow 'Operations' -Title 'Finance, activities, and operational support' `
        -Subtitle 'The repo contains a fuller finance layer than a basic billing module.'
    Add-Card -Slide $slide -Left 42 -Top 136 -Width 270 -Height 140 -Title 'Family billing' `
        -Body "Invoices, invoice items, payments, printable receipts, and invoice status tracking." -AccentColor $palette.Green
    Add-Card -Slide $slide -Left 342 -Top 136 -Width 270 -Height 140 -Title 'Activity finance' `
        -Body "Registrations, fee collection, expense tracking, and expected versus collected revenue." -AccentColor $palette.Emerald
    Add-Card -Slide $slide -Left 642 -Top 136 -Width 274 -Height 140 -Title 'Request workflows' `
        -Body "Pull requests, expense requests, revenue requests, review actions, posting, and settlement." -AccentColor $palette.Gold
    Add-Card -Slide $slide -Left 42 -Top 300 -Width 270 -Height 140 -Title 'Cash control' `
        -Body "Cash boxes, assigned currencies, transfers between boxes, and non-negative balance safeguards." -AccentColor $palette.Green
    Add-Card -Slide $slide -Left 342 -Top 300 -Width 270 -Height 140 -Title 'Multi-currency' `
        -Body "Currency rates, base and local equivalents, and exchange transactions with audit trails." -AccentColor $palette.Emerald
    Add-Card -Slide $slide -Left 642 -Top 300 -Width 274 -Height 140 -Title 'Operational tools' `
        -Body "Reports, exports, barcodes, print templates, and ID card generation for front-desk workflows." -AccentColor $palette.Gold
    Add-Footer -Slide $slide -SlideLabel '06'

    $slide = $presentation.Slides.Add(7, 12)
    Add-SectionHeader -Slide $slide -Eyebrow 'Access Model' -Title 'Who uses the app' `
        -Subtitle 'Dashboards and access are role-based, with scoped permissions in both web and API flows.'
    Add-Card -Slide $slide -Left 42 -Top 148 -Width 200 -Height 250 -Title 'Admin / Manager' `
        -Body "Full operational visibility.`r`n`r`nManages people, settings, reports, finance, and system-wide workflows." -AccentColor $palette.Green
    Add-Card -Slide $slide -Left 268 -Top 148 -Width 200 -Height 250 -Title 'Teacher' `
        -Body "Works on assigned groups.`r`n`r`nRecords attendance, memorization, Quran tests, assessments, and internal notes." -AccentColor $palette.Emerald
    Add-Card -Slide $slide -Left 494 -Top 148 -Width 200 -Height 250 -Title 'Parent' `
        -Body "Views family-facing data.`r`n`r`nFollows student progress, enrollments, activities, invoices, and payments." -AccentColor $palette.Gold
    Add-Card -Slide $slide -Left 720 -Top 148 -Width 196 -Height 250 -Title 'Student' `
        -Body "Uses a personal dashboard.`r`n`r`nSees personal learning progress, points, attendance, and active enrollments." -AccentColor $palette.Green
    Add-Rectangle -Slide $slide -Left 42 -Top 422 -Width 874 -Height 42 -FillColor $palette.Pale -LineColor $palette.SoftGray -ShapeType 5 | Out-Null
    Add-TextBox -Slide $slide -Left 60 -Top 433 -Width 840 -Height 18 `
        -Text 'The API also uses token-based authentication with permission checks, so integrations follow the same access model.' `
        -FontName 'Aptos' -FontSize 12 -FontColor $palette.Ink -ParagraphAlignment 2 | Out-Null
    Add-Footer -Slide $slide -SlideLabel '07'

    $slide = $presentation.Slides.Add(8, 12)
    Add-SectionHeader -Slide $slide -Eyebrow 'Close' -Title 'Suggested demo path and final message' `
        -Subtitle 'Use this order if you want to walk stakeholders through the app live after the presentation.'
    Add-Rectangle -Slide $slide -Left 42 -Top 140 -Width 438 -Height 310 -FillColor $palette.White -LineColor $palette.SoftGray -ShapeType 5 | Out-Null
    Add-TextBox -Slide $slide -Left 64 -Top 164 -Width 390 -Height 240 `
        -Text "Recommended demo flow:`r`n`r`n1. Dashboard and role-based navigation`r`n2. Students and parent records`r`n3. Groups and enrollments`r`n4. Memorization and Quran tests`r`n5. Attendance and points`r`n6. Invoices, payments, and finance reports`r`n7. Website or API integration screens" `
        -FontName 'Aptos' -FontSize 14 -FontColor $palette.Ink | Out-Null
    Add-Rectangle -Slide $slide -Left 510 -Top 140 -Width 406 -Height 310 -FillColor $palette.DarkGreen -LineColor $palette.DarkGreen -ShapeType 5 | Out-Null
    Add-PictureContain -Slide $slide -Path $logoPath -Left 636 -Top 166 -Width 152 -Height 152 | Out-Null
    Add-TextBox -Slide $slide -Left 544 -Top 330 -Width 340 -Height 76 `
        -Text 'Main takeaway: AlKhair App is the operational backbone for a Quran learning organization, combining education tracking, community workflows, and finance in one system.' `
        -FontName 'Aptos' -FontSize 16 -FontColor $palette.White -ParagraphAlignment 2 | Out-Null
    Add-Footer -Slide $slide -SlideLabel '08'

    $presentation.SaveAs($OutputPath, 24)

    if ($ExportPdf) {
        $pdfPath = [System.IO.Path]::ChangeExtension($OutputPath, '.pdf')
        $presentation.SaveAs($pdfPath, 32)
    }
}
finally {
    if ($presentation) {
        try {
            $presentation.Saved = -1
        }
        catch {
        }

        try {
            $presentation.Close()
        }
        catch {
        }
    }

    if ($powerPoint) {
        try {
            $powerPoint.Quit()
        }
        catch {
        }
    }

    Release-ComObject -Object $presentation
    Release-ComObject -Object $powerPoint
    [GC]::Collect()
    [GC]::WaitForPendingFinalizers()
}
