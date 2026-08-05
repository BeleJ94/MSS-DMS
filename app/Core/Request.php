<?php

declare(strict_types=1);

namespace App\Core;

final class Request
{
    private $method;
    private $path;
    private $query;
    private $body;
    private $routeParams = [];

    private function __construct(string $method, string $path, array $query, array $body)
    {
        $this->method = strtoupper($method);
        $this->path = $path;
        $this->query = $query;
        $this->body = $body;
    }

    public static function capture(): self
    {
        $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $scriptDirectory = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
        if ($scriptDirectory !== '/' && $scriptDirectory !== '.' && strpos($uri, $scriptDirectory) === 0) {
            $uri = substr($uri, strlen($scriptDirectory)) ?: '/';
        }

        $body = $_POST;
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        if (stripos($contentType, 'application/json') !== false) {
            $decoded = json_decode((string) file_get_contents('php://input'), true);
            $body = is_array($decoded) ? $decoded : [];
        }

        return new self($_SERVER['REQUEST_METHOD'] ?? 'GET', '/' . trim($uri, '/'), $_GET, $body);
    }

    public function method(): string { return $this->method; }
    public function path(): string { return $this->path; }
    public function query(string $key, $default = null) { return $this->query[$key] ?? $default; }
    public function input(string $key, $default = null) { return $this->body[$key] ?? $default; }
    public function all(): array { return array_merge($this->query, $this->body); }
    public function file(string $key): ?array
    {
        $file = $_FILES[$key] ?? null;
        return is_array($file) ? $file : null;
    }
    public function setRouteParams(array $params): void { $this->routeParams = $params; }
    public function param(string $key, $default = null) { return $this->routeParams[$key] ?? $default; }
}
