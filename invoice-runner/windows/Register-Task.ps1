$ErrorActionPreference = 'Stop'
$taskName = 'ManagementHub Invoices'
$script = Join-Path $env:LOCALAPPDATA 'ManagementHub\Invoices\app\windows\Run-Agent.ps1'
try {
    if (-not (Test-Path -LiteralPath $script)) { throw 'Install and test the agent first.' }
    if (Get-ScheduledTask -TaskName $taskName -ErrorAction SilentlyContinue) { throw 'The task already exists. Stop it before updating.' }
    $user = [Security.Principal.WindowsIdentity]::GetCurrent().Name
    # Password logon supports HTTPS and user-scoped DPAPI while no interactive user is logged on.
    # S4U is deliberately not used. The password goes only to Windows Task Scheduler.
    $credential = Get-Credential -UserName $user -Message 'Windows password for unattended invoice execution (not the Windows Hello PIN)'
    if ($credential.UserName -ne $user) { throw 'Use the Windows account that installed the agent.' }
    $action = New-ScheduledTaskAction -Execute (Join-Path $env:SystemRoot 'System32\WindowsPowerShell\v1.0\powershell.exe') -Argument ('-NoProfile -NonInteractive -File "' + $script + '"')
    $trigger = New-ScheduledTaskTrigger -AtStartup
    $settings = New-ScheduledTaskSettingsSet -MultipleInstances IgnoreNew -StartWhenAvailable -RestartCount 999 -RestartInterval (New-TimeSpan -Minutes 1) -ExecutionTimeLimit ([TimeSpan]::Zero) -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries
    Register-ScheduledTask -TaskName $taskName -Action $action -Trigger $trigger -Settings $settings -User $user -Password $credential.GetNetworkCredential().Password -RunLevel Limited | Out-Null
    $credential = $null
    Start-ScheduledTask -TaskName $taskName
    Write-Host 'Invoice agent scheduled. Activate Windows in the Hub after confirming its recent connection.'
} catch {
    Write-Host 'Task registration failed. Run under the installing Windows account; Windows may require administrator approval.'
    exit 1
}
