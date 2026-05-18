<?php

/**
 * Project Resource Monitor
 * 
 * Monitors CPU and RAM usage for Laravel project components:
 * - PHP (Laravel processes)
 * - MySQL (Database)
 * - Redis (Cache & Queue)
 * 
 * Usage: php scripts/project_resource_monitor.php
 * Usage: php scripts/project_resource_monitor.php --json
 * Usage: php scripts/project_resource_monitor.php --duration=30 --interval=5
 */

class ProjectResourceMonitor
{
    private $isWindows;

    public function __construct()
    {
        $this->isWindows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
    }

    public function getSnapshot(): array
    {
        $php = $this->getPhpResources();
        $mysql = $this->getMysqlResources();
        $redis = $this->getRedisResources();

        $totalRam = $php['ram_mb']
            + (is_numeric($mysql['ram_mb']) ? $mysql['ram_mb'] : 0)
            + (is_numeric($redis['ram_mb']) ? $redis['ram_mb'] : 0);

        $totalCpu = 0.0;
        if (is_numeric($php['cpu'])) {
            $totalCpu += (float) $php['cpu'];
        }
        if (is_numeric($mysql['cpu'])) {
            $totalCpu += (float) $mysql['cpu'];
        }
        if (is_numeric($redis['cpu'])) {
            $totalCpu += (float) $redis['cpu'];
        }

        return [
            'timestamp' => date('Y-m-d H:i:s'),
            'php' => $php,
            'mysql' => $mysql,
            'redis' => $redis,
            'total' => [
                'cpu' => round($totalCpu, 2),
                'ram_mb' => round($totalRam, 2),
            ],
        ];
    }

    /**
     * Get PHP process resources (Laravel) - Windows optimized
     */
    public function getPhpResources()
    {
        if ($this->isWindows) {
            return $this->getPhpResourcesWindows();
        } else {
            return $this->getPhpResourcesLinux();
        }
    }

    private function getPhpResourcesWindows()
    {
        // Use wmic for faster process lookup
        $cmd = 'wmic process where name="php.exe" get ProcessId,VirtualSize,Name /format:csv 2>nul';
        $output = trim(shell_exec($cmd));
        
        if (!$output || strpos($output, 'php.exe') === false) {
            return [
                'cpu' => 0,
                'ram_mb' => 0,
                'processes' => 0,
                'status' => 'Not running'
            ];
        }

        $lines = array_filter(explode("\n", $output), function($line) {
            return strpos($line, 'php.exe') !== false;
        });

        $totalRam = 0;
        $processCount = count($lines);

        foreach ($lines as $line) {
            $parts = array_map('trim', explode(',', $line));
            if (isset($parts[2]) && is_numeric($parts[2])) {
                $totalRam += (int)$parts[2] / (1024 * 1024); // Convert bytes to MB
            }
        }

        return [
            'cpu' => 'N/A',
            'ram_mb' => round($totalRam, 2),
            'processes' => $processCount,
            'status' => $processCount > 0 ? 'Running' : 'Not running'
        ];
    }

    private function getPhpResourcesLinux()
    {
        $output = shell_exec('ps aux | grep php | grep -v grep');
        
        if (!$output) {
            return [
                'cpu' => 0,
                'ram_mb' => 0,
                'processes' => 0,
                'status' => 'Not running'
            ];
        }

        $lines = array_filter(explode("\n", trim($output)));
        $totalCpu = 0;
        $totalRam = 0;

        foreach ($lines as $line) {
            $parts = preg_split('/\s+/', $line);
            if (count($parts) >= 6) {
                $totalCpu += (float)$parts[2]; // CPU percentage
                $totalRam += (float)$parts[5]; // RSS (Resident Set Size) in KB
            }
        }

        return [
            'cpu' => round($totalCpu, 2),
            'ram_mb' => round($totalRam / 1024, 2),
            'processes' => count($lines),
            'status' => 'Running'
        ];
    }

    /**
     * Get MySQL process resources
     */
    public function getMysqlResources()
    {
        if ($this->isWindows) {
            return $this->getMysqlResourcesWindows();
        } else {
            return $this->getMysqlResourcesLinux();
        }
    }

    private function getMysqlResourcesWindows()
    {
        $cmd = 'wmic process where name="mysqld.exe" get VirtualSize,Name /format:csv 2>nul';
        $output = trim(shell_exec($cmd));
        
        if (!$output || strpos($output, 'mysqld.exe') === false) {
            return [
                'cpu' => 'N/A',
                'ram_mb' => 0,
                'status' => 'Not running'
            ];
        }

        $lines = array_filter(explode("\n", $output), function($line) {
            return strpos($line, 'mysqld.exe') !== false;
        });

        $totalRam = 0;
        foreach ($lines as $line) {
            $parts = array_map('trim', explode(',', $line));
            if (isset($parts[1]) && is_numeric($parts[1])) {
                $totalRam += (int)$parts[1] / (1024 * 1024); // Convert bytes to MB
            }
        }

        return [
            'cpu' => 'N/A',
            'ram_mb' => round($totalRam, 2),
            'status' => count($lines) > 0 ? 'Running' : 'Not running'
        ];
    }

    private function getMysqlResourcesLinux()
    {
        $output = shell_exec('ps aux | grep mysqld | grep -v grep');
        
        if (!$output) {
            return [
                'cpu' => 0,
                'ram_mb' => 0,
                'status' => 'Not running'
            ];
        }

        $parts = preg_split('/\s+/', $output);
        if (count($parts) >= 6) {
            $cpu = (float)$parts[2];
            $ram = (float)$parts[5] / 1024; // Convert KB to MB

            return [
                'cpu' => round($cpu, 2),
                'ram_mb' => round($ram, 2),
                'status' => 'Running'
            ];
        }

        return [
            'cpu' => 0,
            'ram_mb' => 0,
            'status' => 'Not running'
        ];
    }

    /**
     * Get Redis process resources
     */
    public function getRedisResources()
    {
        if ($this->isWindows) {
            return $this->getRedisResourcesWindows();
        } else {
            return $this->getRedisResourcesLinux();
        }
    }

    private function getRedisResourcesWindows()
    {
        // Redis on Windows might be running as a service, not a regular process
        // Try to check if it's running via socket
        try {
            $redis = @fsockopen('127.0.0.1', 6379, $errno, $errstr, 1);
            if ($redis) {
                fclose($redis);
                return [
                    'cpu' => 'N/A',
                    'ram_mb' => 'Unknown',
                    'status' => 'Running (Service)'
                ];
            }
        } catch (Exception $e) {
            // Continue
        }

        // Fallback: check for redis process
        $cmd = 'wmic process where name="redis-server.exe" get VirtualSize,Name /format:csv 2>nul';
        $output = trim(shell_exec($cmd));
        
        if ($output && strpos($output, 'redis-server.exe') !== false) {
            $parts = array_map('trim', explode(',', $output));
            if (isset($parts[1]) && is_numeric($parts[1])) {
                $ram = (int)$parts[1] / (1024 * 1024);
                return [
                    'cpu' => 'N/A',
                    'ram_mb' => round($ram, 2),
                    'status' => 'Running'
                ];
            }
        }

        return [
            'cpu' => 'N/A',
            'ram_mb' => 0,
            'status' => 'Not running'
        ];
    }

    private function getRedisResourcesLinux()
    {
        $output = shell_exec('ps aux | grep redis-server | grep -v grep');
        
        if (!$output) {
            return [
                'cpu' => 0,
                'ram_mb' => 0,
                'status' => 'Not running'
            ];
        }

        $parts = preg_split('/\s+/', $output);
        if (count($parts) >= 6) {
            $cpu = (float)$parts[2];
            $ram = (float)$parts[5] / 1024; // Convert KB to MB

            return [
                'cpu' => round($cpu, 2),
                'ram_mb' => round($ram, 2),
                'status' => 'Running'
            ];
        }

        return [
            'cpu' => 0,
            'ram_mb' => 0,
            'status' => 'Not running'
        ];
    }

    public function collectSamples(int $durationSeconds, int $intervalSeconds): array
    {
        $durationSeconds = max($durationSeconds, 1);
        $intervalSeconds = max($intervalSeconds, 1);
        $samples = [];
        $startedAt = microtime(true);
        $endAt = $startedAt + $durationSeconds;
        $nextSampleAt = $startedAt;

        while (microtime(true) < $endAt) {
            $samples[] = $this->getSnapshot();

            $nextSampleAt += $intervalSeconds;
            while (microtime(true) < $nextSampleAt && microtime(true) < $endAt) {
                usleep(100000);
            }
        }

        if ($samples === []) {
            $samples[] = $this->getSnapshot();
        }

        return $samples;
    }

    public function summarizeSamples(array $samples, int $durationSeconds, int $intervalSeconds): array
    {
        if ($samples === []) {
            return [
                'duration_seconds' => $durationSeconds,
                'interval_seconds' => $intervalSeconds,
                'samples' => 0,
                'avg_total_cpu' => 0,
                'max_total_cpu' => 0,
                'avg_total_ram_mb' => 0,
                'max_total_ram_mb' => 0,
                'last_snapshot' => null,
            ];
        }

        $cpuValues = [];
        $ramValues = [];

        foreach ($samples as $sample) {
            if (isset($sample['total']['cpu']) && is_numeric($sample['total']['cpu'])) {
                $cpuValues[] = (float) $sample['total']['cpu'];
            }
            if (isset($sample['total']['ram_mb']) && is_numeric($sample['total']['ram_mb'])) {
                $ramValues[] = (float) $sample['total']['ram_mb'];
            }
        }

        return [
            'duration_seconds' => $durationSeconds,
            'interval_seconds' => $intervalSeconds,
            'samples' => count($samples),
            'avg_total_cpu' => $cpuValues !== [] ? round(array_sum($cpuValues) / count($cpuValues), 2) : 0,
            'max_total_cpu' => $cpuValues !== [] ? round(max($cpuValues), 2) : 0,
            'avg_total_ram_mb' => $ramValues !== [] ? round(array_sum($ramValues) / count($ramValues), 2) : 0,
            'max_total_ram_mb' => $ramValues !== [] ? round(max($ramValues), 2) : 0,
            'last_snapshot' => $samples[array_key_last($samples)],
        ];
    }

    /**
     * Format output for display
     */
    public function display()
    {
        $snapshot = $this->getSnapshot();
        $php = $snapshot['php'];
        $mysql = $snapshot['mysql'];
        $redis = $snapshot['redis'];

        echo "\n";
        echo "╔════════════════════════════════════════════════════════════╗\n";
        echo "║       PROJECT RESOURCE MONITOR - Laravel E-Commerce        ║\n";
        echo "╠════════════════════════════════════════════════════════════╣\n";
        
        // PHP Section
        echo "║ 🔴 PHP (Laravel Process)                                   ║\n";
        echo "║    Status: " . str_pad($php['status'], 48) . "║\n";
        $cpuText = $php['cpu'] === 'N/A' ? $php['cpu'] : $php['cpu'] . '%';
        echo "║    CPU: " . str_pad($cpuText, 50) . "║\n";
        echo "║    RAM: " . str_pad($php['ram_mb'] . ' MB', 48) . "║\n";
        if (isset($php['processes'])) {
            echo "║    Processes: " . str_pad($php['processes'], 45) . "║\n";
        }
        echo "║                                                            ║\n";

        // MySQL Section
        echo "║ 🟢 MySQL (Database)                                       ║\n";
        echo "║    Status: " . str_pad($mysql['status'], 48) . "║\n";
        $mysqlCpu = is_numeric($mysql['cpu']) ? $mysql['cpu'] . '%' : $mysql['cpu'];
        echo "║    CPU: " . str_pad($mysqlCpu, 50) . "║\n";
        echo "║    RAM: " . str_pad($mysql['ram_mb'] . ' MB', 48) . "║\n";
        echo "║                                                            ║\n";

        // Redis Section
        echo "║ 🔵 Redis (Cache & Queue)                                  ║\n";
        echo "║    Status: " . str_pad($redis['status'], 48) . "║\n";
        $redisCpu = is_numeric($redis['cpu']) ? $redis['cpu'] . '%' : $redis['cpu'];
        echo "║    CPU: " . str_pad($redisCpu, 50) . "║\n";
        $redisRam = is_string($redis['ram_mb']) ? $redis['ram_mb'] : ($redis['ram_mb'] . ' MB');
        echo "║    RAM: " . str_pad($redisRam, 48) . "║\n";
        echo "║                                                            ║\n";

        // Total Section
        echo "╠════════════════════════════════════════════════════════════╣\n";
        echo "║ 📊 TOTAL PROJECT RESOURCES                                ║\n";
        
        // Calculate totals
        $totalCpu = $snapshot['total']['cpu'];
        $totalRam = $snapshot['total']['ram_mb'];
        
        echo "║    CPU Total: " . str_pad($totalCpu . '%', 45) . "║\n";
        echo "║    RAM Total: " . str_pad($totalRam . ' MB', 45) . "║\n";
        echo "║                                                            ║\n";

        // Status Indicator
        $cpuStatus = $totalCpu > 80 ? '🔥 HIGH' : ($totalCpu > 50 ? '⚠️  MODERATE' : '✅ NORMAL');
        echo "║    CPU Status: " . str_pad($cpuStatus, 43) . "║\n";
        $ramStatus = $totalRam > 500 ? '🔥 HIGH' : ($totalRam > 300 ? '⚠️  MODERATE' : '✅ NORMAL');
        echo "║    RAM Status: " . str_pad($ramStatus, 43) . "║\n";
        echo "║                                                            ║\n";

        // Timestamp
        echo "║ Last Updated: " . str_pad(date('Y-m-d H:i:s'), 42) . "║\n";
        echo "╚════════════════════════════════════════════════════════════╝\n";
        echo "\n";
    }

    /**
     * Export as JSON
     */
    public function toJson()
    {
        return json_encode($this->getSnapshot(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }
}

// Main execution
$monitor = new ProjectResourceMonitor();

// Check for command line arguments
$options = getopt('', ['json', 'duration::', 'interval::']);
$duration = isset($options['duration']) ? (int) $options['duration'] : 0;
$interval = isset($options['interval']) ? (int) $options['interval'] : 5;

if (isset($options['json']) && $duration > 0) {
    echo json_encode($monitor->summarizeSamples(
        $monitor->collectSamples($duration, $interval),
        $duration,
        $interval
    ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
} elseif (isset($options['json'])) {
    echo $monitor->toJson();
} elseif ($duration > 0) {
    $samples = $monitor->collectSamples($duration, $interval);
    $summary = $monitor->summarizeSamples($samples, $duration, $interval);

    echo PHP_EOL;
    echo "╔════════════════════════════════════════════════════════════╗\n";
    echo "║        PROJECT RESOURCE MONITOR - K6 TIMED RUN            ║\n";
    echo "╠════════════════════════════════════════════════════════════╣\n";
    echo "║ Duration: " . str_pad($summary['duration_seconds'] . ' seconds', 47) . "║\n";
    echo "║ Interval: " . str_pad($summary['interval_seconds'] . ' seconds', 48) . "║\n";
    echo "║ Samples: " . str_pad($summary['samples'], 50) . "║\n";
    echo "║ Avg CPU: " . str_pad($summary['avg_total_cpu'] . '%', 48) . "║\n";
    echo "║ Max CPU: " . str_pad($summary['max_total_cpu'] . '%', 48) . "║\n";
    echo "║ Avg RAM: " . str_pad($summary['avg_total_ram_mb'] . ' MB', 46) . "║\n";
    echo "║ Max RAM: " . str_pad($summary['max_total_ram_mb'] . ' MB', 46) . "║\n";
    echo "║                                                            ║\n";
    echo "║ Last Updated: " . str_pad(date('Y-m-d H:i:s'), 42) . "║\n";
    echo "╚════════════════════════════════════════════════════════════╝\n";
    echo "\n";
} else {
    $monitor->display();
}
