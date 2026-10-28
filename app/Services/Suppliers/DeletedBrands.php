<?php
declare(strict_types=1);

namespace App\Services\Suppliers;

use App\Core\App;

/**
 * Бренды, которые владелец удалил на сайте («Бренды» → «Удалить», решение 28.10.2026): загрузчики поставщиков новые товары
 * этих брендов не создают (причина пропуска brand_deleted). Ключ — HitObuviSync::bkey названия («Ok Shoes» = «OK-SHOES»).
 * Удалили дубль, а бренд с тем же ключом на сайте остался, — не запоминается (товары пойдут в оставшийся). Товары, удалённые
 * вместе с брендом, — в supplier_excluded с brand_id бренда. Список и «Вернуть» — «Бренды → Удалённые».
 */
final class DeletedBrands
{
    /** @var array<string, string>|null [ключ => название] — на минуту (загрузчик идёт до 40 мин подряд: удалённый за это время — со следующей порции) */
    private static ?array $keys = null;
    private static float $keysAt = 0.0;

    public static function key(string $name): string
    {
        return HitObuviSync::bkey($name);
    }

    /** @return array<string, string> [ключ => название]; таблицы ещё нет (до bin/install.php) — пусто */
    public static function keys(): array
    {
        if (self::$keys !== null && microtime(true) - self::$keysAt < 60) return self::$keys;
        self::$keys = [];
        self::$keysAt = microtime(true);
        try {
            foreach (App::db()->all('SELECT bkey, name FROM brand_excluded') as $r) self::$keys[(string) $r['bkey']] = (string) $r['name'];
        } catch (\PDOException) {
        }
        return self::$keys;
    }

    /** Запись об удалённом бренде с таким названием (по ключу) или null */
    public static function find(string $name): ?array
    {
        $k = self::key($name);
        if ($k === '') return null;
        try {
            return App::db()->row('SELECT * FROM brand_excluded WHERE bkey = ?', [$k]);
        } catch (\PDOException) {
            return null;
        }
    }

    /**
     * Запомнить удалённый бренд. Другой бренд сайта с тем же ключом остался (удалили дубль) — не запоминается: false.
     * Повторное удаление (бренд создали снова) — та же запись, товары складываются.
     */
    public static function add(int $brandId, string $name, int $products): bool
    {
        $k = self::key($name);
        if ($k === '') return false;
        $db = App::db();
        foreach ($db->all('SELECT name FROM brands WHERE id <> ?', [$brandId]) as $b) if (self::key((string) $b['name']) === $k) return false;
        $db->query('INSERT INTO brand_excluded (bkey, name, brand_id, products, created_at) VALUES (?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE name = VALUES(name), brand_id = VALUES(brand_id), products = products + VALUES(products), created_at = NOW()',
            [$k, mb_substr($name, 0, 255), $brandId, max(0, $products)]);
        self::$keys = null;
        return true;
    }

    /** Добавить к записи товары, удалённые следующим нажатием (у бренда больше, чем удаляется за раз) */
    public static function addProducts(int $brandId, int $n): void
    {
        if ($n > 0) App::db()->query('UPDATE brand_excluded SET products = products + ? WHERE brand_id = ?', [$n, $brandId]);
    }

    /** @return list<array> записи для списка (новые — первыми) с числом товаров, которые ещё не загружаются (left) */
    public static function all(): array
    {
        $db = App::db();
        try {
            $rows = $db->all('SELECT * FROM brand_excluded ORDER BY created_at DESC, id DESC');
            $left = $db->pairs('SELECT brand_id, COUNT(*) FROM supplier_excluded WHERE brand_id IS NOT NULL GROUP BY brand_id');
        } catch (\PDOException) {
            return [];
        }
        foreach ($rows as &$r) $r['left'] = (int) ($left[(int) $r['brand_id']] ?? 0);
        unset($r);
        return $rows;
    }

    public static function count(): int
    {
        try {
            return (int) App::db()->value('SELECT COUNT(*) FROM brand_excluded');
        } catch (\PDOException) {
            return 0;
        }
    }

    /**
     * Снова загружать бренд: новые товары бренда поставщики добавят (бренд создадут сами); $products — и удалённые товары
     * бренда (supplier_excluded по brand_id: вместе с брендом и до него). @return array{name: string, products: int}|null
     */
    public static function restore(int $id, bool $products): ?array
    {
        $db = App::db();
        $r = $db->row('SELECT * FROM brand_excluded WHERE id = ?', [$id]);
        if (!$r) return null;
        $n = $products && $r['brand_id'] !== null
            ? $db->query('DELETE FROM supplier_excluded WHERE brand_id = ?', [(int) $r['brand_id']])->rowCount() : 0;
        $db->delete('brand_excluded', 'id = ?', [$id]);
        self::$keys = null;
        return ['name' => (string) $r['name'], 'products' => $n];
    }
}
