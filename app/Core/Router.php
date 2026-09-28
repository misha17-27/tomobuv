<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Простой быстрый роутер. Маршруты описаны в app/routes.php.
 *   $r->get('/product/{url}/', [ProductController::class, 'show']);
 *   $r->any('/cart/add/', [CartController::class, 'add']);
 * Плейсхолдер {name} — один сегмент пути без «/», {name*} — остаток пути (включая «/»).
 */
final class Router
{
    private array $routes = [];

    public function get(string $pattern, array $handler): void { $this->add(['GET', 'HEAD'], $pattern, $handler); }
    public function post(string $pattern, array $handler): void { $this->add(['POST'], $pattern, $handler); }
    public function any(string $pattern, array $handler): void { $this->add(['GET', 'HEAD', 'POST'], $pattern, $handler); }

    private function add(array $methods, string $pattern, array $handler): void
    {
        $re = preg_replace_callback('/\{(\w+)(\*)?\}/', static function ($m) {
            return '(?P<' . $m[1] . '>' . (isset($m[2]) && $m[2] === '*' ? '.+' : '[^/]+') . ')';
        }, $pattern);
        $this->routes[] = [$methods, '#^' . $re . '$#u', $handler];
    }

    public function dispatch(string $method, string $path): Response
    {
        $allowed = false;
        foreach ($this->routes as [$methods, $re, $handler]) {
            if (!preg_match($re, $path, $m)) continue;
            if (!in_array($method, $methods, true)) { $allowed = true; continue; }
            $params = array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY);
            [$class, $action] = $handler;
            $res = (new $class())->$action(...array_values($params));
            return $res instanceof Response ? $res : Response::html((string) $res);
        }
        if ($allowed) return Response::text('Method Not Allowed', 'text/plain; charset=utf-8', 405);
        return Response::notFound();
    }
}
