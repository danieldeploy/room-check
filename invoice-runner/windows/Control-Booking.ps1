param(
    [Parameter(Mandatory=$true)][ValidateSet('status','pause','resume','revoke','login','collect','enable-captcha','enable-alerts')][string]$Action,
    [string]$Period,
    [string]$RequestId,
    [int]$Recipient = 0
)
$ErrorActionPreference = 'Stop'
try {
    $request = @{ action = 'control_' + $Action.Replace('-', '_') }
    if ($Action -in @('status','login','collect')) {
        if ($Period -cnotmatch '^20[0-9]{2}-(0[1-9]|1[0-2])$') { throw 'invalid_period' }
        $request.period = $Period
    }
    if ($Action -in @('login','collect')) {
        if ($RequestId -cnotmatch '^[a-f0-9]{32}$') { throw 'request_id_required' }
        $request.request_id = $RequestId
    }
    if ($Action -eq 'enable-alerts') { $request.recipient = $Recipient }
    $app = Split-Path $PSScriptRoot -Parent
    $data = Join-Path (Split-Path $app -Parent) 'data'
    & powershell.exe -NoProfile -NonInteractive -File (Join-Path $PSScriptRoot 'Test-PrivateDirectory.ps1') -Directory $data
    if ($LASTEXITCODE -ne 0) { throw 'private_storage_permissions' }
    Add-Type -AssemblyName System.Security
    $encrypted = [IO.File]::ReadAllBytes((Join-Path $data 'agent.dpapi'))
    $plain = [Security.Cryptography.ProtectedData]::Unprotect($encrypted,$null,[Security.Cryptography.DataProtectionScope]::CurrentUser)
    try { $config = [Text.Encoding]::UTF8.GetString($plain) | ConvertFrom-Json }
    finally { [Array]::Clear($plain,0,$plain.Length) }
    $info = New-Object Diagnostics.ProcessStartInfo
    $info.FileName = [string]$config.node
    $info.Arguments = '"' + (Join-Path $app 'test-control.mjs') + '"'
    $info.WorkingDirectory = $app
    $info.UseShellExecute = $false; $info.CreateNoWindow = $true; $info.RedirectStandardInput = $true
    $process = [Diagnostics.Process]::Start($info)
    $bytes = [Text.Encoding]::UTF8.GetBytes((@{endpoint=$config.endpoint;token=$config.token;request=$request} | ConvertTo-Json -Depth 4 -Compress))
    try { $process.StandardInput.BaseStream.Write($bytes,0,$bytes.Length); $process.StandardInput.BaseStream.Close() }
    finally { [Array]::Clear($bytes,0,$bytes.Length); $config.token = $null }
    $process.WaitForExit(); exit $process.ExitCode
} catch { Write-Output '{"error":"control_unavailable"}'; exit 1 }
