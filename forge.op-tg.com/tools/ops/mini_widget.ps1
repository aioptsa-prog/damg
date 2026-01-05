param(
  [string]$Url = 'http://127.0.0.1:4499/mini',
  [int]$Width = 460,
  [int]$Height = 340,
  [int]$Left = 40,
  [int]$Top = 40,
  [switch]$NoTopMost,
  [switch]$KeepAlive
)
$ErrorActionPreference = 'Stop'
$WindowTitle = '[LMPro Mini]'

# Single-instance guard to avoid multiple launch loops
Add-Type -AssemblyName System.Core | Out-Null
$global:MiniMutex = $null
$createdNew = $false
try {
  $global:MiniMutex = New-Object System.Threading.Mutex($true, 'Global/OPT_Nexus_MiniWidget', [ref]$createdNew)
} catch {
  # Fallback to session-local mutex if Global namespace is not permitted
  try { $global:MiniMutex = New-Object System.Threading.Mutex($true, 'Local/OPT_Nexus_MiniWidget', [ref]$createdNew) } catch {}
}
if(-not $createdNew -and $global:MiniMutex){
  # If another instance is guarding, request focus then exit
  try { Invoke-WebRequest -UseBasicParsing -Uri 'http://127.0.0.1:47771/focus' -TimeoutSec 1 | Out-Null } catch {}
  Write-Host 'Instance already running — focusing existing window.' -ForegroundColor Yellow
  exit 0
}

function Find-BrowserExe {
  $candidates = @(
    "$Env:ProgramFiles (x86)\Microsoft\Edge\Application\msedge.exe",
    "$Env:ProgramFiles\Microsoft\Edge\Application\msedge.exe",
    "$Env:LocalAppData\Microsoft\Edge\Application\msedge.exe",
    "$Env:ProgramFiles (x86)\Google\Chrome\Application\chrome.exe",
    "$Env:ProgramFiles\Google\Chrome\Application\chrome.exe",
    "$Env:LocalAppData\Google\Chrome\Application\chrome.exe"
  )
  foreach($p in $candidates){ if(Test-Path $p){ return $p } }
  return $null
}

$exe = Find-BrowserExe
if(-not $exe){
  Write-Warning 'لم يتم العثور على Edge/Chrome؛ سيتم فتح المتصفح الافتراضي بدون خصائص window app.'
  Start-Process $Url | Out-Null
  exit 0
}

# Prefer app mode to remove toolbars
$launchArgs = @()
if($exe -like '*msedge.exe'){
  $launchArgs += @("--app=$Url", "--window-size=$Width,$Height")
} else {
  $launchArgs += @("--app=$Url", "--window-size=$Width,$Height")
}

function Start-AppWindow {
  return Start-Process -FilePath $exe -ArgumentList $launchArgs -PassThru
}

function Find-ExistingAppProc {
  try {
    $name = (Split-Path $exe -Leaf)
    $procs = Get-CimInstance Win32_Process -Filter "Name = '$name'" -ErrorAction SilentlyContinue
    foreach($p in $procs){
      $cmd = ''
      try { if($p.CommandLine){ $cmd = [string]$p.CommandLine } } catch {}
        if($cmd -and $cmd -like "*--app=$Url*"){
        try { return Get-Process -Id $p.ProcessId -ErrorAction Stop } catch { }
      }
    }
  } catch { }
  return $null
}

# Attach to existing app window if already open; otherwise launch
$proc = Find-ExistingAppProc
if(-not $proc){
  $proc = Start-AppWindow
} else {
  # Focus existing app window immediately
  try {
    $h = [IntPtr]::Zero; try { $proc.Refresh() | Out-Null; $h = $proc.MainWindowHandle } catch {}
    if($h -ne [IntPtr]::Zero){
      if([TopMostWin]::IsIconic($h)){ [TopMostWin]::ShowWindow($h, 9) | Out-Null }
      [TopMostWin]::SetForegroundWindow($h) | Out-Null
      if(-not $NoTopMost){ [TopMostWin]::SetWindowPos($h, $HWND_TOPMOST, $Left, $Top, $Width, $Height, $SWP_SHOWWINDOW) | Out-Null }
    }
  } catch {}
}

# Try to set window topmost and position (best effort)
Add-Type -TypeDefinition @'
public static class TopMostWin {
  [System.Runtime.InteropServices.DllImport("user32.dll")]
  public static extern bool SetWindowPos(System.IntPtr hWnd, System.IntPtr hWndInsertAfter, int X, int Y, int cx, int cy, uint uFlags);
  [System.Runtime.InteropServices.DllImport("user32.dll")]
  public static extern bool ShowWindow(System.IntPtr hWnd, int nCmdShow);
  [System.Runtime.InteropServices.DllImport("user32.dll")]
  public static extern bool SetForegroundWindow(System.IntPtr hWnd);
  [System.Runtime.InteropServices.DllImport("user32.dll")]
  public static extern bool IsIconic(System.IntPtr hWnd);
  [System.Runtime.InteropServices.DllImport("user32.dll")]
  public static extern System.IntPtr GetForegroundWindow();
}
'@

# Window enumeration helpers to find the Edge/Chrome app window by title even if parent process changes
Add-Type -TypeDefinition @'
using System; using System.Runtime.InteropServices; using System.Text;
public static class WinEnum {
  public delegate bool EnumWindowsProc(IntPtr hWnd, IntPtr lParam);
  [DllImport("user32.dll")] public static extern bool EnumWindows(EnumWindowsProc lpEnumFunc, IntPtr lParam);
  [DllImport("user32.dll")] public static extern bool IsWindowVisible(IntPtr hWnd);
  [DllImport("user32.dll", CharSet=CharSet.Unicode)] public static extern int GetWindowText(IntPtr hWnd, StringBuilder text, int count);
  [DllImport("user32.dll")] public static extern uint GetWindowThreadProcessId(IntPtr hWnd, out uint processId);
  public static IntPtr FindByTitle(string needle){
    IntPtr found = IntPtr.Zero; needle = (needle??"").Trim(); if(needle.Length==0) return IntPtr.Zero;
    EnumWindows((h,l)=>{
      if(!IsWindowVisible(h)) return true;
      var sb = new StringBuilder(512); GetWindowText(h, sb, sb.Capacity);
      var t = sb.ToString(); if(!string.IsNullOrEmpty(t) && t.IndexOf(needle, StringComparison.OrdinalIgnoreCase)>=0){ found = h; return false; }
      return true;
    }, IntPtr.Zero);
    return found;
  }
  public static int GetPidForHwnd(IntPtr h){ uint pid=0; GetWindowThreadProcessId(h, out pid); return unchecked((int)pid); }
}
'@

$SWP_NOSIZE = 0x0001
$SWP_NOMOVE = 0x0002
$SWP_NOACTIVATE = 0x0010
$SWP_SHOWWINDOW = 0x0040
$HWND_TOPMOST = [IntPtr]::new(-1)
$HWND_NOTOPMOST = [IntPtr]::new(-2)

function Apply-TopMostAndPos([System.Diagnostics.Process]$p){
  $h = [IntPtr]::Zero
  # Prefer process main window if available
  for($j=0; $j -lt 50; $j++){
    Start-Sleep -Milliseconds 80
    try{ $p.Refresh() | Out-Null; if($p.HasExited){ break }; if($p.MainWindowHandle -ne [IntPtr]::Zero){ $h = $p.MainWindowHandle; break } }catch{}
  }
  # Fallback: find by title (Edge/Chrome can respawn child window)
  if($h -eq [IntPtr]::Zero){ try{ $h = [WinEnum]::FindByTitle($WindowTitle) } catch {} }
  if($h -ne [IntPtr]::Zero){
    [TopMostWin]::SetWindowPos($h, $HWND_TOPMOST, $Left, $Top, $Width, $Height, $SWP_SHOWWINDOW) | Out-Null
    if([TopMostWin]::IsIconic($h)){ [TopMostWin]::ShowWindow($h, 9) | Out-Null } # SW_RESTORE
    if($NoTopMost){ [TopMostWin]::SetWindowPos($h, $HWND_NOTOPMOST, 0,0,0,0, $SWP_NOMOVE -bor $SWP_NOSIZE -bor $SWP_NOACTIVATE) | Out-Null }
  }
}

Apply-TopMostAndPos -p $proc

Write-Host "Mini worker dashboard opened in App Mode (TopMost=$(-not $NoTopMost))." -ForegroundColor Green

if($KeepAlive){
  # Lightweight local guard: keep the process alive and allow controlled exit from UI
  Add-Type -TypeDefinition @'
using System; using System.Net; using System.Text; using System.IO;
public class MiniGuard {
  public static string SignalPath = null;
  public static string FocusPath = null;
  HttpListener l;
  public void Start(){ l = new HttpListener(); l.Prefixes.Add("http://127.0.0.1:47771/"); l.Start(); l.BeginGetContext(Callback, null); }
  void Callback(IAsyncResult ar){ try{ var ctx = l.EndGetContext(ar); l.BeginGetContext(Callback, null);
      var path = ctx.Request.Url.AbsolutePath.Trim('/').ToLowerInvariant();
      if(path=="exit"){ try{ if(!string.IsNullOrEmpty(SignalPath)) File.WriteAllText(SignalPath, DateTime.UtcNow.ToString("o")); } catch {}
        var buf=Encoding.UTF8.GetBytes("OK"); ctx.Response.OutputStream.Write(buf,0,buf.Length); ctx.Response.Close(); l.Stop(); return; }
      if(path=="focus"){ try{ if(!string.IsNullOrEmpty(FocusPath)) File.WriteAllText(FocusPath, DateTime.UtcNow.ToString("o")); } catch {}
        var bf=Encoding.UTF8.GetBytes("OK"); ctx.Response.OutputStream.Write(bf,0,bf.Length); ctx.Response.Close(); return; }
      var b=Encoding.UTF8.GetBytes("ok"); ctx.Response.OutputStream.Write(b,0,b.Length); ctx.Response.Close(); } catch{} }
}
'@

  $signalPath = Join-Path $env:TEMP "mini_widget_exit.signal"
  $focusPath = Join-Path $env:TEMP "mini_widget_focus.signal"
  if(Test-Path $signalPath){ Remove-Item $signalPath -Force -ErrorAction SilentlyContinue }
  [MiniGuard]::FocusPath = $focusPath
  [MiniGuard]::SignalPath = $signalPath
  $guard = New-Object MiniGuard
  try { $guard.Start() } catch { Write-Host ("MiniGuard disabled: " + $_.Exception.Message) -ForegroundColor DarkYellow }

  # Keep alive: relaunch if closed; periodically reassert TopMost; stop when signal file is written
  try {
    $tick = 0
    $lastLaunch = Get-Date '2000-01-01'
    while($true){
      Start-Sleep -Milliseconds 500
      $tick++
      try{
        if([System.IO.File]::Exists($signalPath)){
          try { $proc.Kill() } catch {}
          Remove-Item $signalPath -Force -ErrorAction SilentlyContinue
          break
        }
        if([System.IO.File]::Exists($focusPath)){
          try {
            $hCur = [IntPtr]::Zero
            try {
              $proc.Refresh() | Out-Null
              if(-not $proc.HasExited){ $hCur = $proc.MainWindowHandle }
            } catch {}
            if($hCur -eq [IntPtr]::Zero){ try { $hCur = [WinEnum]::FindByTitle($WindowTitle) } catch {} }
            if($hCur -ne [IntPtr]::Zero){
              if([TopMostWin]::IsIconic($hCur)){ [TopMostWin]::ShowWindow($hCur, 9) | Out-Null }
              [TopMostWin]::SetForegroundWindow($hCur) | Out-Null
              if(-not $NoTopMost){ [TopMostWin]::SetWindowPos($hCur, $HWND_TOPMOST, $Left, $Top, $Width, $Height, $SWP_SHOWWINDOW) | Out-Null }
            }
          } catch {}
          Remove-Item $focusPath -Force -ErrorAction SilentlyContinue
        }
        $hasWindow = $false
        try { $hasWindow = ([WinEnum]::FindByTitle($WindowTitle) -ne [IntPtr]::Zero) } catch {}
        if($proc.HasExited){
          # Try to attach to an existing app-mode window first
          $existing = Find-ExistingAppProc
          if($existing){ $proc = $existing }
          elseif($hasWindow){ Apply-TopMostAndPos -p $proc }
          else {
            # Throttle relaunch to prevent loops if browser respawns child and exits parent
            if(((Get-Date) - $lastLaunch).TotalSeconds -lt 20){ continue }
            $proc = Start-AppWindow; $lastLaunch = Get-Date; Apply-TopMostAndPos -p $proc
          }
        }
        else {
          if(($tick % 6) -eq 0 -and -not $NoTopMost){ Apply-TopMostAndPos -p $proc }
          if(($tick % 10) -eq 0 -and -not $NoTopMost){
            $hCur = [IntPtr]::Zero; try { $hCur = $proc.MainWindowHandle } catch {}
            if($hCur -eq [IntPtr]::Zero){ try { $hCur = [WinEnum]::FindByTitle($WindowTitle) } catch {} }
            if($hCur -ne [IntPtr]::Zero){
              if([TopMostWin]::IsIconic($hCur)){ [TopMostWin]::ShowWindow($hCur, 9) | Out-Null }
              [TopMostWin]::SetForegroundWindow($hCur) | Out-Null
            }
          }
        }
      }catch{}
    }
  } catch {} finally {
    try { if($global:MiniMutex){ $global:MiniMutex.ReleaseMutex() | Out-Null } } catch {}
  }
  exit 0
} else {
  # One-shot focus/open and exit immediately (no keep-alive, no relaunch)
  try { if($global:MiniMutex){ $global:MiniMutex.ReleaseMutex() | Out-Null } } catch {}
  exit 0
}
