# pre-test-reset.ps1 -- Run before every k6 stress test
# Cleans accumulated test data without touching code.
# Usage: .\scripts\pre-test-reset.ps1

$root = "C:\xampp\htdocs\my-ecommerce-app"
Set-Location $root

Write-Host ""
Write-Host "[1/6] Stopping queue worker..."
Get-Process php -ErrorAction SilentlyContinue | Where-Object {
    (Get-CimInstance Win32_Process -Filter "ProcessId=$($_.Id)").CommandLine -like "*queue:work*"
} | ForEach-Object {
    Stop-Process -Id $_.Id -Force
    Write-Host "  Killed worker PID $($_.Id)"
}
Start-Sleep -Seconds 1

Write-Host "[2/6] Truncating k6 test orders (MySQL)..."
$sql = "SET FOREIGN_KEY_CHECKS=0; DELETE oi FROM order_items oi JOIN orders o ON oi.order_id = o.id JOIN users u ON o.user_id = u.id WHERE u.email LIKE 'k6-user-%@test.com'; DELETE o FROM orders o JOIN users u ON o.user_id = u.id WHERE u.email LIKE 'k6-user-%@test.com'; SET FOREIGN_KEY_CHECKS=1; SELECT CONCAT('Remaining orders: ', COUNT(*)) as result FROM orders;"
& "C:\xampp\mysql\bin\mysql.exe" -u root my_ecommerce -e $sql 2>&1
Write-Host ""

Write-Host "[3/6] Resetting product stock + clearing carts (Seeder)..."
php artisan db:seed --class=StressTestSeeder 2>&1 | Select-Object -Last 6

Write-Host "[4/6] Flushing Redis cache..."
php artisan cache:clear 2>&1

Write-Host "[5/6] Rebuilding config cache..."
php artisan config:cache 2>&1

Write-Host "[6/6] Restarting Apache + starting fresh worker..."
& "C:\xampp\apache\bin\httpd.exe" -k restart 2>&1 | Out-Null
Start-Sleep -Seconds 2

$logPath = "$root\storage\logs\worker-live.log"
"" | Set-Content $logPath
Start-Process -FilePath "C:\xampp\php\php.exe" `
    -ArgumentList "artisan","queue:work","--queue=orders,reports,default","--tries=3","--verbose","--timeout=60" `
    -WorkingDirectory $root `
    -RedirectStandardOutput $logPath `
    -WindowStyle Hidden

Start-Sleep -Seconds 2
$worker = Get-Process php -ErrorAction SilentlyContinue | Where-Object {
    (Get-CimInstance Win32_Process -Filter "ProcessId=$($_.Id)").CommandLine -like "*queue:work*"
} | Select-Object -First 1

$port8000 = netstat -an 2>&1 | Select-String "0.0.0.0:8000.*LISTEN"
$portStatus = if ($port8000) { "Apache (parallel) OK" } else { "WARNING: not found!" }

Write-Host ""
Write-Host "============================================"
Write-Host "  RESET COMPLETE -- System ready for test"
Write-Host "============================================"
Write-Host "  Worker PID   : $($worker.Id)"
Write-Host "  Port 8000    : $portStatus"
Write-Host ""
Write-Host "Run the test:"
Write-Host "  k6 run --env BASE_URL=http://localhost:8000 scripts/k6_stress_test.js"
Write-Host ""
