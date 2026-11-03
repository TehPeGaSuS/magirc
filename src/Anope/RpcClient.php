<?php

declare(strict_types=1);

namespace Magirc\Anope;

/**
 * Minimal JSON-RPC 2.0 client for Anope's jsonrpc module.
 *
 * Anope compares the bearer token after base64-decoding it, so the raw token
 * from modules.conf is encoded here. Uses streams rather than curl so the
 * only hard requirement is allow_url_fopen.
 */
final class RpcClient
{
    public function __construct(
        private readonly string $url,
        private readonly string $token,
        private readonly int $timeout = 5,
    ) {
    }

    /**
     * @param list<mixed> $params
     * @return mixed The "result" member of the response.
     * @throws RpcException on transport failure or an error response.
     */
    public function call(string $method, array $params = []): mixed
    {
        $body = json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => $method,
            'params' => $params,
        ], JSON_THROW_ON_ERROR);

        $headers = "Content-Type: application/json\r\n";
        if ($this->token !== '') {
            $headers .= 'Authorization: Bearer ' . base64_encode($this->token) . "\r\n";
        }

        $context = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => $headers,
            'content' => $body,
            'timeout' => $this->timeout,
            'ignore_errors' => true,
        ]]);

        $raw = @file_get_contents($this->url, false, $context);
        if ($raw === false) {
            $err = error_get_last();
            throw new RpcException(sprintf('Cannot reach Anope at %s: %s', $this->url, $err['message'] ?? 'unknown error'));
        }

        try {
            $response = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new RpcException('Anope returned invalid JSON: ' . $e->getMessage());
        }

        if (!is_array($response)) {
            throw new RpcException('Anope returned an unexpected response.');
        }
        if (isset($response['error'])) {
            $error = $response['error'];
            $code = is_array($error) ? (int) ($error['code'] ?? 0) : 0;
            $message = is_array($error) ? (string) ($error['message'] ?? 'unknown error') : (string) $error;
            throw new RpcException(sprintf('%s: %s', $method, $message), $code);
        }
        return $response['result'] ?? null;
    }
}
