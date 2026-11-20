<?php
// Runs only against the disposable Compose stack, including real HTTP middleware.
require __DIR__ . '/../../../../api/vendor/autoload.php';
$app = require __DIR__ . '/../../../../api/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (env('DDMAPS_INTEGRATION_TEST') !== '1') throw new RuntimeException('Disposable environment required.');
use Illuminate\Support\Facades\Http;
use Fleetbase\Models\Company;
use Fleetbase\Models\CompanyUser;
use Fleetbase\Models\User;
function httpCheck($response, $status, $message) {
    if ($response->status() !== $status) throw new RuntimeException("$message: expected $status, got " . $response->status());
    echo "PASS HTTP $message ($status)\n";
}
try {
$a = (new Company())->forceFill(['uuid' => config('construction-site.default_company_uuid'), 'name' => 'HTTP security fixture A']);
$a->save();
$b = Company::create(['name' => 'HTTP security fixture B']);
$clients = [];
$users = [];
foreach (['admin' => $a, 'foreign' => $b, 'reader' => $a] as $name => $company) {
    $user = (new User())->forceFill(['name' => 'HTTP fixture', 'email' => uniqid('http-') . '@example.test',
        'company_uuid' => $company->uuid, 'type' => $name === 'reader' ? 'user' : 'admin', 'status' => 'active', 'password' => bin2hex(random_bytes(20))]);
    $user->save();
    $users[$name] = $user;
    (new CompanyUser())->forceFill(['company_uuid' => $company->uuid, 'user_uuid' => $user->uuid, 'status' => 'active'])->save();
    $clients[$name] = Http::acceptJson()->timeout(15)->withToken($user->createToken('integration-only')->plainTextToken);
}
$url = 'http://localhost:8000/construction-site/int/v1';
httpCheck(Http::acceptJson()->get("$url/roads"), 401, 'anonymous management denied');
$road = $clients['foreign']->post("$url/roads", ['site_road' => ['name' => 'Foreign HTTP road', 'road_type' => 'main', 'status' => 'open']]);
httpCheck($road, 200, 'own road creation succeeds');
$id = $road->json('site_road.uuid') ?? $road->json('site_road.public_id');
if (!$id) throw new RuntimeException('Created road identifier missing');
httpCheck($clients['foreign']->get("$url/roads/$id"), 200, 'own road detail succeeds');
httpCheck($clients['foreign']->patch("$url/roads/$id", ['site_road' => ['name' => 'Updated own HTTP road']]), 200, 'own road update succeeds');
httpCheck($clients['admin']->get("$url/roads/$id"), 404, 'foreign road detail denied');
httpCheck($clients['admin']->patch("$url/roads/$id", ['site_road' => ['name' => 'Attack']]), 404, 'foreign road update denied');
httpCheck($clients['admin']->delete("$url/roads/$id"), 404, 'foreign road delete denied');
$list = $clients['admin']->get("$url/roads", ['company_uuid' => $b->uuid]);
httpCheck($list, 200, 'filtered list succeeds');
if (str_contains($list->body(), $id)) throw new RuntimeException('HTTP foreign road leaked');
echo "PASS HTTP forged company filter cannot expose foreign road\n";
httpCheck($clients['admin']->delete("$url/roads/bulk-delete", ['ids' => [$id]]), 404, 'foreign bulk delete denied');
httpCheck($clients['foreign']->get("$url/roads/$id"), 200, 'rejected bulk delete preserves foreign road');
httpCheck($clients['foreign']->delete("$url/roads/bulk-delete", ['ids' => [$id]]), 200, 'own bulk delete succeeds');
httpCheck($clients['reader']->post("$url/roads", ['site_road' => ['name' => 'Unauthorized']]), 403, 'reader write denied');
httpCheck($clients['reader']->post("$url/notifications/read-all"), 200, 'reader can mark own notifications read');
$readId = (string) Illuminate\Support\Str::uuid();
Illuminate\Support\Facades\DB::table('site_notifications')->insert(['uuid' => $readId, 'company_uuid' => $a->uuid,
    'title' => 'Read fixture', 'message' => 'Fixture', 'read_at' => now()]);
$unreadId = (string) Illuminate\Support\Str::uuid();
Illuminate\Support\Facades\DB::table('site_notifications')->insert(['uuid' => $unreadId, 'company_uuid' => $a->uuid,
    'title' => 'Unread fixture', 'message' => 'Fixture', 'read_at' => null]);
foreach (['unread', 'unread_only'] as $filter) {
    $unread = $clients['admin']->get("$url/notifications", [$filter => true]);
    httpCheck($unread, 200, "$filter query succeeds");
    if (str_contains($unread->body(), $readId) || !str_contains($unread->body(), $unreadId)) {
        throw new RuntimeException("$filter query must include unread and exclude read notifications");
    }
    echo "PASS HTTP $filter query returns only unread notifications\n";
}
$process = proc_open([PHP_BINARY, '/tmp/grant-site-writer.php', $a->uuid, $users['reader']->uuid], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
$output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
fclose($pipes[1]); fclose($pipes[2]);
if (proc_close($process) !== 0) throw new RuntimeException('Writer grant helper failed: ' . $output);
$delegated = $clients['reader']->post("$url/roads", ['site_road' => ['name' => 'Delegated HTTP road', 'road_type' => 'main', 'status' => 'open']]);
httpCheck($delegated, 200, 'explicitly delegated writer can create roads');
$form = ['plate_number' => 'HTTP123', 'driver_phone' => '+79000000000', 'driver_name' => 'HTTP fixture driver', 'company_uuid' => $b->uuid];
$registration = Http::acceptJson()->post("$url/tracking/register", $form);
httpCheck($registration, 200, 'driver can register directly');
if ($registration->json('entry_registration.company_uuid') !== $a->uuid) throw new RuntimeException('The submitted company changed registration ownership');
httpCheck(Http::acceptJson()->post("$url/tracking/register", $form), 409, 'duplicate registration cannot replace active session');
$driver = Http::acceptJson()->withToken($registration->json('session_token'));
httpCheck($driver->get("$url/tracking/status"), 200, 'driver Bearer session works');
httpCheck(Http::acceptJson()->get("$url/tracking/status", ['session_token' => $registration->json('session_token')]), 401, 'URL session token rejected');
$gps = ['latitude' => 60.6, 'longitude' => -135.73, 'accuracy' => 10, 'observed_at' => now()->toIso8601String()];
for ($i = 0; $i < 30; $i++) {
    $response = $driver->post("$url/tracking/update", $gps);
    if ($response->status() !== 200) throw new RuntimeException('GPS allowance rejected legitimate update: ' . $response->status());
}
$limited = $driver->post("$url/tracking/update", $gps);
httpCheck($limited, 429, 'GPS rate limit enforced');
if (!$limited->header('Retry-After')) throw new RuntimeException('Rate limit retry header missing');
echo "PASS HTTP rate limit supplies Retry-After\n";
httpCheck($driver->post("$url/tracking/exit"), 200, 'driver exit succeeds despite GPS throttling');
httpCheck($driver->get("$url/tracking/status"), 401, 'exited driver token revoked');
for ($i = 0; $i < 20; $i++) {
    if ($clients['admin']->get("$url/socket-token")->status() !== 200) throw new RuntimeException('Socket ticket allowance failed');
}
httpCheck($clients['admin']->get("$url/socket-token"), 429, 'socket ticket rate limit enforced');
CompanyUser::where('company_uuid', $a->uuid)->where('user_uuid', $users['reader']->uuid)->update(['status' => 'inactive']);
httpCheck($clients['reader']->get("$url/roads"), 403, 'inactive membership denied');
echo "HTTP security integration passed in disposable database.\n";
} catch (\Throwable $error) { fwrite(STDERR, $error->getMessage() . "\n"); exit(1); }
