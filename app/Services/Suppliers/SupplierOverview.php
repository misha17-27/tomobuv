<?php
declare(strict_types=1);

namespace App\Services\Suppliers;

use App\Core\App;

/**
 * Вкладка «Обзор» страницы поставщика (одна для всех адаптеров: Jong•Golf, Хит обуви, Tom.m) и блок «Цена у поставщика: история»
 * в карточке товара. Только чтение: связи supplier_links (сейчас), журнал изменений supplier_changes (что загрузчик реально
 * записал — SupplierSync::journal), запуски supplier_runs. Методы принимают класс адаптера (наследник Supplier).
 */
final class SupplierOverview
{
    /** Списки «с переходом»: ключ ?list= → заголовок */
    public const LISTS = [
        'price'   => 'Изменения цен',
        'hidden'  => 'Пропали у поставщика — скрыты на сайте',
        'missing' => 'Нет у поставщика — ещё на сайте',
        'out'     => 'Нет в наличии у поставщика — на сайте «нет в наличии»',
        'unlinked' => 'Есть у нас, нет у поставщика (товары бренда без связи)',
        'back'    => 'Вернулись у поставщика',
        'new'     => 'Добавлены загрузчиком',
    ];
    public const DIRS = ['all' => 'Все', 'up' => 'Подорожали', 'down' => 'Подешевели'];
    public const PERIODS = [1 => 'сутки', 7 => '7 дней', 30 => '30 дней', 90 => '90 дней', 365 => 'год'];
    public const KIND_NAMES = ['new' => 'добавлен', 'price' => 'цена', 'hidden' => 'скрыт', 'out' => 'нет в наличии', 'back' => 'вернулся', 'edit' => 'изменён'];

    /**
     * Сводка: товары (связано, на сайте, скрыто, нет у поставщика), добавлено (последний боевой запуск, 7 и 30 дней),
     * изменения цен и наличия за 7 / 30 дней, последняя проверка (несопоставленные и пропущенные — из последнего
     * завершённого прогона). @param class-string<Supplier> $cls
     */
    /** Удалённые владельцем товары поставщика (supplier_excluded): ['n' => всего, 'rows' => последние $limit] */
    public static function excluded(string $cls, int $limit = 50): array
    {
        $db = App::db();
        try {
            return ['n' => (int) $db->value('SELECT COUNT(*) FROM supplier_excluded WHERE supplier = ?', [$cls::CODE]),
                'rows' => $db->all('SELECT code, name, ext_id, product_id, created_at FROM supplier_excluded WHERE supplier = ? ORDER BY created_at DESC, code LIMIT ' . max(1, $limit), [$cls::CODE])];
        } catch (\PDOException) {
            return ['n' => 0, 'rows' => []];
        }
    }

    public static function summary(string $cls): array
    {
        $cls::ensureSchema();
        $db = App::db();
        $code = $cls::CODE;
        $c = $db->row('SELECT COUNT(*) total, SUM(p.status = 1) active, SUM(p.status = 0) hidden, SUM(p.status = 0 AND l.hidden = 1) by_us,
            SUM(l.missed > 0 AND p.status = 1) missing, SUM(p.status = 1 AND p.in_stock = 0) out_stock FROM supplier_links l JOIN products p ON p.id = l.product_id WHERE l.supplier = ?', [$code]) ?: [];
        $d7 = date('Y-m-d H:i:s', time() - 7 * 86400);
        $d30 = date('Y-m-d H:i:s', time() - 30 * 86400);
        $ev = [];
        foreach ($db->all('SELECT kind, SUM(created_at >= ?) d7, COUNT(*) d30 FROM supplier_changes WHERE supplier = ? AND created_at >= ? GROUP BY kind', [$d7, $code, $d30]) as $r) {
            $ev[(string) $r['kind']] = ['d7' => (int) $r['d7'], 'd30' => (int) $r['d30']];
        }
        $up = $db->row("SELECT SUM(" . self::dirSql() . " > 0) up, SUM(" . self::dirSql() . " < 0) down FROM supplier_changes WHERE supplier = ? AND kind = 'price' AND created_at >= ?", [$code, $d30]) ?: [];
        $liveId = $db->value("SELECT id FROM supplier_runs WHERE supplier = ? AND kind = 'run' ORDER BY id DESC LIMIT 1", [$code]);
        $live = $liveId ? $cls::run((int) $liveId) : null;
        $check = $cls::lastFinished();
        $own = $cls::ownBrandIds();
        $unlinked = 0;
        if ($own) { [$bph, $bv] = $db->in($own); $unlinked = (int) $db->value("SELECT COUNT(*) FROM products p WHERE p.brand_id IN ($bph) AND p.status = 1 AND NOT EXISTS (SELECT 1 FROM supplier_links l WHERE l.product_id = p.id)", $bv); }
        $s = $check['stats'] ?? [];
        return [
            'linked' => (int) ($c['total'] ?? 0), 'active' => (int) ($c['active'] ?? 0), 'hidden' => (int) ($c['hidden'] ?? 0),
            'hidden_by_us' => (int) ($c['by_us'] ?? 0), 'missing' => (int) ($c['missing'] ?? 0), 'out' => (int) ($c['out_stock'] ?? 0), 'unlinked' => $unlinked,
            'products' => $cls::productCounts(),
            'last_run' => $live, 'added_last' => $live ? (int) $live['stats']['created'] : 0,
            'added_7' => $ev['new']['d7'] ?? 0, 'added_30' => $ev['new']['d30'] ?? 0,
            'price_7' => $ev['price']['d7'] ?? 0, 'price_30' => $ev['price']['d30'] ?? 0, 'up_30' => (int) ($up['up'] ?? 0), 'down_30' => (int) ($up['down'] ?? 0),
            'hidden_30' => $ev['hidden']['d30'] ?? 0, 'back_30' => $ev['back']['d30'] ?? 0, 'out_30' => $ev['out']['d30'] ?? 0,
            'check' => $check,
            'unmapped' => array_sum(array_map('intval', (array) ($s['unmapped'] ?? []))), 'unmapped_cats' => count((array) ($s['unmapped'] ?? [])),
            'skipped' => (int) ($s['skipped'] ?? 0), 'existing' => (int) ($s['existing'] ?? 0), 'skip' => (array) ($s['skip'] ?? []),
            'missing_runs' => max(1, (int) ($cls::cfg('missing_runs') ?: 1)),
            'history_days' => $cls::historyDays(),
        ];
    }

    /** Направление изменения цены: по цене поставщика, если она известна и изменилась, иначе по нашей цене (SQL-выражение) */
    private static function dirSql(string $a = ''): string
    {
        $p = $a !== '' ? $a . '.' : '';
        return "COALESCE(NULLIF({$p}new_cost - {$p}old_cost, 0), {$p}new_price - {$p}old_price)";
    }

    /**
     * Строки списка (?list=…) с пагинацией. $f: dir — all | up | down (цены), days — период (цены, вернувшиеся, добавленные).
     * @return array{0: array, 1: int} [строки, всего]
     */
    public static function list(string $cls, string $list, array $f, int $page, int $per): array
    {
        $db = App::db();
        $code = $cls::CODE;
        $off = max(0, ($page - 1) * $per);
        $days = isset(self::PERIODS[(int) ($f['days'] ?? 0)]) ? (int) $f['days'] : 30;
        $since = date('Y-m-d H:i:s', time() - $days * 86400);
        $lim = ' LIMIT ' . (int) $per . ' OFFSET ' . (int) $off;
        switch ($list) {
            case 'hidden':                                                   // сейчас: скрыт загрузчиком (нет у поставщика / цвет закончился)
                $where = 'l.supplier = ? AND l.hidden = 1 AND p.status = 0';
                $total = (int) $db->value("SELECT COUNT(*) FROM supplier_links l JOIN products p ON p.id = l.product_id WHERE $where", [$code]);
                $rows = $db->all("SELECT l.code, l.ext_id, l.product_id, l.missed, l.seen_at, l.cost, p.name, p.url, p.status, p.price,
                    (SELECT MAX(c.created_at) FROM supplier_changes c WHERE c.product_id = l.product_id AND c.supplier = l.supplier AND c.kind = 'hidden') hidden_at
                    FROM supplier_links l JOIN products p ON p.id = l.product_id WHERE $where ORDER BY l.seen_at DESC, l.product_id DESC" . $lim, [$code]);
                return [$rows, $total];
            case 'unlinked':                                                 // сейчас: товары бренда поставщика на сайте без связи с ним (и с другими)
                $own = $cls::ownBrandIds();
                if (!$own) return [[], 0];
                [$bph, $bv] = $db->in($own);
                $where = "p.brand_id IN ($bph) AND p.status = 1 AND NOT EXISTS (SELECT 1 FROM supplier_links l WHERE l.product_id = p.id)";
                $total = (int) $db->value("SELECT COUNT(*) FROM products p WHERE $where", $bv);
                $rows = $db->all("SELECT '' code, NULL ext_id, p.id product_id, p.name, p.url, p.status, p.price, p.in_stock, p.supplier, p.created_at FROM products p WHERE $where ORDER BY p.id DESC" . $lim, $bv);
                return [$rows, $total];
            case 'out':                                                      // сейчас: на сайте, но «нет в наличии» (так у поставщика)
                $where = 'l.supplier = ? AND p.status = 1 AND p.in_stock = 0';
                $total = (int) $db->value("SELECT COUNT(*) FROM supplier_links l JOIN products p ON p.id = l.product_id WHERE $where", [$code]);
                $rows = $db->all("SELECT l.code, l.ext_id, l.product_id, l.missed, l.seen_at, l.cost, p.name, p.url, p.status, p.price,
                    (SELECT MAX(c.created_at) FROM supplier_changes c WHERE c.product_id = l.product_id AND c.supplier = l.supplier AND c.kind = 'out') out_at
                    FROM supplier_links l JOIN products p ON p.id = l.product_id WHERE $where ORDER BY l.seen_at DESC, l.product_id DESC" . $lim, [$code]);
                return [$rows, $total];
            case 'missing':                                                  // сейчас: не было в последних полных проходах, но на сайте
                $where = 'l.supplier = ? AND l.missed > 0 AND p.status = 1';
                $total = (int) $db->value("SELECT COUNT(*) FROM supplier_links l JOIN products p ON p.id = l.product_id WHERE $where", [$code]);
                $rows = $db->all("SELECT l.code, l.ext_id, l.product_id, l.missed, l.seen_at, l.cost, p.name, p.url, p.status, p.price
                    FROM supplier_links l JOIN products p ON p.id = l.product_id WHERE $where ORDER BY l.missed DESC, l.seen_at, l.product_id" . $lim, [$code]);
                return [$rows, $total];
            case 'back':
            case 'new':
            case 'price':
                $where = 'c.supplier = ? AND c.kind = ? AND c.created_at >= ?';
                $p = [$code, $list, $since];
                if ($list === 'price' && ($f['dir'] ?? 'all') === 'up') $where .= ' AND ' . self::dirSql('c') . ' > 0';
                if ($list === 'price' && ($f['dir'] ?? 'all') === 'down') $where .= ' AND ' . self::dirSql('c') . ' < 0';
                $total = (int) $db->value("SELECT COUNT(*) FROM supplier_changes c WHERE $where", $p);
                $rows = $db->all("SELECT c.id, c.run_id, c.product_id, c.code, c.kind, c.old_cost, c.new_cost, c.old_price, c.new_price, c.note, c.created_at,
                    p.name, p.url, p.status, p.price, l.ext_id FROM supplier_changes c LEFT JOIN products p ON p.id = c.product_id
                    LEFT JOIN supplier_links l ON l.supplier = c.supplier AND l.code = c.code WHERE $where ORDER BY c.id DESC" . $lim, $p);
                foreach ($rows as &$r) $r['pct'] = self::pct($r);
                unset($r);
                return [$rows, $total];
        }
        return [[], 0];
    }

    /** Изменение в процентах: цены поставщика (если известна обе), иначе нашей цены; null — не посчитать */
    public static function pct(array $r): ?float
    {
        foreach ([['old_cost', 'new_cost'], ['old_price', 'new_price']] as [$a, $b]) {
            $o = $r[$a] !== null ? (float) $r[$a] : 0.0; $n = $r[$b] !== null ? (float) $r[$b] : 0.0;
            if ($o > 0 && $n > 0 && abs($n - $o) >= 0.005) return round(($n - $o) / $o * 100, 1);
        }
        return null;
    }

    /**
     * Последние запуски (боевые и пробные): время, длительность, проверено, добавлено, изменено цен, скрыто, вернулось,
     * ошибки, запросов к сайту поставщика (если адаптер считает). Изменения цен и вернувшиеся — из журнала изменений.
     */
    public static function runs(string $cls, int $limit = 10): array
    {
        $db = App::db();
        $rows = $db->all("SELECT id, kind, src, origin, status, stats, state, error, started_at, finished_at, updated_at FROM supplier_runs
            WHERE supplier = ? AND kind IN ('run', 'dry') ORDER BY id DESC LIMIT " . max(1, $limit), [$cls::CODE]);
        if (!$rows) return [];
        [$ph, $v] = $db->in(array_map(static fn($r) => (int) $r['id'], $rows));
        $ch = [];
        foreach ($db->all("SELECT run_id, kind, COUNT(*) n FROM supplier_changes WHERE run_id IN ($ph) GROUP BY run_id, kind", $v) as $r) $ch[(int) $r['run_id']][(string) $r['kind']] = (int) $r['n'];
        foreach ($rows as &$r) {
            $s = json_decode((string) $r['stats'], true);
            $r['stats'] = (is_array($s) ? $s : []) + $cls::emptyStats();
            $st = json_decode((string) $r['state'], true);
            $r['mode'] = is_array($st) ? (string) ($st['mode'] ?? '') : '';
            $r['complete'] = is_array($st) && !empty($st['complete']);
            unset($r['state']);
            $end = $r['finished_at'] ?? $r['updated_at'] ?? $r['started_at'];
            $r['duration'] = max(0, (int) strtotime((string) $end) - (int) strtotime((string) $r['started_at']));
            $r['prices'] = $ch[(int) $r['id']]['price'] ?? 0;
            $r['back'] = $ch[(int) $r['id']]['back'] ?? 0;
            $r['hidden'] = (int) $r['stats']['hidden_color'] + (int) $r['stats']['hidden_missing'];
        }
        unset($r);
        return $rows;
    }

    /** «1 ч 05 мин», «3 мин 20 с», «12 с» */
    public static function duration(int $sec): string
    {
        if ($sec >= 3600) return intdiv($sec, 3600) . ' ч ' . sprintf('%02d', intdiv($sec % 3600, 60)) . ' мин';
        if ($sec >= 60) return intdiv($sec, 60) . ' мин ' . ($sec % 60) . ' с';
        return $sec . ' с';
    }

    /** Ход запуска для «Обзора»: идущий (или ждущий продолжения) — прогресс; плюс время следующего по расписанию */
    public static function live(string $cls): array
    {
        $active = $cls::active();
        $paused = $active ? null : $cls::paused();
        $run = $active ?? $paused;
        return ['ok' => true, 'running' => (bool) $active, 'paused' => (bool) $paused, 'id' => $run ? (int) $run['id'] : null,
            'kind' => $run['kind'] ?? null, 'progress' => $run ? $cls::progress($run) : null, 'started' => $run['started_at'] ?? null,
            'origin' => $run['origin'] ?? null, 'next' => $cls::nextRun()];
    }

    // ================================================================== карточка товара

    /** Связи товара с поставщиками: [['supplier', 'title', 'code', 'label' (код для людей — Supplier::codeLabel), 'cost', 'seen_at', 'missed', 'hidden']] */
    public static function productLinks(int $pid): array
    {
        if ($pid <= 0 || !self::tables()) return [];
        $out = [];
        foreach (App::db()->all('SELECT supplier, code, ext_id, cost, seen_at, missed, hidden FROM supplier_links WHERE product_id = ? ORDER BY supplier, code', [$pid]) as $r) {
            $cls = Suppliers::get((string) $r['supplier']);
            $r['title'] = $cls ? $cls::TITLE : (string) $r['supplier'];
            $r['label'] = $cls ? $cls::codeLabel((string) $r['code'], $r['ext_id'] !== null ? (string) $r['ext_id'] : null) : (string) $r['code'];
            $r['base'] = $cls ? '/admin/suppliers/' . $r['supplier'] . '/' : null;
            $out[] = $r;
        }
        return $out;
    }

    /** История товара у поставщика (цены, добавлен, скрыт, вернулся) — новые сверху */
    public static function productHistory(int $pid, int $limit = 20): array
    {
        if ($pid <= 0 || !self::tables()) return [];
        $rows = App::db()->all('SELECT id, supplier, run_id, kind, old_cost, new_cost, old_price, new_price, note, created_at FROM supplier_changes
            WHERE product_id = ? ORDER BY id DESC LIMIT ' . max(1, $limit), [$pid]);
        foreach ($rows as &$r) $r['pct'] = self::pct($r);
        unset($r);
        return $rows;
    }

    /** Таблицы раздела уже созданы (карточка товара не создаёт их сама) */
    private static function tables(): bool
    {
        static $ok = null;
        if ($ok === null) {
            try {
                Supplier::ensureSchema();
                $ok = true;
            } catch (\Throwable) {
                $ok = false;
            }
        }
        return $ok;
    }
}
