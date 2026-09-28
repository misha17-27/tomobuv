<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\App;
use App\Core\Cache;

/**
 * Строит catalog_index (категория → активные товары с учётом подкатегорий и динамических
 * категорий) и category_facets (значения фильтров по категориям).
 *
 * Полная перестройка (~100 тыс. товаров) — несколько секунд: bin/reindex.php
 * Точечная — после сохранения товара в админке: CatalogIndexer::products([$id]).
 */
final class CatalogIndexer
{
    /** Полная перестройка индекса всех категорий */
    public static function rebuildAll(?callable $log = null): void
    {
        $db = App::db();
        $cats = $db->all('SELECT id, parent_id, lft, rgt, type, conditions, include_sub, status FROM categories ORDER BY lft');
        $db->query('TRUNCATE catalog_index');
        $db->query('TRUNCATE category_facets');
        foreach ($cats as $c) {
            self::rebuildCategory($c, $cats);   // и скрытые (status=0): они открываются по прямому адресу
            if ($log) $log('категория ' . $c['id']);
        }
        self::updateCounters();
        Cache::flush();
    }

    /** Перестроить одну категорию */
    public static function rebuildCategory(array $c, ?array $all = null): void
    {
        $db = App::db();
        $id = (int) $c['id'];
        $db->query('DELETE FROM catalog_index WHERE category_id = ?', [$id]);
        $db->query('DELETE FROM category_facets WHERE category_id = ?', [$id]);

        if ((int) $c['type'] === 1) {
            [$where, $params] = self::conditionSql((string) $c['conditions']);
            if ($where === '') return;
            $db->query("INSERT IGNORE INTO catalog_index (category_id, product_id, in_stock, created_at, price, sort, name)
                SELECT ?, p.id, p.in_stock, p.created_at, p.price, 0, LEFT(p.name, 64) FROM products p
                WHERE p.status = 1 AND $where", array_merge([$id], $params));
        } else {
            $ids = [$id];
            if ((int) $c['include_sub']) {
                $all ??= $db->all('SELECT id, lft, rgt, status FROM categories');
                foreach ($all as $x) {
                    if ((int) $x['lft'] > (int) $c['lft'] && (int) $x['rgt'] < (int) $c['rgt']) $ids[] = (int) $x['id'];
                }
            }
            [$ph, $vals] = $db->in($ids);
            $db->query("INSERT IGNORE INTO catalog_index (category_id, product_id, in_stock, created_at, price, sort, name)
                SELECT ?, p.id, p.in_stock, p.created_at, p.price, MIN(cp.sort), LEFT(p.name, 64)
                FROM category_products cp JOIN products p ON p.id = cp.product_id AND p.status = 1
                WHERE cp.category_id IN ($ph) GROUP BY p.id", array_merge([$id], $vals));
        }
        self::rebuildFacets($id);
    }

    /** Значения фильтров категории с количеством товаров */
    public static function rebuildFacets(int $categoryId): void
    {
        $db = App::db();
        $fids = $db->col('SELECT id FROM features WHERE is_filter = 1');
        $db->query('DELETE FROM category_facets WHERE category_id = ?', [$categoryId]);
        if (!$fids) return;
        [$ph, $vals] = $db->in($fids);
        $db->query("INSERT INTO category_facets (category_id, feature_id, value_id, cnt)
            SELECT ci.category_id, pf.feature_id, pf.value_id, COUNT(*) FROM catalog_index ci
            JOIN product_features pf ON pf.product_id = ci.product_id AND pf.feature_id IN ($ph)
            WHERE ci.category_id = ? GROUP BY pf.feature_id, pf.value_id", array_merge($vals, [$categoryId]));
    }

    /**
     * Точечное обновление после изменения товаров: перестраиваются только категории,
     * в которые товары входят (напрямую, через родителей или по условию).
     */
    public static function products(array $productIds): void
    {
        $productIds = array_values(array_unique(array_map('intval', $productIds)));
        if (!$productIds) return;
        $db = App::db();
        [$ph, $vals] = $db->in($productIds);
        $direct = $db->col("SELECT DISTINCT category_id FROM category_products WHERE product_id IN ($ph)", $vals);
        $indexed = $db->col("SELECT DISTINCT category_id FROM catalog_index WHERE product_id IN ($ph)", $vals);
        $all = $db->all('SELECT id, parent_id, lft, rgt, type, conditions, include_sub, status FROM categories');
        $byId = array_column($all, null, 'id');
        $affected = array_flip(array_map('intval', array_merge($direct, $indexed)));
        foreach ($direct as $cid) {                                  // все предки прямых категорий
            $x = $byId[$cid] ?? null;
            foreach ($all as $a) {
                if ($x && (int) $a['lft'] < (int) $x['lft'] && (int) $a['rgt'] > (int) $x['rgt']) $affected[(int) $a['id']] = 1;
            }
        }
        foreach ($all as $a) if ((int) $a['type'] === 1) $affected[(int) $a['id']] = 1;   // динамические — всегда
        foreach (array_keys($affected) as $cid) {
            if (isset($byId[$cid])) self::rebuildCategory($byId[$cid], $all);
        }
        self::updateCounters();
        Cache::flush();
    }

    public static function updateCounters(): void
    {
        $db = App::db();
        $db->query('UPDATE categories c LEFT JOIN (SELECT category_id, COUNT(*) n FROM catalog_index GROUP BY category_id) x
            ON x.category_id = c.id SET c.product_count = COALESCE(x.n, 0)');
        $db->query('UPDATE brands b LEFT JOIN (SELECT brand_id, COUNT(*) n FROM products WHERE status = 1 AND brand_id IS NOT NULL GROUP BY brand_id) x
            ON x.brand_id = b.id SET b.product_count = COALESCE(x.n, 0)');
    }

    /**
     * Условия динамических категорий (формат Webasyst):
     *   compare_price>0 · price<500 · brand.value_id=40 · rating>=4 · create_datetime>=2024-01-01
     * Несколько условий через «&».
     */
    public static function conditionSql(string $cond): array
    {
        $where = []; $params = [];
        foreach (array_filter(array_map('trim', explode('&', $cond))) as $part) {
            if (!preg_match('/^([a-z_0-9.]+)\s*(>=|<=|!=|=|>|<)\s*(.+)$/i', $part, $m)) continue;
            [, $field, $op, $val] = $m;
            $val = trim($val);
            if ($field === 'compare_price' || $field === 'price' || $field === 'rating') {
                $where[] = "p.$field $op ?"; $params[] = (float) $val;
            } elseif ($field === 'create_datetime') {
                $where[] = "p.created_at $op ?"; $params[] = $val;
            } elseif (preg_match('/^([a-z_0-9]+)\.value_id$/i', $field, $fm)) {
                $ids = array_map('intval', explode(',', $val));
                if ($fm[1] === 'brand') {
                    [$ph, $v] = App::db()->in($ids);
                    $where[] = "p.brand_id IN ($ph)"; $params = array_merge($params, $v);
                } else {
                    $f = App::db()->value('SELECT id FROM features WHERE code = ?', [$fm[1]]);
                    if (!$f) continue;
                    [$ph, $v] = App::db()->in($ids);
                    $where[] = "EXISTS (SELECT 1 FROM product_features pf WHERE pf.product_id = p.id AND pf.feature_id = ? AND pf.value_id IN ($ph))";
                    $params = array_merge($params, [(int) $f], $v);
                }
            }
        }
        return [implode(' AND ', $where), $params];
    }
}
