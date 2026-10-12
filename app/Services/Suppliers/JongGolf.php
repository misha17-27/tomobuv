<?php
declare(strict_types=1);

namespace App\Services\Suppliers;

use App\Core\App;
use App\Core\Cache;
use App\Core\Settings;
use App\Services\Import\Importer;

/**
 * Автозагрузка товаров Jong•Golf по API — замена коммерческого wa_loader_jonggolf.php старого сайта (FinBoss).
 * Адаптер общего каркаса поставщиков (Supplier: настройки, расписание, запуски, блокировки, отчёт).
 *
 * Запуск (supplier_runs) идёт шагами, каждый укладывается в бюджет времени (админка — ~12 с на AJAX-шаг,
 * cron/CLI — дольше), после обрыва продолжается с того же места (state):
 *   dict    → справочники поставщика (export_product_setting; для прогона по файлу — сохранённая копия);
 *   page    → очередная страница очереди (export_product, ≤ 300 товаров; первая — с clean_export при «Полном
 *             обновлении»), сохраняется в storage/import/jonggolf/runs/N/page-K.json;
 *   items   → товары страницы порциями по 20 (JongGolfSync: одна транзакция + точечная переиндексация);
 *   confirm → подтверждение страницы поставщику (export_product_callback) — только боевой запуск, после записи
 *             всей страницы, как у старого загрузчика; без подтверждения следующая страница недоступна;
 *   finish  → «Полное обновление»: скрыть товары поставщика, которых нет в выгрузке (вместо удаления у старого),
 *             фильтры категорий, сброс кэша, уборка старых отчётов.
 * Виды запуска: run — запись в базу; dry — пробный прогон (ничего не пишет в каталог и не подтверждает поставщику,
 * показывает, что будет добавлено / изменено / скрыто); test — проверка связи (только export_product_setting).
 * В режиме разработки (config debug = true), при выключенной автозагрузке и в пробном прогоне clean_export и callback
 * не отправляются никогда (проверка — в JongGolfApi).
 */
final class JongGolf extends Supplier
{
    public const CODE = 'jonggolf';
    public const TITLE = 'Jong•Golf';

    /** Настройки (settings, ключи «jonggolf.*»): значения владельца из wa_loader_jonggolf.cfg.php, загрузка выключена */
    public const DEFAULTS = [
        'enabled' => '0', 'api_key' => '',
        'window_from' => '1', 'window_to' => '16', 'period' => '5',
        'full_update' => '1', 'add_new' => '1', 'add_without_photo' => '0',
        'update_category' => '1', 'update_name' => '0', 'update_features' => '0', 'update_description' => '0',
        'keep_manual_hidden' => '0', 'adopt_manual' => '0',
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
        'update_description', 'keep_manual_hidden', 'adopt_manual', 'photo_replace', 'photo_check_file'];
    public const NAME_MODES = [
        'site'     => 'По правилу сайта: «Категория Бренд Код» (как автоназвания, RU и UA)',
        'supplier' => 'Как старый загрузчик: «категория поставщика Бренд Код» («Кросівки Jong•Golf B11751-12»)',
    ];
    public const FILTER_MODES = ['empty' => 'Заполнять пустой фильтр категории (как старый загрузчик)', 'always' => 'Всегда дополнять фильтр', 'never' => 'Не трогать фильтры'];

    /** Поля товара у поставщика для характеристик (левая часть строк «поле=Характеристика») */
    public const FIELDS = ['gender' => 'пол', 'size' => 'размерный ряд', 'brand' => 'бренд', 'material>outside' => 'материал верха',
        'material>inside' => 'материал внутри', 'material>bottom' => 'материал стельки', 'material>outsole' => 'материал подошвы'];

    // ================================================================== настройки

    /** Автозагрузка включена владельцем и задан ключ */
    public static function enabled(): bool
    {
        return self::on('enabled') && self::cfg('api_key') !== '';
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
     * Сохранить настройки из формы. Ключ API: пустое поле — не менять, «-» — удалить.
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
        foreach (['window_from' => [0, 23], 'window_to' => [0, 23], 'period' => [1, 24], 'photo_width' => [0, 3000], 'photo_height' => [0, 3000],
                     'photo_quality' => [30, 100], 'log_days' => [1, 90], 'history_days' => [30, 3650]] as $k => [$min, $max]) {
            if (!preg_match('/^\d{1,4}$/', $in[$k]) || (int) $in[$k] < $min || (int) $in[$k] > $max) $err[$k] = "Число от $min до $max";
        }
        if (!isset($err['window_from'], $err['window_to']) && (int) $in['window_from'] > (int) $in['window_to']) $err['window_to'] = 'Конец окна раньше начала';
        if (!isset(self::NAME_MODES[$in['name_mode']])) $in['name_mode'] = 'site';
        if (!isset(self::FILTER_MODES[$in['category_filter']])) $in['category_filter'] = 'empty';
        foreach (['meta_title', 'meta_description', 'meta_keywords', 'meta_title_uk', 'meta_description_uk', 'meta_keywords_uk'] as $k) $in[$k] = mb_substr($in[$k], 0, 500);
        foreach (['skip_brands', 'skip_categories'] as $k) $in[$k] = mb_substr($in[$k], 0, 5000);
        [, $attrErr] = self::attributes($in['attributes']);
        if ($attrErr) $err['attributes'] = implode('; ', $attrErr);
        $key = trim((string) ($post['api_key'] ?? ''));
        $hasKey = $key !== '' && $key !== '-' ? true : ($key === '-' ? false : self::cfg('api_key') !== '');
        if ($key !== '' && $key !== '-' && !preg_match('/^[\x21-\x7E]{8,200}$/', $key)) $err['api_key'] = 'Ключ — латинские буквы, цифры и знаки, без пробелов (8–200 символов)';
        if ($in['enabled'] === '1' && !$hasKey) $err['enabled'] = 'Чтобы включить автозагрузку, укажите ключ API';
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
        return ['pages' => 0, 'products' => 0, 'colors' => 0, 'created' => 0, 'updated' => 0, 'same' => 0, 'hidden_color' => 0, 'hidden_missing' => 0,
            'skipped' => 0, 'errors' => 0, 'warnings' => 0, 'photos' => 0, 'confirmed' => 0, 'skip' => [], 'unmapped' => [], 'queue' => null];
    }

    /**
     * Начать запуск. $o: src — api | file; files — страницы (JSON ответа export_product) для src = file; dict_file —
     * справочники для src = file (иначе сохранённая копия последнего ответа); limit — не больше N товаров поставщика;
     * origin — admin | cron | cli; user_id. Боевой запуск по API — только при включённой автозагрузке.
     * Боевой запуск по файлу — только из CLI (проверка записи на своих данных), поставщику ничего не отправляется.
     */
    public static function start(string $kind, array $o = []): int
    {
        self::ensureSchema();
        if (!isset(self::KINDS[$kind]) || $kind === 'test') throw new \InvalidArgumentException('Неизвестный вид запуска');
        $src = ($o['src'] ?? 'api') === 'file' ? 'file' : 'api';
        $origin = in_array($o['origin'] ?? '', ['cron', 'cli'], true) ? $o['origin'] : 'admin';
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
        self::log($id, 'info', 'Загрузчик стартовал ' . date('d.m.Y H:i:s') . ' — ' . $mode . ($state['limit'] ? ', не больше ' . $state['limit'] . ' товаров' : '')
            . ($src === 'file' ? ', страниц в файле: ' . $state['files'] : ''));
        self::flushLog();
        return $id;
    }

    /** Ошибка шага: ключ API замаскирован; ошибка обмена с поставщиком (JongGolfError) — не в журнал ошибок сайта */
    protected static function failure(\Throwable $e): array
    {
        $msg = $e instanceof JongGolfError || $e instanceof \RuntimeException ? $e->getMessage() : 'Ошибка: ' . $e->getMessage();
        return [(new JongGolfApi())->mask($msg), $e instanceof JongGolfError];
    }

    protected static function stopNote(array $run): string
    {
        return !empty($run['state']['callback']) ? ' — текущая страница поставщику не подтверждена, её товары придут снова' : '';
    }

    /** Машина состояний запуска (см. описание класса) */
    protected static function advance(array &$run, float $deadline): void
    {
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

    /** Проверка связи: только export_product_setting (ничего у поставщика не меняет); копия справочников сохраняется */
    public static function test(int $userId = 0, string $origin = 'admin'): array
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
        return match ($st['phase'] ?? '') {
            'dict' => 'Справочники поставщика', 'page' => 'Запрос страницы ' . ((int) ($st['page'] ?? 0) + 1),
            'items' => 'Страница ' . (int) $st['page'] . ': товар ' . (int) $st['pos'] . ' из ' . (int) ($st['count'] ?? 0),
            'confirm' => 'Подтверждение страницы ' . (int) $st['page'], 'finish' => 'Завершение', default => 'Идёт',
        };
    }

    public static function skipReasons(): array
    {
        return JongGolfSync::SKIP;
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
