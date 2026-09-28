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
use App\Services\CatalogIndexer;

/** Админка → Характеристики: список, настройки, значения (переименование, объединение дублей, удаление). */
final class FeaturesController extends BaseController
{
    private const STATUSES = ['public' => 'На сайте', 'hidden' => 'Скрыта (только фильтр/поиск)', 'private' => 'Служебная (только админка)'];
    private const TYPES = ['varchar' => 'Текст', 'color' => 'Цвет', 'double' => 'Число'];
    private const PER_PAGE = 50;
    private const VSORTS = ['sort' => 'sort, id', 'value' => 'value, id', 'id' => 'id DESC'];
    /** Поиск похожих значений — только если значений не больше этого */
    private const DUPS_MAX = 30000;

    public function index(): Response
    {
        $db = App::db();
        $features = $db->all('SELECT id, code, name, name_uk, type, multiple, status, is_filter, sort FROM features ORDER BY sort, id');
        $stats = AdminCatalog::featureStats();             // тяжёлые подсчёты по 800 тыс. строк — свой кэш на 10 минут
        return $this->render('admin/features/index', [
            'title'    => 'Характеристики',
            'actions'  => '<a class="btn btn-p" href="/admin/features/new/">+ Добавить характеристику</a>',
            'styles'   => ['admin/catalog.css'],
            'features' => $features,
            'values'   => $stats['values'],
            'noUk'     => $stats['noUk'],
            'products' => $stats['products'],
            'statuses' => self::STATUSES,
            'types'    => self::TYPES,
        ]);
    }

    public function create(): Response
    {
        return $this->form(null);
    }

    public function edit(string $id): Response
    {
        $f = ctype_digit($id) ? App::db()->row('SELECT * FROM features WHERE id = ?', [(int) $id]) : null;
        if (!$f) {
            $r = $this->render('admin/forbidden', ['title' => 'Характеристика не найдена', 'message' => 'Такой характеристики нет.']);
            $r->status = 404;
            return $r;
        }
        return $this->form($f);
    }

    private function form(?array $f): Response
    {
        $db = App::db();
        $id = (int) ($f['id'] ?? 0);
        $errors = [];
        $f ??= ['id' => 0, 'code' => '', 'name' => '', 'name_uk' => null, 'type' => 'varchar', 'multiple' => 0, 'status' => 'public', 'is_filter' => 0,
            'sort' => (int) $db->value('SELECT COALESCE(MAX(sort), 0) + 1 FROM features')];

        if (Request::isPost()) {
            $d = [
                'name'      => mb_substr(Request::post('name'), 0, 255),
                'name_uk'   => AdminCatalog::postStr('name_uk', 255),
                'status'    => isset(self::STATUSES[Request::post('status')]) ? Request::post('status') : 'public',
                'is_filter' => Request::post('is_filter') === '1' ? 1 : 0,
                'multiple'  => Request::post('multiple') === '1' ? 1 : 0,
                'sort'      => Request::postInt('sort'),
            ];
            if ($d['name'] === '') $errors['name'] = 'Укажите название.';
            if (!$id) {
                $d['code'] = strtolower(Request::post('code'));
                $d['type'] = isset(self::TYPES[Request::post('type')]) ? Request::post('type') : 'varchar';
                if (!preg_match('/^[a-z][a-z0-9_]{1,63}$/', $d['code'])) $errors['code'] = 'Код — латиница, цифры и «_», начинается с буквы (например, material_verha).';
                elseif ($db->value('SELECT id FROM features WHERE code = ?', [$d['code']])) $errors['code'] = 'Такой код уже есть.';
            }
            if (!$errors) {
                if ($id) {
                    $db->update('features', $d, 'id = ?', [$id]);
                } else {
                    $id = $db->insert('features', $d);
                }
                // «в фильтре» изменилось — пересчитать фильтры категорий
                if ((int) ($f['is_filter'] ?? 0) !== $d['is_filter'] && $f['id']) AdminCatalog::rebuildAllFacets();
                Cache::flush();
                $this->log($f['id'] ? 'feature_update' : 'feature_create', 'feature', $id, ['name' => $d['name']]);
                $this->flash($f['id'] ? 'Характеристика сохранена.' : 'Характеристика создана — добавьте значения.');
                return Response::redirect('/admin/features/' . $id . '/');
            }
            $f = array_merge($f, $d);
        }

        $data = [
            'title'    => $id ? 'Характеристика: ' . $f['name'] : 'Новая характеристика',
            'back'     => ['/admin/features/', 'Все характеристики'],
            'styles'   => ['admin/catalog.css'],
            'scripts'  => ['admin/catalog.js'],
            'f'        => $f,
            'errors'   => $errors,
            'statuses' => self::STATUSES,
            'types'    => self::TYPES,
            'rows'     => [], 'counts' => [], 'pg' => null, 'dups' => null, 'vq' => '', 'vsort' => 'sort', 'unused' => false, 'nouk' => false,
            'dupsTooMany' => false, 'usedBy' => null,
        ];
        if ($id) $data = array_merge($data, $this->valuesPage($id, $f));
        return $this->render('admin/features/form', $data);
    }

    /** Значения характеристики: поиск, страницы, количество товаров у каждого значения */
    private function valuesPage(int $fid, array $f): array
    {
        $db = App::db();
        $vq = mb_substr(Request::get('vq'), 0, 100);
        $vsort = isset(self::VSORTS[Request::get('vsort')]) ? Request::get('vsort') : 'sort';
        $unused = Request::get('unused') === '1';
        $nouk = Request::get('nouk') === '1';
        $w = 'fv.feature_id = ?'; $p = [$fid];
        if ($vq !== '') {
            $like = '%' . addcslashes($vq, '%_\\') . '%';
            if (ctype_digit($vq)) { $w .= ' AND (fv.value LIKE ? OR fv.value_uk LIKE ? OR fv.id = ?)'; array_push($p, $like, $like, (int) $vq); }
            else { $w .= ' AND (fv.value LIKE ? OR fv.value_uk LIKE ?)'; array_push($p, $like, $like); }
        }
        if ($nouk) $w .= " AND (fv.value_uk IS NULL OR fv.value_uk = '')";
        // «без товаров» — анти-джойн по индексу filter: NOT EXISTS MariaDB превращает в материализацию всей
        // product_features (840 тыс. строк, 0,2–0,3 с), LEFT JOIN … IS NULL останавливается на первых 50 строках
        $from = 'feature_values fv';
        if ($unused) {
            $from .= ' LEFT JOIN product_features pfu ON pfu.feature_id = fv.feature_id AND pfu.value_id = fv.id';
            $w .= ' AND pfu.value_id IS NULL';
        }
        // количество — в кэше на 5 минут (листание страниц не пересчитывает 190 тыс. строк); любое изменение значений
        // этой характеристики меняет её «версию» (done()), изменение товаров — Cache::flush()
        $ckey = 'admin.fvcount.' . $fid . '.' . Cache::get('admin.fvver.' . $fid, 0) . '.' . md5($from . '|' . $w . '|' . json_encode($p));
        $total = (int) Cache::remember($ckey, 300, static function () use ($db, $fid, $from, $w, $p, $unused, $vq, $nouk) {
            if ($unused && $vq === '' && !$nouk) {
                // без других условий: все − используемые (для «Даты съёмки» 0,3 с → 0,09 с)
                return max(0, (int) $db->value('SELECT COUNT(*) FROM feature_values WHERE feature_id = ?', [$fid])
                    - (int) $db->value('SELECT COUNT(*) FROM (SELECT DISTINCT pf.value_id FROM product_features pf WHERE pf.feature_id = ?) u
                        JOIN feature_values fv ON fv.id = u.value_id AND fv.feature_id = ?', [$fid, $fid]));
            }
            return (int) $db->value("SELECT COUNT(*) FROM $from WHERE $w", $p);
        });
        $pg = new Paginator($total, self::PER_PAGE, Request::page());
        $rows = $db->all("SELECT fv.id, fv.value, fv.value_uk, fv.code, fv.sort FROM $from WHERE $w ORDER BY fv." . str_replace(', ', ', fv.', self::VSORTS[$vsort])
            . ' LIMIT ' . self::PER_PAGE . ' OFFSET ' . $pg->offset, $p);
        $counts = [];
        if ($rows) {
            [$ph, $vals] = $db->in(array_column($rows, 'id'));
            $counts = array_map('intval', $db->pairs("SELECT value_id, COUNT(*) FROM product_features WHERE feature_id = ? AND value_id IN ($ph) GROUP BY value_id",
                array_merge([$fid], $vals)));
        }
        // поиск похожих — REGEXP по всем значениям; у служебной «Даты съёмки» их 190 тыс. (≈1,5 с), там он не нужен
        $all = ($vq === '' && !$unused && !$nouk) ? $total : (int) $db->value('SELECT COUNT(*) FROM feature_values WHERE feature_id = ?', [$fid]);
        $dupsTooMany = $all > self::DUPS_MAX;
        $dups = Request::get('dups') === '1' && !$dupsTooMany ? $this->duplicates($fid) : null;
        return ['rows' => $rows, 'counts' => $counts, 'pg' => $pg, 'vq' => $vq, 'vsort' => $vsort, 'unused' => $unused, 'nouk' => $nouk, 'dups' => $dups,
            'vtotal' => $total, 'dupsTooMany' => $dupsTooMany, 'usedBy' => $this->usage($f)];
    }

    /** Где используется характеристика (для удаления): товаров, условий категорий, фильтров категорий */
    private function usage(array $f): array
    {
        $db = App::db();
        $fid = (int) $f['id'];
        $products = (int) $db->value('SELECT COUNT(*) FROM (SELECT 1 FROM product_features WHERE feature_id = ? LIMIT 1) x', [$fid]);
        $conds = $db->col("SELECT name FROM categories WHERE type = 1 AND conditions LIKE ?", ['%' . addcslashes((string) $f['code'], '%_\\') . '.value_id%']);
        $filters = 0;
        foreach ($db->col("SELECT filter FROM categories WHERE filter IS NOT NULL AND filter <> ''") as $flt) {
            if (in_array((string) $fid, array_map('trim', explode(',', (string) $flt)), true)) $filters++;
        }
        return ['products' => $products, 'conditions' => $conds, 'filters' => $filters];
    }

    /** Удаление характеристики — только без товаров, не «Бренд»/«Размер» и не из условий категорий */
    public function delete(string $id): Response
    {
        $db = App::db();
        $f = ctype_digit($id) ? $db->row('SELECT * FROM features WHERE id = ?', [(int) $id]) : null;
        if (!$f) { $this->flash('Характеристика не найдена.', true); return Response::redirect('/admin/features/'); }
        $fid = (int) $f['id'];
        $u = $this->usage($f);
        $err = in_array($f['code'], ['brand', 'size'], true) ? 'Характеристики «Бренд» и «Размер» удалить нельзя — на них держатся бренды и размерный ряд товаров.'
            : ($u['products'] ? 'У характеристики есть товары — сначала уберите её значения у товаров.'
            : ($u['conditions'] ? 'Характеристика используется в условии категории «' . $u['conditions'][0] . '».' : null));
        if ($err) { $this->flash($err, true); return Response::redirect('/admin/features/' . $fid . '/'); }
        $cats = [];
        foreach ($db->all("SELECT id, filter FROM categories WHERE filter IS NOT NULL AND filter <> ''") as $c) {
            $list = array_values(array_filter(array_map('trim', explode(',', (string) $c['filter'])), static fn($x) => $x !== '' && $x !== (string) $fid));
            if (implode(',', $list) !== trim((string) $c['filter'])) $cats[(int) $c['id']] = $list ? implode(',', $list) : null;
        }
        $db->transaction(static function ($db) use ($fid, $cats) {
            $db->delete('feature_values', 'feature_id = ?', [$fid]);
            $db->delete('category_facets', 'feature_id = ?', [$fid]);
            $db->delete('features', 'id = ?', [$fid]);
            if ($cats) {                                            // убрать из списков фильтров категорий — одним запросом
                $case = 'CASE id'; $params = [];
                foreach ($cats as $cid => $flt) { $case .= ' WHEN ? THEN ?'; array_push($params, $cid, $flt); }
                [$ph, $vals] = $db->in(array_keys($cats));
                $db->query("UPDATE categories SET filter = $case END WHERE id IN ($ph)", array_merge($params, $vals));
            }
        });
        AdminCatalog::forgetFeatureStats();
        Cache::flush();
        $this->log('feature_delete', 'feature', $fid, ['name' => $f['name'], 'code' => $f['code']]);
        $this->flash('Характеристика «' . $f['name'] . '» удалена.');
        return Response::redirect('/admin/features/');
    }

    /** Похожие значения: совпадают без учёта регистра, пробелов и знаков («32-37» и «32 - 37») */
    private function duplicates(int $fid): array
    {
        $db = App::db();
        try {
            $groups = $db->all("SELECT REGEXP_REPLACE(LOWER(value), '[^\\\\p{L}\\\\p{N}]+', '') k, GROUP_CONCAT(id ORDER BY id) ids
                FROM feature_values WHERE feature_id = ? GROUP BY k HAVING COUNT(*) > 1 ORDER BY COUNT(*) DESC LIMIT 100", [$fid]);
        } catch (\PDOException $e) {
            // MySQL 5.7: REGEXP_REPLACE нет (есть в MySQL 8 и MariaDB) — то же в PHP (значений не больше 30 000, см. $dupsTooMany)
            $by = [];
            foreach ($db->all('SELECT id, value FROM feature_values WHERE feature_id = ? ORDER BY id', [$fid]) as $r) {
                $by[(string) preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtolower((string) $r['value']))][] = (int) $r['id'];
            }
            $by = array_filter($by, static fn(array $l) => count($l) > 1);
            uasort($by, static fn(array $a, array $b) => count($b) <=> count($a));
            $groups = array_map(static fn(array $l) => ['ids' => implode(',', $l)], array_values(array_slice($by, 0, 100)));
        }
        if (!$groups) return [];
        $ids = [];
        foreach ($groups as $g) foreach (explode(',', (string) $g['ids']) as $i) $ids[] = (int) $i;
        [$ph, $vals] = $db->in($ids);
        $names = $db->pairs("SELECT id, value FROM feature_values WHERE id IN ($ph)", $vals);
        $counts = array_map('intval', $db->pairs("SELECT value_id, COUNT(*) FROM product_features WHERE feature_id = ? AND value_id IN ($ph) GROUP BY value_id", array_merge([$fid], $vals)));
        $out = [];
        foreach ($groups as $g) {
            $items = [];
            foreach (explode(',', (string) $g['ids']) as $i) $items[] = ['id' => (int) $i, 'value' => $names[$i] ?? '', 'n' => $counts[(int) $i] ?? 0];
            usort($items, static fn($a, $b) => $b['n'] <=> $a['n'] ?: $a['id'] <=> $b['id']);
            $out[] = $items;
        }
        return $out;
    }

    /** Поиск значений для автокомплита в карточке товара: {ok, values: [{id, value, code}]} */
    public function valuesJson(string $id): Response
    {
        $fid = ctype_digit($id) ? (int) $id : 0;
        $q = mb_substr(Request::get('q'), 0, 100);
        if (!$fid || $q === '') return Response::json(['ok' => true, 'values' => []]);
        $db = App::db();
        $like = addcslashes($q, '%_\\');
        $rows = $db->all('SELECT id, value, code FROM feature_values WHERE feature_id = ? AND value LIKE ? ORDER BY value LIMIT 30', [$fid, $like . '%']);
        if (count($rows) < 30) {
            $have = array_column($rows, 'id');
            foreach ($db->all('SELECT id, value, code FROM feature_values WHERE feature_id = ? AND value LIKE ? ORDER BY value LIMIT 30', [$fid, '%' . $like . '%']) as $r) {
                if (!in_array($r['id'], $have, false) && count($rows) < 30) $rows[] = $r;
            }
        }
        return Response::json(['ok' => true, 'values' => array_map(static fn($r) => ['id' => (int) $r['id'], 'value' => $r['value'],
            'code' => $r['code'] !== null ? (int) $r['code'] : null], $rows)]);
    }

    /** Действия со значениями: add | save (переименование) | merge | delete | delete_unused */
    public function values(string $id): Response
    {
        $db = App::db();
        $f = ctype_digit($id) ? $db->row('SELECT * FROM features WHERE id = ?', [(int) $id]) : null;
        if (!$f) { $this->flash('Характеристика не найдена.', true); return Response::redirect('/admin/features/'); }
        $fid = (int) $f['id'];
        $isBrand = $f['code'] === 'brand';
        $isSize = $f['code'] === 'size';
        $act = Request::post('act');
        $ids = AdminCatalog::ids(Request::postArray('ids'));
        parse_str(Request::post('qs'), $qs);
        $qs = array_intersect_key(is_array($qs) ? $qs : [], array_flip(['vq', 'vsort', 'unused', 'nouk', 'page', 'dups']));
        $qs = array_filter(array_map(static fn($v) => is_scalar($v) ? (string) $v : '', $qs), static fn($v) => $v !== '');
        $back = '/admin/features/' . $fid . '/' . ($qs ? '?' . http_build_query($qs) : '') . '#values';

        if ($act === 'add') {
            $lines = array_values(array_filter(array_map('trim', preg_split('/\r?\n/', mb_substr((string) ($_POST['new'] ?? ''), 0, 100000)) ?: []), 'strlen'));
            if (!$lines) { $this->flash('Введите значение.', true); return Response::redirect($back); }
            $before = (int) $db->value('SELECT COUNT(*) FROM feature_values WHERE feature_id = ?', [$fid]);
            foreach (array_slice($lines, 0, 1000) as $line) AdminCatalog::valueId($fid, $line);
            $added = (int) $db->value('SELECT COUNT(*) FROM feature_values WHERE feature_id = ?', [$fid]) - $before;
            $this->done($f, 'values_add', 'Добавлено значений: ' . $added . '.' . ($added < count($lines) ? ' Уже были: ' . (count($lines) - $added) . '.' : ''));
            return Response::redirect($back);
        }

        if ($act === 'save') {
            $names = Request::postArray('names');
            $namesUk = Request::postArray('names_uk');
            $cur = $curUk = [];
            $allIds = array_values(array_unique(array_map('intval', array_merge(array_keys($names), array_keys($namesUk)))));
            if ($allIds) {
                [$ph, $vals] = $db->in($allIds);
                foreach ($db->all("SELECT id, value, value_uk FROM feature_values WHERE feature_id = ? AND id IN ($ph)", array_merge([$fid], $vals)) as $r) {
                    $cur[(int) $r['id']] = $r['value'];
                    $curUk[(int) $r['id']] = (string) $r['value_uk'];
                }
            }
            $changed = 0; $errs = [];
            // украинские названия: пусто = на сайте русское
            foreach ($namesUk as $vid => $uk) {
                $vid = (int) $vid;
                $uk = is_scalar($uk) ? mb_substr(trim((string) preg_replace('/\s+/u', ' ', (string) $uk)), 0, 255) : '';
                if (!isset($curUk[$vid]) || $uk === $curUk[$vid]) continue;
                $db->update('feature_values', ['value_uk' => $uk === '' ? null : $uk], 'id = ? AND feature_id = ?', [$vid, $fid]);
                $changed++;
            }
            foreach ($names as $vid => $name) {
                $vid = (int) $vid;
                $name = is_scalar($name) ? mb_substr(trim(preg_replace('/\s+/u', ' ', (string) $name)), 0, 255) : '';
                if (!isset($cur[$vid]) || $name === '' || $name === $cur[$vid]) continue;
                $other = (int) $db->value('SELECT id FROM feature_values WHERE feature_id = ? AND value = ? AND id <> ?', [$fid, $name, $vid]);
                if ($other) { $errs[] = '«' . $name . '» уже есть (№' . $other . ') — объедините значения'; continue; }
                $db->update('feature_values', ['value' => $name], 'id = ? AND feature_id = ?', [$vid, $fid]);
                if ($isBrand) $db->update('brands', ['name' => $name], 'id = ?', [$vid]);
                if ($isSize) $db->query('UPDATE products p JOIN product_features pf ON pf.product_id = p.id AND pf.feature_id = ? AND pf.value_id = ? SET p.size = ?',
                    [$fid, $vid, mb_substr($name, 0, 64)]);
                $changed++;
            }
            if ($changed) Cache::flush();
            $this->done($f, 'values_rename', ($changed ? 'Сохранено изменений: ' . $changed . '.' : 'Изменений нет.') . ($errs ? ' Не сохранено: ' . implode('; ', $errs) . '.' : ''), (bool) $errs);
            return Response::redirect($back);
        }

        if ($act === 'merge') {
            $target = Request::postInt('target');
            if (!in_array($target, $ids, true)) $ids[] = $target;
            [$ph, $vals] = $db->in($ids);
            $valid = array_map('intval', $db->col("SELECT id FROM feature_values WHERE feature_id = ? AND id IN ($ph)", array_merge([$fid], $vals)));
            $src = array_values(array_diff($valid, [$target]));
            if (!in_array($target, $valid, true) || !$src) {
                $this->flash('Отметьте минимум два значения и выберите, какое оставить.', true);
                return Response::redirect($back);
            }
            $n = $this->merge($f, $target, $src);
            $this->done($f, 'values_merge', 'Объединено значений: ' . (count($src) + 1) . ', товаров затронуто: ' . $n . '.');
            return Response::redirect($back);
        }

        if ($act === 'delete' || $act === 'delete_unused') {
            // только значения без товаров (анти-джойн по индексу filter — см. valuesPage)
            $w = 'fv.feature_id = ? AND pfu.value_id IS NULL';
            $p = [$fid];
            if ($act === 'delete') {
                if (!$ids) { $this->flash('Отметьте значения.', true); return Response::redirect($back); }
                [$ph, $vals] = $db->in($ids);
                $w .= " AND fv.id IN ($ph)";
                $p = array_merge($p, $vals);
            }
            $del = array_map('intval', $db->col("SELECT fv.id FROM feature_values fv
                LEFT JOIN product_features pfu ON pfu.feature_id = fv.feature_id AND pfu.value_id = fv.id WHERE $w", $p));
            $brandLinks = [];
            if ($isBrand && $del) {                                 // бренды с товарами и из условий категорий не трогаем
                [$ph, $vals] = $db->in($del);
                $busy = array_map('intval', $db->col("SELECT DISTINCT brand_id FROM products WHERE brand_id IN ($ph)", $vals));
                foreach ($db->col("SELECT conditions FROM categories WHERE type = 1 AND conditions LIKE '%brand.value_id%'") as $cond) {
                    if (preg_match_all('/(?<![\w.])brand\.value_id\s*=\s*([\d,]+)/', (string) $cond, $mm)) {
                        foreach ($mm[1] as $list) $busy = array_merge($busy, array_map('intval', explode(',', $list)));
                    }
                }
                $del = array_values(array_diff($del, $busy));
                if ($del) {                                         // адреса удаляемых брендов — убрать редиректы на них
                    [$ph, $vals] = $db->in($del);
                    foreach ($db->col("SELECT url FROM brands WHERE id IN ($ph)", $vals) as $u) {
                        $link = Catalog::brandUrl(['url' => $u]);
                        array_push($brandLinks, $link, rawurldecode($link));
                    }
                }
            }
            foreach (array_chunk($del, 1000) as $part) {
                [$ph, $vals] = $db->in($part);
                $db->query("DELETE FROM feature_values WHERE feature_id = ? AND id IN ($ph)", array_merge([$fid], $vals));
                if ($isBrand) {
                    // логотипы удаляются после строк брендов — иначе Media::usage находит сам удаляемый бренд
                    $imgs = $db->col("SELECT image FROM brands WHERE id IN ($ph) AND image LIKE '/uploads/%'", $vals);
                    $db->query("DELETE FROM brands WHERE id IN ($ph)", $vals);
                    foreach ($imgs as $img) AdminCatalog::deleteUpload((string) $img);
                }
            }
            $skipped = $act === 'delete' ? count($ids) - count($del) : 0;
            AdminCatalog::dropRedirectsTo($brandLinks);
            if ($del) Cache::flush();
            $this->done($f, 'values_delete', 'Удалено значений: ' . count($del) . '.'
                . ($skipped ? ' Пропущено (есть товары' . ($isBrand ? ' или бренд в условии категории' : '') . '): ' . $skipped . '.' : ''));
            return Response::redirect($back);
        }

        $this->flash('Неизвестное действие.', true);
        return Response::redirect($back);
    }

    /** Объединение значений: товары получают $target, остальные значения удаляются. Возвращает число товаров. */
    private function merge(array $f, int $target, array $src): int
    {
        $db = App::db();
        $fid = (int) $f['id'];
        [$ph, $vals] = $db->in($src);
        $pids = array_map('intval', $db->col("SELECT DISTINCT product_id FROM product_features WHERE feature_id = ? AND value_id IN ($ph)", array_merge([$fid], $vals)));
        $targetName = (string) $db->value('SELECT value FROM feature_values WHERE id = ?', [$target]);
        $db->transaction(static function ($db) use ($fid, $target, $ph, $vals, $f, $pids, $targetName) {
            $db->query("INSERT IGNORE INTO product_features (product_id, feature_id, value_id)
                SELECT product_id, ?, ? FROM product_features WHERE feature_id = ? AND value_id IN ($ph)", array_merge([$fid, $target, $fid], $vals));
            $db->query("DELETE FROM product_features WHERE feature_id = ? AND value_id IN ($ph)", array_merge([$fid], $vals));
            $db->query("DELETE FROM feature_values WHERE feature_id = ? AND id IN ($ph)", array_merge([$fid], $vals));
            if ($f['code'] === 'brand') {
                $db->query("UPDATE products SET brand_id = ? WHERE brand_id IN ($ph)", array_merge([$target], $vals));
                $db->query("DELETE FROM brands WHERE id IN ($ph)", $vals);
                AdminCatalog::ensureBrand($target, $targetName);
            }
            if ($f['code'] === 'size' && $pids) {
                foreach (array_chunk($pids, 1000) as $part) {
                    [$pph, $pvals] = $db->in($part);
                    $db->query("UPDATE products SET size = ? WHERE id IN ($pph)", array_merge([mb_substr($targetName, 0, 64)], $pvals));
                }
            }
        });
        // условия динамических категорий вида {код}.value_id=… (brand.value_id=40, color.value_id=12,13): id удалённых
        // значений заменяются оставшимся; такие категории перестраиваются — их товары могли измениться
        $code = (string) $f['code'];
        $re = '/(?<![\w.])' . preg_quote($code, '/') . '\.value_id\s*=\s*([\d,]+)/';
        $dyn = [];
        foreach ($db->all('SELECT id, conditions FROM categories WHERE type = 1 AND conditions LIKE ?', ['%' . addcslashes($code, '%_\\') . '.value_id%']) as $c) {
            if (!preg_match($re, (string) $c['conditions'])) continue;
            $new = preg_replace_callback($re, static function ($m) use ($code, $src, $target) {
                $list = array_unique(array_map(static fn($x) => in_array((int) $x, $src, true) ? $target : (int) $x, explode(',', $m[1])));
                return $code . '.value_id=' . implode(',', $list);
            }, (string) $c['conditions']);
            if ($new !== $c['conditions']) $db->update('categories', ['conditions' => $new], 'id = ?', [(int) $c['id']]);
            $dyn[] = (int) $c['id'];
        }
        if ($dyn) AdminCatalog::reindexCategories($dyn, false);
        if ((int) $f['is_filter']) AdminCatalog::rebuildAllFacets();
        if ($code === 'brand' || $dyn) CatalogIndexer::updateCounters();
        Cache::flush();
        return count($pids);
    }

    private function done(array $f, string $action, string $msg, bool $error = false): void
    {
        AdminCatalog::forgetFeatureStats();
        Cache::forget('admin.feature_options');
        Cache::set('admin.fvver.' . (int) $f['id'], microtime(true), 86400);    // новые счётчики на странице значений
        $this->log('feature_' . $action, 'feature', (int) $f['id'], ['name' => $f['name']]);
        $this->flash($msg, $error);
    }
}
