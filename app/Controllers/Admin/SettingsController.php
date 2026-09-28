<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\App;
use App\Core\Auth;
use App\Core\Cache;
use App\Core\Mailer;
use App\Core\Request;
use App\Core\Response;
use App\Core\Settings;
use App\Services\Catalog;

/**
 * Настройки сайта (таблица settings) по вкладкам. Сохранять может только администратор,
 * менеджер видит значения без возможности изменить. Сохраняются только изменённые ключи
 * (Settings::set сам сбрасывает кэш сайта).
 *
 * Украинская версия: тексты магазина и SEO-шаблоны имеют вариант «<ключ>.uk» (Settings::get на /ua/ берёт его сам,
 * пусто — русский). Способы доставки/оплаты хранят name_uk/description_uk в элементах JSON — их читает
 * App\Services\Orders (на /ua/ показывает name_uk, а в заказ пишет русское name). Копий «shipping_methods.uk»
 * не делаем: Settings::get на /ua/ подменил бы весь список, и в заказы попадали бы украинские названия.
 *
 * WhatsApp и параметры отправки писем (SMTP) — отдельные экраны /admin/whatsapp/ и /admin/mail/ (здесь только ссылки).
 */
final class SettingsController extends BaseController
{
    public const TABS = [
        'store'      => 'Магазин',
        'seo'        => 'SEO-шаблоны',
        'shipping'   => 'Доставка и оплата',
        'currencies' => 'Валюты',
        'mail'       => 'Почта',
    ];

    /** Поля вкладки «Магазин»: ключ → [подпись, тип, подсказка] */
    public const STORE_FIELDS = [
        'store_name'          => ['Название магазина', 'text', 'Подставляется в SEO-шаблоны как {$store_info.name}'],
        'site_title'          => ['Заголовок сайта (title по умолчанию)', 'text', 'Используется, если у страницы нет своего title'],
        'store_phone'         => ['Телефон для SEO-шаблонов', 'text', 'Подставляется как {$store_info.phone}'],
        'store_email'         => ['E-mail магазина', 'email', 'Показывается в подвале сайта'],
        'address'             => ['Адрес', 'text', 'Подвал, страница контактов; последние части адреса — место самовывоза'],
        'work_hours'          => ['Время работы', 'text', 'Например: Пн–Вс · 06:00—18:00'],
        'since_year'          => ['Работаем с года', 'number', 'Показывается на главной и в подвале'],
        'free_shipping_boxes' => ['Бесплатная доставка от, ящиков', 'number', '0 — бесплатной доставки нет'],
        'products_per_page'   => ['Товаров на странице каталога', 'number', 'От 8 до 96, лучше кратно 4'],
        'social_telegram'     => ['Telegram', 'url', 'https://t.me/…'],
        'social_viber'        => ['Viber', 'url', 'viber://chat?number=%2B380… или https://…'],
        'social_instagram'    => ['Instagram', 'url', 'https://instagram.com/…'],
        'social_facebook'     => ['Facebook', 'url', 'https://facebook.com/…'],
    ];

    /** Тексты магазина, у которых есть украинский вариант «<ключ>.uk» */
    public const STORE_UK = ['site_title', 'address', 'work_hours'];

    /** SEO-шаблоны: группа → [название, [поле → подпись], переменные] */
    public const SEO_GROUPS = [
        'home_page' => ['Главная страница', ['meta_title' => 'Title', 'meta_description' => 'Description', 'meta_keywords' => 'Keywords'],
            ['store_info.name', 'store_info.phone']],
        'category' => ['Категория', ['meta_title' => 'Title', 'meta_description' => 'Description', 'meta_keywords' => 'Keywords', 'h1' => 'H1'],
            ['category.name', 'category.seo_name', 'store_info.name', 'store_info.phone']],
        'category_pagination' => ['Категория — страницы 2, 3, … (пагинация)', ['meta_title' => 'Title', 'meta_description' => 'Description', 'h1' => 'H1'],
            ['category.name', 'category.seo_name', 'page_number', 'store_info.name', 'store_info.phone']],
        'product' => ['Товар', ['meta_title' => 'Title', 'meta_description' => 'Description', 'meta_keywords' => 'Keywords', 'h1' => 'H1'],
            ['product.name', 'product.seo_name', 'product.format_price', 'category.name', 'category.seo_name', 'store_info.name', 'store_info.phone']],
        'page' => ['Информационная страница', ['meta_title' => 'Title', 'meta_description' => 'Description', 'meta_keywords' => 'Keywords'],
            ['page.name', 'store_info.name', 'store_info.phone']],
        'brand' => ['Бренд', ['meta_title' => 'Title', 'meta_description' => 'Description', 'meta_keywords' => 'Keywords', 'h1' => 'H1'],
            ['brand.name', 'store_info.name', 'store_info.phone']],
    ];

    /** Флаги «шаблоны включены», которые читает витрина: группа → ключ (выключено — только свои мета-теги) */
    public const SEO_TOGGLES = [
        'category'            => 'seo.category_is_enabled',
        'category_pagination' => 'seo.category_pagination_is_enabled',
        'page'                => 'seo.page_is_enabled',
    ];

    /** Подписи прочих шаблонов из Webasyst */
    private const SEO_OTHER_LABELS = [
        'brand_category_meta_title' => 'Бренд в категории — Title',
        'product_page_meta_title'   => 'Подстраница товара — Title',
        'product_review_meta_title' => 'Отзывы о товаре — Title',
        'tag_meta_title'            => 'Тег — Title',
        'tag_meta_keywords'         => 'Тег — Keywords',
    ];

    /** Страница блога /blog/ — обычный текст без переменных (читает Front\BlogController) */
    public const BLOG_FIELDS = [
        'blog.name'             => 'Название блога (заголовок страницы /blog/)',
        'blog.meta_title'       => 'Title',
        'blog.meta_description' => 'Description',
        'blog.meta_keywords'    => 'Keywords',
    ];

    /** Переменные, которые понимают шаблоны */
    private const SEO_VARS = ['product', 'category', 'store_info', 'page', 'brand', 'page_number', 'tag'];

    public function index(string $tab = 'store'): Response
    {
        if (!array_key_exists($tab, self::TABS)) return $this->missing();
        $canEdit = Auth::isAdmin();
        $errors = [];
        $values = null;   // введённые значения при ошибке

        if (Request::isPost()) {
            if (!$canEdit) {
                $this->flash('Изменять настройки может только администратор.', true);
                return Response::redirect(self::tabUrl($tab));
            }
            [$changes, $errors, $values] = match ($tab) {
                'store'      => $this->collectStore(),
                'seo'        => $this->collectSeo(),
                'shipping'   => $this->collectShipping(),
                'currencies' => $this->collectCurrencies(),
                'mail'       => $this->collectMail(),
            };
            if (!$errors) {
                $all = Settings::all();
                $changed = [];
                foreach ($changes as $k => $v) {
                    $new = is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE) : (string) $v;
                    $exists = array_key_exists($k, $all);
                    if (!$exists && $new === '') continue;                 // пустой новый ключ не создаём
                    // mail.admin_to нет, а письма и так уходят на этот адрес (e-mail магазина / config.php) — ключ не нужен
                    if (!$exists && $k === 'mail.admin_to' && $new === Mailer::adminEmail()) continue;
                    if (!$exists || (string) $all[$k] !== $new) {
                        Settings::set($k, $v);
                        $changed[] = $k;
                    }
                }
                if ($tab === 'shipping' && self::dropUkCopies()) $changed[] = 'shipping_methods.uk';
                if ($changed) {
                    $this->log('settings_update', 'settings', null, ['tab' => $tab, 'keys' => $changed]);
                    $this->flash('Настройки сохранены (изменено: ' . count($changed) . '). Кэш сайта обновлён.');
                } else {
                    $this->flash('Изменений нет.');
                }
                return Response::redirect(self::tabUrl($tab));
            }
        }

        $data = ['title' => 'Настройки', 'tab' => $tab, 'tabs' => self::TABS, 'canEdit' => $canEdit, 'errors' => $errors,
            'styles' => ['admin/content.css'], 'scripts' => ['admin/content.js']];
        $s = Settings::all();
        switch ($tab) {
            case 'store':
                $data['fields'] = self::STORE_FIELDS;
                $data['values'] = $values ?? $s;
                $data['phones'] = $values['phones'] ?? self::jsonOf($s, 'phones');
                break;
            case 'seo':
                $data['groups'] = self::SEO_GROUPS;
                $data['values'] = $values ?? $s;
                $data['other'] = self::otherSeoKeys($s);
                $data['blog'] = self::BLOG_FIELDS;
                $data['toggles'] = self::SEO_TOGGLES;
                $data['service'] = array_filter($s, static fn($v, $k) => str_starts_with((string) $k, 'seo.') && self::isServiceKey((string) $k)
                    && !in_array($k, self::SEO_TOGGLES, true), ARRAY_FILTER_USE_BOTH);
                $data['sample'] = self::sampleVars(false);
                $data['sampleUk'] = self::sampleVars(true);
                break;
            case 'shipping':
                $data['lists'] = [
                    'shipping_methods' => ['Способы доставки', $values['shipping_methods'] ?? self::jsonOf($s, 'shipping_methods')],
                    'payment_methods'  => ['Способы оплаты', $values['payment_methods'] ?? self::jsonOf($s, 'payment_methods')],
                ];
                $data['used'] = self::usedCodes();
                break;
            case 'currencies':
                $data['rates'] = $values ?? (self::jsonOf($s, 'currencies') ?: ['UAH' => 1]);
                break;
            case 'mail':
                // адрес уведомлений один на два экрана: здесь (notify_email) и в «Почта (SMTP)» (mail.admin_to) — показываем действующий
                $data['values'] = $values ?? (['notify_email' => (string) (($s['mail.admin_to'] ?? '') !== '' ? $s['mail.admin_to'] : ($s['notify_email'] ?? ''))] + $s);
                $data['mail'] = [
                    'from' => (string) Mailer::cfg('from', ''), 'smtp' => (string) Mailer::cfg('smtp_host', ''),
                    'store_email' => (string) ($s['store_email'] ?? ''), 'admin' => Mailer::adminEmail(),
                ];
                break;
        }
        $r = $this->render('admin/settings/index', $data);
        if ($errors) $r->status = 422;
        return $r;
    }

    // ------------------------------------------------------------------ сбор и проверка значений вкладок
    // Каждый метод возвращает [изменения key => value, ошибки field => текст, введённые значения для формы]

    private function collectStore(): array
    {
        $vals = []; $err = [];
        foreach (self::STORE_FIELDS as $k => [$label, $type]) {
            $v = mb_substr(Request::post($k), 0, 500);
            if ($type === 'email' && $v !== '' && !filter_var($v, FILTER_VALIDATE_EMAIL)) $err[$k] = 'Неверный e-mail';
            if ($type === 'url' && $v !== '' && !preg_match('#^(https?://[^\s"<>]+|viber://[^\s"<>]+|tg://[^\s"<>]+)$#i', $v)) $err[$k] = 'Ссылка должна начинаться с https://';
            $vals[$k] = $v;
        }
        if ($vals['store_name'] === '') $err['store_name'] = 'Укажите название магазина';
        $year = (int) $vals['since_year'];
        if ($year < 1950 || $year > (int) date('Y')) $err['since_year'] = 'Год от 1950 до ' . date('Y');
        $boxes = $vals['free_shipping_boxes'];
        if (!preg_match('/^\d{1,4}$/', $boxes)) $err['free_shipping_boxes'] = 'Целое число ящиков';
        $pp = (int) $vals['products_per_page'];
        if ($pp < 8 || $pp > 96) $err['products_per_page'] = 'От 8 до 96';

        // украинские варианты текстов
        $uk = Request::postArray('uk');
        foreach (self::STORE_UK as $k) {
            $v = $uk[$k] ?? '';
            $vals[$k . '.uk'] = is_scalar($v) ? mb_substr(trim((string) $v), 0, 500) : '';
        }

        $phones = [];
        foreach (Request::postArray('phones') as $p) {
            $p = trim(mb_substr((string) (is_scalar($p) ? $p : ''), 0, 40));
            if ($p === '') continue;
            if (strlen((string) preg_replace('/\D/', '', $p)) < 10) { $err['phones'] = 'Телефон «' . $p . '» — нужно не меньше 10 цифр'; }
            $phones[] = $p;
        }
        if (!$phones) $err['phones'] = 'Укажите хотя бы один телефон';
        $vals['phones'] = array_values(array_unique($phones));
        $vals['since_year'] = (string) $year;
        $vals['products_per_page'] = (string) $pp;
        return [$vals, $err, $vals];
    }

    private function collectSeo(): array
    {
        $vals = []; $err = [];
        $keys = [];
        foreach (self::SEO_GROUPS as $g => [, $fields]) foreach ($fields as $f => $label) $keys[] = 'seo.' . $g . '_' . $f;
        foreach (array_keys(self::otherSeoKeys(Settings::all())) as $k) $keys[] = $k;
        $post = Request::postArray('seo');
        $postUk = Request::postArray('seo_uk');
        foreach ($keys as $k) {
            foreach ([[$k, $post], [$k . '.uk', $postUk]] as [$key, $src]) {
                $v = $src[$k] ?? '';
                $v = is_scalar($v) ? trim(str_replace(["\r\n", "\r", "\n"], ' ', (string) $v)) : '';
                if ($e = self::tplError($v)) $err[$key] = $e;
                $vals[$key] = $v;
            }
        }
        $on = Request::postArray('enabled');
        foreach (self::SEO_TOGGLES as $key) $vals[$key] = !empty($on[$key]) ? '1' : '0';
        // страница блога — обычный текст
        $blog = Request::postArray('blog');
        $blogUk = Request::postArray('blog_uk');
        foreach (array_keys(self::BLOG_FIELDS) as $k) {
            foreach ([[$k, $blog], [$k . '.uk', $blogUk]] as [$key, $src]) {
                $v = $src[$k] ?? '';
                $v = is_scalar($v) ? trim(str_replace(["\r\n", "\r", "\n"], ' ', (string) $v)) : '';
                if (mb_strlen($v) > 1000) $err[$key] = 'Слишком длинный текст';
                $vals[$key] = $v;
            }
        }
        return [$vals, $err, $vals + Settings::all()];
    }

    /** Ошибка в шаблоне: длина, неизвестная переменная, незакрытая скобка */
    private static function tplError(string $v): ?string
    {
        if (mb_strlen($v) > 1000) return 'Слишком длинный шаблон';
        if (substr_count($v, '{') !== substr_count($v, '}')) return 'Незакрытая фигурная скобка';
        if (preg_match_all('/\{\$([a-z_]+)(?:\.([a-z_]+))?[^}]*\}/i', $v, $m)) {
            foreach ($m[1] as $i => $var) {
                if (!in_array($var, self::SEO_VARS, true)) return 'Неизвестная переменная {$' . $var . ($m[2][$i] ? '.' . $m[2][$i] : '') . '}';
            }
        }
        return null;
    }

    private function collectShipping(): array
    {
        $out = []; $err = [];
        $all = Settings::all();
        $maxId = 0;
        $old = [];   // прежние элементы по коду — сохраняем служебные ключи (pickup, field, placeholder…)
        $lists = ['shipping_methods' => 'shipping', 'payment_methods' => 'payment'];
        foreach (array_keys($lists) as $key) {
            foreach (self::jsonOf($all, $key) as $it) {
                if (!is_array($it)) continue;
                $maxId = max($maxId, (int) ($it['id'] ?? 0));
                if (($it['code'] ?? '') !== '') $old[$key][(string) $it['code']] = $it;
            }
        }
        $usedCodes = [];
        foreach (array_keys(self::usedCodes()) as $k) $usedCodes[substr((string) $k, 2)] = true;   // 's:p4' → 'p4'
        $seenCodes = [];
        foreach ($lists as $key => $field) {
            $rows = [];
            $pos = 0;
            foreach (Request::postArray($field) as $r) {
                if (!is_array($r)) continue;
                $str = static fn(string $k, int $max) => trim(mb_substr(is_scalar($r[$k] ?? null) ? (string) $r[$k] : '', 0, $max));
                $name = $str('name', 190);
                $code = (string) preg_replace('/[^a-z0-9_-]/i', '', $str('code', 32));
                $desc = $str('description', 1000);
                $nameUk = $str('name_uk', 190);
                $descUk = $str('description_uk', 1000);
                if ($name === '' && $code === '' && $desc === '' && $nameUk === '') continue;       // пустая новая строка
                if ($name === '') $err[$key] = 'У каждого способа должно быть название';
                $id = (int) ($r['id'] ?? 0);
                if ($code === '') {                                                // новый способ — новый код p{id}
                    do { $id = ++$maxId; $code = 'p' . $id; } while (isset($usedCodes[$code]) || isset($seenCodes[$code]));
                }
                if (isset($seenCodes[$code])) $err[$key] = 'Код «' . $code . '» повторяется';
                $seenCodes[$code] = true;
                $item = array_merge($old[$key][$code] ?? [], ['id' => $id, 'code' => $code, 'name' => $name, 'description' => $desc,
                    'name_uk' => $nameUk, 'description_uk' => $descUk, 'status' => !empty($r['status']) ? 1 : 0,
                    'sort' => (int) ($r['sort'] ?? 0), '_pos' => $pos++]);
                $rows[] = $item;
            }
            usort($rows, static fn($a, $b) => [$a['sort'], $a['_pos']] <=> [$b['sort'], $b['_pos']]);
            foreach ($rows as $i => &$row) { unset($row['_pos']); $row['sort'] = $i + 1; }
            unset($row);
            if (!array_filter($rows, static fn($r) => $r['status'])) $err[$key] = ($field === 'shipping' ? 'Включите хотя бы один способ доставки' : 'Включите хотя бы один способ оплаты');
            $out[$key] = $rows;
        }
        return [$out, $err, $out];
    }

    /** Удалить старые копии «shipping_methods.uk»/«payment_methods.uk» (их не должно быть — см. описание класса) */
    private static function dropUkCopies(): bool
    {
        $n = App::db()->delete('settings', "name IN ('shipping_methods.uk', 'payment_methods.uk')");
        if ($n) Cache::flush();
        return $n > 0;
    }

    private function collectCurrencies(): array
    {
        $rates = self::jsonOf(Settings::all(), 'currencies') ?: ['UAH' => 1];
        $rates['UAH'] = 1;
        $err = [];
        $post = Request::postArray('rates');
        foreach (['USD', 'EUR'] as $c) {
            $v = str_replace([',', ' '], ['.', ''], trim((string) (is_scalar($post[$c] ?? null) ? $post[$c] : '')));
            if (!is_numeric($v) || (float) $v <= 0 || (float) $v > 100000) { $err[$c] = 'Курс ' . $c . ' — положительное число, например 41.25'; $rates[$c] = $v; continue; }
            // поле не трогали (в форме курс округлён до 4 знаков) — оставляем точное прежнее значение
            if (isset($rates[$c]) && is_numeric($rates[$c]) && $v === self::rateText($rates[$c])) continue;
            $rates[$c] = round((float) $v, 4);
        }
        return [['currencies' => $rates], $err, $rates];
    }

    /**
     * Адрес уведомлений: notify_email. Дублируется в mail.admin_to — его читает Mailer::adminEmail()
     * (письма о заказах и заявках), пусто — письма идут на e-mail магазина.
     */
    private function collectMail(): array
    {
        $v = trim(mb_strtolower(mb_substr(Request::post('notify_email'), 0, 190)));
        $err = [];
        if ($v !== '' && !filter_var($v, FILTER_VALIDATE_EMAIL)) $err['notify_email'] = 'Неверный e-mail';
        $all = Settings::all();
        // тот же адрес — в mail.admin_to (его правит и экран «Почта (SMTP)»): оба ключа всегда одинаковые
        return [['notify_email' => $v, 'mail.admin_to' => $v], $err, ['notify_email' => $v] + $all];
    }

    // ------------------------------------------------------------------ вспомогательное

    /** JSON-настройка как массив — всегда русский вариант (в админке Settings::json тоже русский, но без зависимости от языка) */
    private static function jsonOf(array $all, string $key): array
    {
        $v = json_decode((string) ($all[$key] ?? ''), true);
        return is_array($v) ? $v : [];
    }

    /** Ключ служебный (флаги включения Webasyst, данные плагина) — не редактируется */
    private static function isServiceKey(string $k): bool
    {
        if (str_ends_with($k, '.uk')) $k = substr($k, 0, -3);
        return str_starts_with($k, 'seo.plugin.') || str_ends_with($k, '_is_enabled') || str_ends_with($k, '_ignore_description');
    }

    /** Прочие seo.*-шаблоны из базы (не входящие в основные группы): key → подпись */
    private static function otherSeoKeys(array $all): array
    {
        $known = [];
        foreach (self::SEO_GROUPS as $g => [, $fields]) foreach ($fields as $f => $l) $known['seo.' . $g . '_' . $f] = true;
        $out = [];
        foreach ($all as $k => $v) {
            $k = (string) $k;
            if (!str_starts_with($k, 'seo.') || str_ends_with($k, '.uk') || isset($known[$k]) || self::isServiceKey($k)) continue;
            $short = substr($k, 4);
            $out[$k] = self::SEO_OTHER_LABELS[$short] ?? $short;
        }
        ksort($out);
        return $out;
    }

    /** Пример значений переменных (реальный товар, категория, бренд, страница) для предпросмотра шаблонов */
    private static function sampleVars(bool $uk): array
    {
        $db = App::db();
        $all = Settings::all();
        $pick = static fn(array $r, string $k) => $uk && (string) ($r[$k . '_uk'] ?? '') !== '' ? (string) $r[$k . '_uk'] : (string) ($r[$k] ?? '');
        $p = $db->row('SELECT id, name, name_uk, seo_name, seo_name_uk, price, category_id FROM products WHERE status = 1 AND category_id IS NOT NULL ORDER BY id DESC LIMIT 1') ?? [];
        $c = $p ? (Catalog::category((int) $p['category_id']) ?? []) : [];
        $brand = [];
        foreach (Catalog::brands() as $b) { if (!(int) $b['hidden'] && (int) $b['product_count'] > 0) { $brand = $b; break; } }
        $page = $db->row("SELECT name, name_uk FROM pages WHERE status = 1 AND url NOT LIKE 'pages/%' ORDER BY sort, id LIMIT 1") ?? [];
        $pName = $pick($p, 'name') ?: 'Кроссовки';
        $cName = $pick($c, 'name') ?: 'Детская обувь';
        return [
            'product'    => ['name' => $pName, 'seo_name' => $pick($p, 'seo_name') ?: $pName, 'format_price' => price_format($p['price'] ?? 250)],
            'category'   => ['name' => $cName, 'seo_name' => $pick($c, 'seo_name') ?: $cName],
            'brand'      => ['name' => $brand['name'] ?? 'Jong Golf'],
            'page'       => ['name' => $pick($page, 'name') ?: 'О компании'],
            'store_info' => ['name' => (string) ($all['store_name'] ?? 'Tomobuv'), 'phone' => (string) ($all['store_phone'] ?? '')],
            'page_number' => 2,
        ];
    }

    /** Курс для поля формы: до 4 знаков без хвостовых нулей (29.9999999698 → «30», 41.25 → «41.25») */
    public static function rateText($v): string
    {
        return is_numeric($v) ? rtrim(rtrim(number_format((float) $v, 4, '.', ''), '0'), '.') : (string) $v;
    }

    /**
     * Сколько заказов с каждым способом: ['s:p4' => 120, 'p:p3' => 80, …] — один проход по заказам,
     * кэш на 10 минут (сбрасывается любым сохранением настроек).
     */
    private static function usedCodes(): array
    {
        return Cache::remember('admin.settings.used_methods', 600, static function (): array {
            $out = [];
            foreach (App::db()->all('SELECT shipping_method AS s, payment_method AS p, COUNT(*) AS n FROM orders GROUP BY shipping_method, payment_method') as $r) {
                if ($r['s'] !== null && $r['s'] !== '') $out['s:' . $r['s']] = ($out['s:' . $r['s']] ?? 0) + (int) $r['n'];
                if ($r['p'] !== null && $r['p'] !== '') $out['p:' . $r['p']] = ($out['p:' . $r['p']] ?? 0) + (int) $r['n'];
            }
            return $out;
        });
    }

    public static function tabUrl(string $tab): string
    {
        return $tab === 'store' ? '/admin/settings/' : '/admin/settings/' . $tab . '/';
    }

    private function missing(): Response
    {
        $r = $this->render('admin/forbidden', ['title' => 'Раздел не найден', 'message' => 'Такой вкладки настроек нет.']);
        $r->status = 404;
        return $r;
    }
}
