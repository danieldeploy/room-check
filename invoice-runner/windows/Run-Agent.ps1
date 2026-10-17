param([switch]$TestOnly, [switch]$OneShot)
$ErrorActionPreference = 'Stop'
$root = Split-Path (Split-Path $PSScriptRoot -Parent) -Parent
$data = Join-Path $root 'data'
$mutex = $null; $acquired = $false; $process = $null; $processJob = $null
$browserChannel = $null
try {
    $sid = [Security.Principal.WindowsIdentity]::GetCurrent().User.Value
    $mutex = New-Object Threading.Mutex($false, ('Global\ManagementHub.InvoiceAgent.' + $sid))
    try { $acquired = $mutex.WaitOne(0) } catch [Threading.AbandonedMutexException] { $acquired = $true }
    if (-not $acquired) { Write-Host 'The invoice agent is already running.'; exit 0 }
    & powershell.exe -NoProfile -NonInteractive -File (Join-Path $PSScriptRoot 'Test-PrivateDirectory.ps1') -Directory $data
    if ($LASTEXITCODE -ne 0) { throw 'private_storage_permissions' }
    Add-Type -AssemblyName System.Security
    $encrypted = [IO.File]::ReadAllBytes((Join-Path $data 'agent.dpapi'))
    $plain = [Security.Cryptography.ProtectedData]::Unprotect($encrypted, $null, [Security.Cryptography.DataProtectionScope]::CurrentUser)
    try { $config = [Text.Encoding]::UTF8.GetString($plain) | ConvertFrom-Json }
    finally { [Array]::Clear($plain, 0, $plain.Length) }
    $node = [string]$config.node
    $payload = @{ endpoint=$config.endpoint; token=$config.token; privateDir=$data; probeOnly=[bool]$TestOnly; oneShot=[bool]$OneShot }
    if (-not $TestOnly) {
        . (Join-Path $PSScriptRoot 'Browser-Supervisor.ps1')
        $channelName = 'browser-supervisor-' + [Guid]::NewGuid().ToString('N')
        $browserChannel = Join-Path $data $channelName
        [IO.Directory]::CreateDirectory($browserChannel) | Out-Null
        $payload.browserSupervisor = $channelName
    }
    $info = New-Object Diagnostics.ProcessStartInfo
    $info.FileName = $node
    $info.Arguments = '"' + (Join-Path (Split-Path $PSScriptRoot -Parent) 'windows-agent.mjs') + '"'
    $info.WorkingDirectory = Split-Path $PSScriptRoot -Parent
    $info.UseShellExecute = $false; $info.CreateNoWindow = $true; $info.RedirectStandardInput = $true
    . (Join-Path $PSScriptRoot 'Agent-Job.ps1')
    $processJob = New-Object InvoiceAgentJob
    $process = [Diagnostics.Process]::Start($info)
    $processJob.Attach($process.Handle)
    $bytes = [Text.Encoding]::UTF8.GetBytes(($payload | ConvertTo-Json -Compress))
    try { $process.StandardInput.BaseStream.Write($bytes,0,$bytes.Length); $process.StandardInput.BaseStream.Close() }
    finally { [Array]::Clear($bytes,0,$bytes.Length) }
    $payload.token = $null; $config.token = $null
    while (-not $process.WaitForExit(200)) {
        if ($browserChannel) { Invoke-BookingBrowserRequest -ChannelDirectory $browserChannel -DataDirectory $data }
    }
    exit $process.ExitCode
} catch {
    Write-Host 'Agent failed. Check the protected configuration, Node and the Hub connection.'
    exit 1
} finally {
    if ($process -and -not $process.HasExited) {
        # Only descendants of this agent are stopped. ZKTeco and other Chrome processes are untouched.
        & taskkill.exe /PID $process.Id /T /F 2>$null | Out-Null
    }
    if ($acquired) { $mutex.ReleaseMutex() }
    if ($mutex) { $mutex.Dispose() }
    if ($processJob) { $processJob.Dispose() }
    if ($browserChannel -and (Test-Path -LiteralPath $browserChannel)) {
        Remove-Item -LiteralPath $browserChannel -Recurse -Force -ErrorAction SilentlyContinue
    }
}
