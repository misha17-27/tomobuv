<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\App;
use App\Core\Auth;
use App\Core\Mailer;
use App\Core\RateLimit;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Settings;
use App\Core\View;

/**
 * Почта (SMTP): отправитель, адрес уведомлений, SMTP-сервер, тестовое письмо, список писем сайта.
 * Значения — в settings (mail.*); App\Core\Mailer берёт их, иначе — из config/config.php.
 * Смотреть могут все сотрудники, менять и отправлять тест — только администратор.
 * Пароль SMTP никогда не выводится: пустое поле — «не менять».
 */
final class MailController extends BaseController
{
    public const SECURE = ['ssl' => 'SSL/TLS — порт 465', 'tls' => 'STARTTLS — порт 587', 'none' => 'Без шифрования — порт 25'];
    private const DEFAULT_PORT = ['ssl' => 465, 'tls' => 587, 'none' => 25];

    /** Готовые настройки популярных почтовых сервисов (кнопки над формой, подставляет JS): ключ → [название, сервер, порт, шифрование, где взять пароль] */
    public static function presets(): array
    {
        $host = (string) parse_url((string) App::config('base_url', ''), PHP_URL_HOST);
        if ($host === '' || \App\Services\SystemStatus::isLocalBase()) $host = 'tomobuv.com.ua';
        return [
            'gmail'   => ['Gmail', 'smtp.gmail.com', 465, 'ssl', 'Нужен «пароль приложения»: Аккаунт Google → Безопасность → Двухэтапная аутентификация → Пароли приложений. Обычный пароль Gmail не подойдёт.'],
            'ukrnet'  => ['Ukr.net', 'smtp.ukr.net', 465, 'ssl', 'В настройках ящика Ukr.net включите доступ по SMTP/IMAP и создайте пароль для внешних программ.'],
            'hosting' => ['Почта хостинга', 'mail.' . preg_replace('/^www\./', '', $host), 465, 'ssl', 'cPanel → Email Accounts → Connect Devices: там указаны сервер, порт и шифрование. Логин — полный адрес ящика, пароль — от этого ящика.'],
        ];
    }

    public function index(): Response
    {
        if (Request::isPost()) return $this->save();
        return $this->page();
    }

    private function page(array $errors = [], array $form = [], int $status = 200): Response
    {
        $v = self::stored();
        $v['smtp_pass'] = $v['smtp_pass'] !== '' ? '1' : '';   // в шаблон — только «сохранён ли», сам пароль не передаём
        $isAdmin = Auth::isAdmin();
        $cfg = [];
        foreach (['from', 'from_name', 'admin_to', 'smtp_host', 'smtp_port', 'smtp_user', 'smtp_secure'] as $k) $cfg[$k] = (string) App::config('mail.' . $k, '');
        $cfg['smtp_pass'] = (string) App::config('mail.smtp_pass', '') !== '';

        $host = (string) Mailer::cfg('smtp_host');
        $eff = [
            'from' => (string) Mailer::cfg('from', ''), 'from_name' => (string) Mailer::cfg('from_name', 'Tomobuv'),
            'admin' => Mailer::adminEmail(), 'adminFrom' => self::adminSource(), 'host' => $host,
            'port' => (int) Mailer::cfg('smtp_port', 465), 'secure' => (string) Mailer::cfg('smtp_secure', 'ssl'),
            'user' => (string) Mailer::cfg('smtp_user', ''), 'pass' => (string) Mailer::cfg('smtp_pass', '') !== '',
        ];

        $me = Auth::user();
        $r = $this->render('admin/mail/index', [
            'title' => 'Почта (SMTP)', 'v' => $form + $v, 'cfg' => $cfg, 'eff' => $eff, 'isAdmin' => $isAdmin, 'errors' => $errors,
            'storeEmail' => (string) Settings::get('store_email', ''),
            'testTo' => (string) (Session::flash('sys_mail_to') ?? ($eff['admin'] ?: ($me['email'] ?? ''))),
            'notices' => self::notices($eff['admin']), 'whatsapp' => \App\Services\WhatsApp::enabled(), 'log' => self::logTail(),
            'dev' => App::config('env') === 'dev' && !App::config('mail.dev_send', false),
            'devFiles' => self::devFiles(), 'lastTest' => self::lastTest(), 'presets' => self::presets(),
            'styles' => ['admin/system.css'], 'scripts' => ['admin/system.js'],
        ]);
        $r->status = $status;
        return $r;
    }

    /** POST /admin/mail/ (act = save | test) */
    private function save(): Response
    {
        if (!Auth::isAdmin()) {
            $this->flash('Настройки почты меняет только администратор.', true);
            return Response::redirect('/admin/mail/');
        }
        $old = self::stored();
        $in = [
            'from'        => mb_strtolower(trim(Request::post('from'))),
            'from_name'   => trim(str_replace(["\r", "\n"], ' ', mb_substr(Request::post('from_name'), 0, 100))),
            'admin_to'    => mb_strtolower(trim(Request::post('admin_to'))),
            'smtp_host'   => mb_strtolower(trim((string) preg_replace('#^(ssl|tls|smtps?)://#i', '', Request::post('smtp_host')))),
            'smtp_port'   => trim(Request::post('smtp_port')),
            'smtp_secure' => Request::post('smtp_secure'),
            'smtp_user'   => trim(Request::post('smtp_user')),
        ];
        $errors = [];
        if ($in['from'] !== '' && !filter_var($in['from'], FILTER_VALIDATE_EMAIL)) $errors['from'] = 'Неверный e-mail отправителя.';
        if ($in['admin_to'] !== '' && !filter_var($in['admin_to'], FILTER_VALIDATE_EMAIL)) $errors['admin_to'] = 'Один правильный e-mail (например, tomobuv@gmail.com).';
        if ($in['smtp_host'] !== '' && (strlen($in['smtp_host']) > 190 || !preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)*$/', $in['smtp_host']))) {
            $errors['smtp_host'] = 'Имя сервера, например smtp.gmail.com — без http:// и пробелов.';
        }
        if ($in['smtp_port'] !== '' && (!ctype_digit($in['smtp_port']) || (int) $in['smtp_port'] < 1 || (int) $in['smtp_port'] > 65535)) $errors['smtp_port'] = 'Порт — число от 1 до 65535 (обычно 465 или 587).';
        if ($in['smtp_secure'] !== '' && !isset(self::SECURE[$in['smtp_secure']])) $errors['smtp_secure'] = 'Выберите шифрование.';
        if (mb_strlen($in['smtp_user']) > 190 || preg_match('/[\r\n]/', $in['smtp_user'])) $errors['smtp_user'] = 'Слишком длинный логин.';
        $pass = UsersController::rawPost('smtp_pass');
        if ($pass !== '' && (strlen($pass) > 200 || preg_match('/[\r\n]/', $pass))) $errors['smtp_pass'] = 'Пароль без переносов строк, до 200 символов.';
        if ($in['smtp_host'] !== '' && $in['smtp_port'] === '' && $in['smtp_secure'] !== '') $in['smtp_port'] = (string) self::DEFAULT_PORT[$in['smtp_secure']];
        if ($errors) {
            unset($in['smtp_pass']);
            return $this->page($errors, $in, 422);
        }

        $new = $in;
        if ($pass !== '') $new['smtp_pass'] = $pass;
        elseif (Request::post('smtp_pass_clear') === '1') $new['smtp_pass'] = '';
        $changed = [];
        foreach ($new as $k => $val) {
            if ((string) ($old[$k] ?? '') === (string) $val) continue;
            Settings::set('mail.' . $k, (string) $val);
            $changed[] = $k;
        }
        // тот же адрес показывает «Настройки → Почта» (ключ notify_email) — держим оба ключа одинаковыми
        if (in_array('admin_to', $changed, true) && (string) (Settings::all()['notify_email'] ?? '') !== $new['admin_to']) {
            Settings::set('notify_email', $new['admin_to']);
        }
        if ($changed) $this->log('mail_settings', 'settings', null, ['fields' => $changed]);   // значения (и пароль) в журнал не пишем

        if (Request::post('act') === 'test') return $this->test($changed);
        $this->flash($changed ? 'Настройки почты сохранены. Отправьте тестовое письмо, чтобы проверить.' : 'Изменений нет.');
        return Response::redirect('/admin/mail/');
    }

    /** Тестовое письмо на введённый адрес (после сохранения настроек) */
    private function test(array $changed): Response
    {
        $to = mb_strtolower(trim(Request::post('test_to')));
        if ($to === '') $to = Mailer::adminEmail() ?: (string) (Auth::user()['email'] ?? '');
        Session::flash('sys_mail_to', $to);
        $saved = $changed ? 'Настройки сохранены. ' : '';
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $this->flash($saved . 'Тест не отправлен: укажите правильный адрес получателя.', true);
            return Response::redirect('/admin/mail/#test');
        }
        if (!RateLimit::hit('mailtest:' . Auth::id(), 10, 3600)) {
            $this->flash($saved . 'Слишком много тестовых писем — подождите час или снимите блокировку в «Безопасности».', true);
            return Response::redirect('/admin/mail/#test');
        }
        if (function_exists('set_time_limit')) @set_time_limit(60);   // бывает в disable_functions хостинга
        $host = (string) Mailer::cfg('smtp_host');
        $via = $host !== '' ? 'SMTP ' . $host . ':' . (int) Mailer::cfg('smtp_port', 465) : 'mail() хостинга';
        $store = (string) Settings::get('store_name', 'Tomobuv');
        $html = View::render('emails/layout', [
            'title' => 'Проверка почты — ' . $store, 'preheader' => 'Тестовое письмо из админки',
            'content' => View::render('admin/mail/test-email', ['via' => $via, 'who' => (string) (Auth::user()['name'] ?? ''),
                'from' => (string) Mailer::cfg('from', ''), 'admin' => Mailer::adminEmail(), 'at' => date('d.m.Y H:i')], null),
        ], null);
        $t = microtime(true);
        $ok = Mailer::send($to, 'Проверка почты — ' . $store, $html);
        $sec = round(microtime(true) - $t, 1);
        $error = $ok ? '' : (Mailer::$lastError ?: 'неизвестная ошибка');
        $this->log('mail_test', null, null, ['to' => $to, 'via' => $via, 'ok' => $ok, 'sec' => $sec] + ($ok ? [] : ['error' => mb_substr($error, 0, 300)]));
        if ($ok) {
            $this->flash($saved . 'Тестовое письмо отправлено на ' . $to . ' через ' . $via . ' (' . str_replace('.', ',', (string) $sec) . ' с). Проверьте «Входящие» и папку «Спам».');
        } else {
            $this->flash($saved . 'Письмо не отправлено (' . $via . '): ' . rtrim($error, " .\r\n") . '.' . self::advice($error, $host), true);
        }
        return Response::redirect('/admin/mail/#test');
    }

    // ------------------------------------------------------------------ помощники

    /** Сохранённые в админке значения (без подстановки из config.php) */
    private static function stored(): array
    {
        $all = Settings::all();
        $out = [];
        foreach (['from', 'from_name', 'admin_to', 'smtp_host', 'smtp_port', 'smtp_user', 'smtp_pass', 'smtp_secure'] as $k) $out[$k] = (string) ($all['mail.' . $k] ?? '');
        return $out;
    }

    /** Подсказка по частым ошибкам SMTP */
    private static function advice(string $e, string $host): string
    {
        $l = mb_strtolower($e);
        if ($host === '') return ' Настройте SMTP ниже — функция mail() на многих хостингах отключена или письма уходят в спам.';
        if (str_contains($l, '535') || str_contains($l, 'auth') || str_contains($l, 'username') || str_contains($l, 'password')) return ' Сервер не принял логин или пароль. Для Gmail нужен «пароль приложения», не обычный пароль.';
        if (str_contains($l, 'подключиться') || str_contains($l, 'timed out') || str_contains($l, 'refused')) return ' Проверьте сервер, порт и шифрование (465 — SSL, 587 — STARTTLS). Некоторые хостинги закрывают исходящие порты — спросите поддержку.';
        if (str_contains($l, '553') || str_contains($l, '550') || str_contains($l, 'sender') || str_contains($l, 'from')) return ' Сервер не разрешает этот адрес отправителя — укажите в «От кого» адрес того же ящика, что и логин SMTP.';
        return '';
    }

    /**
     * Откуда взят адрес уведомлений (так же, как в Mailer::adminEmail()):
     * форма этой страницы (settings mail.admin_to) → config.php (mail.admin_to) → e-mail магазина (settings store_email).
     */
    private static function adminSource(): string
    {
        if (trim((string) (Settings::all()['mail.admin_to'] ?? '')) !== '') return 'из этой формы';
        if (trim((string) App::config('mail.admin_to', '')) !== '') return 'из config.php (запасной вариант)';
        if (trim((string) Settings::get('store_email', '')) !== '') return 'e-mail магазина из «Настроек»';
        return '';
    }

    /**
     * Какие письма отправляет сайт: [название, кому, когда, адрес, адрес не задан].
     * Все письма администратору (заказы, «Купить в 1 клик», заявки, отзывы) идут на Mailer::adminEmail().
     */
    private static function notices(string $admin): array
    {
        $none = $admin === '';
        $to = $none ? 'не задан — письмо не отправляется' : $admin;
        return [
            ['Новый заказ', 'Администратору', 'Сразу после оформления заказа: корзина и «Купить в 1 клик» (состав в ящиках и парах, сумма, доставка)', $to, $none],
            ['Заказ оформлен', 'Покупателю', 'Если покупатель указал e-mail: номер, состав, сумма, доставка — на языке заказа (RU/UA)', 'e-mail из заказа', false],
            ['Статус заказа изменён', 'Покупателю', 'Когда в заказе меняют статус с галочкой «Уведомить клиента»', 'e-mail из заказа', false],
            ['Заявка с сайта', 'Администратору', 'Обратный звонок, сообщение со страницы «Контакты», подписка на рассылку', $to, $none],
            ['Отзыв о магазине', 'Администратору', 'Новый отзыв ждёт модерации', $to, $none],
            ['Отзыв о товаре', 'Администратору', 'Новый отзыв о товаре ждёт модерации', $to, $none],
            ['Восстановление пароля', 'Покупателю или сотруднику', 'По запросу на странице «Забыли пароль?» — ссылка на 1 час', 'e-mail учётной записи', false],
            ['Пароль или ссылка клиенту', 'Клиенту', 'В карточке клиента: «Сбросить пароль» или «Ссылка для смены пароля» с галочкой «Отправить»', 'e-mail клиента', false],
            ['Приглашение сотруднику', 'Сотруднику', 'Раздел «Сотрудники»: ссылка «задать пароль» на 72 часа', 'e-mail сотрудника', false],
        ];
    }

    /** Последние строки журнала писем (ссылки восстановления пароля скрыты) */
    private static function logTail(int $n = 8): array
    {
        $files = glob(STORAGE . '/logs/mail-*.log') ?: [];
        if (!$files) return [];
        rsort($files);
        $lines = [];
        foreach (array_slice($files, 0, 2) as $f) {
            $size = (int) @filesize($f);
            $fp = @fopen($f, 'rb');
            if (!$fp) continue;
            if ($size > 32768) fseek($fp, -32768, SEEK_END);
            $chunk = (string) stream_get_contents($fp);
            fclose($fp);
            $part = array_values(array_filter(explode("\n", $chunk), static fn($l) => str_starts_with($l, '[')));
            $lines = array_merge($lines, array_reverse($part));
            if (count($lines) >= $n) break;
        }
        $out = [];
        foreach (array_slice($lines, 0, $n) as $l) {
            $l = (string) preg_replace('/([?&]t=)[a-f0-9]{16,}/i', '$1…', $l);
            $l = (string) preg_replace('/\s\|\s\S*$/', '', $l);            // хвост «| /адрес»
            $out[] = mb_substr($l, 0, 300);
        }
        return $out;
    }

    /** Письма, сохранённые вместо отправки при локальной разработке */
    private static function devFiles(): int
    {
        return count(glob(STORAGE . '/logs/mail/*.html') ?: []);
    }

    /** Последняя проверка почты из журнала админки */
    private static function lastTest(): ?array
    {
        $r = App::db()->row("SELECT a.details, a.created_at, c.name FROM admin_log a LEFT JOIN customers c ON c.id = a.user_id
            WHERE a.action = 'mail_test' ORDER BY a.created_at DESC, a.id DESC LIMIT 1");
        if (!$r) return null;
        $d = json_decode((string) $r['details'], true) ?: [];
        return ['ok' => !empty($d['ok']), 'to' => (string) ($d['to'] ?? ''), 'via' => (string) ($d['via'] ?? ''), 'error' => (string) ($d['error'] ?? ''),
            'at' => (string) $r['created_at'], 'who' => (string) ($r['name'] ?? '')];
    }
}
