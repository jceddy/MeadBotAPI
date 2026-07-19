<?php

declare(strict_types=1);

namespace MeadBotApi\Http;

final class Router
{
    /** @var array<int, array{method: string, pattern: string, paramNames: string[], handler: callable}> */
    private array $routes = [];

    public function get(string $pattern, callable $handler): void
    {
        $this->add('GET', $pattern, $handler);
    }

    public function post(string $pattern, callable $handler): void
    {
        $this->add('POST', $pattern, $handler);
    }

    private function add(string $method, string $pattern, callable $handler): void
    {
        $paramNames = [];
        $regex = preg_replace_callback('#\{([a-zA-Z_][a-zA-Z0-9_]*)\}#', function ($m) use (&$paramNames) {
            $paramNames[] = $m[1];
            return '(?P<' . $m[1] . '>[^/]+)';
        }, $pattern);

        $this->routes[] = [
            'method' => $method,
            'pattern' => '#^' . $regex . '$#',
            'paramNames' => $paramNames,
            'handler' => $handler,
        ];
    }

    /**
     * Dispatch the given method/path. Returns [statusCode, body-array].
     *
     * @return array{0: int, 1: array<string, mixed>}
     */
    public function dispatch(string $method, string $path, callable $paramsProvider): array
    {
        $methodMatchedAnyPattern = false;

        foreach ($this->routes as $route) {
            if (!preg_match($route['pattern'], $path, $matches)) {
                continue;
            }

            if ($route['method'] !== $method) {
                $methodMatchedAnyPattern = true;
                continue;
            }

            $pathParams = [];
            foreach ($route['paramNames'] as $name) {
                $pathParams[$name] = $matches[$name];
            }

            $params = $paramsProvider($pathParams);

            try {
                $result = ($route['handler'])($params);
                $status = is_array($result) && ($result['error'] ?? false) ? 400 : 200;
                return [$status, $result];
            } catch (\InvalidArgumentException $e) {
                return [400, ['error' => true, 'errorMessage' => $e->getMessage()]];
            }
        }

        if ($methodMatchedAnyPattern) {
            return [405, ['error' => true, 'errorMessage' => 'Method not allowed: ' . $method . ' ' . $path]];
        }

        return [404, ['error' => true, 'errorMessage' => 'Not found: ' . $method . ' ' . $path]];
    }
}
