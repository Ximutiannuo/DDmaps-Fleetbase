<?php

// The scheduler has no HTTP metrics server. Check its process and successful minute ticks.
$command = @file_get_contents('/proc/1/cmdline');
$heartbeat = @filemtime('/tmp/fleetbase-scheduler-heartbeat');
if ($command === false || !str_contains($command, 'go-crond')) {
    fwrite(STDERR, "Scheduler process is missing.\n");
    exit(1);
}
if ($heartbeat === false || time() - $heartbeat > 150) {
    fwrite(STDERR, "No successful scheduler tick in the last 150 seconds.\n");
    exit(1);
}
echo "Scheduler process and heartbeat are healthy.\n";
