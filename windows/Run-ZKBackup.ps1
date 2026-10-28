param([switch]$RestoreTest)
$ErrorActionPreference = 'Stop'
$root = Join-Path $env:LOCALAPPDATA 'ManagementHub\ZKBackup'
$data = Join-Path $root 'data'
$app = Join-Path $root 'app'
$agentData = Join-Path $env:LOCALAPPDATA 'ManagementHub\Invoices\data'
$mutex = $null; $acquired = $false; $process = $null; $job = $null
try {
    $sid = [Security.Principal.WindowsIdentity]::GetCurrent().User.Value
    $mutex = New-Object Threading.Mutex($false, ('Global\ManagementHub.ZKBackup.' + $sid))
    try { $acquired = $mutex.WaitOne(0) } catch [Threading.AbandonedMutexException] { $acquired = $true }
    if (!$acquired) { Write-Host 'BACKUP_ALREADY_RUNNING'; exit 0 }
    $privateCheck = Join-Path $env:LOCALAPPDATA 'ManagementHub\Invoices\app\windows\Test-PrivateDirectory.ps1'
    if (!(Test-Path -LiteralPath $privateCheck)) { throw 'missing_private_storage_validator' }
    & powershell.exe -NoProfile -NonInteractive -File $privateCheck -Directory $data
    if ($LASTEXITCODE -ne 0) { throw 'private_storage_permissions' }
    & powershell.exe -NoProfile -NonInteractive -File $privateCheck -Directory $agentData
    if ($LASTEXITCODE -ne 0) { throw 'agent_private_storage_permissions' }
    Add-Type -AssemblyName System.Security
    $encrypted = [IO.File]::ReadAllBytes((Join-Path $agentData 'agent.dpapi'))
    $plain = [Security.Cryptography.ProtectedData]::Unprotect($encrypted, $null, [Security.Cryptography.DataProtectionScope]::CurrentUser)
    try { $config = [Text.Encoding]::UTF8.GetString($plain) | ConvertFrom-Json }
    finally { [Array]::Clear($plain, 0, $plain.Length) }
    if ($config.endpoint -ne 'https://check.welcomehostel.pt/invoice-agent.php') { throw 'untrusted_agent_endpoint' }
    $node = [string]$config.node
    $script = Join-Path $app 'zkaccess-backup.mjs'
    if (!(Test-Path -LiteralPath $script)) { throw 'backup_script_missing' }
    $payload = @{ endpoint = 'https://check.welcomehostel.pt/zkaccess-backup-agent.php'; token = $config.token }
    $info = New-Object Diagnostics.ProcessStartInfo
    $info.FileName = $node
    $info.Arguments = '"' + $script + '"' + $(if ($RestoreTest) {' --restore-test'} else {''})
    $info.WorkingDirectory = $app
    $info.UseShellExecute = $false
    $info.CreateNoWindow = $true
    $info.RedirectStandardInput = $true
    . (Join-Path $env:LOCALAPPDATA 'ManagementHub\Invoices\app\windows\Agent-Job.ps1')
    $job = New-Object InvoiceAgentJob
    $process = [Diagnostics.Process]::Start($info)
    $job.Attach($process.Handle)
    $bytes = [Text.Encoding]::UTF8.GetBytes(($payload | ConvertTo-Json -Compress))
    try { $process.StandardInput.BaseStream.Write($bytes,0,$bytes.Length); $process.StandardInput.BaseStream.Close() }
    finally { [Array]::Clear($bytes,0,$bytes.Length) }
    $payload.token = $null; $config.token = $null
    $process.WaitForExit()
    exit $process.ExitCode
} catch {
    Write-Host 'ZK_BACKUP_FAILED'
    exit 1
} finally {
    if ($process -and !$process.HasExited) {
        & taskkill.exe /PID $process.Id /T /F 2>$null | Out-Null
    }
    if ($job) { $job.Dispose() }
    if ($acquired) { $mutex.ReleaseMutex() }
    if ($mutex) { $mutex.Dispose() }
}
