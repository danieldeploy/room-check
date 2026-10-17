$ErrorActionPreference='Stop'
$root=Join-Path $env:TEMP ('invoice-job-test-'+[Guid]::NewGuid().ToString('N'))
$owner=$null; $child=$null
try {
    New-Item -ItemType Directory -Path $root | Out-Null
    $helper=Join-Path (Split-Path $PSScriptRoot -Parent) 'invoice-runner\windows\Agent-Job.ps1'
    $fixture=Join-Path $root 'owner.ps1'; $receipt=Join-Path $root 'child.txt'
    @'
param($Helper,$Receipt)
$ErrorActionPreference='Stop'
. $Helper
$job=New-Object InvoiceAgentJob
$info=New-Object Diagnostics.ProcessStartInfo
$info.FileName='powershell.exe'
$info.Arguments='-NoProfile -NonInteractive -Command Start-Sleep -Seconds 120'
$info.UseShellExecute=$false; $info.CreateNoWindow=$true
$child=[Diagnostics.Process]::Start($info)
$job.Attach($child.Handle)
[IO.File]::WriteAllText($Receipt,[string]$child.Id)
Start-Sleep -Seconds 120
'@ | Set-Content -LiteralPath $fixture -Encoding UTF8
    $arguments='-NoProfile -NonInteractive -ExecutionPolicy RemoteSigned -File "'+$fixture+'" -Helper "'+$helper+'" -Receipt "'+$receipt+'"'
    $owner=Start-Process powershell.exe -ArgumentList $arguments -PassThru -WindowStyle Hidden
    $deadline=(Get-Date).AddSeconds(20)
    while (-not (Test-Path $receipt) -and (Get-Date) -lt $deadline) { Start-Sleep -Milliseconds 100 }
    if (-not (Test-Path $receipt)) { throw 'Job fixture did not start.' }
    $child=Get-Process -Id ([int][IO.File]::ReadAllText($receipt))
    Stop-Process -Id $owner.Id -Force
    if (-not $child.WaitForExit(5000)) { throw 'A forced launcher stop left an orphaned worker.' }
    Write-Host 'Forced launcher stop terminates its worker through the Windows job.'
} finally {
    if ($child -and -not $child.HasExited) { Stop-Process -Id $child.Id -Force }
    if ($owner -and -not $owner.HasExited) { Stop-Process -Id $owner.Id -Force }
    if (Test-Path $root) { Remove-Item $root -Recurse -Force }
}
