$ErrorActionPreference = 'Stop'
$repo = Split-Path $PSScriptRoot -Parent
$root = Join-Path $env:TEMP ('invoice-supervisor-' + [char]0xE3 + '-' + [Guid]::NewGuid().ToString('N'))
$data = Join-Path $root 'data'; $app = Join-Path $root 'app'; $scripts = Join-Path $app 'windows'
$failure = $null
try {
    [IO.Directory]::CreateDirectory($data) | Out-Null
    [IO.Directory]::CreateDirectory($scripts) | Out-Null
    $sid = [Security.Principal.WindowsIdentity]::GetCurrent().User
    $acl = New-Object Security.AccessControl.DirectorySecurity
    $acl.SetAccessRuleProtection($true, $false)
    $acl.AddAccessRule((New-Object Security.AccessControl.FileSystemAccessRule($sid, 'FullControl', 'ContainerInherit,ObjectInherit', 'None', 'Allow')))
    Set-Acl -LiteralPath $data -AclObject $acl
    foreach ($name in @('Run-Agent.ps1','Agent-Job.ps1','Browser-Supervisor.ps1','Start-Booking-Chrome.ps1','Test-PrivateDirectory.ps1')) {
        Copy-Item (Join-Path $repo ('invoice-runner\windows\' + $name)) $scripts
    }
    foreach ($name in @('browser-supervisor.mjs','controlled-browser.mjs','private-storage.mjs')) {
        Copy-Item (Join-Path $repo ('invoice-runner\' + $name)) $app
    }
    Copy-Item (Join-Path $PSScriptRoot 'invoice-browser-supervisor-windows.mjs') (Join-Path $app 'windows-agent.mjs')
    Add-Type -AssemblyName System.Security
    $config = @{node=(Get-Command node.exe).Source;endpoint='https://example.test';token='synthetic-test-token'} | ConvertTo-Json -Compress
    $plain = [Text.Encoding]::UTF8.GetBytes($config)
    $cipher = [Security.Cryptography.ProtectedData]::Protect($plain, $null, [Security.Cryptography.DataProtectionScope]::CurrentUser)
    [IO.File]::WriteAllBytes((Join-Path $data 'agent.dpapi'), $cipher)
    [IO.File]::WriteAllText((Join-Path $data 'controlled-booking-enabled'), 'enabled')
    & powershell.exe -NoProfile -NonInteractive -File (Join-Path $scripts 'Run-Agent.ps1') -OneShot
    if ($LASTEXITCODE -ne 0 -or -not (Test-Path -LiteralPath (Join-Path $data 'supervisor-passed'))) { throw 'Browser supervision fixture failed.' }
    & node (Join-Path $app 'windows-agent.mjs') survival $data
    if ($LASTEXITCODE -ne 0) { throw 'Persistent Chrome must survive its Node worker and launcher.' }
    if (Get-ChildItem -LiteralPath $data -Directory | Where-Object { $_.Name -like 'browser-supervisor-*' }) { throw 'Supervisor channel was not cleaned up.' }
    Write-Host 'Persistent Chrome survives launcher exit; temporary supervision channel removed.'
} catch { $failure = $_ } finally {
    # TEMP may use the short RUNNER~1 alias while Chrome expands it. Match only
    # the unique disposable profile suffix, independent of that parent alias.
    $profileSuffix = (Split-Path $root -Leaf) + '\data\controlled-booking-login-chrome'
    Get-CimInstance Win32_Process | Where-Object { $_.Name -eq 'chrome.exe' -and $_.CommandLine -and $_.CommandLine.Contains($profileSuffix) -and $_.CommandLine -notmatch ' --type=' } | ForEach-Object {
        & taskkill.exe /PID $_.ProcessId /T /F 2>$null | Out-Null
    }
    for ($attempt = 0; $attempt -lt 20 -and (Test-Path -LiteralPath $root); $attempt++) {
        try { Remove-Item -LiteralPath $root -Recurse -Force }
        catch {
            if ($attempt -eq 19 -and -not $failure) { $failure = $_ }
            Start-Sleep -Milliseconds 250
        }
    }
}
if ($failure) { throw $failure }
exit 0
