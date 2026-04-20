param(
    [string]$TaskName = 'SumotechQdrant'
)

$ErrorActionPreference = 'Stop'

$scriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$projectDir = [System.IO.Path]::GetFullPath((Join-Path $scriptDir '..\..'))
$qdrantScript = Join-Path $scriptDir 'start-qdrant.ps1'

if (!(Test-Path $qdrantScript)) {
    throw "Qdrant start script not found: $qdrantScript"
}

$currentUser = [System.Security.Principal.WindowsIdentity]::GetCurrent().Name

$existing = Get-ScheduledTask -TaskName $TaskName -ErrorAction SilentlyContinue
if ($existing) {
    Unregister-ScheduledTask -TaskName $TaskName -Confirm:$false
}

$trigger = New-ScheduledTaskTrigger -AtLogOn -User $currentUser
$settings = New-ScheduledTaskSettingsSet -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -StartWhenAvailable -ExecutionTimeLimit (New-TimeSpan -Hours 0) -RestartCount 999 -RestartInterval (New-TimeSpan -Minutes 1) -MultipleInstances IgnoreNew
$principal = New-ScheduledTaskPrincipal -UserId $currentUser -LogonType Interactive -RunLevel Limited
$actionArgs = '-NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File "{0}" -ProjectDir "{1}"' -f $qdrantScript, $projectDir
$action = New-ScheduledTaskAction -Execute 'powershell.exe' -Argument $actionArgs -WorkingDirectory $projectDir

$task = New-ScheduledTask -Action $action -Trigger $trigger -Settings $settings -Principal $principal -Description "Auto-start Qdrant for $projectDir"
Register-ScheduledTask -TaskName $TaskName -InputObject $task -Force | Out-Null
Start-ScheduledTask -TaskName $TaskName

$created = Get-ScheduledTask -TaskName $TaskName -ErrorAction SilentlyContinue
if ($created) {
    Write-Output ("Scheduled task installed: {0} ({1})" -f $created.TaskName, $created.State)
}
Write-Output "Qdrant script: $qdrantScript"
Write-Output "Log file: $projectDir\storage\logs\qdrant-autostart.log"
