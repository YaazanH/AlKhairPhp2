[CmdletBinding()]
param(
    [string]$ProjectName = 'alkhairapp',
    [string]$StarterKit = 'laravel/livewire-starter-kit',
    [string]$PhpZipUrl = 'https://downloads.php.net/~windows/releases/latest/php-8.4-nts-Win32-vs17-x64-latest.zip'
)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

function Get-FullPath {
    param([string]$Path)

    return [System.IO.Path]::GetFullPath($Path)
}

function Assert-ChildPath {
    param(
        [string]$BasePath,
        [string]$CandidatePath
    )

    $resolvedBase = (Get-FullPath -Path $BasePath).TrimEnd('\') + '\'
    $resolvedCandidate = Get-FullPath -Path $CandidatePath

    if (-not $resolvedCandidate.StartsWith($resolvedBase, [System.StringComparison]::OrdinalIgnoreCase)) {
        throw "Path '$resolvedCandidate' is outside '$resolvedBase'."
    }

    return $resolvedCandidate
}

function Reset-Directory {
    param([string]$Path)

    $resolved = Assert-ChildPath -BasePath $RepoRoot -CandidatePath $Path

    if (Test-Path -LiteralPath $resolved) {
        Remove-Item -LiteralPath $resolved -Recurse -Force
    }

    New-Item -ItemType Directory -Path $resolved -Force | Out-Null

    return $resolved
}

function Update-IniSetting {
    param(
        [string]$Content,
        [string]$Pattern,
        [string]$Replacement
    )

    return [System.Text.RegularExpressions.Regex]::Replace(
        $Content,
        $Pattern,
        $Replacement,
        [System.Text.RegularExpressions.RegexOptions]::Multiline
    )
}

function Enable-PhpExtension {
    param(
        [string]$Content,
        [string]$ExtensionName
    )

    $pattern = '^\s*;?\s*extension\s*=\s*' + [System.Text.RegularExpressions.Regex]::Escape($ExtensionName) + '\s*$'

    if ([System.Text.RegularExpressions.Regex]::IsMatch($Content, $pattern, [System.Text.RegularExpressions.RegexOptions]::Multiline)) {
        return Update-IniSetting -Content $Content -Pattern $pattern -Replacement ('extension=' + $ExtensionName)
    }

    return $Content + [Environment]::NewLine + 'extension=' + $ExtensionName + [Environment]::NewLine
}

function Invoke-Checked {
    param(
        [string]$FilePath,
        [string[]]$Arguments,
        [string]$WorkingDirectory = $RepoRoot
    )

    Push-Location $WorkingDirectory

    try {
        & $FilePath @Arguments
        if ($LASTEXITCODE -ne 0) {
            throw "Command failed: $FilePath $($Arguments -join ' ')"
        }
    }
    finally {
        Pop-Location
    }
}

$RepoRoot = Get-FullPath -Path $PSScriptRoot
$ProjectRoot = Assert-ChildPath -BasePath $RepoRoot -CandidatePath (Join-Path $RepoRoot $ProjectName)
$ToolRoot = Assert-ChildPath -BasePath $RepoRoot -CandidatePath (Join-Path $RepoRoot '.alkhairapp-tools')
$PhpRoot = Assert-ChildPath -BasePath $RepoRoot -CandidatePath (Join-Path $ToolRoot 'php')
$BinRoot = Assert-ChildPath -BasePath $RepoRoot -CandidatePath (Join-Path $ToolRoot 'bin')
$ComposerHome = Assert-ChildPath -BasePath $RepoRoot -CandidatePath (Join-Path $ToolRoot 'composer-home')
$TmpRoot = Assert-ChildPath -BasePath $RepoRoot -CandidatePath (Join-Path $ToolRoot 'tmp')
$BlueprintBackupRoot = Assert-ChildPath -BasePath $RepoRoot -CandidatePath (Join-Path $ToolRoot 'blueprint-backup')

New-Item -ItemType Directory -Path $ToolRoot -Force | Out-Null
New-Item -ItemType Directory -Path $BinRoot -Force | Out-Null
New-Item -ItemType Directory -Path $ComposerHome -Force | Out-Null
New-Item -ItemType Directory -Path $TmpRoot -Force | Out-Null

$existingBlueprintItems = @('README.md', 'docs')

if (Test-Path -LiteralPath $ProjectRoot) {
    $existingItems = @(Get-ChildItem -Force -LiteralPath $ProjectRoot)
    $unexpectedItems = @($existingItems | Where-Object { $_.Name -notin $existingBlueprintItems })

    if ($unexpectedItems.Count -gt 0) {
        $unexpectedNames = $unexpectedItems.Name -join ', '
        throw "Refusing to overwrite '$ProjectRoot'. Unexpected items found: $unexpectedNames"
    }
}

$phpExe = Join-Path $PhpRoot 'php.exe'
if (-not (Test-Path -LiteralPath $phpExe)) {
    $archivePath = Join-Path $TmpRoot 'php.zip'

    Reset-Directory -Path $PhpRoot | Out-Null
    Invoke-WebRequest -UseBasicParsing -Uri $PhpZipUrl -OutFile $archivePath
    Expand-Archive -LiteralPath $archivePath -DestinationPath $PhpRoot -Force
}

if (-not (Test-Path -LiteralPath $phpExe)) {
    throw "PHP executable was not created at '$phpExe'."
}

$phpIniPath = Join-Path $PhpRoot 'php.ini'
if (-not (Test-Path -LiteralPath $phpIniPath)) {
    $phpIniTemplate = Join-Path $PhpRoot 'php.ini-production'
    if (-not (Test-Path -LiteralPath $phpIniTemplate)) {
        $phpIniTemplate = Join-Path $PhpRoot 'php.ini-development'
    }

    Copy-Item -LiteralPath $phpIniTemplate -Destination $phpIniPath -Force
}

$phpIniContent = Get-Content -LiteralPath $phpIniPath -Raw
$phpIniContent = Update-IniSetting -Content $phpIniContent -Pattern '^\s*;?\s*extension_dir\s*=.*$' -Replacement 'extension_dir = "ext"'
foreach ($extension in @('curl', 'fileinfo', 'intl', 'mbstring', 'openssl', 'pdo_mysql', 'pdo_sqlite', 'sqlite3', 'zip')) {
    $phpIniContent = Enable-PhpExtension -Content $phpIniContent -ExtensionName $extension
}
Set-Content -LiteralPath $phpIniPath -Value $phpIniContent -Encoding ASCII

Invoke-Checked -FilePath $phpExe -Arguments @('-v')

$composerPhar = Join-Path $BinRoot 'composer.phar'
if (-not (Test-Path -LiteralPath $composerPhar)) {
    $installerPath = Join-Path $TmpRoot 'composer-setup.php'
    $expectedHashResponse = Invoke-WebRequest -UseBasicParsing -Uri 'https://composer.github.io/installer.sig'
    if ($expectedHashResponse.Content -is [byte[]]) {
        $expectedHash = [System.Text.Encoding]::UTF8.GetString($expectedHashResponse.Content).Trim().ToLowerInvariant()
    }
    else {
        $expectedHash = ([string]$expectedHashResponse.Content).Trim().ToLowerInvariant()
    }

    Invoke-WebRequest -UseBasicParsing -Uri 'https://getcomposer.org/installer' -OutFile $installerPath

    $actualHash = (Get-FileHash -LiteralPath $installerPath -Algorithm SHA384).Hash.ToLowerInvariant()
    if ($expectedHash -ne $actualHash) {
        throw "Composer installer hash mismatch. Expected '$expectedHash', got '$actualHash'."
    }

    Invoke-Checked -FilePath $phpExe -Arguments @(
        $installerPath,
        "--install-dir=$BinRoot",
        '--filename=composer.phar'
    )
}

if (-not (Test-Path -LiteralPath $composerPhar)) {
    throw "Composer was not created at '$composerPhar'."
}

$composerWrapper = Join-Path $BinRoot 'composer.bat'
if (-not (Test-Path -LiteralPath $composerWrapper)) {
    @(
        '@echo off',
        '"%~dp0..\php\php.exe" "%~dp0composer.phar" %*'
    ) | Set-Content -LiteralPath $composerWrapper -Encoding ASCII
}

$env:COMPOSER_HOME = $ComposerHome
$env:PATH = "$BinRoot;$PhpRoot;$env:PATH"

if (Test-Path -LiteralPath $BlueprintBackupRoot) {
    Remove-Item -LiteralPath $BlueprintBackupRoot -Recurse -Force
}
New-Item -ItemType Directory -Path $BlueprintBackupRoot -Force | Out-Null

if (Test-Path -LiteralPath $ProjectRoot) {
    foreach ($itemName in $existingBlueprintItems) {
        $sourcePath = Join-Path $ProjectRoot $itemName
        if (Test-Path -LiteralPath $sourcePath) {
            Move-Item -LiteralPath $sourcePath -Destination (Join-Path $BlueprintBackupRoot $itemName)
        }
    }

    $remainingItems = @(Get-ChildItem -Force -LiteralPath $ProjectRoot)
    if ($remainingItems.Count -eq 0) {
        Remove-Item -LiteralPath $ProjectRoot -Force
    }
}

Invoke-Checked -FilePath $phpExe -Arguments @(
    $composerPhar,
    'create-project',
    $StarterKit,
    $ProjectRoot,
    '--prefer-dist',
    '--no-interaction'
)

$architectureDocsRoot = Join-Path $ProjectRoot 'docs\architecture'
New-Item -ItemType Directory -Path $architectureDocsRoot -Force | Out-Null

$blueprintReadme = Join-Path $BlueprintBackupRoot 'README.md'
if (Test-Path -LiteralPath $blueprintReadme) {
    Move-Item -LiteralPath $blueprintReadme -Destination (Join-Path $architectureDocsRoot 'blueprint-readme.md')
}

$blueprintDocs = Join-Path $BlueprintBackupRoot 'docs'
if (Test-Path -LiteralPath $blueprintDocs) {
    Get-ChildItem -Force -LiteralPath $blueprintDocs | Move-Item -Destination $architectureDocsRoot
}

Invoke-Checked -FilePath $phpExe -Arguments @('artisan', 'install:api', '--no-interaction') -WorkingDirectory $ProjectRoot

Invoke-Checked -FilePath $phpExe -Arguments @(
    $composerPhar,
    'require',
    'spatie/laravel-permission',
    'dedoc/scramble',
    'spatie/laravel-activitylog',
    '--no-interaction'
) -WorkingDirectory $ProjectRoot

Invoke-Checked -FilePath $phpExe -Arguments @(
    'artisan',
    'vendor:publish',
    '--provider=Spatie\Permission\PermissionServiceProvider',
    '--no-interaction'
) -WorkingDirectory $ProjectRoot

Invoke-Checked -FilePath $phpExe -Arguments @(
    'artisan',
    'vendor:publish',
    '--provider=Dedoc\Scramble\ScrambleServiceProvider',
    '--tag=scramble-config',
    '--no-interaction'
) -WorkingDirectory $ProjectRoot

Invoke-Checked -FilePath $phpExe -Arguments @(
    'artisan',
    'vendor:publish',
    '--provider=Spatie\Activitylog\ActivitylogServiceProvider',
    '--tag=activitylog-migrations',
    '--no-interaction'
) -WorkingDirectory $ProjectRoot

Invoke-Checked -FilePath $phpExe -Arguments @(
    'artisan',
    'vendor:publish',
    '--provider=Spatie\Activitylog\ActivitylogServiceProvider',
    '--tag=activitylog-config',
    '--no-interaction'
) -WorkingDirectory $ProjectRoot

Invoke-Checked -FilePath 'npm' -Arguments @('install') -WorkingDirectory $ProjectRoot
Invoke-Checked -FilePath 'npm' -Arguments @('run', 'build') -WorkingDirectory $ProjectRoot

Write-Host ''
Write-Host 'Alkhair app scaffold completed.'
Write-Host "Project root: $ProjectRoot"
Write-Host "PHP executable: $phpExe"
Write-Host "Composer PHAR: $composerPhar"
