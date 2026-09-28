<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\App;
use App\Core\Image;

/**
 * Загрузка товаров для витрины. Правило производительности: списки получают сначала
 * только id (через индекс), затем Products::cards($ids) одним запросом достаёт поля для карточек.
 * Никаких запросов в цикле по товарам.
 */
final class Products
{
    /** Поля, нужные карточке/строке списка */
    public const CARD_COLS = 'p.id, p.url, p.name, p.name_uk, p.sku, p.price, p.compare_price, p.box_qty, p.size, p.in_stock, p.stock,
        p.brand_id, p.category_id, p.image_id, p.image_ext, p.badge, p.created_at, p.status';

    /** Карточки по списку id с сохранением порядка */
    public static function cards(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if (!$ids) return [];
        [$ph, $vals] = App::db()->in($ids);
        $rows = App::db()->keyed('SELECT ' . self::CARD_COLS . ' FROM products p WHERE p.id IN (' . $ph . ') AND p.status = 1', $vals);
        $out = [];
        foreach ($ids as $id) if (isset($rows[$id])) $out[] = self::decorate($rows[$id]);
        return $out;
    }

    /** Добавить вычисляемые поля: ссылка, фото, бренд, цена за ящик, скидка, «новинка» */
    public static function decorate(array $p): array
    {
        $p['id'] = (int) $p['id'];
        $p['box_qty'] = max(1, (int) ($p['box_qty'] ?? 1));
        $p['price'] = (float) $p['price'];
        $p['compare_price'] = (float) ($p['compare_price'] ?? 0);
        $p['box_price'] = $p['price'] * $p['box_qty'];
        $p['link'] = '/product/' . $p['url'] . '/';
        $p['img'] = Image::product($p, '400');
        $p['img_small'] = Image::product($p, '200');
        $b = Catalog::brand(isset($p['brand_id']) ? (int) $p['brand_id'] : null);
        $p['brand'] = $b['name'] ?? '';
        $p['off'] = ($p['compare_price'] > $p['price'] && $p['price'] > 0)
            ? (int) round(100 - $p['price'] / $p['compare_price'] * 100) : 0;
        $p['is_new'] = !empty($p['created_at']) && strtotime((string) $p['created_at']) > time() - 86400 * 30;
        $p['in_stock'] = (int) ($p['in_stock'] ?? 1);
        return $p;
    }

    public static function byUrl(string $url): ?array
    {
        $p = App::db()->row('SELECT p.*, t.summary, t.description, t.summary_uk, t.description_uk FROM products p
            LEFT JOIN product_texts t ON t.product_id = p.id WHERE p.url = ? LIMIT 1', [$url]);
        return $p ? self::decorate($p) : null;
    }

    public static function images(int $productId): array
    {
        return App::db()->all('SELECT id, ext, width, height, filename, description FROM product_images
            WHERE product_id = ? ORDER BY sort, id', [$productId]);
    }

    /** Характеристики товара: [['name' => 'Материал', 'code' => 'material', 'values' => ['замша']], …] */
    public static function features(int $productId, bool $publicOnly = true): array
    {
        $rows = App::db()->all('SELECT f.id, f.code, f.name, f.name_uk, f.sort, fv.value, fv.value_uk FROM product_features pf
            JOIN features f ON f.id = pf.feature_id JOIN feature_values fv ON fv.id = pf.value_id
            WHERE pf.product_id = ?' . ($publicOnly ? " AND f.status = 'public'" : '') . ' ORDER BY f.sort, f.id, fv.sort, fv.id', [$productId]);
        $out = [];
        foreach ($rows as $r) {
            $out[$r['id']] ??= ['name' => $r['name'], 'code' => $r['code'], 'values' => []];
            $out[$r['id']]['values'][] = $r['value'];
        }
        return array_values($out);
    }

    /** id товаров набора (новинки, промо…) */
    public static function setIds(string $setId, int $limit = 24): array
    {
        return array_map('intval', App::db()->col('SELECT si.product_id FROM product_set_items si
            JOIN products p ON p.id = si.product_id AND p.status = 1
            WHERE si.set_id = ? ORDER BY si.sort LIMIT ' . max(1, $limit), [$setId]));
    }

    /** Самые новые товары (по индексу status_created) */
    public static function latestIds(int $limit = 24): array
    {
        return array_map('intval', App::db()->col('SELECT id FROM products WHERE status = 1 AND in_stock = 1
            ORDER BY created_at DESC LIMIT ' . max(1, $limit)));
    }
}
