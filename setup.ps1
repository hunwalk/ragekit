# Sets up a RageKit checkout on Windows: the CLI's Composer dependencies, then the pinned toolchain
# (TypePHP + Slint, shared per user; see docs\toolchain.md), then a check of everything. Needs
# PHP 8.4+, Composer, git, 7-Zip (unpacks Slint) and Visual Studio 2022 Build Tools with
# "Desktop development with C++" (`ragekit setup` offers to install them: install-build-tools.ps1).
$ErrorActionPreference = 'Stop'
Set-Location $PSScriptRoot
foreach ($tool in 'php', 'composer') {
    if (-not (Get-Command $tool -ErrorAction SilentlyContinue)) { throw "setup: $tool is needed on PATH" }
}
composer install --no-interaction
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
php bin\ragekit setup
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
php bin\ragekit doctor
exit $LASTEXITCODE
