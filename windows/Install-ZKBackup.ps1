param([switch]$DoNotSchedule)
$ErrorActionPreference = 'Stop'
$task = 'ManagementHub ZKAccess Backup'
$root = Join-Path $env:LOCALAPPDATA 'ManagementHub\ZKBackup'
$data = Join-Path $root 'data'
$app = Join-Path $root 'app'
$invoiceData = Join-Path $env:LOCALAPPDATA 'ManagementHub\Invoices\data'
function Protect-Directory([string]$target) {
    [IO.Directory]::CreateDirectory($target) | Out-Null
    $item = Get-Item -LiteralPath $target -Force
    if ($item.Attributes -band [IO.FileAttributes]::ReparsePoint) { throw 'unsafe_backup_directory' }
    $acl = New-Object Security.AccessControl.DirectorySecurity
    $acl.SetAccessRuleProtection($true, $false)
    $sid = [Security.Principal.WindowsIdentity]::GetCurrent().User
    foreach ($who in @($sid, (New-Object Security.Principal.SecurityIdentifier('S-1-5-18')))) {
        $acl.AddAccessRule((New-Object Security.AccessControl.FileSystemAccessRule($who,'FullControl',
            'ContainerInherit,ObjectInherit','None','Allow')))
    }
    Set-Acl -LiteralPath $target -AclObject $acl
}
try {
    if (!(Test-Path (Join-Path $invoiceData 'agent.dpapi'))) { throw 'paired_invoice_agent_required' }
    Protect-Directory $root
    Protect-Directory $data
    Protect-Directory $app
    $src = Join-Path $PSScriptRoot 'zkaccess-backup.mjs'
    $wrapper = Join-Path $PSScriptRoot 'Run-ZKBackup.ps1'
    if (!(Test-Path $src) -or !(Test-Path $wrapper)) { throw 'backup_package_incomplete' }
    if ($PSScriptRoot -ne $app) {
        Copy-Item -LiteralPath $src -Destination $app -Force
        Copy-Item -LiteralPath $wrapper -Destination $app -Force
    }
    $validator = Join-Path $env:LOCALAPPDATA 'ManagementHub\Invoices\app\windows\Test-PrivateDirectory.ps1'
    & powershell.exe -NoProfile -NonInteractive -File $validator -Directory $data
    if ($LASTEXITCODE -ne 0) { throw 'backup_directory_not_private' }
    if ($DoNotSchedule) { Write-Host 'BACKUP_PREPARED'; exit 0 }
    if (Get-ScheduledTask -TaskName $task -ErrorAction SilentlyContinue) {
        throw 'backup_task_already_exists'
    }
    $user = [Security.Principal.WindowsIdentity]::GetCurrent().Name
    $script = Join-Path $app 'Run-ZKBackup.ps1'
    $action = New-ScheduledTaskAction -Execute (Join-Path $env:SystemRoot 'System32\WindowsPowerShell\v1.0\powershell.exe') -Argument ('-NoProfile -NonInteractive -ExecutionPolicy RemoteSigned -File "' + $script + '"')
    # Reconciliation every 5 minutes; the executor itself enforces the chosen
    # Europe/Lisbon time and at most one daily database dump.
    $trigger = New-ScheduledTaskTrigger -Once -At (Get-Date).AddMinutes(1) -RepetitionInterval (New-TimeSpan -Minutes 5) -RepetitionDuration (New-TimeSpan -Days 3650)
    $settings = New-ScheduledTaskSettingsSet -MultipleInstances IgnoreNew -ExecutionTimeLimit ([TimeSpan]::Zero) -StartWhenAvailable -RestartCount 2 -RestartInterval (New-TimeSpan -Minutes 5)
    $principal = New-ScheduledTaskPrincipal -UserId $user -LogonType Interactive -RunLevel Limited
    Register-ScheduledTask -TaskName $task -Action $action -Trigger $trigger -Settings $settings -Principal $principal | Out-Null
    Write-Host 'BACKUP_TASK_REGISTERED'
} catch {
    Write-Host 'BACKUP_SETUP_FAILED'
    exit 1
}
