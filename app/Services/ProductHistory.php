<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\App;
use App\Core\Cache;
use App\Services\Suppliers\SupplierOverview;
use App\Services\Suppliers\Suppliers;

/**
 * Даты и история товара в админке (docs/ARCHITECTURE.md → «Даты и история товара»).
 *
 * Откуда товар — products.source: webasyst (перенос), import (импорт прайса), manual (создан в админке); загрузчики
 * поставщиков source не пишут — источник по products.supplier (код из Suppliers::LIST: Jong•Golf, «Хит обуви», Tom.m…).
 *
 * История — одна лента из журналов, которые уже есть (по индексам, несколько запросов на товар):
 *   admin_log      — правки в админке («было → стало» в details.ch), фото, массовые действия, импорт, автоназвание
 *                    (по записи на товар: product_bulk, product_import, product_autoname, product_nophoto — QUIET);
 *   supplier_changes — загрузчики поставщиков: добавлен, цена поставщика и наша «было → стало», скрыт, нет в наличии, вернулся,
 *                    прочие изменения (edit: ряд, ящик, категория, фото… — «было → стало»);
 *   seo_fix_log    — автоисправление SEO (пакетами; updated_at оно не сдвигает — это не правка данных товара).
 * updated_at сдвигается только реальными изменениями (сохранение без правок, массовое действие над товаром, который уже
 * в нужном состоянии, строка импорта без изменений — не сдвигают).
 */
final class ProductHistory
{
    /** Откуда товар (products.source) */
    public const SOURCES = ['webasyst' => 'перенос с Webasyst', 'import' => 'импорт', 'manual' => 'вручную'];

    /**
     * Записи admin_log об одном товаре, у которых есть общая запись в журнале сотрудников (массовое действие, запуск импорта)
     * или которые сделала система (автоназвание): в «Безопасность → Действия сотрудников» не показываются, их место — история товара
     */
    public const QUIET = ['product_bulk', 'product_import', 'product_autoname', 'product_nophoto'];

    /** Поля с «было → стало» в истории */
    public const FIELDS = [
        'name' => 'название', 'name_uk' => 'название (UA)', 'sku' => 'артикул', 'url' => 'адрес', 'price' => 'цена',
        'compare_price' => 'старая цена', 'purchase_price' => 'закупочная', 'box_qty' => 'пар в ящике', 'min_qty' => 'мин. заказ',
        'stock' => 'остаток', 'in_stock' => 'наличие', 'status' => 'статус', 'badge' => 'бейдж', 'category_id' => 'основная категория',
        'brand_id' => 'бренд', 'size' => 'размеры',
    ];

    /** Тексты и SEO — без значений, только «изменено» (UA — с пометкой) */
    private const CHANGED = [
        'summary' => 'краткое описание изменено', 'description' => 'описание изменено', 'seo_name' => 'SEO-название изменено',
        'h1' => 'H1 изменён', 'meta_title' => 'title изменён', 'meta_description' => 'description изменён', 'meta_keywords' => 'keywords изменены',
        'supplier' => 'поставщик изменён', 'supplier_code' => 'код поставщика изменён',
        'price_manual' => 'закрепление цены изменено (ночная сверка поставщика)',
    ];

    /** Массовые действия списка товаров (ProductsController::BULK) — в заголовке события */
    private const BULK_OPS = ['show' => 'показать на сайте', 'hide' => 'скрыть с сайта', 'instock' => '«в наличии»', 'outstock' => '«нет в наличии»',
        'price' => 'изменение цены', 'addcat' => 'добавить в категорию', 'delcat' => 'убрать из категории'];

    private const MONEY = ['price', 'compare_price', 'purchase_price'];
    private const INTS = ['box_qty', 'min_qty', 'in_stock', 'status', 'category_id', 'brand_id', 'stock', 'nophoto_hidden'];
    /** Служебные колонки — не изменения товара */
    private const SKIP = ['updated_at', 'created_at', 'source'];
    /** Пишутся, но в журнал не попадают: отметка «скрыт: нет фото» снимается вместе со сменой статуса, видна по нему */
    private const UNLOGGED = ['nophoto_hidden'];

    /** Длина значения в «было → стало» */
    private const CUT = 80;

    private static ?array $cats = null;
    private static array $brands = [];
    private static bool $schemaOk = false;

    /** Миграция раздела (database/migrations/product-history.sql) — колонка products.source и индексы; проверка раз в сутки */
    public static function ensureSchema(): void
    {
        if (self::$schemaOk) return;
        Cache::remember('product_history.schema.1', 86400, static function (): int {
            $db = App::db();
            $ok = (int) $db->value("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND COLUMN_NAME = 'source'")
                && (int) $db->value("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'admin_log' AND INDEX_NAME = 'entity'")
                && (int) $db->value("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND INDEX_NAME = 'created'");
            if (!$ok) {
                $sql = (string) preg_replace('/^\s*--.*$/m', '', (string) @file_get_contents(ROOT . '/database/migrations/product-history.sql'));
                foreach (preg_split('/;\s*\n/', $sql) ?: [] as $stmt) {
                    if (trim($stmt) !== '') $db->pdo()->exec($stmt);
                }
            }
            return 1;
        });
        self::$schemaOk = true;
    }

    // ======================================================================= источник

    /** Подпись источника: «перенос с Webasyst», «импорт», «вручную», название загрузчика; '' — неизвестно */
    public static function source(?string $source, ?string $supplier = null): string
    {
        $source = (string) $source;
        if (isset(self::SOURCES[$source])) return self::SOURCES[$source];
        $cls = Suppliers::get($source !== '' ? $source : (string) $supplier);
        return $cls ? (string) $cls::TITLE : '';
    }

    /**
     * «Загружен»: ['label' => источник, 'who' => кто] — у созданного вручную сотрудник, у импорта — задание (первая запись
     * журнала о товаре: product_create / product_import с new); у перенесённых и загрузчиков — только источник
     */
    public static function origin(array $p): array
    {
        $src = (string) ($p['source'] ?? '');
        $out = ['label' => self::source($src, $p['supplier'] ?? null) ?: 'неизвестно', 'who' => ''];
        if (($src !== 'manual' && $src !== 'import') || empty($p['id'])) return $out;
        $db = App::db();
        // у импорта — только запись о создании (new): более поздние импорты того же товара — не «кто загрузил»
        $r = $db->row("SELECT user_id, details FROM admin_log WHERE entity = 'product' AND entity_id = ? AND action = ?" . ($src === 'import' ? ' AND details LIKE ?' : '')
            . ' ORDER BY created_at, id LIMIT 1', array_merge([(int) $p['id'], $src === 'manual' ? 'product_create' : 'product_import'], $src === 'import' ? ['%"new":1%'] : []));
        if (!$r) return $out;
        $d = json_decode((string) $r['details'], true);
        $d = is_array($d) ? $d : [];
        $job = $src === 'import' && !empty($d['job']) ? $db->row('SELECT id, name, user_id FROM import_jobs WHERE id = ?', [(int) $d['job']]) : null;
        $uid = (int) ($r['user_id'] ?: ($d['by'] ?? ($job['user_id'] ?? 0)));
        $name = $uid ? self::staffName($uid, (string) $db->value('SELECT name FROM customers WHERE id = ?', [$uid])) : '';
        $out['who'] = trim(($src === 'import' && !empty($d['job']) ? 'задание №' . (int) $d['job'] . ($job && $job['name'] !== '' ? ' «' . self::cut((string) $job['name']) . '»' : '') . ($name !== '' ? ', ' : '') : '')
            . $name);
        return $out;
    }

    /** Сотрудник в истории — только имя (e-mail и другие данные сотрудника менеджеру не показываются; как в истории заказа) */
    private static function staffName(int $uid, string $name): string
    {
        return trim($name) !== '' ? trim($name) : 'сотрудник №' . $uid;
    }

    // ======================================================================= что изменилось

    /**
     * Отличающиеся поля: [колонка => новое значение] — только те, что есть в $new и отличаются от $old
     * (деньги — до копейки, целые — как числа, NULL = пусто у строк; строки — байт в байт, края и CRLF выравнивает
     * AdminCatalog::keepUnchanged до вызова)
     */
    public static function changed(array $new, array $old): array
    {
        $out = [];
        foreach ($new as $col => $v) {
            if (in_array($col, self::SKIP, true) || !array_key_exists($col, $old)) {
                if (!in_array($col, self::SKIP, true)) $out[$col] = $v;      // колонки не было в строке — считаем изменением
                continue;
            }
            if (!self::same((string) $col, $old[$col], $v)) $out[$col] = $v;
        }
        return $out;
    }

    private static function same(string $col, $a, $b): bool
    {
        $empty = static fn($v) => $v === null || $v === '';
        if (in_array($col, self::MONEY, true)) return abs((float) $a - (float) $b) < 0.005;
        if (in_array($col, self::INTS, true)) return $empty($a) ? $empty($b) : (!$empty($b) && (int) $a === (int) $b);
        return (string) ($a ?? '') === (string) ($b ?? '');
    }

    /** Изменения для журнала: [колонка, было, стало] у FIELDS, [колонка] — у текстов и SEO */
    public static function describe(array $set, array $old): array
    {
        $out = [];
        foreach ($set as $col => $new) {
            if (in_array($col, self::SKIP, true) || in_array($col, self::UNLOGGED, true)) continue;
            $out[] = isset(self::FIELDS[$col]) ? [$col, self::show($col, $old[$col] ?? null), self::show($col, $new)] : [$col];
        }
        return $out;
    }

    /** Значение поля для истории: «1 020», «в наличии», «скрыт», имя категории/бренда… */
    public static function show(string $col, $v): string
    {
        if ($col === 'stock' && ($v === null || $v === '')) return 'не ограничен';
        if ($v === null || $v === '') return '—';
        return match ($col) {
            'price', 'compare_price', 'purchase_price' => rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.'),
            'in_stock' => (int) $v ? 'в наличии' : 'нет в наличии',
            'status' => (int) $v ? 'на сайте' : 'скрыт',
            'category_id' => self::cut((string) (self::cats()[(int) $v]['name'] ?? '№' . (int) $v)),
            'brand_id' => self::cut(self::brand((int) $v)),
            'badge' => (string) (AdminCatalog::BADGES[(string) $v] ?? self::cut((string) $v)),
            default => self::cut((string) $v),
        };
    }

    /** Строка изменения для показа: строка как есть, [поле, было, стало] → «цена: 100 → 110», [поле] → «описание изменено» */
    public static function line($ch): string
    {
        if (is_string($ch)) return $ch;
        if (!is_array($ch) || !isset($ch[0]) || !is_string($ch[0])) return '';
        $col = $ch[0];
        if (count($ch) >= 3) return (self::FIELDS[$col] ?? $col) . ': ' . (string) $ch[1] . ' → ' . (string) $ch[2];
        $uk = str_ends_with($col, '_uk');
        $base = $uk ? substr($col, 0, -3) : $col;
        return (self::CHANGED[$base] ?? (self::FIELDS[$base] ?? $base) . ' изменено') . ($uk ? ' (UA)' : '');
    }

    /** Категория по id (имя) — для «было → стало» */
    public static function categoryName(int $id): string
    {
        return (string) (self::cats()[$id]['name'] ?? '№' . $id);
    }

    private static function cats(): array
    {
        return self::$cats ??= AdminCatalog::categories();
    }

    private static function brand(int $id): string
    {
        if (!array_key_exists($id, self::$brands)) {
            self::$brands[$id] = (string) (Catalog::brands()[$id]['name'] ?? App::db()->value('SELECT name FROM brands WHERE id = ?', [$id]) ?? '№' . $id);
        }
        return self::$brands[$id];
    }

    private static function cut(string $s): string
    {
        $s = trim((string) preg_replace('/\s+/u', ' ', $s));
        return mb_strlen($s) > self::CUT ? mb_substr($s, 0, self::CUT - 1) . '…' : $s;
    }

    // ======================================================================= запись

    /**
     * Записи истории товаров в admin_log пачкой: [[id товара, действие, details], …] (действия из QUIET — без user_id:
     * кто — в details.by, иначе каждый товар массового действия попал бы в «Действия сотрудника»). Время — $at.
     */
    public static function log(array $rows, ?string $at = null): void
    {
        if (!$rows) return;
        $at ??= date('Y-m-d H:i:s');
        $ins = [];
        foreach ($rows as $r) {
            $ins[] = ['user_id' => null, 'action' => (string) $r[1], 'entity' => 'product', 'entity_id' => (int) $r[0],
                'details' => self::json((array) $r[2]), 'ip' => null, 'created_at' => $at];
        }
        App::db()->insertMany('admin_log', $ins, false, 500);
    }

    /** JSON записи журнала не длиннее TEXT (64 КБ): лишние изменения отбрасываются с конца (more — сколько) */
    public static function json(array $d): string
    {
        $f = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE;
        $s = (string) json_encode(array_filter($d, static fn($v) => $v !== null), $f);
        while (strlen($s) > 60000 && !empty($d['ch'])) {
            $cut = max(1, intdiv(count($d['ch']), 4));
            $d['ch'] = array_slice($d['ch'], 0, -$cut);
            $d['more'] = (int) ($d['more'] ?? 0) + $cut;
            $s = (string) json_encode(array_filter($d, static fn($v) => $v !== null), $f);
        }
        return $s;
    }

    // ======================================================================= чтение

    /**
     * Последние события товара из всех журналов, новые сверху: ['items' => [['at', 'title', 'who', 'lines', 'kind'], …],
     * 'more' => есть ли ещё]. $p — строка products (id, created_at, updated_at, source, supplier).
     * Из каждого журнала берётся $limit + 1 последних — после слияния по времени этого достаточно для первых $limit.
     */
    public static function events(array $p, int $limit = 10): array
    {
        $pid = (int) ($p['id'] ?? 0);
        if ($pid <= 0) return ['items' => [], 'more' => false];
        $db = App::db();
        $take = max(1, $limit) + 1;
        $ev = [];
        $src = (string) ($p['source'] ?? '');
        // перенесённый товар: записи журналов о товаре с тем же id до переноса (прежний перенос, тесты) — о других данных
        $since = ($src === 'webasyst' ? self::webasystAt() : null) ?? '1970-01-01 00:00:00';

        // 1. журнал админки: правки, фото, массовые действия, импорт, автоназвание
        $log = $db->all("SELECT id, user_id, action, details, created_at FROM admin_log WHERE entity = 'product' AND entity_id = ? AND created_at >= ?
            ORDER BY created_at DESC, id DESC LIMIT $take", [$pid, $since]);
        $users = []; $jobs = [];
        foreach ($log as &$r) {
            $d = json_decode((string) $r['details'], true);
            $r['d'] = is_array($d) ? $d : [];
            if ($r['user_id']) $users[(int) $r['user_id']] = 1;
            if (!empty($r['d']['by'])) $users[(int) $r['d']['by']] = 1;
            if (!empty($r['d']['job'])) $jobs[(int) $r['d']['job']] = 1;
        }
        unset($r);

        // 2. загрузчики поставщиков
        $sup = [];
        try {
            $sup = $db->all('SELECT id, supplier, run_id, kind, old_cost, new_cost, old_price, new_price, note, created_at FROM supplier_changes
                WHERE product_id = ? AND created_at >= ? ORDER BY created_at DESC, id DESC LIMIT ' . $take, [$pid, $since]);
        } catch (\PDOException) {
            // таблиц автозагрузки ещё нет (database/migrations/suppliers.sql не выполнялась)
        }

        // 3. автоисправление SEO — по пакетам (в пакете у товара до 10 полей)
        $seo = [];
        try {
            $seo = $db->all("SELECT l.id, l.batch_id, l.field, l.lang, l.rule, l.old_value, l.new_value, l.created_at, b.user_id, b.source, b.reverted_at
                FROM seo_fix_log l JOIN seo_fix_batches b ON b.id = l.batch_id WHERE l.entity = 'product' AND l.entity_id = ? AND l.created_at >= ?
                ORDER BY l.id DESC LIMIT " . ($take * 12), [$pid, $since]);
        } catch (\PDOException) {
        }
        foreach ($seo as $r) if ($r['user_id']) $users[(int) $r['user_id']] = 1;

        $names = [];
        if ($users) {
            [$ph, $vals] = $db->in(array_keys($users));
            foreach ($db->all("SELECT id, name FROM customers WHERE id IN ($ph)", $vals) as $u) $names[(int) $u['id']] = self::staffName((int) $u['id'], (string) $u['name']);
        }
        $jobRows = [];
        if ($jobs) {
            [$ph, $vals] = $db->in(array_keys($jobs));
            $jobRows = $db->keyed("SELECT id, name, user_id FROM import_jobs WHERE id IN ($ph)", $vals);
        }
        $who = static fn($uid): string => $uid ? ($names[(int) $uid] ?? 'сотрудник №' . (int) $uid) : '';

        $created = false;                                                    // есть ли в журналах само появление товара
        foreach ($log as $r) {
            $d = $r['d'];
            $lines = array_values(array_filter(array_map([self::class, 'line'], (array) ($d['ch'] ?? []))));
            if (!empty($d['more'])) $lines[] = '…и ещё ' . (int) $d['more'];
            $e = ['at' => (string) $r['created_at'], 'who' => $who($r['user_id'] ?: ($d['by'] ?? null)), 'lines' => $lines, 'kind' => 'admin', 'title' => ''];
            switch ($r['action']) {
                case 'product_create':
                    $e['title'] = 'Создан вручную'; $e['kind'] = 'create'; $created = true;
                    break;
                case 'product_update':
                    $e['title'] = 'Изменён в админке';
                    if (!isset($d['ch'])) $e['lines'][] = 'что изменилось — не записано (сохранение до учёта изменений)';
                    if (!empty($d['auto_name']['name'])) $e['lines'][] = 'название дополнено: «' . $d['auto_name']['name'] . '»';
                    break;
                case 'product_images_upload':
                    $e['title'] = 'Фото добавлено' . (!empty($d['count']) && (int) $d['count'] > 1 ? ' (' . (int) $d['count'] . ')' : '');
                    break;
                case 'product_image_delete':
                    $e['title'] = 'Фото удалено';
                    break;
                case 'product_images_sort':
                    $e['title'] = !empty($d['main']) ? 'Главное фото изменено' : 'Порядок фото изменён';
                    break;
                case 'product_bulk':
                    $e['title'] = 'Массовое действие' . (isset(self::BULK_OPS[$d['op'] ?? '']) ? ': ' . self::BULK_OPS[$d['op']] : '');
                    break;
                case 'product_import':
                    $j = $jobRows[(int) ($d['job'] ?? 0)] ?? null;
                    $e['kind'] = 'import';
                    $e['title'] = 'Импорт' . ($j && $j['name'] !== '' ? ' «' . self::cut((string) $j['name']) . '»' : '') . (!empty($d['job']) ? ' (задание №' . (int) $d['job'] . ')' : '');
                    $e['who'] = $who($d['by'] ?? ($j['user_id'] ?? null)) ?: 'по расписанию';
                    if (!empty($d['new'])) { array_unshift($e['lines'], 'создан из файла'); $e['kind'] = 'create'; $created = true; }
                    if (!empty($d['hide'])) array_unshift($e['lines'], 'скрыт: нет в файле поставщика');
                    break;
                case 'product_nophoto':
                    // NoPhoto (bin/cron.php): товар без фото на сайте не показывается
                    $e['title'] = ($d['op'] ?? '') === 'show' ? 'Показан: фото появилось' : 'Скрыт: нет фото';
                    $e['kind'] = 'auto';
                    $e['who'] = $who($d['by'] ?? null) ?: 'по расписанию';
                    break;
                case 'product_autoname':
                    $e['title'] = 'Автоназвание';
                    $e['kind'] = 'auto';
                    $e['who'] = (string) ($d['src'] ?? 'bin/product-names.php');
                    break;
                default:
                    $e['title'] = \App\Controllers\Admin\SecurityController::actionLabel((string) $r['action']);
            }
            $ev[] = $e;
        }

        $money = static fn($v) => $v === null ? '—' : rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.');
        foreach ($sup as $r) {
            $cls = Suppliers::get((string) $r['supplier']);
            $title = $cls ? (string) $cls::TITLE : (string) $r['supplier'];
            $e = ['at' => (string) $r['created_at'], 'who' => $title . ($r['run_id'] ? ' · запуск №' . (int) $r['run_id'] : ''), 'lines' => [], 'kind' => 'supplier'];
            switch ($r['kind']) {
                case 'new':
                    $e['title'] = 'Добавлен загрузчиком'; $e['kind'] = 'create'; $created = true;
                    if ($r['new_price'] !== null) $e['lines'][] = 'цена: ' . $money($r['new_price']) . ($r['new_cost'] !== null ? ' (у поставщика ' . $money($r['new_cost']) . ')' : '');
                    break;
                case 'price':
                    $e['title'] = 'Цена от поставщика';
                    if ($r['old_price'] !== null || $r['new_price'] !== null) $e['lines'][] = 'цена: ' . $money($r['old_price']) . ' → ' . $money($r['new_price']);
                    if ($r['old_cost'] !== null || $r['new_cost'] !== null) $e['lines'][] = 'у поставщика: ' . $money($r['old_cost']) . ' → ' . $money($r['new_cost']);
                    break;
                case 'edit':
                    // остальные изменения записи загрузчиком («было → стало» через «; » — SupplierSync::editNote)
                    $e['title'] = 'Изменён загрузчиком';
                    $e['lines'] = array_values(array_filter(array_map('trim', explode('; ', (string) $r['note'])), static fn($l) => $l !== ''));
                    break;
                default:
                    // back — снова показан, снова есть после пропусков или снова в наличии (что именно — в note)
                    $e['title'] = match ((string) $r['kind']) {
                        'hidden' => 'Скрыт загрузчиком', 'out' => 'Нет в наличии у поставщика', 'back' => 'Снова есть у поставщика',
                        default => SupplierOverview::KIND_NAMES[$r['kind']] ?? (string) $r['kind'],
                    };
                    if ((string) $r['note'] !== '') $e['lines'][] = (string) $r['note'];
            }
            $ev[] = $e;
        }

        $batches = [];
        foreach ($seo as $r) {
            $b = (int) $r['batch_id'];
            if (!isset($batches[$b])) {
                if (count($batches) >= $take) continue;
                $batches[$b] = ['at' => (string) $r['created_at'], 'kind' => 'seo', 'lines' => [],
                    'title' => 'Автоисправление SEO (пакет №' . $b . ')' . ($r['reverted_at'] ? ' — откачен ' . date('d.m.Y H:i', strtotime((string) $r['reverted_at'])) : ''),
                    'who' => $who($r['user_id']) ?: ((string) $r['source'] === 'cli' ? 'bin/seo-autofix.php' : (string) $r['source'])];
            }
            $field = (string) $r['field'];
            $label = match (preg_replace('/_uk$/', '', $field)) { 'meta_title' => 'title', 'meta_description' => 'description', 'meta_keywords' => 'keywords', 'h1' => 'H1', default => $field }
                . ($r['lang'] === 'uk' ? ' (UA)' : '');
            $new = trim((string) $r['new_value']);
            $batches[$b]['lines'][] = $label . ': «' . self::cut((string) $r['old_value']) . '» → ' . ($new === '' ? 'по шаблону' : '«' . self::cut($new) . '»')
                . (isset(SeoFix::RULES[$r['rule']]) ? ' (' . SeoFix::RULES[$r['rule']] . ')' : '');
        }
        foreach ($batches as $b) { $b['lines'] = array_reverse($b['lines']); $ev[] = $b; }

        // перенос с Webasyst: появление товара и правка на старом сайте — из дат самого товара (журналов старого сайта нет)
        if (!$created && !empty($p['created_at'])) {
            $label = self::source($src, $p['supplier'] ?? null);
            $ev[] = ['at' => (string) $p['created_at'], 'kind' => 'create', 'lines' => [], 'who' => '',
                'title' => $src === 'webasyst' ? 'Создан на старом сайте (перенесён с Webasyst)' : 'Загружен' . ($label !== '' ? ' · ' . $label : '')];
        }
        $waAt = $src === 'webasyst' ? self::webasystAt() : null;
        if ($waAt && !empty($p['updated_at']) && self::after($p['updated_at'], $p['created_at']) && strtotime((string) $p['updated_at']) <= strtotime($waAt)) {
            $ev[] = ['at' => (string) $p['updated_at'], 'kind' => 'webasyst', 'lines' => [], 'who' => '', 'title' => 'Изменён на старом сайте (Webasyst)'];
        }

        // новые сверху; при равном времени — появление товара последним (ниже правок той же секунды)
        $order = array_keys($ev);
        usort($order, static function ($a, $b) use ($ev): int {
            return strcmp($ev[$b]['at'], $ev[$a]['at']) ?: (($ev[$a]['kind'] === 'create') <=> ($ev[$b]['kind'] === 'create')) ?: $a <=> $b;
        });
        $items = array_map(static fn($k) => $ev[$k], $order);
        return ['items' => array_slice($items, 0, $limit), 'more' => count($items) > $limit];
    }

    /**
     * «Изменён»: последнее реальное изменение данных товара (updated_at позже даты загрузки) и кем/чем — событие журнала
     * рядом с updated_at (±2 мин), у перенесённых — «на старом сайте», иначе загрузчик, если товар был у него в том же
     * проходе ($links — SupplierOverview::productLinks: seen_at) — для записей до журнала edit (теперь загрузчики пишут
     * в supplier_changes все реальные изменения: цена, наличие, видимость и edit — остальное). null — изменений после загрузки не было.
     * SEO-автоисправление updated_at не сдвигает и здесь не учитывается (оно есть в истории).
     */
    public static function lastChange(array $p, array $items, array $links = []): ?array
    {
        if (empty($p['updated_at']) || !self::after($p['updated_at'], $p['created_at'] ?? null)) return null;
        $t = strtotime((string) $p['updated_at']);
        $best = null; $gap = 121;
        foreach ($items as $e) {
            if (in_array($e['kind'], ['seo', 'create'], true)) continue;
            $d = abs(strtotime($e['at']) - $t);
            if ($d < $gap) { $gap = $d; $best = $e; }
        }
        if ($best) return ['at' => (string) $p['updated_at'], 'who' => trim($best['title'] . ($best['who'] !== '' ? ' · ' . $best['who'] : ''))];
        foreach ($links as $l) {
            if (!empty($l['seen_at']) && abs(strtotime((string) $l['seen_at']) - $t) <= 120) {
                return ['at' => (string) $p['updated_at'], 'who' => 'загрузчик ' . ($l['title'] ?? $l['supplier'] ?? '')];
            }
        }
        return ['at' => (string) $p['updated_at'], 'who' => 'без записи в журнале' . ($links ? ' (вероятно, загрузчик ' . ($links[0]['title'] ?? $links[0]['supplier'] ?? '') . ')' : '')];
    }

    /** $a позже $b больше чем на секунду (созданный товар: updated_at = created_at) */
    private static function after($a, $b): bool
    {
        return $a !== null && $a !== '' && ($b === null || $b === '' || strtotime((string) $a) > strtotime((string) $b) + 1);
    }

    /** Когда перенесены товары со старого сайта (bin/import-webasyst.php, шаг products); null — не переносились */
    public static function webasystAt(): ?string
    {
        static $v = false;
        if ($v === false) {
            $v = App::db()->value("SELECT value FROM settings WHERE name = 'webasyst.products_at'");
            $v = is_string($v) && $v !== '' ? $v : null;
        }
        return $v;
    }
}
