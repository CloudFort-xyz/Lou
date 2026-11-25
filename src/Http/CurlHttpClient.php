<?php

declare(strict_types=1);

namespace Lou\Http;

use Lou\LouException;

final class CurlHttpClient implements HttpClient
{
    public function __construct(private readonly int $timeout = 10)
    {
    }

    public function request(string $method, string $url, array $headers = [], ?string $body = null): array
    {
        if (!str_starts_with($url, 'https://') && !str_starts_with($url, 'http://')) {
            throw new LouException("Refusing non-HTTP URL: $url");
        }
        $ch = curl_init($url);
        $lines = [];
        foreach ($headers as $k => $v) {
            $lines[] = "$k: $v";
        }
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $lines,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS | CURLPROTO_HTTP,
            CURLOPT_USERAGENT      => 'Lou-CMS',
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $res = curl_exec($ch);
        if ($res === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new LouException("HTTP $method $url failed: $err");
        }
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return ['status' => $status, 'body' => (string) $res];
    }
}
