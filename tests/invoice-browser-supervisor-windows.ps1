$ErrorActionPreference = 'Stop'
$repo = Split-Path $PSScriptRoot -Parent
$root = Join-Path $env:TEMP ('invoice-supervisor-' + [char]0xE3 + '-' + [Guid]::NewGuid().ToString('N'))
$data = Join-Path $root 'data'; $app = Join-Path $root 'app'; $scripts = Join-Path $app 'windows'
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
    $profileSwitch = '--user-data-dir="' + (Join-Path $data 'controlled-booking-login-chrome') + '"'
    $chrome = @(Get-CimInstance Win32_Process | Where-Object { $_.Name -eq 'chrome.exe' -and $_.CommandLine -and $_.CommandLine.Contains($profileSwitch) })
    if ($chrome.Count -ne 1) { throw 'Persistent Chrome must survive its Node worker and launcher.' }
    if (Get-ChildItem -LiteralPath $data -Directory | Where-Object { $_.Name -like 'browser-supervisor-*' }) { throw 'Supervisor channel was not cleaned up.' }
    Write-Host 'Persistent Chrome survives launcher exit; temporary supervision channel removed.'
} finally {
    # Only processes whose command line contains this disposable fixture path.
    Get-CimInstance Win32_Process | Where-Object { $_.Name -eq 'chrome.exe' -and $_.CommandLine -and $_.CommandLine.Contains($root) } | ForEach-Object {
        & taskkill.exe /PID $_.ProcessId /T /F 2>$null | Out-Null
    }
    if (Test-Path -LiteralPath $root) { Remove-Item -LiteralPath $root -Recurse -Force }
}
exit 0
