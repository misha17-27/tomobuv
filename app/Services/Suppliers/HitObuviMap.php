<?php
declare(strict_types=1);

namespace App\Services\Suppliers;

use App\Core\App;
use App\Services\Import\Importer;

/**
 * Сопоставление категорий «Хит обуви» → категории сайта (общая таблица supplier_category_map, supplier = 'hitobuvi'):
 *   kind 'cat'  — категория поставщика: category = путь (/zhenskaya-obuv/w-krossovki), ext_id = её id у поставщика,
 *                 season = раздел (/zhenskaya-obuv), gender = название; mode: '' — в категорию сайта, 'skip' — не загружать,
 *                 'type' — по типу из названия (сквозные: кожаная, великаны); by_size — по детским размерным рядам;
 *   kind 'type' — тип из названия в разделе: category = тип («шлепанцы»), season = раздел; mode '' — всегда важнее
 *                 категории поставщика (шлепки из «Летней обуви» — в шлепанцы), 'fallback' — только для категорий без своей.
 * Черновик — database/suppliers/hitobuvi.php (seed() при первом обращении), дальше правит владелец в админке.
 *
 * Категория товара (resolve): категория поставщика по адресу товара (путь или /product/view/{id}/), иначе по ссылке
 * корзины → тип из названия «всегда» → категория поставщика → тип «если нет своей». Размерный ряд детских — по нижнему
 * и верхнему размеру: ≤23 и ≤29 → 12-26; ≤28 → 26-32; ≤35 → 32-38; иначе 36-41 (выведено из ≈30 тыс. товаров сайта;
 * подкатегория должна быть у категории сайта, иначе — сама категория).
 */
final class HitObuviMap
{
    public const CODE = 'hitobuvi';
    /** Ряды детских категорий сайта */
    public const ROWS = ['12-26', '26-32', '32-38', '36-41'];
    public const CAT_MODES = ['' => 'загружать', 'skip' => 'не загружать', 'type' => 'по типу из названия'];
    public const TYPE_MODES = ['' => 'всегда', 'fallback' => 'если у категории поставщика нет своей'];

    private static ?array $data = null;
    private static ?array $index = null;
    private static ?array $children = null;

    /** Черновик из database/suppliers/hitobuvi.php */
    public static function data(): array
    {
        return self::$data ??= (array) include ROOT . '/database/suppliers/hitobuvi.php';
    }

    public static function forget(): void
    {
        self::$index = null;
        self::$children = null;
    }

    /** Тип из названия: первое слово (или «Сапоги из пены») по словарю RU/UA → ключ типа; null — неизвестен */
    public static function typeOf(string $name): ?string
    {
        $syn = self::data()['synonyms'] ?? [];
        $words = preg_split('/\s+/u', mb_strtolower(trim(str_replace(['’', 'ʼ'], "'", $name)))) ?: [];
        if (count($words) >= 3 && isset($syn[$words[0] . ' ' . $words[1] . ' ' . $words[2]])) return $syn[$words[0] . ' ' . $words[1] . ' ' . $words[2]];
        $w = trim((string) ($words[0] ?? ''), ".,:;!?()\"'");
        return $syn[$w] ?? null;
    }

    /** Ряд по размерам «22-27» → «12-26» (null — не диапазон) */
    public static function row(string $size): ?string
    {
        if (!preg_match('/^(\d{2})(?:[.,]5)?-(\d{2})(?:[.,]5)?$/', preg_replace('/\s+/u', '', $size) ?? '', $m)) return null;
        $lo = (int) $m[1]; $hi = (int) $m[2];
        if ($lo > $hi) return null;
        if ($lo <= 23 && $hi <= 29) return '12-26';
        if ($lo <= 28) return '26-32';
        if ($lo <= 35) return '32-38';
        return '36-41';
    }

    // ================================================================== таблица

    /** Заполнить таблицу черновиком, если строк «Хит обуви» нет. @return array [cats, types, unresolved => [путь …]] */
    public static function seed(bool $force = false): array
    {
        $db = App::db();
        if (!$force && (int) $db->value('SELECT COUNT(*) FROM supplier_category_map WHERE supplier = ?', [self::CODE])) return ['cats' => 0, 'types' => 0, 'unresolved' => []];
        $d = self::data();
        $ci = Importer::categoryIndex();
        $now = date('Y-m-d H:i:s');
        $rows = []; $sort = 0; $bad = [];
        $resolve = static function (?string $path) use ($ci, &$bad): ?int {
            if ($path === null || $path === '') return null;
            $id = JongGolfMap::resolve(str_replace(' > ', '>', $path), $ci);
            if ($id === null) $bad[$path] = 1;
            return $id;
        };
        foreach ($d['categories'] as $path => [$id, $name, $target, $bySize, $mode, $note]) {
            $root = '/' . explode('/', trim($path, '/'))[0];
            $rows[] = ['supplier' => self::CODE, 'kind' => 'cat', 'ext_id' => $id !== null ? (string) $id : null, 'season' => $root, 'category' => $path, 'gender' => mb_substr((string) $name, 0, 100),
                'size' => '', 'target' => (string) $target, 'category_id' => $resolve($target), 'by_size' => (int) $bySize, 'mode' => (string) $mode, 'note' => mb_substr((string) $note, 0, 255),
                'hash' => md5('cat|' . $path), 'sort' => ++$sort, 'updated_at' => $now];
        }
        foreach ($d['types'] as $root => $types) {
            foreach ($types as $type => [$target, $bySize, $mode]) {
                $rows[] = ['supplier' => self::CODE, 'kind' => 'type', 'ext_id' => null, 'season' => $root, 'category' => $type, 'gender' => '', 'size' => '',
                    'target' => (string) $target, 'category_id' => $resolve($target), 'by_size' => (int) $bySize, 'mode' => (string) $mode, 'note' => '',
                    'hash' => md5('type|' . $root . '|' . $type), 'sort' => ++$sort, 'updated_at' => $now];
            }
        }
        $db->transaction(static function ($db) use ($rows): void {
            $db->query('DELETE FROM supplier_category_map WHERE supplier = ?', [self::CODE]);
            $db->insertMany('supplier_category_map', $rows, false, 300);
        });
        self::forget();
        return ['cats' => count(array_filter($rows, static fn($r) => $r['kind'] === 'cat')), 'types' => count(array_filter($rows, static fn($r) => $r['kind'] === 'type')), 'unresolved' => array_keys($bad)];
    }

    /** Строки для поиска: ['cat' => [путь => строка], 'id' => [id поставщика => строка], 'type' => [раздел => [тип => строка]]] */
    public static function index(): array
    {
        if (self::$index !== null) return self::$index;
        self::seed();
        $out = ['cat' => [], 'id' => [], 'type' => []];
        foreach (App::db()->all('SELECT * FROM supplier_category_map WHERE supplier = ? ORDER BY sort, id', [self::CODE]) as $r) {
            $r['category_id'] = $r['category_id'] !== null ? (int) $r['category_id'] : null;
            if ($r['kind'] === 'cat') {
                $out['cat'][$r['category']] = $r;
                if ($r['ext_id'] !== null && $r['ext_id'] !== '') $out['id'][(string) $r['ext_id']] ??= $r;
            } elseif ($r['kind'] === 'type') {
                $out['type'][$r['season']][$r['category']] = $r;
            }
        }
        foreach (self::data()['aliases'] ?? [] as $path => $id) if (!isset($out['cat'][$path]) && isset($out['id'][(string) $id])) $out['cat'][$path] = $out['id'][(string) $id];
        return self::$index = $out;
    }

    /** Строка категории поставщика по пути или id (null — неизвестна) */
    public static function category(?string $path, ?int $id): ?array
    {
        $idx = self::index();
        if ($id !== null && isset($idx['id'][(string) $id])) return $idx['id'][(string) $id];
        if ($path !== null && isset($idx['cat'][$path])) return $idx['cat'][$path];
        return null;
    }

    /**
     * Категория сайта для товара списка. $root — раздел списка (/zhenskaya-obuv), $pageCat — категория страницы (лист).
     * @return array ['cat_id' => ?int, 'skip' => bool, 'row' => ?array (категория поставщика), 'type' => ?string, 'via' => cat|type|fallback|'',
     *                'key' => «раздел|путь|название» для отчёта о несопоставленных, 'root' => раздел товара]
     */
    public static function resolve(array $it, string $root, ?array $pageCat = null): array
    {
        [$path, $vid] = HitObuviParser::urlCategory((string) $it['url']);
        $row = self::category($path, $vid);
        if (!$row && $pageCat && ($pageCat['mode'] ?? '') !== 'skip' && $pageCat['category'] !== $root) $row = $pageCat;   // список листа: его категория
        $cart = (int) ($it['category_id'] ?? 0);
        if (!$row && $cart && $cart !== 5) $row = self::category(null, $cart);
        $root = $row ? (string) $row['season'] : ($path !== null ? '/' . explode('/', trim($path, '/'))[0] : $root);
        $type = self::typeOf((string) $it['name']);
        $t = $type !== null ? (self::index()['type'][$root][$type] ?? null) : null;
        $key = $root . '|' . ($row['category'] ?? ($path ?? ($vid !== null ? '#' . $vid : ($cart ? '#' . $cart : '?')))) . '|' . ($row['gender'] ?? '');
        $out = ['cat_id' => null, 'skip' => false, 'row' => $row, 'type' => $type, 'via' => '', 'key' => $key, 'root' => $root, 'by_size' => false];
        if ($row && $row['mode'] === 'skip') { $out['skip'] = true; return $out; }
        if ($t && $t['mode'] === '' && $t['category_id']) { $rule = $t; $out['via'] = 'type'; }
        elseif ($row && $row['category_id'] && $row['mode'] !== 'type') { $rule = $row; $out['via'] = 'cat'; }
        elseif ($t && $t['category_id']) { $rule = $t; $out['via'] = 'fallback'; }
        else return $out;
        $out['cat_id'] = (int) $rule['category_id'];
        $out['by_size'] = (bool) $rule['by_size'];
        if ($rule['by_size']) $out['cat_id'] = self::sizeChild((int) $rule['category_id'], (string) ($it['size'] ?? ''));
        return $out;
    }

    /** Подкатегория-ряд («32-38») категории сайта для размеров товара; нет такой — сама категория */
    public static function sizeChild(int $catId, string $size): int
    {
        return self::child($catId, self::row($size));
    }

    /** Подкатегория-ряд $row («32-38») категории сайта (правило рядов — у вызывающего: Jong•Golf — JongGolfMap::row); нет такой — сама категория */
    public static function child(int $catId, ?string $row): int
    {
        if ($row === null) return $catId;
        if (self::$children === null) {
            self::$children = [];
            foreach (App::db()->all('SELECT id, parent_id, name FROM categories WHERE type = 0') as $c) {
                $n = (string) preg_replace('/\s+/u', '', (string) $c['name']);
                if (in_array($n, self::ROWS, true)) self::$children[(int) $c['parent_id']][$n] = (int) $c['id'];
            }
        }
        return self::$children[$catId][$row] ?? $catId;
    }

    /** Разделы поставщика: [путь => название] (черновик + таблица) */
    public static function roots(): array
    {
        $out = [];
        foreach (self::data()['roots'] as $p => $r) $out[$p] = (string) $r[0];
        foreach (self::index()['cat'] as $r) $out[$r['season']] ??= $r['season'];
        return $out;
    }

    /** Пол (характеристика) по разделу */
    public static function gender(string $root): string
    {
        return (string) (self::data()['roots'][$root][1] ?? '');
    }

    /** Добавить категорию поставщика, которой нет в таблице (новая в меню): без категории сайта — подсветится в админке */
    public static function addCategory(string $path, string $name, ?int $id = null): void
    {
        $root = '/' . explode('/', trim($path, '/'))[0];
        $db = App::db();
        $sort = (int) $db->value('SELECT COALESCE(MAX(sort), 0) + 1 FROM supplier_category_map WHERE supplier = ?', [self::CODE]);
        $db->query('INSERT IGNORE INTO supplier_category_map (supplier, kind, ext_id, season, category, gender, size, target, category_id, by_size, mode, note, hash, sort, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, NULL, 0, ?, ?, ?, ?, ?)',
            [self::CODE, 'cat', $id !== null ? (string) $id : null, mb_substr($root, 0, 100), mb_substr($path, 0, 100), mb_substr($name, 0, 100), '', '', '', 'новая у поставщика ' . date('d.m.Y'),
                md5('cat|' . $path), $sort, date('Y-m-d H:i:s')]);
        self::forget();
    }

    /**
     * Сохранить правки строк: [id строки => ['category_id' => int (0 — нет), 'by_size' => 0|1, 'mode' => …]]. Общая для
     * таблиц kind cat/type любого поставщика ($supplier, допустимые режимы категорий — $catModes). @return int изменено
     */
    public static function assign(array $changes, string $supplier = self::CODE, array $catModes = self::CAT_MODES): int
    {
        $ci = Importer::categoryIndex();
        $db = App::db();
        $n = 0;
        $now = date('Y-m-d H:i:s');
        $rows = $db->keyed('SELECT id, kind, category_id, by_size, `mode` FROM supplier_category_map WHERE supplier = ?', [$supplier]);
        $db->transaction(static function ($db) use ($changes, $ci, $now, $rows, $supplier, $catModes, &$n): void {
            foreach ($changes as $id => $ch) {
                $r = $rows[(int) $id] ?? null;
                if (!$r) continue;
                $cid = (int) ($ch['category_id'] ?? 0);
                if ($cid && (!isset($ci['cats'][$cid]) || (int) $ci['cats'][$cid]['type'] !== 0)) continue;
                $modes = $r['kind'] === 'type' ? self::TYPE_MODES : $catModes;
                $mode = (string) ($ch['mode'] ?? $r['mode']);
                if (!isset($modes[$mode])) $mode = (string) $r['mode'];
                $set = ['category_id' => $cid ?: null, 'by_size' => !empty($ch['by_size']) ? 1 : 0, 'mode' => $mode];
                if ((int) $r['category_id'] === $cid && (int) $r['by_size'] === $set['by_size'] && (string) $r['mode'] === $mode) continue;
                $set['target'] = $cid ? mb_substr((string) $ci['path'][$cid], 0, 500) : '';
                $set['updated_at'] = $now;
                $n += $db->update('supplier_category_map', $set, 'id = ? AND supplier = ?', [(int) $id, $supplier]);
            }
        });
        self::forget();
        return $n;
    }

    /** Добавить или заменить правило «тип из названия → категория». @return string|null ошибка */
    public static function putType(string $root, string $type, int $categoryId, bool $bySize, string $mode): ?string
    {
        $type = mb_strtolower(trim($type));
        $type = (self::data()['synonyms'][$type] ?? null) ?? $type;
        if ($type === '' || mb_strlen($type) > 60) return 'Укажите тип (первое слово названия, например «шлепки»).';
        if (!isset(self::roots()[$root])) return 'Неизвестный раздел поставщика.';
        $ci = Importer::categoryIndex();
        if (!isset($ci['cats'][$categoryId]) || (int) $ci['cats'][$categoryId]['type'] !== 0) return 'Выберите категорию сайта.';
        if (!isset(self::TYPE_MODES[$mode])) $mode = '';
        $db = App::db();
        $sort = (int) $db->value('SELECT COALESCE(MAX(sort), 0) + 1 FROM supplier_category_map WHERE supplier = ?', [self::CODE]);
        $db->query('INSERT INTO supplier_category_map (supplier, kind, ext_id, season, category, gender, size, target, category_id, by_size, `mode`, note, hash, sort, updated_at)
            VALUES (?, \'type\', NULL, ?, ?, \'\', \'\', ?, ?, ?, ?, \'\', ?, ?, ?)
            ON DUPLICATE KEY UPDATE target = VALUES(target), category_id = VALUES(category_id), by_size = VALUES(by_size), `mode` = VALUES(`mode`), updated_at = VALUES(updated_at)',
            [$root, $type, mb_substr((string) $ci['path'][$categoryId], 0, 500), $categoryId, $bySize ? 1 : 0, $mode, md5('type|' . $root . '|' . $type), $sort, date('Y-m-d H:i:s')]);
        self::forget();
        return null;
    }

    public static function delete(int $id): void
    {
        App::db()->delete('supplier_category_map', "id = ? AND supplier = ? AND kind = 'type'", [$id, self::CODE]);
        self::forget();
    }

    /** Счётчики для экрана: всего категорий, загружаемых, без категории сайта (загружаемые), правил по типу ($supplier — чья таблица) */
    public static function count(string $supplier = self::CODE): array
    {
        $r = App::db()->row("SELECT SUM(kind = 'cat') cats, SUM(kind = 'cat' AND `mode` <> 'skip') active,
            SUM(kind = 'cat' AND `mode` = '' AND category_id IS NULL) unresolved, SUM(kind = 'type') types FROM supplier_category_map WHERE supplier = ?", [$supplier]);
        return ['cats' => (int) ($r['cats'] ?? 0), 'active' => (int) ($r['active'] ?? 0), 'unresolved' => (int) ($r['unresolved'] ?? 0), 'types' => (int) ($r['types'] ?? 0)];
    }

    /** Строки для редактора: категории поставщика (по разделам) и правила по типу */
    public static function all(): array
    {
        self::seed();
        $cats = []; $types = [];
        foreach (App::db()->all('SELECT * FROM supplier_category_map WHERE supplier = ? ORDER BY season, kind, category', [self::CODE]) as $r) {
            if ($r['kind'] === 'cat') $cats[$r['season']][] = $r;
            elseif ($r['kind'] === 'type') $types[$r['season']][] = $r;
        }
        return [$cats, $types];
    }
}
