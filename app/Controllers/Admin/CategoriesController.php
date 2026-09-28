<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\App;
use App\Core\Cache;
use App\Core\Request;
use App\Core\Response;
use App\Core\Str;
use App\Services\AdminCatalog;
use App\Services\Catalog;
use App\Services\CatalogIndexer;
use App\Services\HtmlSanitizer;

/** Админка → Категории: дерево (nested set), создание/редактирование, перемещение, удаление с переносом товаров. */
final class CategoriesController extends BaseController
{
    /** Поля с украинским вариантом (*_uk) */
    private const UK_FIELDS = ['name', 'seo_name', 'h1', 'meta_title', 'meta_description', 'meta_keywords', 'description', 'seo_description'];

    public function index(): Response
    {
        return $this->render('admin/categories/index', [
            'title'   => 'Категории',
            'actions' => '<a class="btn btn-p" href="/admin/categories/new/">+ Добавить категорию</a>',
            'styles'  => ['admin/catalog.css'],
            'scripts' => ['admin/catalog.js'],
            'cats'    => AdminCatalog::categories(),
            'direct'  => AdminCatalog::directCounts(),
        ]);
    }

    /** Вверх/вниз среди соседей или смена родителя: POST id, dir=up|down | parent_id */
    public function move(): Response
    {
        $db = App::db();
        $id = Request::postInt('id');
        $c = $db->row('SELECT id, parent_id, name FROM categories WHERE id = ?', [$id]);
        if (!$c) { $this->flash('Категория не найдена.', true); return $this->back('/admin/categories/'); }
        $dir = Request::post('dir');
        if ($dir === 'up' || $dir === 'down') {
            $sib = array_map('intval', $db->col('SELECT id FROM categories WHERE parent_id = ? ORDER BY sort, id', [(int) $c['parent_id']]));
            $i = array_search($id, $sib, true);
            $j = $dir === 'up' ? $i - 1 : $i + 1;
            if ($i !== false && isset($sib[$j])) {
                [$sib[$i], $sib[$j]] = [$sib[$j], $sib[$i]];
                AdminCatalog::renumber($sib);
                AdminCatalog::rebuildTree();
                Cache::flush();
                $this->log('category_move', 'category', $id, ['dir' => $dir]);
            }
            return $this->back('/admin/categories/');
        }
        if (isset($_POST['parent_id'])) {
            $parent = Request::postInt('parent_id');
            $err = $this->checkParent($id, $parent);
            if ($err) { $this->flash($err, true); return $this->back('/admin/categories/'); }
            $this->changeParent($c, $parent);
            $this->flash('Категория «' . $c['name'] . '» перемещена.');
            return $this->back('/admin/categories/');
        }
        return $this->back('/admin/categories/');
    }

    /** Возврат к дереву — только в пределах админки этого сайта (Referer с чужим хостом игнорируется) */
    protected function back(string $fallback = '/admin/'): Response
    {
        return Response::redirect(AdminCatalog::backUrl($fallback));
    }

    /** Родитель допустим: существует и не является самой категорией или её потомком */
    private function checkParent(int $id, int $parent): ?string
    {
        if ($parent === 0) return null;
        $cats = AdminCatalog::categories();
        if (!isset($cats[$parent])) return 'Родительская категория не найдена.';
        if ($id && in_array($parent, AdminCatalog::subtreeIds($id, $cats), true)) return 'Нельзя переместить категорию внутрь самой себя.';
        return null;
    }

    private function changeParent(array $c, int $parent): void
    {
        $db = App::db();
        $old = (int) $c['parent_id'];
        if ($old === $parent) return;
        $sort = (int) $db->value('SELECT COALESCE(MAX(sort), 0) + 10 FROM categories WHERE parent_id = ?', [$parent]);
        $db->update('categories', ['parent_id' => $parent, 'sort' => $sort, 'updated_at' => date('Y-m-d H:i:s')], 'id = ?', [(int) $c['id']]);
        AdminCatalog::rebuildTree();
        // товары категории попадают в новых предков и уходят из старых
        AdminCatalog::reindexCategories(array_filter([(int) $c['id'], $old]));
        $this->log('category_parent', 'category', (int) $c['id'], ['from' => $old, 'to' => $parent]);
    }

    public function create(): Response
    {
        return $this->form(null);
    }

    public function edit(string $id): Response
    {
        $c = ctype_digit($id) ? App::db()->row('SELECT * FROM categories WHERE id = ?', [(int) $id]) : null;
        if (!$c) {
            $r = $this->render('admin/forbidden', ['title' => 'Категория не найдена', 'message' => 'Такой категории нет — возможно, она удалена.']);
            $r->status = 404;
            return $r;
        }
        return $this->form($c);
    }

    private function form(?array $c): Response
    {
        $db = App::db();
        $id = (int) ($c['id'] ?? 0);
        $errors = [];
        $c ??= ['id' => 0, 'parent_id' => Request::getInt('parent'), 'name' => '', 'url' => '', 'type' => 0, 'conditions' => '', 'include_sub' => 1,
            'status' => 1, 'sort_products' => 'create_datetime DESC', 'filter' => 'price,2,5,8,9,11', 'enable_sorting' => 1, 'seo_name' => '', 'h1' => '',
            'meta_title' => '', 'meta_keywords' => '', 'meta_description' => '', 'description' => '', 'seo_description' => '', 'image' => null,
            'product_count' => 0];
        foreach (self::UK_FIELDS as $k) $c[$k . '_uk'] ??= null;
        $features = $db->keyed('SELECT id, code, name, is_filter, status FROM features ORDER BY sort, id');

        if (Request::isPost()) {
            $str = static fn(string $k, int $max = 500) => ($v = mb_substr(Request::post($k), 0, $max)) === '' ? null : $v;
            // HTML-описания: у менеджера — без скриптов и опасных атрибутов (HtmlSanitizer::staff), у администратора — как есть
            $html = static fn(string $k) => ($v = trim((string) ($_POST[$k] ?? ''))) === '' ? null : HtmlSanitizer::staff(mb_substr($v, 0, 1000000));
            $d = [
                'name'             => mb_substr(Request::post('name'), 0, 255),
                'parent_id'        => max(0, Request::postInt('parent_id')),
                'type'             => Request::post('type') === '1' ? 1 : 0,
                'conditions'       => $str('conditions', 1000),
                'include_sub'      => Request::post('include_sub') === '1' ? 1 : 0,
                'status'           => Request::post('status') === '0' ? 0 : 1,
                'enable_sorting'   => Request::post('enable_sorting') === '1' ? 1 : 0,
                'seo_name'         => $str('seo_name'),
                'h1'               => $str('h1'),
                'meta_title'       => $str('meta_title'),
                'meta_description' => $str('meta_description', 5000),
                'meta_keywords'    => $str('meta_keywords', 5000),
                'description'      => $html('description'),
                'seo_description'  => $html('seo_description'),
            ];
            // украинская версия (пусто — на /ua/ показывается русский текст)
            foreach (self::UK_FIELDS as $k) {
                $d[$k . '_uk'] = in_array($k, ['description', 'seo_description'], true)
                    ? HtmlSanitizer::staff(AdminCatalog::postHtml($k . '_uk'))
                    : AdminCatalog::postStr($k . '_uk', $k === 'name' ? 255 : (str_starts_with($k, 'meta_') && $k !== 'meta_title' ? 5000 : 500));
            }
            $d = AdminCatalog::keepUnchanged($d, $c);   // без правок — байт в байт (перевод строки в конце H1 из импорта)
            $sort = Request::post('sort_products');
            $d['sort_products'] = (isset(AdminCatalog::CATEGORY_SORTS[$sort]) || $sort === (string) ($c['sort_products'] ?? '')) && $sort !== '' ? $sort : null;
            // фильтры: «price» + id характеристик в порядке отметки
            $flt = [];
            foreach (Request::postArray('filter') as $x) {
                $x = is_scalar($x) ? (string) $x : '';
                if ($x === 'price' || (ctype_digit($x) && isset($features[(int) $x]))) $flt[$x] = $x;
            }
            $d['filter'] = $flt ? implode(',', $flt) : null;

            if ($d['name'] === '') $errors['name'] = 'Укажите название.';
            if ($e = $this->checkParent($id, $d['parent_id'])) $errors['parent_id'] = $e;
            $url = AdminCatalog::cleanUrl(Request::post('url'));
            if ($url === '') {
                $base = Str::slug($d['name'] !== '' ? $d['name'] : 'category');
                $url = $base;
                for ($i = 2; $db->value('SELECT id FROM categories WHERE url = ? AND id <> ?', [$url, $id]); $i++) $url = $base . '-' . $i;
            } elseif ($other = (int) $db->value('SELECT id FROM categories WHERE url = ? AND id <> ?', [$url, $id])) {
                $errors['url'] = 'Адрес уже занят категорией №' . $other . '.';
            }
            $d['url'] = $url;
            if ($d['type'] === 1) {
                [$where] = CatalogIndexer::conditionSql((string) $d['conditions']);
                if ($where === '') $errors['conditions'] = 'Условие не распознано — пример: compare_price>0 или brand.value_id=40.';
            }

            // картинка категории (плитка на главной): файл с компьютера важнее выбранной в медиатеке; пустое поле — без картинки
            $picked = AdminCatalog::pickedImage(Request::post('image'), $c['image'] ?? null);
            if ($picked === false) $errors['image'] = 'Картинка не найдена в медиатеке — выберите её заново.';
            $upload = null;
            if (!$errors && !empty($_FILES['image_file']) && ($_FILES['image_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                $upload = AdminCatalog::saveUpload($_FILES['image_file'], 'categories', 'cat-' . ($id ?: 'new'), '800');
                if (is_array($upload)) { $errors['image'] = 'Картинка: ' . $upload['error'] . '.'; $upload = null; }
            }

            if (!$errors) {
                $old = $c;
                $d['image'] = $upload ?: $picked;
                $d['updated_at'] = date('Y-m-d H:i:s');
                if ($id) {
                    $parentChanged = (int) $old['parent_id'] !== $d['parent_id'];
                    $newParent = $d['parent_id'];
                    unset($d['parent_id']);
                    $db->update('categories', $d, 'id = ?', [$id]);
                    if ($d['url'] !== $old['url']) AdminCatalog::rebuildTree();                // full_url
                    if ($parentChanged) {
                        $this->changeParent($old, $newParent);
                    } elseif ((int) $old['type'] !== $d['type'] || (string) $old['conditions'] !== (string) $d['conditions']
                        || (int) $old['include_sub'] !== $d['include_sub'] || (int) $old['status'] !== $d['status']) {
                        AdminCatalog::reindexCategories([$id], false);
                    }
                    if ($d['url'] !== $old['url'] && Request::post('redirect') === '1') {
                        AdminCatalog::addRedirect('/category/' . $old['url'] . '/', '/category/' . $d['url'] . '/');
                    }
                } else {
                    $d['sort'] = (int) $db->value('SELECT COALESCE(MAX(sort), 0) + 10 FROM categories WHERE parent_id = ?', [$d['parent_id']]);
                    $d['created_at'] = $d['updated_at'];
                    $id = $db->insert('categories', $d);
                    AdminCatalog::rebuildTree();
                    AdminCatalog::reindexCategories([$id], false);
                }
                if (!empty($old['image']) && $old['image'] !== $d['image']) AdminCatalog::deleteUpload($old['image']);
                Cache::flush();
                $this->log($old['id'] ? 'category_update' : 'category_create', 'category', $id, ['name' => $d['name']]);
                $this->flash(($old['id'] ? 'Категория сохранена.' : 'Категория создана.') . HtmlSanitizer::notice());
                return Response::redirect('/admin/categories/' . $id . '/');
            }
            $c = array_merge($c, $d, ['image' => $picked === false ? $c['image'] : $picked]);
        }

        $cats = AdminCatalog::categories();
        $dynCount = null;
        if ($id && (int) $c['type'] === 1) {
            [$where, $params] = CatalogIndexer::conditionSql((string) $c['conditions']);
            $dynCount = $where !== '' ? (int) $db->value("SELECT COUNT(*) FROM products p WHERE p.status = 1 AND $where", $params) : 0;
        }
        $vars = ['category' => ['name' => $c['name'], 'seo_name' => trim((string) $c['seo_name']) ?: $c['name']]];
        $uk = AdminCatalog::ukRow($c);
        $varsUk = ['category' => ['name' => $uk['name'], 'seo_name' => trim((string) $uk['seo_name']) ?: $uk['name']]];
        // что покажет /ua/ при пустом украинском поле: своё русское значение, иначе украинский шаблон (как витрина)
        $seoUk = AdminCatalog::ukFallback($c, AdminCatalog::seoTemplates('category', $varsUk, 'uk'));
        $seoUk['meta_title'] = $seoUk['meta_title'] ?: (string) $uk['name'];
        $seoUk['seo_name'] = trim((string) ($c['seo_name'] ?? '')) ?: (string) $uk['name'];
        return $this->render('admin/categories/form', [
            'title'    => $id ? 'Категория: ' . $c['name'] : 'Новая категория',
            'back'     => ['/admin/categories/', 'Все категории'],
            'actions'  => $id ? '<a class="btn" href="/admin/products/?category=' . $id . '">Товары</a> <a class="btn" href="/category/' . e(rawurlencode((string) $c['url'])) . '/" target="_blank" rel="noopener">На сайте ↗</a>' : '',
            'styles'   => ['admin/catalog.css'],
            'scripts'  => ['admin/catalog.js', 'admin/media.js'],
            'c'        => $c,
            'cats'     => $cats,
            'features' => $features,
            'errors'   => $errors,
            'seoTpl'   => AdminCatalog::seoTemplates('category', $vars),
            'seoUk'    => $seoUk,
            'sorts'    => AdminCatalog::CATEGORY_SORTS,
            'brands'   => Catalog::brands(),
            'direct'   => $id ? (int) $db->value('SELECT COUNT(*) FROM category_products WHERE category_id = ?', [$id]) : 0,
            'children' => $id ? (int) $db->value('SELECT COUNT(*) FROM categories WHERE parent_id = ?', [$id]) : 0,
            'mainCount' => $id ? (int) $db->value('SELECT COUNT(*) FROM products WHERE category_id = ?', [$id]) : 0,
            'dynCount' => $dynCount,
        ]);
    }

    /** Удаление: только без подкатегорий; товары — перенести в другую категорию (move_to) */
    public function delete(string $id): Response
    {
        $db = App::db();
        $c = ctype_digit($id) ? $db->row('SELECT * FROM categories WHERE id = ?', [(int) $id]) : null;
        if (!$c) { $this->flash('Категория не найдена.', true); return Response::redirect('/admin/categories/'); }
        $cid = (int) $c['id'];
        if ((int) $db->value('SELECT COUNT(*) FROM categories WHERE parent_id = ?', [$cid])) {
            $this->flash('Сначала перенесите или удалите подкатегории.', true);
            return Response::redirect('/admin/categories/' . $cid . '/');
        }
        $direct = (int) $db->value('SELECT COUNT(*) FROM category_products WHERE category_id = ?', [$cid]);
        $main = (int) $db->value('SELECT COUNT(*) FROM products WHERE category_id = ?', [$cid]);
        $target = Request::postInt('move_to');
        $t = $target ? $db->row('SELECT id, name, type FROM categories WHERE id = ?', [$target]) : null;
        if (($direct || $main) && (!$t || (int) $t['type'] === 1 || $target === $cid)) {
            $this->flash('В категории есть товары (' . max($direct, $main) . ') — выберите обычную категорию, куда их перенести.', true);
            return Response::redirect('/admin/categories/' . $cid . '/');
        }
        $db->transaction(static function ($db) use ($cid, $t) {
            if ($t) {
                $db->query('INSERT IGNORE INTO category_products (category_id, product_id, sort) SELECT ?, product_id, sort FROM category_products WHERE category_id = ?', [(int) $t['id'], $cid]);
                $db->query('UPDATE products SET category_id = ? WHERE category_id = ?', [(int) $t['id'], $cid]);
            } else {
                $db->query('UPDATE products SET category_id = NULL WHERE category_id = ?', [$cid]);
            }
            foreach (['category_products', 'catalog_index', 'category_facets'] as $tbl) $db->query("DELETE FROM `$tbl` WHERE category_id = ?", [$cid]);
            $db->delete('categories', 'id = ?', [$cid]);
        });
        AdminCatalog::deleteUpload($c['image'] ?? null);
        AdminCatalog::dropRedirectsTo(['/category/' . $c['url'] . '/']);
        AdminCatalog::rebuildTree();
        // товары перешли в другую ветку — пересчитать её и прежних предков; пустую категорию — ничего не пересчитываем
        if ($direct) AdminCatalog::reindexCategories(array_filter([$t ? (int) $t['id'] : 0, (int) $c['parent_id']]));
        Cache::forget('admin.category_direct_counts');
        Cache::flush();
        $this->log('category_delete', 'category', $cid, ['name' => $c['name'], 'moved_to' => $t['id'] ?? null, 'products' => $direct]);
        $this->flash('Категория «' . $c['name'] . '» удалена' . ($t && $direct ? ', товары перенесены в «' . $t['name'] . '»' : '') . '.');
        return Response::redirect('/admin/categories/');
    }
}
