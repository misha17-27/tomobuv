<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\App;
use App\Core\Image;
use App\Core\Request;
use App\Core\Response;
use App\Services\AdminCatalog;
use App\Services\Import\Importer;

/**
 * Админка → «Импорт / экспорт» → выгрузка товаров в CSV (UTF-8 с BOM, разделитель «;»).
 * Выгрузка потоковая, порциями по id — 100 000+ товаров без нехватки памяти.
 * Файл импортируется обратно без изменений (ключ поиска — ID), в том числе после правки в Excel.
 */
final class ExportController extends BaseController
{
    private const CHUNK = 2000;

    /** Колонки файла: основные всегда, остальные — по галочкам */
    private const COLS_MAIN = ['id', 'url', 'name', 'sku', 'supplier', 'supplier_code', 'category', 'brand', 'size', 'box_qty', 'min_qty',
        'price', 'price_box', 'compare_price', 'purchase_price', 'stock', 'in_stock', 'status', 'image'];
    private const COLS_SEO = ['meta_title', 'meta_description', 'meta_keywords'];
    private const COLS_DESC = ['description'];
    private const COLS_UK = ['name_uk', 'description_uk', 'meta_title_uk', 'meta_description_uk', 'meta_keywords_uk'];

    public function index(): Response
    {
        $f = self::filters($_GET + ['uk' => '1']);
        $cats = AdminCatalog::categories();
        return $this->render('admin/import/export', [
            'title'     => 'Экспорт товаров',
            'back'      => ['/admin/import/', 'Импорт / экспорт'],
            'styles'    => ['admin/import.css'],
            'scripts'   => ['admin/import.js'],
            'f'         => $f,
            'cats'      => $cats,
            'brands'    => App::db()->all('SELECT id, name FROM brands ORDER BY name'),
            'suppliers' => Importer::suppliers(),
            'count'     => self::countFor($f),
            'cols'      => ['main' => self::COLS_MAIN, 'seo' => self::COLS_SEO, 'desc' => self::COLS_DESC, 'uk' => self::COLS_UK],
        ]);
    }

    /** Сколько товаров попадёт в выгрузку (для формы, AJAX) */
    public function count(): Response
    {
        return Response::json(['ok' => true, 'count' => self::countFor(self::filters($_GET))]);
    }

    /** Фильтры из запроса — только по белым спискам */
    private static function filters(array $q): array
    {
        $s = static fn(string $k): string => isset($q[$k]) && is_scalar($q[$k]) ? trim((string) $q[$k]) : '';
        $cat = (int) $s('category');
        $brand = $s('brand');
        $supplier = $s('supplier');
        return [
            'category' => $cat > 0 && App::db()->value('SELECT id FROM categories WHERE id = ?', [$cat]) ? $cat : 0,
            'brand'    => $brand === '-1' ? -1 : max(0, (int) $brand),
            'supplier' => $supplier === '-' ? '-' : mb_substr($supplier, 0, 64),
            'status'   => in_array($s('status'), ['0', '1'], true) ? $s('status') : '',
            'stock'    => in_array($s('stock'), ['0', '1'], true) ? $s('stock') : '',
            'seo'      => $s('seo') === '1',
            'desc'     => $s('desc') === '1',
            'uk'       => $s('uk') === '1',
        ];
    }

    /** WHERE по фильтрам: [sql, params] (алиас таблицы товаров — p) */
    private static function where(array $f): array
    {
        $w = ['1 = 1'];
        $p = [];
        if ($f['status'] !== '') { $w[] = 'p.status = ?'; $p[] = (int) $f['status']; }
        if ($f['stock'] !== '') { $w[] = 'p.in_stock = ?'; $p[] = (int) $f['stock']; }
        if ($f['brand'] === -1) $w[] = 'p.brand_id IS NULL';
        elseif ($f['brand'] > 0) { $w[] = 'p.brand_id = ?'; $p[] = $f['brand']; }
        if ($f['supplier'] === '-') $w[] = "(p.supplier IS NULL OR p.supplier = '')";
        elseif ($f['supplier'] !== '') { $w[] = 'p.supplier = ?'; $p[] = $f['supplier']; }
        if ($f['category']) {
            $cats = AdminCatalog::categories();
            $c = $cats[$f['category']] ?? null;
            if ($c && (int) $c['type'] === 1) {                               // динамическая — по индексу каталога (только товары на сайте)
                $w[] = 'EXISTS (SELECT 1 FROM catalog_index ci WHERE ci.category_id = ? AND ci.product_id = p.id)';
                $p[] = (int) $c['id'];
            } else {                                                          // обычная — с подкатегориями, включая скрытые товары
                $ids = AdminCatalog::subtreeIds($f['category'], $cats);
                [$ph, $vals] = App::db()->in($ids);
                $w[] = "EXISTS (SELECT 1 FROM category_products cp WHERE cp.product_id = p.id AND cp.category_id IN ($ph))";
                $p = array_merge($p, $vals);
            }
        }
        return [implode(' AND ', $w), $p];
    }

    private static function countFor(array $f): int
    {
        [$where, $params] = self::where($f);
        return (int) App::db()->value("SELECT COUNT(*) FROM products p WHERE $where", $params);
    }

    /** Число без лишних нулей: 1020.00 → 1020, 845.50 → 845.5 */
    private static function num($v): string
    {
        return rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.');
    }

    /** Потоковая выгрузка CSV. ?template=1 — только строка заголовков (шаблон для поставщика). */
    public function products(): Response
    {
        $f = self::filters($_GET);
        $cols = array_merge(self::COLS_MAIN, $f['seo'] ? self::COLS_SEO : [], $f['desc'] ? self::COLS_DESC : [], $f['uk'] ? self::COLS_UK : []);
        $template = Request::get('template') === '1';
        [$where, $params] = self::where($f);
        $db = App::db();
        $this->log('export_products', null, null, array_filter(['filters' => array_filter($f), 'template' => $template ?: null]));

        if (function_exists('set_time_limit')) @set_time_limit(0);
        session_write_close();
        while (ob_get_level() > 0) @ob_end_clean();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="tomobuv-products-' . ($template ? 'template' : date('Ymd-His')) . '.csv"');
        header('Cache-Control: no-store, private');
        header('X-Accel-Buffering: no');
        header('X-Robots-Tag: noindex');
        $out = fopen('php://output', 'wb');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, $cols, ';', '"', '', "\r\n");
        if ($template) { fclose($out); exit; }

        $paths = Importer::categoryIndex()['path'];
        $brands = $db->pairs('SELECT id, name FROM brands');
        $needTexts = $f['desc'] || $f['uk'];
        $sel = 'p.id, p.url, p.name, p.sku, p.supplier, p.supplier_code, p.category_id, p.brand_id, p.size, p.box_qty, p.min_qty, p.price,
            p.compare_price, p.purchase_price, p.stock, p.in_stock, p.status, p.image_id, p.image_ext'
            . ($f['seo'] ? ', p.meta_title, p.meta_description, p.meta_keywords' : '')
            . ($f['uk'] ? ', p.name_uk, p.meta_title_uk, p.meta_description_uk, p.meta_keywords_uk' : '');
        $last = 0;
        do {
            // порция по первичному ключу: WHERE id > последний ORDER BY id — без OFFSET и без памяти на весь каталог
            $rows = $db->all("SELECT $sel FROM products p WHERE p.id > ? AND $where ORDER BY p.id LIMIT " . self::CHUNK, array_merge([$last], $params));
            if (!$rows) break;
            $texts = [];
            if ($needTexts) {
                [$ph, $vals] = $db->in(array_column($rows, 'id'));
                $texts = $db->keyed("SELECT product_id, description, description_uk FROM product_texts WHERE product_id IN ($ph)", $vals);
            }
            foreach ($rows as $r) {
                $id = (int) $r['id'];
                $box = max(1, (int) $r['box_qty']);
                $img = '';
                if ($r['image_id']) {
                    $img = Image::url($id, (int) $r['image_id'], $r['image_ext'] ?: 'jpg', '970');
                    if (str_starts_with($img, '/')) $img = url($img);
                }
                $line = [
                    $id, $r['url'], $r['name'], $r['sku'], (string) $r['supplier'], (string) $r['supplier_code'],
                    $r['category_id'] ? ($paths[(int) $r['category_id']] ?? '') : '', $r['brand_id'] ? ($brands[(int) $r['brand_id']] ?? '') : '',
                    $r['size'], $box, max(1, (int) $r['min_qty']), self::num($r['price']), self::num((float) $r['price'] * $box),
                    self::num($r['compare_price']), self::num($r['purchase_price']), $r['stock'] === null ? '' : (int) $r['stock'],
                    (int) $r['in_stock'], (int) $r['status'], $img,
                ];
                if ($f['seo']) array_push($line, (string) $r['meta_title'], (string) $r['meta_description'], (string) $r['meta_keywords']);
                if ($f['desc']) $line[] = (string) ($texts[$id]['description'] ?? '');
                if ($f['uk']) array_push($line, (string) $r['name_uk'], (string) ($texts[$id]['description_uk'] ?? ''),
                    (string) $r['meta_title_uk'], (string) $r['meta_description_uk'], (string) $r['meta_keywords_uk']);
                // защита от формул в Excel: текст, начинающийся с = + - @, выводится с апострофом (импорт его снимает)
                foreach ($line as &$v) if (is_string($v) && $v !== '' && str_contains('=+-@', $v[0]) && !is_numeric($v)) $v = "'" . $v;
                unset($v);
                fputcsv($out, $line, ';', '"', '', "\r\n");
                $last = $id;
            }
            fflush($out);
            flush();
        } while (count($rows) === self::CHUNK && !connection_aborted());
        fclose($out);
        exit;
    }
}
