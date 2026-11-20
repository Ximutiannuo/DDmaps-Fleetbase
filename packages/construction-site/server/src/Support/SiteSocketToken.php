<?php

namespace Fleetbase\ConstructionSite\Support;

final class SiteSocketToken
{
    public static function sign(array $claims): string
    {
        $key = config('construction-site.socket_key');
        if (!is_string($key) || strlen($key) < 32) throw new \RuntimeException('Socket signing key is not configured.');
        $encode = fn ($value) => rtrim(strtr(base64_encode(json_encode($value)), '+/', '-_'), '=');
        $data = $encode(['typ' => 'JWT', 'alg' => 'HS256']) . '.' . $encode($claims + ['iat' => time(), 'exp' => time() + 300, 'aud' => 'ddmaps-socket']);
        return $data . '.' . rtrim(strtr(base64_encode(hash_hmac('sha256', $data, $key, true)), '+/', '-_'), '=');
    }
}
