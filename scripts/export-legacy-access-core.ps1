param(
    [Parameter(Mandatory = $true)]
    [string]$DatabasePath,

    [string]$OutputPath = ".\storage\app\legacy-access-export"
)

$ErrorActionPreference = 'Stop'

function Convert-LegacyPhoneValue {
    param(
        [AllowNull()]
        [object]$Value
    )

    if ($null -eq $Value -or $Value -is [System.DBNull]) {
        return $null
    }

    $text = [string]$Value
    $text = $text.Trim()

    if ($text -eq '') {
        return $null
    }

    if ($text -match '^[+-]?\d+(\.\d+)?[eE][+-]?\d+$') {
        try {
            $number = [decimal]::Parse(
                $text,
                [System.Globalization.NumberStyles]::Float,
                [System.Globalization.CultureInfo]::InvariantCulture
            )

            return $number.ToString('0', [System.Globalization.CultureInfo]::InvariantCulture)
        }
        catch {
            return $text
        }
    }

    return $text
}

if (-not (Test-Path -LiteralPath $DatabasePath)) {
    throw "Access database not found: $DatabasePath"
}

$resolvedOutput = [System.IO.Path]::GetFullPath($OutputPath)
New-Item -ItemType Directory -Path $resolvedOutput -Force | Out-Null

$tables = @(
    @{ Name = 'Names'; File = 'names.csv' },
    @{ Name = 'Teachers'; File = 'teachers.csv' },
    @{ Name = 'Courses_Name'; File = 'courses_name.csv' },
    @{ Name = 'Groups'; File = 'groups.csv' },
    @{ Name = 'Courses'; File = 'courses.csv' }
)

$connectionString = "Provider=Microsoft.ACE.OLEDB.12.0;Data Source=$DatabasePath;Persist Security Info=False;"
$connection = New-Object System.Data.OleDb.OleDbConnection($connectionString)

try {
    $connection.Open()

    foreach ($table in $tables) {
        $command = $connection.CreateCommand()
        $command.CommandText = "SELECT * FROM [$($table.Name)]"

        $adapter = New-Object System.Data.OleDb.OleDbDataAdapter($command)
        $dataTable = New-Object System.Data.DataTable
        [void]$adapter.Fill($dataTable)

        $rows = foreach ($row in $dataTable.Rows) {
            $record = [ordered]@{}

            foreach ($column in $dataTable.Columns) {
                $value = $row[$column.ColumnName]

                if ($column.ColumnName -in @('father_mob', 'student_mob', 'home_tel')) {
                    $value = Convert-LegacyPhoneValue -Value $value
                }

                $record[$column.ColumnName] = $value
            }

            [pscustomobject]$record
        }

        $destination = Join-Path $resolvedOutput $table.File
        $rows | Export-Csv -Path $destination -NoTypeInformation -Encoding UTF8
        Write-Output "Exported $($dataTable.Rows.Count) rows from $($table.Name) to $destination"
    }
}
finally {
    if ($connection.State -eq [System.Data.ConnectionState]::Open) {
        $connection.Close()
    }
}
