<?php
declare(strict_types=1);

namespace App\Services\Suppliers;

use App\Core\App;
use App\Core\Settings;
use App\Services\Import\Importer;

/**
 * Общее у загрузчиков со страниц сайта поставщика («Хит обуви», Tom.m): наценка (цены на сайте поставщика — закупочные:
 * без коэффициента загрузку нельзя ни включить, ни запустить вручную), режим прохода по расписанию (полный — не чаще
 * full_hours ч и в окне full_from–full_to, иначе быстрый), разбор ошибок сайта (429/5xx — пауза 60 с с удвоением,
 * 3 подряд — ошибка; лимит запросов — проход неполный, товары не скрываются), проверка общих настроек, «Проверка связи».
 * Запросы — SiteClient адаптера, порции — WebSync.
 */
abstract class WebSupplier extends Supplier
{
    protected const LIVE_SRC = 'web';
    public const CHECKS_MISSING = true;
    public const COUNTS_REQUESTS = true;
    public const ROUND_STEPS = ['0' => 'до копеек', '1' => 'до 1 грн', '5' => 'до 5 грн', '10' => 'до 10 грн'];
    public const ROUND_MODES = ['math' => 'по правилам математики', 'up' => 'всегда вверх'];
    public const MODES = ['quick' => 'быстрый (новые товары сверху списков)', 'full' => 'полный проход'];

    // ================================================================== наценка и включение

    /** Включено владельцем и задана наценка (без неё цены были бы закупочными) */
    public static function enabled(): bool
    {
        return static::on('enabled') && static::markup() !== null;
    }

    /** «Запустить сейчас» — и при выключенной автозагрузке (ручной запуск), но только с наценкой: цены поставщика — закупочные */
    public static function manualAllowed(): bool
    {
        return static::markup() !== null;
    }

    public static function manualHint(): string
    {
        return static::markup() === null ? 'Сначала задайте наценку (коэффициент) в настройках — цены поставщика закупочные' : '';
    }

    /** Наценка: ['k', 'plus', 'step', 'mode'] или null — коэффициент не задан */
    public static function markup(): ?array
    {
        $k = Importer::num(static::cfg('markup_k'));
        if ($k === null || $k <= 0) return null;
        return ['k' => $k, 'plus' => Importer::num(static::cfg('markup_plus')) ?? 0.0, 'step' => (int) static::cfg('round_step'), 'mode' => static::cfg('round_mode')];
    }

    /** Цена на сайте за пару: цена поставщика за пару × k + надбавка, с округлением; без наценки — как у поставщика */
    public static function price(float $pair, ?array $m): float
    {
        if ($pair <= 0) return 0.0;
        if ($m === null) return round($pair, 2);
        $v = $pair * $m['k'] + $m['plus'];
        $step = (int) $m['step'];
        if ($step <= 0) return round($v, 2);
        $v = $m['mode'] === 'up' ? ceil(round($v / $step, 6)) * $step : round($v / $step) * $step;
        return max((float) $step, (float) $v);
    }

    /**
     * Общие поля формы настроек: числа в границах $ranges ([ключ => [min, max]]), окна часов (запуска и полного прохода),
     * пауза ≥ 1,5 с, наценка (коэффициент 0,5–10, надбавка, округление), «включено» — только с наценкой. $in — значения
     * формы (нормализуются), ошибки — в $err по полям.
     */
    protected static function checkCommon(array &$in, array &$err, array $ranges): void
    {
        foreach ($ranges as $k => [$min, $max]) {
            if (!preg_match('/^\d{1,5}$/', (string) $in[$k]) || (int) $in[$k] < $min || (int) $in[$k] > $max) $err[$k] = "Число от $min до $max";
        }
        if (!isset($err['window_from'], $err['window_to']) && (int) $in['window_from'] > (int) $in['window_to']) $err['window_to'] = 'Конец окна раньше начала';
        if (isset($in['full_from'], $in['full_to']) && !isset($err['full_from'], $err['full_to']) && (int) $in['full_from'] > (int) $in['full_to']) $err['full_to'] = 'Конец окна раньше начала';
        $delay = Importer::num((string) $in['delay']);
        if ($delay === null || $delay < SiteClient::MIN_DELAY || $delay > 30) $err['delay'] = 'Пауза — от 1,5 до 30 с';
        else $in['delay'] = rtrim(rtrim(number_format($delay, 1, '.', ''), '0'), '.');
        if ($in['markup_k'] !== '') {
            $k = Importer::num($in['markup_k']);
            if ($k === null || $k < 0.5 || $k > 10) $err['markup_k'] = 'Коэффициент от 0,5 до 10 (1,2 — +20 %)';
            else $in['markup_k'] = rtrim(rtrim(number_format($k, 4, '.', ''), '0'), '.');
        }
        $plus = Importer::num($in['markup_plus'] === '' ? '0' : $in['markup_plus']);
        if ($plus === null || abs($plus) > 10000) $err['markup_plus'] = 'Надбавка — число от −10 000 до 10 000 грн';
        else $in['markup_plus'] = rtrim(rtrim(number_format($plus, 2, '.', ''), '0'), '.');
        if (!isset(self::ROUND_STEPS[$in['round_step']])) $in['round_step'] = '1';
        if (!isset(self::ROUND_MODES[$in['round_mode']])) $in['round_mode'] = 'math';
        if ($in['enabled'] === '1' && ($in['markup_k'] === '' || isset($err['markup_k']))) $err['enabled'] = 'Чтобы включить загрузку, укажите наценку (коэффициент): цены поставщика — закупочные';
    }

    // ================================================================== запуски

    public static function emptyStats(): array
    {
        return ['pages' => 0, 'requests' => 0, 'products' => 0, 'created' => 0, 'updated' => 0, 'same' => 0, 'hidden_color' => 0, 'hidden_missing' => 0,
            'skipped' => 0, 'errors' => 0, 'warnings' => 0, 'photos' => 0, 'existing' => 0, 'out' => 0, 'skip' => [], 'unmapped' => [], 'found' => [], 'new_cats' => []];
    }

    /**
     * Из cron: как у всех поставщиков, но шагами не дольше 40 мин — долгий проход продолжается следующим часовым cron
     * (запуск без движения больше STALE = 15 мин считается прерванным и подхватывается)
     */
    public static function cron(callable $say, float $maxTime = 2400.0): void
    {
        parent::cron($say, $maxTime);
    }

    /**
     * Режим боевого запуска «по расписанию»: полный (сверка цен и наличия всех товаров), если с начала последнего завершённого
     * полного прохода прошло не меньше full_hours ч (−10 мин, как расписание) и сейчас окно full_from–full_to
     * (запоздал больше чем на сутки — полный и вне окна); иначе быстрый (только новые сверху списков). Последний запуск в окне
     * полного прохода (следующий через period ч — уже вне окна) — полный и чуть раньше срока, если прошло не меньше
     * full_hours − period ч: период 5 ч не кратен суткам, время запуска сдвигается на час в сутки, и без этого ночное окно
     * 0–6 раз в неделю оставалось бы без полного прохода (06:00 → 02:00 через 20 ч — «рано» → следующий полный через 45 ч)
     */
    public static function autoMode(?int $now = null): string
    {
        $now ??= time();
        $last = App::db()->value("SELECT MAX(started_at) FROM supplier_runs WHERE supplier = ? AND kind = 'run' AND src = ? AND status = 'done' AND state LIKE ?",
            [static::CODE, static::liveSrc(), '%"mode":"full"%"complete":true%']);
        $hours = max(1, (int) static::cfg('full_hours'));
        $ago = $last ? $now - (int) strtotime((string) $last) : PHP_INT_MAX;
        $from = (int) static::cfg('full_from');
        $to = (int) static::cfg('full_to');
        $in = static fn(int $t): bool => (int) date('G', $t) >= $from && (int) date('G', $t) <= $to;
        if ($ago < $hours * 3600 - 600) {
            $period = max(1, (int) static::cfg('period'));
            return $in($now) && !$in($now + $period * 3600) && $ago >= max(1, $hours - $period) * 3600 - 600 ? 'full' : 'quick';
        }
        return $in($now) || ($last && $ago >= ($hours + 24) * 3600) ? 'full' : 'quick';
    }

    protected static function failure(\Throwable $e): array
    {
        // ошибка сайта поставщика (SupplierError) — ожидаемая: в отчёт запуска, не в журнал ошибок сайта
        return [$e instanceof \RuntimeException ? $e->getMessage() : 'Ошибка: ' . $e->getMessage(), $e instanceof SupplierError];
    }

    /**
     * Ошибка сайта во время шага: retry (429/5xx, нет ответа) — пауза 60 с с удвоением (шаг заканчивается с wait), третья
     * подряд — ошибка запуска (товары не скрываются); limit — проход неполный, сразу завершение без скрытия; прочее — дальше.
     */
    protected static function onSiteError(array &$run, SupplierError $e, SiteClient $client): void
    {
        $id = (int) $run['id'];
        $st = &$run['state'];
        $st['requests'] = $client->used();
        $run['stats']['requests'] = $client->used();
        if ($e->kind === 'retry') {
            $st['fails'] = (int) ($st['fails'] ?? 0) + 1;
            $c = get_class($e);
            if ($st['fails'] >= 3) throw new $c($e->getMessage() . ' — третий раз подряд, загрузка остановлена (товары не скрываются)', 'retry', $e->http);
            $pause = 60 * (2 ** ($st['fails'] - 1));
            $st['retry_at'] = time() + $pause;
            $st['wait'] = 1;
            $run['stats']['warnings']++;
            static::log($id, 'warn', $e->getMessage() . ' — пауза ' . $pause . ' с, затем повтор (' . $st['fails'] . ' из 3)');
            static::save($run);
            static::flushLog();
            return;
        }
        if ($e->kind === 'limit') {
            static::log($id, 'warn', $e->getMessage() . ' — проход неполный: остальное — в следующий запуск, товары не скрываются');
            $run['stats']['warnings']++;
            $st['complete'] = false;
            $st['phase'] = 'finish';
            static::save($run);
            static::flushLog();
            return;
        }
        throw $e;
    }

    /**
     * Проверка связи (вид запуска test): $probe($client) делает 1–2 запроса и возвращает текст «Связь есть …»; ошибка —
     * исключением. У поставщика ничего не меняется.
     */
    protected static function testRun(int $userId, string $origin, SiteClient $client, callable $probe): array
    {
        static::ensureSchema();
        $db = App::db();
        $id = $db->insert('supplier_runs', ['supplier' => static::CODE, 'kind' => 'test', 'src' => static::liveSrc(), 'origin' => $origin, 'status' => 'running',
            'user_id' => $userId ?: null, 'stats' => '{}', 'state' => '{}', 'started_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]);
        $t = microtime(true);
        try {
            $body = (string) $probe($client);
            $msg = sprintf('Связь есть (%.1f с, запросов %d): ', microtime(true) - $t, $client->used()) . $body;
            static::log($id, 'info', $msg);
            static::flushLog();
            static::finishRun($id, 'done');
            return ['ok' => true, 'message' => $msg];
        } catch (\Throwable $e) {
            static::log($id, 'error', $e->getMessage());
            static::flushLog();
            static::finishRun($id, 'error', mb_substr($e->getMessage(), 0, 1000));
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    protected static function waitLabel(array $st): string
    {
        return (int) ($st['retry_at'] ?? 0) > time() ? ' — пауза поставщика до ' . date('H:i:s', (int) $st['retry_at']) : ' — ждёт окончания перестройки индекса каталога';
    }

    protected static function progressLine(array $p): string
    {
        $s = $p['stats'];
        return $p['label'] . ': запросов ' . ($s['requests'] ?? 0) . ', товаров ' . $s['products'] . ', добавлено ' . $s['created'] . ', изменено ' . $s['updated']
            . ', без изменений ' . $s['same'] . ', найдено чужих ' . ($s['existing'] ?? 0) . ', пропущено ' . $s['skipped'];
    }

    protected static function stopNote(array $run): string
    {
        return ' — проход неполный, товары не скрываются';
    }

    /** Как найден товар сайта: [ключ => подпись] (порции адаптера, VIA) */
    public static function viaNames(): array
    {
        return [];
    }

    /** «: по связи 10, по коду 3…» */
    public static function foundText(array $found): string
    {
        if (!$found) return '';
        $names = static::viaNames();
        $parts = [];
        foreach ($found as $k => $n) $parts[] = ($names[$k] ?? $k) . ' — ' . $n;
        return ' (найдено: ' . implode(', ', $parts) . ')';
    }

    /** Запуск — со страниц сайта поставщика (отчёт: запросы, страницы); у Jong•Golf запуск по API — нет */
    public static function webRun(array $run): bool
    {
        return true;
    }

    /** Уже есть хоть один запуск с сайта (для подсказок на экране) */
    public static function lastWeb(): ?array
    {
        $id = App::db()->value("SELECT id FROM supplier_runs WHERE supplier = ? AND src = ? AND kind IN ('run', 'dry') ORDER BY id DESC LIMIT 1", [static::CODE, static::liveSrc()]);
        return $id ? static::run((int) $id) : null;
    }

    /** Связанных товаров: всего, скрыто загрузчиком, не было в последних полных проходах */
    public static function linkCounts(): array
    {
        $r = App::db()->row('SELECT COUNT(*) total, SUM(hidden) hidden, SUM(missed > 0) missed FROM supplier_links WHERE supplier = ?', [static::CODE]);
        return ['total' => (int) ($r['total'] ?? 0), 'hidden' => (int) ($r['hidden'] ?? 0), 'missed' => (int) ($r['missed'] ?? 0)];
    }

    /** Сохранить настройку одной строкой (для CLI проверок) */
    public static function set(string $k, string $v): void
    {
        if (!array_key_exists($k, static::DEFAULTS)) throw new \InvalidArgumentException('Нет такой настройки: ' . $k);
        Settings::set(static::CODE . '.' . $k, $v);
    }

    protected static function num(float $v): string
    {
        return rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');
    }
}
