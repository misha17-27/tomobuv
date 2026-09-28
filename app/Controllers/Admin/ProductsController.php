<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\App;
use App\Core\Cache;
use App\Core\Paginator;
use App\Core\Request;
use App\Core\Response;
use App\Core\Str;
use App\Services\AdminCatalog;
use App\Services\Catalog;
use App\Services\CatalogIndexer;

/** Админка → Товары: список с фильтрами и массовыми действиями, карточка товара, фото. */
final class ProductsController extends BaseController
{
    private const PER_PAGE = 50;
    /** Поиск отдаёт не больше стольких товаров (дальше — «уточните запрос») */
    private const SEARCH_LIMIT = 5000;
    /** Последний поиск упёрся в SEARCH_LIMIT */
    private static bool $capped = false;

    /** Сортировки списка — только из этого списка (направление второй колонки = первой, чтобы работали индексы updated/price) */
    private const SORTS = [
        'id_desc'    => ['p.id DESC', 'Сначала новые'],
        'id_asc'     => ['p.id ASC', 'Сначала старые'],
        'updated'    => ['p.updated_at DESC, p.id DESC', 'Недавно изменённые'],
        'name'       => ['p.name ASC, p.id ASC', 'Название А–Я'],
        'name_desc'  => ['p.name DESC, p.id DESC', 'Название Я–А'],
        'price'      => ['p.price ASC, p.id ASC', 'Цена по возрастанию'],
        'price_desc' => ['p.price DESC, p.id DESC', 'Цена по убыванию'],
        'stock'      => ['p.stock IS NULL, p.stock ASC, p.id DESC', 'Остаток по возрастанию'],
    ];

    /** Поля с украинским вариантом (*_uk): products и product_texts */
    private const UK_FIELDS = ['name', 'seo_name', 'h1', 'meta_title', 'meta_description', 'meta_keywords', 'summary', 'description'];

    private const BULK = [
        'show'     => 'Показать на сайте',
        'hide'     => 'Скрыть с сайта',
        'instock'  => 'Отметить «в наличии»',
        'outstock' => 'Отметить «нет в наличии»',
        'addcat'   => 'Добавить в категорию…',
        'delcat'   => 'Убрать из категории…',
        'price'    => 'Изменить цену на ±%…',
        'delete'   => 'Удалить',
    ];

    // ======================================================================= список

    public function index(): Response
    {
        $f = self::filters($_GET);
        [$where, $params] = self::where($f);
        $db = App::db();
        $total = (int) Cache::remember('admin.pcount.' . md5($where . '|' . json_encode($params)), 300,
            static fn() => (int) $db->value("SELECT COUNT(*) FROM products p WHERE $where", $params));
        $pg = new Paginator($total, self::PER_PAGE, Request::page());
        $ids = $total ? array_map('intval', $db->col("SELECT p.id FROM products p WHERE $where ORDER BY " . self::SORTS[$f['sort']][0]
            . ' LIMIT ' . self::PER_PAGE . ' OFFSET ' . $pg->offset, $params)) : [];
        $rows = [];
        if ($ids) {
            [$ph, $vals] = $db->in($ids);
            $byId = $db->keyed("SELECT id, name, name_uk, sku, url, price, compare_price, box_qty, size, stock, in_stock, status, image_id, image_ext,
                brand_id, category_id, badge, updated_at FROM products WHERE id IN ($ph)", $vals);
            foreach ($ids as $id) if (isset($byId[$id])) $rows[] = $byId[$id];
        }
        $cats = AdminCatalog::categories();
        $fvLabel = ($f['ff'] && $f['fv']) ? $db->row('SELECT f.name, fv.value FROM feature_values fv JOIN features f ON f.id = fv.feature_id
            WHERE fv.id = ? AND fv.feature_id = ?', [$f['fv'], $f['ff']]) : null;
        return $this->render('admin/products/index', [
            'fvLabel' => $fvLabel,
            'title'   => 'Товары',
            'actions' => '<a class="btn btn-p" href="/admin/products/new/">+ Добавить товар</a>',
            'styles'  => ['admin/catalog.css'],
            'scripts' => ['admin/catalog.js'],
            'f'       => $f,
            'rows'    => $rows,
            'total'   => $total,
            'pg'      => $pg,
            'cats'    => $cats,
            'brands'  => Catalog::brands(),
            'sorts'   => array_map(static fn($s) => $s[1], self::SORTS),
            'bulk'    => self::BULK,
            'query'   => http_build_query(array_filter(array_diff_key($f, ['sort' => 1]), static fn($v) => $v !== '' && $v !== 0 && $v !== false)),
            'capped'  => self::$capped ? self::SEARCH_LIMIT : 0,
        ]);
    }

    /** Фильтры списка из массива параметров ($_GET или сохранённая строка фильтра) */
    private static function filters(array $src): array
    {
        $s = static fn(string $k) => is_scalar($src[$k] ?? null) ? trim((string) $src[$k]) : '';
        $cat = $s('category');
        $sort = $s('sort');
        return [
            'q'        => mb_substr($s('q'), 0, 100),
            'category' => ($cat === 'none' || ctype_digit($cat)) ? $cat : '',
            'direct'   => $s('direct') === '1' ? '1' : '',
            'brand'    => (int) $s('brand'),
            'status'   => in_array($s('status'), ['0', '1'], true) ? $s('status') : '',
            'stock'    => in_array($s('stock'), ['0', '1'], true) ? $s('stock') : '',
            'sale'     => $s('sale') === '1' ? '1' : '',
            'nophoto'  => $s('nophoto') === '1' ? '1' : '',
            'nouk'     => $s('nouk') === '1' ? '1' : '',             // без перевода на украинский
            'ff'       => max(0, (int) $s('ff')),              // характеристика + значение (ссылка из раздела «Характеристики»)
            'fv'       => max(0, (int) $s('fv')),
            'sort'     => isset(self::SORTS[$sort]) ? $sort : 'id_desc',
        ];
    }

    /** WHERE для списка: [sql, params]. Все значения — через плейсхолдеры. */
    private static function where(array $f): array
    {
        $db = App::db();
        $w = []; $p = [];
        self::$capped = false;
        if ($f['q'] !== '') {
            $ids = self::searchIds($f['q']);
            self::$capped = count($ids) >= self::SEARCH_LIMIT;
            if (!$ids) return ['0', []];
            [$ph, $vals] = $db->in($ids);
            $w[] = "p.id IN ($ph)";
            $p = array_merge($p, $vals);
        }
        if ($f['category'] === 'none') {
            // таких товаров единицы, а проверка — полный проход по 107 тыс.: делаем его один раз (id списком),
            // а не дважды (COUNT + страница)
            $none = array_map('intval', $db->col('SELECT p.id FROM products p WHERE NOT EXISTS (SELECT 1 FROM category_products cp WHERE cp.product_id = p.id) LIMIT 5001'));
            if (count($none) <= 5000) {
                if (!$none) return ['0', []];
                [$ph, $vals] = $db->in($none);
                $w[] = "p.id IN ($ph)";
                $p = array_merge($p, $vals);
            } else {
                $w[] = 'NOT EXISTS (SELECT 1 FROM category_products cp WHERE cp.product_id = p.id)';
            }
        } elseif ($f['category'] !== '') {
            $cats = AdminCatalog::categories();
            $c = $cats[(int) $f['category']] ?? null;
            if (!$c) return ['0', []];
            if ((int) $c['type'] === 1) {                       // динамическая — по её условию (включая скрытые товары)
                [$cw, $cp] = CatalogIndexer::conditionSql((string) $c['conditions']);
                $w[] = $cw !== '' ? '(' . $cw . ')' : '0';
                $p = array_merge($p, $cp);
            } else {
                $cids = $f['direct'] ? [(int) $c['id']] : AdminCatalog::subtreeIds((int) $c['id'], $cats);
                [$ph, $vals] = $db->in($cids);
                $w[] = "p.id IN (SELECT cp.product_id FROM category_products cp WHERE cp.category_id IN ($ph))";
                $p = array_merge($p, $vals);
            }
        }
        if ($f['brand'] > 0) { $w[] = 'p.brand_id = ?'; $p[] = $f['brand']; }
        elseif ($f['brand'] === -1) { $w[] = 'p.brand_id IS NULL'; }
        if ($f['status'] !== '') { $w[] = 'p.status = ?'; $p[] = (int) $f['status']; }
        if ($f['stock'] !== '') { $w[] = 'p.in_stock = ?'; $p[] = (int) $f['stock']; }
        if ($f['sale']) $w[] = 'p.compare_price > 0 AND p.compare_price > p.price';   // первое условие — по индексу compare_price
        if ($f['nophoto']) $w[] = 'p.image_id IS NULL';
        if ($f['nouk']) $w[] = "(p.name_uk IS NULL OR p.name_uk = '')";
        if ($f['ff'] && $f['fv']) {
            $w[] = 'p.id IN (SELECT pf.product_id FROM product_features pf WHERE pf.feature_id = ? AND pf.value_id = ?)';
            array_push($p, $f['ff'], $f['fv']);
        }
        return [$w ? implode(' AND ', $w) : '1', $p];
    }

    /**
     * Поиск id товаров: точный id, начало артикула или адреса (индексы sku, url), полнотекстовый по названию/артикулу;
     * если ничего — LIKE по подстроке (полный проход ~90 мс, только как запасной вариант).
     */
    private static function searchIds(string $q, int $limit = self::SEARCH_LIMIT): array
    {
        $db = App::db();
        $q = trim($q);
        if ($q === '') return [];
        $ids = [];
        if (ctype_digit($q) && strlen($q) <= 10) $ids = array_merge($ids, $db->col('SELECT id FROM products WHERE id = ?', [(int) $q]));
        $like = addcslashes($q, '%_\\') . '%';
        // при лимите берутся самые новые совпадения — «Сначала новые» (по умолчанию) показывает действительно новые
        $ids = array_merge($ids, $db->col('SELECT id FROM products WHERE sku LIKE ? ORDER BY id DESC LIMIT 2000', [$like]));
        $ids = array_merge($ids, $db->col('SELECT id FROM products WHERE url LIKE ? LIMIT 500', [addcslashes(mb_strtolower($q), '%_\\') . '%']));
        $words = array_filter(preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($q), -1, PREG_SPLIT_NO_EMPTY) ?: [], static fn($x) => mb_strlen($x) >= 3);
        if ($words) {
            $against = implode(' ', array_map(static fn($x) => '+' . $x . '*', array_slice($words, 0, 8)));
            $ids = array_merge($ids, $db->col('SELECT id FROM products WHERE MATCH(name, sku) AGAINST (? IN BOOLEAN MODE) ORDER BY id DESC LIMIT ' . $limit, [$against]));
        }
        if (!$ids && mb_strlen($q) >= 2) {
            $sub = '%' . addcslashes($q, '%_\\') . '%';
            $ids = $db->col('SELECT id FROM products WHERE name LIKE ? OR sku LIKE ? ORDER BY id DESC LIMIT ' . $limit, [$sub, $sub]);
        }
        return array_slice(array_values(array_unique(array_map('intval', $ids))), 0, $limit);
    }

    /** Автокомплит товаров для других разделов: [{id, name, sku, price, box_qty, img}] */
    public function search(): Response
    {
        $q = mb_substr(Request::get('q'), 0, 100);
        $ids = $q !== '' ? self::searchIds($q, 300) : [];
        if (!$ids) return Response::json([]);
        $exact = ctype_digit($q) ? (int) $q : 0;
        usort($ids, static fn($a, $b) => ($b === $exact) <=> ($a === $exact) ?: $b <=> $a);
        $ids = array_slice($ids, 0, 20);
        $db = App::db();
        [$ph, $vals] = $db->in($ids);
        $rows = $db->keyed("SELECT id, name, sku, price, box_qty, image_id, image_ext, size, in_stock, status FROM products WHERE id IN ($ph)", $vals);
        $out = [];
        foreach ($ids as $id) {
            if (!isset($rows[$id])) continue;
            $r = $rows[$id];
            $out[] = ['id' => (int) $r['id'], 'name' => $r['name'], 'sku' => $r['sku'], 'price' => (float) $r['price'], 'box_qty' => (int) $r['box_qty'],
                'img' => AdminCatalog::thumb((int) $r['id'], $r['image_id'] ? (int) $r['image_id'] : null, $r['image_ext'], '96x96'),
                'size' => $r['size'], 'in_stock' => (int) $r['in_stock'], 'status' => (int) $r['status']];
        }
        return Response::json($out);
    }

    /** Подсказка адреса: {ok, url, taken} — для автозаполнения поля URL в форме */
    public function slug(): Response
    {
        $id = Request::getInt('id');
        $url = AdminCatalog::cleanUrl(Request::get('url'));
        if ($url === '') {
            $name = Request::get('name');
            if ($name === '') return Response::json(['ok' => true, 'url' => '', 'taken' => false]);
            return Response::json(['ok' => true, 'url' => AdminCatalog::uniqueProductUrl(Str::slug($name), $id), 'taken' => false]);
        }
        $other = (int) App::db()->value('SELECT id FROM products WHERE url = ? AND id <> ?', [$url, $id]);
        return Response::json(['ok' => true, 'url' => $url, 'taken' => $other > 0, 'taken_by' => $other ?: null]);
    }

    // ======================================================================= массовые действия

    public function bulk(): Response
    {
        $action = Request::post('action');
        if (!isset(self::BULK[$action])) return $this->bulkBack('Выберите действие.', true);
        $db = App::db();
        if (Request::post('all') === '1') {
            parse_str(Request::post('filter'), $src);
            [$where, $params] = self::where(self::filters(is_array($src) ? $src : []));
            $ids = array_map('intval', $db->col("SELECT p.id FROM products p WHERE $where ORDER BY p.id LIMIT 200000", $params));
        } else {
            $ids = AdminCatalog::ids(Request::postArray('ids'));
        }
        if (!$ids) return $this->bulkBack('Не выбрано ни одного товара.', true);
        $n = count($ids);

        if ($action === 'delete') {
            if ($n > 5000) return $this->bulkBack('За один раз можно удалить не больше 5000 товаров.', true);
            AdminCatalog::deleteProducts($ids);
            $this->log('products_delete', 'product', null, ['count' => $n, 'ids' => array_slice($ids, 0, 200)]);
            return $this->bulkBack('Удалено товаров: ' . $n . '.');
        }

        $catId = Request::postInt('category_id');
        $cat = null;
        if ($action === 'addcat' || $action === 'delcat') {
            $cat = $db->row('SELECT id, name, type FROM categories WHERE id = ?', [$catId]);
            if (!$cat) return $this->bulkBack('Выберите категорию.', true);
            if ($action === 'addcat' && (int) $cat['type'] === 1) return $this->bulkBack('В динамическую категорию товары попадают по условию — добавить вручную нельзя.', true);
        }
        $pct = 0.0;
        if ($action === 'price') {
            $pct = (float) str_replace(',', '.', Request::post('percent'));
            if ($pct === 0.0 || $pct < -90 || $pct > 500) return $this->bulkBack('Укажите процент от −90 до 500 (например, 10 или −5).', true);
        }

        $snap = AdminCatalog::snapshot($ids);
        $now = date('Y-m-d H:i:s');
        foreach (array_chunk($ids, 1000) as $part) {
            [$ph, $vals] = $db->in($part);
            switch ($action) {
                case 'show':
                case 'hide':
                    $db->query("UPDATE products SET status = ?, updated_at = ? WHERE id IN ($ph)", array_merge([$action === 'show' ? 1 : 0, $now], $vals));
                    break;
                case 'instock':
                case 'outstock':
                    $db->query("UPDATE products SET in_stock = ?, updated_at = ? WHERE id IN ($ph)", array_merge([$action === 'instock' ? 1 : 0, $now], $vals));
                    break;
                case 'price':
                    $round = Request::post('round') === '1' ? 0 : 2;
                    $db->query("UPDATE products SET price = GREATEST(0, ROUND(price * ?, $round)), updated_at = ? WHERE id IN ($ph)",
                        array_merge([1 + $pct / 100, $now], $vals));
                    break;
                case 'addcat':
                    $db->query("INSERT IGNORE INTO category_products (category_id, product_id, sort) SELECT ?, id, 0 FROM products WHERE id IN ($ph)", array_merge([$catId], $vals));
                    $db->query("UPDATE products SET category_id = ? WHERE category_id IS NULL AND id IN ($ph)", array_merge([$catId], $vals));
                    break;
                case 'delcat':
                    $db->query("DELETE FROM category_products WHERE category_id = ? AND product_id IN ($ph)", array_merge([$catId], $vals));
                    $db->query("UPDATE products p SET p.category_id = (SELECT MIN(cp.category_id) FROM category_products cp WHERE cp.product_id = p.id)
                        WHERE p.category_id = ? AND p.id IN ($ph)", array_merge([$catId], $vals));
                    break;
            }
        }
        if ($action === 'addcat' || $action === 'delcat') Cache::forget('admin.category_direct_counts');
        AdminCatalog::reindex($ids, $snap);
        $this->log('products_bulk_' . $action, 'product', null, ['count' => $n, 'category' => $catId ?: null, 'percent' => $pct ?: null, 'ids' => array_slice($ids, 0, 200)]);
        $msg = self::BULK[$action];
        if ($cat) $msg = ($action === 'addcat' ? 'Добавлено в категорию «' : 'Убрано из категории «') . $cat['name'] . '»';
        if ($action === 'price') $msg = 'Цена изменена на ' . ($pct > 0 ? '+' : '') . rtrim(rtrim(number_format($pct, 2, '.', ''), '0'), '.') . '%';
        return $this->bulkBack(rtrim($msg, '…') . ': ' . $n . ' ' . plural($n, 'товар', 'товара', 'товаров') . '.');
    }

    private function bulkBack(string $msg, bool $error = false): Response
    {
        $this->flash($msg, $error);
        return $this->back('/admin/products/');
    }

    /** Возврат на страницу списка — только в пределах админки этого сайта (Referer с чужим хостом игнорируется) */
    protected function back(string $fallback = '/admin/'): Response
    {
        return Response::redirect(AdminCatalog::backUrl($fallback));
    }

    // ======================================================================= карточка товара

    public function create(): Response
    {
        return $this->form(null);
    }

    public function edit(string $id): Response
    {
        if (!ctype_digit($id)) return $this->notFoundPage();
        $p = App::db()->row('SELECT p.*, t.summary, t.description, t.summary_uk, t.description_uk FROM products p
            LEFT JOIN product_texts t ON t.product_id = p.id WHERE p.id = ?', [(int) $id]);
        return $p ? $this->form($p) : $this->notFoundPage();
    }

    private function notFoundPage(): Response
    {
        $r = $this->render('admin/forbidden', ['title' => 'Товар не найден', 'message' => 'Такого товара нет — возможно, он удалён.']);
        $r->status = 404;
        return $r;
    }

    private function form(?array $p): Response
    {
        $db = App::db();
        $id = (int) ($p['id'] ?? 0);
        $features = $db->keyed('SELECT id, code, name, type, multiple, status FROM features ORDER BY sort, id');
        $errors = [];
        if ($id) {
            $links = array_map('intval', $db->col('SELECT category_id FROM category_products WHERE product_id = ? ORDER BY sort, category_id', [$id]));
            $state = [];
            foreach ($db->all('SELECT pf.feature_id, fv.id, fv.value, fv.code FROM product_features pf JOIN feature_values fv ON fv.id = pf.value_id
                WHERE pf.product_id = ? ORDER BY fv.sort, fv.id', [$id]) as $r) {
                $state[(int) $r['feature_id']][] = ['id' => (int) $r['id'], 'value' => $r['value'], 'code' => $r['code']];
            }
        } else {
            $p = ['id' => 0, 'name' => '', 'url' => '', 'sku' => '', 'price' => '', 'compare_price' => '', 'purchase_price' => '', 'box_qty' => 8,
                'min_qty' => '', 'stock' => null, 'in_stock' => 1, 'status' => 1, 'badge' => null, 'category_id' => Request::getInt('category') ?: null,
                'summary' => '', 'description' => '', 'seo_name' => '', 'h1' => '', 'meta_title' => '', 'meta_description' => '', 'meta_keywords' => '',
                'image_id' => null, 'image_ext' => null, 'created_at' => null, 'updated_at' => null];
            foreach (self::UK_FIELDS as $k) $p[$k . '_uk'] = null;
            $links = $p['category_id'] ? [(int) $p['category_id']] : [];
            $state = [];
        }

        if (Request::isPost()) {
            [$data, $links, $state, $errors] = $this->validate($id, $features);
            if (!$errors) {
                $newId = $this->save($id, $p, $data, $links, $state, $features);
                if (!$id && $newId) {
                    $imgErrors = [];
                    foreach (self::files('images') as $file) {
                        $r = AdminCatalog::addProductImage($newId, $file);
                        if (is_string($r)) $imgErrors[] = ($file['name'] ?? 'файл') . ': ' . $r;
                    }
                    if ($imgErrors) $this->flash('Фото не загружены — ' . implode('; ', $imgErrors), true);
                }
                AdminCatalog::reindex([$newId], $this->snap);
                Cache::forget('admin.category_direct_counts');
                $this->log($id ? 'product_update' : 'product_create', 'product', $newId, ['name' => $data['name']]);
                $this->flash($id ? 'Товар сохранён.' : 'Товар создан.');
                return Response::redirect('/admin/products/' . $newId . '/');
            }
            $p = array_merge($p, $data);
        }

        $cats = AdminCatalog::categories();
        $catFull = AdminCatalog::productSeoCategory($p['category_id'] ? (int) $p['category_id'] : null);
        $seoTplUk = AdminCatalog::seoTemplates('product', AdminCatalog::productSeoVars(AdminCatalog::ukRow($p), AdminCatalog::ukRow($catFull)), 'uk');
        $seoUk = AdminCatalog::ukFallback($p, $seoTplUk);
        $ukP = AdminCatalog::ukRow($p);
        $seoUk['seo_name'] = trim((string) ($p['seo_name'] ?? '')) ?: (string) $ukP['name'];
        $seoUk['meta_title'] = $seoUk['meta_title'] ?: (string) $ukP['name'];          // шаблон пуст — витрина берёт название
        $seoUk['h1'] = $seoUk['h1'] ?: (string) $ukP['name'];
        $images = $id ? $db->all('SELECT id, ext, width, height, sort FROM product_images WHERE product_id = ? ORDER BY sort, id', [$id]) : [];
        foreach ($images as &$im) $im['thumb'] = AdminCatalog::thumb($id, (int) $im['id'], $im['ext'], '200');
        unset($im);

        // небольшие справочники значений — прямо в страницу (поиск без запросов), большие — через values.json
        $options = Cache::remember('admin.feature_options', 3600, static function () use ($db, $features) {
            // сколько значений у каждой характеристики — считаем не дальше 601 (одним запросом, по индексу fsort)
            $out = [];
            $fids = array_map('intval', array_keys($features));
            if ($fids) {
                $sql = implode(' UNION ALL ', array_fill(0, count($fids), '(SELECT ? AS f, COUNT(*) AS n FROM (SELECT 1 FROM feature_values WHERE feature_id = ? LIMIT 601) x)'));
                $params = [];
                foreach ($fids as $fid) array_push($params, $fid, $fid);
                foreach ($db->pairs($sql, $params) as $fid => $n) if ((int) $n <= 600) $out[(int) $fid] = [];
            }
            if ($out) {
                [$ph, $vals] = $db->in(array_keys($out));
                foreach ($db->all("SELECT id, feature_id, value, code FROM feature_values WHERE feature_id IN ($ph) ORDER BY sort, value", $vals) as $v) {
                    $out[(int) $v['feature_id']][] = [(int) $v['id'], $v['value'], $v['code'] !== null ? (int) $v['code'] : null];
                }
            }
            return $out;
        });

        return $this->render('admin/products/form', [
            'title'    => $id ? 'Товар: ' . $p['name'] : 'Новый товар',
            'back'     => ['/admin/products/', 'Все товары'],
            'actions'  => $id ? '<a class="btn" href="/product/' . e(rawurlencode((string) $p['url'])) . '/" target="_blank" rel="noopener">На сайте ↗</a>' : '',
            'styles'   => ['admin/catalog.css'],
            'scripts'  => ['admin/catalog.js'],
            'p'        => $p,
            'links'    => $links,
            'state'    => $state,
            'features' => $features,
            'options'  => $options,
            'cats'     => $cats,
            'images'   => $images,
            'errors'   => $errors,
            'seoTpl'   => AdminCatalog::seoTemplates('product', AdminCatalog::productSeoVars($p, $catFull)),
            'seoUk'    => $seoUk,
            'badges'   => AdminCatalog::BADGES,
            'maxUpload' => ini_get('upload_max_filesize'),
        ]);
    }

    /** Снимок индекса до сохранения (для точного пересчёта фильтров) */
    private ?array $snap = null;

    /** Разбор и проверка формы: [данные products, категории, характеристики, ошибки] */
    private function validate(int $id, array $features): array
    {
        $db = App::db();
        $errors = [];
        $money = static fn(string $k) => max(0, round((float) str_replace([' ', ','], ['', '.'], Request::post($k)), 2));
        $str = static fn(string $k, int $max = 500) => ($v = mb_substr(Request::post($k), 0, $max)) === '' ? null : $v;
        $d = [
            'name'           => mb_substr(Request::post('name'), 0, 255),
            'sku'            => mb_substr(Request::post('sku'), 0, 255),
            'price'          => $money('price'),
            'compare_price'  => $money('compare_price'),
            'purchase_price' => $money('purchase_price'),
            'box_qty'        => max(1, min(65535, Request::postInt('box_qty', 1))),
            'stock'          => Request::post('stock') === '' ? null : max(0, min(2000000000, Request::postInt('stock'))),
            'in_stock'       => Request::post('in_stock') === '1' ? 1 : 0,
            'status'         => Request::post('status') === '0' ? 0 : 1,
            'seo_name'       => $str('seo_name'),
            'h1'             => $str('h1'),
            'meta_title'     => $str('meta_title'),
            'meta_description' => $str('meta_description', 5000),
            'meta_keywords'  => $str('meta_keywords', 5000),
            'summary'        => mb_substr(Request::post('summary'), 0, 60000),
            'description'    => mb_substr(is_scalar($_POST['description'] ?? null) ? (string) $_POST['description'] : '', 0, 1000000),
            // украинская версия: пусто = на сайте русский текст
            'name_uk'        => AdminCatalog::postStr('name_uk', 255),
            'seo_name_uk'    => AdminCatalog::postStr('seo_name_uk'),
            'h1_uk'          => AdminCatalog::postStr('h1_uk'),
            'meta_title_uk'  => AdminCatalog::postStr('meta_title_uk'),
            'meta_description_uk' => AdminCatalog::postStr('meta_description_uk', 5000),
            'meta_keywords_uk' => AdminCatalog::postStr('meta_keywords_uk', 5000),
            'summary_uk'     => AdminCatalog::postStr('summary_uk', 60000),
            'description_uk' => AdminCatalog::postHtml('description_uk'),
        ];
        $d['min_qty'] = Request::post('min_qty') === '' ? $d['box_qty'] : max(1, min(65535, Request::postInt('min_qty', 1)));
        if ($d['stock'] === 0) $d['in_stock'] = 0;                  // остаток 0 — нет в наличии
        $badge = Request::post('badge');
        $d['badge'] = $badge === 'custom' ? (mb_substr(Request::post('badge_custom'), 0, 64) ?: null) : (isset(AdminCatalog::BADGES[$badge]) && $badge !== '' ? $badge : null);
        if ($d['name'] === '') $errors['name'] = 'Укажите название товара.';

        // адрес: пусто — из названия; занятый вручную введённый адрес — ошибка
        $url = AdminCatalog::cleanUrl(Request::post('url'));
        if ($url === '') {
            $url = AdminCatalog::uniqueProductUrl(Str::slug($d['name'] !== '' ? $d['name'] : 'tovar'), $id);
        } elseif ($other = (int) $db->value('SELECT id FROM products WHERE url = ? AND id <> ?', [$url, $id])) {
            $errors['url'] = 'Адрес уже занят товаром №' . $other . '.';
        }
        $d['url'] = $url;

        // категории: основная + дополнительные (только существующие и не динамические)
        $cats = AdminCatalog::categories();
        $main = Request::postInt('category_id');
        $d['category_id'] = isset($cats[$main]) ? $main : null;
        $links = [];
        foreach (array_merge([$main], array_map('intval', Request::postArray('cats'))) as $cid) {
            if (isset($cats[$cid]) && (int) $cats[$cid]['type'] === 0) $links[$cid] = $cid;
        }
        $links = array_values($links);
        if ($d['category_id'] && (int) $cats[$d['category_id']]['type'] === 1) {
            $errors['category_id'] = 'Основной категорией не может быть динамическая категория.';
        }
        if (!$d['category_id'] && $links) $d['category_id'] = $links[0];

        // характеристики: fv[fid][] — id существующих значений, fn[fid][] — новые значения
        $fv = Request::postArray('fv');
        $fn = Request::postArray('fn');
        $want = [];
        foreach ($fv as $fid => $list) foreach ((array) $list as $vid) if (is_scalar($vid) && (int) $vid > 0) $want[(int) $vid] = (int) $fid;
        $known = [];
        if ($want) {
            [$ph, $vals] = $db->in(array_keys($want));
            $known = $db->keyed("SELECT id, feature_id, value, code FROM feature_values WHERE id IN ($ph)", $vals);
        }
        $state = [];
        foreach ($features as $fid => $f) {
            $items = [];
            foreach ((array) ($fv[$fid] ?? []) as $vid) {
                $vid = is_scalar($vid) ? (int) $vid : 0;
                if (isset($known[$vid]) && (int) $known[$vid]['feature_id'] === (int) $fid) {
                    $items[] = ['id' => $vid, 'value' => $known[$vid]['value'], 'code' => $known[$vid]['code']];
                }
            }
            foreach ((array) ($fn[$fid] ?? []) as $txt) {
                $txt = is_scalar($txt) ? mb_substr(trim(preg_replace('/\s+/u', ' ', (string) $txt)), 0, 255) : '';
                if ($txt !== '') $items[] = ['id' => 0, 'value' => $txt, 'code' => null];
            }
            if (!(int) $f['multiple'] || in_array($f['code'], ['brand', 'size'], true)) $items = array_slice($items, 0, 1);
            if ($items) $state[(int) $fid] = $items;
        }
        return [$d, $links, $state, $errors];
    }

    /** Запись товара. Возвращает id. */
    private function save(int $id, array $old, array $d, array $links, array $state, array $features): int
    {
        $db = App::db();
        $this->snap = $id ? AdminCatalog::snapshot([$id]) : null;

        // новые значения характеристик → id (для «Бренда» создаётся и бренд)
        foreach ($state as $fid => &$items) {
            foreach ($items as &$it) if (!$it['id']) $it['id'] = AdminCatalog::valueId((int) $fid, $it['value']);
            unset($it);
            $items = array_values(array_filter($items, static fn($it) => $it['id'] > 0));
        }
        unset($items);
        $brandF = AdminCatalog::brandFeatureId();
        $sizeF = AdminCatalog::featureId('size');
        $d['brand_id'] = isset($state[$brandF][0]) ? $state[$brandF][0]['id'] : null;
        $d['size'] = isset($state[$sizeF][0]) ? mb_substr($state[$sizeF][0]['value'], 0, 64) : '';

        $texts = ['summary' => $d['summary'] !== '' ? $d['summary'] : null, 'description' => trim($d['description']) !== '' ? $d['description'] : null,
            'summary_uk' => $d['summary_uk'], 'description_uk' => $d['description_uk']];
        unset($d['summary'], $d['description'], $d['summary_uk'], $d['description_uk']);
        $d['updated_at'] = date('Y-m-d H:i:s');

        $id = $db->transaction(static function ($db) use ($id, $d, $texts, $links, $state) {
            if ($id) {
                $db->update('products', $d, 'id = ?', [$id]);
            } else {
                $d['created_at'] = $d['updated_at'];
                $id = $db->insert('products', $d);
            }
            if (!array_filter($texts, static fn($v) => $v !== null)) {
                $db->delete('product_texts', 'product_id = ?', [$id]);
            } else {
                $db->upsert('product_texts', ['product_id' => $id] + $texts, array_keys($texts));
            }
            // категории: удаляем лишние, добавляем новые (порядок существующих сохраняется)
            $have = array_map('intval', $db->col('SELECT category_id FROM category_products WHERE product_id = ?', [$id]));
            $drop = array_diff($have, $links);
            if ($drop) {
                [$ph, $vals] = $db->in($drop);
                $db->query("DELETE FROM category_products WHERE product_id = ? AND category_id IN ($ph)", array_merge([$id], $vals));
            }
            $add = array_diff($links, $have);
            $db->insertMany('category_products', array_map(static fn($c) => ['category_id' => $c, 'product_id' => $id, 'sort' => 0], array_values($add)), true);
            // характеристики
            $db->delete('product_features', 'product_id = ?', [$id]);
            $rows = [];
            foreach ($state as $fid => $items) foreach ($items as $it) $rows[] = ['product_id' => $id, 'feature_id' => (int) $fid, 'value_id' => (int) $it['id']];
            $db->insertMany('product_features', $rows, true);
            return $id;
        });
        $this->snap ??= ['ids' => [$id], 'rows' => [], 'pairs' => [], 'brands' => [], 'big' => false];

        // смена адреса — 301 со старого (если отмечено)
        if (!empty($old['url']) && $old['url'] !== $d['url'] && Request::post('redirect') === '1') {
            AdminCatalog::addRedirect('/product/' . $old['url'] . '/', '/product/' . $d['url'] . '/');
        }
        return $id;
    }

    /** Нормализация $_FILES[name] с multiple в список файлов */
    private static function files(string $key): array
    {
        $f = $_FILES[$key] ?? null;
        if (!$f || !isset($f['name'])) return [];
        if (!is_array($f['name'])) return ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE ? [] : [$f];
        $out = [];
        foreach (array_keys($f['name']) as $i) {
            if (($f['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) continue;
            $out[] = ['name' => $f['name'][$i], 'type' => $f['type'][$i] ?? '', 'tmp_name' => $f['tmp_name'][$i] ?? '',
                'error' => $f['error'][$i] ?? UPLOAD_ERR_NO_FILE, 'size' => $f['size'][$i] ?? 0];
        }
        return $out;
    }

    public function delete(string $id): Response
    {
        $id = ctype_digit($id) ? (int) $id : 0;
        $name = $id ? App::db()->value('SELECT name FROM products WHERE id = ?', [$id]) : null;
        if ($name === null) { $this->flash('Товар не найден.', true); return Response::redirect('/admin/products/'); }
        AdminCatalog::deleteProducts([$id]);
        Cache::forget('admin.category_direct_counts');
        $this->log('product_delete', 'product', $id, ['name' => $name]);
        $this->flash('Товар «' . $name . '» удалён.');
        return Response::redirect('/admin/products/');
    }

    // ======================================================================= фото (AJAX)

    private function productOr404(string $id): ?int
    {
        if (!ctype_digit($id)) return null;
        return App::db()->value('SELECT id FROM products WHERE id = ?', [(int) $id]) ? (int) $id : null;
    }

    private static function imagesJson(int $pid): array
    {
        $rows = App::db()->all('SELECT id, ext, width, height FROM product_images WHERE product_id = ? ORDER BY sort, id', [$pid]);
        return array_map(static fn($r) => ['id' => (int) $r['id'], 'width' => (int) $r['width'], 'height' => (int) $r['height'],
            'thumb' => AdminCatalog::thumb($pid, (int) $r['id'], $r['ext'], '200'),
            'big' => AdminCatalog::thumb($pid, (int) $r['id'], $r['ext'], '970')], $rows);
    }

    public function upload(string $id): Response
    {
        $pid = $this->productOr404($id);
        if (!$pid) return Response::json(['ok' => false, 'error' => 'Товар не найден'], 404);
        $files = array_merge(self::files('images'), self::files('image'));
        if (!$files) return Response::json(['ok' => false, 'error' => 'Файл не получен (возможно, больше ' . ini_get('post_max_size') . ')'], 422);
        $errors = []; $n = 0;
        foreach (array_slice($files, 0, 30) as $f) {
            $r = AdminCatalog::addProductImage($pid, $f);
            if (is_string($r)) $errors[] = ($f['name'] ?? 'файл') . ': ' . $r; else $n++;
        }
        if ($n) { Cache::flush(); $this->log('product_images_upload', 'product', $pid, ['count' => $n]); }
        return Response::json(['ok' => $n > 0, 'added' => $n, 'error' => $errors ? implode('; ', $errors) : null, 'images' => self::imagesJson($pid)], $n ? 200 : 422);
    }

    public function deleteImage(string $id, string $iid): Response
    {
        $pid = $this->productOr404($id);
        $img = ($pid && ctype_digit($iid)) ? App::db()->row('SELECT id, ext FROM product_images WHERE id = ? AND product_id = ?', [(int) $iid, $pid]) : null;
        if (!$img) return Response::json(['ok' => false, 'error' => 'Фото не найдено'], 404);
        App::db()->delete('product_images', 'id = ?', [(int) $img['id']]);
        AdminCatalog::deleteImageFiles($pid, (int) $img['id'], (string) $img['ext']);
        AdminCatalog::syncMainImage($pid);
        Cache::flush();
        $this->log('product_image_delete', 'product', $pid, ['image' => (int) $img['id']]);
        return Response::json(['ok' => true, 'images' => self::imagesJson($pid)]);
    }

    public function sortImages(string $id): Response
    {
        $pid = $this->productOr404($id);
        if (!$pid) return Response::json(['ok' => false, 'error' => 'Товар не найден'], 404);
        $order = AdminCatalog::ids(Request::postArray('ids') ?: explode(',', Request::post('ids')));
        $have = array_map('intval', App::db()->col('SELECT id FROM product_images WHERE product_id = ? ORDER BY sort, id', [$pid]));
        $order = array_values(array_intersect($order, $have));
        $order = array_merge($order, array_values(array_diff($have, $order)));    // чего нет в запросе — в конец
        self::applyImageOrder($pid, $order);
        return Response::json(['ok' => true, 'images' => self::imagesJson($pid)]);
    }

    public function mainImage(string $id, string $iid): Response
    {
        $pid = $this->productOr404($id);
        $have = $pid ? array_map('intval', App::db()->col('SELECT id FROM product_images WHERE product_id = ? ORDER BY sort, id', [$pid])) : [];
        $iid = ctype_digit($iid) ? (int) $iid : 0;
        if (!in_array($iid, $have, true)) return Response::json(['ok' => false, 'error' => 'Фото не найдено'], 404);
        self::applyImageOrder($pid, array_merge([$iid], array_values(array_diff($have, [$iid]))));
        return Response::json(['ok' => true, 'images' => self::imagesJson($pid)]);
    }

    /** Порядок фото одним запросом; главное фото — первое */
    private static function applyImageOrder(int $pid, array $order): void
    {
        if (!$order) return;
        $db = App::db();
        $case = 'CASE id'; $params = [];
        foreach ($order as $i => $iid) { $case .= ' WHEN ? THEN ?'; array_push($params, $iid, $i); }
        [$ph, $vals] = $db->in($order);
        $db->query("UPDATE product_images SET sort = $case END WHERE product_id = ? AND id IN ($ph)", array_merge($params, [$pid], $vals));
        AdminCatalog::syncMainImage($pid);
        Cache::flush();
    }
}
