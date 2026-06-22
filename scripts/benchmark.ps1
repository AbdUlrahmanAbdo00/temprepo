# benchmark.ps1 - Full before/after benchmark
# Usage: .\scripts\benchmark.ps1

$root = "C:\xampp\htdocs\my-ecommerce-app"
Set-Location $root

$benchDir  = "$root\storage\app\benchmark"
$stopFile  = "$benchDir\.stop"
New-Item -ItemType Directory -Force -Path $benchDir | Out-Null

# ─── helpers ──────────────────────────────────────────────────────────────────

function Log($msg) { Write-Host ">>> $msg" -ForegroundColor Cyan }

function Stop-Worker {
    Get-Process php -ErrorAction SilentlyContinue | Where-Object {
        (Get-CimInstance Win32_Process -Filter "ProcessId=$($_.Id)").CommandLine -like "*queue:work*"
    } | ForEach-Object { Stop-Process -Id $_.Id -Force -ErrorAction SilentlyContinue }
    Start-Sleep 1
}

function Start-Worker {
    $log = "$root\storage\logs\worker-live.log"
    "" | Set-Content $log
    Start-Process "C:\xampp\php\php.exe" `
        -ArgumentList "artisan","queue:work","--queue=orders,reports,default","--tries=3","--verbose","--timeout=60" `
        -WorkingDirectory $root -RedirectStandardOutput $log -WindowStyle Hidden
    Start-Sleep 2
}

function Restart-MySQL {
    Log "Restarting MySQL..."
    # Force-kill any running mysqld process
    Get-Process mysqld -ErrorAction SilentlyContinue | Stop-Process -Force -ErrorAction SilentlyContinue
    Start-Sleep 3
    # Remove stale PID file if left behind
    $pidF = "C:\xampp\mysql\data\mysql.pid"
    if (Test-Path $pidF) { Remove-Item $pidF -Force }
    # Start fresh (write my.ini without BOM first, just in case)
    $ini = "C:\xampp\mysql\bin\my.ini"
    $utf8NoBom = New-Object System.Text.UTF8Encoding $false
    [System.IO.File]::WriteAllText($ini, (Get-Content $ini -Raw), $utf8NoBom)
    Start-Process "C:\xampp\mysql\bin\mysqld.exe" `
        -ArgumentList "--defaults-file=C:\xampp\mysql\bin\my.ini" -WindowStyle Hidden
    # Wait up to 15s for MySQL to accept connections
    $ready = $false
    for ($i = 0; $i -lt 15; $i++) {
        Start-Sleep 1
        $r = & "C:\xampp\mysql\bin\mysqladmin.exe" -u root status 2>&1 | Select-Object -First 1
        if ($r -notlike "*failed*" -and $r -notlike "*error*") { $ready = $true; break }
    }
    if ($ready) { Write-Host "  MySQL ready." } else { Write-Host "  WARNING: MySQL may not be ready!" }
}

function Ensure-Apache {
    $port = netstat -an 2>&1 | Select-String "0.0.0.0:8000.*LISTEN"
    if (-not $port) {
        Log "Apache not running — starting..."
        Get-Process httpd -EA SilentlyContinue | Stop-Process -Force -EA SilentlyContinue
        Start-Sleep 1
        Start-Process "C:\xampp\apache\bin\httpd.exe" -WindowStyle Hidden
        Start-Sleep 3
    }
    $port2 = netstat -an 2>&1 | Select-String "0.0.0.0:8000.*LISTEN"
    Write-Host "  Port 8000: $(if($port2){'Apache OK'}else{'WARNING: still not running!'})"
}

function Clean-And-Seed {
    Log "Cleaning DB and re-seeding..."
    $sql = "SET FOREIGN_KEY_CHECKS=0; DELETE oi FROM order_items oi JOIN orders o ON oi.order_id=o.id JOIN users u ON o.user_id=u.id WHERE u.email LIKE 'k6-user-%@test.com'; DELETE o FROM orders o JOIN users u ON o.user_id=u.id WHERE u.email LIKE 'k6-user-%@test.com'; SET FOREIGN_KEY_CHECKS=1;"
    & "C:\xampp\mysql\bin\mysql.exe" -u root my_ecommerce -e $sql 2>$null
    php artisan db:seed --class=StressTestSeeder 2>&1 | Select-Object -Last 3
    php artisan cache:clear  2>$null
    php artisan config:cache 2>$null
}

function Start-Monitor($csvPath) {
    if (Test-Path $stopFile) { Remove-Item $stopFile -Force }
    "timestamp,cpu_pct,sys_ram_mb,mysql_ram_mb,php_ram_mb,php_count" |
        Out-File $csvPath -Encoding utf8
    $job = Start-Job -ScriptBlock {
        param($csv, $stop)
        while (-not (Test-Path $stop)) {
            $ts  = Get-Date -Format "HH:mm:ss"
            $cpu = [math]::Round((Get-CimInstance Win32_Processor |
                       Measure-Object LoadPercentage -Average).Average, 1)
            $os  = Get-CimInstance Win32_OperatingSystem
            $sys = [math]::Round(($os.TotalVisibleMemorySize - $os.FreePhysicalMemory) / 1024)
            $mys = [math]::Round((Get-Process mysqld -EA SilentlyContinue |
                       Measure-Object WorkingSet64 -Sum).Sum / 1MB)
            $php = Get-Process php -EA SilentlyContinue
            $pr  = [math]::Round(($php | Measure-Object WorkingSet64 -Sum).Sum / 1MB)
            $pc  = @($php).Count
            "$ts,$cpu,$sys,$mys,$pr,$pc" | Out-File $csv -Append -Encoding utf8
            Start-Sleep 2
        }
    } -ArgumentList $csvPath, $stopFile
    return $job
}

function Stop-Monitor($job) {
    "" | Out-File $stopFile -Encoding utf8
    Start-Sleep 3
    Stop-Job  $job -EA SilentlyContinue
    Remove-Job $job -EA SilentlyContinue
    if (Test-Path $stopFile) { Remove-Item $stopFile -Force }
}

function Get-Stats($csv) {
    if (-not (Test-Path $csv)) { return @{} }
    $rows = Import-Csv $csv
    if ($rows.Count -eq 0) { return @{} }
    return @{
        cpu_avg  = [math]::Round(($rows | Measure-Object cpu_pct       -Average).Average, 1)
        cpu_max  = [math]::Round(($rows | Measure-Object cpu_pct       -Maximum).Maximum, 1)
        ram_avg  = [math]::Round(($rows | Measure-Object sys_ram_mb    -Average).Average)
        ram_max  = [math]::Round(($rows | Measure-Object sys_ram_mb    -Maximum).Maximum)
        mysql_avg= [math]::Round(($rows | Measure-Object mysql_ram_mb  -Average).Average)
        php_avg  = [math]::Round(($rows | Measure-Object php_ram_mb    -Average).Average)
        php_max  = [math]::Round(($rows | Measure-Object php_ram_mb    -Maximum).Maximum)
        samples  = $rows.Count
    }
}

# ─── revert / apply helpers ───────────────────────────────────────────────────

function Set-JobConnection($file, [bool]$addBack) {
    $txt = Get-Content $file -Raw
    if ($addBack) {
        # add $this->onConnection('database'); before onQueue line (only if not already there)
        if ($txt -notlike '*onConnection*') {
            $txt = $txt.Replace(
                "        `$this->onQueue('orders');",
                "        `$this->onConnection('database');`r`n        `$this->onQueue('orders');"
            )
        }
    } else {
        # remove the onConnection line
        $txt = $txt.Replace(
            "        `$this->onConnection('database');`r`n        `$this->onQueue('orders');",
            "        `$this->onQueue('orders');"
        ).Replace(
            "        `$this->onConnection('database');`n        `$this->onQueue('orders');",
            "        `$this->onQueue('orders');"
        )
    }
    $utf8NoBom = New-Object System.Text.UTF8Encoding $false
    [System.IO.File]::WriteAllText($file, $txt, $utf8NoBom)
}

function Set-MySQLConfig([bool]$big) {
    $ini = "C:\xampp\mysql\bin\my.ini"
    $txt = Get-Content $ini -Raw
    if ($big) {
        $txt = $txt -replace 'innodb_buffer_pool_size=\d+M', 'innodb_buffer_pool_size=256M'
        $txt = $txt -replace 'innodb_log_file_size=\d+M',    'innodb_log_file_size=64M'
        $txt = $txt -replace 'innodb_log_buffer_size=\d+M',  'innodb_log_buffer_size=32M'
        $txt = $txt -replace 'innodb_flush_log_at_trx_commit=\d', 'innodb_flush_log_at_trx_commit=1'
    } else {
        $txt = $txt -replace 'innodb_buffer_pool_size=\d+M', 'innodb_buffer_pool_size=16M'
        $txt = $txt -replace 'innodb_log_file_size=\d+M',    'innodb_log_file_size=5M'
        $txt = $txt -replace 'innodb_log_buffer_size=\d+M',  'innodb_log_buffer_size=8M'
        $txt = $txt -replace 'innodb_flush_log_at_trx_commit=\d', 'innodb_flush_log_at_trx_commit=1'
    }
    $utf8NoBom = New-Object System.Text.UTF8Encoding $false
    [System.IO.File]::WriteAllText($ini, $txt, $utf8NoBom)
}

# ══════════════════════════════════════════════════════════════════════════════
#  PHASE 1 — BEFORE
# ══════════════════════════════════════════════════════════════════════════════
Write-Host ""
Write-Host "========================================" -ForegroundColor Yellow
Write-Host "  PHASE 1 - BEFORE (reverting fixes)  " -ForegroundColor Yellow
Write-Host "========================================" -ForegroundColor Yellow

Log "Reverting ProcessOrderJob + GenerateOrderSummaryJob"
Set-JobConnection "$root\app\Jobs\ProcessOrderJob.php"          $true
Set-JobConnection "$root\app\Jobs\GenerateOrderSummaryJob.php"  $true

Log "Reverting MySQL config to 16MB buffer, flush=1"
Set-MySQLConfig $false
Restart-MySQL

Stop-Worker
Clean-And-Seed
Start-Worker

Ensure-Apache
Log "Starting monitor + k6 (BEFORE)..."
$monBefore = Start-Monitor "$benchDir\before-monitor.csv"

k6 run --env BASE_URL=http://localhost:8000 `
       --env SUMMARY_PATH=storage/app/benchmark/before-k6.json `
       scripts/k6_stress_test.js 2>&1

Stop-Monitor $monBefore
Log "BEFORE test complete."

# ══════════════════════════════════════════════════════════════════════════════
#  PHASE 2 — APPLY FIXES
# ══════════════════════════════════════════════════════════════════════════════
Write-Host ""
Write-Host "========================================" -ForegroundColor Green
Write-Host "  PHASE 2 - Applying all fixes         " -ForegroundColor Green
Write-Host "========================================" -ForegroundColor Green

Log "Fixing ProcessOrderJob + GenerateOrderSummaryJob"
Set-JobConnection "$root\app\Jobs\ProcessOrderJob.php"          $false
Set-JobConnection "$root\app\Jobs\GenerateOrderSummaryJob.php"  $false

Log "Applying MySQL config to 256MB buffer, flush=1 (safe)"
Set-MySQLConfig $true
Restart-MySQL

Stop-Worker
Clean-And-Seed
Start-Worker

Ensure-Apache
Log "Starting monitor + k6 (AFTER)..."
$monAfter = Start-Monitor "$benchDir\after-monitor.csv"

k6 run --env BASE_URL=http://localhost:8000 `
       --env SUMMARY_PATH=storage/app/benchmark/after-k6.json `
       scripts/k6_stress_test.js 2>&1

Stop-Monitor $monAfter
Log "AFTER test complete."

# ══════════════════════════════════════════════════════════════════════════════
#  COMPARISON
# ══════════════════════════════════════════════════════════════════════════════
Write-Host ""
Write-Host "========================================" -ForegroundColor Magenta
Write-Host "  BENCHMARK RESULTS                    " -ForegroundColor Magenta
Write-Host "========================================" -ForegroundColor Magenta

$bs = Get-Stats "$benchDir\before-monitor.csv"
$as = Get-Stats "$benchDir\after-monitor.csv"

$f = "{0,-26} {1,14} {2,14}"
Write-Host ($f -f "System Metric", "BEFORE", "AFTER")
Write-Host ("-" * 56)
if ($bs.Count -and $as.Count) {
    Write-Host ($f -f "CPU avg %",          "$($bs.cpu_avg)%",   "$($as.cpu_avg)%")
    Write-Host ($f -f "CPU max %",          "$($bs.cpu_max)%",   "$($as.cpu_max)%")
    Write-Host ($f -f "System RAM avg MB",  "$($bs.ram_avg)MB",  "$($as.ram_avg)MB")
    Write-Host ($f -f "System RAM max MB",  "$($bs.ram_max)MB",  "$($as.ram_max)MB")
    Write-Host ($f -f "MySQL RAM avg MB",   "$($bs.mysql_avg)MB","$($as.mysql_avg)MB")
    Write-Host ($f -f "PHP RAM avg MB",     "$($bs.php_avg)MB",  "$($as.php_avg)MB")
    Write-Host ($f -f "PHP RAM max MB",     "$($bs.php_max)MB",  "$($as.php_max)MB")
    Write-Host ($f -f "Monitor samples",    $bs.samples,          $as.samples)
}

if ((Test-Path "$benchDir\before-k6.json") -and (Test-Path "$benchDir\after-k6.json")) {
    $b = Get-Content "$benchDir\before-k6.json" | ConvertFrom-Json
    $a = Get-Content "$benchDir\after-k6.json"  | ConvertFrom-Json

    Write-Host ""
    Write-Host ($f -f "k6 Metric", "BEFORE", "AFTER")
    Write-Host ("-" * 56)
    Write-Host ($f -f "Iterations",        $b.iterations,                         $a.iterations)
    Write-Host ($f -f "Success rate",      "$($b.success_rate_pct)%",             "$($a.success_rate_pct)%")
    Write-Host ($f -f "422 out-of-stock",  $b.responses.out_of_stock_422,         $a.responses.out_of_stock_422)
    Write-Host ($f -f "5xx errors",        $b.responses.server_errors,            $a.responses.server_errors)
    Write-Host ($f -f "Checkout p95 ms",   $b.latency_ms.checkout.p95,            $a.latency_ms.checkout.p95)
    Write-Host ($f -f "In-PHP avg ms",     $b.latency_ms.checkout_processing.avg, $a.latency_ms.checkout_processing.avg)
    Write-Host ($f -f "SQL total avg ms",  $b.db_queries.total_ms.avg,            $a.db_queries.total_ms.avg)
    Write-Host ($f -f "dispatch_ms avg",   $b.stages.dispatch_ms.avg,             $a.stages.dispatch_ms.avg)
    Write-Host ($f -f "dispatch2_ms avg",  $b.stages.dispatch2_ms.avg,            $a.stages.dispatch2_ms.avg)
    Write-Host ($f -f "NO CRASH",          $b.no_crash,                           $a.no_crash)
}

Write-Host ""
Write-Host "Output files in: $benchDir"
Get-ChildItem $benchDir | ForEach-Object { Write-Host "  $($_.Name)  ($([math]::Round($_.Length/1KB,1)) KB)" }
