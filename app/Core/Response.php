<?php

declare(strict_types=1);

namespace App\Core;

final class Response
{
    private $content;
    private $status;
    private $headers;

    public function __construct(string $content = '', int $status = 200, array $headers = [])
    {
        $this->content = $content;
        $this->status = $status;
        $this->headers = $headers;
    }

    public static function json(array $data, int $status = 200): self
    {
        return new self((string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $status, [
            'Content-Type' => 'application/json; charset=utf-8',
        ]);
    }

    public static function redirect(string $url, int $status = 302): self
    {
        return new self('', $status, ['Location' => $url]);
    }

    public function send(): void
    {
        $reasons = [
            200 => 'OK', 201 => 'Created', 302 => 'Found', 400 => 'Bad Request',
            401 => 'Unauthorized', 403 => 'Forbidden', 404 => 'Not Found',
            419 => 'Authentication Timeout', 422 => 'Unprocessable Entity', 429 => 'Too Many Requests',
            500 => 'Internal Server Error', 503 => 'Service Unavailable',
        ];
        $protocol = $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.1';
        header($protocol . ' ' . $this->status . ' ' . ($reasons[$this->status] ?? ''), true, $this->status);
        $securityHeaders = [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => 'geolocation=(self), camera=(self), microphone=()',
        ];
        foreach ($securityHeaders as $name => $value) {
            if (!array_key_exists($name, $this->headers)) {
                header($name . ': ' . $value);
            }
        }
        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value);
        }
        echo $this->content;
    }
}
