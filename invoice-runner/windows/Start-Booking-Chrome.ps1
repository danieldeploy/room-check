param([Parameter(Mandatory=$true)][string]$DataDirectory)
$ErrorActionPreference = 'Stop'
if (-not [Environment]::UserInteractive) { throw 'interactive_session_required' }
if (-not (Test-Path -LiteralPath (Join-Path $DataDirectory 'controlled-booking-enabled'))) { return }

$chrome = Join-Path ${env:ProgramFiles} 'Google\Chrome\Application\chrome.exe'
if (-not (Test-Path -LiteralPath $chrome)) { throw 'chrome_unavailable' }

function Start-ControlledProfile {
    param([string]$Name, [string]$StartUrl)
    $profile = Join-Path $DataDirectory $Name
    if (-not (Test-Path -LiteralPath $profile)) {
        [IO.Directory]::CreateDirectory($profile) | Out-Null
        $sid = [Security.Principal.WindowsIdentity]::GetCurrent().User
        $acl = New-Object Security.AccessControl.DirectorySecurity
        $acl.SetAccessRuleProtection($true, $false)
        foreach ($identity in @($sid, (New-Object Security.Principal.SecurityIdentifier('S-1-5-18')))) {
            $rule = New-Object Security.AccessControl.FileSystemAccessRule($identity, 'FullControl', 'ContainerInherit,ObjectInherit', 'None', 'Allow')
            $acl.AddAccessRule($rule)
        }
        Set-Acl -LiteralPath $profile -AclObject $acl
    }
    & powershell.exe -NoProfile -NonInteractive -File (Join-Path $PSScriptRoot 'Test-PrivateDirectory.ps1') -Directory $profile
    if ($LASTEXITCODE -ne 0) { throw 'private_profile_permissions' }

    $profileSwitch = '--user-data-dir="' + $profile + '"'
    $existing = Get-CimInstance Win32_Process | Where-Object {
        $_.Name -eq 'chrome.exe' -and $_.CommandLine -and
            $_.CommandLine.IndexOf($profileSwitch, [StringComparison]::OrdinalIgnoreCase) -ge 0
    }
    $endpointFile = Join-Path $profile 'DevToolsActivePort'
    if ($existing) {
        if (Test-Path -LiteralPath $endpointFile) { return }
        throw 'controlled_chrome_not_ready'
    }

    $arguments = $profileSwitch + ' --remote-debugging-address=127.0.0.1 --remote-debugging-port=0 --no-first-run --no-default-browser-check ' + $StartUrl
    Start-Process -FilePath $chrome -ArgumentList $arguments
    Start-Sleep -Seconds 5
    if (-not (Test-Path -LiteralPath $endpointFile)) { throw 'controlled_chrome_not_ready' }
}

# The existing browser and its cookies remain untouched.
Start-ControlledProfile -Name 'controlled-booking-chrome' -StartUrl 'https://admin.booking.com/'
# A failed smoke-test browser must not prevent the primary agent from running.
try {
    Start-ControlledProfile -Name 'controlled-booking-login-chrome' -StartUrl 'about:blank'
} catch {
    Write-Warning 'fresh_login_chrome_unavailable'
}
