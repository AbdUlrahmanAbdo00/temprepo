<?php

require __DIR__ . '/../vendor/autoload.php';

function formatBytes(float $bytes): string
{
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $index = 0;

    while ($bytes >= 1024 && $index < count($units) - 1) {
        $bytes /= 1024;
        $index++;
    }

    return number_format($bytes, 2) . ' ' . $units[$index];
}

function getCpuInfo(): array
{
    $os = PHP_OS_FAMILY;
    $loadAverage = function_exists('sys_getloadavg') ? sys_getloadavg() : false;

    if ($os === 'Windows') {
        $cpuCoreOutput = trim((string) shell_exec('powershell -NoProfile -Command "(Get-CimInstance Win32_ComputerSystem).NumberOfLogicalProcessors" 2>NUL'));
        $cpuCores = (int) $cpuCoreOutput;
    } else {
        $cpuCoreOutput = trim((string) shell_exec('nproc 2>/dev/null'));
        if ($cpuCoreOutput === '') {
            $cpuCoreOutput = trim((string) shell_exec('getconf _NPROCESSORS_ONLN 2>/dev/null'));
        }
        $cpuCores = (int) $cpuCoreOutput;
    }

    if ($cpuCores <= 0) {
        $cpuCores = 1;
    }

    if ($os === 'Windows') {
        $windowsLoad = trim((string) shell_exec('wmic cpu get loadpercentage /value 2>NUL'));
        if (preg_match('/LoadPercentage=(\d+)/i', $windowsLoad, $matches)) {
            $currentLoad = (int) $matches[1];

            return [
                'type' => 'windows',
                'cores' => $cpuCores,
                'load_1m' => $currentLoad,
                'load_percent' => $currentLoad,
                'label' => 'CPU Load Percentage',
            ];
        }
    }

    $load1m = is_array($loadAverage) && isset($loadAverage[0]) ? (float) $loadAverage[0] : 0.0;
    $loadPercent = ($load1m / $cpuCores) * 100;

    return [
        'type' => 'unix',
        'cores' => $cpuCores,
        'load_1m' => $load1m,
        'load_percent' => $loadPercent,
        'label' => '1 Minute Load Average',
    ];
}

function getMemoryInfo(): array
{
    $os = PHP_OS_FAMILY;

    if ($os !== 'Windows' && is_readable('/proc/meminfo')) {
        $meminfo = file_get_contents('/proc/meminfo');

        preg_match('/MemTotal:\s+(\d+)\skB/i', $meminfo, $totalMatch);
        preg_match('/MemAvailable:\s+(\d+)\skB/i', $meminfo, $availableMatch);

        $total = isset($totalMatch[1]) ? ((float) $totalMatch[1] * 1024) : 0.0;
        $available = isset($availableMatch[1]) ? ((float) $availableMatch[1] * 1024) : 0.0;
        $used = max($total - $available, 0.0);
        $percent = $total > 0 ? ($used / $total) * 100 : 0.0;

        return [
            'total' => $total,
            'used' => $used,
            'available' => $available,
            'percent' => $percent,
            'source' => 'proc_meminfo',
        ];
    }

    if ($os === 'Windows') {
        $output = trim((string) shell_exec('powershell -NoProfile -Command "Get-CimInstance Win32_OperatingSystem | Select-Object TotalVisibleMemorySize,FreePhysicalMemory | ConvertTo-Json -Compress" 2>NUL'));

        if ($output !== '') {
            $data = json_decode($output, true);
            if (is_array($data) && isset($data['TotalVisibleMemorySize'], $data['FreePhysicalMemory'])) {
                $total = (float) $data['TotalVisibleMemorySize'] * 1024;
                $available = (float) $data['FreePhysicalMemory'] * 1024;
                $used = max($total - $available, 0.0);
                $percent = $total > 0 ? ($used / $total) * 100 : 0.0;

                return [
                    'total' => $total,
                    'used' => $used,
                    'available' => $available,
                    'percent' => $percent,
                    'source' => 'powershell',
                ];
            }
        }
    }

    return [
        'total' => 0.0,
        'used' => 0.0,
        'available' => 0.0,
        'percent' => 0.0,
        'source' => 'unavailable',
    ];
}

function printSeparator(): void
{
    echo str_repeat('=', 60) . PHP_EOL;
}

printSeparator();
echo "Server Pressure Monitor" . PHP_EOL;
echo "Time: " . date('Y-m-d H:i:s') . PHP_EOL;
echo "Host: " . gethostname() . PHP_EOL;
echo "OS: " . PHP_OS_FAMILY . PHP_EOL;
printSeparator();

$cpu = getCpuInfo();
$memory = getMemoryInfo();

if ($cpu['type'] === 'windows') {
    echo "CPU Load: {$cpu['load_percent']}%" . PHP_EOL;
} else {
    echo "CPU Load Average (1m): {$cpu['load_1m']}" . PHP_EOL;
    echo "CPU Load Relative to Cores ({$cpu['cores']} cores): " . number_format($cpu['load_percent'], 2) . "%" . PHP_EOL;
}

echo PHP_EOL;
echo "RAM Used: " . formatBytes($memory['used']) . PHP_EOL;
echo "RAM Available: " . formatBytes($memory['available']) . PHP_EOL;
echo "RAM Total: " . formatBytes($memory['total']) . PHP_EOL;
echo "RAM Usage: " . number_format($memory['percent'], 2) . "%" . PHP_EOL;
echo "Memory Source: " . $memory['source'] . PHP_EOL;

printSeparator();

echo PHP_EOL . "Quick Interpretation:" . PHP_EOL;

if ($cpu['type'] === 'windows') {
    echo $cpu['load_percent'] >= 80
        ? "- CPU pressure is high. Consider reducing workers or scaling the server." . PHP_EOL
        : "- CPU pressure is acceptable." . PHP_EOL;
} else {
    echo $cpu['load_percent'] >= 80
        ? "- CPU pressure is high. Consider reducing workers or scaling the server." . PHP_EOL
        : "- CPU pressure is acceptable." . PHP_EOL;
}

echo $memory['percent'] >= 80
    ? "- RAM pressure is high. Consider lowering queue workers or freeing memory." . PHP_EOL
    : "- RAM pressure is acceptable." . PHP_EOL;

printSeparator();
