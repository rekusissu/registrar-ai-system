<#
.SYNOPSIS
    Fails if any source file starts with a UTF-8 BOM (EF BB BF).

.DESCRIPTION
    A UTF-8 BOM before the opening <?php makes PHP emit three invisible
    bytes as output before any code runs. That breaks every header() call
    in the file - "Cannot modify header information - headers already sent" -
    so session redirects are silently discarded and the page renders with
    no HTML at all: a blank white screen with no sidebar.

    On a dev box APP_ENV is usually unset, so display_errors=1 and the
    warning is visible. On the live host APP_ENV=production silences
    display_errors (shared/config.php:153), so the same defect appears as a
    completely blank page with no explanation.

    The BOM is easy to introduce by accident: PowerShell 5.1's
    Set-Content -Encoding UTF8 writes one, as does some editors. It is
    invisible in most diffs, which is how a single page shipped broken.

.PARAMETER Path
    Optional subdirectory to scan. Defaults to the repository root.

.EXAMPLE
    powershell -File scripts/check-bom.ps1
    powershell -File scripts/check-bom.ps1 -Path registrar
#>
[CmdletBinding()]
param(
    [string]$Path = '.'
)

$ErrorActionPreference = 'Stop'
$repoRoot = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
Set-Location $repoRoot

# Directories that hold third-party or generated code we do not control.
$skipDirs = @('vendor', 'node_modules', '.git', 'uploads', 'assets', 'logs')
$extensions = @('.php', '.css', '.js', '.sql', '.md', '.json')

# Prefer the file list from git so we only ever inspect tracked files.
$tracked = $null
if (Get-Command git -ErrorAction SilentlyContinue) {
    $tracked = git ls-files 2>$null | Where-Object { $_ -and (Test-Path $_) }
}

$files = if ($tracked) {
    $tracked | Where-Object {
        $ext = [System.IO.Path]::GetExtension($_).ToLower()
        $extensions -contains $ext
    }
} else {
    Get-ChildItem -Path $Path -Recurse -File |
        Where-Object {
            $extensions -contains $_.Extension.ToLower() -and
            -not ($skipDirs | Where-Object { $_.FullName -match "[\\/]$_([\\/]|$)" })
        } |
        ForEach-Object { $_.FullName.Replace($repoRoot + [IO.Path]::DirectorySeparatorChar, '') }
}

$offenders = @()
foreach ($file in $files) {
    $full = Join-Path $repoRoot $file
    if (-not (Test-Path $full)) { continue }
    $bytes = [System.IO.File]::ReadAllBytes($full)
    if ($bytes.Length -ge 3 -and
        $bytes[0] -eq 0xEF -and $bytes[1] -eq 0xBB -and $bytes[2] -eq 0xBF) {
        $offenders += $file
    }
}

if ($offenders.Count -gt 0) {
    Write-Host ''
    Write-Host 'FAIL: UTF-8 BOM found in the following file(s):' -ForegroundColor Red
    foreach ($f in $offenders) { Write-Host "  $f" -ForegroundColor Red }
    Write-Host ''
    Write-Host 'A BOM stops PHP emitting output before <?php, which blocks every' -ForegroundColor Yellow
    Write-Host 'header() call and renders the page completely blank. Strip it with:' -ForegroundColor Yellow
    Write-Host ''
    Write-Host '  $b = [IO.File]::ReadAllBytes($p)' -ForegroundColor Cyan
    Write-Host '  if ($b[0] -eq 0xEF) { [IO.File]::WriteAllBytes($p, $b[3..($b.Length-1)]) }' -ForegroundColor Cyan
    Write-Host ''
    Write-Host 'Or re-save the file as UTF-8 without BOM.' -ForegroundColor Cyan
    Write-Host ''
    exit 1
}

Write-Host "OK: no UTF-8 BOM in $($files.Count) checked file(s)." -ForegroundColor Green
exit 0
