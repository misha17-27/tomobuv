<?php
declare(strict_types=1);

namespace App\Services\Suppliers;

use App\Core\App;
use App\Services\AdminCatalog;
use App\Services\CatalogIndexer;
use App\Services\Import\Importer;
use App\Services\ProductName;
use App\Services\UkWords;

/**
 * Товары «Хит обуви» со страницы списка — порциями (одна транзакция под блокировкой записи индекса, как у Jong•Golf:
 * общая часть порций со страниц сайта — WebSync, записи — SupplierSync).
 *
 * Поиск товара сайта (первое подходящее, правила из разбора):
 *   R1 связь supplier_links (hitobuvi, код = product_id поставщика);
 *   R2 forsage-код из «машинного» адреса товара → товар supplier = 'forsage' с этим supplier_code («Хит обуви» сам
 *      импортирует товары из Forsage, как старый сайт);
 *   R3 точное название + тот же размерный ряд среди товаров без поставщика, forsage и hitobuvi (jonggolf, tomm — никогда);
 *   R4 тип + бренд + «хвост» названия (артикул и цвет, кириллица = латиница, без «-._/») + ряд — только единственный.
 * Один товар — один поставщик: товар, связанный с другим поставщиком (Tom.m, Jong•Golf), и товар сайта с брендом из
 * «пропускаемых» в R2–R4 не находятся; товар поставщика с таким брендом в названии или производителем — пропускается.
 * Найденный не наш товар (Forsage, заведённый вручную) по умолчанию не меняется и не дублируется — строка в отчёте;
 * с настройкой «брать под загрузчик» — становится товаром поставщика. Связанный — цена (×наценка), закупочная цена,
 * ящик, ряд, наличие, видимость (скрытый загрузчиком — снова показан; скрытый вручную — по настройке), категория.
 * Новый (id не ниже порога, категория сопоставлена, есть фото) — название поставщика (тип по-русски; «голое» — ProductName),
 * UA — словарём сайта (UkWords), бренд из названия (иначе фабрика без страны), характеристики по настройке,
 * фото full_ → GD 700×700; SEO — шаблоны сайта (свои meta не пишутся). Без фото не создаётся.
 */
final class HitObuviSync extends WebSync
{
    protected const SUPPLIER = HitObuvi::class;
    private const EX_COLS = 'id, url, name, name_uk, sku, supplier, supplier_code, category_id, brand_id, price, compare_price, price_manual, nophoto_hidden, purchase_price, box_qty, min_qty, size,
        stock, in_stock, status, image_id, image_ext';
    protected const UPDATABLE = ['sku', 'supplier', 'supplier_code', 'category_id', 'price', 'compare_price', 'purchase_price', 'box_qty', 'min_qty', 'size',
        'stock', 'in_stock', 'status', 'updated_at'];
    protected const MASS_SKIP = ['cat_skip', 'old', 'noadd', 'excluded', 'limit_new', 'brand', 'dup', 'taken', 'foreign'];

    public const SKIP = [
        'cat_skip' => 'категория поставщика не загружается (настройка)', 'category' => 'категория не сопоставлена', 'brand' => 'бренд в списке пропускаемых',
        'price' => 'нет цены', 'box' => 'не определить пар в ящике', 'size' => 'нет размерного ряда', 'old' => 'старый товар поставщика (id ниже порога) — не создаётся',
        'excluded' => 'удалён на сайте вручную — снова не добавляется', 'noadd' => 'новые товары не добавляются (настройка)', 'limit_new' => 'лимит новых товаров за запуск', 'nophoto' => 'нет фото у поставщика',
        'photo' => 'фото не скачалось', 'existing' => 'есть на сайте (Forsage / вручную) — не взят под загрузчик', 'ambiguous' => 'похож на несколько товаров сайта',
        'dup' => 'повтор товара в запуске', 'taken' => 'найденный товар сайта уже связан с другим товаром поставщика',
        'foreign' => 'найденный товар сайта ведёт другой поставщик (свой загрузчик бренда) или его бренд в пропускаемых',
    ];
    /** Бренды со своим загрузчиком (bkey): Jong•Golf, J&G, Tom.m */
    private const OWN_BRANDS = ['JONGGOLF', 'JG', 'TOMM', 'TOMMM'];
    public const VIA = ['link' => 'по связи', 'forsage' => 'по forsage-коду', 'name' => 'по названию и ряду', 'article' => 'по типу, бренду, артикулу и ряду'];

    private int $minId;
    private array $skipBrands = [];
    /** Названия брендов для разбора названий: [ключ bkey => название] (бренды сайта + производители поставщика) */
    private array $brandNames = [];
    private int $maxWords = 1;
    /** [bkey => [id бренда сайта, …]] (больше товаров — первым) */
    private array $siteBrands = [];
    private array $brandProducts = [];
    /** Разбор названий товаров сайта для R4: [id => split()] */
    private array $splitCache = [];
    /** excludedModel: forsage-коды и «название|ряд» удалённых вручную */
    private ?array $excludedModels = null;

    public function __construct(int $runId, bool $dry, array &$stats, HitObuviClient $client)
    {
        $this->runId = $runId;
        $this->dry = $dry;
        $this->stats = &$stats;
        $this->client = $client;
        foreach (HitObuvi::DEFAULTS as $k => $_) $this->cfg[$k] = HitObuvi::cfg($k);
        [$this->attr] = HitObuvi::attributes();
        $this->markup = HitObuvi::markup();
        $this->minId = (int) $this->cfg['min_id'];
        $this->brandFeature = AdminCatalog::featureId('brand');
        foreach (preg_split('~\r\n|\n~', $this->cfg['skip_brands']) ?: [] as $b) if (($k = self::bkey($b)) !== '') $this->skipBrands[$k] = trim($b);
    }

    /** Свои значения настроек для этого запуска (только известные ключи; --load — лимит новых) */
    public function override(array $cfg): self
    {
        foreach ($cfg as $k => $v) if (array_key_exists($k, $this->cfg)) $this->cfg[$k] = (string) $v;
        return $this;
    }

    // ================================================================== ключи

    /** Ключ бренда: верхний регистр, кириллические двойники → латиница, без пробелов и знаков («Ok Shoes» = «OK-SHOES») */
    public static function bkey(string $s): string
    {
        $s = mb_strtoupper(Supplier::name($s));
        $s = strtr($s, ['А' => 'A', 'В' => 'B', 'С' => 'C', 'Е' => 'E', 'Н' => 'H', 'К' => 'K', 'М' => 'M', 'О' => 'O', 'Р' => 'P', 'Т' => 'T', 'Х' => 'X', 'І' => 'I', 'У' => 'Y']);
        return (string) preg_replace('~[^A-Z0-9А-ЯЁЇІЄҐ]+~u', '', $s);
    }

    /** Ключ артикула / «хвоста» названия: как JongGolf::code и без «-._/» («H388-X» = «H388X», «А18» = «A18») */
    public static function akey(string $s): string
    {
        return (string) preg_replace('~[-_./]+~', '', JongGolf::code(mb_strtolower($s)));
    }

    /** Размерный ряд для сравнения: «36 – 41» → «36-41» */
    private static function sz(?string $s): string
    {
        return (string) preg_replace(['/\s+/u', '/[‐‑‒–—−]/u'], ['', '-'], trim((string) $s));
    }

    /** Бренды сайта и производители поставщика (manufacturers.json со страниц) для разбора названий */
    private function loadBrandNames(): void
    {
        $this->brandNames = []; $this->siteBrands = []; $this->maxWords = 1;
        $add = function (string $n): void {
            $n = Supplier::name($n);
            $k = self::bkey($n);
            if ($k === '' || mb_strlen($k) < 2) return;
            $this->brandNames[$k] ??= $n;
            $this->maxWords = max($this->maxWords, min(5, count(preg_split('/\s+/u', $n) ?: [])));
        };
        $counts = [];
        foreach (App::db()->all('SELECT id, name, product_count FROM brands') as $b) {
            $add((string) $b['name']);
            $counts[self::bkey((string) $b['name'])][(int) $b['id']] = (int) $b['product_count'];
        }
        foreach ($counts as $k => $list) { arsort($list); $this->siteBrands[$k] = array_keys($list); }
        $mf = json_decode((string) @file_get_contents(HitObuvi::dir() . '/manufacturers.json'), true);
        foreach (is_array($mf) ? $mf : [] as $n) $add((string) $n);
    }

    /**
     * Название «Тип Бренд Артикул [цвет]» → ['type' => первое слово (или «Сапоги из пены»), 'brand' => название бренда | '',
     * 'tail' => остальное]. Бренд — самое длинное совпадение начала остатка с брендами сайта и производителями поставщика.
     */
    public function split(string $name): array
    {
        $w = preg_split('/\s+/u', trim($name)) ?: [];
        $tn = count($w) >= 3 && HitObuviMap::typeOf(implode(' ', array_slice($w, 0, 3))) === 'сапоги из пены' ? 3 : 1;
        $type = implode(' ', array_slice($w, 0, $tn));
        $rest = array_slice($w, $tn);
        for ($k = min($this->maxWords, count($rest) - 1); $k >= 1; $k--) {
            $key = self::bkey(implode(' ', array_slice($rest, 0, $k)));
            if (isset($this->brandNames[$key])) return ['type' => $type, 'brand' => $this->brandNames[$key], 'tail' => implode(' ', array_slice($rest, $k))];
        }
        return ['type' => $type, 'brand' => '', 'tail' => implode(' ', $rest)];
    }

    // ================================================================== порции (цикл, фото, отчёт — WebSync)

    protected function prepare(): void
    {
        parent::prepare();
        $this->loadBrandNames();
    }

    /** Товары порции: разбор, поиск на сайте, повторы, решение. $meta — страница (root, cat) */
    protected function items(array $slice, array $meta): array
    {
        $root = (string) ($meta['root'] ?? '');
        $pageCat = !empty($meta['cat']) ? HitObuviMap::category((string) $meta['cat'], null) : null;
        $items = [];
        foreach ($slice as $p) $items[] = $this->expand($p, $root, $pageCat);
        $this->match($items);
        $this->dedupe($items);
        foreach ($items as &$it) $this->decide($it);
        unset($it);
        return $items;
    }

    // ================================================================== разбор товара поставщика

    /** Товар списка → строка для решения (цены, ящик, категория, бренд, название) */
    private function expand(array $p, string $root, ?array $pageCat): array
    {
        $d = $p['desc'] ?? [];
        $sid = (int) $p['id'];
        $size = (string) ($d['size'] ?? '');
        $box = null; $boxNote = '';
        $pb = (float) ($p['price_box'] ?? 0); $pu = (float) ($p['price_unit'] ?? 0);
        if ($pb > 0 && $pu > 0) $box = (int) round($pb / $pu);
        $descBox = isset($d['box_qty']) ? (int) $d['box_qty'] : null;
        if (($box === null || $box < 1) && $descBox) $box = $descBox;
        if ($box !== null && ($box < 1 || $box > 200)) $box = null;
        if ($box && $descBox && $descBox !== $box) $boxNote = 'в описании «Количество в упаковке: ' . $descBox . '», по ценам — ' . $box . ' (взято по ценам)';
        $pair = $box && $pb > 0 ? round($pb / $box, 2) : ($pu > 0 ? $pu : 0.0);
        $oldPair = $box && (float) ($p['old_price_box'] ?? 0) > $pb ? round((float) $p['old_price_box'] / $box, 2) : 0.0;
        $price = HitObuvi::price($pair, $this->markup);
        $compare = $oldPair > 0 ? HitObuvi::price($oldPair, $this->markup) : 0.0;
        if ($compare <= $price) $compare = 0.0;
        // название: тип по-русски («Кросівки Adidas B8588-3» → «Кроссовки Adidas B8588-3»)
        $name = Supplier::name((string) $p['name']);
        $w = preg_split('/\s+/u', $name, 2) ?: [$name];
        if (preg_match('/[іїєґ]/u', mb_strtolower($w[0])) && ($ru = UkWords::ru($w[0])) !== null) $name = trim($ru . ' ' . ($w[1] ?? ''));
        $sp = $this->split($name);
        $brand = $sp['brand'] !== '' ? $sp['brand'] : (string) ($d['maker_name'] ?? '');
        $map = HitObuviMap::resolve(['url' => (string) $p['url'], 'category_id' => $p['category_id'] ?? null, 'name' => $name, 'size' => $size], $root, $pageCat);
        $thumb = (string) ($p['thumb'] ?? '');
        $photo = $thumb !== '' ? (string) preg_replace('~/thumb_([^/]+)$~', '/full_$1', $thumb) : '';
        return [
            'sid' => $sid, 'code' => (string) $sid, 'name' => $name, 'name_src' => (string) $p['name'], 'url_src' => (string) $p['url'], 'cart' => $p['category_id'] ?? null,
            'size' => mb_substr($size, 0, 64), 'color' => (string) ($d['color'] ?? ''), 'material' => (string) ($d['material_top'] ?? ''), 'lining' => (string) ($d['material_lining'] ?? ''),
            'maker' => (string) ($d['maker_name'] ?? ''), 'country' => (string) ($d['country'] ?? ''), 'brand' => $brand, 'split' => $sp,
            'box' => $box, 'box_note' => $boxNote, 'price_box' => $pb, 'pair' => $pair, 'old_pair' => $oldPair, 'price' => $price, 'compare' => $compare,
            'cost' => $pair, 'old_cost' => null, 'returned' => '',
            'forsage' => HitObuviParser::forsageCode((string) $p['url'], $sid), 'map' => $map, 'cat_id' => $map['cat_id'], 'root' => $map['root'],
            'photo_url' => $photo !== '' && HitObuviClient::url($photo) !== null ? (string) HitObuviClient::url($photo) : '', 'label' => (string) ($p['label'] ?? ''),
            'ex' => null, 'via' => '', 'link' => null, 'action' => '', 'why' => '', 'msg' => '', 'level' => 'skip', 'upd' => [], 'changes' => [], 'warn' => [], 'id' => null,
        ];
    }

    // ================================================================== поиск существующих

    private function match(array &$items): void
    {
        if (!$items) return;
        $db = App::db();
        $codes = array_values(array_unique(array_column($items, 'code')));
        [$ph, $v] = $db->in($codes);
        $links = [];
        foreach ($db->all("SELECT l.code, l.hidden, l.missed, l.cost, p.id FROM supplier_links l JOIN products p ON p.id = l.product_id WHERE l.supplier = ? AND l.code IN ($ph)",
            array_merge([HitObuvi::CODE], $v)) as $r) {
            $links[(string) $r['code']] = ['id' => (int) $r['id'], 'hidden' => (int) $r['hidden'], 'missed' => (int) $r['missed'], 'cost' => $r['cost'] !== null ? (float) $r['cost'] : null];
        }
        $found = [];                                                        // [индекс => [via, [id …]]]
        foreach ($items as $i => $it) if (isset($links[$it['code']])) $found[$i] = ['link', [$links[$it['code']]['id']]];
        // R2: forsage-код из адреса
        $fs = [];
        foreach ($items as $i => $it) if (!isset($found[$i]) && $it['forsage'] !== null) $fs[$it['forsage']][] = $i;
        if ($fs) {
            [$fph, $fv] = $db->in(array_map('strval', array_keys($fs)));
            $byCode = [];
            foreach ($db->all("SELECT id, supplier_code FROM products WHERE supplier = 'forsage' AND supplier_code IN ($fph)", $fv) as $r) $byCode[(string) $r['supplier_code']][] = (int) $r['id'];
            foreach ($fs as $code => $idx) foreach ($idx as $i) if (isset($byCode[(string) $code])) $found[$i] = ['forsage', $byCode[(string) $code]];
        }
        // R3: точное название + ряд (без jonggolf и чужих поставщиков)
        $names = [];
        foreach ($items as $i => $it) if (!isset($found[$i])) { $names[$it['name']] = 1; $names[$it['name_src']] = 1; }
        if ($names) {
            [$nph, $nv] = $db->in(array_map('strval', array_keys($names)));
            $byName = [];
            foreach ($db->all("SELECT id, name, size, sku, supplier FROM products WHERE name IN ($nph)", $nv) as $r) {
                if (!in_array((string) $r['supplier'], ['', 'forsage', HitObuvi::CODE], true)) continue;
                $byName[Importer::nk((string) $r['name'])][] = $r;
            }
            foreach ($items as $i => $it) {
                if (isset($found[$i])) continue;
                $c = [];
                foreach (array_unique([Importer::nk($it['name']), Importer::nk($it['name_src'])]) as $k) {
                    foreach ($byName[$k] ?? [] as $r) if ($it['size'] === '' || self::sz($r['size']) === self::sz($it['size']) || self::sz($r['sku']) === self::sz($it['size'])) $c[(int) $r['id']] = 1;
                }
                if ($c) $found[$i] = ['name', array_keys($c)];
            }
        }
        // R4: тип + бренд + хвост (артикул, цвет) + ряд — только единственный кандидат
        foreach ($items as $i => $it) {
            if (isset($found[$i]) || $it['split']['brand'] === '' || !preg_match('/\d/', $it['split']['tail'])) continue;
            $bids = $this->siteBrands[self::bkey($it['split']['brand'])] ?? [];
            if (!$bids) continue;
            $type = HitObuviMap::typeOf($it['name']) ?? Importer::nk($it['split']['type']);
            $tail = self::akey($it['split']['tail']);
            $c = [];
            foreach ($this->productsOfBrands($bids) as $r) {
                if (!in_array((string) $r['supplier'], ['', 'forsage', HitObuvi::CODE], true)) continue;
                if ($it['size'] !== '' && self::sz($r['size']) !== self::sz($it['size'])) continue;
                $sp = $this->splitCache[(int) $r['id']] ??= $this->split((string) $r['name']);
                if ((HitObuviMap::typeOf((string) $r['name']) ?? Importer::nk($sp['type'])) !== $type) continue;
                if (self::akey($sp['tail']) !== $tail) continue;
                $c[(int) $r['id']] = 1;
            }
            if ($c) $found[$i] = ['article', array_keys($c)];
        }
        // R5: бренд со своим загрузчиком (Jong•Golf, Tom.m), которого владелец разрешил брать у «Хит обуви» (убрал из пропускаемых:
        // у бренда нет фото, 25.10.2026), — та же модель (код + ряд) уже на сайте от загрузчика бренда: не дублируется (foreign)
        foreach ($items as $i => $it) {
            if (isset($found[$i]) || !array_intersect([self::bkey($it['brand']), self::bkey($it['maker'])], self::OWN_BRANDS)) continue;
            $key = \App\Services\LegacyProducts::ownKey($it['name'], '', $it['size']);
            $own = $key !== null ? ($this->ownModels([JongGolf::CODE, Tomm::CODE])[$key] ?? []) : [];
            if ($own) $found[$i] = ['own', $own];
        }
        // строки найденных товаров + связи с другими товарами поставщика
        $ids = [];
        foreach ($found as [, $list]) foreach ($list as $pid) $ids[$pid] = 1;
        $rows = []; $taken = [];
        if ($ids) {
            [$iph, $iv] = $db->in(array_keys($ids));
            $rows = $db->keyed('SELECT ' . self::EX_COLS . " FROM products WHERE id IN ($iph)", $iv);
            $cats = [];
            foreach ($db->all("SELECT product_id, category_id FROM category_products WHERE product_id IN ($iph)", $iv) as $r) $cats[(int) $r['product_id']][] = (int) $r['category_id'];
            foreach ($rows as $pid => &$r) $r['cats'] = $cats[$pid] ?? [];
            unset($r);
            foreach ($db->all("SELECT product_id, code FROM supplier_links WHERE supplier = ? AND product_id IN ($iph)", array_merge([HitObuvi::CODE], $iv)) as $r) $taken[(int) $r['product_id']] = (string) $r['code'];
        }
        // один товар — один поставщик: связанные с другим поставщиком (Tom.m, Jong•Golf) и товары пропускаемых брендов сайта — не наши
        $foreign = $this->foreignLinked(array_keys($ids));
        $skipBids = [];
        foreach (array_keys($this->skipBrands) as $k) foreach ($this->siteBrands[$k] ?? [] as $bid) $skipBids[(int) $bid] = 1;
        $alien = static fn($pid) => isset($foreign[$pid]) || (isset($rows[$pid]) && isset($skipBids[(int) $rows[$pid]['brand_id']]));
        $used = [];
        foreach ($items as $i => &$it) {
            if (!isset($found[$i])) continue;
            [$via, $all] = $found[$i];
            $list = array_values(array_filter($all, static fn($pid) => isset($rows[$pid]) && ($via === 'link' || (!$alien($pid) && (!isset($taken[$pid]) || $taken[$pid] === $it['code'])))));
            if (!$list) {
                if ($via === 'link') continue;
                $al = array_values(array_filter($all, $alien));
                if ($al) { $it['via'] = 'foreign'; $it['msg'] = 'ID ' . implode(', ', $al) . ' — товар другого поставщика или пропускаемого бренда'; }
                else { $it['via'] = 'taken'; $it['msg'] = 'товар сайта уже связан с другим товаром поставщика'; }
                continue;
            }
            if (count($list) > 1) { $it['via'] = 'ambiguous'; $it['msg'] = 'ID ' . implode(', ', $list); continue; }
            $pid = $list[0];
            if (isset($used[$pid])) { $it['via'] = 'ambiguous'; $it['msg'] = 'ID ' . $pid . ' уже найден для товара поставщика ' . $used[$pid]; continue; }
            $used[$pid] = $it['code'];
            $it['ex'] = $rows[$pid];
            $it['via'] = $via;
            $it['link'] = $links[$it['code']] ?? null;
        }
        unset($it);
    }

    /**
     * Ту же модель владелец уже удалял (supplier_excluded), а поставщик выложил её заново под новым номером: тот же
     * forsage-код (ext_id связи) или то же название и размерный ряд удалённого товара — новый не создаётся (решение владельца
     * 26.10.2026: удалённое вручную не возвращается). Вернуть — «Обзор» поставщика → «Удалены вручную».
     */
    private function excludedModel(array $it): bool
    {
        if ($this->excludedModels === null) {
            $this->excludedModels = ['ext' => [], 'name' => []];
            try {
                foreach (App::db()->all('SELECT name, size, ext_id FROM supplier_excluded WHERE supplier = ?', [HitObuvi::CODE]) as $r) {
                    if ((string) $r['ext_id'] !== '') $this->excludedModels['ext'][(string) $r['ext_id']] = true;
                    if ((string) $r['name'] !== '' && (string) $r['size'] !== '') $this->excludedModels['name'][Importer::nk((string) $r['name']) . '|' . self::sz((string) $r['size'])] = true;
                }
            } catch (\PDOException) {
                // таблицы / колонки ещё нет (до bin/install.php)
            }
        }
        if ($it['forsage'] !== null && isset($this->excludedModels['ext'][(string) $it['forsage']])) return true;
        if ($it['size'] === '') return false;
        foreach (array_unique([Importer::nk($it['name']), Importer::nk($it['name_src'])]) as $k) {
            if (isset($this->excludedModels['name'][$k . '|' . self::sz($it['size'])])) return true;
        }
        return false;
    }

    /** Товары брендов (для R4): id, name, size, supplier — один раз за шаг */
    private function productsOfBrands(array $bids): array
    {
        $k = implode(',', $bids);
        if (!isset($this->brandProducts[$k])) {
            [$ph, $v] = App::db()->in($bids);
            $this->brandProducts[$k] = App::db()->all("SELECT id, name, size, sku, supplier FROM products WHERE brand_id IN ($ph)", $v);
        }
        return $this->brandProducts[$k];
    }

    // ================================================================== решение

    private function decide(array &$it): void
    {
        if ($it['action'] === 'skip') return;
        $c = $this->cfg;
        $ex = $it['ex'];
        if ($it['via'] === 'ambiguous') { $this->skip($it, 'ambiguous', 'похож на несколько товаров сайта (' . $it['msg'] . ') — не изменены, новый не создан'); return; }
        if ($it['via'] === 'taken') { $this->skip($it, 'taken', ''); return; }
        if ($it['via'] === 'foreign') { $this->skip($it, 'foreign', ''); return; }
        if ($it['map']['skip']) { $this->skip($it, 'cat_skip', ''); return; }
        // бренд со своим загрузчиком (Tom.m, Jong•Golf): по бренду из названия и по производителю («Пинетки Red Hat P023», производитель TOM.M)
        if (($it['brand'] !== '' && isset($this->skipBrands[self::bkey($it['brand'])])) || ($it['maker'] !== '' && isset($this->skipBrands[self::bkey($it['maker'])]))) { $this->skip($it, 'brand', ''); return; }
        if ($it['pair'] <= 0) { $this->skip($it, 'price', 'нет цены у товара поставщика ' . $it['code'], 'warn'); return; }
        if (!$it['box']) { $this->skip($it, 'box', 'не определить пар в ящике у товара поставщика ' . $it['code'], 'warn'); return; }
        if ($it['box_note'] !== '') $it['warn'][] = $it['box_note'];
        if ($ex) {
            $it['id'] = (int) $ex['id'];
            $ours = (string) $ex['supplier'] === HitObuvi::CODE || $it['via'] === 'link';
            if (!$ours && $c['adopt'] !== '1') {
                $it['action'] = 'existing';
                return;
            }
            // цена поставщика за пару в прошлом проходе (история цен «Обзора»): из связи (пишется каждым проходом, и без
            // «обновлять цены» — иначе журнал повторял бы одно и то же изменение), у взятого под загрузчик — закупочная в карточке
            $it['old_cost'] = $it['link']['cost'] ?? ((float) $ex['purchase_price'] > 0 ? (float) $ex['purchase_price'] : null);
            $missed = (int) ($it['link']['missed'] ?? 0);
            if ($c['update_existing'] !== '1' && $ours) {
                $it['action'] = 'same'; $it['changes'] = []; $it['link_set'] = 1;
                if ($missed > 0 && (int) $ex['status'] === 1) $it['returned'] = 'снова есть у поставщика (не было ' . $missed . ' полн. проходов)';
                return;
            }
            $hiddenByUs = (int) ($it['link']['hidden'] ?? 0) === 1;
            $status = (int) $ex['status'] === 1 || $hiddenByUs || $c['keep_manual_hidden'] !== '1' ? 1 : 0;
            if ($status === 0) $it['warn'][] = 'скрыт вручную — оставлен скрытым (настройка)';
            if ($hiddenByUs && (int) $ex['status'] === 0 && $status === 1) $it['returned'] = 'снова есть у поставщика — показан (был скрыт загрузчиком)';
            elseif ($missed > 0 && $status === 1) $it['returned'] = 'снова есть у поставщика (не было ' . $missed . ' полн. проходов)';
            $upd = ['price' => $it['price'], 'compare_price' => $it['compare'], 'purchase_price' => $it['pair'], 'in_stock' => 1, 'stock' => null, 'status' => $status,
                'supplier' => HitObuvi::CODE, 'supplier_code' => $it['code']];
            // ряд: артикул меняется, только если он и был рядом (у заведённых вручную артикул — свой код)
            if ($it['size'] !== '') {
                if (trim((string) $ex['sku']) === '' || self::sz($ex['sku']) === self::sz($ex['size'])) $upd['sku'] = $it['size'];
                $upd['size'] = $it['size'];
            }
            if ((int) $ex['box_qty'] !== (int) $it['box']) {
                $upd['box_qty'] = (int) $it['box'];
                if ((int) $ex['min_qty'] === (int) $ex['box_qty']) $upd['min_qty'] = (int) $it['box'];
            }
            if ($it['cat_id'] !== null && $c['update_category'] === '1' && ((int) $ex['category_id'] !== $it['cat_id'] || $ex['cats'] !== [$it['cat_id']])) {
                $upd['category_id'] = $it['cat_id'];
                $it['relink'] = true;
            }
            if ($it['cat_id'] === null) $it['warn'][] = 'категория «' . $it['map']['key'] . '» не сопоставлена — привязка не изменена';
            if (!self::photoOnDisk($ex) && $it['photo_url'] !== '') $it['photo'] = true;   // нет фото или его файла — скачать
            $this->diff($it, $ex, $upd);
            if (!empty($it['relink']) && !isset($it['upd']['category_id'])) $it['changes'][] = 'привязка к категории → ' . ($this->ci['path'][$it['cat_id']] ?? $it['cat_id']);
            if (!empty($it['photo'])) $it['changes'][] = 'фото';
            $it['link_set'] = 1;
            $it['action'] = $it['upd'] || !empty($it['relink']) || !empty($it['photo']) ? 'update' : 'same';
            return;
        }
        // нового товара на сайте нет
        if ($c['add_new'] !== '1') { $this->skip($it, 'noadd', ''); return; }
        if ($this->excluded((string) $it['code']) || $this->excludedModel($it)) { $this->skip($it, 'excluded', ''); return; }
        if ($this->minId > 0 && $it['sid'] < $this->minId) { $this->skip($it, 'old', ''); return; }
        if ($it['cat_id'] === null) { $this->skip($it, 'category', 'категория «' . $it['map']['key'] . '»' . ($it['map']['type'] ? ' (тип «' . $it['map']['type'] . '»)' : '') . ' не сопоставлена — «' . $it['name'] . '» не добавлен'); return; }
        if ($it['size'] === '') { $this->skip($it, 'size', 'нет размерного ряда — «' . $it['name'] . '» (' . $it['code'] . ') не добавлен', 'warn'); return; }
        if ($it['photo_url'] === '') { $this->skip($it, 'nophoto', 'нет фото — «' . $it['name'] . '» (' . $it['code'] . ') не добавлен', 'warn'); return; }
        if ((int) $c['max_new'] > 0 && (int) $this->stats['created'] + $this->plannedNew >= (int) $c['max_new']) { $this->skip($it, 'limit_new', ''); return; }
        $this->plannedNew++;
        $it['brand_ref'] = $this->brandFor($it['brand']);
        $name = mb_substr($it['name'], 0, ProductName::MAX);
        $nameUk = null;
        if (ProductName::isBare($name)) {
            [$name, $uk] = ProductName::build(['name' => $name, 'category_id' => $it['cat_id'], 'brand_name' => $it['brand']]);
            $nameUk = $uk !== $name ? $uk : null;
        } else {
            $uk = (string) UkWords::translate($name);
            $nameUk = $uk !== $name ? mb_substr($uk, 0, ProductName::MAX) : null;
        }
        $now = date('Y-m-d H:i:s');
        $it['row'] = [
            'url' => '', 'name' => $name, 'name_uk' => $nameUk, 'sku' => mb_substr($it['size'], 0, 255), 'category_id' => $it['cat_id'],
            'brand_id' => is_int($it['brand_ref']) ? $it['brand_ref'] : null, 'price' => $it['price'], 'compare_price' => $it['compare'], 'purchase_price' => $it['pair'],
            'box_qty' => (int) $it['box'], 'min_qty' => (int) $it['box'], 'size' => $it['size'], 'stock' => null, 'in_stock' => 1, 'status' => 1,
            'supplier' => HitObuvi::CODE, 'supplier_code' => $it['code'], 'created_at' => $now, 'updated_at' => $now,
        ];
        $it['url_base'] = substr(Supplier::translit($name), 0, 240) ?: 'product';
        $it['features'] = true;
        $it['photo'] = true;
        $it['link_set'] = 1;
        $it['action'] = 'create';
    }

    /** Бренд сайта по ключу (больше товаров — первый), иначе как у Jong•Golf (название / «new:») */
    private function brandFor(string $brand): int|string|null
    {
        if ($brand === '') return null;
        $ids = $this->siteBrands[self::bkey($brand)] ?? [];
        return $ids ? $ids[0] : $this->brandRef($brand);
    }

    // ================================================================== запись

    /** Водяной знак поставщика на фото → свой (настройка watermark; фото без него — как есть) */
    protected function retouch(\GdImage $canvas): void
    {
        if ($this->cfg['watermark'] === '1') HitObuviWatermark::apply($canvas);
    }

    protected function apply(array &$items, array $photos, array &$moved): void
    {
        $db = App::db();
        $now = date('Y-m-d H:i:s');
        foreach ($items as $i => &$it) {                                    // фото не скачалось: новый не создаётся, у найденного — предупреждение
            if (empty($it['photo']) || isset($photos[$i]['file'])) continue;
            $err = $photos[$i]['error'] ?? 'не скачано';
            if ($it['action'] === 'create') { $this->skip($it, 'photo', 'не получено фото ' . $it['photo_url'] . ' (' . $err . ') — «' . $it['row']['name'] . '» не добавлен', 'error'); continue; }
            $it['photo'] = false;
            $it['warn'][] = 'фото не загружено: ' . $err;
            $it['changes'] = array_values(array_diff($it['changes'], ['фото']));
            if ($it['action'] === 'update' && !$it['upd'] && empty($it['relink'])) $it['action'] = 'same';
        }
        unset($it);
        $upIds = [];
        foreach ($items as $it) if ($it['action'] === 'update') $upIds[] = (int) $it['id'];
        $snap = CatalogIndexer::snapshot($upIds);

        // 1. новые бренды
        $need = [];
        foreach ($items as $it) if ($it['action'] === 'create' && is_string($it['brand_ref'] ?? null)) $need[substr($it['brand_ref'], 4)] = 1;
        $newBrand = $this->createBrands($need);
        $brandId = static fn($ref) => is_string($ref) ? ($newBrand[substr($ref, 4)] ?? null) : $ref;

        // 2. новые товары
        $creates = array_keys(array_filter($items, static fn($it) => $it['action'] === 'create'));
        if ($creates) {
            $this->allocateUrls($items, $creates);
            $db->insertMany('products', array_map(static fn($i) => ['brand_id' => $brandId($items[$i]['brand_ref'] ?? null)] + $items[$i]['row'], $creates), false, 200);
            [$ph, $v] = $db->in(array_map(static fn($i) => $items[$i]['row']['url'], $creates));
            $ids = $db->pairs("SELECT url, id FROM products WHERE url IN ($ph)", $v);
            foreach ($creates as $i) $items[$i]['id'] = (int) ($ids[$items[$i]['row']['url']] ?? 0);
        }

        // 3. изменения найденных
        $upd = [];
        foreach ($items as $it) {
            if ($it['action'] !== 'update') continue;
            $u = $it['upd'];
            if ($u || !empty($it['relink']) || !empty($it['photo'])) $u['updated_at'] = $now;
            if ($u) $upd[(int) $it['id']] = $u;
        }
        $this->bulkUpdate($upd);

        // 4. категории
        $unlink = []; $link = [];
        foreach ($items as $it) {
            if (!$it['id']) continue;
            if ($it['action'] === 'create') $link[] = ['category_id' => (int) $it['cat_id'], 'product_id' => (int) $it['id'], 'sort' => 0];
            elseif ($it['action'] === 'update' && !empty($it['relink'])) { $unlink[] = (int) $it['id']; $link[] = ['category_id' => (int) $it['cat_id'], 'product_id' => (int) $it['id'], 'sort' => 0]; }
        }
        if ($unlink) { [$ph, $v] = $db->in($unlink); $db->query("DELETE FROM category_products WHERE product_id IN ($ph)", $v); }
        $db->insertMany('category_products', $link, true, 500);

        // 5. характеристики новых: существующие значения по нормализованному тексту, недостающие — создаются
        $want = []; $feat = [];
        foreach ($items as $i => $it) {
            if ($it['action'] !== 'create' || !$it['id']) continue;
            foreach ($this->attr as $field => $f) {
                $fid = (int) $f['id'];
                $val = match ($field) {
                    'size' => $it['size'], 'color' => $it['color'], 'material' => $it['material'], 'lining' => $it['lining'],
                    'gender' => HitObuviMap::gender($it['root']), 'country' => $it['country'], 'maker' => $it['maker'], 'brand' => '', default => '',
                };
                if ($field === 'brand') { $feat[$i][$fid] = ['brand' => true]; continue; }
                $val = Supplier::name($val);
                if ($val === '') continue;
                $feat[$i][$fid] = ['value' => $val];
                $want[$fid][$val] = 1;
            }
        }
        $vid = $this->valueIds($want);
        $ins = [];
        foreach ($feat as $i => $list) {
            foreach ($list as $fid => $f) {
                $val = !empty($f['brand']) ? $brandId($items[$i]['brand_ref'] ?? null) : ($vid[$fid][self::vkey($f['value'])] ?? null);
                if ($val) $ins[] = ['product_id' => (int) $items[$i]['id'], 'feature_id' => (int) $fid, 'value_id' => (int) $val];
            }
        }
        $db->insertMany('product_features', $ins, true, 1000);

        // 6. фото
        foreach ($items as $i => &$it) {
            if (!$it['id'] || empty($it['photo']) || !isset($photos[$i]['file']) || !in_array($it['action'], ['create', 'update'], true)) continue;
            if ($it['action'] === 'update') self::dropMissingImages((int) $it['id']);   // записи о фото без файлов — убрать
            $this->storePhoto($it, $photos[$i], [], $moved, $now);
        }
        unset($it);

        // 7. связи «товар поставщика → товар сайта»: встречен у поставщика — не пропал (missed = 0), скрытый загрузчиком — показан
        $links = [];
        foreach ($items as $it) {
            if (!$it['id'] || !in_array($it['action'], ['create', 'update', 'same'], true)) continue;
            $links[] = [HitObuvi::CODE, $it['code'], (int) $it['id'], $it['forsage'] !== null ? mb_substr($it['forsage'], 0, 64) : null,
                (int) ($it['upd']['status'] ?? $it['ex']['status'] ?? 1) === 1 ? 0 : (int) ($it['link']['hidden'] ?? 0), $now, $it['pair'] > 0 ? round($it['pair'], 2) : null, $now, $now];
        }
        // связанный, но пропущенный (не загружаемая категория, бренд, нет цены) — у поставщика он есть: только отметка
        $touch = [];
        foreach ($items as $it) if ($it['action'] === 'skip' && $it['via'] === 'link' && $it['ex']) $touch[] = $it['code'];
        foreach (array_chunk($links, 300) as $part) {
            $db->query('INSERT INTO supplier_links (supplier, code, product_id, ext_id, hidden, seen_at, cost, created_at, updated_at) VALUES '
                . implode(',', array_fill(0, count($part), '(?,?,?,?,?,?,?,?,?)'))
                . ' ON DUPLICATE KEY UPDATE product_id = VALUES(product_id), ext_id = VALUES(ext_id), hidden = VALUES(hidden), missed = 0, seen_at = VALUES(seen_at), cost = VALUES(cost), updated_at = VALUES(updated_at)', array_merge(...$part));
        }
        if ($touch) {
            [$ph, $v] = $db->in($touch);
            $db->query("UPDATE supplier_links SET missed = 0, seen_at = ? WHERE supplier = ? AND code IN ($ph)", array_merge([$now, HitObuvi::CODE], $v));
        }
        $this->seen($items);
        $this->journal($items, $now);                                       // «Обзор»: добавленные, цены, скрытые, вернувшиеся

        // 8. индекс каталога — точечно (новые — в снимке «пустыми»)
        $changed = [];
        foreach ($items as $it) if ($it['id'] && in_array($it['action'], ['create', 'update'], true)) $changed[] = (int) $it['id'];
        if ($changed) {
            $snap['ids'] = array_values(array_unique(array_merge($snap['ids'], $changed)));
            CatalogIndexer::products($changed, $snap, false);
        }
    }

    /** Подробности строки отчёта (таблица запуска): что будет у товара после записи */
    protected function data(array $it): array
    {
        $ex = $it['ex'];
        $cat = $it['action'] === 'create' || !empty($it['relink']) ? $it['cat_id'] : ($ex['category_id'] ?? $it['cat_id']);
        return [
            'a' => $it['action'], 'pid' => $it['id'] ?: ($ex['id'] ?? null), 'code' => $it['code'], 'via' => $it['via'], 'why' => $it['why'] ?: null,
            'name' => $it['row']['name'] ?? ($ex['name'] ?? $it['name']), 'name_uk' => $it['row']['name_uk'] ?? null, 'src' => $it['name_src'], 'supurl' => $it['url_src'],
            'cat_id' => $cat !== null ? (int) $cat : null, 'category' => $cat !== null ? ($this->ci['path'][(int) $cat] ?? '') : '', 'map_key' => $it['map']['key'], 'map_via' => $it['map']['via'],
            'price' => $it['price'], 'purchase' => $it['pair'], 'compare' => $it['compare'], 'price_box' => $it['price_box'], 'box' => $it['box'],
            'old_price' => $ex !== null ? (float) $ex['price'] : null, 'status' => (int) ($it['upd']['status'] ?? ($ex['status'] ?? 1)), 'in_stock' => 1,
            'brand' => $it['brand'], 'size' => $it['size'], 'color' => $it['color'], 'material' => trim($it['material'] . ($it['lining'] !== '' ? ' / ' . $it['lining'] : '')),
            'country' => $it['country'], 'changes' => $it['changes'], 'photo' => $it['photo_url'], 'forsage' => $it['forsage'],
        ];
    }
}
