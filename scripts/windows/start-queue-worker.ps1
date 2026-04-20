param(
    [string]$ProjectDir = '',
    [string]$WorkerName = 'worker-1'
)

$ErrorActionPreference = 'Stop'

$scriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
if ([string]::IsNullOrWhiteSpace($ProjectDir)) {
    $ProjectDir = [System.IO.Path]::GetFullPath((Join-Path $scriptDir '..\..'))
}

$artisanPath = Join-Path $ProjectDir 'artisan'
$logDir = Join-Path $ProjectDir 'storage\logs'
$safeWorkerName = ($WorkerName -replace '[^a-zA-Z0-9\-_]', '_')
$logFile = Join-Path $logDir ("queue-worker-autostart-{0}.log" -f $safeWorkerName)

if (!(Test-Path $logDir)) {
    New-Item -ItemType Directory -Path $logDir -Force | Out-Null
}

function Write-Log {
    param([string]$Message)
    $timestamp = Get-Date -Format 'yyyy-MM-dd HH:mm:ss'
    try {
        Add-Content -Path $logFile -Value ("[queue-worker] $timestamp $Message") -ErrorAction Stop
    }
    catch {
        # Ignore transient log write issues so worker startup is not blocked.
    }
}

if (!(Test-Path $artisanPath)) {
    Write-Log "ERROR: artisan not found in '$ProjectDir'"
    exit 1
}

$phpExe = $null
$laragonPhpRoot = 'C:\laragon\bin\php'

if (Test-Path $laragonPhpRoot) {
    $phpExe = Get-ChildItem -Path $laragonPhpRoot -Recurse -Filter 'php.exe' -ErrorAction SilentlyContinue |
        Sort-Object FullName -Descending |
        Select-Object -First 1 -ExpandProperty FullName
}

if (-not $phpExe) {
    $phpCmd = Get-Command php -ErrorAction SilentlyContinue | Select-Object -First 1
    if ($phpCmd) {
        $phpExe = $phpCmd.Source
    }
}

if (-not $phpExe) {
    Write-Log 'ERROR: php.exe not found (checked Laragon and PATH)'
    exit 1
}

Write-Log "Starting worker '$safeWorkerName' with '$phpExe'"

Push-Location $ProjectDir
$exitCode = 1
try {
    & $phpExe artisan queue:work --queue=default,media-generation --sleep=3 --tries=3 --timeout=1800 >> $logFile 2>&1
    $exitCode = $LASTEXITCODE
}
catch {
    Write-Log ("ERROR: " + $_.Exception.Message)
    $exitCode = 1
}
finally {
    Pop-Location
}

Write-Log "Worker exited with code $exitCode"
exit $exitCode
