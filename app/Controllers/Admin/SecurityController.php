<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\App;
use App\Core\Mailer;
use App\Core\Paginator;
use App\Core\Request;
use App\Core\Response;
use App\Core\Settings;
use App\Core\Str;
use App\Services\SystemStatus;

/**
 * Безопасность (только администратор): журнал входов в админку, текущие блокировки
 * (rate_limits), доступ к админке только с разрешённых IP, рекомендации.
 */
final class SecurityController extends BaseController
{
    protected const MANAGER_ALLOWED = false;

    private const PER_PAGE = 30;

    /** Вкладки журнала: ключ → [подпись, действия admin_log] */
    public const TABS = [
        'all'     => ['Все входы', ['login', 'login_failed']],
        'ok'      => ['Успешные', ['login']],
        'failed'  => ['Неудачные', ['login_failed']],
        'actions' => ['Действия сотрудников', []],
    ];

    /**
     * Счётчики попыток: префикс ключа rate_limits → [что ограничивает, лимит попыток, окно, что в ключе].
     * Лимиты — как в контроллерах (Admin\AuthController, Front\AuthController и др.).
     */
    public const LIMITS = [
        'admin-login:' => ['Вход в админку', 10, 'IP'],
        'login:ip:'    => ['Вход в кабинет', 10, 'IP'],
        'login:u:'     => ['Вход в кабинет', 10, 'логин (зашифрован)'],
        'pwchange:'    => ['Смена пароля в кабинете', 10, 'клиент №'],
        'admin-pw:'    => ['Смена пароля в админке', 10, 'сотрудник №'],
        'signup:'      => ['Регистрация', 10, 'IP'],
        'forgot:ip:'   => ['Восстановление пароля', 5, 'IP'],
        'forgot:e:'    => ['Восстановление пароля', 3, 'e-mail (зашифрован)'],
        'reset:'       => ['Новый пароль по ссылке', 10, 'IP'],
        'order:'       => ['Оформление заказов', 10, 'IP'],
        'coupon:'      => ['Ввод промокодов', 30, 'IP'],
        'review:'      => ['Отзывы о магазине', 3, 'IP'],
        'product_review:' => ['Отзывы о товарах', 5, 'IP'],
        'request:'     => ['Заявки с сайта', 5, 'IP'],
        'mailtest:'    => ['Тестовые письма', 10, 'сотрудник №'],
    ];

    /** Подписи действий журнала админки (admin_log.action) */
    public const ACTIONS = [
        'login' => 'Вход', 'login_failed' => 'Неудачный вход', 'cache_clear' => 'Очистка кэша', 'catalog_reindex' => 'Перестройка индекса каталога',
        'settings_update' => 'Изменение настроек', 'mail_settings' => 'Изменение настроек почты', 'mail_test' => 'Тестовое письмо',
        'security_ips' => 'Изменение списка IP', 'security_unblock' => 'Снятие блокировки', 'seo_refresh' => 'Обновление SEO-проверки',
        'staff_create' => 'Новый сотрудник', 'staff_update' => 'Изменение сотрудника', 'staff_password' => 'Сброс пароля сотрудника',
        'staff_revoke' => 'Сотрудник лишён доступа', 'staff_invite' => 'Приглашение сотруднику', 'account_profile' => 'Изменение своего профиля',
        'account_password' => 'Смена своего пароля', 'account_password_failed' => 'Неверный текущий пароль в «Мой аккаунт»',
        'order_status' => 'Статус заказа', 'order_items' => 'Состав заказа', 'order_edit' => 'Изменение заказа', 'order_create' => 'Заказ по телефону',
        'order_comment' => 'Комментарий к заказу', 'orders_export' => 'Выгрузка заказов', 'reports_export' => 'Выгрузка отчёта',
        'customer_role' => 'Роль клиента', 'customer_password' => 'Пароль клиента', 'customer_edit' => 'Изменение клиента',
        'customer_reset_link' => 'Ссылка восстановления пароля клиенту', 'customer_block' => 'Клиент заблокирован', 'customer_unblock' => 'Клиент разблокирован',
        'product_create' => 'Новый товар', 'product_update' => 'Изменение товара', 'product_delete' => 'Удаление товара', 'products_delete' => 'Удаление товаров',
        'product_images_upload' => 'Загрузка фото товара', 'product_image_delete' => 'Удаление фото товара', 'export_products' => 'Выгрузка товаров',
        'category_create' => 'Новая категория', 'category_update' => 'Изменение категории', 'category_delete' => 'Удаление категории',
        'category_move' => 'Перемещение категории', 'category_parent' => 'Смена родительской категории', 'brand_delete' => 'Удаление бренда',
        'import_upload' => 'Загрузка прайса', 'import_start' => 'Запуск импорта', 'import_restart' => 'Повтор импорта', 'import_delete' => 'Удаление импорта',
        'import_profile_save' => 'Профиль импорта сохранён', 'import_profile_delete' => 'Профиль импорта удалён',
        'coupon_create' => 'Новый промокод', 'coupon_update' => 'Изменение промокода', 'coupon_delete' => 'Удаление промокода',
        'coupons_site_on' => 'Промокоды на сайте включены', 'coupons_site_off' => 'Промокоды на сайте выключены',
        'page_create' => 'Новая страница', 'page_update' => 'Изменение страницы', 'page_delete' => 'Удаление страницы',
        'blog_create' => 'Новая статья', 'blog_update' => 'Изменение статьи', 'blog_delete' => 'Удаление статьи',
        'banner_create' => 'Новый баннер', 'banner_update' => 'Изменение баннера', 'banner_delete' => 'Удаление баннера', 'banner_sort' => 'Порядок баннеров',
        'media_upload' => 'Загрузка изображения', 'media_delete' => 'Удаление изображения', 'upload' => 'Загрузка файла',
        'redirect_save' => 'Редирект сохранён', 'redirect_delete' => 'Редирект удалён', 'redirect_import' => 'Импорт редиректов',
        'feature_delete' => 'Удаление характеристики', 'whatsapp_settings' => 'Изменение настроек WhatsApp', 'whatsapp_test' => 'Тестовое сообщение WhatsApp',
        'feature_create' => 'Новая характеристика', 'feature_update' => 'Изменение характеристики', 'feature_values_add' => 'Значения характеристики добавлены',
        'feature_values_delete' => 'Значения характеристики удалены', 'feature_values_merge' => 'Значения характеристики объединены',
        'feature_values_rename' => 'Значение характеристики переименовано', 'brand_create' => 'Новый бренд', 'brand_update' => 'Изменение бренда',
        'banner_hide' => 'Баннер скрыт/показан', 'products_bulk_addcat' => 'Товары, массово: добавлены в категорию',
        'products_bulk_delcat' => 'Товары, массово: убраны из категории', 'products_bulk_hide' => 'Товары, массово: скрыты',
        'products_bulk_show' => 'Товары, массово: показаны', 'products_bulk_instock' => 'Товары, массово: в наличии',
        'products_bulk_outstock' => 'Товары, массово: нет в наличии', 'products_bulk_price' => 'Товары, массово: цена',
        'coupon_bulk_delete' => 'Промокоды, массово: удалены', 'coupon_bulk_disable' => 'Промокоды, массово: выключены',
        'coupon_bulk_enable' => 'Промокоды, массово: включены', 'request_delete' => 'Заявка удалена', 'request_done' => 'Заявка обработана',
        'request_new' => 'Заявка снова новая', 'review_approve' => 'Отзыв одобрен', 'review_delete' => 'Отзыв удалён', 'review_hide' => 'Отзыв скрыт',
        'review_reply' => 'Ответ на отзыв',
    ];

    /** Подписи для действий с переменной частью (products_bulk_hide, review_approve…) */
    private const ACTION_PREFIXES = [
        'products_bulk_' => 'Товары, массово', 'coupon_bulk_' => 'Промокоды, массово', 'review_' => 'Отзыв', 'request_' => 'Заявка',
        'customer_' => 'Клиент', 'feature_' => 'Характеристика', 'order_' => 'Заказ', 'product_' => 'Товар', 'category_' => 'Категория',
        'import_' => 'Импорт', 'coupon_' => 'Промокод', 'page_' => 'Страница', 'blog_' => 'Статья', 'banner_' => 'Баннер', 'media_' => 'Изображения',
        'redirect_' => 'Редиректы', 'staff_' => 'Сотрудник', 'account_' => 'Мой аккаунт', 'brand_' => 'Бренд', 'whatsapp_' => 'WhatsApp',
    ];

    /** Подписи сущностей журнала (admin_log.entity) */
    public const ENTITIES = [
        'order' => 'заказ', 'customer' => 'клиент', 'staff' => 'сотрудник', 'product' => 'товар', 'category' => 'категория', 'brand' => 'бренд',
        'coupon' => 'промокод', 'page' => 'страница', 'blog_post' => 'статья', 'banner' => 'баннер', 'media' => 'изображение', 'file' => 'файл',
        'redirect' => 'редирект', 'import_job' => 'импорт', 'import_profile' => 'профиль импорта', 'report' => 'отчёт', 'settings' => 'настройки',
        'request' => 'заявка', 'review' => 'отзыв', 'feature' => 'характеристика',
    ];

    /** Подпись действия по словарю, иначе по префиксу («Товары, массово: hide»), иначе код как есть */
    public static function actionLabel(string $action): string
    {
        if (isset(self::ACTIONS[$action])) return self::ACTIONS[$action];
        foreach (self::ACTION_PREFIXES as $prefix => $label) {
            if (str_starts_with($action, $prefix)) return $label . ': ' . str_replace('_', ' ', substr($action, strlen($prefix)));
        }
        return $action;
    }

    /** Ссылка на объект журнала в админке ('' — если своей страницы у объекта нет) */
    public static function entityUrl(?string $entity, $id): string
    {
        $id = (int) $id;
        if (!$id) return '';
        return match ($entity) {
            'order' => '/admin/orders/' . $id . '/', 'customer' => '/admin/customers/' . $id . '/', 'staff' => '/admin/users/' . $id . '/',
            'product' => '/admin/products/' . $id . '/', 'coupon' => '/admin/coupons/' . $id . '/', 'page' => '/admin/pages/' . $id . '/',
            'blog_post' => '/admin/blog/' . $id . '/', 'category' => '/admin/categories/' . $id . '/',
            default => '',
        };
    }

    public function index(): Response
    {
        $db = App::db();
        $tab = Request::get('tab', 'all');
        if (!isset(self::TABS[$tab])) $tab = 'all';
        $q = mb_substr(Request::get('q'), 0, 100);
        $days = Request::getInt('days', 0);
        if (!in_array($days, [0, 1, 7, 30, 90], true)) $days = 0;

        // счётчики вкладок (индекс action_created)
        $byAction = $db->pairs("SELECT action, COUNT(*) FROM admin_log WHERE action IN ('login','login_failed') GROUP BY action");
        $counts = ['all' => (int) array_sum($byAction), 'ok' => (int) ($byAction['login'] ?? 0), 'failed' => (int) ($byAction['login_failed'] ?? 0),
            'actions' => (int) $db->value("SELECT COUNT(*) FROM admin_log WHERE action NOT IN ('login','login_failed')")];

        [$where, $params] = self::logWhere($tab, $q, $days);
        // без поиска и периода число записей вкладки уже посчитано выше — второй полный подсчёт не нужен
        $total = ($q === '' && !$days) ? $counts[$tab]
            : (int) $db->value('SELECT COUNT(*) FROM admin_log a LEFT JOIN customers c ON c.id = a.user_id WHERE ' . $where, $params);
        $pg = new Paginator($total, self::PER_PAGE, Request::page());
        $log = $total ? $db->all('SELECT a.id, a.user_id, a.action, a.entity, a.entity_id, a.details, a.ip, a.created_at, c.name, c.email, c.role
            FROM admin_log a LEFT JOIN customers c ON c.id = a.user_id WHERE ' . $where . ' ORDER BY a.created_at DESC, a.id DESC LIMIT ' . $pg->perPage . ' OFFSET ' . $pg->offset, $params) : [];

        $since = date('Y-m-d H:i:s', time() - 30 * 86400);
        $stats30 = $db->pairs("SELECT action, COUNT(*) FROM admin_log WHERE action IN ('login','login_failed') AND created_at >= ? GROUP BY action", [$since]);
        $blocks = self::blocks();
        $ip = Request::ip();

        return $this->render('admin/security/index', [
            'title' => 'Безопасность', 'tab' => $tab, 'q' => $q, 'days' => $days, 'counts' => $counts, 'log' => $log, 'pg' => $pg, 'total' => $total,
            'tried' => $tab === 'actions' ? [] : self::triedAccounts($log),
            'stats30' => ['ok' => (int) ($stats30['login'] ?? 0), 'failed' => (int) ($stats30['login_failed'] ?? 0)],
            'blocks' => $blocks, 'ip' => $ip,
            'configIps' => array_values(array_filter(array_map('trim', (array) App::config('admin_ips', [])))),
            'settingIps' => Settings::json('admin_ips', []), 'allowed' => self::allowedIps(),
            'tips' => self::tips(), 'styles' => ['admin/system.css'], 'scripts' => ['admin/system.js'],
        ]);
    }

    /** POST /admin/security/unblock/ (k) — снять блокировку (удалить счётчик попыток) */
    public function unblock(): Response
    {
        $k = Request::post('k');
        if ($k === '' || strlen($k) > 100) { $this->flash('Не указана блокировка.', true); return Response::redirect('/admin/security/#blocks'); }
        $n = App::db()->delete('rate_limits', 'k = ?', [$k]);
        if ($n) {
            $this->log('security_unblock', null, null, $k);
            $this->flash('Блокировка снята: ' . self::describeKey($k)['who'] . ' может снова пробовать.');
        } else {
            $this->flash('Блокировка уже снята или истекла.');
        }
        return Response::redirect('/admin/security/#blocks');
    }

    /** POST /admin/security/ips/ (ips — по одному в строке) — список IP, с которых открывается админка */
    public function saveIps(): Response
    {
        $raw = preg_split('/[\s,;]+/', Request::post('ips'), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $ips = []; $bad = [];
        foreach ($raw as $v) {
            $v = trim($v);
            // IPv6 — в каноническом виде (как REMOTE_ADDR): «0:0:0:0:0:0:0:1» → «::1», иначе адрес не совпал бы при проверке
            if (filter_var($v, FILTER_VALIDATE_IP)) $ips[] = (string) (@inet_ntop((string) @inet_pton($v)) ?: $v); else $bad[] = $v;
        }
        $ips = array_values(array_unique($ips));
        if ($bad) {
            $this->flash('Не сохранено: это не IP-адреса — ' . implode(', ', array_map(static fn($b) => mb_substr($b, 0, 45), array_slice($bad, 0, 5))) . '. Укажите адреса целиком, например 93.184.216.34.', true);
            \App\Core\Session::flash('sys_ips_draft', Request::post('ips'));
            return Response::redirect('/admin/security/#ips');
        }
        if (count($ips) > 50) {
            $this->flash('Не больше 50 адресов.', true);
            \App\Core\Session::flash('sys_ips_draft', Request::post('ips'));
            return Response::redirect('/admin/security/#ips');
        }
        $me = Request::ip();
        $merged = array_values(array_unique(array_merge(array_filter(array_map('trim', (array) App::config('admin_ips', []))), $ips)));
        if ($merged && !in_array($me, $merged, true)) {
            $this->flash('Не сохранено: в списке нет вашего текущего IP ' . $me . ' — вы сразу потеряли бы доступ к админке. Добавьте его в список.', true);
            \App\Core\Session::flash('sys_ips_draft', Request::post('ips'));
            return Response::redirect('/admin/security/#ips');
        }
        $old = Settings::json('admin_ips', []);
        if ($old === $ips) { $this->flash('Изменений нет.'); return Response::redirect('/admin/security/#ips'); }
        Settings::set('admin_ips', $ips);
        $this->log('security_ips', 'settings', null, ['from' => $old, 'to' => $ips]);
        $this->flash($ips ? 'Список сохранён: админка открывается только с ' . count($ips) . ' ' . plural(count($ips), 'адреса', 'адресов', 'адресов') . '.' : 'Ограничение по IP снято: админка открывается с любого адреса (по паролю).');
        return Response::redirect('/admin/security/#ips');
    }

    // ------------------------------------------------------------------ помощники

    /** Условие выборки журнала */
    private static function logWhere(string $tab, string $q, int $days): array
    {
        $w = []; $p = [];
        $actions = self::TABS[$tab][1];
        if ($actions) {
            $w[] = 'a.action IN (' . implode(',', array_fill(0, count($actions), '?')) . ')';
            array_push($p, ...$actions);
        } else {
            $w[] = "a.action NOT IN ('login','login_failed')";
        }
        if ($days) { $w[] = 'a.created_at >= ?'; $p[] = date('Y-m-d H:i:s', time() - $days * 86400); }
        if ($q !== '') {
            $like = '%' . addcslashes($q, '%_\\') . '%';
            $w[] = '(a.ip LIKE ? OR a.details LIKE ? OR c.email LIKE ? OR c.name LIKE ?)';
            array_push($p, $like, $like, $like, $like);
        }
        return [implode(' AND ', $w), $p];
    }

    /**
     * Чьи логины вводили в неудачных попытках входа (одним запросом на страницу журнала):
     * [введённый логин в нижнем регистре => ['id', 'name', 'email', 'role', 'off' — доступ закрыт]].
     * В admin_log.user_id неудачной попытки пусто, если пароль не подошёл (Auth::attempt не нашёл запись) —
     * поэтому учётную запись ищем по введённому e-mail, логину или телефону.
     */
    public static function triedAccounts(array $log): array
    {
        $byKey = [];
        foreach ($log as $r) {
            if ($r['action'] !== 'login_failed' || $r['user_id']) continue;
            $d = mb_strtolower(trim((string) $r['details']));
            if ($d === '') continue;
            $byKey[$d] = str_contains($d, '@') ? '' : Str::phone($d);
        }
        if (!$byKey) return [];
        $db = App::db();
        [$ph, $vals] = $db->in(array_keys($byKey));
        $phones = array_values(array_unique(array_filter($byKey)));
        [$pph, $pvals] = $db->in($phones);
        // отключённые тоже ищем: вход отключённого сотрудника всегда «неудачный», это важно видеть
        $rows = $db->all("SELECT id, name, email, login, phone, role, status FROM customers WHERE (email IN ($ph) OR login IN ($ph)"
            . ($phones ? " OR phone IN ($pph)" : '') . ") ORDER BY status DESC, (role <> 'customer') DESC, id LIMIT 200", array_merge($vals, $vals, $phones ? $pvals : []));
        $out = [];
        foreach ($byKey as $k => $phone) {
            foreach ($rows as $c) {
                if ($k === mb_strtolower((string) $c['email']) || $k === mb_strtolower((string) $c['login']) || ($phone !== '' && $phone === (string) $c['phone'])) {
                    $out[$k] = ['id' => (int) $c['id'], 'name' => (string) $c['name'], 'email' => (string) $c['email'], 'role' => (string) $c['role'],
                        'off' => !(int) $c['status']];
                    break;
                }
            }
        }
        return $out;
    }

    /**
     * Строки, которые сотрудник мог ввести в поле «логин» (e-mail, логин, телефон в частых написаниях) —
     * по ним в журнале находятся неудачные попытки входа под его учётной записью.
     */
    public static function loginKeys(array $u): array
    {
        $keys = [(string) ($u['email'] ?? ''), (string) ($u['login'] ?? '')];
        $p = (string) ($u['phone'] ?? '');
        if ($p !== '') array_push($keys, $p, '+' . $p, str_starts_with($p, '38') ? substr($p, 2) : $p);
        return array_values(array_unique(array_filter($keys, static fn($k) => $k !== '')));
    }

    /** Последние входы и неудачные попытки под учётной записью (по индексам user_created и action_created) */
    public static function loginEvents(array $u, int $limit = 12): array
    {
        $db = App::db();
        $uid = (int) $u['id'];
        $keys = self::loginKeys($u);
        $sql = "(SELECT id, action, details, ip, created_at FROM admin_log WHERE user_id = ? AND action IN ('login','login_failed')
            ORDER BY created_at DESC, id DESC LIMIT $limit)";
        $params = [$uid];
        if ($keys) {
            [$ph, $vals] = $db->in($keys);
            $sql .= " UNION (SELECT id, action, details, ip, created_at FROM admin_log WHERE action = 'login_failed' AND user_id IS NULL AND details IN ($ph)
                ORDER BY created_at DESC, id DESC LIMIT $limit)";
            $params = array_merge($params, $vals);
        }
        return $db->all($sql . " ORDER BY created_at DESC, id DESC LIMIT $limit", $params);
    }

    /** Неудачных попыток входа под учётной записью с даты $since */
    public static function failedSince(array $u, string $since): int
    {
        $db = App::db();
        $keys = self::loginKeys($u);
        [$ph, $vals] = $db->in($keys);
        return (int) $db->value("SELECT COUNT(*) FROM admin_log WHERE action = 'login_failed' AND created_at >= ?
            AND (user_id = ?" . ($keys ? " OR (user_id IS NULL AND details IN ($ph))" : '') . ')', array_merge([$since, (int) $u['id']], $keys ? $vals : []));
    }

    /** Действующие блокировки: счётчики, у которых исчерпан лимит и окно ещё не закончилось */
    public static function blocks(): array
    {
        $rows = App::db()->all('SELECT k, hits, reset_at FROM rate_limits WHERE reset_at > ? ORDER BY reset_at DESC LIMIT 500', [time()]);
        $out = [];
        foreach ($rows as $r) {
            $d = self::describeKey((string) $r['k']);
            if (!$d['max'] || (int) $r['hits'] < $d['max']) continue;
            $out[] = $d + ['k' => (string) $r['k'], 'hits' => (int) $r['hits'], 'reset_at' => (int) $r['reset_at']];
        }
        return $out;
    }

    /** Разбор ключа rate_limits: что ограничено и для кого */
    public static function describeKey(string $k): array
    {
        foreach (self::LIMITS as $prefix => [$what, $max, $kind]) {
            if (str_starts_with($k, $prefix)) {
                $val = substr($k, strlen($prefix));
                $who = str_contains($kind, 'зашифрован') ? $kind . ' ' . substr($val, 0, 8) . '…' : ($kind === 'IP' ? 'IP ' . $val : $kind . $val);
                return ['what' => $what, 'max' => $max, 'who' => $who, 'ipv' => $kind === 'IP' ? $val : '', 'admin' => $prefix === 'admin-login:'];
            }
        }
        return ['what' => 'Другое', 'max' => 0, 'who' => $k, 'ipv' => '', 'admin' => false];
    }

    /** Рекомендации: [состояние ok|warn|bad, текст, пояснение] */
    public static function tips(): array
    {
        $db = App::db();
        $local = SystemStatus::isLocalBase();
        $t = [];
        $debug = (bool) App::config('debug', false);
        $t[] = [$debug ? ($local ? 'warn' : 'bad') : 'ok', $debug ? 'Режим отладки включён' : 'Режим отладки выключен',
            $debug ? 'На хостинге в config/config.php должно быть debug => false — иначе посетители видят пути и тексты ошибок.' : 'Посетители не видят технических подробностей ошибок.'];
        $key = (string) App::config('app_key', '');
        $keyBad = $key === '' || $key === 'CHANGE_ME' || strlen($key) < 32;
        $t[] = [$keyBad ? 'bad' : 'ok', $keyBad ? 'Не задан секретный ключ app_key' : 'Секретный ключ app_key задан',
            $keyBad ? 'Сгенерируйте 64 символа: php -r "echo bin2hex(random_bytes(32));"' : 'Подписывает ссылки на заказы из писем покупателям — чужой заказ по номеру не открыть.'];
        $https = str_starts_with((string) App::config('base_url', ''), 'https://');
        $t[] = [$https ? 'ok' : ($local ? 'warn' : 'bad'), $https ? 'Сайт работает по HTTPS' : 'Адрес сайта без HTTPS',
            $https ? 'Пароль при входе передаётся в зашифрованном виде.' : 'Подключите SSL-сертификат (обычно бесплатный Let’s Encrypt в панели хостинга) и укажите base_url с https://.'];
        $cfgW = is_writable(ROOT . '/config/config.php');
        $t[] = [$cfgW ? 'warn' : 'ok', $cfgW ? 'config/config.php доступен на запись' : 'config/config.php только для чтения',
            $cfgW ? 'Поставьте права 0440 или 0640 — файл с паролями не должен меняться веб-сервером.' : 'Файл с паролями защищён от изменения.'];

        $staff = $db->row("SELECT SUM(role = 'admin' AND status = 1) admins,
            SUM(status = 1 AND (last_login_at IS NULL OR last_login_at < ?)) stale,
            SUM(status = 1 AND password LIKE 'wa:%') legacy FROM customers WHERE role IN ('admin','manager')",
            [date('Y-m-d H:i:s', time() - 180 * 86400)]) ?: [];
        $admins = (int) ($staff['admins'] ?? 0);
        $t[] = [$admins > 5 ? 'warn' : 'ok', 'Администраторов: ' . $admins,
            $admins > 5 ? 'Полный доступ стоит оставить 1–3 людям, остальным — роль «менеджер».' : 'Полный доступ у небольшого числа людей.'];
        $stale = (int) ($staff['stale'] ?? 0);
        if ($stale) $t[] = ['warn', 'Не входили больше полугода: ' . $stale, 'Отключите учётные записи, которыми не пользуются, в разделе «Сотрудники».'];
        $legacy = (int) ($staff['legacy'] ?? 0);
        if ($legacy) $t[] = ['warn', 'Пароль старого формата (md5) у ' . $legacy . ' ' . plural($legacy, 'сотрудника', 'сотрудников', 'сотрудников'),
            'Перенесён из Webasyst. Обновится при следующем входе; надёжнее задать новый пароль.'];
        $smtp = (string) Mailer::cfg('smtp_host') !== '';
        $t[] = [$smtp ? 'ok' : 'warn', $smtp ? 'Почта отправляется через SMTP' : 'Почта через mail() хостинга',
            $smtp ? 'Письма подписаны почтовым сервером и реже попадают в спам.' : 'Настройте SMTP в разделе «Почта» — письма о заказах надёжнее доходят.'];
        return $t;
    }
}
