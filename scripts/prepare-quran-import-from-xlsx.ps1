[CmdletBinding()]
param(
    [string] $InputDirectory = 'C:\جامع الخير\to import',
    [string] $OutputDirectory = '.\storage\app\legacy-quran-import-live'
)

$ErrorActionPreference = 'Stop'

function Resolve-AbsolutePath {
    param([string] $Path)

    if ([System.IO.Path]::IsPathRooted($Path)) {
        return $Path
    }

    return [System.IO.Path]::GetFullPath((Join-Path (Get-Location) $Path))
}

function Get-CellText {
    param(
        [Parameter(Mandatory = $true)] $Worksheet,
        [Parameter(Mandatory = $true)] [int] $Row,
        [Parameter(Mandatory = $true)] [int] $Column
    )

    $text = $Worksheet.Cells.Item($Row, $Column).Text

    if ($null -eq $text) {
        return ''
    }

    return ([string] $text).Trim()
}

function Read-SheetRows {
    param(
        [Parameter(Mandatory = $true)] $Worksheet,
        [int] $HeaderRow,
        [int] $MaxColumns = 20
    )

    $headers = @()
    for ($col = 1; $col -le $MaxColumns; $col++) {
        $header = Get-CellText -Worksheet $Worksheet -Row $HeaderRow -Column $col
        if ($header -eq '') {
            break
        }
        $headers += $header
    }

    if ($headers.Count -eq 0) {
        throw "No headers found on row $HeaderRow in worksheet '$($Worksheet.Name)'."
    }

    $rows = @()
    $row = $HeaderRow + 1
    while ($true) {
        $item = [ordered]@{}
        $hasValue = $false

        for ($index = 0; $index -lt $headers.Count; $index++) {
            $col = $index + 1
            $value = Get-CellText -Worksheet $Worksheet -Row $row -Column $col
            if ($value -ne '') {
                $hasValue = $true
            }
            $item[$headers[$index]] = $value
        }

        if (-not $hasValue) {
            break
        }

        $rows += [pscustomobject] $item
        $row++
    }

    return ,@($rows)
}

function Require-Columns {
    param(
        [Parameter(Mandatory = $true)] [object[]] $Rows,
        [Parameter(Mandatory = $true)] [string[]] $Columns,
        [string] $Label
    )

    if ($Rows.Count -eq 0) {
        throw "$Label is empty."
    }

    $available = $Rows[0].PSObject.Properties.Name
    foreach ($column in $Columns) {
        if ($available -notcontains $column) {
            throw "$Label is missing required column '$column'. Found: $($available -join ', ')"
        }
    }
}

function Require-MinimumColumns {
    param(
        [Parameter(Mandatory = $true)] [object[]] $Rows,
        [int] $Minimum,
        [string] $Label
    )

    if ($Rows.Count -eq 0) {
        throw "$Label is empty."
    }

    $available = $Rows[0].PSObject.Properties.Name
    if ($available.Count -lt $Minimum) {
        throw "$Label expected at least $Minimum columns but found only $($available.Count)."
    }

    return ,@($available)
}

$inputPath = Resolve-AbsolutePath $InputDirectory
$outputPath = Resolve-AbsolutePath $OutputDirectory

if (-not (Test-Path -LiteralPath $inputPath -PathType Container)) {
    throw "Input directory not found: $inputPath"
}

New-Item -ItemType Directory -Force -Path $outputPath | Out-Null

$pagesFile = Join-Path $inputPath 'pages_rows.xlsx'
$ajzaFile = Join-Path $inputPath 'ajza.xlsx'

if (-not (Test-Path -LiteralPath $pagesFile -PathType Leaf)) {
    throw "Missing file: $pagesFile"
}

if (-not (Test-Path -LiteralPath $ajzaFile -PathType Leaf)) {
    throw "Missing file: $ajzaFile"
}

$excel = New-Object -ComObject Excel.Application
$excel.Visible = $false
$excel.DisplayAlerts = $false

try {
    $pagesWorkbook = $excel.Workbooks.Open($pagesFile)
    $pagesSheet = $pagesWorkbook.Worksheets.Item(1)
    $pageRows = Read-SheetRows -Worksheet $pagesSheet -HeaderRow 2
    Require-Columns -Rows $pageRows -Columns @('full_name', 'page', 'listen_date', 'listener_name', 'Courses_Name') -Label 'pages_rows.xlsx'

    $normalizedPageRows = for ($index = 0; $index -lt $pageRows.Count; $index++) {
        [pscustomobject]@{
            record_no = $index + 1
            full_name = $pageRows[$index].full_name
            page_no = $pageRows[$index].page
            listen_date = $pageRows[$index].listen_date
            listener_name = $pageRows[$index].listener_name
            Courses_Name = $pageRows[$index].Courses_Name
        }
    }

    $pagesWorkbook.Close($false)

    $ajzaWorkbook = $excel.Workbooks.Open($ajzaFile)
    $ajzaSheet = $ajzaWorkbook.Worksheets.Item(1)
    $ajzaRows = Read-SheetRows -Worksheet $ajzaSheet -HeaderRow 1
    $ajzaHeaders = Require-MinimumColumns -Rows $ajzaRows -Minimum 8 -Label 'ajza.xlsx'

    $normalizedAjzaRows = foreach ($row in $ajzaRows) {
        [pscustomobject]@{
            record_no = $row.($ajzaHeaders[0])
            student_name = $row.($ajzaHeaders[1])
            juz_number = $row.($ajzaHeaders[2])
            listener_name = $row.($ajzaHeaders[3])
            tested_on = $row.($ajzaHeaders[4])
            evaluation = $row.($ajzaHeaders[5])
            course_name = $row.($ajzaHeaders[6])
            score = $row.($ajzaHeaders[7])
            awqaf_exam_name = if ($ajzaHeaders.Count -ge 9) { $row.($ajzaHeaders[8]) } else { '' }
            awqaf_exam_result = if ($ajzaHeaders.Count -ge 10) { $row.($ajzaHeaders[9]) } else { '' }
        }
    }

    $ajzaWorkbook.Close($false)
} finally {
    if ($pagesWorkbook) { $pagesWorkbook = $null }
    if ($ajzaWorkbook) { $ajzaWorkbook = $null }
    $excel.Quit()
    [System.Runtime.Interopservices.Marshal]::ReleaseComObject($excel) | Out-Null
    [gc]::Collect()
    [gc]::WaitForPendingFinalizers()
}

$entreCsv = Join-Path $outputPath 'entre.csv'
$ajzaCsv = Join-Path $outputPath 'ajza.csv'

$normalizedPageRows | Export-Csv -LiteralPath $entreCsv -NoTypeInformation -Encoding UTF8
$normalizedAjzaRows | Export-Csv -LiteralPath $ajzaCsv -NoTypeInformation -Encoding UTF8

Write-Output "Prepared import files:"
Write-Output " - $entreCsv ($($normalizedPageRows.Count) rows)"
Write-Output " - $ajzaCsv ($($normalizedAjzaRows.Count) rows)"
