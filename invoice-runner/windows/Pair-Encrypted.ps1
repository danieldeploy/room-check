param(
    [switch]$Prepare,
    [string]$PackageFile,
    [string]$Endpoint = 'https://check.welcomehostel.pt/invoice-agent.php'
)
$ErrorActionPreference = 'Stop'
$data = Join-Path $env:LOCALAPPDATA 'ManagementHub\Invoices\data'
$mutex = $null; $acquired = $false
function Invoke-PairingHelper($Payload, [string]$Node) {
    $info = New-Object Diagnostics.ProcessStartInfo
    $info.FileName = $Node
    $info.Arguments = '"' + (Join-Path (Split-Path $PSScriptRoot -Parent) 'pairing-crypto.mjs') + '"'
    $info.UseShellExecute = $false; $info.CreateNoWindow = $true
    $info.RedirectStandardInput = $true; $info.RedirectStandardOutput = $true; $info.RedirectStandardError = $true
    $process = [Diagnostics.Process]::Start($info)
    try {
        $bytes = [Text.Encoding]::UTF8.GetBytes(($Payload | ConvertTo-Json -Compress -Depth 8))
        try { $process.StandardInput.BaseStream.Write($bytes,0,$bytes.Length); $process.StandardInput.BaseStream.Close() }
        finally { [Array]::Clear($bytes,0,$bytes.Length) }
        $output = $process.StandardOutput.ReadToEnd()
        $null = $process.StandardError.ReadToEnd(); $process.WaitForExit()
        if ($process.ExitCode -ne 0) { throw 'Pairing helper rejected the request.' }
        return ($output | ConvertFrom-Json)
    } finally { $process.Dispose(); $output=$null }
}
function Save-Protected([string]$Path, $Value) {
    $plain = [Text.Encoding]::UTF8.GetBytes(($Value | ConvertTo-Json -Compress -Depth 8))
    $temporary = $Path + '.' + [Guid]::NewGuid().ToString('N') + '.tmp'
    try {
        $cipher = [Security.Cryptography.ProtectedData]::Protect($plain,$null,[Security.Cryptography.DataProtectionScope]::CurrentUser)
        [IO.File]::WriteAllBytes($temporary,$cipher)
        if (Test-Path -LiteralPath $Path) { [IO.File]::Replace($temporary,$Path,$null) }
        else { [IO.File]::Move($temporary,$Path) }
    } finally {
        [Array]::Clear($plain,0,$plain.Length)
        if (Test-Path -LiteralPath $temporary) { Remove-Item -LiteralPath $temporary -Force }
    }
}
try {
    if ([bool]$Prepare -eq [bool]$PackageFile) { throw 'Choose preparation or import.' }
    $sid = [Security.Principal.WindowsIdentity]::GetCurrent().User.Value
    $mutex = New-Object Threading.Mutex($false,('Global\ManagementHub.InvoiceAgent.' + $sid))
    try { $acquired=$mutex.WaitOne(0) } catch [Threading.AbandonedMutexException] { $acquired=$true }
    if (-not $acquired) { throw 'Stop the invoice agent before pairing.' }
    & powershell.exe -NoProfile -NonInteractive -ExecutionPolicy RemoteSigned -File (Join-Path $PSScriptRoot 'Test-PrivateDirectory.ps1') -Directory $data
    if ($LASTEXITCODE -ne 0) { throw 'Invalid private directory.' }
    Add-Type -AssemblyName System.Security
    $node = (Get-Command node.exe -ErrorAction Stop).Source
    $pendingPath = Join-Path $data 'pending-pairing.dpapi'
    if ($Prepare -and -not (Test-Path -LiteralPath $pendingPath)) {
        $uri=[Uri]$Endpoint
        if ($uri.Scheme -ne 'https' -or $uri.UserInfo -or $uri.Query -or $uri.Fragment -or -not $uri.AbsolutePath.EndsWith('/invoice-agent.php')) { throw 'Invalid HTTPS endpoint.' }
        $keys = Invoke-PairingHelper @{action='prepare'} $node
        Save-Protected $pendingPath @{ endpoint=$Endpoint; node=$node; privateKey=$keys.privateKey; publicKey=$keys.publicKey; created=$keys.created }
        $keys.privateKey=$null
    }
    $plain=[Security.Cryptography.ProtectedData]::Unprotect([IO.File]::ReadAllBytes($pendingPath),$null,[Security.Cryptography.DataProtectionScope]::CurrentUser)
    try { $pending=[Text.Encoding]::UTF8.GetString($plain) | ConvertFrom-Json }
    finally { [Array]::Clear($plain,0,$plain.Length) }
    if ($Prepare) {
        Write-Output $pending.publicKey
        Write-Host 'Public request only. The private key remains DPAPI-protected on this computer.'
    } else {
        $item=Get-Item -LiteralPath $PackageFile
        if ($item.PSIsContainer -or $item.Length -gt 8192 -or ($item.Attributes -band [IO.FileAttributes]::ReparsePoint)) { throw 'Invalid encrypted package.' }
        $sealed=[IO.File]::ReadAllText($item.FullName) | ConvertFrom-Json
        $opened=Invoke-PairingHelper @{action='open';pending=$pending;sealed=$sealed} $node
        Save-Protected (Join-Path $data 'agent.dpapi') @{ endpoint=$pending.endpoint; node=$pending.node; token=$opened.token }
        $opened.token=$null
        Remove-Item -LiteralPath $pendingPath -Force
        Write-Host 'Encrypted pairing imported. Run Run-Agent.ps1 -TestOnly before activation.'
    }
    $pending.privateKey=$null
} catch {
    Write-Host ('Pairing diagnostic: ' + $_.Exception.GetType().FullName + '; line ' + $_.InvocationInfo.ScriptLineNumber)
    Write-Host 'Encrypted pairing failed. Keep collection paused and check the request, package and private directory.'
    exit 1
} finally {
    if ($acquired) { $mutex.ReleaseMutex() }
    if ($mutex) { $mutex.Dispose() }
}
