<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\App;
use App\Core\Cache;
use App\Core\Paginator;
use App\Core\Request;
use App\Core\Response;
use App\Services\AdminCatalog;
use App\Services\Catalog;
use App\Services\HtmlSanitizer;

/**
 * Админка → Бренды. Бренд = значение характеристики «Бренд» (id совпадают, как в Webasyst):
 * переименование меняет и значение характеристики, новый бренд создаёт значение.
 */
final class BrandsController extends BaseController
{
    private const PER_PAGE = 50;

    /** Сортировки списка — только из этого списка */
    private const SORTS = [
        'name'   => ['b.name ASC, b.id ASC', 'По названию'],
        'count'  => ['b.product_count DESC, b.name ASC', 'Больше товаров'],
        'new'    => ['b.id DESC', 'Сначала новые'],
    ];

    /** Поля с украинским вариантом (*_uk). Название бренда не переводится. */
    private const UK_FIELDS = ['title', 'h1', 'meta_description', 'meta_keywords', 'summary', 'description', 'seo_description'];

    public function index(): Response
    {
        $db = App::db();
        $q = mb_substr(Request::get('q'), 0, 100);
        $show = in_array(Request::get('show'), ['visible', 'hidden', 'empty', 'nouk'], true) ? Request::get('show') : '';
        $sort = isset(self::SORTS[Request::get('sort')]) ? Request::get('sort') : 'name';
        $w = []; $p = [];
        if ($q !== '') {
            $like = '%' . addcslashes($q, '%_\\') . '%';
            if (ctype_digit($q)) { $w[] = '(b.name LIKE ? OR b.url LIKE ? OR b.id = ?)'; array_push($p, $like, $like, (int) $q); }
            else { $w[] = '(b.name LIKE ? OR b.url LIKE ?)'; array_push($p, $like, $like); }
        }
        if ($show === 'visible') $w[] = 'b.hidden = 0';
        if ($show === 'hidden') $w[] = 'b.hidden = 1';
        if ($show === 'empty') $w[] = 'b.product_count = 0';
        $noUkSql = "(b.description IS NOT NULL AND b.description <> '' AND (b.description_uk IS NULL OR b.description_uk = ''))";
        if ($show === 'nouk') $w[] = $noUkSql;
        $where = $w ? implode(' AND ', $w) : '1';
        $total = (int) $db->value("SELECT COUNT(*) FROM brands b WHERE $where", $p);
        $pg = new Paginator($total, self::PER_PAGE, Request::page());
        $rows = $db->all("SELECT b.id, b.name, b.url, b.image, b.hidden, b.product_count, b.title, b.meta_description,
            (b.description IS NOT NULL AND b.description <> '') AS has_desc,
            (COALESCE(b.title_uk, '') <> '' OR COALESCE(b.description_uk, '') <> '' OR COALESCE(b.meta_description_uk, '') <> '') AS has_uk
            FROM brands b WHERE $where ORDER BY " . self::SORTS[$sort][0] . ' LIMIT ' . self::PER_PAGE . ' OFFSET ' . $pg->offset, $p);
        // счётчики вкладок — одним запросом (брендов сотни)
        $counts = array_map('intval', $db->row("SELECT COUNT(*) AS `all`, COALESCE(SUM(b.hidden = 0), 0) AS visible, COALESCE(SUM(b.hidden = 1), 0) AS hidden,
            COALESCE(SUM(b.product_count = 0), 0) AS `empty`, COALESCE(SUM($noUkSql), 0) AS nouk FROM brands b") ?? []);   // EMPTY — зарезервированное слово MySQL 8
        return $this->render('admin/brands/index', [
            'title'   => 'Бренды',
            'actions' => '<a class="btn btn-p" href="/admin/brands/new/">+ Добавить бренд</a>',
            'styles'  => ['admin/catalog.css'],
            'rows'    => $rows,
            'total'   => $total,
            'pg'      => $pg,
            'q'       => $q,
            'show'    => $show,
            'sort'    => $sort,
            'sorts'   => array_map(static fn($s) => $s[1], self::SORTS),
            'counts'  => $counts,
        ]);
    }

    public function create(): Response
    {
        return $this->form(null);
    }

    public function edit(string $id): Response
    {
        $b = ctype_digit($id) ? App::db()->row('SELECT * FROM brands WHERE id = ?', [(int) $id]) : null;
        if (!$b) {
            $r = $this->render('admin/forbidden', ['title' => 'Бренд не найден', 'message' => 'Такого бренда нет — возможно, он удалён или объединён с другим.']);
            $r->status = 404;
            return $r;
        }
        return $this->form($b);
    }

    private function form(?array $b): Response
    {
        $db = App::db();
        $id = (int) ($b['id'] ?? 0);
        $errors = [];
        $b ??= ['id' => 0, 'name' => '', 'url' => '', 'title' => '', 'h1' => '', 'meta_keywords' => '', 'meta_description' => '', 'summary' => '',
            'description' => '', 'seo_description' => '', 'image' => null, 'hidden' => 0, 'sort' => 0, 'product_count' => 0];
        foreach (self::UK_FIELDS as $k) $b[$k . '_uk'] ??= null;
        $brandF = AdminCatalog::brandFeatureId();

        if (Request::isPost()) {
            $d = [
                'name'             => trim((string) preg_replace('/\s+/u', ' ', mb_substr(Request::post('name'), 0, 255))),
                // название на /ua/ — только для служебных («Не указано» → «Не вказано»); пусто = как в RU. Адрес (url) от него не зависит
                'name_uk'          => trim((string) preg_replace('/\s+/u', ' ', mb_substr(Request::post('name_uk'), 0, 255))) ?: null,
                'title'            => AdminCatalog::postStr('title'),
                'h1'               => AdminCatalog::postStr('h1'),
                'meta_keywords'    => AdminCatalog::postStr('meta_keywords', 5000),
                'meta_description' => AdminCatalog::postStr('meta_description', 5000),
                'summary'          => AdminCatalog::postStr('summary'),
                // HTML: у менеджера — без скриптов и опасных атрибутов (HtmlSanitizer::staff), поле без правок — как в базе
                // (AdminCatalog::staffHtml); summary — простой текст (на сайте через e())
                'description'      => AdminCatalog::staffHtml(AdminCatalog::postHtml('description'), $b['description'] ?? null),
                'seo_description'  => AdminCatalog::staffHtml(AdminCatalog::postHtml('seo_description'), $b['seo_description'] ?? null),
                'hidden'           => Request::post('hidden') === '1' ? 1 : 0,
                'sort'             => max(-100000, min(100000, Request::postInt('sort'))),
            ];
            foreach (self::UK_FIELDS as $k) {
                $d[$k . '_uk'] = in_array($k, ['description', 'seo_description'], true)
                    ? AdminCatalog::staffHtml(AdminCatalog::postHtml($k . '_uk'), $b[$k . '_uk'] ?? null)
                    : AdminCatalog::postStr($k . '_uk', in_array($k, ['meta_keywords', 'meta_description'], true) ? 5000 : 500);
            }
            $d = AdminCatalog::keepUnchanged($d, $b);   // без правок — байт в байт (перевод строки в конце meta_description из Webasyst)
            if ($d['name'] === '') $errors['name'] = 'Укажите название бренда.';
            elseif ($brandF && ($other = (int) $db->value('SELECT id FROM feature_values WHERE feature_id = ? AND value = ? AND id <> ?', [$brandF, $d['name'], $id]))) {
                $errors['name'] = 'Бренд «' . $d['name'] . '» уже есть (№' . $other . '). Чтобы объединить дубли, используйте «Характеристики → Бренд → Найти похожие».';
            }
            // адрес: как в Webasyst — имя бренда (/brand/Mona+Lisa/); вручную — любой без «/ ? # %»
            $url = trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace('/[\/\\\\?#%]+/u', ' ', Request::post('url'))));
            if ($url === '') $url = $d['name'];
            $url = mb_substr($url, 0, 190);
            if ($url !== '' && ($other = (int) $db->value('SELECT id FROM brands WHERE url = ? AND id <> ?', [$url, $id]))) {
                $errors['url'] = 'Адрес уже занят брендом №' . $other . '.';
            }
            $d['url'] = $url;

            // логотип: файл с компьютера важнее выбранного в медиатеке; пустое поле — без логотипа
            $picked = AdminCatalog::pickedImage(Request::post('image'), $b['image'] ?? null);
            if ($picked === false) $errors['image'] = 'Логотип не найден в медиатеке — выберите его заново.';
            $upload = null;
            if (!$errors && !empty($_FILES['image_file']) && ($_FILES['image_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                $upload = AdminCatalog::saveUpload($_FILES['image_file'], 'brands', 'brand-' . ($id ?: 'new'), '400');
                if (is_array($upload)) { $errors['image'] = 'Логотип: ' . $upload['error'] . '.'; $upload = null; }
            }

            if (!$errors) {
                $old = $b;
                $d['image'] = $upload ?: $picked;

                if (!$id) {
                    // новый бренд = новое значение характеристики «Бренд» (id совпадают)
                    if (!$brandF) {
                        $id = $db->insert('brands', $d);
                    } else {
                        $id = AdminCatalog::valueId($brandF, $d['name']);
                        if (!$id) {
                            $this->flash('Не удалось создать бренд — попробуйте ещё раз.', true);
                            return Response::redirect('/admin/brands/new/');
                        }
                        $db->update('brands', $d, 'id = ?', [$id]);
                    }
                } else {
                    $db->update('brands', $d, 'id = ?', [$id]);
                    if ($brandF && $d['name'] !== $old['name']) {
                        $db->update('feature_values', ['value' => $d['name']], 'id = ? AND feature_id = ?', [$id, $brandF]);
                    }
                    if ($d['url'] !== $old['url'] && Request::post('redirect') === '1') {
                        // from_url — как Request::path (раскодирован, пробел = «+»), to_url — ссылка /brand/Mona+Lisa/
                        $oldLink = Catalog::brandUrl($old);
                        AdminCatalog::addRedirect(rawurldecode($oldLink), Catalog::brandUrl($d), [$oldLink]);
                    }
                }
                // name_uk = значение характеристики «Бренд» на /ua/ (фильтр, характеристики товара)
                if ($brandF && (string) ($old['name_uk'] ?? '') !== (string) $d['name_uk']) {
                    $db->update('feature_values', ['value_uk' => $d['name_uk']], 'id = ? AND feature_id = ?', [$id, $brandF]);
                }
                if (!empty($old['image']) && $old['image'] !== $d['image']) AdminCatalog::deleteUpload($old['image']);
                Cache::flush();
                $this->log($old['id'] ? 'brand_update' : 'brand_create', 'brand', $id, ['name' => $d['name']]);
                $this->flash(($old['id'] ? 'Бренд сохранён.' : 'Бренд создан.') . HtmlSanitizer::notice());
                return Response::redirect('/admin/brands/' . $id . '/');
            }
            $b = array_merge($b, $d, ['image' => $picked === false ? $b['image'] : $picked]);
        }

        $all = $id ? (int) $db->value('SELECT COUNT(*) FROM products WHERE brand_id = ?', [$id]) : 0;
        $cats = [];
        if ($id) {
            foreach ($db->all("SELECT id, name, conditions FROM categories WHERE type = 1 AND conditions LIKE '%brand.value_id%'") as $c) {
                if (preg_match('/brand\.value_id\s*=\s*([\d,]+)/', (string) $c['conditions'], $m) && in_array($id, array_map('intval', explode(',', $m[1])), true)) $cats[] = $c;
            }
        }
        return $this->render('admin/brands/form', [
            'title'   => $id ? 'Бренд: ' . $b['name'] : 'Новый бренд',
            'back'    => ['/admin/brands/', 'Все бренды'],
            'actions' => $id ? '<a class="btn" href="/admin/products/?brand=' . $id . '">Товары бренда</a> <a class="btn" href="' . e(Catalog::brandUrl($b)) . '" target="_blank" rel="noopener">На сайте ↗</a>' : '',
            'styles'  => ['admin/catalog.css'],
            'scripts' => ['admin/catalog.js', 'admin/media.js'],
            'b'       => $b,
            'errors'  => $errors,
            'all'     => $all,
            'dynCats' => $cats,
        ]);
    }

    /** Удаление — только бренда без товаров и не используемого в условиях категорий */
    public function delete(string $id): Response
    {
        $db = App::db();
        $b = ctype_digit($id) ? $db->row('SELECT id, name, url, image FROM brands WHERE id = ?', [(int) $id]) : null;
        if (!$b) { $this->flash('Бренд не найден.', true); return Response::redirect('/admin/brands/'); }
        $bid = (int) $b['id'];
        $brandF = AdminCatalog::brandFeatureId();
        $n = (int) $db->value('SELECT COUNT(*) FROM products WHERE brand_id = ?', [$bid])
            + ($brandF ? (int) $db->value('SELECT COUNT(*) FROM product_features WHERE feature_id = ? AND value_id = ?', [$brandF, $bid]) : 0);
        if ($n) {
            $this->flash('У бренда есть товары — удалить нельзя. Перенесите товары на другой бренд или объедините бренды в разделе «Характеристики».', true);
            return Response::redirect('/admin/brands/' . $bid . '/');
        }
        foreach ($db->all("SELECT name, conditions FROM categories WHERE type = 1 AND conditions LIKE '%brand.value_id%'") as $c) {
            if (preg_match('/brand\.value_id\s*=\s*([\d,]+)/', (string) $c['conditions'], $m) && in_array($bid, array_map('intval', explode(',', $m[1])), true)) {
                $this->flash('Бренд используется в условии категории «' . $c['name'] . '» — сначала измените условие.', true);
                return Response::redirect('/admin/brands/' . $bid . '/');
            }
        }
        $db->transaction(static function ($db) use ($bid, $brandF) {
            $db->delete('brands', 'id = ?', [$bid]);
            if ($brandF) $db->delete('feature_values', 'id = ? AND feature_id = ?', [$bid, $brandF]);
        });
        AdminCatalog::deleteUpload($b['image'] ?? null);
        AdminCatalog::dropRedirectsTo([Catalog::brandUrl($b), rawurldecode(Catalog::brandUrl($b))]);
        Cache::flush();
        $this->log('brand_delete', 'brand', $bid, ['name' => $b['name']]);
        $this->flash('Бренд «' . $b['name'] . '» удалён.');
        return Response::redirect('/admin/brands/');
    }
}
