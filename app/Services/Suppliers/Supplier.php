<?php
declare(strict_types=1);

namespace App\Services\Suppliers;

use App\Core\App;
use App\Core\Cache;
use App\Core\Log;
use App\Core\Settings;
use App\Services\Import\Importer;

/**
 * Общий каркас автозагрузки поставщика («Каталог → Поставщики»). Наследник — адаптер поставщика (JongGolf — API,
 * HitObuvi — страницы сайта) задаёт CODE, TITLE, DEFAULTS и свои фазы запуска (advance), остальное — здесь:
 *   - настройки settings «{CODE}.*» (cfg, on, lines);
 *   - расписание: окно часов + период (due, nextRun), cron — продолжить прерванный запуск или начать новый;
 *   - запуски supplier_runs: создание под блокировкой (createRun), шаг в бюджете времени (step → advance), «Остановить»
 *     (условный UPDATE), итог только поверх «Идёт» (finishRun), продолжение после обрыва (state), «зависший» — STALE;
 *   - блокировка запусков поставщика (GET_LOCK по коду — общая для админки, cron и CLI);
 *   - отчёт supplier_run_log (пишется пачкой: log → flushLog), уборка старых отчётов и папок запусков.
 * Методы вызываются на классе адаптера (JongGolf::step(…)) — static:: внутри указывает на него.
 */
abstract class Supplier
{
    public const CODE = '';
    public const TITLE = '';
    /** Настройки по умолчанию: [ключ без «CODE.» => значение] */
    public const DEFAULTS = [];
    public const KINDS = ['run' => 'Запуск', 'dry' => 'Пробный прогон', 'test' => 'Проверка связи'];
    public const STATUSES = ['running' => 'Идёт', 'done' => 'Готово', 'error' => 'Ошибка', 'stopped' => 'Остановлен'];
    /** Сверка наличия по N полным проходам (supplier_links.missed): «нет у поставщика — ещё на сайте» в «Обзоре» */
    public const CHECKS_MISSING = false;
    /** Считает ли адаптер запросы к сайту поставщика (stats.requests) */
    public const COUNTS_REQUESTS = false;
    /** Переносит ли наличие у поставщика на сайт («нет в наличии» без скрытия): список «Нет в наличии у поставщика» в «Обзоре» */
    public const TRACKS_STOCK = false;

    /**
     * Бренды сайта, весь товар которых — от этого поставщика (официальный сайт бренда): «Обзор» показывает их товары без связи
     * с поставщиком — после полного прохода это «есть у нас, нет у поставщика». [] — поставщик не одного бренда.
     */
    public static function ownBrandIds(): array
    {
        return [];
    }

    /**
     * Код товара у поставщика для людей («Обзор», карточка товара): по умолчанию — код связи (supplier_links.code);
     * адаптер, у которого код связи — служебный id (Tom.m: id товара на сайте бренда), показывает артикул ($ext — ext_id)
     */
    public static function codeLabel(string $code, ?string $ext = null): string
    {
        return $code;
    }

    /** Запуск без движения дольше стольких секунд считается прерванным (как busy.txt старого загрузчика — 15 мин) */
    public const STALE = 900;
    /** supplier_runs.src боевого запуска (для расписания: «последний боевой запуск») */
    protected const LIVE_SRC = 'api';
    private const LOG_MAX = 20000;

    private static bool $schemaOk = false;
    /** Вложенность блокировки запусков: [код поставщика => n] */
    private static array $held = [];
    private static array $logBuf = [];

    // ================================================================== настройки

    public static function cfg(string $k): string
    {
        $v = Settings::all()[static::CODE . '.' . $k] ?? null;
        return $v === null ? (string) (static::DEFAULTS[$k] ?? '') : (string) $v;
    }

    public static function on(string $k): bool
    {
        return static::cfg($k) === '1';
    }

    /** Автозагрузка включена владельцем (и выполнены условия адаптера: ключ API, наценка …) */
    abstract public static function enabled(): bool;

    /**
     * Можно ли боевой запуск «Запустить сейчас» (и продолжение прерванного ручного запуска из cron). По умолчанию —
     * только при включённой автозагрузке; адаптер может разрешить ручной запуск и при выключенной (Хит обуви — если задана наценка).
     */
    public static function manualAllowed(): bool
    {
        return static::enabled();
    }

    /** Почему «Запустить сейчас» недоступна (подсказка на кнопке); '' — доступна */
    public static function manualHint(): string
    {
        return static::manualAllowed() ? '' : 'Автозагрузка выключена';
    }

    /** Сколько дней хранить журнал изменений «Обзора» (история цен, скрытые, вернувшиеся): «{код}.history_days», по умолчанию 365 */
    public static function historyDays(): int
    {
        $d = (int) static::cfg('history_days');
        return $d >= 30 ? min(3650, $d) : 365;
    }

    /** Записать настройки, которые отличаются от сохранённых */
    protected static function store(array $in): void
    {
        $all = Settings::all();
        foreach ($in as $k => $v) {
            if (($all[static::CODE . '.' . $k] ?? null) !== $v) Settings::set(static::CODE . '.' . $k, $v);
        }
    }

    /** Название-значение: HTML-сущности раскодированы до стабильного результата, пробелы схлопнуты, края обрезаны */
    public static function name(string $s): string
    {
        for ($i = 0; $i < 10 && str_contains($s, '&'); $i++) {
            $d = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if ($d === $s) break;
            $s = $d;
        }
        return trim(preg_replace('/\s+/u', ' ', $s) ?? $s);          // null — не UTF-8: как есть
    }

    /**
     * Список строк настройки (бренды, категории): по строке на значение, «/r/n» тоже разделитель.
     * Ключ — нормализованное имя (HTML-сущности раскодированы до конца: «J&amp;amp;G» = «J&G»)
     */
    public static function lines(string $k): array
    {
        $out = [];
        foreach (preg_split('~\r\n|\n|/r/n~', static::cfg($k)) ?: [] as $l) {
            $l = static::name($l);
            if ($l !== '') $out[JongGolfMap::norm($l)] = $l;
        }
        return $out;
    }

    /** Адрес товара из названия — translit() старого загрузчика Jong•Golf 1-в-1 («Кросівки Jong•Golf B11751-12» → krosivki-jonggolf-b11751-12) */
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

    // ================================================================== схема

    /** Таблицы раздела (database/migrations/suppliers.sql) — создать или дополнить, если install.php ещё не запускали */
    public static function ensureSchema(): void
    {
        if (self::$schemaOk) return;
        Cache::remember('suppliers.schema.3', 86400, static function (): int {
            $db = App::db();
            if (!(int) $db->value("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'supplier_category_map' AND COLUMN_NAME = 'mode'")
                || !(int) $db->value("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'supplier_links' AND COLUMN_NAME = 'cost'")
                || !(int) $db->value("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'supplier_changes'")
                || !(int) $db->value("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'supplier_run_seen'")) {
                $sql = (string) preg_replace('/^\s*--.*$/m', '', (string) file_get_contents(ROOT . '/database/migrations/suppliers.sql'));
                foreach (preg_split('/;\s*\n/', $sql) ?: [] as $stmt) if (trim($stmt) !== '') $db->pdo()->exec($stmt);
            }
            return 1;
        });
        self::$schemaOk = true;
    }

    // ================================================================== расписание

    /**
     * Пора ли боевому запуску по расписанию: включено, час в окне [с; по] (Europe/Kiev, включительно) и с прошлого
     * запуска прошло не меньше периода (−10 мин: cron раз в час не сдвигает 01 → 06 → 11 → 16 на час). [да/нет, почему]
     */
    public static function due(?int $now = null): array
    {
        $now ??= time();
        if (!static::enabled()) return [false, 'автозагрузка выключена'];
        return self::dueAt($now, (int) static::cfg('window_from'), (int) static::cfg('window_to'), (int) static::cfg('period'), static::lastStart());
    }

    /** Начало последнего боевого запуска (timestamp) или null; у адаптера с выбором источника — с любого из них */
    protected static function lastStart(): ?int
    {
        [$ph, $v] = App::db()->in(static::liveSrcs());
        $last = App::db()->value("SELECT MAX(started_at) FROM supplier_runs WHERE supplier = ? AND kind = 'run' AND src IN ($ph)", array_merge([static::CODE], $v));
        return $last ? (int) strtotime((string) $last) : null;
    }

    /** supplier_runs.src боевого запуска сейчас (адаптер с выбором источника — Jong•Golf: сайт бренда или API — по настройке) */
    protected static function liveSrc(): string
    {
        return static::LIVE_SRC;
    }

    /** Источники боевых запусков для расписания: сменили источник — следующий запуск всё равно через период после прошлого */
    protected static function liveSrcs(): array
    {
        return [static::liveSrc()];
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
        if (!static::enabled()) return null;
        $last = static::lastStart();                                        // один запрос, а не на каждый шаг цикла
        $t = time();
        for ($i = 0; $i < 48 * 6; $i++, $t += 600) {
            if (self::dueAt($t, (int) static::cfg('window_from'), (int) static::cfg('window_to'), (int) static::cfg('period'), $last)[0]) return $i === 0 ? time() : $t - $t % 3600;
        }
        return null;
    }

    /**
     * Из cron (bin/cron.php раз в час, CLI адаптера без параметров): продолжить прерванный боевой запуск
     * или начать новый по расписанию и довести до конца. $say — вывод строк в консоль.
     */
    public static function cron(callable $say, float $maxTime = 3000.0): void
    {
        static::ensureSchema();
        static::cleanup();
        $db = App::db();
        $stale = $db->row("SELECT * FROM supplier_runs WHERE supplier = ? AND status = 'running' ORDER BY id DESC LIMIT 1", [static::CODE]);
        if ($stale && strtotime((string) ($stale['updated_at'] ?? $stale['started_at'])) > time() - static::STALE) {
            $say('Запуск №' . $stale['id'] . ' уже идёт — пропуск');
            return;
        }
        // прерванный боевой: ручной («Запустить сейчас», страницу закрыли) — пока разрешён ручной запуск; начатый по расписанию —
        // только пока автозагрузка включена (выключили — cron к поставщику больше не ходит, запуск получает «Остановлен»)
        $resume = $stale && $stale['kind'] === 'run' && ($stale['origin'] === 'cron' ? static::enabled() : static::manualAllowed());
        if ($resume) {
            // дальше шаги делает cron
            $say('Продолжаю прерванный запуск №' . $stale['id']);
            static::log((int) $stale['id'], 'info', 'Продолжение после обрыва (cron)');
            static::flushLog();
            $id = (int) $stale['id'];
        } else {
            if ($stale) static::finishRun((int) $stale['id'], 'stopped', $stale['kind'] === 'run' ? 'остановлен: автозагрузка выключена' : 'прерван: не было движения больше ' . intdiv(static::STALE, 60) . ' мин');
            [$due, $why] = static::due();
            if (!$due) { $say('Автозагрузка ' . static::TITLE . ': не запускаю — ' . $why); return; }
            $id = static::start('run', ['src' => static::liveSrc(), 'origin' => 'cron']);
            $say('Запуск №' . $id . ' начат');
        }
        static::drive($id, $say, $maxTime);
    }

    /** Начать запуск (адаптер): kind — run | dry, $o — источник, лимиты, origin, user_id. @return int id запуска */
    abstract public static function start(string $kind, array $o = []): int;

    /** Строка хода запуска для консоли */
    protected static function progressLine(array $p): string
    {
        $s = $p['stats'];
        return $p['label'] . ': товаров ' . $s['products'] . ', добавлено ' . $s['created'] . ', изменено ' . $s['updated']
            . ', без изменений ' . $s['same'] . ', скрыто ' . ($s['hidden_color'] + $s['hidden_missing']) . ', пропущено ' . $s['skipped'];
    }

    /** Довести запуск до конца (CLI): шаги подряд под общей блокировкой */
    public static function drive(int $id, callable $say, float $maxTime = 3000.0): array
    {
        $t0 = microtime(true);
        $p = ['busy' => true];
        $lastLine = '';
        $ok = static::withLock(static function () use ($id, $say, $t0, $maxTime, &$p, &$lastLine): void {
            while (true) {
                $p = static::step($id, 60.0);
                $line = static::progressLine($p);
                if ($line !== $lastLine) { $say($line); $lastLine = $line; }
                if (!empty($p['done'])) break;
                if (!empty($p['wait'])) sleep(5);                           // идёт перестройка индекса каталога / пауза поставщика
                elseif (!empty($p['busy'])) sleep(3);
                if (microtime(true) - $t0 > $maxTime) { $say('Время вышло — продолжение при следующем запуске'); break; }
            }
        }, 30);
        if (!$ok) $say('Запуски ' . static::TITLE . ' заняты другим процессом — повтор при следующем cron');
        return $p;
    }

    /** Одна блокировка на запуски поставщика (GET_LOCK — общий для админки, cron и CLI) */
    public static function withLock(callable $fn, int $wait = 0): bool
    {
        $code = static::CODE;
        if ((self::$held[$code] ?? 0) > 0) { self::$held[$code]++; try { $fn(); } finally { self::$held[$code]--; } return true; }
        $db = App::db();
        $name = 'tomobuv_sup_' . substr(md5((string) $db->value('SELECT DATABASE()') . $code), 0, 16);
        try { $got = (int) $db->value('SELECT GET_LOCK(?, ?)', [$name, $wait]); } catch (\PDOException) { $got = 1; }
        if ($got !== 1) return false;
        self::$held[$code] = (self::$held[$code] ?? 0) + 1;
        try {
            $fn();
        } finally {
            self::$held[$code]--;
            try { $db->value('SELECT RELEASE_LOCK(?)', [$name]); } catch (\PDOException) {}
        }
        return true;
    }

    // ================================================================== запуски

    /** Папка файлов раздела (вне public): storage/import/{CODE}[/runs/N] */
    public static function dir(?int $runId = null): string
    {
        $d = Importer::dir() . '/' . static::CODE . ($runId ? '/runs/' . $runId : '');
        if (!is_dir($d)) @mkdir($d, 0775, true);
        return $d;
    }

    public static function run(int $id): ?array
    {
        static::ensureSchema();
        $r = App::db()->row('SELECT * FROM supplier_runs WHERE id = ? AND supplier = ?', [$id, static::CODE]);
        if (!$r) return null;
        foreach (['stats', 'state'] as $k) { $v = json_decode((string) $r[$k], true); $r[$k] = is_array($v) ? $v : []; }
        $r['stats'] += static::emptyStats();
        return $r;
    }

    /** Идущий запуск (не прерванный) или null */
    public static function active(): ?array
    {
        static::ensureSchema();
        $r = App::db()->row("SELECT id, updated_at, started_at FROM supplier_runs WHERE supplier = ? AND status = 'running' ORDER BY id DESC LIMIT 1", [static::CODE]);
        return $r && strtotime((string) ($r['updated_at'] ?? $r['started_at'])) > time() - static::STALE ? static::run((int) $r['id']) : null;
    }

    /**
     * Запуск в статусе «Идёт» без движения дольше STALE: страницу закрыли или cron-шаг закончил свои 40–50 мин, а следующий
     * cron ещё не начался. Боевой продолжит cron (или кнопка «Продолжить»), пробный — только кнопка. null — такого нет.
     */
    public static function paused(): ?array
    {
        static::ensureSchema();
        $r = App::db()->row("SELECT id, updated_at, started_at FROM supplier_runs WHERE supplier = ? AND status = 'running' AND kind IN ('run', 'dry') ORDER BY id DESC LIMIT 1", [static::CODE]);
        return $r && strtotime((string) ($r['updated_at'] ?? $r['started_at'])) <= time() - static::STALE ? static::run((int) $r['id']) : null;
    }

    public static function emptyStats(): array
    {
        return ['pages' => 0, 'products' => 0, 'created' => 0, 'updated' => 0, 'same' => 0, 'hidden_color' => 0, 'hidden_missing' => 0,
            'skipped' => 0, 'errors' => 0, 'warnings' => 0, 'photos' => 0, 'skip' => [], 'unmapped' => []];
    }

    /** Уже идёт запуск — исключение с понятным текстом */
    protected static function assertIdle(): void
    {
        if (($a = static::active()) !== null) throw new \RuntimeException('Уже идёт запуск №' . $a['id'] . ' (' . static::KINDS[$a['kind']] . ') — дождитесь окончания или остановите его.');
    }

    /**
     * Создать запуск: «уже идёт?» и создание — под блокировкой запусков поставщика (два одновременных старта — cron
     * и «Запустить сейчас» — не создают двух запусков); старые «зависшие» запуски — остановлены. @return int id
     */
    protected static function createRun(string $kind, string $src, string $origin, ?int $userId, array $state, ?array $stats = null): int
    {
        $db = App::db();
        $id = 0;
        $locked = static::withLock(static function () use (&$id, $db, $kind, $src, $origin, $userId, $state, $stats): void {
            static::assertIdle();
            $db->query("UPDATE supplier_runs SET status = 'stopped', error = 'прерван', finished_at = NOW() WHERE supplier = ? AND status = 'running'", [static::CODE]);
            $id = $db->insert('supplier_runs', ['supplier' => static::CODE, 'kind' => $kind, 'src' => $src, 'origin' => $origin, 'status' => 'running',
                'user_id' => $userId ?: null, 'stats' => json_encode($stats ?? static::emptyStats()), 'state' => json_encode($state),
                'started_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]);
        }, 10);
        if (!$locked) throw new \RuntimeException('Запуски ' . static::TITLE . ' заняты другим процессом — повторите через минуту.');
        return $id;
    }

    /** Текст ошибки шага для отчёта: [сообщение, ожидаемая (ошибка поставщика — без записи в журнал ошибок сайта)] */
    protected static function failure(\Throwable $e): array
    {
        return [$e instanceof \RuntimeException ? $e->getMessage() : 'Ошибка: ' . $e->getMessage(), false];
    }

    /**
     * Шаг запуска (AJAX из админки или цикл CLI) не дольше $budget секунд. Параллельный шаг того же поставщика
     * (другая вкладка, cron) — ['busy' => true]. Ошибка поставщика или базы — запуск в статусе error.
     */
    public static function step(int $id, float $budget = 12.0): array
    {
        static::ensureSchema();
        $res = null;
        $ok = static::withLock(static function () use ($id, $budget, &$res): void {
            if (function_exists('set_time_limit')) @set_time_limit((int) $budget + 90);
            @ini_set('memory_limit', '512M');
            $run = static::run($id);
            if (!$run || $run['status'] !== 'running') { $res = $run; return; }
            $deadline = microtime(true) + max(3.0, $budget);
            try {
                static::advance($run, $deadline);
            } catch (\Throwable $e) {
                [$msg, $expected] = static::failure($e);
                if (!$expected) Log::error('supplier ' . static::CODE . ' run #' . $id . ': ' . $msg . ' @ ' . $e->getFile() . ':' . $e->getLine());
                $run['stats']['errors']++;
                static::log($id, 'error', 'Работа завершена аварийно: ' . $msg);
                static::save($run);
                static::finishRun($id, 'error', mb_substr($msg, 0, 1000));   // остановленный во время шага — остаётся «Остановлен»
                static::dropPhotoCache($id);
            } finally {
                static::flushLog();
            }
            $res = static::run($id);
        });
        if (!$ok) return ['busy' => true] + static::progress(static::run($id));
        return static::progress($res);
    }

    /** Машина состояний запуска адаптера: фазы до $deadline, состояние — $run['state'] (сохраняет save) */
    abstract protected static function advance(array &$run, float $deadline): void;

    /** Дополнение к строке «Остановлен вручную» (что будет с данными поставщика) */
    protected static function stopNote(array $run): string
    {
        return '';
    }

    /**
     * Остановить запуск (кнопка «Остановить»). Атомарно: статус «Остановлен» ставится одним условным UPDATE
     * (… AND status = 'running') под блокировкой запусков, если её удалось взять за 3 с (шага нет). Идёт шаг (другая
     * вкладка, cron/CLI держит блокировку весь прогон) — тот же условный UPDATE без блокировки: шаг проверяет статус
     * перед каждой порцией и завершением (stopped()), а свой итоговый статус пишет тоже только поверх «Идёт» (finishRun) —
     * «Готово» или «Ошибка» не перезапишут «Остановлен». Уже начатая порция (одна транзакция) дописывается целиком.
     * @return bool остановлен сейчас
     */
    public static function stop(int $id): bool
    {
        $run = static::run($id);
        if (!$run || $run['status'] !== 'running') return false;
        $done = false;
        $write = static function (bool $locked) use ($id, $run, &$done): void {
            $done = static::finishRun($id, 'stopped', 'остановлен вручную');
            if (!$done) return;                                             // шаг успел закончить запуск сам
            static::log($id, 'warn', 'Остановлен вручную' . static::stopNote($run));
            static::flushLog();
            if ($locked) static::dropPhotoCache($id);                       // идущий шаг уберёт их сам, заметив остановку
        };
        if (!static::withLock(static fn() => $write(true), 3)) $write(false);
        return $done;
    }

    /** Запуск больше не «Идёт» (остановлен кнопкой или новым запуском) — шаг прекращает работу, ничего не дописывая */
    protected static function stopped(int $id): bool
    {
        return (string) App::db()->value('SELECT status FROM supplier_runs WHERE id = ?', [$id]) !== 'running';
    }

    /** Итоговый статус — только поверх «Идёт» (см. stop). @return bool записан */
    protected static function finishRun(int $id, string $status, ?string $error = null): bool
    {
        return App::db()->update('supplier_runs', ['status' => $status, 'error' => $error, 'finished_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')],
            "id = ? AND status = 'running'", [$id]) > 0;
    }

    /** Фото, скачанные для отложенной порции (photo-*.jpg в папке запуска), — запуск закончен, не нужны */
    protected static function dropPhotoCache(int $id): void
    {
        foreach (glob(Importer::dir() . '/' . static::CODE . '/runs/' . $id . '/photo-*.jpg') ?: [] as $f) @unlink($f);
    }

    protected static function save(array $run): void
    {
        App::db()->update('supplier_runs', ['stats' => json_encode($run['stats'], JSON_UNESCAPED_UNICODE), 'state' => json_encode($run['state'], JSON_UNESCAPED_UNICODE),
            'updated_at' => date('Y-m-d H:i:s')], 'id = ?', [(int) $run['id']]);
    }

    /** Подпись идущей фазы для экрана (адаптер) */
    protected static function phaseLabel(array $st): string
    {
        return 'Идёт';
    }

    /** Процент выполнения (порции страницы); null — не считается: идущий — 0, законченный — 100 */
    protected static function percent(array $run): ?int
    {
        $st = $run['state'];
        return ($st['phase'] ?? '') === 'items' && !empty($st['count']) ? (int) floor((int) $st['pos'] / (int) $st['count'] * 100) : null;
    }

    /** Подпись ожидания (wait): порция отложена — следующий шаг через 5 с */
    protected static function waitLabel(array $st): string
    {
        return ' — ждёт окончания перестройки индекса каталога';
    }

    /** Состояние запуска для экрана и CLI */
    public static function progress(?array $run): array
    {
        if (!$run) return ['ok' => false, 'error' => 'Запуск не найден', 'done' => true, 'label' => '—', 'stats' => static::emptyStats()];
        $st = $run['state'];
        $label = match ($run['status']) {
            'running' => static::phaseLabel($st),
            'done' => 'Итоги',
            default => static::STATUSES[$run['status']] ?? $run['status'],
        };
        $pct = static::percent($run) ?? ($run['status'] === 'running' ? 0 : 100);
        $wait = $run['status'] === 'running' && !empty($st['wait']);
        if ($wait) $label .= static::waitLabel($st);
        return ['ok' => $run['status'] !== 'error', 'id' => (int) $run['id'], 'status' => $run['status'], 'kind' => $run['kind'], 'label' => $label,
            'percent' => $pct, 'page' => (int) ($st['page'] ?? 0), 'done' => $run['status'] !== 'running', 'wait' => $wait, 'error' => $run['error'], 'stats' => $run['stats']];
    }

    /** Причины пропуска [ключ => текст] (статистика запуска) */
    public static function skipReasons(): array
    {
        return [];
    }

    /** «: нет в наличии у поставщика 12, категория не сопоставлена 3…» */
    public static function skipText(array $skip): string
    {
        if (!$skip) return '';
        $parts = [];
        $names = static::skipReasons();
        arsort($skip);
        foreach ($skip as $k => $n) $parts[] = ($names[$k] ?? $k) . ' — ' . $n;
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
        static::ensureSchema();
        $rows = App::db()->all('SELECT id, kind, src, origin, status, stats, state, error, started_at, finished_at, updated_at FROM supplier_runs WHERE supplier = ? ORDER BY id DESC LIMIT ' . max(1, $limit), [static::CODE]);
        foreach ($rows as &$r) {
            $s = json_decode((string) $r['stats'], true); $r['stats'] = (is_array($s) ? $s : []) + static::emptyStats();
            $s = json_decode((string) $r['state'], true); $r['state'] = is_array($s) ? $s : [];
        }
        unset($r);
        return $rows;
    }

    /** Последний завершённый запуск (боевой или пробный) — для «несопоставленных категорий» */
    public static function lastFinished(): ?array
    {
        static::ensureSchema();
        $id = App::db()->value("SELECT id FROM supplier_runs WHERE supplier = ? AND kind IN ('run', 'dry') AND status = 'done' ORDER BY id DESC LIMIT 1", [static::CODE]);
        return $id ? static::run((int) $id) : null;
    }

    /**
     * Уборка: отчёты и файлы запусков старше «Хранить отчёты, дней» (как save_log_int старого загрузчика);
     * последние 5 запусков остаются всегда. Загруженные для прогона файлы — через сутки.
     */
    public static function cleanup(): void
    {
        static::ensureSchema();
        $db = App::db();
        $days = max(1, (int) static::cfg('log_days'));
        $keep = array_map('intval', $db->col('SELECT id FROM supplier_runs WHERE supplier = ? ORDER BY id DESC LIMIT 5', [static::CODE]));
        $old = array_map('intval', $db->col("SELECT id FROM supplier_runs WHERE supplier = ? AND status <> 'running' AND started_at < DATE_SUB(NOW(), INTERVAL ? DAY)", [static::CODE, $days]));
        $old = array_values(array_diff($old, $keep));
        foreach (array_chunk($old, 200) as $part) {
            [$ph, $v] = $db->in($part);
            foreach (['supplier_run_log', 'supplier_run_seen'] as $t) $db->query("DELETE FROM `$t` WHERE run_id IN ($ph)", $v);
            $db->query("DELETE FROM supplier_runs WHERE id IN ($ph)", $v);
        }
        foreach ($old as $id) self::rmRunDir($id);
        // журнал изменений («Обзор»: цены, скрытые, вернувшиеся, добавленные) — «{код}.history_days» дней (по умолчанию 365)
        $db->query('DELETE FROM supplier_changes WHERE supplier = ? AND created_at < DATE_SUB(NOW(), INTERVAL ? DAY)', [static::CODE, static::historyDays()]);
        foreach (glob(static::dir() . '/upload-*') ?: [] as $f) if (is_file($f) && filemtime($f) < time() - 86400) @unlink($f);
        // связи с удалёнными товарами (удалили до supplier_excluded или не через админку) — тоже «удалён вручную»: загрузчик
        // их снова не создаёт (решение владельца: удалённое не возвращается; вернуть — «Обзор» поставщика)
        try {
            $db->query('INSERT IGNORE INTO supplier_excluded (supplier, code, name, ext_id, product_id, created_at)
                SELECT l.supplier, l.code, NULL, l.ext_id, l.product_id, NOW() FROM supplier_links l LEFT JOIN products p ON p.id = l.product_id WHERE l.supplier = ? AND p.id IS NULL', [static::CODE]);
        } catch (\PDOException) {
            return;                                                             // нет таблицы (до bin/install.php) — связи не трогать
        }
        $db->query('DELETE l FROM supplier_links l LEFT JOIN products p ON p.id = l.product_id WHERE l.supplier = ? AND p.id IS NULL', [static::CODE]);
    }

    /** Папка запуска со всеми файлами (страницы, фото отложенной порции) */
    protected static function rmRunDir(int $id): void
    {
        $d = Importer::dir() . '/' . static::CODE . '/runs/' . $id;
        if (!is_dir($d)) return;
        foreach (glob($d . '/*') ?: [] as $f) if (is_file($f)) @unlink($f);
        @rmdir($d);
    }

    /** Товары поставщика на сайте: ['total', 'active'] */
    public static function productCounts(): array
    {
        $r = App::db()->row('SELECT COUNT(*) total, SUM(status = 1) active FROM products WHERE supplier = ?', [static::CODE]);
        return ['total' => (int) ($r['total'] ?? 0), 'active' => (int) ($r['active'] ?? 0)];
    }
}
