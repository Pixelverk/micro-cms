<?php
declare(strict_types=1);

$_SERVER['REQUEST_START'] = microtime(true);

function add_perf_log(string $message): void {
    $logDir = STORAGE_PATH . '/logs';
    $file = $logDir . '/perf.log';
    $date = date('Y-m-d H:i:s');
    file_put_contents($file, "[$date] $message\n", FILE_APPEND | LOCK_EX);
}

function stop_logging(bool $hit = false): void {
    $duration = microtime(true) - ($_SERVER['REQUEST_START'] ?? microtime(true));
    $suffix = $hit ? ' | CACHE HIT' : '';

    // Active memory (actual usage)
    $active_used_kb = memory_get_usage(false) / 1024;
    $active_peak_kb = memory_get_peak_usage(false) / 1024;

    // Allocated/reserved memory (total PHP allocation)
    $total_used_kb = memory_get_usage(true) / 1024;
    $total_peak_kb = memory_get_peak_usage(true) / 1024;

    $msg = sprintf(
        'Request %s took %.3f ms | ' .
        'Active Mem: %.0f KB / %.2f MB (peak %.0f KB / %.2f MB) | ' .
        'Allocated Mem: %.0f KB / %.2f MB (peak %.0f KB / %.2f MB)%s',
        $_SERVER['REQUEST_URI'] ?? '-',
        $duration * 1000,

        // Active memory
        $active_used_kb,
        $active_used_kb / 1024,
        $active_peak_kb,
        $active_peak_kb / 1024,

        // Allocated memory
        $total_used_kb,
        $total_used_kb / 1024,
        $total_peak_kb,
        $total_peak_kb / 1024,

        $suffix
    );

    add_perf_log($msg);
}