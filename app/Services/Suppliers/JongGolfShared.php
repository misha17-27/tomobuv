<?php
declare(strict_types=1);

namespace App\Services\Suppliers;

use App\Core\App;
use App\Services\Import\Importer;
use App\Services\ProductName;

/**
 * Общее у двух источников Jong•Golf — API (JongGolfSync) и официальный сайт бренда (JongGolfSiteSync): один поставщик
 * 'jonggolf', одни связи supplier_links (код цвета → товар) и одни правила: поиск товаров сайта, название нового товара,
 * SEO по шаблонам настроек, сравнение характеристик, фильтр категории. Используется в наследниках SupplierSync
 * ($this->cfg — настройки «jonggolf.*», attrIds(), brandFeature, ci, runId).
 */
trait JongGolfShared
{
    /**
     * Найти товары сайта для цветов $items (поля code, aid, supname): 1) связь supplier_links (код цвета → товар);
     * 2) название старого загрузчика «{категория поставщика} {бренд} {код}» (так он искал: WHERE name = ?, без учёта регистра) —
     * у товаров без поставщика и jonggolf; 3) код в артикуле, название кончается кодом — товары без поставщика (заведены вручную:
     * «Весна-Осень Jong•Golf C31140-3», бывает с кириллической «С»). Товар, связанный с другим поставщиком, в 2–3 не находится
     * («один товар — один поставщик»). В $items: ex — строка товара ($cols + cats, images, fv — характеристики полей настройки,
     * если $features; desc — описание, если $texts), via — link | name | code | ambiguous, link — [id, hidden, cost, missed], msg.
     */
    protected function lookup(array &$items, string $cols, bool $features, bool $texts): void
    {
        if (!$items) return;
        $db = App::db();
        $codes = array_values(array_unique(array_column($items, 'code')));
        [$ph, $v] = $db->in($codes);
        // 1. связь «код цвета → товар»
        $links = [];
        foreach ($db->all("SELECT l.code, l.hidden, l.cost, l.missed, p.id FROM supplier_links l JOIN products p ON p.id = l.product_id WHERE l.supplier = ? AND l.code IN ($ph)",
            array_merge([JongGolf::CODE], $v)) as $r) $links[(string) $r['code']] = ['id' => (int) $r['id'], 'hidden' => (int) $r['hidden'], 'cost' => $r['cost'] !== null ? (float) $r['cost'] : null, 'missed' => (int) $r['missed']];
        $ids = array_column($links, 'id');
        // 2. название старого загрузчика
        $names = [];
        foreach ($items as $it) if (!isset($links[$it['code']]) && ($it['supname'] ?? '') !== '') $names[$it['supname']] = 1;
        $byName = [];
        if ($names) {
            [$nph, $nv] = $db->in(array_keys($names));
            foreach ($db->all('SELECT id, name, supplier FROM products WHERE name IN (' . $nph . ')', $nv) as $r) {
                if (!in_array((string) $r['supplier'], ['', JongGolf::CODE], true)) continue;     // чужой поставщик с тем же названием — не наш
                $k = Importer::nk((string) $r['name']);
                if (!isset($byName[$k]) || $r['supplier'] === JongGolf::CODE) $byName[$k] = (int) $r['id'];
            }
        }
        // 3. запасной поиск по коду среди товаров без поставщика
        $variants = [];
        foreach ($items as $it) {
            if (isset($links[$it['code']]) || isset($byName[Importer::nk((string) ($it['supname'] ?? ''))])) continue;
            foreach (self::variants($it['aid']) as $var) $variants[$var] = $it['code'];
        }
        $byCode = [];
        if ($variants) {
            foreach (array_chunk(array_keys($variants), 500) as $part) {
                [$cph, $cv] = $db->in($part);
                foreach ($db->all("SELECT id, name, sku FROM products WHERE sku IN ($cph) AND (supplier IS NULL OR supplier = '' OR supplier = ?)", array_merge($cv, [JongGolf::CODE])) as $r) {
                    $code = JongGolf::code((string) $r['sku']);
                    $parts = preg_split('/\s+/u', trim((string) $r['name'])) ?: [];
                    if (JongGolf::code((string) end($parts)) !== $code) continue;    // название должно кончаться этим кодом
                    $byCode[$code][(int) $r['id']] = 1;
                }
            }
        }
        $cand = array_values($byName);
        foreach ($byCode as $set) $cand = array_merge($cand, array_keys($set));
        $foreign = $this->foreignLinked($cand);
        foreach ($byName as $k => $pid) if (isset($foreign[$pid])) unset($byName[$k]);
        foreach ($byCode as $c => $set) {
            $byCode[$c] = array_diff_key($set, $foreign);
            if (!$byCode[$c]) unset($byCode[$c]);
        }
        $ids = array_merge($ids, array_values($byName));
        foreach ($byCode as $set) $ids = array_merge($ids, array_keys($set));
        $rows = [];
        if ($ids) {
            [$iph, $iv] = $db->in(array_values(array_unique($ids)));
            $rows = $db->keyed('SELECT ' . $cols . " FROM products WHERE id IN ($iph)", $iv);
            $cats = [];
            foreach ($db->all("SELECT product_id, category_id FROM category_products WHERE product_id IN ($iph)", $iv) as $r) $cats[(int) $r['product_id']][] = (int) $r['category_id'];
            $imgs = [];
            foreach ($db->all("SELECT id, product_id, ext FROM product_images WHERE product_id IN ($iph)", $iv) as $r) $imgs[(int) $r['product_id']][(int) $r['id']] = (string) $r['ext'];
            // текущие характеристики (поля настройки) и описание: «обновлять характеристики / описание» пишет только отличия —
            // иначе каждый проход переписывал бы их и сдвигал updated_at у всех товаров
            $fvals = []; $desc = [];
            $attr = array_keys($this->attrIds());
            if ($attr && $features) {
                [$fph, $fv] = $db->in($attr);
                foreach ($db->all("SELECT pf.product_id, pf.feature_id, pf.value_id, v.value FROM product_features pf LEFT JOIN feature_values v ON v.id = pf.value_id
                    WHERE pf.product_id IN ($iph) AND pf.feature_id IN ($fph)", array_merge($iv, $fv)) as $r) {
                    $fvals[(int) $r['product_id']][(int) $r['feature_id']][] = (int) $r['feature_id'] === $this->brandFeature ? 'id:' . (int) $r['value_id'] : self::vkey((string) $r['value']);
                }
            }
            if ($texts) $desc = $db->pairs("SELECT product_id, description FROM product_texts WHERE product_id IN ($iph)", $iv);
            foreach ($rows as $pid => &$r) { $r['cats'] = $cats[$pid] ?? []; $r['images'] = $imgs[$pid] ?? []; $r['fv'] = $fvals[$pid] ?? []; $r['desc'] = $desc[$pid] ?? null; }
            unset($r);
        }
        foreach ($items as &$it) {
            if (isset($links[$it['code']]) && isset($rows[$links[$it['code']]['id']])) {
                $it['ex'] = $rows[$links[$it['code']]['id']]; $it['via'] = 'link'; $it['link'] = $links[$it['code']];
            } elseif (($pid = $byName[Importer::nk((string) ($it['supname'] ?? ''))] ?? null) && isset($rows[$pid])) {
                $it['ex'] = $rows[$pid]; $it['via'] = 'name';
            } elseif (isset($byCode[$it['code']])) {
                $found = array_keys($byCode[$it['code']]);
                if (count($found) > 1) { $it['via'] = 'ambiguous'; $it['msg'] = 'ID ' . implode(', ', $found); }
                elseif (isset($rows[$found[0]])) { $it['ex'] = $rows[$found[0]]; $it['via'] = 'code'; }
            }
        }
        unset($it);
    }

    /** Написания кода с кириллическими двойниками латинских букв («C31095-4» → «С31095-4») — для поиска по артикулу */
    protected static function variants(string $aid): array
    {
        $lat = JongGolf::code($aid);
        $out = [$lat => 1, trim($aid) => 1];
        $pairs = ['A' => 'А', 'B' => 'В', 'C' => 'С', 'E' => 'Е', 'H' => 'Н', 'K' => 'К', 'M' => 'М', 'O' => 'О', 'P' => 'Р', 'T' => 'Т', 'X' => 'Х'];
        $pos = [];
        foreach (mb_str_split($lat) as $i => $ch) if (isset($pairs[$ch])) $pos[] = $i;
        $pos = array_slice($pos, 0, 3);                                     // до 8 вариантов
        for ($mask = 1; $mask < (1 << count($pos)); $mask++) {
            $chars = mb_str_split($lat);
            foreach ($pos as $b => $i) if ($mask & (1 << $b)) $chars[$i] = $pairs[$chars[$i]];
            $out[implode('', $chars)] = 1;
        }
        return array_keys($out);
    }

    /** Название нового товара (или при «менять название»): [RU, UA | null] — правило сайта ProductName или как старый загрузчик (supname) */
    protected function names(array $it, ?int $catId): array
    {
        if ($this->cfg['name_mode'] === 'supplier') return [mb_substr($it['supname'], 0, 255), null];
        // бренд — раскодированное название поставщика («J&G», а не «J&amp;amp;G» из старой базы); «Не указано» правило пропускает
        [$ru, $uk] = ProductName::build(['name' => $it['aid'], 'category_id' => $catId, 'brand_name' => $it['brand']]);
        return [$ru, $uk !== $ru ? $uk : null];
    }

    /** SEO по шаблонам настроек (как product_meta_* старого загрузчика; UA — с украинским названием); пусто — шаблоны сайта */
    protected function metas(string $name, ?string $nameUk): array
    {
        $c = $this->cfg;
        $uk = $nameUk ?? $name;
        return [
            'meta_title' => mb_substr(JongGolf::meta($c['meta_title'], $name), 0, 500) ?: null,
            'meta_description' => JongGolf::meta($c['meta_description'], $name) ?: null,
            'meta_keywords' => JongGolf::meta($c['meta_keywords'], $name, true) ?: null,
            'meta_title_uk' => mb_substr(JongGolf::meta($c['meta_title_uk'], $uk), 0, 500) ?: null,
            'meta_description_uk' => JongGolf::meta($c['meta_description_uk'], $uk) ?: null,
            'meta_keywords_uk' => JongGolf::meta($c['meta_keywords_uk'], $uk, true) ?: null,
        ];
    }

    /**
     * Характеристики товара сайта (поля настройки; $fids — только эти) уже такие, как у поставщика: значения — по нормализованному
     * тексту (vkey), бренд — по id (новый бренд — отличие). Запись удаляет эти поля и вставляет $it['feat'] — здесь сравниваются
     * ровно эти множества.
     */
    protected function sameFeatures(array $it, array $ex, ?array $fids = null): bool
    {
        $cur = $ex['fv'] ?? [];
        foreach ($fids ?? array_keys($this->attrIds()) as $fid) {
            $want = [];
            if (isset($it['feat'][$fid])) {
                if ($fid === $this->brandFeature) {
                    $ref = $it['brand_ref'] ?? null;
                    if (is_string($ref)) return false;                       // бренд будет создан
                    if (is_int($ref)) $want[] = 'id:' . $ref;
                } else {
                    $want[] = self::vkey((string) $it['feat'][$fid]['value']);
                }
            }
            $have = array_values(array_unique($cur[$fid] ?? []));
            sort($want);
            sort($have);
            if ($want !== $have) return false;
        }
        return true;
    }

    /** Фильтр категории (как старый загрузчик: пустой — «price» + характеристики настройки; «всегда» — дополнить) */
    protected function categoryFilters(array $cats): void
    {
        if (!$cats || $this->cfg['category_filter'] === 'never') return;
        $db = App::db();
        $fids = array_keys($this->attrIds());
        [$ph, $v] = $db->in($cats);
        foreach ($db->pairs("SELECT id, filter FROM categories WHERE id IN ($ph)", $v) as $cid => $filter) {
            $filter = trim((string) $filter);
            if ($filter !== '' && $this->cfg['category_filter'] !== 'always') continue;
            $list = $filter !== '' ? explode(',', $filter) : ['price'];
            $new = $list;
            foreach ($fids as $fid) if (!in_array((string) $fid, $new, true)) $new[] = (string) $fid;
            if ($new !== $list) {
                $db->update('categories', ['filter' => mb_substr(implode(',', $new), 0, 255)], 'id = ?', [(int) $cid]);
                JongGolf::log($this->runId, 'info', 'Фильтр категории «' . ($this->ci['path'][(int) $cid] ?? $cid) . '»: ' . implode(',', $new));
            }
        }
    }
}
