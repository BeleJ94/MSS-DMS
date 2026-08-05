<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

final class Router
{
    private $routes = [];

    public function get(string $path, $handler, array $middleware = []): void { $this->add('GET', $path, $handler, $middleware); }
    public function post(string $path, $handler, array $middleware = []): void { $this->add('POST', $path, $handler, $middleware); }

    public function add(string $method, string $path, $handler, array $middleware = []): void
    {
        $this->routes[strtoupper($method)]['/' . trim($path, '/')] = [
            'handler' => $handler,
            'middleware' => $middleware,
        ];
    }

    public function dispatch(Request $request): Response
    {
        $route = $this->routes[$request->method()][$request->path()] ?? null;
        if ($route === null) {
            foreach ($this->routes[$request->method()] ?? [] as $registeredPath => $candidate) {
                $parameterNames = [];
                $pattern = preg_replace_callback('/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/', function (array $matches) use (&$parameterNames): string {
                    $parameterNames[] = $matches[1];
                    return '([^/]+)';
                }, $registeredPath);
                if ($pattern !== null && preg_match('#^' . $pattern . '$#', $request->path(), $matches)) {
                    array_shift($matches);
                    $request->setRouteParams(array_combine($parameterNames, array_map('urldecode', $matches)) ?: []);
                    $route = $candidate;
                    break;
                }
            }
        }
        if ($route === null) {
            return new Response(View::render('errors/404', ['title' => 'Page introuvable']), 404);
        }

        foreach ($route['middleware'] as $middlewareClass) {
            $middleware = new $middlewareClass();
            $response = $middleware->handle($request);
            if ($response instanceof Response) {
                return $response;
            }
        }

        $handler = $route['handler'];

        if (is_array($handler) && isset($handler[0], $handler[1])) {
            $controller = new $handler[0]();
            $result = $controller->{$handler[1]}($request);
        } elseif (is_callable($handler)) {
            $result = $handler($request);
        } else {
            throw new RuntimeException('Gestionnaire de route invalide.');
        }

        return $result instanceof Response ? $result : new Response((string) $result);
    }
}
