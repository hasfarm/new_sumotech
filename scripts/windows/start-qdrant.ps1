param(
    [string]$ProjectDir = ''
)

$ErrorActionPreference = 'Stop'

$scriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
if ([string]::IsNullOrWhiteSpace($ProjectDir)) {
    $ProjectDir = [System.IO.Path]::GetFullPath((Join-Path $scriptDir '..\..'))
}

$qdrantExe = Join-Path $ProjectDir 'storage\tools\qdrant\qdrant.exe'
$logDir = Join-Path $ProjectDir 'storage\logs'
$logFile = Join-Path $logDir 'qdrant-autostart.log'

if (!(Test-Path $logDir)) {
    New-Item -ItemType Directory -Path $logDir -Force | Out-Null
}

function Write-Log {
    param([string]$Message)
    $timestamp = Get-Date -Format 'yyyy-MM-dd HH:mm:ss'
    try {
        Add-Content -Path $logFile -Value ("[qdrant] $timestamp $Message") -ErrorAction Stop
    }
    catch {
        # Ignore log write issues.
    }
}

if (!(Test-Path $qdrantExe)) {
    Write-Log "ERROR: qdrant.exe not found at '$qdrantExe'"
    exit 1
}

# Skip if a Qdrant instance is already running.
$running = Get-CimInstance Win32_Process -Filter "name='qdrant.exe'" -ErrorAction SilentlyContinue
if ($running) {
    Write-Log 'Qdrant already running, skipping start.'
    exit 0
}

Write-Log "Starting Qdrant from '$qdrantExe'"
Push-Location $ProjectDir
$exitCode = 1
try {
    & $qdrantExe >> $logFile 2>&1
    $exitCode = $LASTEXITCODE
}
catch {
    Write-Log ('ERROR: ' + $_.Exception.Message)
    $exitCode = 1
}
finally {
    Pop-Location
}

Write-Log "Qdrant exited with code $exitCode"
exit $exitCode
