<?php

namespace App\Classes;

class ResourceMonitor
{
    private bool $isWindows;
    private array $cpuBaseline = [];
    private array $sampleHistory = [];

    public function __construct()
    {
        $this->isWindows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
    }

    public function getSnapshot(): array
    {
        $php = $this->getPhpResources();
        $mysql = $this->getMysqlResources();

        $totalRam = $php['ram_mb'] + (is_numeric($mysql['ram_mb']) ? $mysql['ram_mb'] : 0);
        $totalCpu = 0.0;
        
        if (is_numeric($php['cpu'])) {
            $totalCpu += (float) $php['cpu'];
        }
        if (is_numeric($mysql['cpu'])) {
            $totalCpu += (float) $mysql['cpu'];
        }

        return [
            'timestamp' => date('Y-m-d H:i:s'),
            'php' => $php,
            'mysql' => $mysql,
            'total' => [
                'cpu' => round($totalCpu, 2),
                'ram_mb' => round($totalRam, 2),
            ],
        ];
    }

    private function getPhpResources(): array
    {
        if ($this->isWindows) {
            return $this->getWindowsProcessGroupResources(['httpd.exe', 'php-cgi.exe', 'php.exe'], 'PHP');
        }
        return $this->getLinuxProcessResources('php', 'PHP');
    }

    private function getMysqlResources(): array
    {
        if ($this->isWindows) {
            return $this->getWindowsProcessGroupResources(['mysqld.exe'], 'MySQL');
        }
        return $this->getLinuxProcessResources('mysqld', 'MySQL');
    }

    private function getLinuxProcessResources(string $processName, string $label): array
    {
        $output = shell_exec("ps aux | grep {$processName} | grep -v grep 2>/dev/null");

        if (!$output) {
            return ['cpu' => 0, 'ram_mb' => 0, 'status' => 'Not running'];
        }

        $lines = array_filter(explode("\n", trim($output)));
        $totalCpu = 0;
        $totalRam = 0;
        $processCount = 0;

        foreach ($lines as $line) {
            $parts = preg_split('/\s+/', $line);
            if (count($parts) >= 6) {
                $totalCpu += (float) $parts[2];
                $totalRam += (float) $parts[5];
                $processCount++;
            }
        }

        return [
            'cpu' => round($totalCpu, 2),
            'ram_mb' => round($totalRam / 1024, 2),
            'processes' => $processCount,
            'status' => 'Running',
        ];
    }

    private function getWindowsProcessGroupResources(array $processNames, string $label): array
    {
        $processes = [];

        foreach ($processNames as $processName) {
            $normalizedName = preg_replace('/\.exe$/i', '', $processName) ?: $processName;
            $command = 'powershell -NoProfile -Command "Get-Process -Name ' . $normalizedName . ' -ErrorAction SilentlyContinue | Select-Object Id,ProcessName,CPU,WorkingSet64 | ConvertTo-Json -Compress" 2>NUL';
            $output = trim((string) shell_exec($command));

            if ($output === '') {
                continue;
            }

            $decoded = json_decode($output, true);
            if ($decoded === null) {
                continue;
            }

            if (isset($decoded[0])) {
                foreach ($decoded as $item) {
                    if (is_array($item)) {
                        $processes[] = $item;
                    }
                }
            } elseif (is_array($decoded)) {
                $processes[] = $decoded;
            }
        }

        if (empty($processes)) {
            return ['cpu' => 0, 'ram_mb' => 0, 'processes' => 0, 'status' => 'Not running'];
        }

        $ramBytes = 0.0;
        $currentCpu = [];

        foreach ($processes as $process) {
            if (!is_array($process)) {
                continue;
            }
            $pid = (string) ($process['Id'] ?? uniqid($label, true));
            $ramBytes += (float) ($process['WorkingSet64'] ?? 0);
            $currentCpu[$pid] = (float) ($process['CPU'] ?? 0);
        }

        $cpuPercent = $this->calculateCpuPercent($label, $currentCpu);

        return [
            'cpu' => round($cpuPercent, 2),
            'ram_mb' => round($ramBytes / (1024 * 1024), 2),
            'processes' => count($processes),
            'status' => count($processes) > 0 ? 'Running' : 'Not running',
        ];
    }

    private function calculateCpuPercent(string $label, array $currentCpu): float
    {
        $key = strtolower($label);
        $now = microtime(true);
        $cores = $this->getLogicalCpuCores();

        if (!isset($this->cpuBaseline[$key])) {
            $this->cpuBaseline[$key] = ['timestamp' => $now, 'cpu' => $currentCpu];
            return 0.0;
        }

        $previous = $this->cpuBaseline[$key];
        $elapsed = max($now - $previous['timestamp'], 0.001);
        $cpuDelta = 0.0;

        foreach ($currentCpu as $pid => $cpuSeconds) {
            $previousCpu = (float) ($previous['cpu'][$pid] ?? 0.0);
            if ($cpuSeconds > $previousCpu) {
                $cpuDelta += $cpuSeconds - $previousCpu;
            }
        }

        $this->cpuBaseline[$key] = ['timestamp' => $now, 'cpu' => $currentCpu];

        return ($cpuDelta / $elapsed / max($cores, 1)) * 100;
    }

    private function getLogicalCpuCores(): int
    {
        if (!$this->isWindows) {
            $cores = (int) trim((string) shell_exec('nproc 2>/dev/null'));
            return $cores > 0 ? $cores : 1;
        }

        $cpuCoreOutput = trim((string) shell_exec('powershell -NoProfile -Command "(Get-CimInstance Win32_ComputerSystem).NumberOfLogicalProcessors" 2>NUL'));
        $cores = (int) $cpuCoreOutput;
        return $cores > 0 ? $cores : 1;
    }

    public function recordSample(string $phase = 'monitoring'): void
    {
        $this->sampleHistory[] = [
            'phase' => $phase,
            ...$this->getSnapshot()
        ];
    }

    public function displayCurrent(string $title = 'Resource Monitor'): void
    {
        $snapshot = $this->getSnapshot();

        echo "\n╔════════════════════════════════════════════════════════════╗\n";
        echo "║ " . str_pad($title, 58) . "║\n";
        echo "╠════════════════════════════════════════════════════════════╣\n";
        echo "║ 🔴 PHP (Laravel)                                           ║\n";
        echo "║    Status: " . str_pad($snapshot['php']['status'], 48) . "║\n";
        $phpCpu = is_numeric($snapshot['php']['cpu']) ? $snapshot['php']['cpu'] . '%' : $snapshot['php']['cpu'];
        echo "║    CPU: " . str_pad($phpCpu, 50) . "║\n";
        echo "║    RAM: " . str_pad($snapshot['php']['ram_mb'] . ' MB', 48) . "║\n";
        if (isset($snapshot['php']['processes'])) {
            echo "║    Processes: " . str_pad($snapshot['php']['processes'], 45) . "║\n";
        }
        echo "║                                                            ║\n";
        echo "║ 🟢 MySQL (Database)                                       ║\n";
        echo "║    Status: " . str_pad($snapshot['mysql']['status'], 48) . "║\n";
        $mysqlCpu = is_numeric($snapshot['mysql']['cpu']) ? $snapshot['mysql']['cpu'] . '%' : $snapshot['mysql']['cpu'];
        echo "║    CPU: " . str_pad($mysqlCpu, 50) . "║\n";
        echo "║    RAM: " . str_pad($snapshot['mysql']['ram_mb'] . ' MB', 48) . "║\n";
        echo "║                                                            ║\n";
        echo "╠════════════════════════════════════════════════════════════╣\n";
        echo "║ 📊 TOTAL RESOURCES                                         ║\n";
        echo "║    CPU Total: " . str_pad($snapshot['total']['cpu'] . '%', 45) . "║\n";
        echo "║    RAM Total: " . str_pad($snapshot['total']['ram_mb'] . ' MB', 45) . "║\n";
        echo "║                                                            ║\n";
        $cpuStatus = $snapshot['total']['cpu'] > 80 ? '🔥 HIGH' : ($snapshot['total']['cpu'] > 50 ? '⚠️  MODERATE' : '✅ NORMAL');
        echo "║    Status: " . str_pad($cpuStatus, 48) . "║\n";
        echo "║    Time: " . str_pad($snapshot['timestamp'], 50) . "║\n";
        echo "╚════════════════════════════════════════════════════════════╝\n\n";
    }

    public function getSampleHistory(): array
    {
        return $this->sampleHistory;
    }
}
