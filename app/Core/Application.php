<?php

declare(strict_types=1);

namespace App\Core;

final class Application
{
    private $basePath;
    private $router;

    public function __construct(string $basePath)
    {
        $this->basePath = $basePath;
        $this->router = new Router();
    }

    public function router(): Router
    {
        return $this->router;
    }

    public function basePath(string $path = ''): string
    {
        return $this->basePath . ($path !== '' ? '/' . ltrim($path, '/') : '');
    }

    public function run(): void
    {
        $request = Request::capture();
        $response = $this->router->dispatch($request);
        $response->send();
    }
}

