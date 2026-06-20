<?php

/**
 * Requirement 9 — Resource Sampler (PowerShell-based, Windows 11 reliable)
 *
 * Samples PROJECT-ONLY CPU% and RAM of the stack (php + httpd + mysqld + redis)
 * at a fixed interval, writing a time-series to storage/app/req9/resources.json
 * for the dashboard's CPU-over-time and RAM-over-time charts.
 *
 * BOTH metrics are project-scoped (consistent):
 *   • RAM = sum of WorkingSet64 of the project processes.
 *   • CPU = sum of the project processes' CPU-seconds delta over the interval,
 *           divided by logical cores → % of total CPU capacity used by the project.
 *           (Locale-independent: uses Get-Process .CPU, not localized perf counters.)
 *
 * Uses PowerShell (not the deprecated wmic, which is absent on newer Windows 11).
 *
 * Start it in its OWN terminal, just before the k6 stress test, for the same
 * duration as the test (default 90s = 20s ramp + 60s hold + 10s ramp-down):
 *
 *   php scripts/req9_resource_sampler.php --duration=90 --interval=2
 */

$opts     = getopt('', ['duration::', 'interval::']);
$duration = max(5, (int) ($opts['duration'] ?? 90));
$interval = max(1, (int) ($opts['interval'] ?? 2));
$outPath  = __DIR__ . '/../storage/app/req9/resources.json';

@mkdir(dirname($outPath), 0777, true);

// PowerShell loop: one compact JSON object per sample, on its own line.
$ps = <<<PS
\$ErrorActionPreference = 'SilentlyContinue'
\$ProgressPreference = 'SilentlyContinue'
\$names = 'php','httpd','mysqld','redis-server'
\$cores = (Get-CimInstance Win32_ComputerSystem).NumberOfLogicalProcessors
if (-not \$cores -or \$cores -lt 1) { \$cores = 1 }
\$start = Get-Date
\$prevCpu = (Get-Process \$names | Measure-Object CPU -Sum).Sum
\$prevTime = Get-Date
while ((New-TimeSpan -Start \$start -End (Get-Date)).TotalSeconds -lt $duration) {
    Start-Sleep -Seconds $interval
    \$now = Get-Date
    \$procs = Get-Process \$names
    \$curCpu = (\$procs | Measure-Object CPU -Sum).Sum
    \$ram = (\$procs | Measure-Object WorkingSet64 -Sum).Sum
    \$secs = (\$now - \$prevTime).TotalSeconds
    \$cpuPct = if (\$secs -gt 0) { [math]::Round(((\$curCpu - \$prevCpu) / \$secs) / \$cores * 100, 2) } else { 0 }
    \$prevCpu = \$curCpu
    \$prevTime = \$now
    \$elapsed = [math]::Round((New-TimeSpan -Start \$start -End \$now).TotalSeconds, 1)
    \$obj = [pscustomobject]@{
        elapsed_s = \$elapsed
        time      = (Get-Date -Format 'HH:mm:ss')
        cpu_pct   = [double]\$cpuPct
        ram_mb    = [math]::Round(\$ram / 1MB, 2)
    }
    Write-Output (\$obj | ConvertTo-Json -Compress)
}
PS;

// -EncodedCommand expects base64 of UTF-16LE — avoids all shell quoting issues.
$encoded = base64_encode(mb_convert_encoding($ps, 'UTF-16LE', 'UTF-8'));

echo "Sampling resources for {$duration}s every {$interval}s (PowerShell) ...\n";

$raw = (string) shell_exec("powershell -NoProfile -NonInteractive -EncodedCommand {$encoded}");

$samples = [];
foreach (preg_split('/\r?\n/', trim($raw)) as $line) {
    $line = trim($line);
    if ($line === '') {
        continue;
    }
    $obj = json_decode($line, true);
    if (is_array($obj) && isset($obj['cpu_pct'])) {
        $samples[] = [
            'elapsed_s' => (float) $obj['elapsed_s'],
            'time'      => (string) $obj['time'],
            'cpu_pct'   => (float) $obj['cpu_pct'],
            'ram_mb'    => (float) $obj['ram_mb'],
        ];
        echo sprintf("  t=%5ss  CPU=%5s%%  RAM=%9s MB\n", $obj['elapsed_s'], $obj['cpu_pct'], $obj['ram_mb']);
    }
}

if ($samples === []) {
    fwrite(STDERR, "WARNING: no samples captured — is PowerShell available? Falling back to one empty sample.\n");
    $samples[] = ['elapsed_s' => 0, 'time' => date('H:i:s'), 'cpu_pct' => 0, 'ram_mb' => 0];
}

$cpu = array_column($samples, 'cpu_pct');
$ram = array_column($samples, 'ram_mb');

$report = [
    'captured_at' => date('Y-m-d H:i:s'),
    'duration_s'  => $duration,
    'interval_s'  => $interval,
    'samples'     => $samples,
    'summary'     => [
        'count'       => count($samples),
        'avg_cpu_pct' => $cpu ? round(array_sum($cpu) / count($cpu), 2) : 0,
        'max_cpu_pct' => $cpu ? max($cpu) : 0,
        'avg_ram_mb'  => $ram ? round(array_sum($ram) / count($ram), 2) : 0,
        'max_ram_mb'  => $ram ? max($ram) : 0,
    ],
];

file_put_contents($outPath, json_encode($report, JSON_PRETTY_PRINT));
echo "\nSaved " . count($samples) . " samples → {$outPath}\n";
echo "Peak CPU {$report['summary']['max_cpu_pct']}%  |  Peak RAM {$report['summary']['max_ram_mb']} MB\n";
