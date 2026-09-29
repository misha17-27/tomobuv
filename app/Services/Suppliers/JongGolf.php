<?php
declare(strict_types=1);

namespace App\Services\Suppliers;

use App\Core\App;
use App\Core\Cache;
use App\Core\Log;
use App\Core\Settings;
use App\Services\Import\Importer;

/**
 * Автозагрузка товаров Jong•Golf по API — замена коммерческого wa_loader_jonggolf.php старого сайта (FinBoss).
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
final class JongGolf
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
        'category_filter' => 'empty', 'log_days' => '5', 'prices' => '{}',
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

    public const KINDS = ['run' => 'Запуск', 'dry' => 'Пробный прогон', 'test' => 'Проверка связи'];
    public const STATUSES = ['running' => 'Идёт', 'done' => 'Готово', 'error' => 'Ошибка', 'stopped' => 'Остановлен'];

    /** Запуск без движения дольше стольких секунд считается прерванным (как busy.txt старого загрузчика — 15 мин) */
    public const STALE = 900;
    private const LOG_MAX = 20000;

    private static bool $schemaOk = false;
    private static int $held = 0;
    private static array $logBuf = [];

    // ================================================================== настройки

    public static function cfg(string $k): string
    {
        $v = Settings::all()['jonggolf.' . $k] ?? null;
        return $v === null ? (string) (self::DEFAULTS[$k] ?? '') : (string) $v;
    }

    public static function on(string $k): bool
    {
        return self::cfg($k) === '1';
    }

    /** Автозагрузка включена владельцем и задан ключ */
    public static function enabled(): bool
    {
        return self::on('enabled') && self::cfg('api_key') !== '';
    }

    /** Ключ для показа: «••••1a2b» (последние 4 символа) */
    public static function keyHint(): string
    {
        $k = self::cfg('api_key');
        return $k === '' ? '' : '•••••••• ' . (strlen($k) > 8 ? substr($k, -4) : '');
    }

    /**
     * Сохранить настройки из формы. Ключ API: пустое поле — не менять, «-» — удалить.
     * @return array [ошибки по полям]
     */
    public static function saveSettings(array $post): array
    {
        $in = [];
        $err = [];
        foreach (self::DEFAULTS as $k => $def) {
            if ($k === 'prices' || $k === 'api_key') continue;
            $in[$k] = in_array($k, self::BOOLS, true) ? (!empty($post[$k]) ? '1' : '0') : trim(str_replace("\r\n", "\n", (string) ($post[$k] ?? $def)));
        }
        foreach (['window_from' => [0, 23], 'window_to' => [0, 23], 'period' => [1, 24], 'photo_width' => [0, 3000], 'photo_height' => [0, 3000],
                     'photo_quality' => [30, 100], 'log_days' => [1, 90]] as $k => [$min, $max]) {
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
        $all = Settings::all();
        foreach ($in as $k => $v) {
            if (($all['jonggolf.' . $k] ?? null) !== $v) Settings::set('jonggolf.' . $k, $v);
        }
        if ($key === '-') Settings::set('jonggolf.api_key', '');
        elseif ($key !== '') Settings::set('jonggolf.api_key', $key);
        return [];
    }

    /**
     * Характеристики из строк «поле поставщика=Характеристика сайта» (как $attribute_textarea старого загрузчика,
     * «/r/n» тоже разделитель). Характеристика — по названию или коду. @return array{0: [поле => строка features], 1: string[] ошибки}
     */
    public static function attributes(?string $text = null): array
    {
        $text ??= self::cfg('attributes');
        $features = App::db()->all('SELECT id, code, name, type FROM features');
        $byName = [];
        foreach ($features as $f) { $byName[Importer::nk((string) $f['name'])] ??= $f; $byName['code:' . strtolower((string) $f['code'])] = $f; }
        $out = []; $err = [];
        foreach (preg_split('~\r\n|\n|/r/n~', $text) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || !str_contains($line, '=')) continue;
            [$l, $r] = array_map('trim', explode('=', $line, 2));
            if (!isset(self::FIELDS[$l])) { $err[] = 'неизвестное поле поставщика «' . $l . '» (есть: ' . implode(', ', array_keys(self::FIELDS)) . ')'; continue; }
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
     * @return array{0: array, 1: string[]} [перенесённые настройки => значение (ключ скрыт), не перенесённые переменные]
     */
    public static function importOldConfig(string $php): array
    {
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
        foreach ($set as $k => $v) if (($all['jonggolf.' . $k] ?? null) !== $v) Settings::set('jonggolf.' . $k, $v);
        $skipped = array_values(array_merge(array_diff(array_keys($vars), array_keys($map), ['s_category_allow_filter_bool']),
            array_map(static fn($m) => $m . ' (шаблон «{name}» — на сайте SEO-шаблоны)', $machine)));
        if (isset($set['api_key'])) $set['api_key'] = '(задан)';
        return [$set, $skipped];
    }

    /** Список строк настройки (производители, категории): по строке на значение, «/r/n» тоже разделитель */
    public static function lines(string $k): array
    {
        $out = [];
        foreach (preg_split('~\r\n|\n|/r/n~', self::cfg($k)) ?: [] as $l) {
            $l = trim($l);
            if ($l !== '') $out[JongGolfMap::norm($l)] = $l;
        }
        return $out;
    }

    // ================================================================== схема и связи

    /** Таблицы раздела (database/migrations/suppliers.sql) — создать, если install.php ещё не запускали */
    public static function ensureSchema(): void
    {
        if (self::$schemaOk) return;
        Cache::remember('suppliers.schema.1', 86400, static function (): int {
            $db = App::db();
            if (!(int) $db->value("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'supplier_run_seen'")) {
                $sql = (string) preg_replace('/^\s*--.*$/m', '', (string) file_get_contents(ROOT . '/database/migrations/suppliers.sql'));
                foreach (preg_split('/;\s*\n/', $sql) ?: [] as $stmt) if (trim($stmt) !== '') $db->pdo()->exec($stmt);
            }
            return 1;
        });
        self::$schemaOk = true;
    }

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

    // ================================================================== расписание

    /**
     * Пора ли боевому запуску по расписанию: включено, час в окне [с; по] (Europe/Kiev, включительно) и с прошлого
     * запуска прошло не меньше периода (−10 мин: cron раз в час не сдвигает 01 → 06 → 11 → 16 на час). [да/нет, почему]
     */
    public static function due(?int $now = null): array
    {
        $now ??= time();
        if (!self::enabled()) return [false, 'автозагрузка выключена'];
        return self::dueAt($now, (int) self::cfg('window_from'), (int) self::cfg('window_to'), (int) self::cfg('period'), self::lastStart());
    }

    /** Начало последнего боевого запуска по API (timestamp) или null */
    private static function lastStart(): ?int
    {
        $last = App::db()->value("SELECT MAX(started_at) FROM supplier_runs WHERE supplier = ? AND kind = 'run' AND src = 'api'", [self::CODE]);
        return $last ? (int) strtotime((string) $last) : null;
    }

    /** Правило расписания без базы (для проверки): час $now в [$from; $to] и с $last прошло не меньше $period ч − 10 мин */
    public static function dueAt(int $now, int $from, int $to, int $period, ?int $last): array
    {
        $h = (int) date('G', $now);
        if ($h < $from || $h > $to) return [false, sprintf('вне окна запуска (%02d:00–%02d:59)', $from, $to)];
        if ($last && $now - $last < $period * 3600 - 600) return [false, 'после запуска ' . date('d.m H:i', $last) . ' не прошло ' . $period . ' ч'];
        return [true, ''];
    }

    /** Время следующего запуска по расписанию (для экрана) или null */
    public static function nextRun(): ?int
    {
        if (!self::enabled()) return null;
        $last = self::lastStart();                                          // один запрос, а не на каждый шаг цикла
        $t = time();
        for ($i = 0; $i < 48 * 6; $i++, $t += 600) {
            if (self::dueAt($t, (int) self::cfg('window_from'), (int) self::cfg('window_to'), (int) self::cfg('period'), $last)[0]) return $i === 0 ? time() : $t - $t % 3600;
        }
        return null;
    }

    /**
     * Из cron (bin/cron.php раз в час, bin/supplier-jonggolf.php без параметров): продолжить прерванный боевой запуск
     * или начать новый по расписанию и довести до конца. $say — вывод строк в консоль.
     */
    public static function cron(callable $say, float $maxTime = 3000.0): void
    {
        self::ensureSchema();
        self::cleanup();
        $db = App::db();
        $stale = $db->row("SELECT * FROM supplier_runs WHERE supplier = ? AND status = 'running' ORDER BY id DESC LIMIT 1", [self::CODE]);
        if ($stale && strtotime((string) ($stale['updated_at'] ?? $stale['started_at'])) > time() - self::STALE) {
            $say('Запуск №' . $stale['id'] . ' уже идёт — пропуск');
            return;
        }
        if ($stale && $stale['kind'] === 'run' && self::enabled()) {
            $say('Продолжаю прерванный запуск №' . $stale['id']);
            self::log((int) $stale['id'], 'info', 'Продолжение после обрыва (cron)');
            self::flushLog();
            $id = (int) $stale['id'];
        } else {
            if ($stale) self::finishRun((int) $stale['id'], 'stopped', 'прерван: не было движения больше ' . intdiv(self::STALE, 60) . ' мин');
            [$due, $why] = self::due();
            if (!$due) { $say('Автозагрузка ' . self::TITLE . ': не запускаю — ' . $why); return; }
            $id = self::start('run', ['src' => 'api', 'origin' => 'cron']);
            $say('Запуск №' . $id . ' начат');
        }
        self::drive($id, $say, $maxTime);
    }

    /** Довести запуск до конца (CLI): шаги подряд под общей блокировкой */
    public static function drive(int $id, callable $say, float $maxTime = 3000.0): array
    {
        $t0 = microtime(true);
        $p = ['busy' => true];
        $lastLine = '';
        $ok = self::withLock(static function () use ($id, $say, $t0, $maxTime, &$p, &$lastLine): void {
            while (true) {
                $p = self::step($id, 60.0);
                $line = $p['label'] . ': товаров ' . $p['stats']['products'] . ', добавлено ' . $p['stats']['created'] . ', изменено ' . $p['stats']['updated']
                    . ', без изменений ' . $p['stats']['same'] . ', скрыто ' . ($p['stats']['hidden_color'] + $p['stats']['hidden_missing']) . ', пропущено ' . $p['stats']['skipped'];
                if ($line !== $lastLine) { $say($line); $lastLine = $line; }
                if (!empty($p['done'])) break;
                if (!empty($p['busy'])) sleep(3);
                if (microtime(true) - $t0 > $maxTime) { $say('Время вышло — продолжение при следующем запуске'); break; }
            }
        }, 30);
        if (!$ok) $say('Запуски ' . self::TITLE . ' заняты другим процессом — повтор при следующем cron');
        return $p;
    }

    /** Одна блокировка на запуски поставщика (GET_LOCK — общий для админки, cron и CLI) */
    public static function withLock(callable $fn, int $wait = 0): bool
    {
        if (self::$held > 0) { self::$held++; try { $fn(); } finally { self::$held--; } return true; }
        $db = App::db();
        $name = 'tomobuv_sup_' . substr(md5((string) $db->value('SELECT DATABASE()') . self::CODE), 0, 16);
        try { $got = (int) $db->value('SELECT GET_LOCK(?, ?)', [$name, $wait]); } catch (\PDOException) { $got = 1; }
        if ($got !== 1) return false;
        self::$held++;
        try {
            $fn();
        } finally {
            self::$held--;
            try { $db->value('SELECT RELEASE_LOCK(?)', [$name]); } catch (\PDOException) {}
        }
        return true;
    }

    // ================================================================== запуски

    /** Папка файлов раздела (вне public): storage/import/jonggolf[/runs/N] */
    public static function dir(?int $runId = null): string
    {
        $d = Importer::dir() . '/' . self::CODE . ($runId ? '/runs/' . $runId : '');
        if (!is_dir($d)) @mkdir($d, 0775, true);
        return $d;
    }

    public static function run(int $id): ?array
    {
        self::ensureSchema();
        $r = App::db()->row('SELECT * FROM supplier_runs WHERE id = ? AND supplier = ?', [$id, self::CODE]);
        if (!$r) return null;
        foreach (['stats', 'state'] as $k) { $v = json_decode((string) $r[$k], true); $r[$k] = is_array($v) ? $v : []; }
        $r['stats'] += self::emptyStats();
        return $r;
    }

    /** Идущий запуск (не прерванный) или null */
    public static function active(): ?array
    {
        self::ensureSchema();
        $r = App::db()->row("SELECT id, updated_at, started_at FROM supplier_runs WHERE supplier = ? AND status = 'running' ORDER BY id DESC LIMIT 1", [self::CODE]);
        return $r && strtotime((string) ($r['updated_at'] ?? $r['started_at'])) > time() - self::STALE ? self::run((int) $r['id']) : null;
    }

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
        $busy = static function (): void {
            if (($a = self::active()) !== null) throw new \RuntimeException('Уже идёт запуск №' . $a['id'] . ' (' . self::KINDS[$a['kind']] . ') — дождитесь окончания или остановите его.');
        };
        $busy();
        if ($src === 'api' && self::cfg('api_key') === '') throw new \RuntimeException('Не задан ключ API.');
        $files = [];
        if ($src === 'file') {
            foreach ((array) ($o['files'] ?? []) as $f) if (is_file((string) $f)) $files[] = (string) $f;
            if (!$files) throw new \RuntimeException('Нет файла с товарами поставщика для прогона.');
        }
        $db = App::db();
        $api = new JongGolfApi(self::cfg('api_key'), $kind === 'run' && $src === 'api');
        $callback = $kind === 'run' && $src === 'api' && $api->canChangeQueue();
        $state = [
            'phase' => 'dict', 'page' => 0, 'pos' => 0, 'files' => 0, 'complete' => false,
            'callback' => $callback, 'clean' => $callback && self::on('full_update'),
            'limit' => isset($o['limit']) && (int) $o['limit'] > 0 ? (int) $o['limit'] : null,
            'dict_file' => isset($o['dict_file']) && is_file((string) $o['dict_file']) ? (string) $o['dict_file'] : null,
            'debug' => App::isDebug(),
        ];
        // «уже идёт?» и создание запуска — под блокировкой запусков поставщика: два одновременных старта (cron и «Запустить
        // сейчас») не создают двух запусков, которые делят одну очередь поставщика (каждый увидел бы только часть выгрузки)
        $id = 0;
        $locked = self::withLock(static function () use (&$id, $db, $kind, $src, $origin, $o, $state, $busy): void {
            $busy();
            // старые «зависшие» запуски — остановлены (новый начнётся с начала; поставщику они ничего не подтвердили после обрыва)
            $db->query("UPDATE supplier_runs SET status = 'stopped', error = 'прерван', finished_at = NOW() WHERE supplier = ? AND status = 'running'", [self::CODE]);
            $id = $db->insert('supplier_runs', ['supplier' => self::CODE, 'kind' => $kind, 'src' => $src, 'origin' => $origin, 'status' => 'running',
                'user_id' => !empty($o['user_id']) ? (int) $o['user_id'] : null, 'stats' => json_encode(self::emptyStats()), 'state' => json_encode($state),
                'started_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]);
        }, 10);
        if (!$locked) throw new \RuntimeException('Запуски ' . self::TITLE . ' заняты другим процессом — повторите через минуту.');
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

    /**
     * Шаг запуска (AJAX из админки или цикл CLI) не дольше $budget секунд. Параллельный шаг того же поставщика
     * (другая вкладка, cron) — ['busy' => true]. Ошибка поставщика или базы — запуск в статусе error.
     */
    public static function step(int $id, float $budget = 12.0): array
    {
        self::ensureSchema();
        $res = null;
        $ok = self::withLock(static function () use ($id, $budget, &$res): void {
            if (function_exists('set_time_limit')) @set_time_limit((int) $budget + 90);
            @ini_set('memory_limit', '512M');
            $run = self::run($id);
            if (!$run || $run['status'] !== 'running') { $res = $run; return; }
            $deadline = microtime(true) + max(3.0, $budget);
            $db = App::db();
            try {
                self::advance($run, $deadline);
            } catch (\Throwable $e) {
                $msg = $e instanceof JongGolfError || $e instanceof \RuntimeException ? $e->getMessage() : 'Ошибка: ' . $e->getMessage();
                $msg = (new JongGolfApi(self::cfg('api_key')))->mask($msg);
                if (!$e instanceof JongGolfError) Log::error('supplier jonggolf run #' . $id . ': ' . $msg . ' @ ' . $e->getFile() . ':' . $e->getLine());
                $run['stats']['errors']++;
                self::log($id, 'error', 'Работа завершена аварийно: ' . $msg);
                self::save($run);
                self::finishRun($id, 'error', mb_substr($msg, 0, 1000));
            } finally {
                self::flushLog();
            }
            $res = self::run($id);
        });
        if (!$ok) return ['busy' => true] + self::progress(self::run($id));
        return self::progress($res);
    }

    /** Остановить запуск (кнопка «Остановить»): текущая страница поставщику не подтверждается */
    public static function stop(int $id): void
    {
        $run = self::run($id);
        if (!$run || $run['status'] !== 'running') return;
        self::log($id, 'warn', 'Остановлен вручную' . ($run['state']['callback'] ? ' — текущая страница поставщику не подтверждена, её товары придут снова' : ''));
        self::flushLog();
        self::finishRun($id, 'stopped', 'остановлен вручную');
    }

    private static function finishRun(int $id, string $status, ?string $error = null): void
    {
        App::db()->update('supplier_runs', ['status' => $status, 'error' => $error, 'finished_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')], 'id = ?', [$id]);
    }

    private static function save(array $run): void
    {
        App::db()->update('supplier_runs', ['stats' => json_encode($run['stats'], JSON_UNESCAPED_UNICODE), 'state' => json_encode($run['state'], JSON_UNESCAPED_UNICODE),
            'updated_at' => date('Y-m-d H:i:s')], 'id = ?', [(int) $run['id']]);
    }

    /** Машина состояний запуска (см. описание класса) */
    private static function advance(array &$run, float $deadline): void
    {
        $id = (int) $run['id'];
        $st = &$run['state'];
        $dry = $run['kind'] === 'dry';
        $api = new JongGolfApi(self::cfg('api_key'), $run['kind'] === 'run' && $run['src'] === 'api');
        while (microtime(true) < $deadline) {
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
                    $st['pos'] = $sync->process($list, (int) $st['pos'], $deadline, $left, static function (int $pos) use (&$run, &$st): void {
                        $st['pos'] = $pos;
                        self::save($run);
                        self::flushLog();
                    });
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
                    $sync->finish(!empty($st['complete']));
                    $s = $run['stats'];
                    self::log($id, 'info', '==========');
                    self::log($id, 'info', ($dry ? 'Будет добавлено: ' : 'Количество добавленных товаров: ') . $s['created']);
                    self::log($id, 'info', ($dry ? 'Будет изменено: ' : 'Количество изменённых товаров: ') . $s['updated'] . ' (без изменений: ' . $s['same'] . ')');
                    self::log($id, 'info', ($dry ? 'Будет скрыто: ' : 'Скрыто: ') . ($s['hidden_color'] + $s['hidden_missing']) . ' (цвет закончился: ' . $s['hidden_color'] . ', нет у поставщика: ' . $s['hidden_missing'] . ')');
                    self::log($id, 'info', 'Пропущено цветов: ' . $s['skipped'] . self::skipText($s['skip']));
                    self::log($id, 'info', 'Работа завершена ' . date('d.m.Y H:i:s'));
                    self::save($run);
                    self::finishRun($id, 'done');
                    self::flushLog();
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
        $api = new JongGolfApi(self::cfg('api_key'));
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

    /** Состояние запуска для экрана и CLI */
    public static function progress(?array $run): array
    {
        if (!$run) return ['ok' => false, 'error' => 'Запуск не найден', 'done' => true, 'label' => '—', 'stats' => self::emptyStats()];
        $st = $run['state'];
        $label = match ($run['status']) {
            'running' => match ($st['phase'] ?? '') {
                'dict' => 'Справочники поставщика', 'page' => 'Запрос страницы ' . ((int) ($st['page'] ?? 0) + 1),
                'items' => 'Страница ' . (int) $st['page'] . ': товар ' . (int) $st['pos'] . ' из ' . (int) ($st['count'] ?? 0),
                'confirm' => 'Подтверждение страницы ' . (int) $st['page'], 'finish' => 'Завершение', default => 'Идёт',
            },
            'done' => 'Итоги',
            default => self::STATUSES[$run['status']] ?? $run['status'],
        };
        $pct = ($st['phase'] ?? '') === 'items' && !empty($st['count']) ? (int) floor((int) $st['pos'] / (int) $st['count'] * 100) : ($run['status'] === 'running' ? 0 : 100);
        return ['ok' => $run['status'] !== 'error', 'id' => (int) $run['id'], 'status' => $run['status'], 'kind' => $run['kind'], 'label' => $label,
            'percent' => $pct, 'page' => (int) ($st['page'] ?? 0), 'done' => $run['status'] !== 'running', 'error' => $run['error'], 'stats' => $run['stats']];
    }

    /** «: нет в наличии у поставщика 12, категория не сопоставлена 3…» */
    public static function skipText(array $skip): string
    {
        if (!$skip) return '';
        $parts = [];
        arsort($skip);
        foreach ($skip as $k => $n) $parts[] = (JongGolfSync::SKIP[$k] ?? $k) . ' — ' . $n;
        return ' (' . implode(', ', $parts) . ')';
    }

    // ================================================================== журнал

    /** Строка отчёта (как write_to_log старого загрузчика). Пишется пачкой — flushLog() */
    public static function log(int $runId, string $level, string $msg, ?int $pid = null, ?array $data = null): void
    {
        static $count = [];
        $count[$runId] = ($count[$runId] ?? (int) App::db()->value('SELECT COUNT(*) FROM supplier_run_log WHERE run_id = ?', [$runId])) + 1;
        if ($count[$runId] > self::LOG_MAX && !in_array($level, ['error', 'info'], true)) return;
        self::$logBuf[] = ['run_id' => $runId, 'level' => $level, 'product_id' => $pid, 'message' => mb_substr($msg, 0, 1000),
            'data' => $data !== null ? json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) : null, 'created_at' => date('Y-m-d H:i:s')];
        if (count(self::$logBuf) >= 300) self::flushLog();
    }

    /** Отметка буфера отчёта (порция, которую придётся повторить, не должна оставить строк) */
    public static function logMark(): int
    {
        return count(self::$logBuf);
    }

    public static function logRollback(int $mark): void
    {
        self::$logBuf = array_slice(self::$logBuf, 0, $mark);
    }

    public static function flushLog(): void
    {
        if (!self::$logBuf) return;
        $buf = self::$logBuf;
        self::$logBuf = [];
        App::db()->insertMany('supplier_run_log', $buf, false, 300);
    }

    public static function runs(int $limit = 50): array
    {
        self::ensureSchema();
        $rows = App::db()->all('SELECT id, kind, src, origin, status, stats, error, started_at, finished_at, updated_at FROM supplier_runs WHERE supplier = ? ORDER BY id DESC LIMIT ' . max(1, $limit), [self::CODE]);
        foreach ($rows as &$r) { $s = json_decode((string) $r['stats'], true); $r['stats'] = (is_array($s) ? $s : []) + self::emptyStats(); }
        unset($r);
        return $rows;
    }

    /** Последний завершённый запуск (боевой или пробный) — для «несопоставленных сочетаний» */
    public static function lastFinished(): ?array
    {
        self::ensureSchema();
        $id = App::db()->value("SELECT id FROM supplier_runs WHERE supplier = ? AND kind IN ('run', 'dry') AND status = 'done' ORDER BY id DESC LIMIT 1", [self::CODE]);
        return $id ? self::run((int) $id) : null;
    }

    /**
     * Уборка: отчёты и файлы запусков старше «Хранить отчёты, дней» (как save_log_int старого загрузчика);
     * последние 5 запусков остаются всегда. Загруженные для прогона файлы — через сутки.
     */
    public static function cleanup(): void
    {
        self::ensureSchema();
        $db = App::db();
        $days = max(1, (int) self::cfg('log_days'));
        $keep = array_map('intval', $db->col('SELECT id FROM supplier_runs WHERE supplier = ? ORDER BY id DESC LIMIT 5', [self::CODE]));
        $old = array_map('intval', $db->col("SELECT id FROM supplier_runs WHERE supplier = ? AND status <> 'running' AND started_at < DATE_SUB(NOW(), INTERVAL ? DAY)", [self::CODE, $days]));
        $old = array_values(array_diff($old, $keep));
        foreach (array_chunk($old, 200) as $part) {
            [$ph, $v] = $db->in($part);
            foreach (['supplier_run_log', 'supplier_run_seen'] as $t) $db->query("DELETE FROM `$t` WHERE run_id IN ($ph)", $v);
            $db->query("DELETE FROM supplier_runs WHERE id IN ($ph)", $v);
        }
        foreach ($old as $id) {
            $d = Importer::dir() . '/' . self::CODE . '/runs/' . $id;
            if (!is_dir($d)) continue;
            foreach (glob($d . '/*') ?: [] as $f) if (is_file($f)) @unlink($f);
            @rmdir($d);
        }
        foreach (glob(self::dir() . '/upload-*') ?: [] as $f) if (is_file($f) && filemtime($f) < time() - 86400) @unlink($f);
        // связи с удалёнными товарами (товар удалили в админке — следующий запуск создаст его заново и свяжет)
        $db->query('DELETE l FROM supplier_links l LEFT JOIN products p ON p.id = l.product_id WHERE l.supplier = ? AND p.id IS NULL', [self::CODE]);
    }

    // ================================================================== как старый загрузчик

    /** translit() старого загрузчика — 1-в-1 (адреса новых товаров: «Кросівки Jong•Golf B11751-12» → krosivki-jonggolf-b11751-12) */
    public static function translit(string $text): string
    {
        static $ru = null, $en = null;
        $ru ??= explode('-', 'А-а-Б-б-В-в-Ґ-ґ-Г-г-Д-д-Е-е-Ё-ё-Є-є-Ж-ж-З-з-И-и-І-і-Ї-ї-Й-й-К-к-Л-л-М-м-Н-н-О-о-П-п-Р-р-С-с-Т-т-У-у-Ф-ф-Х-х-Ц-ц-Ч-ч-Ш-ш-Щ-щ-Ъ-ъ-Ы-ы-Ь-ь-Э-э-Ю-ю-Я-я');
        $en ??= explode('-', 'A-a-B-b-V-v-G-g-G-g-D-d-E-e-E-e-E-e-ZH-zh-Z-z-I-i-I-i-I-i-J-j-K-k-L-l-M-m-N-n-O-o-P-p-R-r-S-s-T-t-U-u-F-f-H-h-TS-ts-CH-ch-SH-sh-SCH-sch---Y-y---E-e-YU-yu-YA-ya');
        $text = str_replace(['>', '/'], '-', $text);
        $res = str_replace($ru, $en, $text);
        $res = (string) preg_replace('/[\s]+/ui', '-', $res);
        return strtolower((string) preg_replace('/[^0-9a-zа-я\-]+/ui', '', $res));
    }

    /** Мета-тег по шаблону ({name}); keywords — как старый загрузчик: без ( ) , . * " и через «, » */
    public static function meta(string $tpl, string $name, bool $keywords = false): string
    {
        $v = str_replace('{name}', $name, $tpl);
        if ($keywords) $v = str_replace(' ', ', ', str_replace('  ', ' ', strtr($v, ['(' => ' ', ')' => ' ', ',' => '', '.' => '', '*' => '', '"' => ''])));
        return trim($v);
    }
}
