<?php

namespace App\Exceptions;

use Fleetbase\Exceptions\Handler as ExceptionHandler;

class Handler extends ExceptionHandler
{
    /**
     * A list of the inputs that are never flashed for validation exceptions.
     *
     * @var array<int, string>
     */
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
        'admission_code',
        'session_token',
    ];

    public function render($request, \Throwable $exception)
    {
        if ($request->is('construction-site/*')) {
            $status = 500;
            $message = '服务暂时不可用，请稍后重试。';
            $extra = [];
            $headers = [];
            if ($exception instanceof \Illuminate\Auth\AuthenticationException) {
                $status = 401; $message = 'Unauthenticated.';
            } elseif ($exception instanceof \Illuminate\Database\Eloquent\ModelNotFoundException) {
                $status = 404; $message = 'Requested resource not found.';
            } elseif ($exception instanceof \Illuminate\Validation\ValidationException) {
                $status = 422; $message = '请检查提交信息。'; $extra['errors'] = $exception->errors();
            } elseif ($exception instanceof \Fleetbase\Exceptions\FleetbaseRequestValidationException) {
                $status = 422; $message = '请检查提交信息。'; $extra['errors'] = $exception->getErrors();
            } elseif ($exception instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface) {
                $status = $exception->getStatusCode();
                $headers = $exception->getHeaders();
                if ($status < 500) $message = $exception->getMessage() ?: 'Request rejected.';
            }
            return response()->json(['message' => $message] + $extra, $status, $headers);
        }
        return parent::render($request, $exception);
    }
}
