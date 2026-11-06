$ErrorActionPreference='Stop'
$root=Split-Path -Parent $MyInvocation.MyCommand.Path
$node=Join-Path $env:ProgramFiles 'nodejs\node.exe'
try {
 $result=& $node (Join-Path $root 'managed-ui.mjs') sweep
 $code=$LASTEXITCODE
 # Only counters/fixed status leave the controller. No titles, URLs or command lines.
 [IO.File]::WriteAllText((Join-Path $root 'last-check.json'),($result -join "`n"),(New-Object Text.UTF8Encoding($false)))
 exit $code
} catch {exit 1}
