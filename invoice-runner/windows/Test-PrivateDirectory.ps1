param([Parameter(Mandatory=$true)][string]$Directory)
$ErrorActionPreference = 'Stop'
try {
    $item = Get-Item -LiteralPath $Directory -Force
    if (-not $item.PSIsContainer -or ($item.Attributes -band [IO.FileAttributes]::ReparsePoint)) { exit 1 }
    $identity = [Security.Principal.WindowsIdentity]::GetCurrent()
    $allowed = @($identity.User.Value, 'S-1-5-18', 'S-1-5-32-544')
    $acl = Get-Acl -LiteralPath $Directory
    foreach ($rule in $acl.Access) {
        if ($rule.AccessControlType -eq 'Allow') {
            $sid = $rule.IdentityReference.Translate([Security.Principal.SecurityIdentifier]).Value
            if ($allowed -notcontains $sid) { exit 1 }
        }
    }
    if (-not $acl.AreAccessRulesProtected) { exit 1 }
    exit 0
} catch { exit 1 }
