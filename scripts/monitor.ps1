# monitor.ps1 — Project-only resource monitor (mysqld + php + httpd)
# Usage: powershell -File scripts\monitor.ps1 before
#        powershell -File scripts\monitor.ps1 after
# Stop:  create storage\app\benchmark\.stop  OR press Ctrl+C

param(
    [string]$Phase = "monitor"
)

$ErrorActionPreference = 'Continue'
$root      = "C:\xampp\htdocs\my-ecommerce-app"
$benchDir  = "$root\storage\app\benchmark"
$CsvPath   = "$benchDir\$Phase-monitor.csv"
$StopFile  = "$benchDir\.stop"

Set-Location $root
New-Item -ItemType Directory -Force -Path $benchDir | Out-Null
if (Test-Path $StopFile) { Remove-Item $StopFile -Force }

# Number of logical CPU cores (to normalise CPU ticks to 0-100%)
$cores = [int](Get-CimInstance Win32_Processor |
              Measure-Object -Property NumberOfLogicalProcessors -Sum).Sum

function Get-CpuTicks([string]$Name) {
    $procs = Get-Process -Name $Name -ErrorAction SilentlyContinue
    if (-not $procs) { return 0 }
    return ($procs | ForEach-Object { $_.TotalProcessorTime.Ticks } |
            Measure-Object -Sum).Sum
}

function Get-RamMB([string]$Name) {
    $procs = Get-Process -Name $Name -ErrorAction SilentlyContinue
    if (-not $procs) { return 0 }
    return [math]::Round(($procs | Measure-Object WorkingSet64 -Sum).Sum / 1MB)
}

function Get-ProcCount([string]$Name) {
    return @(Get-Process -Name $Name -ErrorAction SilentlyContinue).Count
}

# CSV header
"timestamp,project_cpu_pct,project_ram_mb" |
    Out-File $CsvPath -Encoding utf8

Write-Host ""
Write-Host "Project monitor started  →  $CsvPath"
Write-Host "Tracking: mysqld + php + httpd  (interval: 2s)"
Write-Host "Stop: create $StopFile  OR  Ctrl+C"
Write-Host ""
Write-Host ("{0,-10} {1,12} {2,12}" -f "Time", "CPU%", "RAM")
Write-Host ("-" * 36)

# Initial CPU snapshot
$ticksPerSec = 10000000   # 1 tick = 100ns → 10,000,000 ticks/sec
$prevMysql   = Get-CpuTicks 'mysqld'
$prevPhp     = Get-CpuTicks 'php'
$prevHttpd   = Get-CpuTicks 'httpd'
$prevTime    = [DateTime]::UtcNow

while (-not (Test-Path $StopFile)) {
    Start-Sleep -Seconds 2

    $nowTime    = [DateTime]::UtcNow
    $elapsedSec = ([DateTime]::UtcNow - $prevTime).TotalSeconds
    $prevTime   = $nowTime

    # CPU delta using ACTUAL elapsed time (not assumed 2s)
    $currMysql  = Get-CpuTicks 'mysqld'
    $currPhp    = Get-CpuTicks 'php'
    $currHttpd  = Get-CpuTicks 'httpd'

    $totalTicks  = $elapsedSec * $ticksPerSec * $cores
    $mysqlCpu   = [math]::Round(($currMysql  - $prevMysql)  / $totalTicks * 100, 1)
    $phpCpu     = [math]::Round(($currPhp    - $prevPhp)    / $totalTicks * 100, 1)
    $httpdCpu   = [math]::Round(($currHttpd  - $prevHttpd)  / $totalTicks * 100, 1)
    $projectCpu = [math]::Round($mysqlCpu + $phpCpu + $httpdCpu, 1)

    $prevMysql = $currMysql
    $prevPhp   = $currPhp
    $prevHttpd = $currHttpd

    # RAM
    $projectRam = (Get-RamMB 'mysqld') + (Get-RamMB 'php') + (Get-RamMB 'httpd')

    $ts = Get-Date -Format "HH:mm:ss"

    # CSV
    "$ts,$projectCpu,$projectRam" | Out-File $CsvPath -Append -Encoding utf8

    # Console
    Write-Host ("{0,-10} {1,10}%  {2,8}MB" -f $ts, $projectCpu, $projectRam)
}

Write-Host ""
Write-Host "Monitor stopped. Results saved to: $CsvPath"
