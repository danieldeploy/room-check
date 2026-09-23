param([Parameter(Mandatory=$true)][string]$DataDirectory)
$ErrorActionPreference = 'Stop'
if (-not [Environment]::UserInteractive) { throw 'interactive_session_required' }
if (-not (Test-Path (Join-Path $DataDirectory 'controlled-booking-enabled'))) { return }
$profile = Join-Path $DataDirectory 'controlled-booking-chrome'
if (-not (Test-Path $profile)) { New-Item -Path $profile -ItemType Directory | Out-Null }
& powershell.exe -NoProfile -NonInteractive -File (Join-Path $PSScriptRoot 'Test-PrivateDirectory.ps1') -Directory $profile
if ($LASTEXITCODE -ne 0) { throw 'private_profile_permissions' }
$chrome = Join-Path ${env:ProgramFiles} 'Google\Chrome\Application\chrome.exe'
if (-not (Test-Path $chrome)) { throw 'chrome_unavailable' }
$existing = Get-CimInstance Win32_Process | Where-Object {
    $_.Name -eq 'chrome.exe' -and $_.CommandLine -like '*controlled-booking-chrome*'
}
if ($existing) {
    if (Test-Path (Join-Path $profile 'DevToolsActivePort')) { return }
    throw 'controlled_chrome_not_ready'
}
$arguments = '--user-data-dir="' + $profile + '" --remote-debugging-address=127.0.0.1 --remote-debugging-port=0 --no-first-run --no-default-browser-check https://admin.booking.com/'
Start-Process -FilePath $chrome -ArgumentList $arguments
Start-Sleep -Seconds 5
if (-not (Test-Path (Join-Path $profile 'DevToolsActivePort'))) { throw 'controlled_chrome_not_ready' }
