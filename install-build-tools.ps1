# Installs what RageKit needs to compile apps on Windows: Visual Studio 2022 Build Tools with the
# "Desktop development with C++" workload (the MSVC compiler and the Windows SDK). Does nothing when
# they're already installed; adds the workload to an existing Build Tools install that lacks it,
# and finishes an install that was interrupted.
#
#   powershell -ExecutionPolicy Bypass -File install-build-tools.ps1
#
# Windows asks for administrator rights. Downloads a few GB and takes 5-20 minutes.
$ErrorActionPreference = 'Stop'
$workload = 'Microsoft.VisualStudio.Workload.VCTools'
$compiler = 'Microsoft.VisualStudio.Component.VC.Tools.x86.x64'
$installerDir = Join-Path ${env:ProgramFiles(x86)} 'Microsoft Visual Studio\Installer'
$vswhere = Join-Path $installerDir 'vswhere.exe'
$options = @('--passive', '--wait', '--norestart', '--add', $workload, '--includeRecommended')

# The installation that has the C++ compiler, or $null.
function Find-Compiler {
    if (-not (Test-Path $vswhere)) { return $null }
    $path = & $vswhere -latest -products * -requires $compiler -property installationPath
    return ($path | Select-Object -First 1)
}

function Invoke-Installer([string] $exe, [string[]] $arguments) {
    $process = Start-Process -FilePath $exe -ArgumentList $arguments -Wait -PassThru
    switch ($process.ExitCode) {
        0 { return }
        3010 { Write-Host 'Done; Windows wants a restart before the tools are fully usable.'; return }
        1602 { throw 'cancelled (administrator rights were declined, or the installer was closed)' }
        5007 { throw 'the installer needs administrator rights' }
        default { throw "the Visual Studio Installer failed with exit code $($process.ExitCode)" }
    }
}

$found = Find-Compiler
if ($found) {
    Write-Host "The C++ build tools are already installed: $found"
    exit 0
}

# Build Tools already installed (maybe unfinished) but without the C++ compiler: change that one.
$existing = $null
$complete = $true
if (Test-Path $vswhere) {
    $existing = & $vswhere -all -products Microsoft.VisualStudio.Product.BuildTools -property installationPath | Select-Object -First 1
    if ($existing) {
        $complete = (& $vswhere -all -products Microsoft.VisualStudio.Product.BuildTools -property isComplete | Select-Object -First 1) -ne '0'
    }
}

if ($existing) {
    $setup = Join-Path $installerDir 'setup.exe'
    if (-not $complete) {
        Write-Host "Finishing the interrupted Build Tools install in $existing ..."
        Invoke-Installer $setup @('resume', '--installPath', "`"$existing`"", '--passive', '--wait', '--norestart')
    }
    if (-not (Find-Compiler)) {
        Write-Host "Adding the C++ workload to $existing (Windows will ask for administrator rights) ..."
        Invoke-Installer $setup (@('modify', '--installPath', "`"$existing`"") + $options)
    }
} else {
    $exe = Join-Path $env:TEMP 'vs_BuildTools.exe'
    Write-Host 'Downloading the Visual Studio 2022 Build Tools installer ...'
    $ProgressPreference = 'SilentlyContinue' # Invoke-WebRequest is much slower with its progress bar
    Invoke-WebRequest -Uri 'https://aka.ms/vs/17/release/vs_BuildTools.exe' -OutFile $exe -UseBasicParsing
    $signature = Get-AuthenticodeSignature $exe
    if ($signature.Status -ne 'Valid' -or $signature.SignerCertificate.Subject -notmatch 'O=Microsoft Corporation') {
        Remove-Item $exe -ErrorAction SilentlyContinue
        throw "the downloaded installer isn't validly signed by Microsoft ($($signature.Status)); not running it"
    }
    Write-Host 'Installing (Windows will ask for administrator rights; this takes a while) ...'
    try {
        Invoke-Installer $exe $options
    } finally {
        Remove-Item $exe -ErrorAction SilentlyContinue
    }
}

$found = Find-Compiler
if (-not $found) {
    throw "the installer finished, but the C++ compiler still isn't found; open the Visual Studio Installer and add `"Desktop development with C++`""
}
Write-Host "The C++ build tools are installed: $found"
