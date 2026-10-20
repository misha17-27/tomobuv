<?php
declare(strict_types=1);

namespace App\Services\Suppliers;

use App\Core\App;
use App\Core\Cache;
use App\Core\Settings;
use App\Services\Import\Importer;

/**
 * Загрузка товаров Jong•Golf — адаптер каркаса поставщиков (WebSupplier → Supplier: настройки, расписание, запуски,
 * блокировки, отчёт) с двумя источниками (настройка «jonggolf.source»); связи supplier_links (код цвета → товар),
 * таблица категорий и «Обзор» у них общие:
 *   site (по умолчанию) — официальный сайт бренда www.jonggolf.com (магазин — дилер бренда, данные и фото брать можно): весь
 *          каталог в наличии — один запрос списка /ru/catalog/?limit=1000 (≈2,2 МБ, по сети ≈110 КБ); карточка модели и фото — только
 *          для новых (JongGolfSiteSync, JongGolfParser, JongGolfClient: пауза, robots.txt). Цвета без остатка бренд не показывает:
 *          связанный товар, которого нет в полном проходе, — «нет в наличии», после missing_runs (2) полных проходов подряд — скрыт;
 *   api  — API бренда (замена коммерческого wa_loader_jonggolf.php старого сайта; ключ и белый список IP), очередь выгрузки
 *          с подтверждением — запасной путь (JongGolfSync, JongGolfApi).
 *
 * Запуск (supplier_runs) идёт шагами, каждый укладывается в бюджет времени (админка — ~12 с на AJAX-шаг,
 * cron/CLI — дольше), после обрыва продолжается с того же места (state).
 * Сайт бренда (src web — с сайта, pages — сохранённые страницы; state.site = true):
 *   start  → проверки (наценка, разделы, характеристики, robots.txt);
 *   list   → список /ru/catalog/?limit=1000 → runs/N/list-1.html (после обрыва повторно не качается); полнота — по фасетам пола
 *            (их сумма = цветов в наличии); меньше — запасной путь по 100 на странице; не похоже на список — ошибка, ничего не пишется;
 *   items  → цвета порциями по 40 (JongGolfSiteSync: цена с наценкой, наличие, запись; карточка и фото — новым);
 *   finish → только полный проход: связанные, которых нет в списке, — «нет в наличии», N проходов подряд — скрыть (защита 50 %).
 * API (src api — очередь, file — сохранённый ответ):
 *   dict    → справочники поставщика (export_product_setting; для прогона по файлу — сохранённая копия);
 *   page    → очередная страница очереди (export_product, ≤ 300 товаров; первая — с clean_export при «Полном
 *             обновлении»), сохраняется в storage/import/jonggolf/runs/N/page-K.json;
 *   items   → товары страницы порциями по 20 (JongGolfSync: одна транзакция + точечная переиндексация);
 *   confirm → подтверждение страницы поставщику (export_product_callback) — только боевой запуск, после записи
 *             всей страницы, как у старого загрузчика; без подтверждения следующая страница недоступна;
 *   finish  → «Полное обновление»: скрыть товары поставщика, которых нет в выгрузке (вместо удаления у старого),
 *             фильтры категорий, сброс кэша, уборка старых отчётов.
 * Виды запуска: run — запись в базу; dry — пробный прогон (ничего не пишет в каталог и не подтверждает поставщику,
 * показывает, что будет добавлено / изменено / скрыто); test — проверка связи (сайт: robots.txt и первая страница каталога;
 * API: только export_product_setting). В режиме разработки (config debug = true), при выключенной автозагрузке, при
 * источнике «сайт бренда» и в пробном прогоне clean_export и callback не отправляются никогда (проверка — в JongGolfApi).
 */
final class JongGolf extends WebSupplier
{
    public const CODE = 'jonggolf';
    public const TITLE = 'Jong•Golf';
    public const SITE = 'https://www.jonggolf.com';
    /** Каталог в наличии одним запросом (сервер принимает limit=1000, в интерфейсе 9 / 21); запасной путь — по 100 на странице */
    public const LIST_PATH = '/ru/catalog/';
    public const LIST_LIMIT = 1000;
    public const PAGE_LIMIT = 100;
    /** Образец адреса фото товара (проверка robots.txt хоста фото в начале запуска и в «Проверке связи») */
    public const PHOTO_PROBE = 'https://public.jonggolf.com/files/products/1/1/b_0.jpg';
    public const SOURCES = ['site' => 'официальный сайт бренда (www.jonggolf.com)', 'api' => 'API бренда (ключ, белый список IP)'];
    /** Наличие у бренда переносится на сайт («нет в наличии», а не сразу скрытие) — список «Нет в наличии» в «Обзоре» */
    public const TRACKS_STOCK = true;

    /** Настройки (settings, ключи «jonggolf.*»): значения владельца из wa_loader_jonggolf.cfg.php и решения владельца, загрузка выключена */
    public const DEFAULTS = [
        'enabled' => '0', 'source' => 'site', 'api_key' => '',
        // решение владельца: полная сверка цен и наличия всех товаров каждые 5 ч круглые сутки (cron раз в час решает, пора ли);
        // с сайта бренда каждый проход полный — 1 запрос списка (быстрого режима нет)
        'window_from' => '0', 'window_to' => '23', 'period' => '5',
        // сайт бренда: бережность, наценка (не задана — включить нельзя), новые за запуск (каждый новый цвет — фото, модель — карточка)
        'delay' => '2', 'max_requests' => '1500', 'max_new' => '500',
        'markup_k' => '', 'markup_plus' => '0', 'round_step' => '1', 'round_mode' => 'math',
        'hide_missing' => '1', 'missing_runs' => '2', 'card_features' => '1', 'more_photos' => '1', 'brand' => 'Jong•Golf',
        // API: каждый запуск — весь каталог (clean_export), не пришедшие — скрыть
        'full_update' => '1',
        'add_new' => '1', 'add_without_photo' => '0',
        'update_category' => '1', 'update_name' => '0', 'update_features' => '0', 'update_description' => '0',
        'keep_manual_hidden' => '0', 'adopt_manual' => '1',
        'skip_brands' => '', 'skip_categories' => '',
        'name_mode' => 'site',
        // SEO новых товаров: пусто — шаблоны раздела «SEO» сайта (seo.product_meta_*; «машинные» «{name}» и «купить {name} в Одессе»
        // старого загрузчика SEO-стандарт сайта всё равно очищает — App\Services\SeoFix); свой текст — шаблон с {name}
        'meta_title' => '', 'meta_description' => '', 'meta_keywords' => '',
        'meta_title_uk' => '', 'meta_description_uk' => '', 'meta_keywords_uk' => '',
        'attributes' => "gender=Пол\nsize=Размер\nmaterial>outside=Материал внешний\nmaterial>inside=Материал внутри\nmaterial>bottom=Материал\nmaterial>outsole=Материал подошвы\nbrand=Бренд",
        'photo_width' => '700', 'photo_height' => '700', 'photo_quality' => '90', 'photo_replace' => '0', 'photo_check_file' => '0',
        'category_filter' => 'empty', 'log_days' => '5', 'history_days' => '365', 'prices' => '{}',
    ];
    public const BOOLS = ['enabled', 'full_update', 'add_new', 'add_without_photo', 'update_category', 'update_name', 'update_features',
        'update_description', 'keep_manual_hidden', 'adopt_manual', 'photo_replace', 'photo_check_file', 'hide_missing', 'card_features', 'more_photos'];
    public const NAME_MODES = [
        'site'     => 'По правилу сайта: «Категория Бренд Код» (как автоназвания, RU и UA)',
        'supplier' => 'Как старый загрузчик: «категория поставщика Бренд Код» («Кросівки Jong•Golf B11751-12»)',
    ];
    public const FILTER_MODES = ['empty' => 'Заполнять пустой фильтр категории (как старый загрузчик)', 'always' => 'Всегда дополнять фильтр', 'never' => 'Не трогать фильтры'];

    /**
     * Поля товара у поставщика для характеристик (левая часть строк «поле=Характеристика»). С сайта бренда: пол, ряд, бренд,
     * пар в ящике и цвет — из списка, материалы и повторные размеры — из карточки модели (только новым товарам).
     */
    public const FIELDS = ['gender' => 'пол', 'size' => 'размерный ряд', 'brand' => 'бренд', 'material>outside' => 'материал верха',
        'material>inside' => 'материал внутри', 'material>bottom' => 'материал стельки', 'material>outsole' => 'материал подошвы',
        'pairs' => 'пар в ящике (сайт бренда)', 'color' => 'цвет по коду (справочник API; сайт бренда)', 'repeat' => 'повторные размеры «29-2,30-2» (сайт бренда, карточка)'];

    // ================================================================== настройки и источник

    /** Источник — официальный сайт бренда (иначе API) */
    public static function siteSource(): bool
    {
        return self::cfg('source') !== 'api';
    }

    /** Автозагрузка включена владельцем и выполнены условия источника: сайт бренда — задана наценка, API — задан ключ */
    public static function enabled(): bool
    {
        if (!self::on('enabled')) return false;
        return self::siteSource() ? self::markup() !== null : self::cfg('api_key') !== '';
    }

    /** «Запустить сейчас»: с сайта бренда — при заданной наценке (и при выключенной автозагрузке); по API — только при включённой */
    public static function manualAllowed(): bool
    {
        return self::siteSource() ? parent::manualAllowed() : self::enabled();
    }

    public static function manualHint(): string
    {
        if (self::siteSource()) return parent::manualHint();
        return self::enabled() ? '' : 'Автозагрузка по API выключена (или не задан ключ)';
    }

    protected static function liveSrc(): string
    {
        return self::siteSource() ? 'web' : 'api';
    }

    protected static function liveSrcs(): array
    {
        return ['web', 'api'];
    }

    /** Запуск с сайта бренда (src web — с сайта, pages — сохранённые страницы); api и file — API */
    public static function webRun(array $run): bool
    {
        return in_array((string) ($run['src'] ?? ''), ['web', 'pages'], true);
    }

    /** Бренды сайта, весь товар которых — от Jong•Golf (настройка «Бренд новых товаров»): «Обзор» — товары бренда без связи */
    public static function ownBrandIds(): array
    {
        $want = HitObuviSync::bkey(self::cfg('brand') ?: self::TITLE);
        $out = [];
        foreach (App::db()->all('SELECT id, name FROM brands') as $b) if (HitObuviSync::bkey((string) $b['name']) === $want) $out[] = (int) $b['id'];
        return $out;
    }

    public static function viaNames(): array
    {
        return JongGolfSiteSync::VIA;
    }

    /** Ключ для показа: «ключ задан (64 символа)» — без единого символа самого ключа; не задан — '' */
    public static function keyHint(): string
    {
        $n = strlen(self::cfg('api_key'));
        if ($n === 0) return '';
        $w = $n % 10 === 1 && $n % 100 !== 11 ? 'символ' : ($n % 10 >= 2 && $n % 10 <= 4 && ($n % 100 < 12 || $n % 100 > 14) ? 'символа' : 'символов');
        return 'ключ задан (' . $n . ' ' . $w . ')';
    }

    /**
     * Записать ключ API ('' — удалить). Ключ приходит внутри массива и пишется запросом с параметрами-массивом:
     * при ошибке базы в трассировке исключения (getTraceAsString при zend.exception_ignore_args = Off, журнал ошибок)
     * будут «Array», а не первые 15 символов ключа, как у Settings::set('jonggolf.api_key', $key). Сообщение ошибки —
     * без ключа, исходное исключение не прикладывается (в его трассировке — те же «Array», но его текст — от базы).
     */
    private static function storeKey(#[\SensitiveParameter] array $secret): void
    {
        try {
            App::db()->upsert('settings', ['name' => 'jonggolf.api_key', 'value' => (string) ($secret['key'] ?? '')], ['value']);
        } catch (\Throwable $e) {
            $k = (string) ($secret['key'] ?? '');
            $msg = $k !== '' ? str_replace([$k, rawurlencode($k)], '***', $e->getMessage()) : $e->getMessage();
            throw new \RuntimeException('Ключ API не сохранён: ' . $msg);
        }
        Settings::reset();
        Cache::flush();
    }

    /**
     * Сохранить настройки из формы (форма присылает поля обоих источников). Ключ API: пустое поле — не менять, «-» — удалить.
     * Включить загрузку: с сайта бренда — только с наценкой (цены бренда — закупочные), по API — только с ключом.
     * @return array [ошибки по полям]
     */
    public static function saveSettings(#[\SensitiveParameter] array $post): array
    {
        $in = [];
        $err = [];
        foreach (self::DEFAULTS as $k => $def) {
            if ($k === 'prices' || $k === 'api_key') continue;
            $in[$k] = in_array($k, self::BOOLS, true) ? (!empty($post[$k]) ? '1' : '0') : trim(str_replace("\r\n", "\n", (string) ($post[$k] ?? $def)));
        }
        if (!isset(self::SOURCES[$in['source']])) $in['source'] = 'site';
        self::checkCommon($in, $err, ['window_from' => [0, 23], 'window_to' => [0, 23], 'period' => [1, 168], 'photo_width' => [0, 3000], 'photo_height' => [0, 3000],
            'photo_quality' => [30, 100], 'log_days' => [1, 90], 'history_days' => [30, 3650], 'max_requests' => [20, 5000], 'max_new' => [0, 5000], 'missing_runs' => [1, 10]]);
        if ($in['source'] === 'api') unset($err['enabled']);              // наценка обязательна только сайту бренда (у API — коэффициенты по категориям)
        $in['brand'] = mb_substr($in['brand'], 0, 100) ?: self::TITLE;
        if (!isset(self::NAME_MODES[$in['name_mode']])) $in['name_mode'] = 'site';
        if (!isset(self::FILTER_MODES[$in['category_filter']])) $in['category_filter'] = 'empty';
        foreach (['meta_title', 'meta_description', 'meta_keywords', 'meta_title_uk', 'meta_description_uk', 'meta_keywords_uk'] as $k) $in[$k] = mb_substr($in[$k], 0, 500);
        foreach (['skip_brands', 'skip_categories'] as $k) $in[$k] = mb_substr($in[$k], 0, 5000);
        [, $attrErr] = self::attributes($in['attributes']);
        if ($attrErr) $err['attributes'] = implode('; ', $attrErr);
        $key = trim((string) ($post['api_key'] ?? ''));
        $hasKey = $key !== '' && $key !== '-' ? true : ($key === '-' ? false : self::cfg('api_key') !== '');
        if ($key !== '' && $key !== '-' && !preg_match('/^[\x21-\x7E]{8,200}$/', $key)) $err['api_key'] = 'Ключ — латинские буквы, цифры и знаки, без пробелов (8–200 символов)';
        if ($in['enabled'] === '1' && $in['source'] === 'api' && !$hasKey) $err['enabled'] = 'Чтобы включить автозагрузку по API, укажите ключ API';
        if ($err) return $err;
        self::store($in);
        if ($key === '-') self::storeKey(['key' => '']);
        elseif ($key !== '') self::storeKey(['key' => $key]);
        return [];
    }

    /**
     * Характеристики из строк «поле поставщика=Характеристика сайта» (как $attribute_textarea старого загрузчика,
     * «/r/n» тоже разделитель). Характеристика — по названию или коду. @return array{0: [поле => строка features], 1: string[] ошибки}
     */
    public static function attributes(?string $text = null): array
    {
        return self::featureLines($text ?? self::cfg('attributes'), self::FIELDS);
    }

    /** Разбор строк «поле=Характеристика» по списку полей поставщика $fields (общий для адаптеров) */
    public static function featureLines(string $text, array $fields): array
    {
        $features = App::db()->all('SELECT id, code, name, type FROM features');
        $byName = [];
        foreach ($features as $f) { $byName[Importer::nk((string) $f['name'])] ??= $f; $byName['code:' . strtolower((string) $f['code'])] = $f; }
        $out = []; $err = [];
        foreach (preg_split('~\r\n|\n|/r/n~', $text) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || !str_contains($line, '=')) continue;
            [$l, $r] = array_map('trim', explode('=', $line, 2));
            if (!isset($fields[$l])) { $err[] = 'неизвестное поле поставщика «' . $l . '» (есть: ' . implode(', ', array_keys($fields)) . ')'; continue; }
            $f = $byName[Importer::nk($r)] ?? $byName['code:' . strtolower($r)] ?? null;
            if (!$f) { $err[] = 'на сайте нет характеристики «' . $r . '»'; continue; }
            $out[$l] = $f;
        }
        return [$out, $err];
    }

    /** Коэффициенты цен по категориям сайта: [id категории => ['k' => множитель, 'plus' => надбавка, грн]] */
    public static function prices(): array
    {
        $j = json_decode(self::cfg('prices'), true);
        $out = [];
        foreach (is_array($j) ? $j : [] as $cid => $r) {
            $k = (float) ($r['k'] ?? 1); $plus = (float) ($r['plus'] ?? 0);
            if ((int) $cid > 0 && ($k != 1.0 || $plus != 0.0) && $k > 0) $out[(int) $cid] = ['k' => $k, 'plus' => $plus];
        }
        return $out;
    }

    public static function savePrices(array $k, array $plus): int
    {
        $out = [];
        foreach ($k as $cid => $v) {
            $kv = Importer::num((string) $v) ?? 1.0;
            $pv = Importer::num((string) ($plus[$cid] ?? '0')) ?? 0.0;
            if ((int) $cid > 0 && $kv > 0 && $kv <= 100 && abs($pv) < 100000 && ($kv != 1.0 || $pv != 0.0)) $out[(int) $cid] = ['k' => round($kv, 4), 'plus' => round($pv, 2)];
        }
        Settings::set('jonggolf.prices', $out ? json_encode($out) : '{}');
        return count($out);
    }

    /**
     * Перенос настроек из wa_loader_jonggolf.cfg.php старого загрузчика. Файл НЕ выполняется — присваивания
     * «$имя=значение;» разбираются как текст. Логин и пароль старой админки, восстановление дерева категорий,
     * тип товаров и прочее, чего в новом сайте нет, не переносятся. Автозагрузка при переносе не включается.
     * Принимает путь к файлу, а не его текст: в тексте — ключ API, а строковый аргумент функции, которая может бросить
     * исключение (ошибка базы при записи), попал бы в трассировку (PHP 8.1 не знает #[\SensitiveParameter]).
     * @return array{0: array, 1: string[]} [перенесённые настройки => значение (ключ скрыт), не перенесённые переменные]
     */
    public static function importOldConfig(string $path): array
    {
        $php = (string) @file_get_contents($path);
        $vars = [];
        if (preg_match_all('/^\s*\$(\w+)\s*=\s*(\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\]|\\\\.)*"|[^;]*?)\s*;/m', $php, $m, PREG_SET_ORDER)) {
            foreach ($m as $x) {
                $v = trim($x[2]);
                if ($v !== '' && ($v[0] === "'" || $v[0] === '"')) $v = str_replace(["\\'", '\\"', '\\\\'], ["'", '"', '\\'], substr($v, 1, -1));
                elseif (strtolower($v) === 'true') $v = '1';
                elseif (strtolower($v) === 'false') $v = '0';
                $vars[$x[1]] = $v;
            }
        }
        $map = [
            'start_from_int' => 'window_from', 'start_to_int' => 'window_to', 'start_period_real' => 'period', 'token' => 'api_key',
            'save_log_int' => 'log_days', 'skip_manufacturers_textarea' => 'skip_brands', 'skip_category_textarea' => 'skip_categories',
            'replace_all_products_bool' => 'full_update', 'add_new_products_bool' => 'add_new', 'add_new_products_withoutimage_bool' => 'add_without_photo',
            'change_product_name_bool' => 'update_name', 'change_product_category_bool' => 'update_category', 'change_product_attributes_bool' => 'update_features',
            'change_product_description_bool' => 'update_description', 'attribute_textarea' => 'attributes',
            'product_meta_title' => 'meta_title', 'product_meta_description' => 'meta_description', 'product_meta_keyword' => 'meta_keywords',
            'test_photo_bool' => 'photo_check_file', 'update_photos_bool' => 'photo_replace', 'new_width_int' => 'photo_width',
            'new_height_int' => 'photo_height', 'new_quality_int' => 'photo_quality',
        ];
        $set = []; $machine = [];
        foreach ($map as $old => $new) {
            if (!array_key_exists($old, $vars)) continue;
            $v = (string) $vars[$old];
            if (in_array($new, ['skip_brands', 'skip_categories', 'attributes'], true)) $v = trim(str_replace('/r/n', "\n", $v));
            if (in_array($new, ['window_from', 'window_to', 'period', 'log_days', 'photo_width', 'photo_height', 'photo_quality'], true)) $v = (string) (int) round((float) $v);
            if ($new === 'api_key' && ($v === '' || !preg_match('/^[\x21-\x7E]{8,200}$/', $v))) continue;
            if ($new === 'attributes' && self::attributes($v)[1]) continue;              // характеристик нет на сайте — оставить свои
            // «машинные» мета старого загрузчика — не переносим: на новом сайте title/description строят SEO-шаблоны (SeoFix их и так очищает)
            if (str_starts_with($new, 'meta_') && in_array($v, ['{name}', 'купить {name} в Одессе', ''], true)) { $machine[] = $old; continue; }
            $set[$new] = $v;
        }
        if (array_key_exists('s_category_allow_filter_bool', $vars)) $set['category_filter'] = $vars['s_category_allow_filter_bool'] === '1' ? 'always' : 'empty';
        $all = Settings::all();
        foreach ($set as $k => $v) if ($k !== 'api_key' && ($all['jonggolf.' . $k] ?? null) !== $v) Settings::set('jonggolf.' . $k, $v);
        if (isset($set['api_key']) && ($all['jonggolf.api_key'] ?? null) !== $set['api_key']) self::storeKey(['key' => $set['api_key']]);   // ключ — не аргументом-строкой
        $skipped = array_values(array_merge(array_diff(array_keys($vars), array_keys($map), ['s_category_allow_filter_bool']),
            array_map(static fn($m) => $m . ' (шаблон «{name}» — на сайте SEO-шаблоны)', $machine)));
        if (isset($set['api_key'])) $set['api_key'] = '(задан)';
        return [$set, $skipped];
    }

    // ================================================================== связи

    /** Код товара у поставщика для поиска: без пробелов, верхний регистр, кириллические С/А/В/Е/Н/К/М/О/Р/Т/Х → латиница */
    public static function code(string $aid): string
    {
        $s = mb_strtoupper((string) preg_replace('/\s+/u', '', $aid));
        return strtr($s, ['А' => 'A', 'В' => 'B', 'С' => 'C', 'Е' => 'E', 'Н' => 'H', 'К' => 'K', 'М' => 'M', 'О' => 'O', 'Р' => 'P', 'Т' => 'T', 'Х' => 'X', 'І' => 'I']);
    }

    /**
     * Пересобрать связи с товарами после переноса со старого сайта (bin/import-webasyst.php): товары с supplier = 'jonggolf'
     * названы старым загрузчиком «{категория} {бренд} {код цвета}» — код = последнее слово. @return int связей
     */
    public static function relink(): int
    {
        self::ensureSchema();
        $db = App::db();
        $rows = [];
        $now = date('Y-m-d H:i:s');
        foreach ($db->all("SELECT id, name, status, supplier_code FROM products WHERE supplier = ? ORDER BY id", [self::CODE]) as $p) {
            $parts = preg_split('/\s+/u', trim((string) $p['name'])) ?: [];
            $code = self::code((string) end($parts));
            if ($code === '' || isset($rows[$code])) continue;
            $rows[$code] = ['supplier' => self::CODE, 'code' => $code, 'product_id' => (int) $p['id'], 'ext_id' => $p['supplier_code'],
                'hidden' => (int) $p['status'] === 0 ? 1 : 0, 'created_at' => $now, 'updated_at' => null];
        }
        $db->transaction(static function ($db) use ($rows): void {
            $db->query('DELETE FROM supplier_links WHERE supplier = ?', [self::CODE]);
            $db->insertMany('supplier_links', array_values($rows), true, 500);
        });
        return count($rows);
    }

    // ================================================================== запуски

    public static function emptyStats(): array
    {
        return parent::emptyStats() + ['colors' => 0, 'confirmed' => 0, 'queue' => null, 'photo_robots' => 0];
    }

    /**
     * Начать запуск. $o: src — web | pages (сайт бренда) | api | file (API); по умолчанию — источник из настроек; origin —
     * admin | cron | cli; user_id; limit — не больше N товаров поставщика (сайт бренда — цветов).
     * Сайт бренда: dir — папка сохранённых страниц для src = pages (папка прошлого запуска с list-1.html или общий кэш ответов
     * SiteClient); cache — сквозной кэш для src = web (проверки: повторно не скачивать); aids — только эти цвета (проверка записи).
     * API: files — страницы (JSON ответа export_product) для src = file; dict_file — справочники для src = file (иначе
     * сохранённая копия последнего ответа). Боевой запуск по API — только при источнике «API» и включённой автозагрузке; по файлу
     * и по сохранённым страницам — только из CLI (проверка записи на своих данных), бренду ничего не отправляется.
     */
    public static function start(string $kind, array $o = []): int
    {
        self::ensureSchema();
        if (!isset(self::KINDS[$kind]) || $kind === 'test') throw new \InvalidArgumentException('Неизвестный вид запуска');
        $src = (string) ($o['src'] ?? self::liveSrc());
        if (in_array($src, ['web', 'pages'], true)) return self::startSite($kind, $src, $o);
        $src = $src === 'file' ? 'file' : 'api';
        $origin = in_array($o['origin'] ?? '', ['cron', 'cli'], true) ? $o['origin'] : 'admin';
        if ($kind === 'run' && $src === 'api' && self::siteSource()) throw new \RuntimeException('Источник в настройках — сайт бренда: запись по API не выполняется (переключите источник на «API» или сделайте пробный прогон).');
        if ($kind === 'run' && $src === 'api' && !self::enabled()) throw new \RuntimeException('Автозагрузка выключена — включите её в настройках (после отключения cron старого сайта) или сделайте пробный прогон.');
        if ($kind === 'run' && $src === 'file' && $origin !== 'cli') throw new \RuntimeException('Запись по сохранённому файлу — только из командной строки (bin/supplier-jonggolf.php --write).');
        self::assertIdle();
        if ($src === 'api' && self::cfg('api_key') === '') throw new \RuntimeException('Не задан ключ API.');
        $files = [];
        if ($src === 'file') {
            foreach ((array) ($o['files'] ?? []) as $f) if (is_file((string) $f)) $files[] = (string) $f;
            if (!$files) throw new \RuntimeException('Нет файла с товарами поставщика для прогона.');
        }
        $db = App::db();
        $api = new JongGolfApi($kind === 'run' && $src === 'api');
        $callback = $kind === 'run' && $src === 'api' && $api->canChangeQueue();
        $state = [
            'phase' => 'dict', 'page' => 0, 'pos' => 0, 'files' => 0, 'complete' => false,
            'callback' => $callback, 'clean' => $callback && self::on('full_update'),
            'limit' => isset($o['limit']) && (int) $o['limit'] > 0 ? (int) $o['limit'] : null,
            'dict_file' => isset($o['dict_file']) && is_file((string) $o['dict_file']) ? (string) $o['dict_file'] : null,
            'debug' => App::isDebug(),
        ];
        // «уже идёт?» и создание запуска — под блокировкой запусков поставщика (createRun): очередь поставщика одна —
        // два запуска разделили бы её, и каждый увидел бы только часть выгрузки
        $id = self::createRun($kind, $src, $origin, !empty($o['user_id']) ? (int) $o['user_id'] : null, $state);
        $dir = self::dir($id);
        if ($src === 'file') {
            $n = 0;
            foreach ($files as $f) {
                foreach (self::splitPages((string) file_get_contents($f)) as $page) file_put_contents($dir . '/page-' . ++$n . '.json', $page);
            }
            $state['files'] = $n;
            $db->update('supplier_runs', ['state' => json_encode($state)], 'id = ?', [$id]);
        }
        $mode = match (true) {
            $kind === 'dry' => 'пробный прогон: в каталог ничего не записывается, поставщику ничего не подтверждается',
            $src === 'file' => 'запись по сохранённому файлу (поставщику ничего не отправляется)',
            $callback => 'боевой запуск' . ($state['clean'] ? ', полное обновление (clean_export)' : ''),
            default => 'запись без подтверждения поставщику (' . (App::isDebug() ? 'режим разработки' : 'автозагрузка выключена') . ') — только первая страница очереди',
        };
        self::log($id, 'info', 'Загрузчик стартовал ' . date('d.m.Y H:i:s') . ' — источник: API; ' . $mode . ($state['limit'] ? ', не больше ' . $state['limit'] . ' товаров' : '')
            . ($src === 'file' ? ', страниц в файле: ' . $state['files'] : ''));
        self::flushLog();
        return $id;
    }

    /** Начать запуск с сайта бренда (см. start) */
    private static function startSite(string $kind, string $src, array $o): int
    {
        $origin = in_array($o['origin'] ?? '', ['cron', 'cli'], true) ? $o['origin'] : 'admin';
        if ($kind === 'run' && !self::siteSource()) throw new \RuntimeException('Источник в настройках — API: запись с сайта бренда не выполняется (переключите источник на «сайт бренда» или сделайте пробный прогон).');
        if ($kind === 'run' && $src === 'web' && $origin === 'cron' && !self::enabled()) throw new \RuntimeException('Загрузка Jong•Golf выключена — запуск по расписанию не выполняется.');
        if ($kind === 'run' && $src === 'pages' && $origin !== 'cli') throw new \RuntimeException('Запись по сохранённым страницам — только из командной строки (bin/supplier-jonggolf.php --write).');
        if ($kind === 'run' && self::markup() === null) throw new \RuntimeException('Наценка не задана — запись товаров невозможна (цены бренда закупочные). Укажите коэффициент в настройках.');
        self::assertIdle();
        $dir = null;
        if ($src === 'pages') {
            $dir = rtrim(str_replace('\\', '/', (string) ($o['dir'] ?? '')), '/');
            if ($dir === '' || !is_dir($dir)) throw new \RuntimeException('Нет папки с сохранёнными страницами бренда: ' . $dir);
            if (!is_file($dir . '/list-1.html') && !is_file($dir . '/' . SiteClient::cacheName(self::listUrl()))) {
                throw new \RuntimeException('В папке ' . $dir . ' нет сохранённого списка каталога бренда (list-1.html прошлого запуска или ответ ' . self::listUrl() . ' в кэше).');
            }
        }
        $aids = array_values(array_unique(array_filter(array_map([self::class, 'code'], (array) ($o['aids'] ?? [])))));
        $state = [
            'site' => true, 'phase' => 'start', 'mode' => 'full', 'complete' => false, 'page' => 0, 'pages' => 0, 'paged' => false, 'total' => null,
            'pos' => 0, 'count' => 0, 'dir' => $dir, 'cache' => $src === 'web' && !empty($o['cache']) ? (string) $o['cache'] : null,
            'limit' => isset($o['limit']) && (int) $o['limit'] > 0 ? (int) $o['limit'] : null, 'aids' => $aids ?: null,
            'fails' => 0, 'retry_at' => 0, 'requests' => 0, 'debug' => App::isDebug(),
        ];
        $id = self::createRun($kind, $src, $origin, !empty($o['user_id']) ? (int) $o['user_id'] : null, $state);
        $m = self::markup();
        $what = match (true) {
            $kind === 'dry' => 'пробный прогон: в каталог ничего не записывается',
            $src === 'pages' => 'запись по сохранённым страницам (сеть не используется)',
            default => 'боевой запуск',
        };
        self::log($id, 'info', 'Загрузчик стартовал ' . date('d.m.Y H:i:s') . ' — источник: сайт бренда; ' . $what . '; ' . ($src === 'pages'
                ? 'сохранённые страницы: ' . $dir
                : 'список ' . self::listUrl() . ', пауза ' . self::cfg('delay') . ' с, не больше ' . (int) self::cfg('max_requests') . ' запросов, новых — ' . ((int) self::cfg('max_new') ?: 'без ограничения'))
            . ($state['limit'] ? ', не больше ' . $state['limit'] . ' цветов' : '') . ($aids ? ', только цвета ' . implode(', ', $aids) : '') . '. '
            . ($m ? 'Наценка: ×' . $m['k'] . ($m['plus'] ? ' + ' . $m['plus'] . ' грн' : '') . ', округление ' . self::ROUND_STEPS[(string) $m['step']] . ($m['step'] ? ' (' . self::ROUND_MODES[$m['mode']] . ')' : '')
                : 'Наценка НЕ задана — в отчёте цены бренда (закупочные).'));
        self::flushLog();
        return $id;
    }

    /** Адрес списка: весь каталог в наличии (?limit=1000) или страница $page запасного пути (по 100) */
    public static function listUrl(int $page = 0): string
    {
        return self::SITE . self::LIST_PATH . ($page > 0 ? '?limit=' . self::PAGE_LIMIT . '&page=' . $page : '?limit=' . self::LIST_LIMIT);
    }

    /** Клиент запуска с сайта бренда: пауза и лимит из настроек, папка сохранённых ответов (pages) или сквозной кэш (web) */
    private static function client(array $st, string $src): JongGolfClient
    {
        $c = new JongGolfClient((float) (Importer::num(self::cfg('delay')) ?? 2.0), max(20, (int) self::cfg('max_requests')), $src === 'pages' ? ($st['dir'] ?? null) : null, $st['cache'] ?? null);
        $c->setUsed((int) ($st['requests'] ?? 0));
        return $c;
    }

    /** Ошибка шага: ключ API замаскирован; ошибка обмена с поставщиком (JongGolfError, SupplierError) — не в журнал ошибок сайта */
    protected static function failure(\Throwable $e): array
    {
        $msg = $e instanceof JongGolfError || $e instanceof \RuntimeException ? $e->getMessage() : 'Ошибка: ' . $e->getMessage();
        return [(new JongGolfApi())->mask($msg), $e instanceof JongGolfError || $e instanceof SupplierError];
    }

    protected static function stopNote(array $run): string
    {
        if (!empty($run['state']['site'])) return parent::stopNote($run);
        return !empty($run['state']['callback']) ? ' — текущая страница поставщику не подтверждена, её товары придут снова' : '';
    }

    /** Машина состояний запуска (см. описание класса) */
    protected static function advance(array &$run, float $deadline): void
    {
        if (!empty($run['state']['site'])) { self::advanceSite($run, $deadline); return; }
        $id = (int) $run['id'];
        $st = &$run['state'];
        $dry = $run['kind'] === 'dry';
        $api = new JongGolfApi($run['kind'] === 'run' && $run['src'] === 'api');
        unset($st['wait']);
        while (microtime(true) < $deadline) {
            // остановлен во время шага (stop) — ни порций, ни подтверждения поставщику, ни скрытия отсутствующих, ни статуса
            if (self::stopped($id)) {
                self::save($run);
                self::dropPhotoCache($id);
                return;
            }
            switch ($st['phase']) {
                case 'dict':
                    $dict = self::loadDictionary($run, $api);
                    $a = $dict['answer'];
                    $cats = 0;
                    foreach ((array) ($a['seasons'] ?? []) as $s) $cats += count((array) ($s['categorys'] ?? []));
                    self::log($id, 'info', 'Справочники получены: сезонов ' . count((array) ($a['seasons'] ?? [])) . ', категорий ' . $cats . ', брендов ' . count((array) ($a['brand'] ?? []))
                        . ($run['src'] === 'file' && empty($st['dict_file']) ? ' (сохранённая копия от ' . date('d.m.Y H:i', (int) @filemtime(self::dir() . '/product_setting.json')) . ')' : ''));
                    $map = JongGolfMap::count();
                    if (!$map['total']) throw new \RuntimeException('Таблица соответствия категорий пуста — загрузите её на вкладке «Категории» (файл table/category_table_jonggolf.xls старого загрузчика).');
                    self::log($id, 'info', 'В таблице соответствия сочетаний: ' . $map['total'] . ($map['unresolved'] ? ', без категории сайта: ' . $map['unresolved'] : ''));
                    [, $attrErr] = self::attributes();
                    if ($attrErr) throw new \RuntimeException('Характеристики в настройках: ' . implode('; ', $attrErr));
                    $st['phase'] = 'page';
                    break;

                case 'page':
                    $n = (int) $st['page'] + 1;
                    $file = self::dir($id) . '/page-' . $n . '.json';
                    if ($run['src'] === 'file') {
                        if ($n > (int) $st['files']) { $st['complete'] = true; $st['phase'] = 'finish'; break; }
                        $resp = json_decode((string) file_get_contents($file), true);
                    } elseif (is_file($file)) {                              // страница уже получена до обрыва шага
                        $resp = json_decode((string) file_get_contents($file), true);
                    } else {
                        $clean = !empty($st['clean']) && $n === 1;
                        $resp = $api->page($clean);
                        file_put_contents($file, (string) $resp['_raw']);
                        unset($resp['_raw']);
                        self::log($id, 'info', 'Запрос страницы ' . $n . ($clean ? ' (clean_export — весь каталог заново)' : '') . ': ' . number_format((int) ($resp['_bytes'] ?? 0), 0, '', ' ') . ' байт');
                    }
                    if (!is_array($resp)) throw new \RuntimeException('Страница ' . $n . ': ответ поставщика не читается (не JSON)');
                    if (($resp['type'] ?? '') === 'error') {
                        // type=error — так поставщик сообщает, что очередь пройдена (старый загрузчик: молча return). Без clean_export
                        // (пробный прогон, режим разработки) очередь между запусками обычно пуста уже на первой странице — это не авария;
                        // ошибка — только первая страница полной выгрузки (clean_export): весь каталог пустым не бывает
                        $msg = (string) ($resp['msg'] ?? '');
                        if ($n === 1 && !empty($st['clean'])) throw new JongGolfError('Поставщик ответил ошибкой: ' . ($msg !== '' ? $api->mask($msg) : 'type=error'));
                        self::log($id, 'info', ($n === 1 ? 'Очередь поставщика пуста — товаров для выгрузки нет' : 'Поставщик сообщил об окончании очереди')
                            . ($msg !== '' ? ': ' . $api->mask($msg) : ''));
                        $st['complete'] = true; $st['phase'] = 'finish';
                        break;
                    }
                    if (($resp['type'] ?? 'success') !== 'success') throw new JongGolfError('Страница ' . $n . ': неожиданный ответ поставщика (type=' . (string) ($resp['type'] ?? '') . ')');
                    $answer = is_array($resp['answer'] ?? null) ? $resp['answer'] : [];
                    if ($run['src'] === 'api' && $n === 1) $run['stats']['queue'] = count($answer);
                    if (!$answer) {                                          // старый загрузчик: страница из ≤ 1 товара — конец (ошибка); здесь — только пустая
                        self::log($id, 'info', 'Страница ' . $n . ': товаров нет — очередь поставщика пройдена');
                        $st['complete'] = true; $st['phase'] = 'finish';
                        break;
                    }
                    $run['stats']['pages']++;
                    self::log($id, 'info', 'Обработка товаров со страницы ' . $n . ': всего товаров на странице ' . count($answer));
                    $st['page'] = $n; $st['pos'] = 0; $st['count'] = count($answer);
                    $st['phase'] = 'items';
                    break;

                case 'items':
                    $resp = json_decode((string) file_get_contents(self::dir($id) . '/page-' . (int) $st['page'] . '.json'), true);
                    $answer = is_array($resp['answer'] ?? null) ? $resp['answer'] : [];
                    $list = [];
                    foreach ($answer as $k => $p) $list[] = [(string) $k, is_array($p) ? $p : []];
                    $dict = self::loadDictionary($run, $api);
                    $sync = new JongGolfSync($id, $dict['answer'], $dry, $run['stats']);
                    $left = $st['limit'] !== null ? max(0, (int) $st['limit'] - (int) $run['stats']['products']) : null;
                    $st['pos'] = $sync->process($list, (int) $st['pos'], $deadline, $left, static function (int $pos) use (&$run, &$st, $id): bool {
                        $st['pos'] = $pos;
                        self::save($run);
                        self::flushLog();
                        return !self::stopped($id);                          // остановлен — следующую порцию не начинать
                    });
                    if ($sync->deferred()) {
                        // идёт полная перестройка индекса каталога: порция не записана (фото, если их успели скачать, лежат
                        // в папке запуска — повтор их не качает); шаг заканчивается, следующий — через 5 с (progress: wait)
                        if (empty($st['waits'])) self::log($id, 'info', 'Идёт перестройка индекса каталога — запись порции отложена до её окончания');
                        $st['waits'] = (int) ($st['waits'] ?? 0) + 1;
                        $st['wait'] = 1;
                        self::save($run);
                        self::flushLog();
                        return;
                    }
                    if ($st['limit'] !== null && (int) $run['stats']['products'] >= (int) $st['limit']) {
                        self::log($id, 'info', 'Достигнут лимит ' . $st['limit'] . ' товаров — остальное не обрабатывается, скрытие отсутствующих не выполняется');
                        $st['phase'] = 'finish';
                        break;
                    }
                    if ($st['pos'] >= count($list)) $st['phase'] = 'confirm';
                    break;

                case 'confirm':
                    $resp = json_decode((string) file_get_contents(self::dir($id) . '/page-' . (int) $st['page'] . '.json'), true);
                    $keys = array_keys(is_array($resp['answer'] ?? null) ? $resp['answer'] : []);
                    if (!empty($st['callback']) && !$dry && $run['src'] === 'api') {
                        // все ключи страницы, включая пропущенные, — как старый загрузчик (иначе поставщик отдавал бы их снова)
                        $msg = $api->confirm($keys);
                        $run['stats']['confirmed'] += count($keys);
                        self::log($id, 'info', 'Получено подтверждение: ' . mb_substr($api->mask($msg), 0, 300));
                        $st['phase'] = 'page';
                    } elseif ($run['src'] === 'file') {
                        $st['phase'] = 'page';
                    } else {
                        self::log($id, 'info', 'Страница поставщику не подтверждается (' . ($dry ? 'пробный прогон' : ($st['debug'] ? 'режим разработки' : 'автозагрузка выключена'))
                            . ') — следующие страницы очереди без подтверждения недоступны, обработана только эта');
                        $st['phase'] = 'finish';
                    }
                    break;

                case 'finish':
                    $sync = new JongGolfSync($id, [], $dry, $run['stats']);
                    $sync->finish(!empty($st['complete']), static fn(): bool => !self::stopped($id));
                    $s = $run['stats'];
                    self::log($id, 'info', '==========');
                    self::log($id, 'info', ($dry ? 'Будет добавлено: ' : 'Количество добавленных товаров: ') . $s['created']);
                    self::log($id, 'info', ($dry ? 'Будет изменено: ' : 'Количество изменённых товаров: ') . $s['updated'] . ' (без изменений: ' . $s['same'] . ')');
                    self::log($id, 'info', ($dry ? 'Будет скрыто: ' : 'Скрыто: ') . ($s['hidden_color'] + $s['hidden_missing']) . ' (цвет закончился: ' . $s['hidden_color'] . ', нет у поставщика: ' . $s['hidden_missing'] . ')');
                    self::log($id, 'info', 'Пропущено цветов: ' . $s['skipped'] . self::skipText($s['skip']));
                    self::log($id, 'info', 'Работа завершена ' . date('d.m.Y H:i:s'));
                    self::save($run);
                    if (!self::finishRun($id, 'done')) self::log($id, 'warn', 'Запуск остановлен во время завершения — статус «Остановлен» сохранён');
                    self::flushLog();
                    self::dropPhotoCache($id);
                    self::cleanup();
                    return;
            }
            self::save($run);
            self::flushLog();
        }
    }

    /** Справочники: для API — запрос (один раз за запуск, копия в папке запуска), для файла — dict_file или сохранённая копия */
    private static function loadDictionary(array $run, JongGolfApi $api): array
    {
        static $memo = [];
        $id = (int) $run['id'];
        if (isset($memo[$id])) return $memo[$id];
        $file = self::dir($id) . '/product_setting.json';
        if (is_file($file)) {
            $d = json_decode((string) file_get_contents($file), true);
        } elseif ($run['src'] === 'api') {
            $d = $api->dictionary();
            $raw = (string) $d['_raw'];
            unset($d['_raw'], $d['_bytes']);
            file_put_contents($file, $raw);
            @file_put_contents(self::dir() . '/product_setting.json', $raw);   // последняя копия — для прогонов по файлу
        } else {
            $src = $run['state']['dict_file'] ?? null;
            $src = $src && is_file($src) ? $src : self::dir() . '/product_setting.json';
            if (!is_file($src)) throw new \RuntimeException('Нет справочников поставщика: нажмите «Проверить подключение» (сохранит их) или загрузите product_setting.json из tmp/ старого загрузчика.');
            copy($src, $file);
            $d = json_decode((string) file_get_contents($file), true);
        }
        if (!is_array($d) || ($d['type'] ?? '') !== 'success' || !is_array($d['answer']['seasons'] ?? null) || !is_array($d['answer']['gender'] ?? null)) {
            throw new \RuntimeException('Справочники поставщика не читаются (нет сезонов или пола)');
        }
        return $memo[$id] = $d;
    }

    /** Файл с товарами → страницы: ответ export_product целиком, массив таких ответов или просто answer */
    private static function splitPages(string $json): array
    {
        $j = json_decode($json, true);
        if (!is_array($j)) throw new \RuntimeException('Файл с товарами — не JSON');
        if (isset($j['type']) || isset($j['answer'])) return [json_encode($j, JSON_UNESCAPED_UNICODE)];
        if (array_is_list($j) && $j && is_array($j[0]) && (isset($j[0]['type']) || isset($j[0]['answer']))) {
            return array_map(static fn($p) => json_encode($p, JSON_UNESCAPED_UNICODE), $j);
        }
        return [json_encode(['type' => 'success', 'answer' => $j], JSON_UNESCAPED_UNICODE)];
    }

    // ================================================================== сайт бренда

    /** Машина состояний запуска с сайта бренда (см. описание класса) */
    private static function advanceSite(array &$run, float $deadline): void
    {
        $id = (int) $run['id'];
        $st = &$run['state'];
        $dry = $run['kind'] === 'dry';
        unset($st['wait']);
        // наценку сняли во время запуска — дальше товары записались бы по закупочной цене: запуск останавливается с ошибкой
        if (!$dry && self::markup() === null) throw new SupplierError('Наценка не задана (снята во время запуска) — запись остановлена, чтобы не ставить на сайт закупочные цены. Укажите коэффициент и запустите снова.', 'markup');
        if ((int) ($st['retry_at'] ?? 0) > time()) { $st['wait'] = 1; return; }   // пауза после 429/5xx — шаг ничего не делает
        $client = self::client($st, (string) $run['src']);
        $sync = null;
        try {
            while (microtime(true) < $deadline) {
                if (self::stopped($id)) {
                    self::save($run);
                    self::dropPhotoCache($id);
                    return;
                }
                switch ($st['phase']) {
                    case 'start':
                        $seed = JongGolfMap::seedSections();
                        $sec = JongGolfMap::sectionCount();
                        $map = JongGolfMap::count();
                        self::log($id, 'info', 'Категории: сочетаний в таблице ' . $map['total'] . ($map['unresolved'] ? ' (без категории сайта ' . $map['unresolved'] . ')' : '')
                            . '; разделов бренда ' . $sec['cats'] . ' (загружаются ' . $sec['active'] . ($sec['unresolved'] ? ', без категории сайта ' . $sec['unresolved'] : '') . ')'
                            . ($seed['added'] ? ' — добавлено из черновика ' . $seed['added'] : ''));
                        [, $attrErr] = self::attributes();
                        if ($attrErr) throw new \RuntimeException('Характеристики в настройках: ' . implode('; ', $attrErr));
                        if ($run['src'] === 'pages' && is_file($st['dir'] . '/list-1.html')) {
                            // папка прошлого запуска: его списки и карточки — как будто получены этим запуском (сеть не нужна)
                            foreach (array_merge(glob($st['dir'] . '/list-*.html') ?: [], glob($st['dir'] . '/card-*.json') ?: []) as $f) @copy($f, self::dir($id) . '/' . basename($f));
                        }
                        if ($run['src'] === 'web' && !$client->allowed(self::listUrl())) throw new SupplierError('robots.txt бренда запрещает список ' . self::LIST_PATH . ' — загрузка невозможна', 'robots');
                        // фото — на public.jonggolf.com; его robots.txt (как у www) закрывает все пути с «/product», и /files/products/ тоже:
                        // такие фото не запрашиваются (новый цвет без фото — только с «Добавлять без фото»), разрешит бренд — заработает само
                        $st['photos_ok'] = $client->allowed(self::PHOTO_PROBE);
                        if (!$st['photos_ok']) {
                            $run['stats']['warnings']++;
                            self::log($id, 'warn', 'Фото бренда закрыты robots.txt (' . parse_url(self::PHOTO_PROBE, PHP_URL_HOST) . ': правило «Disallow: */product*» закрывает /files/products/) — фото не запрашиваются'
                                . (self::on('add_without_photo') ? '; новые цвета добавляются без фото' : '; новые цвета без фото не добавляются (настройка «Добавлять без фото»)')
                                . '. Чтобы фото загружались, бренду нужно разрешить их в robots.txt (например, «User-agent: TomobuvLoader» + «Allow: /files/products/»).');
                        }
                        $st['phase'] = 'list';
                        break;

                    case 'list':
                        self::nextList($run, $client);
                        break;

                    case 'items':
                        $items = self::siteItems($id);
                        $sync ??= new JongGolfSiteSync($id, $dry, $run['stats'], $client);
                        $left = $st['limit'] !== null ? max(0, (int) $st['limit'] - (int) $run['stats']['products']) : null;
                        $st['pos'] = $sync->process($items, (int) $st['pos'], [], $deadline, $left, static function (int $pos) use (&$run, &$st, $id, $client): bool {
                            $st['pos'] = $pos;
                            $st['requests'] = $client->used();
                            $run['stats']['requests'] = $client->used();
                            self::save($run);
                            self::flushLog();
                            return !self::stopped($id);
                        });
                        $st['fails'] = 0;
                        if ($sync->deferred()) {
                            if (empty($st['waits'])) self::log($id, 'info', 'Идёт перестройка индекса каталога или карточки и фото не уложились в шаг — запись порции продолжится следующим шагом');
                            $st['waits'] = (int) ($st['waits'] ?? 0) + 1;
                            $st['wait'] = $sync->deferredBy() === 'lock' ? 1 : 0;
                            $st['requests'] = $client->used();
                            $run['stats']['requests'] = $client->used();
                            self::save($run);
                            self::flushLog();
                            return;
                        }
                        if ($st['limit'] !== null && (int) $run['stats']['products'] >= (int) $st['limit'] && $st['pos'] < count($items)) {
                            self::log($id, 'info', 'Достигнут лимит ' . $st['limit'] . ' цветов — остальное не обрабатывается, «нет в наличии» и скрытие не выполняются');
                            $st['complete'] = false;
                            $st['phase'] = 'finish';
                            break;
                        }
                        if ($st['pos'] >= count($items)) $st['phase'] = 'finish';
                        break;

                    case 'finish':
                        $sync ??= new JongGolfSiteSync($id, $dry, $run['stats'], $client);
                        // скрытие и «нет в наличии» — только полный проход с сайта (сохранённые страницы — только в пробном прогоне: покажет, что будет)
                        $sync->finish(!empty($st['complete']) && empty($st['aids']) && ($run['src'] === 'web' || $dry), static fn(): bool => !self::stopped($id));
                        $s = $run['stats'];
                        self::log($id, 'info', '==========');
                        self::log($id, 'info', 'Запросов к сайту бренда: ' . $s['requests'] . ', страниц списка: ' . $s['pages'] . ', цветов в списке: ' . (int) $st['count']
                            . ($st['total'] !== null ? ' (в наличии по фасетам — ' . (int) $st['total'] . ')' : ''));
                        self::log($id, 'info', ($dry ? 'Будет добавлено: ' : 'Добавлено товаров: ') . $s['created'] . ' (фото: ' . $s['photos'] . ')');
                        self::log($id, 'info', ($dry ? 'Будет изменено: ' : 'Изменено товаров: ') . $s['updated'] . ' (без изменений: ' . $s['same'] . ')' . self::foundText($s['found']));
                        self::log($id, 'info', 'Найдено на сайте, но не взято под загрузчик: ' . $s['existing']);
                        self::log($id, 'info', ($dry ? 'Будет «нет в наличии»: ' : '«Нет в наличии» (нет в списке бренда): ') . (int) ($s['out'] ?? 0)
                            . '; ' . ($dry ? 'будет скрыто: ' : 'скрыто: ') . $s['hidden_missing'] . ' (нет у бренда ' . (int) self::cfg('missing_runs') . ' полных проходов подряд)');
                        self::log($id, 'info', 'Пропущено: ' . $s['skipped'] . self::skipText($s['skip']));
                        if (!empty($s['photo_robots']) || !empty($s['photo_robots_extra'])) {
                            self::log($id, 'warn', 'Фото закрыты robots.txt бренда: не запрошено главных фото — ' . (int) ($s['photo_robots'] ?? 0) . ' (новые цвета и найденные без фото)'
                                . (!empty($s['photo_robots_extra']) ? ', доп. фото — ' . (int) $s['photo_robots_extra'] : ''));
                        }
                        self::log($id, 'info', 'Работа завершена ' . date('d.m.Y H:i:s'));
                        self::save($run);
                        if (!self::finishRun($id, 'done')) self::log($id, 'warn', 'Запуск остановлен во время завершения — статус «Остановлен» сохранён');
                        self::flushLog();
                        self::dropPhotoCache($id);
                        self::cleanup();
                        return;
                }
                $st['requests'] = $client->used();
                $run['stats']['requests'] = $client->used();
                self::save($run);
                self::flushLog();
            }
        } catch (SupplierError $e) {
            self::onSiteError($run, $e, $client);                          // 429/5xx — пауза, лимит — проход неполный, прочее — ошибка запуска
        }
    }

    /**
     * Список каталога: весь каталог в наличии одним запросом (list-1.html); полнота — число цветов = сумме фасетов пола.
     * Меньше (бренд ограничил limit) — запасной путь: страницы по 100 (list-pK.html). Страница без товаров и без «товаров не найдено» —
     * сменился шаблон: ошибка, в каталог ничего не пишется. Все страницы прочитаны — цвета (без повторов) → items.json, фаза items.
     */
    private static function nextList(array &$run, JongGolfClient $client): void
    {
        $id = (int) $run['id'];
        $st = &$run['state'];
        $n = (int) $st['page'] + 1;
        $name = $st['paged'] ? 'list-p' . $n . '.html' : 'list-1.html';
        $url = $st['paged'] ? self::listUrl($n) : self::listUrl();
        $file = self::dir($id) . '/' . $name;
        if (is_file($file)) {
            $html = (string) file_get_contents($file);                          // получена до обрыва шага (или из папки прошлого запуска)
        } else {
            $r = $client->get($url);
            $st['fails'] = 0;
            if (!empty($r['missing'])) throw new SupplierError('Страницы ' . $url . ' нет среди сохранённых ответов — загрузка остановлена, в каталог ничего не записано', 'layout');
            if ($r['code'] !== 200) throw new SupplierError('Страница ' . $url . ': ответ ' . $r['code'], $r['code'] === 404 ? 'layout' : 'retry', $r['code']);
            $html = $r['body'];
            file_put_contents($file, $html);
        }
        $page = JongGolfParser::listPage($html);
        if (!$page['items'] && !$page['empty']) throw new SupplierError('Страница ' . $url . ' не похожа на список товаров (нет блоков товаров — сменился шаблон сайта бренда?) — загрузка остановлена, в каталог ничего не записано, товары не скрываются', 'layout');
        $run['stats']['pages']++;
        if (!$st['paged']) {
            $st['total'] = $page['total'];
            self::log($id, 'info', 'Список ' . $url . ': цветов ' . count($page['items']) . ($page['total'] !== null ? ', в наличии по фасетам пола — ' . $page['total'] : ', фасетов пола на странице нет'));
            if ($page['total'] !== null && count($page['items']) < (int) $page['total']) {
                $st['paged'] = true;
                $st['pages'] = (int) ceil((int) $page['total'] / self::PAGE_LIMIT);
                $st['page'] = 0;
                $run['stats']['warnings']++;
                self::log($id, 'warn', 'В списке меньше цветов, чем по фасетам (' . count($page['items']) . ' из ' . $page['total'] . ') — бренд не отдал весь каталог одной страницей: читаю по ' . self::PAGE_LIMIT . ' (' . $st['pages'] . ' запросов)');
                return;
            }
            $files = [$name];
        } else {
            $st['page'] = $n;
            self::log($id, 'info', 'Страница ' . $n . ' из ' . $st['pages'] . ': ' . $url . ' — цветов ' . count($page['items']));
            if ($n < (int) $st['pages']) return;
            $files = array_map(static fn($k) => 'list-p' . $k . '.html', range(1, (int) $st['pages']));
        }
        $all = [];
        foreach ($files as $f) foreach (JongGolfParser::listPage((string) @file_get_contents(self::dir($id) . '/' . $f))['items'] as $it) $all[JongGolf::code($it['aid'])] ??= $it;
        $total = $st['total'];
        $st['complete'] = $total !== null && $all && count($all) >= (int) $total;
        if ($total === null) {
            $run['stats']['warnings']++;
            self::log($id, 'warn', 'На странице нет фасетов пола — полноту списка не проверить: проход неполный, «нет в наличии» и скрытие не выполняются');
        } elseif (!$st['complete']) {
            $run['stats']['warnings']++;
            self::log($id, 'warn', 'Список неполный: прочитано ' . count($all) . ' цветов из ' . $total . ' — «нет в наличии» и скрытие не выполняются, цены и новые — по прочитанному');
        }
        if ($st['aids']) {
            // только часть цветов — проход не полный: ничего не скрывает и для расписания не «полная сверка»
            $all = array_intersect_key($all, array_flip($st['aids']));
            $st['complete'] = false;
        }
        $all = array_values($all);
        file_put_contents(self::dir($id) . '/items.json', json_encode($all, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $models = count(array_unique(array_column($all, 'model')));
        self::log($id, 'info', 'Цветов бренда к обработке: ' . count($all) . ' (моделей ' . $models . ')' . ($st['complete'] ? ' — список полный' : ''));
        $st['count'] = count($all);
        $st['pos'] = 0;
        $st['phase'] = 'items';
    }

    /** Папка последнего запуска с сайта бренда, где сохранён список (list-1.html) — для пробного прогона без запросов; null — нет */
    public static function lastSiteDir(): ?string
    {
        foreach (App::db()->col("SELECT id FROM supplier_runs WHERE supplier = ? AND src IN ('web', 'pages') AND kind IN ('run', 'dry') ORDER BY id DESC LIMIT 20", [self::CODE]) as $rid) {
            $d = Importer::dir() . '/' . self::CODE . '/runs/' . (int) $rid;
            if (is_file($d . '/list-1.html')) return $d;
        }
        return null;
    }

    /** Цвета запуска после разбора списка (items.json) */
    private static function siteItems(int $id): array
    {
        static $memo = [];
        if (isset($memo[$id])) return $memo[$id];
        $j = json_decode((string) @file_get_contents(self::dir($id) . '/items.json'), true);
        if (!is_array($j)) throw new \RuntimeException('Нет списка цветов запуска (items.json) — запустите заново');
        $memo = [$id => $j];
        return $j;
    }

    /**
     * Проверка связи: сайт бренда — robots.txt и первая страница каталога (2 запроса, ≈110 КБ); API — только export_product_setting.
     * У поставщика ничего не меняется.
     */
    public static function test(int $userId = 0, string $origin = 'admin'): array
    {
        if (!self::siteSource()) return self::testApi($userId, $origin);
        return self::testRun($userId, $origin, self::client([], 'web'), static function (JongGolfClient $c): string {
            $url = self::SITE . self::LIST_PATH;
            if (!$c->allowed($url) || !$c->allowed(self::listUrl())) throw new SupplierError('robots.txt бренда запрещает список ' . self::LIST_PATH, 'robots');
            $r = $c->get($url);
            if ($r['code'] !== 200) throw new SupplierError('Ответ ' . $r['code'] . ' на ' . $url, 'retry', $r['code']);
            $p = JongGolfParser::listPage($r['body']);
            if (!$p['items']) throw new \RuntimeException('Страница ' . $url . ' получена, но товары не читаются (сменился шаблон сайта бренда?)');
            $f = $p['items'][0];
            return sprintf('каталог бренда: в наличии %s цветов (по фасетам пола), на первой странице %d; первый — %s «%s», ряд %s, пара %s грн, в ящике %d пар. Фото: %s.',
                $p['total'] !== null ? number_format((int) $p['total'], 0, '', ' ') : '?', count($p['items']), $f['aid'], $f['season'] . ' | ' . $f['cat'], $f['size'] ?: '—',
                self::num((float) $f['pair']), (int) $f['inbox'], $c->allowed(self::PHOTO_PROBE) ? 'robots.txt разрешает' : 'закрыты robots.txt бренда (public.jonggolf.com) — не загружаются');
        });
    }

    /** Проверка связи с API: только export_product_setting (ничего у поставщика не меняет); копия справочников сохраняется */
    private static function testApi(int $userId, string $origin): array
    {
        self::ensureSchema();
        $db = App::db();
        $id = $db->insert('supplier_runs', ['supplier' => self::CODE, 'kind' => 'test', 'src' => 'api', 'origin' => $origin, 'status' => 'running',
            'user_id' => $userId ?: null, 'stats' => '{}', 'state' => '{}', 'started_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]);
        $api = new JongGolfApi();
        $t = microtime(true);
        try {
            $d = $api->dictionary();
            @file_put_contents(self::dir() . '/product_setting.json', (string) $d['_raw']);
            $a = $d['answer'];
            $cats = 0;
            foreach ((array) ($a['seasons'] ?? []) as $s) $cats += count((array) ($s['categorys'] ?? []));
            $msg = sprintf('Связь есть (%.1f с): справочники получены — сезонов %d, категорий %d, брендов %d, цветов %d.', microtime(true) - $t,
                count((array) ($a['seasons'] ?? [])), $cats, count((array) ($a['brand'] ?? [])), count((array) ($a['color'] ?? [])));
            self::log($id, 'info', $msg);
            self::flushLog();
            self::finishRun($id, 'done');
            return ['ok' => true, 'message' => $msg];
        } catch (\Throwable $e) {
            $msg = $api->mask($e->getMessage());
            self::log($id, 'error', $msg);
            self::flushLog();
            self::finishRun($id, 'error', mb_substr($msg, 0, 1000));
            return ['ok' => false, 'error' => $msg, 'ip' => $e instanceof JongGolfError && $e->kind === 'ip'];
        }
    }

    /** Подпись идущей фазы */
    protected static function phaseLabel(array $st): string
    {
        if (!empty($st['site'])) {
            return match ($st['phase'] ?? '') {
                'start' => 'Проверки',
                'list' => ($st['paged'] ?? false ? 'Страница списка ' . ((int) ($st['page'] ?? 0) + 1) . ' из ' . (int) ($st['pages'] ?? 0) : 'Список каталога бренда') . ' (всего запросов ' . (int) ($st['requests'] ?? 0) . ')',
                'items' => 'Цвет ' . (int) ($st['pos'] ?? 0) . ' из ' . (int) ($st['count'] ?? 0) . ' (запросов ' . (int) ($st['requests'] ?? 0) . ')',
                'finish' => 'Завершение', default => 'Идёт',
            };
        }
        return match ($st['phase'] ?? '') {
            'dict' => 'Справочники поставщика', 'page' => 'Запрос страницы ' . ((int) ($st['page'] ?? 0) + 1),
            'items' => 'Страница ' . (int) $st['page'] . ': товар ' . (int) $st['pos'] . ' из ' . (int) ($st['count'] ?? 0),
            'confirm' => 'Подтверждение страницы ' . (int) $st['page'], 'finish' => 'Завершение', default => 'Идёт',
        };
    }

    protected static function percent(array $run): ?int
    {
        $st = $run['state'];
        if (empty($st['site'])) return parent::percent($run);
        if ($run['status'] !== 'running') return null;
        // список — первые 10 %, цвета — остальное
        if (in_array($st['phase'] ?? '', ['start', 'list'], true)) return !empty($st['pages']) ? (int) floor((int) ($st['page'] ?? 0) / (int) $st['pages'] * 10) : 0;
        if (($st['phase'] ?? '') === 'items' && !empty($st['count'])) return 10 + (int) floor((int) $st['pos'] / (int) $st['count'] * 89);
        return ($st['phase'] ?? '') === 'finish' ? 99 : 10;
    }

    public static function skipReasons(): array
    {
        return JongGolfSiteSync::SKIP + JongGolfSync::SKIP;
    }

    // ================================================================== как старый загрузчик

    /** Мета-тег по шаблону ({name}); keywords — как старый загрузчик: без ( ) , . * " и через «, » */
    public static function meta(string $tpl, string $name, bool $keywords = false): string
    {
        $v = str_replace('{name}', $name, $tpl);
        if ($keywords) $v = str_replace(' ', ', ', str_replace('  ', ' ', strtr($v, ['(' => ' ', ')' => ' ', ',' => '', '.' => '', '*' => '', '"' => ''])));
        return trim($v);
    }
}
