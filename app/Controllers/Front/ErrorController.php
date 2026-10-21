<?php
declare(strict_types=1);

namespace App\Controllers\Front;

use App\Core\App;
use App\Core\Request;
use App\Core\Response;
use App\Core\Seo;
use App\Core\View;

final class ErrorController
{
    /**
     * 404. Перед ответом проверяется таблица redirects (301 со старых адресов),
     * а совпадения /pages/… и т.п. обрабатывает PageController.
     */
    public static function notFound(): Response
    {
        return self::redirect(Request::path()) ?? self::page();
    }

    /** Редирект из таблицы redirects для пути (без /ua — Response::send добавит префикс к Location), иначе null */
    public static function redirect(string $path): ?Response
    {
        try {
            $r = App::db()->row('SELECT id, to_url, code FROM redirects WHERE from_url = ?', [$path]);
            if ($r) {
                App::db()->query('UPDATE redirects SET hits = hits + 1 WHERE id = ?', [$r['id']]);
                return Response::redirect($r['to_url'], (int) $r['code'] ?: 301);
            }
        } catch (\Throwable $e) {
            // база недоступна — просто 404
        }
        return null;
    }

    /** Страница 404 (без проверки редиректов) */
    public static function page(): Response
    {
        $seo = Seo::make(t('Страница не найдена'));
        $seo->robots = 'noindex, follow';
        return Response::html(View::render('errors/404', ['seo' => $seo]), 404);
    }
}
