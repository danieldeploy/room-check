param([string]$Endpoint = 'https://check.welcomehostel.pt/invoice-agent.php')
$ErrorActionPreference = 'Stop'
$data = Join-Path $env:LOCALAPPDATA 'ManagementHub\Invoices\data'
$mutex = $null; $acquired = $false
try {
    $sid = [Security.Principal.WindowsIdentity]::GetCurrent().User.Value
    $mutex = New-Object Threading.Mutex($false, ('Global\ManagementHub.InvoiceAgent.' + $sid))
    try { $acquired = $mutex.WaitOne(0) } catch [Threading.AbandonedMutexException] { $acquired = $true }
    if (-not $acquired) { throw 'Stop the invoice agent before pairing.' }
    & powershell.exe -NoProfile -NonInteractive -File (Join-Path $PSScriptRoot 'Test-PrivateDirectory.ps1') -Directory $data
    if ($LASTEXITCODE -ne 0) { throw 'Invalid private data directory.' }
    $uri = [Uri]$Endpoint
    if ($uri.Scheme -ne 'https' -or $uri.UserInfo -or $uri.Query -or $uri.Fragment -or -not $uri.AbsolutePath.EndsWith('/invoice-agent.php')) { throw 'Invalid HTTPS endpoint.' }
    $node = (Get-Command node.exe -ErrorAction Stop).Source
    if ([Version]((& $node -p 'process.versions.node').Trim()) -lt [Version]'22.12.0') { throw 'Node 22.12 or newer is required.' }
    Add-Type -AssemblyName System.Security
    $secret = Read-Host 'Paste the new Windows pairing key from the Hub' -AsSecureString
    $pointer = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($secret)
    try { $token = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($pointer) }
    finally { [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($pointer); $secret.Dispose() }
    if ($token -notmatch '^[a-f0-9]{64}$') { throw 'Invalid pairing key.' }
    $plain = [Text.Encoding]::UTF8.GetBytes((@{ endpoint=$Endpoint; token=$token; node=$node } | ConvertTo-Json -Compress))
    try {
        $encrypted = [Security.Cryptography.ProtectedData]::Protect($plain, $null, [Security.Cryptography.DataProtectionScope]::CurrentUser)
        $target = Join-Path $data 'agent.dpapi'
        $temporary = Join-Path $data ('agent-' + [Guid]::NewGuid().ToString('N') + '.tmp')
        [IO.File]::WriteAllBytes($temporary, $encrypted)
        if (Test-Path -LiteralPath $target) { [IO.File]::Replace($temporary, $target, $null) }
        else { [IO.File]::Move($temporary, $target) }
    } finally { [Array]::Clear($plain, 0, $plain.Length); $token=$null }
    Write-Host 'Pairing saved. Run Run-Agent.ps1 -TestOnly before restarting the task.'
} catch {
    Write-Host 'Pairing failed. Keep the agent paused and verify the Windows account, key and private directory.'
    exit 1
} finally {
    if ($acquired) { $mutex.ReleaseMutex() }
    if ($mutex) { $mutex.Dispose() }
}
