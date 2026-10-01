# Called only by the launcher, outside the kill-on-close Node job.
function Invoke-BookingBrowserRequest {
    param([string]$ChannelDirectory, [string]$DataDirectory)
    $request = Join-Path $ChannelDirectory 'request'
    $response = Join-Path $ChannelDirectory 'response'
    if (-not (Test-Path -LiteralPath $request) -or (Test-Path -LiteralPath $response)) { return }
    $item = Get-Item -LiteralPath $request -Force
    if ($item.PSIsContainer -or ($item.Attributes -band [IO.FileAttributes]::ReparsePoint) -or $item.Length -ne 32) { return }
    $id = [IO.File]::ReadAllText($request)
    if ($id -cnotmatch '^[a-f0-9]{32}$') { return }
    $code = 'browser_unavailable'
    $helper = $null
    try {
        $info = New-Object Diagnostics.ProcessStartInfo
        $info.FileName = Join-Path $env:SystemRoot 'System32\WindowsPowerShell\v1.0\powershell.exe'
        $info.Arguments = '-NoProfile -NonInteractive -File "' + (Join-Path $PSScriptRoot 'Start-Booking-Chrome.ps1') + '" -DataDirectory "' + $DataDirectory + '"'
        $info.UseShellExecute = $false; $info.CreateNoWindow = $true
        $info.RedirectStandardOutput = $true; $info.RedirectStandardError = $true
        $helper = [Diagnostics.Process]::Start($info)
        $helper.BeginOutputReadLine(); $helper.BeginErrorReadLine()
        # Includes the 30-second Chrome readiness window plus private-profile
        # validation and cold PowerShell startup, bounded by the Node deadline.
        if ($helper.WaitForExit(45000) -and $helper.ExitCode -eq 0) { $code = 'ready' }
    } catch { }
    finally {
        if ($helper) {
            if (-not $helper.HasExited) { $helper.Kill() }
            $helper.Dispose()
        }
    }
    # A timed-out request must never satisfy a later task.
    if (-not (Test-Path -LiteralPath $request) -or [IO.File]::ReadAllText($request) -cne $id) { return }
    $temporary = Join-Path $ChannelDirectory ($id + '.response.tmp')
    [IO.File]::WriteAllText($temporary, (@{id=$id;code=$code} | ConvertTo-Json -Compress), (New-Object Text.UTF8Encoding($false)))
    Move-Item -LiteralPath $temporary -Destination $response -Force
}
