<?php
declare(strict_types=1);

namespace App\Services\Suppliers;

use App\Core\App;
use App\Services\AdminCatalog;
use App\Services\CatalogIndexer;
use App\Services\ProductName;

/**
 * Товары Tom.m с сайта бренда (tomm.ua) — порциями (общая часть — WebSync / SupplierSync).
 *
 * Сопоставление с нашими товарами — сразу для всего каталога бренда (plan, фаза match), правила по разбору (1 118 из 1 587
 * наших Tom.m находятся, ложных на проверке глазами — 0):
 *   R1 связь supplier_links (tomm, код = id товара у бренда, ext_id = его код) — бренд переименовывает и переиспользует
 *      товары: сменился код у того же id — товар не меняется, предупреждение «проверьте»;
 *   R2 точный код (кириллица = латиница, без «-», « LED» и хвоста Forsage « 1») + тот же ряд; код единственный и у нас
 *      единственный — найден и при другом ряде (наши опечатки 38-38 / 32-27 — ряд исправится, предупреждение);
 *   R3 код без 1–2-буквенной приставки Forsage (T, B, F, CT, AB: «C-T0196-F» = 0196F) + тот же ряд, только единственный;
 *   R4 не найден, но код (или он с приставкой) уже есть у товара сайта без бренда, у Tom.m с другим рядом или у другого бренда
 *      с тем же рядом — «похожий»: новый не создаётся (был бы дубль), предупреждение «проверьте».
 * Ищется только среди товаров брендов Tom.m сайта (настройка), кроме товаров других загрузчиков (Jong•Golf, Хит обуви) и
 * связанных с другим поставщиком (один товар — один поставщик); код у нас — артикул (если это не ряд), иначе после «TOM.M»
 * в названии, иначе последнее слово с цифрой (приклеенное слово-тип «Кроссовки65156K» отрезается).
 *
 * Найденный (по умолчанию берётся под загрузчик: supplier = 'tomm', название, адрес и SEO не меняются): цена пары
 * × наценка, старая цена, закупочная (цена бренда), ящик и мин. заказ, ряд, наличие, артикул — код бренда, если был пуст или рядом
 * (Forsage писал в артикул ряд «32-37»); нет в наличии у бренда — «нет в наличии»
 * (in_stock = 0, цена — прежняя: у бренда тогда 0), скрытый загрузчиком — снова показан, скрытый вручную — по настройке;
 * категория — по настройке; фото — если у товара его нет. Пропал с сайта бренда — скрыть только после 2 полных проходов.
 * Новый — только в наличии, с ценой, сопоставленным разделом и скачанным фото: название ProductName («Категория Tom.m Код»,
 * RU и UA), артикул — код, бренд — Tom.m, характеристики — по настройке (карточка товара: линейка, сезон, материалы; значения
 * по-русски — SupplierSync::ruValue), все фото → GD 700×700, SEO — шаблоны сайта.
 */
final class TommSync extends WebSync
{
    protected const SUPPLIER = Tomm::class;
    private const EX_COLS = 'id, url, name, name_uk, sku, supplier, supplier_code, category_id, brand_id, price, compare_price, purchase_price, box_qty, min_qty, size,
        stock, in_stock, status, image_id, image_ext';
    protected const UPDATABLE = ['sku', 'supplier', 'supplier_code', 'category_id', 'price', 'compare_price', 'purchase_price', 'box_qty', 'min_qty', 'size',
        'stock', 'in_stock', 'status', 'updated_at'];
    protected const MASS_SKIP = ['nostock', 'noadd', 'excluded', 'limit_new', 'dup', 'taken', 'foreign', 'cat_skip'];
    protected const CHANGE_NAMES = ['price' => 'цена', 'compare_price' => 'старая цена', 'purchase_price' => 'закупочная', 'status' => 'на сайте', 'in_stock' => 'наличие',
        'stock' => 'остаток', 'sku' => 'артикул', 'size' => 'размер', 'box_qty' => 'пар в ящике', 'min_qty' => 'мин. заказ', 'supplier' => 'поставщик',
        'supplier_code' => 'код поставщика', 'category_id' => 'категория'];

    public const SKIP = [
        'cat_skip' => 'раздел бренда не загружается (настройка)', 'category' => 'раздел бренда не сопоставлен с категорией сайта',
        'price' => 'в наличии у бренда, но без цены', 'box' => 'не определить пар в ящике', 'size' => 'нет размерного ряда',
        'nostock' => 'нет в наличии у бренда — новый товар не создаётся', 'excluded' => 'удалён на сайте вручную — снова не добавляется', 'excluded_recode' => 'новая модель под id удалённого вручную — не добавлена (проверьте)', 'noadd' => 'новые товары не добавляются (настройка)',
        'limit_new' => 'лимит новых товаров за запуск', 'nophoto' => 'нет фото у бренда', 'photo' => 'фото не скачалось',
        'existing' => 'есть на сайте (Forsage / вручную) — не взят под загрузчик', 'ambiguous' => 'похож на несколько товаров сайта',
        'recode' => 'у бренда сменился код товара — товар сайта не изменён (проверьте)', 'dup' => 'повтор товара в запуске',
        'taken' => 'найденный товар сайта уже связан с другим товаром бренда', 'foreign' => 'найденный товар сайта ведёт другой поставщик',
        'similar' => 'похожий товар уже есть на сайте (без бренда, другой бренд или другой ряд) — новый не создан, проверьте',
    ];
    public const VIA = ['link' => 'по связи', 'exact' => 'по коду и ряду', 'exact_size' => 'по коду (ряд у нас другой)', 'prefix' => 'по коду без приставки Forsage и ряду'];
    /** Итоги сопоставления (фаза match) */
    public const PLAN = ['link' => 'по связи', 'exact' => 'по коду', 'exact_size' => 'по коду (ряд у нас другой)', 'prefix' => 'без приставки Forsage', 'ambiguous' => 'неоднозначно', 'recode' => 'сменился код у бренда', 'similar' => 'похож на товар сайта (проверить)', 'new' => 'нет у нас'];
    private const CYR = ['А' => 'A', 'В' => 'B', 'С' => 'C', 'Е' => 'E', 'Н' => 'H', 'К' => 'K', 'М' => 'M', 'О' => 'O', 'Р' => 'P', 'Т' => 'T', 'Х' => 'X', 'І' => 'I', 'У' => 'Y',
        'а' => 'A', 'в' => 'B', 'с' => 'C', 'е' => 'E', 'н' => 'H', 'к' => 'K', 'м' => 'M', 'о' => 'O', 'р' => 'P', 'т' => 'T', 'х' => 'X', 'і' => 'I', 'у' => 'Y'];

    /** Характеристики из карточки — подписи для отчёта */
    private const FEAT_NAMES = ['line' => 'Линейка', 'season' => 'Сезон', 'material' => 'Верх', 'lining' => 'Подкладка', 'sole' => 'Подошва'];

    private array $brandIds;

    public function __construct(int $runId, bool $dry, array &$stats, TommClient $client)
    {
        $this->runId = $runId;
        $this->dry = $dry;
        $this->stats = &$stats;
        $this->client = $client;
        foreach (Tomm::DEFAULTS as $k => $_) $this->cfg[$k] = Tomm::cfg($k);
        [$this->attr] = Tomm::attributes();
        $this->markup = Tomm::markup();
        $this->brandFeature = AdminCatalog::featureId('brand');
        $this->brandIds = Tomm::brandIds();
    }

    // ================================================================== коды

    /** Код для сравнения: кириллица = латиница, верхний регистр, без « LED», хвоста Forsage « 1» и знаков («C-T75-53-C 1» → CT7553C) */
    public static function norm(string $s): string
    {
        $s = mb_strtoupper(strtr(trim($s), self::CYR));
        $s = (string) preg_replace(['~\s+LED$~u', '~\s+\d$~u'], '', $s);
        return (string) preg_replace('~[^A-Z0-9]~', '', $s);
    }

    /** Код без 1–2-буквенной приставки Forsage: T60079B → 60079B, AB01205A → 01205A; иначе null */
    public static function strip(string $c): ?string
    {
        return preg_match('~^[A-Z]{1,2}(\d{4,6}[A-Z]?)$~', $c, $m) ? $m[1] : null;
    }

    private static function sz(?string $s): string
    {
        return (string) preg_replace('~[^\d-]~', '', str_replace(['–', '—', '−'], '-', (string) $s));
    }

    /** Код нашего товара: артикул (если не ряд), иначе после «TOM.M» в названии, иначе последнее слово с цифрой */
    public static function ourCode(array $p): string
    {
        $sku = trim((string) ($p['sku'] ?? ''));
        if ($sku !== '' && !preg_match('~^\d{2}\s*-\s*\d{2}$~', $sku) && preg_match('~\d~', $sku)) return self::norm($sku);
        $n = (string) ($p['name'] ?? '');
        if (preg_match('~\bTOM+\.?M\b\s+(.+)$~iu', $n, $m)) return self::norm($m[1]);
        if (preg_match('~(\S*\d\S*)(\s+LED)?\s*$~u', $n, $m)) return self::norm((string) preg_replace('~^\p{Cyrillic}{3,}~u', '', $m[1]));
        return '';
    }

    /** Коды поставщиков со своим загрузчиком, кроме Tom.m: их товары Tom.m не берёт */
    private static function otherLoaders(): array
    {
        return array_values(array_diff(array_keys(Suppliers::all()), [Tomm::CODE]));
    }

    // ================================================================== сопоставление (фаза match)

    /**
     * Все товары бренда → с решением сопоставления 'm' => ['via' => link | exact | exact_size | prefix | ambiguous | recode | '',
     * 'pid' => ?int, 'warn' => string, 'msg' => string]. Правила по очереди для всего каталога (как в разборе): R1, затем R2
     * для всех, затем R3 для оставшихся — найденный однажды товар сайта второй раз не берётся.
     */
    public static function plan(array $tiles, int $runId): array
    {
        $db = App::db();
        $bids = Tomm::brandIds();
        $links = [];
        foreach ($db->all('SELECT l.code, l.product_id, l.ext_id FROM supplier_links l JOIN products p ON p.id = l.product_id WHERE l.supplier = ?', [Tomm::CODE]) as $r) {
            $links[(string) $r['code']] = ['pid' => (int) $r['product_id'], 'ext' => (string) $r['ext_id']];
        }
        $linked = [];
        foreach ($links as $l) $linked[$l['pid']] = 1;
        $O = []; $oBy = []; $oStrip = [];
        if ($bids) {
            [$ph, $v] = $db->in($bids);
            $foreign = array_flip(array_map('intval', $db->col("SELECT DISTINCT l.product_id FROM supplier_links l JOIN products p ON p.id = l.product_id WHERE l.supplier <> ? AND p.brand_id IN ($ph)", array_merge([Tomm::CODE], $v))));
            $other = self::otherLoaders();
            foreach ($db->all("SELECT id, name, sku, size, supplier FROM products WHERE brand_id IN ($ph) ORDER BY id", $v) as $o) {
                $pid = (int) $o['id'];
                if (isset($linked[$pid]) || isset($foreign[$pid]) || in_array((string) $o['supplier'], $other, true)) continue;
                $o['code'] = self::ourCode($o);
                if ($o['code'] === '') continue;
                $O[$pid] = $o;
                $oBy[$o['code']][] = $pid;
                if (($s = self::strip($o['code'])) !== null) $oStrip[$s][] = $pid;
            }
        }
        $tBy = [];
        foreach ($tiles as $i => &$t) { $t['ncode'] = self::norm((string) $t['code']); $tBy[$t['ncode']][] = $i; }
        unset($t);
        $res = []; $used = [];
        // R1 — связь
        foreach ($tiles as $i => $t) {
            $l = $links[(string) $t['id']] ?? null;
            if (!$l) continue;
            $used[$l['pid']] = 1;
            $res[$i] = $l['ext'] !== '' && $l['ext'] !== $t['ncode']
                ? ['via' => 'recode', 'pid' => $l['pid'], 'warn' => '', 'msg' => 'у бренда сменился код товара ' . $t['id'] . ': было ' . $l['ext'] . ', стало ' . $t['ncode'] . ' — товар сайта id=' . $l['pid'] . ' не изменён, проверьте']
                : ['via' => 'link', 'pid' => $l['pid'], 'warn' => '', 'msg' => ''];
        }
        // R2 — точный код (+ ряд, если кандидатов несколько)
        foreach ($tiles as $i => $t) {
            if (isset($res[$i])) continue;
            $c = $t['ncode'];
            $cand = array_values(array_filter($oBy[$c] ?? [], static fn($pid) => !isset($used[$pid])));
            if (!$cand) continue;
            if (count($tBy[$c]) > 1 || count($cand) > 1) {
                $same = array_values(array_filter($cand, static fn($pid) => self::sz($O[$pid]['size']) === self::sz($t['size'])));
                if (count($tBy[$c]) === 1 && count($same) === 1) { $res[$i] = ['via' => 'exact', 'pid' => $same[0], 'warn' => '', 'msg' => '']; $used[$same[0]] = 1; continue; }
                $res[$i] = ['via' => 'ambiguous', 'pid' => null, 'warn' => '', 'msg' => 'код ' . $c . ': товары сайта ID ' . implode(', ', $cand) . (count($tBy[$c]) > 1 ? ', у бренда этот код у ' . count($tBy[$c]) . ' товаров' : '')];
                continue;
            }
            $pid = $cand[0];
            $diff = self::sz($O[$pid]['size']) !== self::sz($t['size']);
            $res[$i] = ['via' => $diff ? 'exact_size' : 'exact', 'pid' => $pid, 'msg' => '',
                'warn' => $diff ? 'ряд у нас «' . $O[$pid]['size'] . '», у бренда «' . $t['size'] . '» — найден по коду' : ''];
            $used[$pid] = 1;
        }
        // R3 — без приставки Forsage, тот же ряд, единственный (неоднозначные по точному коду — тоже пробуются)
        foreach ($tiles as $i => $t) {
            if (isset($res[$i]) && $res[$i]['via'] !== 'ambiguous') continue;
            $s = self::strip($t['ncode']);
            $cands = [];
            foreach (array_merge($oStrip[$t['ncode']] ?? [], $s !== null ? ($oBy[$s] ?? []) : [], $s !== null ? ($oStrip[$s] ?? []) : []) as $pid) {
                if (isset($used[$pid]) || self::sz($O[$pid]['size']) !== self::sz($t['size'])) continue;
                $cands[$pid] = 1;
            }
            if (count($cands) === 1) {
                $pid = (int) array_key_first($cands);
                $res[$i] = ['via' => 'prefix', 'pid' => $pid, 'msg' => '',
                    'warn' => $s !== null ? 'у бренда код с приставкой: ' . $t['ncode'] . ' ↔ наш ' . $O[$pid]['code'] . ' — проверьте вручную' : ''];
                $used[$pid] = 1;
            } elseif (count($cands) > 1 && !isset($res[$i])) {
                $res[$i] = ['via' => 'ambiguous', 'pid' => null, 'warn' => '', 'msg' => 'код ' . $t['ncode'] . ' без приставки: товары сайта ID ' . implode(', ', array_keys($cands))];
            }
        }
        // R4 — «новый» у бренда, но этот код (или он же с приставкой Forsage) уже есть у товара сайта вне правил R2–R3: без бренда
        // («Зимняя обувь 65193H»), Tom.m с другим рядом (CT5752C 33-38 ↔ 5752C 32-37), другой бренд с тем же рядом (Ok Shoes 11324K) —
        // новый не создаётся (был бы дубль), в отчёте — «проверьте»
        $cores = [];
        foreach ($tiles as $i => $t) if (!isset($res[$i]) && preg_match('~\d{4,6}~', $t['ncode'], $m)) $cores[$m[0]] = 1;
        $keys = static function (string $c): array { $s = self::strip($c); return $s !== null ? [$c, $s] : [$c]; };
        $sim = [];
        if ($cores) {
            // один проход по товарам сайта (без REGEXP в SQL — он с сотнями вариантов медленный): цифры кода в названии или артикуле
            foreach ($db->query('SELECT id, name, sku, size, brand_id FROM products') as $o) {
                if (!preg_match_all('~\d{4,6}~', $o['name'] . ' ' . $o['sku'], $dm) || !array_intersect_key($cores, array_flip($dm[0]))) continue;
                $pid = (int) $o['id'];
                if (isset($used[$pid]) || isset($linked[$pid]) || ($c = self::ourCode($o)) === '') continue;   // уже связан с другим товаром бренда — не дубль
                foreach ($keys($c) as $k) $sim[$k][$pid] = $o;
            }
        }
        foreach ($tiles as $i => $t) {
            if (isset($res[$i])) continue;
            $hit = [];
            foreach ($keys($t['ncode']) as $k) foreach ($sim[$k] ?? [] as $pid => $o) {
                if (!(int) $o['brand_id'] || in_array((int) $o['brand_id'], $bids, true) || self::sz($o['size']) === self::sz($t['size'])) $hit[$pid] = '«' . $o['name'] . '» (' . ($o['size'] ?: 'ряд не указан') . ')';
            }
            if ($hit) $res[$i] = ['via' => 'similar', 'pid' => null, 'warn' => '', 'msg' => 'у бренда ' . $t['ncode'] . ' (' . $t['size'] . '), на сайте уже есть похожий: ' . implode('; ', array_map(static fn($pid, $n) => 'ID ' . $pid . ' ' . $n, array_keys($hit), $hit))];
        }
        foreach ($tiles as $i => &$t) $t['m'] = $res[$i] ?? ['via' => '', 'pid' => null, 'warn' => '', 'msg' => ''];
        unset($t);
        return $tiles;
    }

    // ================================================================== порции

    /** Товары порции: разбор, строки найденных товаров сайта (свежие), повторы, решение */
    protected function items(array $slice, array $meta): array
    {
        $items = [];
        foreach ($slice as $t) $items[] = $this->expand($t);
        $this->match($items);
        $this->dedupe($items);
        foreach ($items as &$it) $this->decide($it);
        unset($it);
        return $items;
    }

    /** Плитка бренда → строка для решения (цены с наценкой, ящик, категория, фото) */
    private function expand(array $t): array
    {
        $code = self::norm((string) $t['code']);
        $pair = (float) ($t['price'] ?? 0);
        $pb = (float) ($t['price_box'] ?? 0);
        $warn = [];
        // пар в ящике: «Пар в ящике» плитки, заказ (скрытое quantity) и цена ящика ÷ цена пары. Бывает, что «Пар в ящике» расходится
        // с двумя другими, а они между собой совпадают (65084H: 6 пар, заказ по 8, ящик 3520 = 440 × 8) — бренд продаёт ящик по заказу
        $pf = (int) ($t['pairs'] ?? 0);
        $qty = (int) ($t['qty'] ?? 0);
        $ok = static fn(int $n): bool => $n >= 1 && $n <= 200;
        $byPrice = $pair > 0 && $pb > 0 && abs($pb / $pair - round($pb / $pair)) < 0.01 ? (int) round($pb / $pair) : 0;
        $box = $ok($pf) ? $pf : ($ok($qty) ? $qty : ($ok($byPrice) ? $byPrice : null));
        $boxAlt = [];
        if ($box && $ok($qty) && $qty !== $box) {
            if ($qty === $byPrice) { $warn[] = 'у бренда «Пар в ящике» ' . $box . ', а заказ и цена ящика — по ' . $qty . ' — взято ' . $qty; $box = $qty; }
            elseif ($byPrice === 0) $boxAlt = [$box, $qty];                // цены нет (нет в наличии) — не понять, что верно: у найденного ящик не меняется
        }
        if ($box && $pair > 0 && $pb > 0 && abs($pb - $pair * $box) > 1) $warn[] = 'у бренда цена ящика ' . self::num($pb) . ' ≠ пара × ' . $box . ' (' . self::num($pair * $box) . ') — взята цена пары';
        if ($box && $qty > 0 && $qty !== $box) $warn[] = 'у бренда «Пар в ящике» ' . $box . ', а заказ по ' . $qty;
        $old = (float) ($t['old_price'] ?? 0) > $pair ? (float) $t['old_price'] : 0.0;
        $price = Tomm::price($pair, $this->markup);
        $compare = $old > 0 && $pair > 0 ? Tomm::price($old, $this->markup) : 0.0;
        if ($compare <= $price) $compare = 0.0;
        $map = TommMap::resolve((string) $t['root'], (string) $t['sub'], (string) $t['size']);
        $photos = [];
        foreach ((array) ($t['photos'] ?? []) as $u) if (($abs = TommClient::url((string) $u)) !== null) $photos[] = $abs;
        $m = (array) ($t['m'] ?? []);
        if (($m['warn'] ?? '') !== '') $warn[] = $m['warn'];
        return [
            'sid' => (int) $t['id'], 'code' => (string) (int) $t['id'], 'acode' => $code, 'name' => (string) $t['code'], 'name_src' => (string) $t['code'], 'url_src' => (string) $t['url'],
            'size' => mb_substr((string) $t['size'], 0, 64), 'box' => $box, 'box_alt' => $boxAlt, 'price_box' => $pb, 'pair' => $pair, 'old_pair' => $old, 'price' => $price, 'compare' => $compare,
            'cost' => $pair, 'old_cost' => null, 'returned' => '', 'stock' => (bool) $t['stock'], 'stock_out' => false,
            'map' => $map, 'cat_id' => $map['cat_id'], 'photo_urls' => array_values(array_unique($photos)), 'photo_url' => $photos[0] ?? '',
            'plan' => $m, 'ex' => null, 'via' => '', 'link' => null, 'action' => '', 'why' => '', 'msg' => '', 'level' => 'skip', 'upd' => [], 'changes' => [], 'warn' => $warn, 'id' => null,
            'card' => null, 'feat' => [],
        ];
    }

    /** Найденные товары сайта (по решению plan) — свежие строки; связанные с другим поставщиком за это время — не наши */
    private function match(array &$items): void
    {
        if (!$items) return;
        $db = App::db();
        [$ph, $v] = $db->in(array_values(array_unique(array_column($items, 'code'))));
        $links = [];
        foreach ($db->all("SELECT code, hidden, missed, cost, product_id FROM supplier_links WHERE supplier = ? AND code IN ($ph)", array_merge([Tomm::CODE], $v)) as $r) {
            $links[(string) $r['code']] = ['id' => (int) $r['product_id'], 'hidden' => (int) $r['hidden'], 'missed' => (int) $r['missed'], 'cost' => $r['cost'] !== null ? (float) $r['cost'] : null];
        }
        $pids = array_values(array_unique(array_filter(array_map(static fn($it) => (int) ($it['plan']['pid'] ?? 0), $items))));
        $rows = []; $taken = [];
        if ($pids) {
            [$iph, $iv] = $db->in($pids);
            $rows = $db->keyed('SELECT ' . self::EX_COLS . " FROM products WHERE id IN ($iph)", $iv);
            $cats = [];
            foreach ($db->all("SELECT product_id, category_id FROM category_products WHERE product_id IN ($iph)", $iv) as $r) $cats[(int) $r['product_id']][] = (int) $r['category_id'];
            foreach ($rows as $pid => &$r) $r['cats'] = $cats[$pid] ?? [];
            unset($r);
            foreach ($db->all("SELECT product_id, code FROM supplier_links WHERE supplier = ? AND product_id IN ($iph)", array_merge([Tomm::CODE], $iv)) as $r) $taken[(int) $r['product_id']] = (string) $r['code'];
        }
        $foreign = $this->foreignLinked($pids);
        $other = self::otherLoaders();
        foreach ($items as &$it) {
            $p = $it['plan'];
            $via = (string) ($p['via'] ?? '');
            $it['link'] = $links[$it['code']] ?? null;
            if ($via === 'ambiguous' || $via === 'recode' || $via === 'similar') { $it['via'] = $via; $it['msg'] = (string) $p['msg']; if ($via === 'recode') $it['ex'] = $rows[(int) $p['pid']] ?? null; continue; }
            if ($via === '' || !$p['pid']) continue;
            $pid = (int) $p['pid'];
            $ex = $rows[$pid] ?? null;
            if (!$ex) continue;                                             // удалили — будет новым
            if ($via !== 'link' && (isset($foreign[$pid]) || in_array((string) $ex['supplier'], $other, true))) { $it['via'] = 'foreign'; $it['msg'] = 'id=' . $pid . ' ведёт ' . ($foreign[$pid] ?? $ex['supplier']); continue; }
            if ($via !== 'link' && isset($taken[$pid]) && $taken[$pid] !== $it['code']) { $it['via'] = 'taken'; continue; }
            $it['ex'] = $ex;
            $it['via'] = $via;
        }
        unset($it);
    }

    // ================================================================== решение

    private function decide(array &$it): void
    {
        if ($it['action'] === 'skip') return;
        $c = $this->cfg;
        $ex = $it['ex'];
        if ($it['via'] === 'ambiguous') { $this->skip($it, 'ambiguous', 'похож на несколько товаров сайта (' . $it['msg'] . ') — не изменены, новый не создан', 'warn'); return; }
        if ($it['via'] === 'recode') { $this->skip($it, 'recode', mb_strtoupper(mb_substr($it['msg'], 0, 1)) . mb_substr($it['msg'], 1), 'warn'); $it['id'] = $ex ? (int) $ex['id'] : null; return; }
        if ($it['via'] === 'taken') { $this->skip($it, 'taken', ''); return; }
        if ($it['via'] === 'foreign') { $this->skip($it, 'foreign', ''); return; }
        if ($it['map']['skip']) { $this->skip($it, 'cat_skip', ''); return; }
        if ($it['stock'] && $it['pair'] <= 0) { $this->skip($it, 'price', 'у бренда «в наличии», но цена 0: ' . $it['name'] . ' (' . $it['code'] . ') — не изменён', 'warn'); return; }
        if (!$it['box']) { $this->skip($it, 'box', 'не определить пар в ящике у товара бренда ' . $it['name'] . ' (' . $it['code'] . ')', 'warn'); return; }
        if ($ex) {
            $it['id'] = (int) $ex['id'];
            $ours = (string) $ex['supplier'] === Tomm::CODE || $it['via'] === 'link';
            if (!$ours && $c['adopt'] !== '1') { $it['action'] = 'existing'; return; }
            // цена бренда за пару в прошлом проходе (история цен «Обзора») — из связи; у взятого под загрузчик её нет: закупочная в карточке —
            // цена Forsage, а не бренда («подорожал» по ней — не изменение у бренда), поэтому направление — по нашей цене
            $it['old_cost'] = $it['link']['cost'] ?? null;
            $missed = (int) ($it['link']['missed'] ?? 0);
            $it['stock_out'] = !$it['stock'];
            if ($c['update_existing'] !== '1' && $ours) {
                $it['action'] = 'same'; $it['link_set'] = 1;
                if ($missed > 0 && (int) $ex['status'] === 1) $it['returned'] = 'снова есть у бренда (не было ' . $missed . ' полн. проходов)';
                return;
            }
            $hiddenByUs = (int) ($it['link']['hidden'] ?? 0) === 1;
            $status = (int) $ex['status'] === 1 || $hiddenByUs || $c['keep_manual_hidden'] !== '1' ? 1 : 0;
            if ($status === 0) $it['warn'][] = 'скрыт вручную — оставлен скрытым (настройка)';
            if ($hiddenByUs && (int) $ex['status'] === 0 && $status === 1) $it['returned'] = 'снова есть у бренда — показан (был скрыт загрузчиком)';
            elseif ($missed > 0 && $status === 1) $it['returned'] = 'снова есть у бренда (не было ' . $missed . ' полн. проходов)';
            $upd = ['status' => $status, 'supplier' => Tomm::CODE, 'supplier_code' => $it['acode']];
            if ($it['stock']) {
                // остаток у бренда не виден: в наличии — без ограничения (stock NULL), как у других загрузчиков
                $upd += ['price' => $it['price'], 'compare_price' => $it['compare'], 'purchase_price' => $it['pair'], 'in_stock' => 1, 'stock' => null];
            } else {
                // нет в наличии у бренда (цена там обычно 0): на сайте — «нет в наличии» (остаток 0), наша цена и закупочная — прежние
                $upd['in_stock'] = 0;
                $upd['stock'] = 0;
                $it['out_note'] = 'нет в наличии у бренда';
                $it['price'] = (float) $ex['price'];
                $it['compare'] = (float) $ex['compare_price'];
            }
            // артикул: пустой или размерный ряд (так писал Forsage: «32-37») — код бренда, как у новых товаров (решение владельца
            // 23.10.2026: на сайте «Артикул: 3641H», а не размеры; названия не меняются); свой код — не меняется
            if (trim((string) $ex['sku']) === '' || preg_match('~^\s*\d{2}\s*[-–—]\s*\d{2}\s*$~u', (string) $ex['sku'])) $upd['sku'] = $it['acode'];
            if ($it['size'] !== '') $upd['size'] = $it['size'];
            if (in_array((int) $ex['box_qty'], $it['box_alt'], true)) $it['box'] = (int) $ex['box_qty'];   // у бренда неоднозначно, наш — один из вариантов: не меняется
            if ((int) $ex['box_qty'] !== (int) $it['box']) {
                $upd['box_qty'] = (int) $it['box'];
                if ((int) $ex['min_qty'] === (int) $ex['box_qty']) $upd['min_qty'] = (int) $it['box'];
            }
            if ($it['cat_id'] !== null && $c['update_category'] === '1' && ((int) $ex['category_id'] !== $it['cat_id'] || $ex['cats'] !== [$it['cat_id']])) {
                $upd['category_id'] = $it['cat_id'];
                $it['relink'] = true;
            }
            if ($it['cat_id'] === null && !$it['map']['skip']) $it['warn'][] = 'раздел «' . $it['map']['key'] . '» не сопоставлен — категория не проверена';
            // нет фото: нет записи о фото ИЛИ запись есть, а файла оригинала нет (не перенесён со старого сайта)
            if (!self::photoOnDisk($ex) && $it['photo_urls'] && $c['add_photos'] === '1') $it['photo'] = true;
            $this->diff($it, $ex, $upd);
            if (!empty($it['relink']) && !isset($it['upd']['category_id'])) $it['changes'][] = 'привязка к категории → ' . ($this->ci['path'][$it['cat_id']] ?? $it['cat_id']);
            if (!empty($it['photo'])) $it['changes'][] = 'фото';
            $it['link_set'] = 1;
            $it['action'] = $it['upd'] || !empty($it['relink']) || !empty($it['photo']) ? 'update' : 'same';
            return;
        }
        // нового товара на сайте нет
        if ($c['add_new'] !== '1') { $this->skip($it, 'noadd', ''); return; }
        if (($exExt = $this->excludedExt((string) $it['code'])) !== null) {
            // бренд переиспользует id: под id удалённого вручную — другая модель; не добавляется, но видно в журнале
            if ($exExt !== '' && $exExt !== $it['acode']) { $this->skip($it, 'excluded_recode', 'у бренда id ' . $it['code'] . ' теперь ' . $it['name'] . ' (удалённый вручную был ' . $exExt
                . ') — не добавлен; если это новая модель — «Вернуть» в «Обзоре»', 'warn'); return; }
            $this->skip($it, 'excluded', ''); return;
        }
        if (!$it['stock']) { $this->skip($it, 'nostock', ''); return; }
        if ($it['via'] === 'similar') { $this->skip($it, 'similar', mb_strtoupper(mb_substr($it['msg'], 0, 1)) . mb_substr($it['msg'], 1) . ' — новый не создан (был бы дубль), проверьте', 'warn'); return; }
        if ($it['cat_id'] === null) { $this->skip($it, 'category', 'раздел «' . $it['map']['key'] . '» не сопоставлен — ' . $it['name'] . ' (' . $it['code'] . ') не добавлен'); return; }
        if ($it['size'] === '') { $this->skip($it, 'size', 'нет размерного ряда — ' . $it['name'] . ' (' . $it['code'] . ') не добавлен', 'warn'); return; }
        if (!$it['photo_urls']) { $this->skip($it, 'nophoto', 'нет фото — ' . $it['name'] . ' (' . $it['code'] . ') не добавлен', 'warn'); return; }
        if (!$this->brandIds) { $this->skip($it, 'noadd', 'на сайте нет бренда Tom.m (настройка «Бренды Tom.m на сайте»)', 'error'); return; }
        if ((int) $c['max_new'] > 0 && (int) $this->stats['created'] + $this->plannedNew >= (int) $c['max_new']) { $this->skip($it, 'limit_new', ''); return; }
        $this->plannedNew++;
        $bid = (int) $this->brandIds[0];
        [$name, $uk] = ProductName::build(['name' => $it['acode'], 'category_id' => $it['cat_id'], 'brand_id' => $bid]);
        $name = mb_substr($name, 0, ProductName::MAX);
        $nameUk = $uk !== $name ? mb_substr($uk, 0, ProductName::MAX) : null;
        $now = date('Y-m-d H:i:s');
        $it['row'] = [
            'url' => '', 'name' => $name, 'name_uk' => $nameUk, 'sku' => $it['acode'], 'category_id' => $it['cat_id'], 'brand_id' => $bid,
            'price' => $it['price'], 'compare_price' => $it['compare'], 'purchase_price' => $it['pair'], 'box_qty' => (int) $it['box'], 'min_qty' => (int) $it['box'],
            'size' => $it['size'], 'stock' => null, 'in_stock' => 1, 'status' => 1, 'supplier' => Tomm::CODE, 'supplier_code' => $it['acode'], 'created_at' => $now, 'updated_at' => $now,
        ];
        $it['url_base'] = substr(Supplier::translit($name), 0, 240) ?: 'product';
        $it['features'] = true;
        $it['photo'] = true;
        $it['card'] = $c['card_features'] === '1';
        $it['link_set'] = 1;
        $it['action'] = 'create';
    }

    protected function priceText(array $it): string
    {
        if ($it['stock']) return parent::priceText($it);
        return ' — у бренда нет в наличии' . ((float) $it['pair'] > 0 ? ' (пара ' . self::num((float) $it['pair']) . ' грн)' : '') . ', цена на сайте остаётся ' . self::num((float) $it['price']) . ' грн, ящик ' . (int) $it['box'];
    }

    // ================================================================== карточки новых

    private function cardFile(array $it): string
    {
        return Tomm::dir($this->runId) . '/card-' . (int) $it['sid'] . '.json';
    }

    protected function netCost(array $it): int
    {
        return $it['action'] === 'create' && !empty($it['card']) && !is_file($this->cardFile($it)) ? 1 : 0;
    }

    /**
     * Карточки новых товаров (линейка, сезон, материалы, все фото) — по одной через бережный клиент, разбор — в папке запуска
     * (отложенная порция не качает снова). Карточка не получена (404, нет среди сохранённых) — товар создаётся без этих
     * характеристик, предупреждение. @return bool false — время шага кончилось
     */
    protected function enrich(array &$items, float $deadline): bool
    {
        $first = true;
        foreach ($items as &$it) {
            if ($it['action'] !== 'create' || empty($it['card'])) continue;
            $f = $this->cardFile($it);
            if (!is_file($f)) {
                if (!$first && microtime(true) > $deadline - 4.0) return false;
                $first = false;
                $r = $this->client->get($it['url_src'], false, true);   // карточка — без cookie списка
                $card = !empty($r['missing']) ? ['error' => 'нет среди сохранённых ответов'] : ($r['code'] !== 200 ? ['error' => 'ответ ' . $r['code']] : (TommParser::card($r['body'], (int) $it['sid']) ?? ['error' => 'не похоже на карточку товара']));
                file_put_contents($f, json_encode($card, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            }
            $card = json_decode((string) file_get_contents($f), true) ?: ['error' => 'не читается'];
            if (isset($card['error'])) { $it['warn'][] = 'карточка не получена (' . $card['error'] . ') — без сезона и материалов'; continue; }
            if (self::norm((string) $card['code']) !== $it['acode']) $it['warn'][] = 'в карточке код «' . $card['code'] . '», в списке «' . $it['name'] . '»';
            $it['feat'] = (array) $card['features'];
            foreach ((array) $card['photos'] as $u) if (($abs = TommClient::url((string) $u)) !== null && !in_array($abs, $it['photo_urls'], true)) $it['photo_urls'][] = $abs;
        }
        unset($it);
        return true;
    }

    // ================================================================== запись

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
        foreach ($items as $i => &$it) foreach ($photos[$i]['lost'] ?? [] as $l) $it['warn'][] = 'доп. фото не загружено: ' . $l;
        unset($it);
        $upIds = [];
        foreach ($items as $it) if ($it['action'] === 'update') $upIds[] = (int) $it['id'];
        $snap = CatalogIndexer::snapshot($upIds);

        // 1. новые товары
        $creates = array_keys(array_filter($items, static fn($it) => $it['action'] === 'create'));
        if ($creates) {
            $this->allocateUrls($items, $creates);
            foreach ($creates as $i) $items[$i]['row']['created_at'] = $items[$i]['row']['updated_at'] = $now;   // время записи (порция могла ждать фото)
            $db->insertMany('products', array_map(static fn($i) => $items[$i]['row'], $creates), false, 200);
            [$ph, $v] = $db->in(array_map(static fn($i) => $items[$i]['row']['url'], $creates));
            $ids = $db->pairs("SELECT url, id FROM products WHERE url IN ($ph)", $v);
            foreach ($creates as $i) $items[$i]['id'] = (int) ($ids[$items[$i]['row']['url']] ?? 0);
        }

        // 2. изменения найденных
        $upd = [];
        foreach ($items as $it) {
            if ($it['action'] !== 'update') continue;
            $u = $it['upd'];
            if ($u || !empty($it['relink']) || !empty($it['photo'])) $u['updated_at'] = $now;
            if ($u) $upd[(int) $it['id']] = $u;
        }
        $this->bulkUpdate($upd);

        // 3. категории
        $unlink = []; $link = [];
        foreach ($items as $it) {
            if (!$it['id']) continue;
            if ($it['action'] === 'create') $link[] = ['category_id' => (int) $it['cat_id'], 'product_id' => (int) $it['id'], 'sort' => 0];
            elseif ($it['action'] === 'update' && !empty($it['relink'])) { $unlink[] = (int) $it['id']; $link[] = ['category_id' => (int) $it['cat_id'], 'product_id' => (int) $it['id'], 'sort' => 0]; }
        }
        if ($unlink) { [$ph, $v] = $db->in($unlink); $db->query("DELETE FROM category_products WHERE product_id IN ($ph)", $v); }
        $db->insertMany('category_products', $link, true, 500);

        // 4. характеристики новых: существующие значения по нормализованному тексту (по-русски), недостающие — создаются
        $want = []; $feat = [];
        foreach ($items as $i => $it) {
            if ($it['action'] !== 'create' || !$it['id']) continue;
            foreach ($this->attr as $field => $f) {
                $fid = (int) $f['id'];
                if ($field === 'brand') { $feat[$i][$fid] = ['brand' => true]; continue; }
                $val = match ($field) {
                    'size' => $it['size'], 'pairs' => (string) (int) $it['box'], 'gender' => (string) $this->cfg['gender'],
                    default => (string) ($it['feat'][$field] ?? ''),
                };
                $val = self::ruValue($val);
                if (in_array($field, ['material', 'lining', 'sole'], true)) $val = $this->combo($fid, $val);
                if ($val === '') continue;
                $feat[$i][$fid] = ['value' => $val];
                $want[$fid][$val] = 1;
            }
        }
        $vid = $this->valueIds($want);
        $ins = [];
        foreach ($feat as $i => $list) {
            foreach ($list as $fid => $f) {
                $val = !empty($f['brand']) ? (int) $items[$i]['row']['brand_id'] : ($vid[$fid][self::vkey($f['value'])] ?? null);
                if ($val) $ins[] = ['product_id' => (int) $items[$i]['id'], 'feature_id' => (int) $fid, 'value_id' => (int) $val];
            }
        }
        $db->insertMany('product_features', $ins, true, 1000);

        // 5. фото: все (главное — первое)
        foreach ($items as $i => &$it) {
            if (!$it['id'] || empty($it['photo']) || !isset($photos[$i]['file']) || !in_array($it['action'], ['create', 'update'], true)) continue;
            if ($it['action'] === 'update') self::dropMissingImages((int) $it['id']);   // записи о фото без файлов — убрать
            $this->storePhotos($it, $photos[$i], $moved, $now);
        }
        unset($it);

        // 6. связи «товар бренда → товар сайта»: встречен у бренда — не пропал (missed = 0), скрытый загрузчиком — показан;
        //    цена бренда (cost) — последняя известная: у товара без наличия бренд показывает 0
        $links = [];
        foreach ($items as $it) {
            if (!$it['id'] || !in_array($it['action'], ['create', 'update', 'same'], true)) continue;
            $links[] = [Tomm::CODE, $it['code'], (int) $it['id'], mb_substr($it['acode'], 0, 64),
                (int) ($it['upd']['status'] ?? $it['ex']['status'] ?? 1) === 1 ? 0 : (int) ($it['link']['hidden'] ?? 0), $now, $it['pair'] > 0 ? round($it['pair'], 2) : null, $now, $now];
        }
        $touch = [];
        foreach ($items as $it) if ($it['action'] === 'skip' && $it['link'] && $it['ex']) $touch[] = $it['code'];   // связанный, но пропущенный — у бренда он есть
        foreach (array_chunk($links, 300) as $part) {
            $db->query('INSERT INTO supplier_links (supplier, code, product_id, ext_id, hidden, seen_at, cost, created_at, updated_at) VALUES '
                . implode(',', array_fill(0, count($part), '(?,?,?,?,?,?,?,?,?)'))
                . ' ON DUPLICATE KEY UPDATE product_id = VALUES(product_id), ext_id = VALUES(ext_id), hidden = VALUES(hidden), missed = 0, seen_at = VALUES(seen_at),
                    cost = COALESCE(VALUES(cost), cost), updated_at = VALUES(updated_at)', array_merge(...$part));
        }
        if ($touch) {
            [$ph, $v] = $db->in($touch);
            $db->query("UPDATE supplier_links SET missed = 0, seen_at = ? WHERE supplier = ? AND code IN ($ph)", array_merge([$now, Tomm::CODE], $v));
        }
        $this->seen($items);
        $this->journal($items, $now);                                       // «Обзор»: добавленные, цены, нет в наличии, скрытые, вернувшиеся

        // 7. индекс каталога — точечно (новые — в снимке «пустыми»)
        $changed = [];
        foreach ($items as $it) if ($it['id'] && in_array($it['action'], ['create', 'update'], true)) $changed[] = (int) $it['id'];
        if ($changed) {
            $snap['ids'] = array_values(array_unique(array_merge($snap['ids'], $changed)));
            CatalogIndexer::products($changed, $snap, false);
        }
    }

    /**
     * Составной материал бренда «текстиль+искусственная кожа» (после ruValue: «кожзам» → «искусственная кожа») — как такое
     * значение уже записано на сайте: через «-» в любом порядке («искусственная кожа-текстиль»), иначе через «-» по порядку бренда
     */
    private function combo(int $fid, string $v): string
    {
        $parts = array_values(array_filter(array_map('trim', preg_split('~\s*\+\s*~u', $v) ?: []), static fn($x) => $x !== ''));
        if (count($parts) < 2) return $v;
        foreach ([$parts, array_reverse($parts)] as $p) if ($this->hasValue($fid, implode('-', $p))) return implode('-', $p);
        return implode('-', $parts);
    }

    /** Подробности строки отчёта (таблица запуска): что будет у товара после записи */
    protected function data(array $it): array
    {
        $ex = $it['ex'];
        $cat = $it['action'] === 'create' || !empty($it['relink']) ? $it['cat_id'] : ($ex['category_id'] ?? $it['cat_id']);
        $feat = [];
        foreach (self::FEAT_NAMES as $k => $label) if (((string) ($it['feat'][$k] ?? '')) !== '') $feat[$label] = self::ruValue((string) $it['feat'][$k]);
        return [
            'a' => $it['action'], 'pid' => $it['id'] ?: ($ex['id'] ?? null), 'code' => $it['code'], 'acode' => $it['acode'], 'via' => $it['via'], 'why' => $it['why'] ?: null,
            'name' => $it['row']['name'] ?? ($ex['name'] ?? $it['name']), 'name_uk' => $it['row']['name_uk'] ?? null, 'src' => $it['name_src'], 'supurl' => $it['url_src'],
            'cat_id' => $cat !== null ? (int) $cat : null, 'category' => $cat !== null ? ($this->ci['path'][(int) $cat] ?? '') : '', 'map_key' => $it['map']['key'], 'map_via' => $it['map']['via'],
            'price' => $it['price'], 'purchase' => $it['pair'], 'compare' => $it['compare'], 'price_box' => $it['price_box'], 'box' => $it['box'],
            'old_price' => $ex !== null ? (float) $ex['price'] : null, 'status' => (int) ($it['upd']['status'] ?? ($ex['status'] ?? 1)),
            'in_stock' => (int) ($it['upd']['in_stock'] ?? ($ex !== null ? $ex['in_stock'] : 1)), 'stock' => $it['stock'],
            'size' => $it['size'], 'changes' => $it['changes'], 'photo' => $it['photo_url'], 'photos' => count($it['photo_urls']), 'feat' => $feat,
        ];
    }
}
