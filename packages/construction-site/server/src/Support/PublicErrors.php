<?php

namespace Fleetbase\ConstructionSite\Support;

use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

trait PublicErrors
{
    protected function publicError(\Throwable $error)
    {
        if ($error instanceof ValidationException) {
            return response()->json(['status' => 'error', 'message' => '请检查提交信息。', 'errors' => $error->errors()], 422);
        }
        if ($error instanceof HttpExceptionInterface && $error->getStatusCode() < 500) {
            return response()->json(['status' => 'error', 'message' => $error->getMessage()], $error->getStatusCode());
        }
        report($error);
        return response()->json(['status' => 'error', 'message' => '服务暂时不可用，请稍后重试。'], 500);
    }
}
