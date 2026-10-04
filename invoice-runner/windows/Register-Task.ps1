param([switch]$AtLogon)
$ErrorActionPreference = 'Stop'
$taskName = 'ManagementHub Invoices'
$script = Join-Path $env:LOCALAPPDATA 'ManagementHub\Invoices\app\windows\Run-Agent.ps1'
try {
    if (-not (Test-Path -LiteralPath $script)) { throw 'Install and test the agent first.' }
    if (Get-ScheduledTask -TaskName $taskName -ErrorAction SilentlyContinue) { throw 'The task already exists. Stop it before updating.' }
    $user = [Security.Principal.WindowsIdentity]::GetCurrent().Name
    # RemoteSigned applies to this process only; organizational policies still take precedence.
    $action = New-ScheduledTaskAction -Execute (Join-Path $env:SystemRoot 'System32\WindowsPowerShell\v1.0\powershell.exe') -Argument ('-NoProfile -NonInteractive -ExecutionPolicy RemoteSigned -File "' + $script + '"')
    $settings = New-ScheduledTaskSettingsSet -MultipleInstances IgnoreNew -StartWhenAvailable -RestartCount 999 -RestartInterval (New-TimeSpan -Minutes 1) -ExecutionTimeLimit ([TimeSpan]::Zero) -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries
    if ($AtLogon) {
        # Use the existing interactive session without requesting or storing a Windows password.
        # A user must log on after boot; this script does not configure automatic Windows logon.
        $trigger = New-ScheduledTaskTrigger -AtLogOn -User $user
        $principal = New-ScheduledTaskPrincipal -UserId $user -LogonType Interactive -RunLevel Limited
        Register-ScheduledTask -TaskName $taskName -Action $action -Trigger $trigger -Settings $settings -Principal $principal | Out-Null
    } else {
        # Password logon supports HTTPS and user-scoped DPAPI without an interactive session.
        # S4U is deliberately not used. Only Task Scheduler receives this password.
        $credential = Get-Credential -UserName $user -Message 'Windows password for unattended invoice execution (not the Windows Hello PIN)'
        if ($credential.UserName -ne $user) { throw 'Use the Windows account that installed the agent.' }
        $trigger = New-ScheduledTaskTrigger -AtStartup
        Register-ScheduledTask -TaskName $taskName -Action $action -Trigger $trigger -Settings $settings -User $user -Password $credential.GetNetworkCredential().Password -RunLevel Limited | Out-Null
        $credential = $null
    }
    Start-ScheduledTask -TaskName $taskName
    Write-Host 'Invoice agent scheduled. Activate Windows in the Hub after confirming its recent connection.'
} catch {
    Write-Host 'Task registration failed. Run under the installing Windows account; Windows may require administrator approval.'
    exit 1
}
