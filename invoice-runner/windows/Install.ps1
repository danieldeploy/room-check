param([string]$Endpoint = 'https://check.welcomehostel.pt/invoice-agent.php')
$ErrorActionPreference = 'Stop'
$taskName = 'ManagementHub Invoices'
$root = Join-Path $env:LOCALAPPDATA 'ManagementHub\Invoices'
$data = Join-Path $root 'data'
$app = Join-Path $root 'app'
function Protect-Folder([string]$Directory) {
    [IO.Directory]::CreateDirectory($Directory) | Out-Null
    if ((Get-Item -LiteralPath $Directory -Force).Attributes -band [IO.FileAttributes]::ReparsePoint) { throw 'The installation directory cannot be a junction or symbolic link.' }
    $sid = [Security.Principal.WindowsIdentity]::GetCurrent().User
    $acl = New-Object Security.AccessControl.DirectorySecurity
    $acl.SetAccessRuleProtection($true, $false)
    foreach ($identity in @($sid, (New-Object Security.Principal.SecurityIdentifier('S-1-5-18')))) {
        $rule = New-Object Security.AccessControl.FileSystemAccessRule($identity, 'FullControl', 'ContainerInherit,ObjectInherit', 'None', 'Allow')
        $acl.AddAccessRule($rule)
    }
    Set-Acl -LiteralPath $Directory -AclObject $acl
}
try {
    if (-not [Environment]::Is64BitOperatingSystem) { throw 'Windows x64 is required.' }
    $uri = [Uri]$Endpoint
    if ($uri.Scheme -ne 'https' -or $uri.UserInfo -or $uri.Query -or $uri.Fragment -or -not $uri.AbsolutePath.EndsWith('/invoice-agent.php')) { throw 'Invalid HTTPS endpoint.' }
    $node = (Get-Command node.exe -ErrorAction Stop).Source
    $version = [Version]((& $node -p 'process.versions.node').Trim())
    if ($version -lt [Version]'22.12.0') { throw 'Install a supported Node.js LTS release (minimum 22.12).' }
    $npm = Join-Path (Split-Path $node -Parent) 'npm.cmd'
    if (-not (Test-Path -LiteralPath $npm)) { throw 'npm is required.' }
    if (Test-Path -LiteralPath (Join-Path $data 'agent.dpapi')) { throw 'Already installed. Use the documented update or pairing procedure.' }
    if (Get-ScheduledTask -TaskName $taskName -ErrorAction SilentlyContinue) { throw 'A scheduled invoice agent already exists.' }
    $source = Split-Path $PSScriptRoot -Parent
    Protect-Folder $root; Protect-Folder $data
    [IO.Directory]::CreateDirectory($app) | Out-Null
    Get-ChildItem -LiteralPath $source -File | Where-Object { $_.Extension -eq '.mjs' -or $_.Name -in @('package.json','package-lock.json') } | Copy-Item -Destination $app
    Copy-Item -LiteralPath (Join-Path $source 'windows') -Destination $app -Recurse
    Push-Location $app
    try {
        & $npm ci --no-audit --no-fund
        if ($LASTEXITCODE -ne 0) { throw 'Dependency installation failed.' }
        & $npm run install-chrome
        if ($LASTEXITCODE -ne 0) { throw 'Chrome installation failed.' }
    } finally { Pop-Location }
    Add-Type -AssemblyName System.Security
    $secret = Read-Host 'Paste the one-time pairing key from Hub > Invoices > Settings' -AsSecureString
    $pointer = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($secret)
    try { $token = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($pointer) }
    finally { [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($pointer); $secret.Dispose() }
    if ($token -notmatch '^[a-f0-9]{64}$') { throw 'Invalid pairing key.' }
    $json = @{ endpoint=$Endpoint; token=$token; node=$node } | ConvertTo-Json -Compress
    $plain = [Text.Encoding]::UTF8.GetBytes($json)
    try {
        $encrypted = [Security.Cryptography.ProtectedData]::Protect($plain, $null, [Security.Cryptography.DataProtectionScope]::CurrentUser)
        [IO.File]::WriteAllBytes((Join-Path $data 'agent.dpapi'), $encrypted)
    } finally { [Array]::Clear($plain, 0, $plain.Length); $token=$null; $json=$null }
    & powershell.exe -NoProfile -File (Join-Path $app 'windows\Run-Agent.ps1') -TestOnly
    if ($LASTEXITCODE -ne 0) { throw 'Connection or Chrome test failed. The agent has not been scheduled.' }
    Write-Host 'Connection and Chrome tested. Hub remains paused until you activate Windows there.'
    Write-Host 'Next run Register-Task.ps1 to start the agent automatically, including after a restart.'
} catch {
    Write-Host 'Installation stopped. No existing ZKTeco task or Windows update setting was changed.'
    Write-Host $_.Exception.Message
    exit 1
}
