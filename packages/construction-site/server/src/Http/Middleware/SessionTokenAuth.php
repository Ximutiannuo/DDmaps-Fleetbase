<?php

namespace Fleetbase\ConstructionSite\Http\Middleware;

use Closure;
use Fleetbase\ConstructionSite\Models\EntryRegistration;
use Illuminate\Http\Request;

class SessionTokenAuth
{
    /**
     * Handle an incoming request.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        $token = $this->resolveToken($request);
        if (empty($token)) {
            return response()->json(
                [
                    'status'  => 'error',
                    'message' => 'Session token required.',
                ],
                401
            );
        }

        $registration = EntryRegistration::where('session_token', hash('sha256', $token))
            ->where('status', 'active')
            ->whereNull('exited_at')
            ->where('token_expires_at', '>', now())
            ->first();

        if (!$registration) {
            return response()->json(
                [
                    'status'  => 'error',
                    'message' => 'Invalid or expired session token.',
                ],
                401
            );
        }

        $request->attributes->set('entryRegistration', $registration);
        session(['company' => $registration->company_uuid]);

        return $next($request);
    }

    /** Resolve the token exclusively from the Authorization header. */
    protected function resolveToken(Request $request): ?string
    {
        $bearer = $request->bearerToken();
        if (!empty($bearer)) {
            return $bearer;
        }

        return null;
    }
}
