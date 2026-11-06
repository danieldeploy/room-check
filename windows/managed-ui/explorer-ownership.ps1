# Safely manage Explorer windows explicitly created by a ChatGPT-owned task.
param([ValidateSet('open','close')][string]$Action,[string]$Folder,[long]$Handle)
$ErrorActionPreference = 'Stop'
function Get-OwnedCandidates($shell) {
  $found = @()
  foreach ($window in @($shell.Windows())) {
    try {
      if ([IO.Path]::GetFileName([string]$window.FullName) -ine 'explorer.exe') { continue }
      $url = [Uri][string]$window.LocationURL
      if ($url.Scheme -ne 'file') { continue }
      $folderPath = [IO.Path]::GetFullPath($url.LocalPath).TrimEnd('\')
      $found += [pscustomobject]@{
        Window = $window; Hwnd = [long]$window.HWND;
        Folder = $folderPath; Busy = [bool]$window.Busy
      }
    } catch { }
  }
  return $found
}
$shell = New-Object -ComObject Shell.Application
if ($Action -eq 'open') {
  $directory = Get-Item -LiteralPath $Folder -ErrorAction Stop
  if (-not $directory.PSIsContainer) { throw 'invalid_directory' }
  $expected = $directory.FullName.TrimEnd('\')
  $before = @(Get-OwnedCandidates $shell | ForEach-Object { $_.Hwnd })
  $shell.Explore($expected)
  $owned = @()
  for ($n = 0; $n -lt 40; $n++) {
    Start-Sleep -Milliseconds 200
    $owned = @(Get-OwnedCandidates $shell | Where-Object {
      $before -notcontains $_.Hwnd -and $_.Folder -ieq $expected
    })
    if ($owned.Count -ne 0) { break }
  }
  if ($owned.Count -eq 1) {
    [pscustomobject]@{ ok = $true; hwnd = $owned[0].Hwnd; folder = $expected; status = 'registered' } | ConvertTo-Json -Compress
  } else {
    [pscustomobject]@{ ok = $false; status = 'ownership_uncertain' } | ConvertTo-Json -Compress
  }
} else {
  $expected = [IO.Path]::GetFullPath($Folder).TrimEnd('\')
  $owned = @(Get-OwnedCandidates $shell | Where-Object {
    $_.Hwnd -eq $Handle -and $_.Folder -ieq $expected -and -not $_.Busy
  })
  if ($owned.Count -ne 1) {
    [pscustomobject]@{ ok = $false; status = 'preserved_unknown_or_busy' } | ConvertTo-Json -Compress
  } else {
    # Quit affects only the COM window whose HWND and folder both match.
    $owned[0].Window.Quit()
    Start-Sleep -Milliseconds 500
    $stillPresent = @(Get-OwnedCandidates $shell | Where-Object { $_.Hwnd -eq $Handle }).Count -gt 0
    [pscustomobject]@{ ok = (-not $stillPresent); status = $(if ($stillPresent) { 'preserved_unconfirmed' } else { 'closed' }) } | ConvertTo-Json -Compress
  }
}
