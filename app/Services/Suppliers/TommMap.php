<?php
declare(strict_types=1);

namespace App\Services\Suppliers;

use App\Core\App;
use App\Services\Import\Importer;

/**
 * Сопоставление разделов Tom.m (tomm.ua) → категории сайта: общая таблица supplier_category_map, supplier = 'tomm',
 * kind 'cat': category = ext_id = раздел бренда из адреса товара («bosonizhki», подраздел — «kedy/nosochki»),
 * gender = название раздела у бренда; mode '' — загружать, 'skip' — нет; by_size — по детским рядам 12-26 / 26-32 / 32-38 /
 * 36-41 (HitObuviMap::row — по «Размер» товара; подразделы бренда «малі / середні / великі» не нужны).
 * Черновик — database/suppliers/tomm.php (seed() при первом обращении), дальше правит владелец в админке. Раздел, которого
 * нет в таблице (новый у бренда), боевой запуск добавляет без категории сайта — подсветится на вкладке «Категории».
 */
final class TommMap
{
    public const CODE = 'tomm';
    public const CAT_MODES = ['' => 'загружать', 'skip' => 'не загружать'];

    private static ?array $index = null;

    public static function data(): array
    {
        return (array) include ROOT . '/database/suppliers/tomm.php';
    }

    public static function forget(): void
    {
        self::$index = null;
        HitObuviMap::forget();
    }

    /** Заполнить таблицу черновиком, если строк Tom.m нет. @return array [cats, unresolved => [путь …]] */
    public static function seed(bool $force = false): array
    {
        $db = App::db();
        if (!$force && (int) $db->value('SELECT COUNT(*) FROM supplier_category_map WHERE supplier = ?', [self::CODE])) return ['cats' => 0, 'unresolved' => []];
        $ci = Importer::categoryIndex();
        $now = date('Y-m-d H:i:s');
        $rows = []; $sort = 0; $bad = [];
        foreach (self::data()['categories'] as $slug => [$name, $target, $bySize, $mode, $note]) {
            $cid = $target ? JongGolfMap::resolve(str_replace(' > ', '>', (string) $target), $ci) : null;
            if ($target && $cid === null) $bad[] = $target;
            $rows[] = ['supplier' => self::CODE, 'kind' => 'cat', 'ext_id' => $slug, 'season' => '', 'category' => $slug, 'gender' => mb_substr((string) $name, 0, 100),
                'size' => '', 'target' => (string) $target, 'category_id' => $cid, 'by_size' => (int) $bySize, 'mode' => (string) $mode, 'note' => mb_substr((string) $note, 0, 255),
                'hash' => md5('cat|' . $slug), 'sort' => ++$sort, 'updated_at' => $now];
        }
        $db->transaction(static function ($db) use ($rows): void {
            $db->query('DELETE FROM supplier_category_map WHERE supplier = ?', [self::CODE]);
            $db->insertMany('supplier_category_map', $rows, false, 300);
        });
        self::forget();
        return ['cats' => count($rows), 'unresolved' => $bad];
    }

    /** Строки таблицы: [раздел => строка] */
    public static function index(): array
    {
        if (self::$index !== null) return self::$index;
        self::seed();
        $out = [];
        foreach (App::db()->all("SELECT * FROM supplier_category_map WHERE supplier = ? AND kind = 'cat' ORDER BY sort, id", [self::CODE]) as $r) {
            $r['category_id'] = $r['category_id'] !== null ? (int) $r['category_id'] : null;
            $out[(string) $r['category']] = $r;
        }
        return self::$index = $out;
    }

    /**
     * Категория сайта для товара бренда: подраздел («kedy/nosochki»), иначе раздел; ряд — по размерам товара.
     * @return array ['cat_id' => ?int, 'skip' => bool, 'key' => «раздел|название» (несопоставленные), 'via' => cat|'', 'row' => ?array, 'by_size' => bool]
     */
    public static function resolve(string $root, string $sub, string $size): array
    {
        $idx = self::index();
        $slug = $sub !== '' && isset($idx[$root . '/' . $sub]) ? $root . '/' . $sub : $root;
        $row = $idx[$slug] ?? null;
        $out = ['cat_id' => null, 'skip' => false, 'key' => $slug . '|' . ($row['gender'] ?? ''), 'via' => '', 'row' => $row, 'by_size' => false, 'slug' => $slug];
        if (!$row) return $out;
        if ($row['mode'] === 'skip') { $out['skip'] = true; return $out; }
        if (!$row['category_id']) return $out;
        $out['via'] = 'cat';
        $out['by_size'] = (bool) $row['by_size'];
        $out['cat_id'] = $row['by_size'] ? HitObuviMap::sizeChild((int) $row['category_id'], $size) : (int) $row['category_id'];
        return $out;
    }

    /** Раздел бренда, которого нет в таблице (новый): без категории сайта — подсветится в админке */
    public static function addCategory(string $slug, string $name): void
    {
        $db = App::db();
        $sort = (int) $db->value('SELECT COALESCE(MAX(sort), 0) + 1 FROM supplier_category_map WHERE supplier = ?', [self::CODE]);
        $db->query("INSERT IGNORE INTO supplier_category_map (supplier, kind, ext_id, season, category, gender, size, target, category_id, by_size, `mode`, note, hash, sort, updated_at)
            VALUES (?, 'cat', ?, '', ?, ?, '', '', NULL, 1, '', ?, ?, ?, ?)",
            [self::CODE, mb_substr($slug, 0, 64), mb_substr($slug, 0, 100), mb_substr($name !== '' ? $name : $slug, 0, 100), 'новый раздел у бренда ' . date('d.m.Y'), md5('cat|' . $slug), $sort, date('Y-m-d H:i:s')]);
        self::forget();
    }

    /** Правки строк из админки (общая запись — HitObuviMap::assign) */
    public static function assign(array $changes): int
    {
        $n = HitObuviMap::assign($changes, self::CODE, self::CAT_MODES);
        self::forget();
        return $n;
    }

    /** Счётчики: разделов, загружаемых, без категории сайта */
    public static function count(): array
    {
        return HitObuviMap::count(self::CODE);
    }

    /** Строки для редактора */
    public static function all(): array
    {
        self::seed();
        return App::db()->all("SELECT * FROM supplier_category_map WHERE supplier = ? AND kind = 'cat' ORDER BY sort, id", [self::CODE]);
    }
}
