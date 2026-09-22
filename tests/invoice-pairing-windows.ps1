$ErrorActionPreference = 'Stop'
$repo = Split-Path $PSScriptRoot -Parent
$root = Join-Path $env:TEMP ('invoice-pairing-' + [char]0xE3 + '-' + [Guid]::NewGuid().ToString('N'))
$previousLocalData = $env:LOCALAPPDATA
try {
    $env:LOCALAPPDATA = $root
    $installed = Join-Path $root 'ManagementHub\Invoices'
    $data = Join-Path $installed 'data'
    $scripts = Join-Path $installed 'app\windows'
    [IO.Directory]::CreateDirectory($data) | Out-Null
    [IO.Directory]::CreateDirectory($scripts) | Out-Null
    $sid = [Security.Principal.WindowsIdentity]::GetCurrent().User
    $acl = New-Object Security.AccessControl.DirectorySecurity
    $acl.SetAccessRuleProtection($true,$false)
    $acl.AddAccessRule((New-Object Security.AccessControl.FileSystemAccessRule($sid,'FullControl','ContainerInherit,ObjectInherit','None','Allow')))
    Set-Acl -LiteralPath $data -AclObject $acl
    $pair = Join-Path $repo 'invoice-runner\windows\Pair-Encrypted.ps1'
    $request = & powershell.exe -NoProfile -NonInteractive -ExecutionPolicy RemoteSigned -File $pair -Prepare | Out-String
    if ($LASTEXITCODE -ne 0) { throw 'Public pairing request failed.' }
    $pem = [regex]::Match($request,'(?s)-----BEGIN PUBLIC KEY-----.*?-----END PUBLIC KEY-----').Value
    if (-not $pem -or $request.Contains('PRIVATE KEY')) { throw 'Invalid public request.' }
    $publicFile = Join-Path $root 'public.pem'
    $packageFile = Join-Path $root 'sealed.json'
    [IO.File]::WriteAllText($publicFile,$pem,(New-Object Text.UTF8Encoding($false)))
    & node (Join-Path $repo 'tests\invoice-pairing-windows.mjs') seal $publicFile $packageFile
    if ($LASTEXITCODE -ne 0) { throw 'Sealed fixture failed.' }
    & powershell.exe -NoProfile -NonInteractive -ExecutionPolicy RemoteSigned -File $pair -PackageFile $packageFile
    if ($LASTEXITCODE -ne 0) { throw 'Pairing import failed.' }
    if (Test-Path -LiteralPath (Join-Path $data 'pending-pairing.dpapi')) { throw 'Pending private key not removed.' }
    if (-not (Test-Path -LiteralPath (Join-Path $data 'agent.dpapi'))) { throw 'Protected configuration missing.' }
    # Exercise the real launcher with a local fixture that validates its private stdin.
    Copy-Item (Join-Path $repo 'invoice-runner\windows\Run-Agent.ps1') $scripts
    Copy-Item (Join-Path $repo 'invoice-runner\windows\Test-PrivateDirectory.ps1') $scripts
    Copy-Item (Join-Path $repo 'tests\invoice-pairing-windows.mjs') (Join-Path $installed 'app\windows-agent.mjs')
    & powershell.exe -NoProfile -NonInteractive -ExecutionPolicy RemoteSigned -File (Join-Path $scripts 'Run-Agent.ps1') -TestOnly
    if ($LASTEXITCODE -ne 0) { throw 'Protected agent launch failed.' }
    Write-Host 'Encrypted pairing, DPAPI storage and Windows PowerShell launcher passed.'
} finally {
    $env:LOCALAPPDATA = $previousLocalData
    if (Test-Path -LiteralPath $root) { Remove-Item -LiteralPath $root -Recurse -Force }
}
exit 0
