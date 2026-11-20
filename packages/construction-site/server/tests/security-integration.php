<?php

// Isolated fixtures in one rolled-back transaction; never deletes production data.
require __DIR__ . '/../../../../api/vendor/autoload.php';
$app = require __DIR__ . '/../../../../api/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Fleetbase\ConstructionSite\Http\Controllers\EntryRegistrationController;
use Fleetbase\ConstructionSite\Http\Middleware\ConstructionSiteAuthorization;
use Fleetbase\ConstructionSite\Http\Middleware\SessionTokenAuth;
use Fleetbase\ConstructionSite\Models\EntryRegistration;
use Fleetbase\ConstructionSite\Services\EntrySessionService;
use Fleetbase\ConstructionSite\Support\RegistrationInput;
use Fleetbase\ConstructionSite\Support\RoadInput;
use Fleetbase\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

function securityCheck(bool $ok, string $description): void {
    if (!$ok) throw new RuntimeException($description);
    echo "PASS $description\n";
}
function rejects(callable $fn, int $status): void {
    try { $fn(); } catch (HttpExceptionInterface $e) {
        securityCheck($e->getStatusCode() === $status, "Request rejected with $status"); return;
    }
    throw new RuntimeException("Expected rejection $status");
}

Event::fake([Fleetbase\ConstructionSite\Events\SiteNotificationCreated::class, Fleetbase\ConstructionSite\Events\RoadNetworkChanged::class]);
DB::beginTransaction();
try {
    $company = (string) Str::uuid();
    DB::table('companies')->insert(['uuid' => $company, 'name' => 'Security rollback fixture']);
    $admin = (new User())->forceFill(['uuid' => (string) Str::uuid(), 'company_uuid' => $company, 'type' => 'admin']);
    $reader = (new User())->forceFill(['uuid' => (string) Str::uuid(), 'company_uuid' => $company, 'type' => 'driver']);
    session(['company' => $company, 'user' => $admin->uuid]);
    securityCheck(ConstructionSiteAuthorization::canWrite($admin), 'Admin can manage site');
    securityCheck(!ConstructionSiteAuthorization::canWrite($reader), 'Unconfigured low-privilege user denied');
    securityCheck(RegistrationInput::plate(' ab 123 ') === 'AB123', 'Plate normalization');
    securityCheck(RegistrationInput::phone('+7 (900) 123-45-67') === '+79001234567', 'International phone normalization');
    $data = ['plate_number' => 'TEST123', 'driver_phone' => '+79001234567', 'driver_name' => 'Fixture driver'];
    $service = app(EntrySessionService::class);
    config(['construction-site.default_company_uuid' => null]);
    rejects(fn () => $service->register($data), 403);
    config(['construction-site.default_company_uuid' => $company, 'construction-site.default_gate_uuid' => null]);
    $entry = $service->register($data + ['company_uuid' => (string) Str::uuid(), 'gate_uuid' => (string) Str::uuid()]);
    securityCheck($entry->company_uuid === $company && $entry->gate_uuid === null, 'Direct registration uses the configured company and gate');
    securityCheck(strlen($entry->issuedSessionToken) === 64, 'Registration issues raw token once');
    securityCheck($entry->session_token === hash('sha256', $entry->issuedSessionToken), 'Database stores token hash');
    securityCheck(!array_key_exists('session_token', $entry->toArray()), 'Resource does not serialize token');
    securityCheck($entry->token_expires_at->isFuture(), 'Session has expiry');
    rejects(fn () => $service->register($data), 409);
    rejects(fn () => $service->register(array_replace($data, ['plate_number' => 'OTHER123'])), 409);
    securityCheck($entry->fresh()->session_token === $entry->session_token, 'Rejected registration preserves active token');
    securityCheck(DB::table('entry_session_audits')->where('entry_uuid', $entry->uuid)->count() === 1, 'Vehicle binding audited');
    $middleware = app(SessionTokenAuth::class);
    $authenticate = function ($token = null, $query = []) use ($middleware) {
        $request = Request::create('/tracking/status', 'GET', $query);
        if ($token) $request->headers->set('Authorization', 'Bearer ' . $token);
        return $middleware->handle($request, fn () => response()->json(['ok' => true]));
    };
    securityCheck($authenticate($entry->issuedSessionToken)->getStatusCode() === 200, 'Raw Bearer authenticates');
    securityCheck($authenticate($entry->session_token)->getStatusCode() === 401, 'Stored hash cannot authenticate');
    securityCheck($authenticate(null, ['session_token' => $entry->issuedSessionToken])->getStatusCode() === 401, 'Query token rejected');
    $entry->forceFill(['token_expires_at' => now()->subSecond()])->saveQuietly();
    securityCheck($authenticate($entry->issuedSessionToken)->getStatusCode() === 401, 'Expired session rejected');
    $replacement = $service->register($data);
    securityCheck($replacement->uuid !== $entry->uuid && $entry->fresh()->status === 'exited', 'Expired session can be safely replaced');
    $service->exit($replacement);
    securityCheck($authenticate($replacement->issuedSessionToken)->getStatusCode() === 401, 'Exited session rejected');
    try {
        RoadInput::segment(['name' => 'bad', 'geometry' => ['type' => 'LineString', 'coordinates' => [[190, 0], [0, 0]]]], true);
        throw new RuntimeException('Invalid geometry accepted');
    } catch (Illuminate\Validation\ValidationException $e) { securityCheck(true, 'Invalid geometry rejected'); }
    $anonymous = Request::create('/roads', 'POST', $data);
    securityCheck(app(ConstructionSiteAuthorization::class)->handle($anonymous, fn () => response('unsafe'))->getStatusCode() === 401, 'Unauthenticated writes denied');
    echo "Security integration passed; fixtures rolled back.\n";
} catch (\Throwable $error) {
    fwrite(STDERR, get_class($error) . ': ' . $error->getMessage() . "\n" . $error->getTraceAsString() . "\n");
    exit(1);
} finally { DB::rollBack(); }
