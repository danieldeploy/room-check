param([Parameter(Mandatory=$true)][string]$DataDirectory)
$ErrorActionPreference = 'Stop'
if (-not [Environment]::UserInteractive) { throw 'interactive_session_required' }
if (-not (Test-Path -LiteralPath (Join-Path $DataDirectory 'controlled-booking-enabled'))) { throw 'controlled_chrome_disabled' }

$chrome = Join-Path ${env:ProgramFiles} 'Google\Chrome\Application\chrome.exe'
if (-not (Test-Path -LiteralPath $chrome)) { throw 'chrome_unavailable' }

function Test-ControlledEndpoint {
    param([string]$EndpointFile)
    $response = $null; $reader = $null
    try {
        $item = Get-Item -LiteralPath $EndpointFile -Force -ErrorAction Stop
        if ($item.PSIsContainer -or ($item.Attributes -band [IO.FileAttributes]::ReparsePoint) -or $item.Length -gt 256) { return $false }
        $lines = [IO.File]::ReadAllText($EndpointFile).Trim() -split '\r?\n'
        if ($lines.Count -ne 2 -or $lines[0] -cnotmatch '^[1-9][0-9]{0,4}$' -or [int]$lines[0] -gt 65535 -or
            $lines[1] -cnotmatch '^/devtools/browser/[a-zA-Z0-9-]{8,80}$') { return $false }
        $port = $lines[0]
        $request = [Net.HttpWebRequest]::Create('http://127.0.0.1:' + $port + '/json/version')
        $request.Proxy = $null; $request.AllowAutoRedirect = $false
        $request.Timeout = 1000; $request.ReadWriteTimeout = 1000
        $response = $request.GetResponse()
        if ([int]$response.StatusCode -ne 200) { return $false }
        $reader = New-Object IO.StreamReader($response.GetResponseStream())
        $buffer = New-Object char[] 4096
        $count = $reader.ReadBlock($buffer, 0, $buffer.Length)
        if ($count -eq $buffer.Length) { return $false }
        $version = (-join $buffer[0..($count-1)]) | ConvertFrom-Json
        return $version.webSocketDebuggerUrl -ceq ('ws://127.0.0.1:' + $port + $lines[1])
    } catch { return $false }
    finally { if ($reader) { $reader.Dispose() }; if ($response) { $response.Dispose() } }
}

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
    if (-not $existing) {
        # Chrome can leave a stale endpoint after exit. Its presence is not readiness.
        if (Test-Path -LiteralPath $endpointFile) {
            $item = Get-Item -LiteralPath $endpointFile -Force
            if ($item.PSIsContainer -or ($item.Attributes -band [IO.FileAttributes]::ReparsePoint)) { throw 'controlled_endpoint_invalid' }
            Remove-Item -LiteralPath $endpointFile -Force
        }
        $arguments = $profileSwitch + ' --remote-debugging-address=127.0.0.1 --remote-debugging-port=0 --no-first-run --no-default-browser-check ' + $StartUrl
        Start-Process -FilePath $chrome -ArgumentList $arguments | Out-Null
    }
    $clock = [Diagnostics.Stopwatch]::StartNew()
    # Cold profile initialization can outlast 15 seconds on a loaded computer.
    while ($clock.Elapsed.TotalSeconds -lt 30) {
        if (Test-ControlledEndpoint -EndpointFile $endpointFile) { return }
        Start-Sleep -Milliseconds 200
    }
    throw 'controlled_chrome_not_ready'
}

# Login, mapping and collection share the already authenticated profile.
# Keep the historical directory name so upgrading never loses its cookies.
Start-ControlledProfile -Name 'controlled-booking-login-chrome' -StartUrl 'about:blank'
