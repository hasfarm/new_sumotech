param(
    [string]$TaskName = 'SumotechQueueWorker',
    [int]$WorkerCount = 4
)

$ErrorActionPreference = 'Stop'

$scriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$projectDir = [System.IO.Path]::GetFullPath((Join-Path $scriptDir '..\..'))
$workerScript = Join-Path $scriptDir 'start-queue-worker.ps1'

if ($WorkerCount -lt 1) {
    throw 'WorkerCount must be >= 1'
}

if (!(Test-Path $workerScript)) {
    throw "Worker script not found: $workerScript"
}

$currentUser = [System.Security.Principal.WindowsIdentity]::GetCurrent().Name
$existingTasks = Get-ScheduledTask -TaskName "$TaskName*" -ErrorAction SilentlyContinue
foreach ($existing in $existingTasks) {
    Unregister-ScheduledTask -TaskName $existing.TaskName -Confirm:$false
}

$trigger = New-ScheduledTaskTrigger -AtLogOn -User $currentUser
$settings = New-ScheduledTaskSettingsSet -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -StartWhenAvailable -ExecutionTimeLimit (New-TimeSpan -Hours 0) -RestartCount 999 -RestartInterval (New-TimeSpan -Minutes 1) -MultipleInstances IgnoreNew
$principal = New-ScheduledTaskPrincipal -UserId $currentUser -LogonType Interactive -RunLevel Limited

for ($i = 1; $i -le $WorkerCount; $i++) {
    $workerTaskName = "{0}-{1}" -f $TaskName, $i
    $actionArgs = '-NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File "{0}" -ProjectDir "{1}" -WorkerName "{2}"' -f $workerScript, $projectDir, $workerTaskName
    $action = New-ScheduledTaskAction -Execute 'powershell.exe' -Argument $actionArgs -WorkingDirectory $projectDir

    $task = New-ScheduledTask -Action $action -Trigger $trigger -Settings $settings -Principal $principal -Description "Auto-start Laravel queue worker $i/$WorkerCount for $projectDir"
    Register-ScheduledTask -TaskName $workerTaskName -InputObject $task -Force | Out-Null
    Start-ScheduledTask -TaskName $workerTaskName
}

$createdTasks = Get-ScheduledTask -TaskName "$TaskName-*" -ErrorAction SilentlyContinue | Sort-Object TaskName
Write-Output "Scheduled tasks installed for user '$currentUser':"
foreach ($t in $createdTasks) {
    Write-Output ("- {0} ({1})" -f $t.TaskName, $t.State)
}
Write-Output "Worker script: $workerScript"
Write-Output "Log files: $projectDir\storage\logs\queue-worker-autostart-SumotechQueueWorker-<n>.log"
