<?php
declare(strict_types=1);

namespace App\Controllers\Front;

use App\Core\App;
use App\Core\Csrf;
use App\Core\Lang;
use App\Core\Log;
use App\Core\Mailer;
use App\Core\RateLimit;
use App\Core\Request;
use App\Core\Response;
use App\Core\Str;
use App\Core\View;

/**
 * Заявки с сайта: POST /request/{callback|subscribe|contact}/ (формы с data-request, app.js → bindRequests).
 * Ответ JSON: {ok:true, message} или {ok:false, error, field?}. Запись в requests + письмо администратору.
 */
final class RequestController
{
    /** Названия заявок для письма администратору (админка — только на русском) */
    public const TYPES = [
        'callback'  => 'Обратный звонок',
        'subscribe' => 'Подписка на рассылку',
        'contact'   => 'Сообщение с сайта',
    ];

    public function store(string $type): Response
    {
        self::langFromReferer();
        if (!isset(self::TYPES[$type])) {
            return Request::isAjax() ? Response::json(['ok' => false, 'error' => t('Неизвестный тип заявки')], 404) : Response::notFound();
        }
        if (!Csrf::check()) {
            return self::fail(t('Страница устарела. Обновите её и отправьте форму ещё раз.'), null, 419);
        }
        $ok = self::message($type);
        if (Request::post('website') !== '') return Response::json(['ok' => true, 'message' => $ok]);   // honeypot — бот

        $name = mb_substr(Request::post('name'), 0, 190);
        $phoneRaw = mb_substr(Request::post('phone'), 0, 32);
        $emailRaw = mb_substr(Request::post('email'), 0, 190);
        $text = mb_substr(Request::post('text') ?: Request::post('message'), 0, 5000);
        // телефон: украинский → 380XXXXXXXXX; иностранный (10+ цифр) — как ввели, без мусора
        $phone = $phoneRaw !== '' ? (Str::phone($phoneRaw) ?: (strlen((string) preg_replace('/\D/', '', $phoneRaw)) >= 10 ? (string) preg_replace('/[^\d+]/', '', $phoneRaw) : '')) : '';
        $email = $emailRaw !== '' ? Str::email($emailRaw) : '';

        // проверка полей
        if ($phoneRaw !== '' && $phone === '') return self::fail(t('Проверьте номер телефона'), 'phone');
        if ($emailRaw !== '' && $email === '') return self::fail(t('Проверьте e-mail'), 'email');
        switch ($type) {
            case 'callback':
                if ($phone === '') return self::fail(t('Укажите номер телефона'), 'phone');
                break;
            case 'subscribe':
                if ($email === '') return self::fail(t('Укажите e-mail'), 'email');
                break;
            case 'contact':
                if ($phone === '' && $email === '') return self::fail(t('Укажите телефон или e-mail для ответа'), 'phone');
                if (mb_strlen(trim($text)) < 3) return self::fail(t('Напишите сообщение'), 'text');
                break;
        }

        if (!RateLimit::hit('request:' . Request::ip(), 5, 600)) {
            return self::fail(t('Слишком много заявок. Попробуйте через 10 минут или позвоните нам.'), null, 429);
        }

        $db = App::db();
        if ($type === 'subscribe' && $db->value("SELECT id FROM requests WHERE type = 'subscribe' AND email = ? LIMIT 1", [$email])) {
            return Response::json(['ok' => true, 'message' => t('Вы уже подписаны на рассылку. Спасибо!')]);
        }
        $productId = Request::postInt('product_id');
        if ($productId > 0 && !$db->value('SELECT id FROM products WHERE id = ?', [$productId])) $productId = 0;
        $id = $db->insert('requests', [
            'type' => $type, 'name' => $name !== '' ? $name : null, 'phone' => $phone !== '' ? $phone : null,
            'email' => $email !== '' ? $email : null, 'text' => trim($text) !== '' ? trim($text) : null,
            'product_id' => $productId > 0 ? $productId : null, 'status' => 'new', 'ip' => Request::ip(),
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        self::notifyAdmin(self::TYPES[$type] . ' №' . $id, [
            ['Имя', $name], ['Телефон', $phone !== '' ? AccountController::phoneView($phone) : ''], ['E-mail', $email],
            ['Сообщение', trim($text)], ['Товар', $productId > 0 ? '#' . $productId : ''],
            ['Язык', Lang::isUk() ? 'украинский' : ''], ['Дата', date('d.m.Y H:i')], ['IP', Request::ip()],
        ], '/admin/requests/');
        \App\Services\WhatsApp::notifyRequestLater($type, (int) $id, self::TYPES[$type] . ' №' . $id, [
            ['Имя', $name], ['Телефон', $phone !== '' ? AccountController::phoneView($phone) : ''], ['E-mail', $email],
            ['Сообщение', trim($text)], ['Товар', $productId > 0 ? url('/admin/products/' . $productId . '/') : ''],
        ]);

        return Response::json(['ok' => true, 'message' => $ok]);
    }

    private static function message(string $type): string
    {
        return match ($type) {
            'callback'  => t('Спасибо! Менеджер перезвонит вам в рабочее время.'),
            'subscribe' => t('Спасибо! Вы подписаны на рассылку.'),
            default     => t('Спасибо! Сообщение отправлено, мы ответим в ближайшее время.'),
        };
    }

    /**
     * Формы заявок стоят на всех страницах, app.js отправляет их на /request/… без префикса /ua.
     * Если форма отправлена с украинской страницы — отвечаем по-украински.
     */
    private static function langFromReferer(): void
    {
        if (Lang::isUk()) return;
        $ref = (string) ($_SERVER['HTTP_REFERER'] ?? '');
        $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
        if ($ref === '' || $host === '' || strcasecmp((string) parse_url($ref, PHP_URL_HOST) . (($p = parse_url($ref, PHP_URL_PORT)) ? ':' . $p : ''), $host) !== 0) return;
        $path = (string) parse_url($ref, PHP_URL_PATH);
        if ($path === '/ua' || str_starts_with($path, '/ua/')) Lang::set('uk');
    }

    private static function fail(string $error, ?string $field = null, int $status = 422): Response
    {
        return Response::json(['ok' => false, 'error' => $error] + ($field ? ['field' => $field] : []), $status);
    }

    /** Письмо администратору (адрес — Mailer::adminEmail(): настройки почты, иначе e-mail магазина). Ошибки только в лог. */
    public static function notifyAdmin(string $title, array $fields, string $adminPath): void
    {
        $to = Mailer::adminEmail();
        if ($to === '') return;
        $was = Lang::current();
        Lang::set(Lang::DEFAULT);                    // письмо администратору — всегда по-русски
        try {
            $subject = $title . ' — Tomobuv';
            $html = View::render('emails/layout', [
                'title' => $subject,
                'content' => View::render('emails/request-admin', [
                    'typeName' => $title, 'fields' => $fields, 'adminUrl' => url($adminPath),
                    'page' => mb_substr((string) ($_SERVER['HTTP_REFERER'] ?? ''), 0, 300),
                ], null),
            ], null);
        } catch (\Throwable $e) {
            Log::error('notifyAdmin: ' . $e->getMessage());
            return;
        } finally {
            Lang::set($was);
        }
        self::deferMail($to, $subject, $html, 'request-admin', 'письмо администратору: ' . $title);
    }

    /**
     * Отправить письмо после ответа посетителю: на PHP-FPM/LiteSpeed соединение закрывается раньше,
     * посетитель не ждёт SMTP (и по времени ответа нельзя понять, отправлялось ли письмо).
     * Локальная разработка (env=dev) — как у писем заказов: письмо сохраняется в storage/logs/mail/
     * вместо отправки (включить отправку: mail.dev_send = true).
     * @param string $kind короткое имя для файла письма в dev-режиме (password-reset-15, request-admin…)
     */
    public static function deferMail(string $to, string $subject, string $html, string $kind, string $what): void
    {
        if (App::config('env') === 'dev' && !App::config('mail.dev_send', false)) {
            $dir = STORAGE . '/logs/mail';
            @mkdir($dir, 0775, true);
            $file = date('Ymd-His') . '-' . preg_replace('/[^a-z0-9-]+/i', '-', $kind) . '-' . substr(bin2hex(random_bytes(3)), 0, 6) . '.html';
            @file_put_contents($dir . '/' . $file, "<!-- To: $to | Subject: " . str_replace('--', '—', $subject) . " -->\n" . $html);
            Log::write('mail', 'DEV: ' . $what . ' сохранено в storage/logs/mail/' . $file . ' (адресат ' . $to . ')');
            return;
        }
        register_shutdown_function(static function () use ($to, $subject, $html, $what): void {
            if (function_exists('fastcgi_finish_request')) @fastcgi_finish_request();
            elseif (function_exists('litespeed_finish_request')) @litespeed_finish_request();
            try {
                if (!Mailer::send($to, $subject, $html)) Log::write('mail', 'Не удалось отправить ' . $what . ': ' . Mailer::$lastError);
            } catch (\Throwable $e) {
                Log::error('Mail (' . $what . '): ' . $e->getMessage());
            }
        });
    }
}
