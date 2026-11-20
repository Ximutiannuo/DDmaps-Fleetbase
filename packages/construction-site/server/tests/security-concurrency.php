<?php
require __DIR__ . '/../../../../api/vendor/autoload.php';
$app = require __DIR__ . '/../../../../api/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Fleetbase\ConstructionSite\Services\EntrySessionService;
if (env('DDMAPS_INTEGRATION_TEST') !== '1') throw new RuntimeException('Run only in docker-compose.test.yml; this test commits disposable fixtures.');
Event::fake([Fleetbase\ConstructionSite\Events\SiteNotificationCreated::class, Fleetbase\ConstructionSite\Events\RoadNetworkChanged::class]);
if (($argv[1] ?? '') === 'worker') {
    config(['construction-site.default_company_uuid' => $argv[4]]);
    $inputs = json_decode(file_get_contents($argv[2]), true, 512, JSON_THROW_ON_ERROR);
    try { app(EntrySessionService::class)->register($inputs[(int) $argv[3]]); echo '200'; }
    catch (Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) { echo $e->getStatusCode(); }
    catch (Throwable $e) { fwrite(STDERR, get_class($e)); exit(1); }
    exit(0);
}
$company = (string) Str::uuid();
DB::table('companies')->insert(['uuid' => $company, 'name' => 'Disposable concurrency fixture']);
$cases = [
    'same vehicle' => [['CONCURRENT1', '+79001110001'], ['CONCURRENT1', '+79001110002']],
    'same driver' => [['CONCURRENT2', '+79001110003'], ['CONCURRENT3', '+79001110003']],
];
$expectedActive = 0;
foreach ($cases as $scenario => $registrations) {
    $inputs = array_map(fn ($pair) => [
        'plate_number' => $pair[0], 'driver_name' => 'Concurrency fixture', 'driver_phone' => $pair[1],
    ], $registrations);
    $file = tempnam(sys_get_temp_dir(), 'entry-race-'); chmod($file, 0600);
    file_put_contents($file, json_encode($inputs, JSON_THROW_ON_ERROR)); $processes = [];
    try {
        for ($i = 0; $i < 2; $i++) {
            $process = proc_open([PHP_BINARY, __FILE__, 'worker', $file, (string) $i, $company], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            if (!is_resource($process)) throw new RuntimeException('Cannot launch parallel worker');
            fclose($pipes[0]); $processes[] = [$process, $pipes];
        }
        $statuses = [];
        foreach ($processes as [$process, $pipes]) {
            $statuses[] = (int) trim(stream_get_contents($pipes[1])); $error = stream_get_contents($pipes[2]);
            fclose($pipes[1]); fclose($pipes[2]);
            if (proc_close($process) !== 0) throw new RuntimeException('Worker failed: ' . $error);
        }
        sort($statuses);
        if ($statuses !== [200, 409]) throw new RuntimeException('Unexpected concurrent outcomes: ' . json_encode($statuses));
        if (DB::table('entry_registrations')->where('company_uuid', $company)->where('status', 'active')->count() !== ++$expectedActive) throw new RuntimeException('Duplicate active entry');
        echo "PASS $scenario: concurrent registration creates exactly one active session\n";
    } finally { unlink($file); }
}
