<?php

declare(strict_types=1);

namespace App\Core\Http;

use App\Core\Exception\HttpException;
use App\Core\Exception\NotFoundException;
use App\Core\Exception\MethodNotAllowedException;

class Router
{
    private array $routes = [];
    private array $middlewares = [];

    public function get(string $path, callable|array $handler): self
    {
        return $this->addRoute('GET', $path, $handler);
    }

    public function post(string $path, callable|array $handler): self
    {
        return $this->addRoute('POST', $path, $handler);
    }

    public function put(string $path, callable|array $handler): self
    {
        return $this->addRoute('PUT', $path, $handler);
    }

    public function delete(string $path, callable|array $handler): self
    {
        return $this->addRoute('DELETE', $path, $handler);
    }

    public function patch(string $path, callable|array $handler): self
    {
        return $this->addRoute('PATCH', $path, $handler);
    }

    public function options(string $path, callable|array $handler): self
    {
        return $this->addRoute('OPTIONS', $path, $handler);
    }

    public function addMiddleware(string $name, callable $middleware): self
    {
        $this->middlewares[$name] = $middleware;
        return $this;
    }

    private function addRoute(string $method, string $path, callable|array $handler, array $middlewares = []): self
    {
        $this->routes[] = [
            'method' => $method,
            'path' => $path,
            'handler' => $handler,
            'middlewares' => $middlewares,
        ];
        return $this;
    }

    public function dispatch(Request $request): Response
    {
        $method = $request->getMethod();
        $uri = $request->getUri();

        // Handle OPTIONS for CORS preflight
        if ($method === 'OPTIONS') {
            return Response::json(null, 200);
        }

        $matchedRoute = null;
        $methodMatched = false;

        foreach ($this->routes as $route) {
            $pattern = $this->pathToRegex($route['path']);

            if (preg_match($pattern, $uri, $matches)) {
                if ($route['method'] === $method) {
                    $matchedRoute = $route;

                    // Extract route parameters
                    $params = $this->extractParams($route['path'], $matches);
                    $request->setRouteParams($params);
                    break;
                }
                $methodMatched = true;
            }
        }

        if ($matchedRoute === null) {
            if ($methodMatched) {
                throw new MethodNotAllowedException();
            }
            throw new NotFoundException("Route not found: $method $uri");
        }

        // Execute handler
        $handler = $matchedRoute['handler'];

        if (is_callable($handler)) {
            $result = $handler($request);
        } elseif (is_array($handler)) {
            [$controllerClass, $method] = $handler;
            $controller = new $controllerClass();
            $result = $controller->$method($request);
        } else {
            throw new HttpException('Invalid route handler', 500);
        }

        // Convert result to Response if needed
        if ($result instanceof Response) {
            return $result;
        }

        return Response::json($result);
    }

    private function pathToRegex(string $path): string
    {
        // Escape forward slashes
        $pattern = preg_quote($path, '#');

        // Convert {param} to named capture groups
        $pattern = preg_replace('/\\\{([a-zA-Z_]+)\\\}/', '(?P<$1>[^/]+)', $pattern);

        return '#^' . $pattern . '$#';
    }

    private function extractParams(string $path, array $matches): array
    {
        $params = [];

        // Find all {param} in the path
        preg_match_all('/\{([a-zA-Z_]+)\}/', $path, $paramNames);

        foreach ($paramNames[1] as $name) {
            if (isset($matches[$name])) {
                $params[$name] = $matches[$name];
            }
        }

        return $params;
    }
}
