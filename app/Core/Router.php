<?php
declare(strict_types=1);

namespace App\Core;

final class Router
{
    private array $routes = [];

    public function get(string $pattern, array $handler): void
    {
        $this->add('GET', $pattern, $handler);
    }

    public function post(string $pattern, array $handler): void
    {
        $this->add('POST', $pattern, $handler);
    }

    private function add(string $method, string $pattern, array $handler): void
    {
        // {slug} = clinic slug, any other {param} = numeric id. Anything else never matches.
        $regex = preg_replace_callback('#\{(\w+)\}#', static function (array $m): string {
            $rule = $m[1] === 'slug' ? '[a-z0-9-]{2,60}' : '\d{1,10}';
            return '(?P<' . $m[1] . '>' . $rule . ')';
        }, $pattern);
        $this->routes[] = [$method, '#^' . $regex . '$#', $handler];
    }

    public function dispatch(string $method, string $path): void
    {
        if ($method === 'HEAD') {
            $method = 'GET';
        }
        $methodMismatch = false;
        foreach ($this->routes as [$routeMethod, $regex, [$class, $action]]) {
            if (!preg_match($regex, $path, $matches)) {
                continue;
            }
            if ($routeMethod !== $method) {
                $methodMismatch = true;
                continue;
            }
            $params = [];
            foreach ($matches as $key => $value) {
                if (is_string($key)) {
                    $params[$key] = ctype_digit($value) && $key !== 'slug' ? (int) $value : $value;
                }
            }
            (new $class())->$action(...$params);
            return;
        }
        throw new HttpException($methodMismatch ? 405 : 404);
    }
}
