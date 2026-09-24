param([switch]$Browser)
$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName System.Security
$root = Join-Path $env:TEMP ('invoice-agent-test-' + [char]0xE3 + '-' + [Guid]::NewGuid().ToString('N'))
$repo = Split-Path $PSScriptRoot -Parent
try {
    [IO.Directory]::CreateDirectory($root) | Out-Null
    $sid = [Security.Principal.WindowsIdentity]::GetCurrent().User
    $acl = New-Object Security.AccessControl.DirectorySecurity
    $acl.SetAccessRuleProtection($true, $false)
    $acl.AddAccessRule((New-Object Security.AccessControl.FileSystemAccessRule($sid, 'FullControl', 'ContainerInherit,ObjectInherit', 'None', 'Allow')))
    Set-Acl -LiteralPath $root -AclObject $acl
    $guard = Join-Path $repo 'invoice-runner\windows\Test-PrivateDirectory.ps1'
    & powershell.exe -NoProfile -NonInteractive -File $guard -Directory $root
    if ($LASTEXITCODE -ne 0) { throw 'Private Windows ACL rejected.' }
    if (-not $Browser) {
        $plain = [Text.Encoding]::UTF8.GetBytes('test-only-token')
        $cipher = [Security.Cryptography.ProtectedData]::Protect($plain, $null, [Security.Cryptography.DataProtectionScope]::CurrentUser)
        $decoded = [Security.Cryptography.ProtectedData]::Unprotect($cipher, $null, [Security.Cryptography.DataProtectionScope]::CurrentUser)
        if ([Text.Encoding]::UTF8.GetString($decoded) -ne 'test-only-token') { throw 'DPAPI round trip failed.' }
        # Change only the DACL of this disposable fixture. Set-Acl can request
        # SeSecurityPrivilege on the real non-elevated Windows account.
        & icacls.exe $root /grant '*S-1-1-0:(OI)(CI)(RX)' /Q | Out-Null
        if ($LASTEXITCODE -ne 0) { throw 'Could not create the permission-denial fixture.' }
        & powershell.exe -NoProfile -NonInteractive -File $guard -Directory $root
        if ($LASTEXITCODE -eq 0) { throw 'Publicly readable data directory was accepted.' }
        Write-Host 'DPAPI and private-directory permission checks passed.'
    } else {
        & node (Join-Path $repo 'tests\invoice-browser-windows.mjs') $root
        if ($LASTEXITCODE -ne 0) { throw 'Sandboxed Chrome preflight failed.' }
        if (Get-ChildItem -LiteralPath $root -Force | Where-Object { $_.Name -like '.browser-*' }) { throw 'Browser profile was not removed.' }
        Write-Host 'Real Windows Chrome preflight and profile cleanup passed.'
    }
} finally { if (Test-Path -LiteralPath $root) { Remove-Item -LiteralPath $root -Recurse -Force } }
# The permission-denial assertion intentionally ran a native process that returned 1.
# Once all assertions pass, do not propagate that expected status to the CI shell.
exit 0
