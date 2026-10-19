# Sets up a RageKit checkout on Windows: the CLI's Composer dependencies, then the pinned toolchain
# (TypePHP + Slint, shared per user; see docs\toolchain.md), then a check of everything. Needs
# PHP 8.4+, Composer, git, 7-Zip (unpacks Slint) and Visual Studio 2022 Build Tools with
# "Desktop development with C++" (offered here when missing: install-build-tools.ps1).
$ErrorActionPreference = 'Stop'
Set-Location $PSScriptRoot
foreach ($tool in 'php', 'composer') {
    if (-not (Get-Command $tool -ErrorAction SilentlyContinue)) { throw "setup: $tool is needed on PATH" }
}
$vswhere = Join-Path ${env:ProgramFiles(x86)} 'Microsoft Visual Studio\Installer\vswhere.exe'
$compiler = if (Test-Path $vswhere) { & $vswhere -latest -products * -requires Microsoft.VisualStudio.Component.VC.Tools.x86.x64 -property installationPath }
if (-not $compiler) {
    $answer = Read-Host 'setup: the C++ build tools (Visual Studio 2022 Build Tools) are missing. Install them now? [Y/n]'
    if ($answer -eq '' -or $answer -match '^[Yy]') {
        & (Join-Path $PSScriptRoot 'install-build-tools.ps1')
    } else {
        Write-Host 'setup: continuing without them; builds need them (install-build-tools.ps1 installs them later)'
    }
}
composer install --no-interaction
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
php bin\ragekit setup
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
php bin\ragekit doctor
exit $LASTEXITCODE
