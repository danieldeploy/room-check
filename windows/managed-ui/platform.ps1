param([Parameter(Mandatory=$true)][ValidateSet('install','schedule','status','open-explorer','inspect','close','process','start-chrome')][string]$Action)
$ErrorActionPreference='Stop'
$raw=[Console]::In.ReadToEnd();$request=if($raw){$raw|ConvertFrom-Json}else{[pscustomobject]@{}}
$root=Split-Path -Parent $MyInvocation.MyCommand.Path
$taskName='ChatGPT ManagedUI Idle Cleanup'
function Output($x){$x|ConvertTo-Json -Depth 5 -Compress}
try {
 if($Action -in @('install','schedule','status')){
  $service=New-Object -ComObject Schedule.Service;$service.Connect();$folder=$service.GetFolder('\')
  $task=$null;try{$task=$folder.GetTask($taskName)}catch{}
  if($task -and $task.Definition.RegistrationInfo.Description -ne 'ManagedUI v2: task-owned temporary windows only'){throw 'foreign_task'}
  if($Action -eq 'install'){
   $definition=$service.NewTask(0);$definition.RegistrationInfo.Description='ManagedUI v2: task-owned temporary windows only'
   $definition.Principal.UserId=[Security.Principal.WindowsIdentity]::GetCurrent().Name
   $definition.Principal.LogonType=3;$definition.Principal.RunLevel=0
   $definition.Settings.Enabled=$false;$definition.Settings.Hidden=$true;$definition.Settings.StartWhenAvailable=$true
   $definition.Settings.DisallowStartIfOnBatteries=$false;$definition.Settings.StopIfGoingOnBatteries=$false
   $definition.Settings.ExecutionTimeLimit='PT2M';$definition.Settings.MultipleInstances=2
   $trigger=$definition.Triggers.Create(1);$trigger.StartBoundary=(Get-Date).AddMinutes(30).ToString('yyyy-MM-ddTHH:mm:ss')
   $trigger.Repetition.Interval='PT30M';$trigger.Enabled=$true
   $launch=$definition.Actions.Create(0);$launch.Path=Join-Path $PSHOME 'powershell.exe'
   $launch.Arguments='-NoProfile -NonInteractive -WindowStyle Hidden -ExecutionPolicy RemoteSigned -File "'+(Join-Path $root 'sweep.ps1')+'"'
   $task=$folder.RegisterTaskDefinition($taskName,$definition,6,$definition.Principal.UserId,$null,3)
  }
  if(-not $task){throw 'scheduler_missing'}
  if($Action -eq 'schedule'){$task.Enabled=($request.enabled -eq $true)}
  Output @{ok=$true;enabled=[bool]$task.Enabled;interval=[string]$task.Definition.Triggers.Item(1).Repetition.Interval;lastResult=$task.LastTaskResult};exit 0
 }
 if($Action -eq 'start-chrome'){
  $profile=if($request.profile -eq 'primary'){'controlled-booking-chrome'}elseif($request.profile -eq 'fresh_login'){'controlled-booking-login-chrome'}else{throw 'invalid_profile'}
  $directory=Join-Path $env:LOCALAPPDATA ('ManagementHub\Invoices\data\'+$profile)
  if(-not (Test-Path -LiteralPath $directory -PathType Container)){throw 'missing_profile'}
  $candidates=@((Join-Path $env:ProgramFiles 'Google\Chrome\Application\chrome.exe'),(Join-Path ${env:ProgramFiles(x86)} 'Google\Chrome\Application\chrome.exe'))
  $exe=$candidates|Where-Object{Test-Path -LiteralPath $_ -PathType Leaf}|Select-Object -First 1
  if(-not $exe){throw 'chrome_unavailable'}
  Start-Process -FilePath $exe -ArgumentList @('--remote-debugging-port=0',('--user-data-dir="'+$directory+'"'),'--no-first-run','--no-default-browser-check','about:blank')|Out-Null
  Output @{ok=$true;requested=$true};exit 0
 }
 if($Action -eq 'process'){
  $p=Get-Process -Id ([int]$request.pid) -ErrorAction SilentlyContinue
  Output @{ok=$true;alive=($null -ne $p -and $p.StartTime.ToUniversalTime().Ticks.ToString() -eq [string]$request.started)};exit 0
 }
 # Read-only Windows observations. Closing remains delegated to the existing Explorer helper.
 Add-Type -TypeDefinition @'
using System;
using System.Runtime.InteropServices;
public static class OwnedUIObservation {
 [StructLayout(LayoutKind.Sequential)] public struct LASTINPUTINFO { public uint cbSize; public uint dwTime; }
 [DllImport("user32.dll")] static extern bool GetLastInputInfo(ref LASTINPUTINFO x);
 [DllImport("user32.dll")] public static extern IntPtr GetForegroundWindow();
 [DllImport("user32.dll")] public static extern uint GetWindowThreadProcessId(IntPtr h, out uint pid);
 public static long IdleMs(){var x=new LASTINPUTINFO();x.cbSize=(uint)Marshal.SizeOf(x);if(!GetLastInputInfo(ref x))return 0;return unchecked((uint)Environment.TickCount-x.dwTime);}
}
'@
 function Identity([long]$h){
  [uint32]$owner=0;[void][OwnedUIObservation]::GetWindowThreadProcessId([IntPtr]$h,[ref]$owner)
  if($owner -eq 0){return $null};$p=Get-Process -Id $owner -ErrorAction Stop
  return [pscustomobject]@{pid=[int]$owner;started=$p.StartTime.ToUniversalTime().Ticks.ToString();name=$p.ProcessName;cpu=[double]$p.CPU}
 }
 $legacy=Join-Path $root 'explorer-ownership.ps1'
 if($Action -eq 'open-explorer'){
  $output=& $legacy -Action open -Folder $request.folder
  $opened=($output -join "`n")|ConvertFrom-Json
  if(-not $opened.ok){throw 'ownership_unconfirmed'}
  $identity=Identity ([long]$opened.hwnd)
  if(-not $identity -or $identity.name -ne 'explorer'){throw 'identity_unconfirmed'}
  Output @{ok=$true;resource=@{kind='explorer_window';hwnd=[long]$opened.hwnd;pid=$identity.pid;started=$identity.started;folder=$opened.folder;sample=$identity.cpu}};exit 0
 }
 $item=$request.resource
 if($item.kind -ne 'explorer_window' -or -not $item.folder -or [long]$item.hwnd -le 0){throw 'unsupported_resource'}
 $shell=New-Object -ComObject Shell.Application
 $matches=@($shell.Windows()|Where-Object{[long]$_.HWND -eq [long]$item.hwnd -and [IO.Path]::GetFileName([string]$_.FullName) -ieq 'explorer.exe'})
 if($matches.Count -eq 0){Output @{ok=$true;exists=$false};exit 0}
 if($matches.Count -ne 1){throw 'ambiguous_window'}
 $window=$matches[0];$uri=[Uri][string]$window.LocationURL
 $identity=Identity ([long]$item.hwnd)
 $same=($identity -and $identity.pid -eq $item.pid -and $identity.started -eq [string]$item.started -and $uri.Scheme -eq 'file' -and [IO.Path]::GetFullPath($uri.LocalPath).TrimEnd('\') -ieq [string]$item.folder)
 if(-not $same){Output @{ok=$true;exists=$true;identity=$false};exit 0}
 $idle=[OwnedUIObservation]::IdleMs();$now=[DateTimeOffset]::UtcNow.ToUnixTimeMilliseconds()
 $busy=[bool]$window.Busy -or $null -eq $item.sample -or $identity.cpu-[double]$item.sample -gt 0.5
 $safe=(-not $busy)
 if($request.completed -ne $true -and [OwnedUIObservation]::GetForegroundWindow().ToInt64() -eq [long]$item.hwnd){$safe=$false}
 if($request.completed -eq $true -and $now-$idle -gt [long]$item.createdAt){$safe=$false}
 $activity=if($idle -lt 1800000){$now-$idle}else{0};if($busy){$activity=$now}
 if($Action -eq 'inspect'){Output @{ok=$true;exists=$true;identity=$true;safe=$safe;busy=$busy;lastActivity=$activity;sample=$identity.cpu};exit 0}
 if(-not $safe -or ($request.completed -ne $true -and $idle -lt 1800000)){Output @{ok=$true;closed=$false};exit 0}
 $output=& $legacy -Action close -Folder $item.folder -Handle $item.hwnd
 $closed=($output -join "`n")|ConvertFrom-Json
 Output @{ok=$true;closed=($closed.ok -eq $true -and $closed.status -eq 'closed')}
} catch {Output @{ok=$false;error='platform_unavailable'};exit 1}
