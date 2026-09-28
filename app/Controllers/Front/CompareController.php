<?php
declare(strict_types=1);

namespace App\Controllers\Front;

use App\Core\App;
use App\Core\Response;
use App\Core\Seo;
use App\Core\View;
use App\Services\Catalog;
use App\Services\Products;

/**
 * Сравнение товаров: /compare/ — из cookie cmp, /compare/1,2,3/ — список в адресе (как в Webasyst).
 * Страница персональная (зависит от cookie) — без кэша, noindex.
 */
final class CompareController
{
    public const MAX = 20;
    /** Характеристики, которые уже показаны отдельными строками */
    private const SKIP_FEATURES = ['brand', 'kol_vo_par', 'size'];

    public function index(string $ids = ''): Response
    {
        $fromUrl = $ids !== '';
        if ($fromUrl && !preg_match('/^\d{1,10}(,\d{1,10})*,?$/', $ids)) return Response::notFound();
        $list = $fromUrl ? $ids : (string) ($_COOKIE['cmp'] ?? '');
        $idList = array_slice(array_values(array_unique(array_filter(array_map('intval', explode(',', $list)), static fn($i) => $i > 0))), 0, self::MAX);

        $products = $idList ? Products::cards($idList) : [];
        [$rows, $hasDiff] = $products ? self::rows($products) : [[], false];

        $seo = Seo::make(t('Сравнить товары'));
        $seo->robots = 'noindex, follow';
        $html = View::render('front/compare', [
            'seo' => $seo, 'products' => $products, 'rows' => $rows, 'hasDiff' => $hasDiff, 'fromUrl' => $fromUrl,
            'scripts' => ['js/account.js'], 'bodyClass' => 'pg-compare',
        ]);
        return Response::html($html);
    }

    /**
     * Строки таблицы: [['label' => 'Материал', 'values' => [id => 'замша', …], 'diff' => bool, 'kind' => 'price|text'], …]
     * Характеристики всех товаров — одним запросом (без запросов в цикле).
     */
    private static function rows(array $products): array
    {
        $ids = array_column($products, 'id');
        $rows = [];
        $add = static function (string $label, array $values, string $kind = 'text', bool $always = true) use (&$rows): void {
            $filled = array_filter($values, static fn($v) => $v !== '' && $v !== null);
            if (!$always && !$filled) return;
            $norm = array_map(static fn($v) => mb_strtolower(trim((string) $v)), $values);
            $rows[] = ['label' => $label, 'values' => $values, 'kind' => $kind, 'diff' => count(array_unique($norm)) > 1];
        };
        $col = static fn(callable $fn) => array_combine($ids, array_map($fn, $products));

        $add(t('Цена за ящик'), $col(static fn($p) => $p['box_price']), 'price');
        $add(t('Цена за пару'), $col(static fn($p) => $p['price']), 'price');
        $add(t('Пар в ящике'), $col(static fn($p) => (string) $p['box_qty']));
        $add(t('Размерный ряд'), $col(static fn($p) => (string) $p['size']), 'text', false);
        $add(t('Бренд'), $col(static fn($p) => (string) $p['brand']), 'text', false);
        // в перенесённых из Webasyst товарах артикулом часто записан размерный ряд — такой не повторяем
        $add(t('Артикул'), $col(static fn($p) => (string) $p['sku'] !== (string) $p['size'] ? (string) $p['sku'] : ''), 'text', false);
        $add(t('Наличие'), $col(static fn($p) => $p['in_stock'] ? t('В наличии') : t('Нет в наличии')));

        // публичные характеристики: значения одним запросом (с украинскими вариантами), названия и порядок — из кэша справочника
        $features = array_filter(Catalog::features(), static fn($f) => $f['status'] === 'public' && !in_array($f['code'], self::SKIP_FEATURES, true));
        if ($features) {
            $db = App::db();
            [$ph, $vals] = $db->in($ids);
            [$fph, $fvals] = $db->in(array_keys($features));
            $data = [];
            foreach ($db->all("SELECT pf.product_id, pf.feature_id, fv.value, fv.value_uk FROM product_features pf
                JOIN feature_values fv ON fv.id = pf.value_id
                WHERE pf.product_id IN ($ph) AND pf.feature_id IN ($fph) ORDER BY fv.sort, fv.id", array_merge($vals, $fvals)) as $r) {
                $data[(int) $r['feature_id']][(int) $r['product_id']][] = (string) $r['value'];
            }
            foreach ($features as $fid => $f) {
                if (empty($data[$fid])) continue;
                $values = [];
                foreach ($ids as $pid) $values[$pid] = isset($data[$fid][$pid]) ? implode(', ', $data[$fid][$pid]) : '';
                $add((string) $f['name'], $values);
            }
        }
        $hasDiff = (bool) array_filter($rows, static fn($r) => $r['diff']);
        return [$rows, $hasDiff];
    }
}
