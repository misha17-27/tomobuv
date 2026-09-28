<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Ответ контроллера.
 *   return Response::html(View::render(...))->cache(3600);   // страница витрины, кэшируется
 *   return Response::json(['ok' => true]);
 *   return Response::redirect('/cart/');
 *   return Response::notFound();
 */
final class Response
{
    public int $status = 200;
    public string $body = '';
    public array $headers = [];
    public int $cacheTtl = 0;

    public static function html(string $body, int $status = 200): self
    {
        $r = new self();
        $r->body = $body;
        $r->status = $status;
        $r->headers['Content-Type'] = 'text/html; charset=utf-8';
        return $r;
    }

    public static function json($data, int $status = 200): self
    {
        $r = new self();
        $r->body = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $r->status = $status;
        $r->headers['Content-Type'] = 'application/json; charset=utf-8';
        return $r;
    }

    public static function text(string $body, string $type = 'text/plain; charset=utf-8', int $status = 200): self
    {
        $r = new self();
        $r->body = $body;
        $r->status = $status;
        $r->headers['Content-Type'] = $type;
        return $r;
    }

    public static function redirect(string $to, int $status = 302): self
    {
        $r = new self();
        $r->status = $status;
        $r->headers['Location'] = $to;
        return $r;
    }

    public static function notFound(): self
    {
        return \App\Controllers\Front\ErrorController::notFound();
    }

    /** Разрешить кэширование готовой страницы на $ttl секунд (только 200 и только витрина) */
    public function cache(int $ttl = 0): self
    {
        $this->cacheTtl = $ttl > 0 ? $ttl : (int) App::config('cache.page_ttl', 3600);
        return $this;
    }

    /**
     * Заголовки безопасности для любого ответа PHP (в т. ч. страниц из кэша). Apache ставит их и сам (public/.htaccess),
     * но на php -S, nginx или без mod_headers их бы не было. Свой заголовок ответа (Referrer-Policy: no-referrer) их заменяет.
     */
    public static function securityHeaders(): void
    {
        if (headers_sent()) return;
        header_remove('X-Powered-By');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        if (Session::https()) header('Strict-Transport-Security: max-age=31536000');
    }

    public function header(string $name, string $value): self
    {
        $this->headers[$name] = $value;
        return $this;
    }

    public function send(): void
    {
        // украинская версия: внутренние ссылки → /ua/… (до сохранения в кэш страниц)
        $ct = $this->headers['Content-Type'] ?? '';
        if (Lang::isUk() && (str_starts_with($ct, 'text/html') || str_starts_with($ct, 'application/json'))) {
            $this->body = Lang::rewriteLinks($this->body);
        }
        if (isset($this->headers['Location']) && Lang::isUk() && str_starts_with($this->headers['Location'], '/') && !str_starts_with($this->headers['Location'], '/ua/')) {
            $loc = $this->headers['Location'];
            if (!preg_match('#^/(admin|assets|wa-data|uploads)/#', $loc)) $this->headers['Location'] = Lang::path($loc);
        }
        http_response_code($this->status);
        self::securityHeaders();
        foreach ($this->headers as $k => $v) header($k . ': ' . $v);
        if ($this->status === 200 && $this->cacheTtl > 0) {
            PageCache::store($this->body, $this->cacheTtl, $this->headers['Content-Type'] ?? 'text/html; charset=utf-8');
            header('X-Cache: MISS');
        } elseif (!isset($this->headers['Cache-Control'])) {
            header('Cache-Control: no-store, private');
        }
        if (App::isDebug() && str_starts_with($this->headers['Content-Type'] ?? '', 'text/html')) {
            $ms = round((microtime(true) - START_TIME) * 1000, 1);
            $q = App::db()->queries ?? 0;
            $this->body .= "\n<!-- {$ms} ms, {$q} SQL -->";
        }
        echo $this->body;
    }
}
