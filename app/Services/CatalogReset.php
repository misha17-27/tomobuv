<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\App;
use App\Core\Cache;
use App\Core\Image;
use App\Services\Suppliers\HitObuviSync;
use App\Services\Suppliers\JongGolf;
use App\Services\Suppliers\Tomm;
use App\Services\Suppliers\TommSync;

/**
 * Пересборка каталога (решение владельца, октябрь 2026): удалить все товары, кроме Tom.m и Jong•Golf, — каталог заново
 * собирают загрузчики («Хит обуви», Tom.m и Jong•Golf с сайтов брендов). CLI — bin/catalog-reset.php, порядок —
 * docs/INSTALL.md → «Пересборка каталога».
 *
 * Остаются (признаки, товар может иметь несколько):
 *   link     — связь supplier_links с загрузчиком tomm / jonggolf;
 *   supplier — products.supplier = tomm / jonggolf;
 *   brand    — бренд Tom.m / Jong•Golf во всех написаниях (ключ HitObuviSync::bkey: TOM.M = Tom.m = ТОМ.М = TOMM, Tomm.m;
 *              Jong•Golf = JONG GOLF = Jong Golf, J&G = J&amp;G = J&amp;amp;G) + бренды из настроек загрузчиков (Tomm::brandIds,
 *              JongGolf::ownBrandIds); из них без поставщика — «manual» (заведены вручную);
 *   none_jg  — бренд «Не указано», но supplier = jonggolf (старый загрузчик Jong•Golf);
 *   code     — без бренда («Не указано» / пусто) и без поставщика, код модели бренда: Tom.m «60123B», «601578B», Jong•Golf
 *              «B31130-4» (заведены вручную на старом сайте без бренда — рядом с такими же товарами бренда).
 * Удаляются все остальные. Перед удалением — карта старых адресов (LegacyProducts::capture) для 301.
 *
 * Удаление (run): под CatalogIndexer::exclusive (полные перестройки индекса ждут), пачками по 2–5 тыс. товаров; пачка —
 * одна транзакция под блокировкой записи индекса (CatalogIndexer::locked, взятой до транзакции): строки индекса,
 * характеристики, фото (строки), тексты, категории, журнал SEO… и сами товары. Витрина в это время работает (InnoDB:
 * чтение не ждёт), фильтры категорий до конца показывают прежние числа. Затем — хвосты в таблицах без индекса по товару
 * (корзины, подборки, импорт, отчёты загрузчиков), CatalogIndexer::rebuildAll (индекс, фильтры, счётчики категорий
 * и брендов), Cache::flush. Состояние — storage/catalog-reset.json: повторный запуск после обрыва доделывает
 * (подтверждение — то же число, что в первом запуске, или текущий остаток).
 * Файлы фото (public/wa-data) не трогаются — отдельно, после проверки нового каталога: purgeFiles().
 */
final class CatalogReset
{
    public const GROUPS = ['tomm' => 'Tom.m', 'jonggolf' => 'Jong•Golf'];
    /** Ключи брендов (HitObuviSync::bkey) */
    public const BRAND_KEYS = ['tomm' => ['TOMM', 'TOMMM'], 'jonggolf' => ['JONGGOLF', 'JG']];
    /** Код модели бренда у товара без бренда (после JongGolf::code: кириллица = латиница, верхний регистр) */
    public const CODE_RE = ['tomm' => '~^\d{5,6}[A-Z]$~', 'jonggolf' => '~^[A-D]\d{5}-\d{1,3}$~'];
    public const WHY = [
        'link' => 'связь с загрузчиком (supplier_links)', 'supplier' => 'products.supplier загрузчика', 'brand' => 'бренд',
        'manual' => 'бренд, без поставщика (заведён вручную)', 'none_jg' => 'бренд «Не указано», supplier = jonggolf',
        'code' => 'без бренда, код модели бренда (заведён вручную)',
    ];

    /**
     * Таблицы со ссылкой на товар: таблица => [колонка, действие, условие, где].
     * действие: delete — строки удаляются; null — ссылка обнуляется (запись остаётся); keep — не трогается.
     * где: batch — в транзакции пачки (есть индекс по товару); tail — после пачек, «без товара» (LEFT JOIN products):
     * индекса по товару нет (первичный ключ начинается с другой колонки), удаляются и давно висящие строки.
     */
    public const TABLES = [
        'catalog_index'     => ['product_id', 'delete', '', 'batch'],
        'catalog_index_sig' => ['product_id', 'delete', '', 'batch'],
        'category_products' => ['product_id', 'delete', '', 'batch'],
        'product_features'  => ['product_id', 'delete', '', 'batch'],
        'product_images'    => ['product_id', 'delete', '', 'batch'],
        'product_texts'     => ['product_id', 'delete', '', 'batch'],
        'product_reviews'   => ['product_id', 'delete', '', 'batch'],
        'product_related'   => ['product_id', 'delete', '', 'tail'],
        'product_set_items' => ['product_id', 'delete', '', 'tail'],
        'cart_items'        => ['product_id', 'delete', '', 'tail'],
        'supplier_links'    => ['product_id', 'delete', '', 'batch'],
        'supplier_changes'  => ['product_id', 'delete', '', 'batch'],
        'supplier_run_seen' => ['product_id', 'delete', '', 'tail'],
        'supplier_run_log'  => ['product_id', 'null', '', 'tail'],
        'import_seen'       => ['product_id', 'delete', '', 'tail'],
        'import_images'     => ['product_id', 'delete', '', 'tail'],
        'requests'          => ['product_id', 'null', '', 'tail'],
        'seo_fix_log'       => ['entity_id', 'delete', "entity = 'product'", 'batch'],
        // журнал действий сотрудников («Безопасность») не чистится; машинные записи истории товара (QUIET, без user_id) — да
        'admin_log'         => ['entity_id', 'keep', "entity = 'product'", 'batch'],
        // снимок названия, артикула и цены в заказе — остаётся (карточка заказа: «товар удалён с сайта», кабинет — без ссылки)
        'order_items'       => ['product_id', 'keep', '', ''],
    ];
    public const LABELS = [
        'catalog_index' => 'индекс каталога', 'catalog_index_sig' => 'подписи фильтров', 'category_products' => 'товар ↔ категории',
        'product_features' => 'характеристики', 'product_images' => 'фото (строки)', 'product_texts' => 'описания', 'product_reviews' => 'отзывы о товаре',
        'product_related' => 'связанные товары', 'product_set_items' => 'подборки (главная)', 'cart_items' => 'корзины',
        'supplier_links' => 'связи загрузчиков', 'supplier_changes' => 'журнал загрузчиков', 'supplier_run_seen' => 'встреченные в запусках',
        'supplier_run_log' => 'отчёты запусков (ссылка → NULL)', 'import_seen' => 'импорт: отметки строк', 'import_images' => 'импорт: очередь фото',
        'requests' => 'заявки (ссылка → NULL)', 'seo_fix_log' => 'журнал автоисправления SEO', 'admin_log' => 'журнал сотрудников',
        'order_items' => 'позиции заказов',
    ];
    public const BATCH_MIN = 500;
    public const BATCH_MAX = 5000;

    // ================================================================== состав

    /** Бренды групп: ['tomm' => [id => name], 'jonggolf' => [id => name]] и id «Не указано» */
    public static function brands(): array
    {
        $out = ['tomm' => [], 'jonggolf' => [], 'none' => []];
        $none = HitObuviSync::bkey('Не указано');
        $extra = ['tomm' => [], 'jonggolf' => []];
        try { $extra['tomm'] = Tomm::brandIds(); } catch (\Throwable) {}
        try { $extra['jonggolf'] = JongGolf::ownBrandIds(); } catch (\Throwable) {}
        foreach (App::db()->all('SELECT id, name FROM brands') as $b) {
            $k = HitObuviSync::bkey((string) $b['name']);
            $id = (int) $b['id'];
            foreach (self::BRAND_KEYS as $g => $keys) {
                if (in_array($k, $keys, true) || in_array($id, $extra[$g], true)) $out[$g][$id] = (string) $b['name'];
            }
            if ($k === $none) $out['none'][] = $id;
        }
        return $out;
    }

    /** Код модели товара без бренда: артикул (не ряд), иначе последнее слово названия с цифрой; JongGolf::code */
    public static function bareCode(string $name, string $sku): string
    {
        $sku = trim($sku);
        if ($sku !== '' && preg_match('/\d/', $sku) && !preg_match('/^\d{2}\s*-\s*\d{2}$/', $sku)) return JongGolf::code($sku);
        return preg_match('/(\S*\d\S*)\s*$/u', trim($name), $m) ? JongGolf::code($m[1]) : '';
    }

    /**
     * Что остаётся: [id => ['g' => tomm|jonggolf, 'why' => [признак => 1]]]. Товар с признаками обеих групп (не бывает) —
     * в группе первого признака.
     */
    public static function keep(?array $brands = null): array
    {
        $db = App::db();
        $brands ??= self::brands();
        $keep = [];
        $add = static function (int $id, string $g, string $why) use (&$keep): void {
            $keep[$id] ??= ['g' => $g, 'why' => []];
            $keep[$id]['why'][$why] = 1;
        };
        $sup = array_keys(self::GROUPS);
        [$sph, $sv] = $db->in($sup);
        foreach ($db->all("SELECT l.supplier, l.product_id FROM supplier_links l JOIN products p ON p.id = l.product_id WHERE l.supplier IN ($sph)", $sv) as $r) {
            $add((int) $r['product_id'], (string) $r['supplier'], 'link');
        }
        foreach ($db->all("SELECT id, supplier FROM products WHERE supplier IN ($sph)", $sv) as $r) $add((int) $r['id'], (string) $r['supplier'], 'supplier');
        foreach (self::GROUPS as $g => $_) {
            if (!$brands[$g]) continue;
            [$ph, $v] = $db->in(array_keys($brands[$g]));
            foreach ($db->all("SELECT id, supplier FROM products WHERE brand_id IN ($ph)", $v) as $r) {
                $add((int) $r['id'], $g, 'brand');
                if ((string) $r['supplier'] === '') $add((int) $r['id'], $g, 'manual');
            }
        }
        if ($brands['none']) {
            [$ph, $v] = $db->in($brands['none']);
            foreach ($db->all("SELECT id FROM products WHERE brand_id IN ($ph) AND supplier = 'jonggolf'", $v) as $r) $add((int) $r['id'], 'jonggolf', 'none_jg');
        }
        foreach (self::bareProducts($brands) as $r) {
            $code = self::bareCode((string) $r['name'], (string) $r['sku']);
            foreach (self::CODE_RE as $g => $re) if ($code !== '' && preg_match($re, $code)) { $add((int) $r['id'], $g, 'code'); break; }
        }
        ksort($keep);
        return $keep;
    }

    /** Товары без бренда (пусто, «Не указано», бренда нет в справочнике) и без поставщика */
    private static function bareProducts(array $brands): array
    {
        $db = App::db();
        [$ph, $v] = $db->in($brands['none'] ?: [0]);
        return $db->all("SELECT p.id, p.name, p.sku, p.size, p.brand_id FROM products p LEFT JOIN brands b ON b.id = p.brand_id
            WHERE (p.brand_id IS NULL OR b.id IS NULL OR p.brand_id IN ($ph)) AND (p.supplier IS NULL OR p.supplier = '')", $v);
    }

    /** id удаляемых: все товары, кроме $keep */
    public static function deleteIds(array $keep): array
    {
        $out = [];
        foreach (App::db()->col('SELECT id FROM products ORDER BY id') as $id) if (!isset($keep[(int) $id])) $out[] = (int) $id;
        return $out;
    }

    // ================================================================== предпросмотр

    /**
     * Полный разбор для --dry-run: состав, разбивка, примеры, строки по таблицам, карта адресов, файлы, что опустеет.
     */
    public static function plan(int $samples = 20): array
    {
        $db = App::db();
        $brands = self::brands();
        $keep = self::keep($brands);
        $del = self::deleteIds($keep);
        $keepIds = array_keys($keep);
        $total = (int) $db->value('SELECT COUNT(*) FROM products');
        $out = ['total' => $total, 'keep' => count($keep), 'delete' => count($del), 'brands' => $brands, 'groups' => [], 'samples_keep' => [], 'boundary' => [],
            'tables' => [], 'extra' => [], 'legacy' => [], 'files' => [], 'empty' => []];

        // разбивка по группам и признакам
        $rows = self::rows($keepIds, 'p.id, p.name, p.sku, p.size, p.supplier, p.status, p.brand_id, b.name brand');
        foreach ($keep as $id => $k) {
            $g = $k['g'];
            $out['groups'][$g]['total'] = ($out['groups'][$g]['total'] ?? 0) + 1;
            foreach (array_keys($k['why']) as $w) $out['groups'][$g]['why'][$w] = ($out['groups'][$g]['why'][$w] ?? 0) + 1;
            $r = $rows[$id] ?? null;
            if (!$r) continue;
            $bn = $r['brand'] !== null ? (string) $r['brand'] : '(без бренда)';
            $out['groups'][$g]['brand'][$bn] = ($out['groups'][$g]['brand'][$bn] ?? 0) + 1;
            $s = (string) ($r['supplier'] ?? '') ?: '(нет)';
            $out['groups'][$g]['supplier'][$s] = ($out['groups'][$g]['supplier'][$s] ?? 0) + 1;
            $out['groups'][$g]['active'] = ($out['groups'][$g]['active'] ?? 0) + ((int) $r['status'] === 1 ? 1 : 0);
        }
        // примеры оставляемых: все «по коду», затем поровну из каждого сочетания признаков
        $byCombo = [];
        foreach ($keep as $id => $k) $byCombo[$k['g'] . ':' . implode('+', array_keys($k['why']))][] = $id;
        ksort($byCombo);
        $pick = [];
        foreach ($byCombo as $combo => $ids) if (str_contains($combo, 'code')) foreach ($ids as $id) $pick[$id] = $combo;
        for ($i = 0; count($pick) < $samples && $i < 50; $i++) {
            foreach ($byCombo as $combo => $ids) {
                if (count($pick) >= $samples) break;
                $id = $ids[intdiv(count($ids) * $i, 7) % count($ids)] ?? null;
                if ($id !== null && !isset($pick[$id])) $pick[$id] = $combo;
            }
        }
        foreach ($pick as $id => $combo) if (isset($rows[$id])) $out['samples_keep'][] = $rows[$id] + ['why' => $combo];
        $out['boundary'] = self::boundary($keep, $brands, $samples);

        // строки по таблицам: у удаляемых = у всех существующих − у оставляемых; «без товара» — висят уже сейчас
        foreach (self::TABLES as $t => [$col, $act, $cond]) {
            $where = $cond !== '' ? ' AND t.' . $cond : '';
            $all = (int) $db->value("SELECT COUNT(*) FROM `$t` t WHERE t.`$col` IS NOT NULL$where");
            $exist = (int) $db->value("SELECT COUNT(*) FROM `$t` t JOIN products p ON p.id = t.`$col` WHERE 1 = 1$where");
            $kept = 0;
            foreach (array_chunk($keepIds, 1000) as $part) {
                [$ph, $v] = $db->in($part);
                $kept += (int) $db->value("SELECT COUNT(*) FROM `$t` t WHERE t.`$col` IN ($ph)$where", $v);
            }
            $out['tables'][$t] = ['action' => $act, 'where' => self::TABLES[$t][3], 'rows' => $all, 'deleted' => $exist - $kept, 'orphans' => $all - $exist, 'kept' => $kept];
        }
        if (isset($out['tables']['product_related'])) {
            // вторая колонка связи
            $out['tables']['product_related']['deleted'] += (int) $db->value('SELECT COUNT(*) FROM product_related r JOIN products p ON p.id = r.related_product_id');
        }
        $out['tables']['admin_log']['quiet'] = (int) $db->value("SELECT COUNT(*) FROM admin_log WHERE entity = 'product' AND user_id IS NULL AND action IN ('" . implode("','", ProductHistory::QUIET) . "')");
        $out['tables']['products'] = ['action' => 'delete', 'where' => 'batch', 'rows' => $total, 'deleted' => count($del), 'orphans' => 0, 'kept' => count($keep)];
        $out['tables']['category_facets'] = ['action' => 'rebuild', 'where' => 'rebuild', 'rows' => (int) $db->value('SELECT COUNT(*) FROM category_facets'), 'deleted' => 0, 'orphans' => 0, 'kept' => 0];

        // прочие ссылки: редиректы на удаляемые товары, промокоды, баннеры, подборки, тексты
        $out['extra'] = self::extraRefs($keep);
        // карта старых адресов (предпросмотр): сколько строк, куда ведут сейчас
        $out['legacy'] = self::legacyPreview($del);
        // файлы фото локально (оригиналы и миниатюры)
        $out['files'] = self::files($keep);
        // что опустеет: категории (индекс только с оставляемыми) и бренды
        $out['empty'] = self::emptyAfter($keepIds);
        return $out;
    }

    /** Строки товаров с брендом: [id => row] */
    private static function rows(array $ids, string $cols): array
    {
        $db = App::db();
        $out = [];
        foreach (array_chunk($ids, 1000) as $part) {
            [$ph, $v] = $db->in($part);
            foreach ($db->all("SELECT $cols FROM products p LEFT JOIN brands b ON b.id = p.brand_id WHERE p.id IN ($ph)", $v) as $r) $out[(int) $r['id']] = $r;
        }
        return $out;
    }

    /**
     * Граничные удаляемые: бренд похож (ключ содержит TOM, JG, JONG, GOLF), без бренда и без кода бренда, без поставщика с другим
     * брендом, код модели как у оставляемого Tom.m/Jong•Golf (у товара другого бренда — Tom.m R4 «похожий»), код вида
     * Jong•Golf «B21201-3» у других брендов. @return ['counts' => [вид => n], 'rows' => [...]]
     */
    private static function boundary(array $keep, array $brands, int $limit): array
    {
        $db = App::db();
        $kinds = [];
        $groupIds = array_keys($brands['tomm'] + $brands['jonggolf']);
        // похожие бренды
        $similar = [];
        foreach ($db->all('SELECT id, name FROM brands') as $b) {
            $k = HitObuviSync::bkey((string) $b['name']);
            if (in_array((int) $b['id'], $groupIds, true)) continue;
            if (preg_match('/TOM|JG|JONG|GOLF|ТОМ|ДЖ/u', $k)) $similar[(int) $b['id']] = (string) $b['name'];
        }
        if ($similar) {
            [$ph, $v] = $db->in(array_keys($similar));
            foreach ($db->col("SELECT id FROM products WHERE brand_id IN ($ph)", $v) as $id) if (!isset($keep[(int) $id])) $kinds['brand_similar'][] = (int) $id;
        }
        foreach (self::bareProducts($brands) as $r) if (!isset($keep[(int) $r['id']])) $kinds['bare'][] = (int) $r['id'];
        [$ph, $v] = $db->in($groupIds ?: [0]);
        foreach ($db->col("SELECT id FROM products WHERE (supplier IS NULL OR supplier = '') AND brand_id IS NOT NULL AND brand_id NOT IN ($ph)", $v) as $id) {
            if (!isset($keep[(int) $id])) $kinds['manual_other'][] = (int) $id;
        }
        // коды оставляемых → товары других брендов с тем же кодом в конце названия
        $codes = [];
        foreach (self::rows(array_keys($keep), 'p.id, p.name, p.sku') as $r) {
            $c = TommSync::ourCode($r);
            if ($c !== '' && strlen($c) >= 5) $codes[$c] = 1;
        }
        $jgLike = [];
        foreach ($db->query("SELECT id, name, sku FROM products WHERE name REGEXP '[0-9]'") as $r) {
            if (isset($keep[(int) $r['id']])) continue;
            $c = TommSync::ourCode($r);
            if ($c !== '' && isset($codes[$c])) $kinds['same_code'][] = (int) $r['id'];
            elseif (preg_match('~(?:^|\s)[A-D]\d{5}-\d{1,3}(?:\s|$)~u', mb_strtoupper((string) $r['name']))) $jgLike[] = (int) $r['id'];
        }
        if ($jgLike) $kinds['jg_like_code'] = $jgLike;
        $counts = array_map('count', $kinds);
        // примеры: из каждого вида поровну
        $pick = [];
        for ($i = 0; count($pick) < $limit && $i < $limit; $i++) {
            foreach ($kinds as $kind => $ids) {
                if (count($pick) >= $limit) break;
                $id = $ids[intdiv(count($ids) * $i, max(1, min(count($ids), 6))) % count($ids)] ?? null;
                if ($id !== null && !isset($pick[$id])) $pick[$id] = $kind;
            }
        }
        $rows = self::rows(array_keys($pick), 'p.id, p.name, p.sku, p.size, p.supplier, p.status, p.brand_id, b.name brand');
        $list = [];
        foreach ($pick as $id => $kind) if (isset($rows[$id])) $list[] = $rows[$id] + ['why' => $kind];
        return ['counts' => $counts, 'rows' => $list];
    }

    /** Прочие ссылки на удаляемые товары (вне таблиц с product_id) */
    private static function extraRefs(array $keep): array
    {
        $db = App::db();
        $out = ['redirects_to' => 0, 'redirects_alias' => 0, 'coupons' => 0, 'banners' => 0, 'sets_manual' => 0, 'content' => 0];
        foreach ($db->all("SELECT r.from_url, p.id FROM redirects r JOIN products p ON r.to_url IN (CONCAT('/product/', p.url, '/'), CONCAT('/product/', p.url))") as $r) {
            if (isset($keep[(int) $r['id']])) continue;
            $out['redirects_to']++;
            if (preg_match('~^/product/[^/?#]+/?$~', (string) $r['from_url'])) $out['redirects_alias']++;
        }
        foreach ($db->all("SELECT id, product_ids FROM coupons WHERE product_ids IS NOT NULL AND product_ids <> ''") as $c) {
            $ids = array_filter(array_map('intval', preg_split('/[^\d]+/', (string) $c['product_ids']) ?: []));
            foreach ($ids as $id) if (!isset($keep[$id])) { $out['coupons']++; break; }
        }
        $out['banners'] = (int) $db->value("SELECT COUNT(*) FROM banners WHERE link LIKE '%/product/%'");
        $out['sets_manual'] = (int) $db->value('SELECT COUNT(*) FROM product_sets WHERE type = 0');
        $out['content'] = (int) $db->value("SELECT (SELECT COUNT(*) FROM pages WHERE content LIKE '%/product/%' OR content_uk LIKE '%/product/%')
            + (SELECT COUNT(*) FROM blog_posts WHERE text LIKE '%/product/%' OR text_uk LIKE '%/product/%' OR text_before_cut LIKE '%/product/%')
            + (SELECT COUNT(*) FROM categories WHERE description LIKE '%/product/%' OR seo_description LIKE '%/product/%')");
        return $out;
    }

    /** Карта адресов для удаляемых: строк, forsage-кодов, без категории, куда ведут сейчас; уже в карте */
    private static function legacyPreview(array $del): array
    {
        $db = App::db();
        $out = ['rows' => count($del), 'forsage' => 0, 'no_category' => 0, 'targets' => [], 'top' => [], 'hidden_cats' => 0, 'in_map' => 0,
            'with_keys' => ['name' => 0, 'article' => 0, 'code' => 0, 'own' => 0], 'matched' => []];
        $groups = [];
        $noCat = [];
        foreach (array_chunk($del, 2000) as $part) {
            [$ph, $v] = $db->in($part);
            foreach ($db->all("SELECT id, category_id, supplier, supplier_code FROM products WHERE id IN ($ph)", $v) as $r) {
                if ((string) $r['supplier'] === 'forsage' && (string) $r['supplier_code'] !== '') $out['forsage']++;
                if ($r['category_id'] === null) { $noCat[] = (int) $r['id']; continue; }
                $groups[(int) $r['category_id']] = ($groups[(int) $r['category_id']] ?? 0) + 1;
            }
        }
        // без основной категории — первая из category_products (как capture), нет и её — главная
        $out['no_category'] = count($noCat);
        foreach (array_chunk($noCat, 1000) as $part) {
            [$ph, $v] = $db->in($part);
            $fromCp = array_map('intval', $db->pairs("SELECT product_id, MIN(category_id) FROM category_products WHERE product_id IN ($ph) GROUP BY product_id", $v));
            foreach ($part as $id) $groups[$fromCp[$id] ?? 0] = ($groups[$fromCp[$id] ?? 0] ?? 0) + 1;
        }
        $rows = [];
        $visible = Catalog::categories();
        foreach ($groups as $cid => $n) {
            $path = LegacyProducts::catPath($cid ?: null);
            $rows[] = [$cid ?: null, $path, $n];
            $to = LegacyProducts::categoryTarget($cid ?: null, $path);
            $out['top'][$to] = ($out['top'][$to] ?? 0) + $n;
            if ($cid && !isset($visible[$cid])) $out['hidden_cats'] += $n;
        }
        $out['targets'] = LegacyProducts::previewTargets($rows);
        arsort($out['top']);
        $out['targets_distinct'] = count($out['top']);
        $out['top'] = array_slice($out['top'], 0, 12, true);
        try {
            $out['in_map'] = (int) $db->value('SELECT COUNT(*) FROM legacy_products');
            $out['matched'] = array_map('intval', $db->pairs('SELECT matched_by, COUNT(*) FROM legacy_products WHERE new_product_id IS NOT NULL GROUP BY matched_by'));
            foreach (['name' => 'name_key', 'article' => 'art_key', 'code' => 'code_key', 'own' => 'own_key'] as $k => $c) $out['with_keys'][$k] = (int) $db->value("SELECT COUNT(*) FROM legacy_products WHERE `$c` IS NOT NULL");
        } catch (\PDOException) {
            $out['in_map'] = null;
        }
        return $out;
    }

    /** Папки фото товаров локально: [всего папок, у удаляемых] (файлы и объём считает purgeFiles(true) — на хостинге их сотни тысяч) */
    private static function files(array $keep): array
    {
        $root = PUBLIC_DIR . '/' . App::config('images.wa_data', 'wa-data');
        $out = ['dirs' => 0, 'deleted_dirs' => 0, 'root' => $root];
        foreach (['protected', 'public'] as $zone) {
            foreach (glob($root . '/' . $zone . '/shop/products/*/*/*', GLOB_ONLYDIR) ?: [] as $d) {
                $out['dirs']++;
                $id = (int) basename($d);
                if (isset($keep[$id])) continue;
                $out['deleted_dirs']++;
            }
        }
        return $out;
    }

    /** Категории (по индексу) и бренды, у которых не останется товаров */
    private static function emptyAfter(array $keepIds): array
    {
        $db = App::db();
        $catsNow = array_map('intval', $db->pairs('SELECT id, product_count FROM categories WHERE status = 1'));
        $catsKeep = [];
        $brandsKeep = [];
        foreach (array_chunk($keepIds, 1000) as $part) {
            [$ph, $v] = $db->in($part);
            foreach ($db->all("SELECT category_id, COUNT(*) n FROM catalog_index WHERE product_id IN ($ph) GROUP BY category_id", $v) as $r) $catsKeep[(int) $r['category_id']] = ($catsKeep[(int) $r['category_id']] ?? 0) + (int) $r['n'];
            foreach ($db->col("SELECT DISTINCT brand_id FROM products WHERE id IN ($ph) AND status = 1 AND brand_id IS NOT NULL", $v) as $b) $brandsKeep[(int) $b] = 1;
        }
        $empty = [];
        foreach ($catsNow as $id => $n) if ($n > 0 && empty($catsKeep[$id])) $empty[] = $id;
        $names = $empty ? $db->pairs('SELECT id, name FROM categories WHERE id IN (' . implode(',', array_map('intval', $empty)) . ')') : [];
        $brandsNow = (int) $db->value('SELECT COUNT(*) FROM brands WHERE product_count > 0 AND hidden = 0');
        return ['categories_now' => count(array_filter($catsNow)), 'categories_empty' => count($empty), 'categories_left' => count(array_filter($catsKeep)),
            'empty_names' => array_values($names), 'brands_now' => $brandsNow, 'brands_left' => count($brandsKeep)];
    }

    // ================================================================== удаление

    public static function stateFile(): string
    {
        return STORAGE . '/catalog-reset.json';
    }

    public static function state(): ?array
    {
        $s = is_file(self::stateFile()) ? json_decode((string) @file_get_contents(self::stateFile()), true) : null;
        return is_array($s) ? $s : null;
    }

    private static function saveState(array $s): void
    {
        @file_put_contents(self::stateFile(), json_encode($s, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
    }

    /**
     * Выполнить пересборку: $confirm — число удаляемых из --dry-run (или из первого прерванного запуска). $batch — товаров
     * в пачке (500–5000), $pause — секунд между пачками (сайт и загрузчики успевают писать). @return array итоги
     */
    public static function run(int $confirm, callable $say, int $batch = 2000, float $pause = 0.2): array
    {
        @set_time_limit(0);
        @ini_set('memory_limit', '1024M');
        LegacyProducts::ensureSchema();
        $db = App::db();
        $batch = max(self::BATCH_MIN, min(self::BATCH_MAX, $batch));
        $running = $db->col("SELECT CONCAT(supplier, ' №', id) FROM supplier_runs WHERE status = 'running'");
        if ($running) throw new \RuntimeException('Идёт запуск загрузчика (' . implode(', ', $running) . ') — дождитесь конца или остановите его («Поставщики → Остановить») и повторите.');
        $brands = self::brands();
        $keep = self::keep($brands);
        $del = self::deleteIds($keep);
        $state = self::state();
        $resume = $state && empty($state['finished_at']);
        if ($confirm !== count($del) && !($resume && (int) $state['planned'] === $confirm)) {
            throw new \RuntimeException('Подтверждение не совпадает: сейчас удаляется ' . count($del) . ' товаров (php bin/catalog-reset.php --dry-run)'
                . ($resume ? ', в прерванном запуске было ' . (int) $state['planned'] : '') . ', передано --confirm=' . $confirm . '.');
        }
        // новый запуск после законченного (повтор — доделать хвосты, пересчитать индекс): прежние итоги — в history, не теряются
        $history = $state ? array_slice(array_merge((array) ($state['history'] ?? []), [array_diff_key($state, ['history' => 1])]), -10) : [];
        $state = $resume ? $state : ['planned' => count($del), 'keep' => count($keep), 'started_at' => date('Y-m-d H:i:s'), 'deleted' => 0, 'batches' => 0, 'rows' => [], 'history' => $history];
        $state['resumed_at'] = $resume ? date('Y-m-d H:i:s') : null;
        unset($state['finished_at']);
        self::saveState($state);
        $say(($resume ? 'Продолжение прерванной пересборки' : 'Пересборка каталога') . ': остаётся ' . count($keep) . ', удаляется ' . count($del) . ', пачки по ' . $batch);
        $t = microtime(true);
        $res = ['captured' => 0, 'aliases' => 0, 'deleted' => 0, 'tail' => [], 'busy_dropped' => 0, 'redirects' => 0, 'coupons' => 0];
        $ok = CatalogIndexer::exclusive(static function () use (&$res, &$state, $del, $brands, $batch, $pause, $say, $db): void {
            // 1. карта старых адресов (до удаления; повторно — обновит строки тех же адресов)
            $c = LegacyProducts::capture($del, $say);
            $res['captured'] = $c['rows']; $res['aliases'] = $c['aliases'];
            $say('Карта старых адресов: записано ' . $c['rows'] . ', старых адресов из редиректов ' . $c['aliases']);
            // 2. редиректы на удаляемые товары (их адреса уже в карте) и промокоды с ними
            $urls = [];
            foreach (array_chunk($del, 2000) as $part) {
                [$ph, $v] = $db->in($part);
                foreach ($db->col("SELECT url FROM products WHERE id IN ($ph)", $v) as $u) $urls[] = '/product/' . $u . '/';
            }
            $res['redirects'] = AdminCatalog::dropRedirectsTo($urls);
            $res['coupons'] = self::dropFromCoupons(array_flip($del));
            // 3. товары пачками
            $groupBrands = array_keys($brands['tomm'] + $brands['jonggolf']);
            $n = 0;
            foreach (array_chunk($del, $batch) as $i => $part) {
                $rows = self::deleteBatch($part, $groupBrands);
                foreach ($rows as $tb => $cnt) $state['rows'][$tb] = ($state['rows'][$tb] ?? 0) + $cnt;
                $n += $rows['products'] ?? 0;
                $state['deleted'] = (int) ($state['deleted'] ?? 0) + ($rows['products'] ?? 0);
                $state['batches'] = (int) ($state['batches'] ?? 0) + 1;
                self::saveState($state);
                if ($i % 5 === 4 || ($i + 1) * $batch >= count($del)) $say('Удалено товаров: ' . $n . ' из ' . count($del));
                if ($pause > 0) usleep((int) ($pause * 1e6));
            }
            $res['deleted'] = $n;
            // 4. хвосты: таблицы без индекса по товару
            $res['tail'] = self::tail();
            foreach ($res['tail'] as $tb => $cnt) $state['rows'][$tb] = ($state['rows'][$tb] ?? 0) + $cnt;
            self::saveState($state);
            $say('Хвосты: ' . implode(', ', array_map(static fn($k, $v) => $k . ' ' . $v, array_keys($res['tail']), $res['tail'])));
            // 5. индекс, фильтры, счётчики категорий и брендов (полная перестройка — одной транзакцией)
            $say('Перестройка индекса каталога…');
            CatalogIndexer::rebuildAll();
        });
        if (!$ok) throw new \RuntimeException('Идёт другая перестройка индекса каталога (bin/reindex.php, импорт) — не дождались за 10 минут. Повторите позже: запуск доделает.');
        $res['busy_dropped'] = LegacyProducts::dropBusy();
        Cache::flush();
        $res['match'] = LegacyProducts::match(null, false, true);
        $state['finished_at'] = date('Y-m-d H:i:s');
        $state['seconds'] = (int) round(microtime(true) - $t);
        self::saveState($state);
        $res['state'] = $state;
        return $res;
    }

    /**
     * Удалить пачку товаров одной транзакцией под блокировкой записи индекса (взятой до транзакции). Перед удалением —
     * проверка заново (FOR UPDATE): товар, который за это время взял под себя загрузчик Tom.m/Jong•Golf или получил их бренд,
     * не удаляется. Взаимная блокировка (1213/1205) — повтор пачки. @return array [таблица => строк]
     */
    private static function deleteBatch(array $ids, array $groupBrands): array
    {
        $db = App::db();
        for ($try = 1; ; $try++) {
            $rows = [];
            try {
                $got = CatalogIndexer::locked(static function () use ($db, $ids, $groupBrands, &$rows): void {
                    $db->transaction(static function () use ($db, $ids, $groupBrands, &$rows): void {
                        [$ph, $v] = $db->in($ids);
                        [$gph, $gv] = $db->in($groupBrands ?: [0]);
                        $sup = array_keys(self::GROUPS);
                        $live = array_map('intval', $db->col("SELECT p.id FROM products p WHERE p.id IN ($ph)
                            AND (p.supplier IS NULL OR p.supplier NOT IN (?, ?)) AND (p.brand_id IS NULL OR p.brand_id NOT IN ($gph))
                            AND NOT EXISTS (SELECT 1 FROM supplier_links l WHERE l.product_id = p.id AND l.supplier IN (?, ?)) FOR UPDATE",
                            array_merge($v, $sup, $gv, $sup)));
                        if (!$live) return;
                        [$ph, $v] = $db->in($live);
                        foreach (self::TABLES as $t => [$col, $act, $cond, $where]) {
                            if ($where !== 'batch' || $act !== 'delete') continue;
                            $rows[$t] = $db->query("DELETE FROM `$t` WHERE `$col` IN ($ph)" . ($cond !== '' ? ' AND ' . $cond : ''), $v)->rowCount();
                        }
                        // машинные записи истории товара (импорт, массовые действия, автоназвание — без сотрудника)
                        [$qph, $qv] = $db->in(ProductHistory::QUIET);
                        $rows['admin_log'] = $db->query("DELETE FROM admin_log WHERE entity = 'product' AND user_id IS NULL AND action IN ($qph) AND entity_id IN ($ph)", array_merge($qv, $v))->rowCount();
                        $rows['products'] = $db->query("DELETE FROM products WHERE id IN ($ph)", $v)->rowCount();
                    });
                }, 120.0, false);
                if (!$got) throw new \RuntimeException('Блокировка записи индекса занята дольше 2 минут (импорт или загрузчик) — повторите запуск: он доделает.');
                return $rows;
            } catch (\PDOException $e) {
                if ($try >= 5 || !CatalogIndexer::lockError($e)) throw $e;
                usleep(300000 * $try);
            }
        }
    }

    /** Строки без товара в таблицах без индекса по товару (и давно висящие). @return array [таблица => строк] */
    private static function tail(): array
    {
        $db = App::db();
        $out = [];
        // большие таблицы — частями по заданию / запуску (короткие транзакции)
        $parts = ['import_seen' => 'job_id', 'import_images' => 'job_id', 'supplier_run_seen' => 'run_id', 'supplier_run_log' => 'run_id'];
        foreach (self::TABLES as $t => [$col, $act, $cond, $where]) {
            if ($where !== 'tail' || !in_array($act, ['delete', 'null'], true)) continue;
            $sql = $act === 'delete'
                ? "DELETE t FROM `$t` t LEFT JOIN products p ON p.id = t.`$col` WHERE t.`$col` IS NOT NULL AND p.id IS NULL"
                : "UPDATE `$t` t LEFT JOIN products p ON p.id = t.`$col` SET t.`$col` = NULL WHERE t.`$col` IS NOT NULL AND p.id IS NULL";
            $out[$t] = 0;
            if (isset($parts[$t])) {
                foreach ($db->col("SELECT DISTINCT `{$parts[$t]}` FROM `$t`") as $k) $out[$t] += $db->query($sql . " AND t.`{$parts[$t]}` = ?", [(int) $k])->rowCount();
            } else {
                $out[$t] = $db->query($sql)->rowCount();
            }
        }
        $out['product_related'] = ($out['product_related'] ?? 0)
            + $db->query('DELETE r FROM product_related r LEFT JOIN products p ON p.id = r.related_product_id WHERE p.id IS NULL')->rowCount();
        return $out;
    }

    /** Убрать удаляемые id из промокодов «только на товары». @return int изменено промокодов */
    private static function dropFromCoupons(array $del): int
    {
        $db = App::db();
        $n = 0;
        foreach ($db->all("SELECT id, product_ids FROM coupons WHERE product_ids IS NOT NULL AND product_ids <> ''") as $c) {
            $ids = array_values(array_filter(array_map('intval', preg_split('/[^\d]+/', (string) $c['product_ids']) ?: [])));
            $left = array_values(array_filter($ids, static fn($id) => !isset($del[$id])));
            if (count($left) === count($ids)) continue;
            $db->update('coupons', ['product_ids' => $left ? implode(',', $left) : null], 'id = ?', [(int) $c['id']]);
            $n++;
        }
        return $n;
    }

    // ================================================================== файлы фото (после проверки нового каталога)

    /**
     * Удалить папки фото товаров, которых больше нет (оригиналы wa-data/protected и миниатюры wa-data/public) — только
     * товаров из карты старых адресов. $dry — посчитать. @return array [dirs, files, bytes]
     */
    public static function purgeFiles(bool $dry, ?callable $say = null): array
    {
        $db = App::db();
        LegacyProducts::ensureSchema();
        $root = PUBLIC_DIR . '/' . App::config('images.wa_data', 'wa-data');
        $out = ['dirs' => 0, 'files' => 0, 'bytes' => 0];
        $ids = array_map('intval', $db->col('SELECT DISTINCT l.old_id FROM legacy_products l LEFT JOIN products p ON p.id = l.old_id WHERE p.id IS NULL'));
        foreach ($ids as $i => $id) {
            foreach (['protected', 'public'] as $zone) {
                $dir = $root . '/' . $zone . '/shop/products/' . Image::productDir($id);
                if (!is_dir($dir)) continue;
                $out['dirs']++;
                $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
                foreach ($it as $f) if ($f->isFile()) { $out['files']++; $out['bytes'] += (int) $f->getSize(); }
            }
            if (!$dry) AdminCatalog::deleteProductFiles($id);
            if ($say && $i % 10000 === 9999) $say('Проверено товаров: ' . ($i + 1) . ' из ' . count($ids));
        }
        return $out;
    }
}
