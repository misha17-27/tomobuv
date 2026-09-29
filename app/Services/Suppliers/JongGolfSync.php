<?php
declare(strict_types=1);

namespace App\Services\Suppliers;

use App\Core\App;
use App\Core\Cache;
use App\Core\Image;
use App\Services\AdminCatalog;
use App\Services\CatalogIndexer;
use App\Services\HtmlSanitizer;
use App\Services\Import\Importer;
use App\Services\ProductName;

/**
 * Обработка товаров Jong•Golf порциями — правила старого загрузчика (wa_loader_jonggolf.php → load_products)
 * с настройками владельца, запись — пакетами, как импорт прайсов (Importer).
 *
 * Товар поставщика (id_product) с N цветами (images[]) = N товаров сайта. Для каждого цвета:
 *   - поиск: связь supplier_links (код цвета → товар) → название старого загрузчика «{категория поставщика} {бренд} {код}»
 *     → запасной поиск по коду (артикул = код, название кончается кодом; кириллические С/А/В… = латинские) среди товаров
 *     без поставщика — это товары, заведённые вручную (их берём под загрузчик только с настройкой «adopt_manual»);
 *   - найден, цвет закончился (status 0) → скрыть: не на сайте, нет в наличии, остаток 0, цена — как у поставщика;
 *   - найден, цвет есть → цена (с коэффициентом категории), в наличии, снова показан (скрытый вручную — по настройке),
 *     категория по таблице (отвязка прежних), фото — если нет главного; название, характеристики, описание — по настройкам;
 *   - не найден → создать (название — правило сайта ProductName или как у старого загрузчика, адрес — translit старого
 *     загрузчика, уникальный; ящик = мин. заказ = inbox; 7 характеристик; фото вписывается в холст 700×700).
 *     Без фото не создаётся (add_new_products_withoutimage = false у владельца).
 * Пропуски считаются по причинам (SKIP) — в отчёте видно, почему товар не попал на сайт.
 * Порция — одна транзакция под блокировкой записи индекса (CatalogIndexer::locked, до транзакции) со снимком
 * характеристик до записи и точечной переиндексацией (CatalogIndexer::products) — как Importer::processRows.
 */
final class JongGolfSync
{
    private const PORTION = 20;                 // товаров поставщика (≈80 цветов) за транзакцию
    private const MIN_LEFT = 5.0;               // следующая порция шага — только если до конца бюджета осталось столько секунд
    private const LOCK_WAIT = 10.0;             // ждать блокировку индекса не дольше (и не дольше остатка бюджета шага)
    private const PRECHECK = 2.0;               // проверка «не идёт ли перестройка» до скачивания фото — ждать не дольше
    private const IMG_MAX = 10485760;
    private const EX_COLS = 'id, url, name, name_uk, sku, supplier, supplier_code, category_id, brand_id, price, compare_price, box_qty, min_qty, size,
        stock, in_stock, status, image_id, image_ext, meta_title, meta_description, meta_keywords, meta_title_uk, meta_description_uk, meta_keywords_uk';
    /** Колонки products, которые может менять загрузчик (белый список UPDATE) */
    private const UPDATABLE = ['name', 'name_uk', 'sku', 'supplier', 'supplier_code', 'category_id', 'brand_id', 'price', 'compare_price', 'size',
        'stock', 'in_stock', 'status', 'meta_title', 'meta_description', 'meta_keywords', 'meta_title_uk', 'meta_description_uk', 'meta_keywords_uk', 'updated_at'];
    private const CHANGE_NAMES = ['price' => 'цена', 'compare_price' => 'старая цена', 'status' => 'на сайте', 'in_stock' => 'наличие', 'stock' => 'остаток',
        'sku' => 'артикул (размерный ряд)', 'supplier' => 'поставщик', 'supplier_code' => 'код поставщика', 'category_id' => 'категория', 'name' => 'название',
        'name_uk' => 'название (укр.)', 'size' => 'размер', 'brand_id' => 'бренд', 'meta_title' => 'SEO title', 'meta_description' => 'SEO description', 'meta_keywords' => 'SEO keywords'];

    /** Причины пропуска (статистика запуска) */
    public const SKIP = [
        'status' => 'товар неактивен у поставщика (status ≠ 1)', 'offdel' => 'снят поставщиком (action_type off/del)',
        'brand' => 'бренд в списке пропускаемых', 'category_skip' => 'категория в списке пропускаемых', 'nocolors' => 'нет цветов (images)',
        'absent' => 'цвет закончился, товара на сайте нет', 'noadd' => 'новые товары не добавляются (настройка)',
        'category' => 'категория не сопоставлена (новый товар)', 'size' => 'нет размерного ряда', 'price' => 'цена 0',
        'nophoto' => 'новый товар без фото', 'photo' => 'фото не скачалось', 'manual' => 'есть товар, заведённый вручную',
        'dup' => 'повтор кода цвета', 'ambiguous' => 'код совпал с несколькими товарами',
    ];

    private int $runId;
    private array $dict;
    private bool $dry;
    private array $stats;
    private array $cfg = [];
    private array $attr = [];
    private array $prices = [];
    private array $ci = [];
    private array $brandByNk = [];
    private array $values = [];                 // [fid => [нормализованное значение => id]]
    private array $brandByNorm = [];            // [ключ раскодированного названия => [id, …]] — «J&amp;amp;G» на сайте = «J&G»
    private int $brandFeature = 0;
    private int $sizeFeature = 0;
    private array $skipBrands = [];
    private array $skipCats = [];
    private bool $deferred = false;             // порция отложена: блокировка индекса занята (идёт полная перестройка)

    public function __construct(int $runId, array $dict, bool $dry, array &$stats)
    {
        $this->runId = $runId;
        $this->dict = $dict;
        $this->dry = $dry;
        $this->stats = &$stats;
        foreach (JongGolf::DEFAULTS as $k => $_) $this->cfg[$k] = JongGolf::cfg($k);
        [$this->attr] = JongGolf::attributes();
        $this->prices = JongGolf::prices();
        $this->brandFeature = AdminCatalog::featureId('brand');
        $this->sizeFeature = AdminCatalog::featureId('size');
        $this->skipBrands = JongGolf::lines('skip_brands');
        $this->skipCats = JongGolf::lines('skip_categories');
    }

    // ================================================================== порции

    /**
     * Товары страницы [[ключ, товар], …] с позиции $pos до $deadline (и не больше $limit). $saved($pos) — после каждой
     * записанной порции (сохранить место: после обрыва записанное не обрабатывается повторно); вернула false — запуск
     * остановлен, следующая порция не начинается. Следующая порция — только если до $deadline осталось ≥ MIN_LEFT с
     * (первая — всегда): скачивание фото и ожидание блокировки ограничены остатком бюджета шага. @return int новая позиция
     */
    public function process(array $list, int $pos, float $deadline, ?int $limit, ?callable $saved = null): int
    {
        $this->ci = Importer::categoryIndex();
        $this->loadBrands();
        $this->deferred = false;
        $first = true;
        while ($pos < count($list) && ($limit === null || $limit > 0) && microtime(true) < $deadline - ($first ? 1.0 : self::MIN_LEFT)) {
            $first = false;
            $n = self::PORTION;
            if ($limit !== null) $n = min($n, $limit);
            $slice = array_slice($list, $pos, $n);
            if (!$this->portion($slice, $deadline)) { $this->deferred = true; break; }   // идёт перестройка индекса — следующим шагом
            $pos += count($slice);
            if ($limit !== null) $limit -= count($slice);
            if ($saved && $saved($pos) === false) break;
        }
        return $pos;
    }

    /** Последняя порция process() отложена (блокировка индекса занята) — шаг заканчивается и повторяется позже */
    public function deferred(): bool
    {
        return $this->deferred;
    }

    /**
     * Бренды сайта: [ключ названия как в базе => id] и [ключ раскодированного названия => [id, …]] (JongGolf::name —
     * «J&amp;amp;G» и «J&amp;G» из старой базы оба «J&G»); значения характеристик — заново (после отката транзакции
     * в кэше могли остаться несуществующие id)
     */
    private function loadBrands(): void
    {
        $this->brandByNk = [];
        $this->brandByNorm = [];
        $this->values = [];
        $norm = [];
        foreach (App::db()->all('SELECT id, name, product_count FROM brands ORDER BY id') as $b) {
            $this->brandByNk[Importer::nk((string) $b['name'])] ??= (int) $b['id'];
            $norm[Importer::nk(JongGolf::name((string) $b['name']))][(int) $b['id']] = (int) $b['product_count'];
        }
        foreach ($norm as $k => $list) {                                    // больше товаров — первым, при равенстве — меньший id
            uksort($list, static fn($a, $b) => [$list[$b], $a] <=> [$list[$a], $b]);
            $this->brandByNorm[$k] = array_keys($list);
        }
    }

    /** Одна порция. false — блокировка индекса занята (порция не записана, повторится; скачанные фото остаются в папке запуска) */
    private function portion(array $slice, float $deadline): bool
    {
        $items = [];
        $statsBefore = $this->stats;
        $logMark = JongGolf::logMark();
        foreach ($slice as [$key, $p]) {
            $this->stats['products']++;
            foreach ($this->expand($key, $p) as $it) $items[] = $it;
        }
        $this->match($items);
        $this->dedupe($items);
        foreach ($items as &$it) $this->decide($it);
        unset($it);
        if ($this->dry) {
            $this->report($items);
            $this->seen($items);
            JongGolf::flushLog();
            return true;
        }
        // порция откладывается без следов: счётчики и строки отчёта — как до неё (разбор повторится следующим шагом)
        $defer = function () use ($statsBefore, $logMark): bool {
            $this->stats = $statsBefore;
            JongGolf::logRollback($logMark);
            return false;
        };
        // блокировка индекса занята дольше PRECHECK с (идёт полная перестройка — минуты; правка в админке держит её
        // доли секунды): отложить сразу, ничего не скачивая
        if (!CatalogIndexer::locked(static function (): void {}, max(0.0, min(self::PRECHECK, $deadline - microtime(true) - 1.0)), false)) return $defer();
        // фото — до ожидания блокировки; скачанные лежат в папке запуска (photo-*.jpg) и при повторе порции не качаются снова
        $photos = $this->photos($items, $deadline);
        $done = false;
        for ($try = 1; ; $try++) {
            $moved = [];
            try {
                // ждать блокировку — не дольше LOCK_WAIT и остатка бюджета шага
                $ok = CatalogIndexer::locked(function () use (&$items, $photos, &$moved): void {
                    App::db()->transaction(function () use (&$items, $photos, &$moved): void {
                        $this->apply($items, $photos, $moved);
                    });
                }, max(0.0, min(self::LOCK_WAIT, $deadline - microtime(true) - 1.0)), false);
                if (!$ok) return $defer();                                  // фото не удаляются — пригодятся следующему шагу
                $done = true;
                break;
            } catch (\PDOException $e) {
                foreach ($moved as $f) @unlink($f);
                if ($try >= 3 || !CatalogIndexer::lockError($e)) { $this->dropPhotos($photos); throw $e; }
                usleep(300000 * $try);                                      // взаимная блокировка — транзакция откачена, повтор
                foreach ($items as &$it) { $it['id'] = $it['ex']['id'] ?? null; $it['stored'] = []; $it['drop_files'] = []; }
                unset($it);
                $this->loadBrands();
            } catch (\Throwable $e) {
                foreach ($moved as $f) @unlink($f);
                $this->dropPhotos($photos);
                throw $e;
            }
        }
        foreach ($items as $it) foreach ($it['drop_files'] ?? [] as [$pid, $iid, $ext]) AdminCatalog::deleteImageFiles($pid, $iid, $ext);   // заменённые фото
        $this->dropPhotos($photos);
        if ($done) $this->report($items);
        JongGolf::flushLog();
        return true;
    }

    // ================================================================== разбор товара поставщика

    /** Товар поставщика → цвета (строки для записи); пропущенные — в статистику */
    private function expand(string $key, array $p): array
    {
        $ext = (string) ($p['id_product'] ?? $key);
        $label = trim(($p['articul'] ?? '') . ' id-поставщика=' . $ext);
        if ((int) ($p['status'] ?? 0) !== 1) return $this->skipAll($p, 'status', $label);
        if (in_array((string) ($p['action_type'] ?? ''), ['off', 'del'], true)) return $this->skipAll($p, 'offdel', $label);
        $a = $this->dict;
        $sid = (string) ($p['season'] ?? '');
        $season = trim((string) ($a['seasons'][$sid]['name'] ?? ''));
        $cat = trim((string) ($a['seasons'][$sid]['categorys'][(string) ($p['id_category'] ?? '')]['name'] ?? ''));
        $gender = trim((string) ($a['gender'][(string) ($p['gender'] ?? '')] ?? ''));
        $size = trim((string) ($p['size'] ?? ''));
        $bid = (string) ($p['brand'] ?? '');
        $brandRaw = trim((string) ($a['brand'][$bid]['name'] ?? ''));
        $brand = JongGolf::name($brandRaw);                                 // «J&amp;amp;amp;G» → «J&G»
        if ($brand !== '' && isset($this->skipBrands[JongGolfMap::norm($brand)])) return $this->skipAll($p, 'brand', $label . ' бренд ' . $brand);
        if ($this->skipCats && (isset($this->skipCats[JongGolfMap::norm($cat)]) || isset($this->skipCats[JongGolfMap::norm($season . '|' . $cat)]))) return $this->skipAll($p, 'category_skip', $label . ' ' . $cat);
        // характеристики — как $attribute_textarea старого загрузчика
        $feat = [];
        foreach ($this->attr as $field => $f) {
            $v = '';
            if (str_starts_with($field, 'material>')) {
                $part = substr($field, 9);
                $mid = (string) ($p['material'][$part] ?? '0');
                if ($mid !== '0' && $mid !== '') $v = (string) ($a['material'][$part][$mid]['name'] ?? '');
            } elseif ($field === 'brand') {
                $v = $brand;
            } elseif ($field === 'gender') {
                $v = $gender;
            } else {
                $v = (string) ($p[$field] ?? '');
            }
            $v = JongGolf::name($v);
            if ($v !== '') $feat[(int) $f['id']] = ['name' => (string) $f['name'], 'value' => $v];
        }
        // цена за пару: со скидкой — discount, старая — cost (без наценки у старого загрузчика; здесь — коэффициент категории)
        $cost = (float) ($p['cost'] ?? 0);
        $disc = (float) ($p['discount'] ?? 0);
        [$price, $compare] = $disc > 0 ? [$disc, $cost] : [$cost, 0.0];
        [$mrow, $mkey] = JongGolfMap::find($season, $cat, $gender, $size);
        $catId = $mrow && $mrow['category_id'] ? (int) $mrow['category_id'] : null;
        if ($catId !== null && !isset($this->ci['cats'][$catId])) $catId = null;          // категорию удалили после загрузки таблицы
        if ($catId === null) $this->stats['unmapped'][$mkey] = ($this->stats['unmapped'][$mkey] ?? 0) + count((array) ($p['images'] ?? []));
        if ($catId !== null && isset($this->prices[$catId])) {
            $r = $this->prices[$catId];
            $price = round($price * $r['k'] + $r['plus'], 2);
            if ($compare > 0) $compare = round($compare * $r['k'] + $r['plus'], 2);
        }
        $images = is_array($p['images'] ?? null) ? $p['images'] : [];
        if (!$images) {
            $this->stats['skipped']++;
            $this->skipCount('nocolors', 'Ошибка: у товара ' . $label . ' нет цветов (images)');
            return [];
        }
        $out = [];
        foreach ($images as $ckey => $img) {
            $aid = trim((string) ($img['aid'] ?? ''));
            if ($aid === '') { $this->stats['skipped']++; $this->skipCount('nocolors', 'Ошибка: у цвета ' . $ckey . ' товара ' . $label . ' нет кода (aid)'); continue; }
            $this->stats['colors']++;
            $out[] = [
                'ext' => $ext, 'key' => $key, 'aid' => $aid, 'code' => JongGolf::code($aid), 'url' => trim((string) ($img['url'] ?? '')),
                'cstatus' => (int) ($img['status'] ?? 0), 'season' => $season, 'cat' => $cat, 'gender' => $gender, 'size' => $size,
                'brand' => $brand, 'brand_raw' => $brandRaw, 'feat' => $feat, 'price' => $price, 'compare' => $compare, 'box' => max(1, min(65535, (int) ($p['inbox'] ?? 1))),
                'desc' => (string) ($p['desc'] ?? ''), 'cat_id' => $catId, 'mkey' => $mkey,
                'supname' => trim($cat . ' ' . $brand . ' ' . $aid),                  // название старого загрузчика
                'ex' => null, 'via' => '', 'link' => null, 'action' => '', 'msg' => '', 'upd' => [], 'changes' => [], 'id' => null,
            ];
        }
        return $out;
    }

    /** Весь товар пропущен (все цвета) */
    private function skipAll(array $p, string $why, string $label): array
    {
        $n = max(1, count((array) ($p['images'] ?? [])));
        $this->stats['skipped'] += $n;
        $this->stats['skip'][$why] = ($this->stats['skip'][$why] ?? 0) + $n;
        $this->example($why, 'Пропущен ' . $label . ': ' . self::SKIP[$why]);
        return [];
    }

    private function skipCount(string $why, string $msg, int $n = 1): void
    {
        $this->stats['skip'][$why] = ($this->stats['skip'][$why] ?? 0) + $n;
        $this->example($why, $msg);
    }

    /** В отчёт — первые 20 примеров каждой массовой причины пропуска, остальное — только в счётчики */
    private function example(string $why, string $msg): void
    {
        $n = $this->stats['examples'][$why] = (int) ($this->stats['examples'][$why] ?? 0) + 1;
        if ($n <= 20) JongGolf::log($this->runId, 'skip', $msg);
        elseif ($n === 21) JongGolf::log($this->runId, 'skip', '…дальше такие пропуски («' . self::SKIP[$why] . '») только считаются');
    }

    // ================================================================== поиск существующих товаров

    private function match(array &$items): void
    {
        if (!$items) return;
        $db = App::db();
        $codes = array_values(array_unique(array_column($items, 'code')));
        [$ph, $v] = $db->in($codes);
        // 1. связь «код цвета → товар»
        $links = [];
        foreach ($db->all("SELECT l.code, l.hidden, p.id FROM supplier_links l JOIN products p ON p.id = l.product_id WHERE l.supplier = ? AND l.code IN ($ph)",
            array_merge([JongGolf::CODE], $v)) as $r) $links[(string) $r['code']] = ['id' => (int) $r['id'], 'hidden' => (int) $r['hidden']];
        $ids = array_column($links, 'id');
        // 2. название старого загрузчика (так он искал: WHERE name = ? по всей таблице, без учёта регистра)
        $names = [];
        foreach ($items as $it) if (!isset($links[$it['code']])) $names[$it['supname']] = 1;
        $byName = [];
        if ($names) {
            [$nph, $nv] = $db->in(array_keys($names));
            foreach ($db->all('SELECT id, name, supplier FROM products WHERE name IN (' . $nph . ')', $nv) as $r) {
                if (!in_array((string) $r['supplier'], ['', JongGolf::CODE], true)) continue;     // чужой поставщик с тем же названием — не наш
                $k = Importer::nk((string) $r['name']);
                if (!isset($byName[$k]) || $r['supplier'] === JongGolf::CODE) $byName[$k] = (int) $r['id'];
            }
            $ids = array_merge($ids, array_values($byName));
        }
        // 3. запасной поиск по коду среди товаров без поставщика (заведены вручную: название «Весна-Осень Jong•Golf C31140-3»,
        //    артикул = код, бывает с кириллической «С»)
        $variants = [];
        foreach ($items as $it) {
            if (isset($links[$it['code']]) || isset($byName[Importer::nk($it['supname'])])) continue;
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
            foreach ($byCode as $c => $set) $ids = array_merge($ids, array_keys($set));
        }
        $rows = [];
        if ($ids) {
            [$iph, $iv] = $db->in(array_values(array_unique($ids)));
            $rows = $db->keyed('SELECT ' . self::EX_COLS . " FROM products WHERE id IN ($iph)", $iv);
            $cats = [];
            foreach ($db->all("SELECT product_id, category_id FROM category_products WHERE product_id IN ($iph)", $iv) as $r) $cats[(int) $r['product_id']][] = (int) $r['category_id'];
            $imgs = [];
            foreach ($db->all("SELECT id, product_id, ext FROM product_images WHERE product_id IN ($iph)", $iv) as $r) $imgs[(int) $r['product_id']][(int) $r['id']] = (string) $r['ext'];
            foreach ($rows as $pid => &$r) { $r['cats'] = $cats[$pid] ?? []; $r['images'] = $imgs[$pid] ?? []; }
            unset($r);
        }
        foreach ($items as &$it) {
            if (isset($links[$it['code']]) && isset($rows[$links[$it['code']]['id']])) {
                $it['ex'] = $rows[$links[$it['code']]['id']]; $it['via'] = 'link'; $it['link'] = $links[$it['code']];
            } elseif (($pid = $byName[Importer::nk($it['supname'])] ?? null) && isset($rows[$pid])) {
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
    private static function variants(string $aid): array
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

    /** Повтор кода цвета в порции или раньше в этом запуске — второй раз не обрабатывается */
    private function dedupe(array &$items): void
    {
        if (!$items) return;
        $db = App::db();
        [$ph, $v] = $db->in(array_values(array_unique(array_column($items, 'code'))));
        $seen = array_flip($db->col("SELECT code FROM supplier_run_seen WHERE run_id = ? AND code IN ($ph)", array_merge([$this->runId], $v)));
        foreach ($items as &$it) {
            if (isset($seen[$it['code']])) { $it['action'] = 'skip'; $it['why'] = 'dup'; $it['msg'] = 'повтор кода цвета ' . $it['aid'] . ' — уже обработан в этом запуске'; continue; }
            $seen[$it['code']] = 1;
        }
        unset($it);
    }

    // ================================================================== решение по цвету

    private function decide(array &$it): void
    {
        if ($it['action'] === 'skip') return;
        $c = $this->cfg;
        $ex = $it['ex'];
        if ($it['via'] === 'ambiguous') { $this->skip($it, 'ambiguous', 'код ' . $it['aid'] . ' совпал с несколькими товарами без поставщика (' . $it['msg'] . ') — не изменены, новый не создан'); return; }
        if ($ex && $it['via'] === 'code' && (string) $ex['supplier'] !== JongGolf::CODE && $c['adopt_manual'] !== '1') {
            $this->skip($it, 'manual', 'на сайте есть товар ID ' . $ex['id'] . ' «' . $ex['name'] . '», заведённый вручную, — не изменён и дубль не создан. Взять его под загрузчик — настройка «Товары, заведённые вручную»');
            return;
        }
        if ($ex) {
            $it['id'] = (int) $ex['id'];
            $keepSku = JongGolf::code((string) $ex['sku']) === $it['code'];      // артикул = код (товар заведён вручную) — не затираем размерным рядом
            $upd = [];
            if ($it['cstatus'] !== 1) {
                // цвет закончился: товар скрыт, нет в наличии (старый загрузчик: status = 0, count = 0)
                $upd = ['status' => 0, 'in_stock' => 0, 'stock' => 0];
                if ($it['price'] > 0) $upd += ['price' => $it['price'], 'compare_price' => $it['compare']];
                if ($it['size'] !== '' && !$keepSku) $upd['sku'] = $it['size'];
                $upd += ['supplier' => JongGolf::CODE, 'supplier_code' => $it['ext']];
                $it['action'] = 'hide';
                $it['hidden'] = 1;
            } else {
                if ($it['price'] <= 0) { $this->skip($it, 'price', 'цена 0 у поставщика — товар ID ' . $ex['id'] . ' не изменён', 'error'); return; }
                $manualHidden = (int) $ex['status'] === 0 && !($it['link']['hidden'] ?? 0) && $it['via'] === 'link';
                $status = $c['keep_manual_hidden'] === '1' && $manualHidden ? 0 : 1;
                $upd = ['price' => $it['price'], 'compare_price' => $it['compare'], 'in_stock' => 1, 'stock' => null, 'status' => $status,
                    'supplier' => JongGolf::CODE, 'supplier_code' => $it['ext']];
                if ($it['size'] !== '' && !$keepSku) $upd['sku'] = $it['size'];
                if ($status === 0) $it['warn'][] = 'скрыт вручную — оставлен скрытым (настройка)';
                if ($it['cat_id'] !== null && $c['update_category'] === '1' && ((int) $ex['category_id'] !== $it['cat_id'] || $ex['cats'] !== [$it['cat_id']])) {
                    $upd['category_id'] = $it['cat_id'];
                    $it['relink'] = true;
                }
                if ($it['cat_id'] === null) $it['warn'][] = 'категория «' . $it['mkey'] . '» не сопоставлена — привязка не изменена';
                if ($c['update_name'] === '1') {
                    [$name, $nameUk] = $this->names($it, $it['cat_id'] ?? (int) $ex['category_id']);
                    $upd += ['name' => $name, 'name_uk' => $nameUk] + $this->metas($name, $nameUk);
                }
                if ($c['update_features'] === '1') {
                    $it['features'] = true;
                    if ($it['size'] !== '') $upd['size'] = mb_substr($it['size'], 0, 64);
                    $it['brand_ref'] = $this->brandRef($it['brand'], $it['brand_raw']);
                    if (is_int($it['brand_ref'])) $upd['brand_id'] = $it['brand_ref'];
                    elseif ($it['brand_ref'] === null) $upd['brand_id'] = null;
                }
                if ($c['update_description'] === '1' && trim($it['desc']) !== '') $it['texts'] = ['description' => HtmlSanitizer::supplier($it['desc'])];
                // фото: нет главного (или его строки / файла) либо «Заменить имеющиеся фото»
                $main = $ex['image_id'] !== null && isset($ex['images'][(int) $ex['image_id']]);
                if ($main && $c['photo_check_file'] === '1' && !is_file(Image::originalPath((int) $ex['id'], (int) $ex['image_id'], (string) ($ex['image_ext'] ?: 'jpg')))) $main = false;
                if ($it['url'] !== '' && (!$main || $c['photo_replace'] === '1')) $it['photo'] = true;
                elseif (!$main && $it['url'] === '') $it['warn'][] = 'поставщик не задал фото';
                $it['action'] = 'update';
            }
            // только отличающиеся поля
            foreach ($upd as $col => $val) {
                $old = $ex[$col] ?? null;
                $same = match ($col) {
                    'price', 'compare_price' => $old !== null && abs((float) $old - (float) $val) < 0.005,
                    'stock', 'brand_id', 'category_id' => ($old === null && $val === null) || ($old !== null && $val !== null && (int) $old === (int) $val),
                    'status', 'in_stock' => (int) $old === (int) $val,
                    default => trim((string) $old) === trim((string) $val),
                };
                if ($same) continue;
                $it['upd'][$col] = $val;
                $it['changes'][] = (self::CHANGE_NAMES[$col] ?? $col) . ': ' . self::show($col, $old, $this) . ' → ' . self::show($col, $val, $this);
            }
            if (!empty($it['relink']) && !isset($it['upd']['category_id'])) $it['changes'][] = 'привязка к категории → ' . ($this->ci['path'][$it['cat_id']] ?? $it['cat_id']);
            if (!empty($it['features'])) $it['changes'][] = 'характеристики';
            if (!empty($it['texts'])) $it['changes'][] = 'описание';
            if (!empty($it['photo'])) $it['changes'][] = 'фото';
            $linkHidden = (int) ($it['link']['hidden'] ?? -1);
            $it['link_set'] = $it['action'] === 'hide' ? 1 : ((int) ($it['upd']['status'] ?? $ex['status']) === 1 ? 0 : ($linkHidden === 1 ? 1 : 0));
            if ($it['action'] === 'update' && !$it['upd'] && empty($it['relink']) && empty($it['features']) && empty($it['texts']) && empty($it['photo'])) $it['action'] = 'same';
            if ($it['action'] === 'hide' && !$it['upd']) $it['action'] = 'same';
            return;
        }
        // нового товара нет на сайте
        if ($it['cstatus'] !== 1) { $this->skip($it, 'absent', ''); return; }
        if ($c['add_new'] !== '1') { $this->skip($it, 'noadd', ''); return; }
        if ($it['cat_id'] === null) { $this->skip($it, 'category', 'Ошибка: категория «' . $it['mkey'] . '» не обработана (нет в таблице соответствия) — ' . $it['aid'] . ' не добавлен', 'error'); return; }
        if ($it['size'] === '') { $this->skip($it, 'size', 'Ошибка: для ' . $it['aid'] . ' id-поставщика=' . $it['ext'] . ' не задана размерная сетка', 'error'); return; }
        if ($it['price'] <= 0) { $this->skip($it, 'price', 'Ошибка: цена 0 — ' . $it['aid'] . ' id-поставщика=' . $it['ext'] . ' не добавлен', 'error'); return; }
        if ($it['url'] === '' && $c['add_without_photo'] !== '1') { $this->skip($it, 'nophoto', 'Предупреждение: НЕ добавлен ' . $it['aid'] . ' id-поставщика=' . $it['ext'] . ' — нет фото', 'warn'); return; }
        $it['brand_ref'] = $this->brandRef($it['brand'], $it['brand_raw']);
        [$name, $nameUk] = $this->names($it, $it['cat_id']);
        $now = date('Y-m-d H:i:s');
        $it['row'] = [
            'url' => '', 'name' => $name, 'name_uk' => $nameUk, 'sku' => mb_substr($it['size'], 0, 255), 'category_id' => $it['cat_id'],
            'brand_id' => is_int($it['brand_ref']) ? $it['brand_ref'] : null, 'price' => $it['price'], 'compare_price' => $it['compare'], 'purchase_price' => 0,
            'box_qty' => $it['box'], 'min_qty' => $it['box'], 'size' => mb_substr($it['size'], 0, 64), 'stock' => null, 'in_stock' => 1, 'status' => 1,
            'supplier' => JongGolf::CODE, 'supplier_code' => mb_substr($it['ext'], 0, 100), 'created_at' => $now, 'updated_at' => $now,
        ] + $this->metas($name, $nameUk);
        $it['url_base'] = substr(JongGolf::translit($name), 0, 240) ?: 'product';
        if (trim($it['desc']) !== '') $it['texts'] = ['description' => HtmlSanitizer::supplier($it['desc'])];
        $it['features'] = true;
        $it['photo'] = $it['url'] !== '';
        $it['link_set'] = 0;
        $it['action'] = 'create';
    }

    private function skip(array &$it, string $why, string $msg, string $level = 'skip'): void
    {
        $it['action'] = 'skip';
        $it['why'] = $why;
        $it['msg'] = $msg;
        $it['level'] = $level;
    }

    /** Название нового товара (или при «менять название»): [RU, UA | null] */
    private function names(array $it, ?int $catId): array
    {
        if ($this->cfg['name_mode'] === 'supplier') return [mb_substr($it['supname'], 0, 255), null];
        // бренд — раскодированное название поставщика («J&G», а не «J&amp;amp;G» из старой базы); «Не указано» правило пропускает
        [$ru, $uk] = ProductName::build(['name' => $it['aid'], 'category_id' => $catId, 'brand_name' => $it['brand']]);
        return [$ru, $uk !== $ru ? $uk : null];
    }

    /** SEO по шаблонам настроек (как product_meta_* старого загрузчика; UA — с украинским названием) */
    private function metas(string $name, ?string $nameUk): array
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
     * Бренд: id существующего, «new:Название» (создаётся при записи) или null — не указан. $brand — раскодированное
     * название (JongGolf::name: «J&G»), $raw — как в ответе поставщика (экранировано многократно: «J&amp;amp;amp;G»).
     * На сайте такой бренд мог остаться со старого сайта экранированным («J&amp;amp;G», id 72267; есть и «J&amp;G»,
     * id 71814) — загрузчик его не переименовывает (сменился бы адрес /brand/…/ — решение владельца), а находит:
     *  1) бренд с раскодированным названием «J&G» (владелец переименовал — берётся он);
     *  2) название как в базе — от ответа поставщика, раскодируя по одному уровню: первое совпадение (ближайшее
     *     к ответу поставщика — то, что записывал старый загрузчик: «J&amp;amp;G» = id 72267);
     *  3) по раскодированному названию: из нескольких — с большим числом товаров, при равенстве — меньший id;
     *  4) нет — новый бренд с раскодированным названием («J&G»).
     */
    private function brandRef(string $brand, string $raw = ''): int|string|null
    {
        if ($brand === '') return null;
        if (isset($this->brandByNk[Importer::nk($brand)])) return $this->brandByNk[Importer::nk($brand)];
        $s = $raw !== '' ? $raw : $brand;
        for ($i = 0; $i < 10; $i++) {
            if (isset($this->brandByNk[Importer::nk($s)])) return $this->brandByNk[Importer::nk($s)];
            $d = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if ($d === $s) break;
            $s = $d;
        }
        $ids = $this->brandByNorm[Importer::nk($brand)] ?? [];
        return $ids ? $ids[0] : 'new:' . $brand;
    }

    private static function show(string $col, $v, self $s): string
    {
        if ($v === null || $v === '') return '—';
        if ($col === 'status' || $col === 'in_stock') return (int) $v ? 'да' : 'нет';
        if ($col === 'category_id') return (string) ($s->ci['path'][(int) $v] ?? $v);
        if ($col === 'price' || $col === 'compare_price') return rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.');
        $v = (string) $v;
        return mb_strlen($v) > 40 ? mb_substr($v, 0, 39) . '…' : $v;
    }

    // ================================================================== фото

    /**
     * Скачать и подготовить фото новых товаров и товаров без фото (до транзакции): вписывание в холст
     * photo_width × photo_height по центру на белом фоне, JPEG с качеством photo_quality (как load_photos старого
     * загрузчика; ширина или высота 0 — без холста, как есть). Готовое фото — в папке запуска (photoFile): порция,
     * отложенная из-за перестройки индекса, при повторе берёт его оттуда, а не качает снова; после записи порции
     * (или при ошибке) файлы удаляются (dropPhotos), остатки — по окончании запуска (JongGolf::dropPhotoCache).
     * Время скачивания — в пределах остатка бюджета шага. @return [индекс цвета => ['file', 'w', 'h'] | ['error']]
     */
    private function photos(array $items, float $deadline): array
    {
        $urls = [];
        foreach ($items as $i => $it) if (!empty($it['photo']) && in_array($it['action'], ['create', 'update'], true)) $urls[$i] = $it['url'];
        if (!$urls) return [];
        $out = [];
        foreach ($urls as $i => $u) {                                        // скачано прошлым шагом (порция была отложена)
            $f = $this->photoFile($u);
            $info = is_file($f) ? @getimagesize($f) : false;
            if ($info) { $out[$i] = ['file' => $f, 'w' => (int) $info[0], 'h' => (int) $info[1]]; unset($urls[$i]); }
        }
        for ($try = 1; $try <= 2 && $urls; $try++) {
            $left = $deadline - microtime(true) - 2.0;
            if ($try === 2 && $left < 3.0) break;                           // на повтор время шага кончилось
            $left = max(3.0, $left);
            $res = Importer::fetchMany($urls, self::IMG_MAX, (int) min(20, ceil($left)), true, $left);
            foreach ($res as $i => $r) {
                if (isset($r['error'])) { $out[$i] = ['error' => (string) $r['error']]; continue; }
                $out[$i] = $this->frame((string) $r['file'], $this->photoFile($urls[$i]));
                @unlink((string) $r['file']);
                unset($urls[$i]);
            }
            if ($try === 1) foreach ($urls as $i => $_) if (empty($res[$i]['retry']) && !str_contains((string) ($res[$i]['error'] ?? ''), 'ответ сервера 5')) unset($urls[$i]);   // повтор — только таймауты и 5xx
        }
        return $out;
    }

    /** Готовое фото по ссылке в папке запуска (с параметрами холста: другие настройки — другой файл) */
    private function photoFile(string $url): string
    {
        $c = $this->cfg;
        return JongGolf::dir($this->runId) . '/photo-' . md5($url . '|' . $c['photo_width'] . 'x' . $c['photo_height'] . 'q' . $c['photo_quality']) . '.jpg';
    }

    private function frame(string $src, string $dst): array
    {
        $info = @getimagesize($src);
        if (!$info || filesize($src) < 500) return ['error' => 'файл не является картинкой'];
        [$w, $h] = $info;
        if ($w < 10 || $h < 10 || $w * $h > 30000000) return ['error' => 'недопустимый размер ' . $w . '×' . $h];
        $img = match ((string) $info['mime']) {
            'image/jpeg' => @imagecreatefromjpeg($src),
            'image/png'  => @imagecreatefrompng($src),
            'image/gif'  => @imagecreatefromgif($src),
            'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($src) : false,
            default      => false,
        };
        if (!$img) return ['error' => 'картинка не читается (' . $info['mime'] . ')'];
        $cw = (int) $this->cfg['photo_width']; $ch = (int) $this->cfg['photo_height'];
        if ($cw <= 0 || $ch <= 0) { $cw = $w; $ch = $h; }
        $canvas = imagecreatetruecolor($cw, $ch);
        imagefill($canvas, 0, 0, (int) imagecolorallocate($canvas, 255, 255, 255));   // поля — белые (у старого загрузчика почти белые #FFFFFA)
        $scale = min($cw / $w, $ch / $h);                                      // вписать с сохранением пропорций (как старый: и увеличивая)
        $tw = max(1, (int) round($w * $scale)); $th = max(1, (int) round($h * $scale));
        imagecopyresampled($canvas, $img, intdiv($cw - $tw, 2), intdiv($ch - $th, 2), 0, 0, $tw, $th, $w, $h);
        imagedestroy($img);
        $tmp = $dst . '.part';                                              // целиком или никак: недописанный файл не примется за готовый
        $ok = imagejpeg($canvas, $tmp, max(30, min(100, (int) $this->cfg['photo_quality'])));
        imagedestroy($canvas);
        if (!$ok || !@rename($tmp, $dst)) { @unlink($tmp); return ['error' => 'не удалось сохранить JPEG']; }
        return ['file' => $dst, 'w' => $cw, 'h' => $ch];
    }

    private function dropPhotos(array $photos): void
    {
        foreach ($photos as $p) if (isset($p['file'])) @unlink($p['file']);
    }

    // ================================================================== запись порции

    /** Всё в открытой транзакции (под блокировкой индекса). $moved — перенесённые файлы фото (удаляются при откате) */
    private function apply(array &$items, array $photos, array &$moved): void
    {
        $db = App::db();
        $now = date('Y-m-d H:i:s');
        // фото не скачалось: новый товар не создаётся (как старый загрузчик), у найденного — предупреждение
        foreach ($items as $i => &$it) {
            if (empty($it['photo']) || isset($photos[$i]['file'])) continue;
            $err = $photos[$i]['error'] ?? 'не скачано';
            if ($it['action'] === 'create' && $this->cfg['add_without_photo'] !== '1') { $this->skip($it, 'photo', 'Ошибка: не получено фото по ссылке ' . $it['url'] . ' (' . $err . ') — ' . $it['aid'] . ' не добавлен', 'error'); continue; }
            $it['photo'] = false;
            $it['warn'][] = 'фото не загружено: ' . $err;
            if ($it['action'] === 'update') $it['changes'] = array_values(array_diff($it['changes'], ['фото']));
        }
        unset($it);
        $upIds = [];
        foreach ($items as $it) if (in_array($it['action'], ['update', 'hide'], true)) $upIds[] = (int) $it['id'];
        $snap = CatalogIndexer::snapshot($upIds);

        // 1. новые бренды (id бренда = id значения характеристики «Бренд», AdminCatalog::valueId)
        $newBrand = [];
        foreach ($items as $it) {
            if (!is_string($it['brand_ref'] ?? null) || !($it['action'] === 'create' || !empty($it['features']))) continue;
            $name = substr($it['brand_ref'], 4);
            if (isset($newBrand[$name])) continue;
            $bid = $this->brandByNk[Importer::nk($name)] ?? ($this->brandFeature ? AdminCatalog::valueId($this->brandFeature, $name) : 0);
            $newBrand[$name] = $bid ?: null;
            if ($bid && !isset($this->brandByNk[Importer::nk($name)])) {
                $this->brandByNk[Importer::nk($name)] = $bid;
                JongGolf::log($this->runId, 'info', 'Добавлен бренд «' . $name . '» (id ' . $bid . ')');
            }
        }
        $brandId = static fn($ref) => is_string($ref) ? ($newBrand[substr($ref, 4)] ?? null) : $ref;

        // 2. новые товары: адрес — translit старого загрузчика, свободный (slug, slug-2 … slug-9, иначе со случайным хвостом)
        $creates = array_keys(array_filter($items, static fn($it) => $it['action'] === 'create'));
        if ($creates) {
            $cand = [];
            foreach ($creates as $i) { $b = $items[$i]['url_base']; $cand[$b] = 1; for ($k = 2; $k <= 9; $k++) $cand[$b . '-' . $k] = 1; }
            [$ph, $v] = $db->in(array_keys($cand));
            $taken = array_flip(array_map('mb_strtolower', $db->col("SELECT url FROM products WHERE url IN ($ph)", $v)));
            foreach ($creates as $i) {
                $b = $items[$i]['url_base'];
                $url = null;
                foreach (array_merge([$b], array_map(static fn($k) => $b . '-' . $k, range(2, 9))) as $u) if (!isset($taken[$u])) { $url = $u; break; }
                $url ??= $b . '-' . substr(bin2hex(random_bytes(3)), 0, 5);
                $taken[$url] = 1;
                $items[$i]['row']['url'] = $url;
            }
            $db->insertMany('products', array_map(static fn($i) => ['brand_id' => $brandId($items[$i]['brand_ref'] ?? null)] + $items[$i]['row'], $creates), false, 200);
            [$ph, $v] = $db->in(array_map(static fn($i) => $items[$i]['row']['url'], $creates));
            $ids = $db->pairs("SELECT url, id FROM products WHERE url IN ($ph)", $v);
            foreach ($creates as $i) $items[$i]['id'] = (int) ($ids[$items[$i]['row']['url']] ?? 0);
        }

        // 3. изменения найденных — UPDATE … CASE пачкой (колонки из белого списка)
        $upd = [];
        foreach ($items as $it) {
            if (!in_array($it['action'], ['update', 'hide'], true)) continue;
            $u = $it['upd'];
            if (!empty($it['features']) && is_string($it['brand_ref'] ?? null)) $u['brand_id'] = $brandId($it['brand_ref']);
            if ($u || !empty($it['relink']) || !empty($it['features']) || !empty($it['texts']) || !empty($it['photo'])) $u['updated_at'] = $now;
            if ($u) $upd[(int) $it['id']] = $u;
        }
        $this->bulkUpdate($upd);

        // 4. категории: у найденных — отвязать от всех и привязать к категории по таблице (change_product_category)
        $unlink = []; $link = [];
        foreach ($items as $it) {
            if (!$it['id']) continue;
            if ($it['action'] === 'create') $link[] = ['category_id' => (int) $it['cat_id'], 'product_id' => (int) $it['id'], 'sort' => 0];
            elseif (!empty($it['relink'])) { $unlink[] = (int) $it['id']; $link[] = ['category_id' => (int) $it['cat_id'], 'product_id' => (int) $it['id'], 'sort' => 0]; }
        }
        if ($unlink) { [$ph, $v] = $db->in($unlink); $db->query("DELETE FROM category_products WHERE product_id IN ($ph)", $v); }
        $db->insertMany('category_products', $link, true, 500);

        // 5. характеристики: у новых — все, у найденных (change_product_attributes) — удалить эти и вставить заново
        $want = [];
        foreach ($items as $i => $it) {
            if (!$it['id'] || empty($it['features']) || !in_array($it['action'], ['create', 'update'], true)) continue;
            foreach ($it['feat'] as $fid => $f) if ($fid !== $this->brandFeature) $want[$fid][$f['value']] = 1;
        }
        $vid = $this->valueIds($want);
        $del = []; $ins = []; $filterCats = [];
        foreach ($items as $it) {
            if (!$it['id'] || empty($it['features']) || !in_array($it['action'], ['create', 'update'], true)) continue;
            if ($it['action'] === 'update') foreach (array_keys($this->attrIds()) as $fid) $del[$fid][] = (int) $it['id'];
            foreach ($it['feat'] as $fid => $f) {
                $val = $fid === $this->brandFeature ? $brandId($it['brand_ref'] ?? null) : ($vid[$fid][self::vkey($f['value'])] ?? null);
                if ($val) $ins[] = ['product_id' => (int) $it['id'], 'feature_id' => (int) $fid, 'value_id' => (int) $val];
            }
            $filterCats[(int) ($it['cat_id'] ?? $it['ex']['category_id'] ?? 0)] = 1;
        }
        foreach ($del as $fid => $pids) {
            [$ph, $v] = $db->in($pids);
            $db->query("DELETE FROM product_features WHERE feature_id = ? AND product_id IN ($ph)", array_merge([$fid], $v));
        }
        $db->insertMany('product_features', $ins, true, 1000);

        // 6. описания
        foreach ($items as $it) {
            if (!$it['id'] || empty($it['texts']) || !in_array($it['action'], ['create', 'update'], true)) continue;
            $db->query('INSERT INTO product_texts (product_id, description) VALUES (?, ?) ON DUPLICATE KEY UPDATE description = VALUES(description)', [(int) $it['id'], $it['texts']['description']]);
        }

        // 7. фото: строка product_images → файл оригинала (путь как в Webasyst) → главное фото товара
        foreach ($items as $i => &$it) {
            if (!$it['id'] || empty($it['photo']) || !isset($photos[$i]['file'])) continue;
            $pid = (int) $it['id'];
            if ($it['action'] === 'update') {                                // замена: старые строки и (после COMMIT) их файлы
                foreach ($it['ex']['images'] as $iid => $ext) $it['drop_files'][] = [$pid, (int) $iid, $ext];
                $db->query('DELETE FROM product_images WHERE product_id = ?', [$pid]);
            }
            $p = $photos[$i];
            $iid = $db->insert('product_images', ['product_id' => $pid, 'sort' => 0, 'ext' => 'jpg', 'width' => min(65535, $p['w']), 'height' => min(65535, $p['h']),
                'filename' => '', 'description' => null, 'created_at' => $now]);
            $dst = Image::originalPath($pid, $iid, 'jpg');
            @mkdir(dirname($dst), 0775, true);
            if (!@copy($p['file'], $dst)) throw new \RuntimeException('Не удалось сохранить фото в ' . dirname($dst) . ' — проверьте права на public/wa-data');
            $moved[] = $dst;
            $db->update('products', ['image_id' => $iid, 'image_ext' => 'jpg'], 'id = ?', [$pid]);
            $it['stored'][] = $iid;
        }
        unset($it);
        // новый товар без фото (add_new_products_withoutimage = true) — как есть; с фото, которое не сохранилось, — не бывает (исключение выше)

        // 8. связи «код цвета → товар» и встреченные товары (для скрытия отсутствующих)
        $links = [];
        foreach ($items as $it) {
            if (!$it['id'] || !in_array($it['action'], ['create', 'update', 'same', 'hide'], true)) continue;
            $links[] = [JongGolf::CODE, $it['code'], (int) $it['id'], mb_substr($it['ext'], 0, 64), (int) ($it['link_set'] ?? 0), $now, $now];
        }
        foreach (array_chunk($links, 300) as $part) {
            $db->query('INSERT INTO supplier_links (supplier, code, product_id, ext_id, hidden, created_at, updated_at) VALUES '
                . implode(',', array_fill(0, count($part), '(?,?,?,?,?,?,?)'))
                . ' ON DUPLICATE KEY UPDATE product_id = VALUES(product_id), ext_id = VALUES(ext_id), hidden = VALUES(hidden), updated_at = VALUES(updated_at)', array_merge(...$part));
        }
        $this->seen($items);

        // 9. фильтр категории (как старый загрузчик: пустой — «price» + характеристики товаров)
        if ($filterCats && $this->cfg['category_filter'] !== 'never') $this->categoryFilters(array_keys(array_filter($filterCats, static fn($v, $k) => $k > 0, ARRAY_FILTER_USE_BOTH)));

        // 10. индекс каталога — точечно для изменённых и новых (новые — в снимке «пустыми»)
        $changed = [];
        foreach ($items as $it) if ($it['id'] && in_array($it['action'], ['create', 'update', 'hide'], true)) $changed[] = (int) $it['id'];
        if ($changed) {
            $snap['ids'] = array_values(array_unique(array_merge($snap['ids'], $changed)));
            CatalogIndexer::products($changed, $snap, false);              // кэш — один раз в конце запуска
        }
    }

    /** UPDATE products SET col = CASE id WHEN … END — пачкой по 200 */
    private function bulkUpdate(array $upd): void
    {
        if (!$upd) return;
        $db = App::db();
        foreach (array_chunk($upd, 200, true) as $part) {
            $cols = [];
            foreach ($part as $id => $u) foreach ($u as $c => $v) $cols[$c][$id] = $v;
            $set = []; $params = [];
            foreach ($cols as $c => $vals) {
                if (!in_array($c, self::UPDATABLE, true)) continue;
                $sql = "`$c` = CASE id";
                foreach ($vals as $id => $v) { $sql .= ' WHEN ? THEN ?'; $params[] = (int) $id; $params[] = is_float($v) ? (string) round($v, 2) : $v; }
                $set[] = $sql . " ELSE `$c` END";
            }
            if (!$set) continue;
            [$ph, $ids] = $db->in(array_keys($part));
            $db->query('UPDATE products SET ' . implode(', ', $set) . " WHERE id IN ($ph)", array_merge($params, $ids));
        }
    }

    /** id характеристик из настройки (без «Бренда» — у него значения = бренды) */
    private function attrIds(): array
    {
        $out = [];
        foreach ($this->attr as $f) $out[(int) $f['id']] = 1;
        return $out;
    }

    /** Ключ значения характеристики: раскодированное (значение сайта «Шкіра &amp;amp; текстиль» = «Шкіра & текстиль» поставщика) */
    private static function vkey(string $v): string
    {
        return Importer::nk(JongGolf::name($v));
    }

    /**
     * id значений характеристик: существующее — без учёта регистра и лишних пробелов («велюр» = «Велюр»,
     * «60% PU,\n 40% Тканина» = «60% PU, 40% Тканина» — фильтр не раздваивается), новое — создаётся в конце списка.
     * @return [fid => [ключ значения => id]]
     */
    private function valueIds(array $want): array
    {
        $db = App::db();
        $out = [];
        foreach ($want as $fid => $vals) {
            if (!isset($this->values[$fid])) {
                $this->values[$fid] = [];
                foreach ($db->all('SELECT id, value FROM feature_values WHERE feature_id = ? ORDER BY id', [$fid]) as $r) $this->values[$fid][self::vkey((string) $r['value'])] ??= (int) $r['id'];
            }
            foreach (array_keys($vals) as $val) {
                $k = self::vkey((string) $val);
                if (!isset($this->values[$fid][$k])) {
                    $clean = mb_substr(trim((string) preg_replace('/\s+/u', ' ', (string) $val)), 0, 255);
                    $this->values[$fid][$k] = AdminCatalog::valueId((int) $fid, $clean);
                    JongGolf::log($this->runId, 'info', 'Новое значение характеристики: ' . $this->featureName((int) $fid) . ' = «' . $clean . '»');
                }
                $out[$fid][$k] = $this->values[$fid][$k];
            }
        }
        return $out;
    }

    private function featureName(int $fid): string
    {
        foreach ($this->attr as $f) if ((int) $f['id'] === $fid) return (string) $f['name'];
        return '#' . $fid;
    }

    private function categoryFilters(array $cats): void
    {
        if (!$cats) return;
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

    /** Встреченные коды и товары запуска (для скрытия отсутствующих и повторов) */
    private function seen(array $items): void
    {
        $rows = [];
        foreach ($items as $it) {
            if (!in_array($it['action'], ['create', 'update', 'same', 'hide'], true)) continue;
            $rows[$it['code']] = [$this->runId, $it['code'], $it['id'] ? (int) $it['id'] : null];
        }
        foreach (array_chunk(array_values($rows), 500) as $part) {
            App::db()->query('INSERT IGNORE INTO supplier_run_seen (run_id, code, product_id) VALUES ' . implode(',', array_fill(0, count($part), '(?,?,?)')), array_merge(...$part));
        }
    }

    // ================================================================== отчёт

    /** Строки отчёта и счётчики по итогам порции (как write_to_log старого загрузчика) */
    private function report(array $items): void
    {
        $will = $this->dry ? 'Будет ' : '';
        foreach ($items as $it) {
            $a = $it['action'];
            if ($a === 'skip') {
                $this->stats['skipped']++;
                $why = $it['why'] ?? 'other';
                if (in_array($why, ['absent', 'noadd'], true)) { $this->skipCount($why, 'Пропущен ' . $it['aid'] . ': ' . self::SKIP[$why]); continue; }
                $this->stats['skip'][$why] = ($this->stats['skip'][$why] ?? 0) + 1;
                $level = $it['level'] ?? 'skip';
                if ($level === 'error') $this->stats['errors']++;
                if ($level === 'warn') $this->stats['warnings']++;
                JongGolf::log($this->runId, $level === 'error' ? 'error' : ($level === 'warn' ? 'warn' : 'skip'), $it['msg'] !== '' ? $it['msg'] : 'Пропущен ' . $it['aid'] . ': ' . (self::SKIP[$why] ?? $why),
                    $it['ex']['id'] ?? null, $this->data($it));
                continue;
            }
            $price = ' Цена=' . self::num($it['price']) . ($it['compare'] > 0 ? ' Старая цена=' . self::num($it['compare']) : '');
            $warn = !empty($it['warn']) ? ' (' . implode('; ', $it['warn']) . ')' : '';
            if (!empty($it['warn'])) $this->stats['warnings']++;
            $this->stats['photos'] += count($it['stored'] ?? []);
            switch ($a) {
                case 'create':
                    $this->stats['created']++;
                    JongGolf::log($this->runId, 'add', ($this->dry ? 'Будет добавлен' : 'Добавлен id=' . $it['id']) . ' sku=' . $it['size'] . ' «' . $it['row']['name'] . '» id-поставщика=' . $it['ext']
                        . ' в категорию ' . ($this->ci['path'][$it['cat_id']] ?? $it['cat_id']) . $price . ($this->dry ? '' : ' добавлено ' . count($it['stored'] ?? []) . ' фото') . $warn, $it['id'] ?: null, $this->data($it));
                    break;
                case 'update':
                    $this->stats['updated']++;
                    JongGolf::log($this->runId, 'update', $will . ($this->dry ? 'изменён' : 'Изменён') . ' id=' . $it['id'] . ' sku=' . $it['size'] . ' «' . $it['ex']['name'] . '» id-поставщика=' . $it['ext'] . $price
                        . ': ' . implode('; ', $it['changes']) . $warn . self::via($it), (int) $it['id'], $this->data($it));
                    break;
                case 'hide':
                    $this->stats['hidden_color']++;
                    JongGolf::log($this->runId, 'hide', $will . ($this->dry ? 'скрыт' : 'Скрыт') . ' id=' . $it['id'] . ' «' . $it['ex']['name'] . '» id-поставщика=' . $it['ext'] . ' — цвет закончился у поставщика (на складе 0): '
                        . implode('; ', $it['changes']) . self::via($it), (int) $it['id'], $this->data($it));
                    break;
                case 'same':
                    $this->stats['same']++;
                    JongGolf::log($this->runId, 'same', 'Без изменений id=' . $it['id'] . ' «' . $it['ex']['name'] . '»' . $price . $warn . self::via($it), (int) $it['id'], $this->data($it));
                    break;
            }
        }
    }

    private static function via(array $it): string
    {
        return match ($it['via']) { 'name' => ' [найден по названию]', 'code' => ' [найден по коду, взят под загрузчик]', default => '' };
    }

    private static function num(float $v): string
    {
        return rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');
    }

    /** Подробности строки для таблицы пробного прогона и сверки: что будет у товара после записи */
    private function data(array $it): array
    {
        $ex = $it['ex'];
        $cat = $it['action'] === 'create' || !empty($it['relink']) ? $it['cat_id'] : ($ex['category_id'] ?? $it['cat_id']);
        $feat = [];
        foreach ($it['feat'] as $f) $feat[$f['name']] = $f['value'];
        $u = $it['upd'] + ($it['row'] ?? []);
        return [
            'a' => $it['action'], 'pid' => $it['id'] ?: ($ex['id'] ?? null), 'code' => $it['aid'], 'ext' => $it['ext'], 'via' => $it['via'],
            'name' => $it['row']['name'] ?? ($u['name'] ?? ($ex['name'] ?? $it['supname'])), 'url' => ($it['row']['url'] ?? '') ?: ($ex['url'] ?? ($it['url_base'] ?? '')),
            'cat_id' => $cat !== null ? (int) $cat : null, 'category' => $cat !== null ? ($this->ci['path'][(int) $cat] ?? '') : '', 'map_key' => $it['mkey'],
            'price' => $it['price'], 'compare' => $it['compare'], 'box' => $it['action'] === 'create' ? $it['box'] : (int) ($ex['box_qty'] ?? $it['box']), 'inbox' => $it['box'],
            'status' => (int) ($u['status'] ?? ($ex['status'] ?? 0)), 'in_stock' => (int) ($u['in_stock'] ?? ($ex['in_stock'] ?? 0)),
            'brand' => $it['brand'], 'size' => $it['size'], 'feat' => $feat, 'changes' => $it['changes'], 'photo' => !empty($it['photo']), 'why' => $it['why'] ?? null,
            'season' => $it['season'], 'supcat' => $it['cat'], 'gender' => $it['gender'],
        ];
    }

    // ================================================================== завершение

    /**
     * «Полное обновление» (replace_all_products): товары поставщика, которых не было в выгрузке, — скрыть
     * (старый загрузчик их физически удалял вместе с фото). Только если получена вся очередь и в запуске был хоть один
     * найденный или новый товар (как «добавлено > 0 или изменено > 0» у старого). Защита: больше половины активных
     * товаров поставщика — не скрываем (похоже на неполную выгрузку). В конце — сброс кэша сайта.
     * $alive() — запуск ещё «Идёт» (не остановлен кнопкой): проверяется прямо перед скрытием.
     */
    public function finish(bool $complete, ?callable $alive = null): void
    {
        $db = App::db();
        $s = &$this->stats;
        $touched = $s['created'] + $s['updated'] + $s['same'] + $s['hidden_color'];
        if ($complete && $alive && !$alive()) {
            JongGolf::log($this->runId, 'info', 'Запуск остановлен — скрытие отсутствующих у поставщика не выполнялось');
        } elseif (!$complete) {
            JongGolf::log($this->runId, 'info', 'Скрытие отсутствующих у поставщика не выполнялось: обработана не вся очередь поставщика');
        } elseif ($this->cfg['full_update'] !== '1') {
            JongGolf::log($this->runId, 'info', '«Полное обновление» выключено — товары, которых нет у поставщика, не скрываются');
        } elseif ($touched === 0) {
            JongGolf::log($this->runId, 'info', 'Ни один товар не найден и не добавлен — скрытие отсутствующих не выполняется (как у старого загрузчика)');
        } else {
            $missing = $db->pairs('SELECT p.id, p.name FROM products p LEFT JOIN supplier_run_seen s ON s.run_id = ? AND s.product_id = p.id
                WHERE p.supplier = ? AND p.status = 1 AND s.product_id IS NULL ORDER BY p.id', [$this->runId, JongGolf::CODE]);
            $active = (int) $db->value('SELECT COUNT(*) FROM products WHERE supplier = ? AND status = 1', [JongGolf::CODE]);
            if ($missing && count($missing) > $active * 0.5) {
                $s['warnings']++;
                $s['hide_cancelled'] = count($missing);
                JongGolf::log($this->runId, 'warn', 'Скрытие отменено: пришлось бы скрыть ' . count($missing) . ' из ' . $active . ' активных товаров ' . JongGolf::TITLE
                    . ' — похоже, поставщик отдал неполную выгрузку. Проверьте отчёт; товары остались как были.');
            } elseif ($missing) {
                $ids = array_map('intval', array_keys($missing));
                if (!$this->dry) {
                    CatalogIndexer::locked(static function () use ($db, $ids): void {
                        $db->transaction(static function () use ($db, $ids): void {
                            $snap = CatalogIndexer::snapshot($ids);
                            foreach (array_chunk($ids, 500) as $part) {
                                [$ph, $v] = $db->in($part);
                                $db->query("UPDATE products SET status = 0, updated_at = NOW() WHERE id IN ($ph)", $v);
                                $db->query("UPDATE supplier_links SET hidden = 1, updated_at = NOW() WHERE supplier = ? AND product_id IN ($ph)", array_merge([JongGolf::CODE], $v));
                            }
                            CatalogIndexer::products($ids, $snap, false);
                        });
                    });
                }
                foreach ($missing as $pid => $name) {
                    JongGolf::log($this->runId, 'hide', ($this->dry ? 'Будет скрыт' : 'Скрыт') . ' product_id=' . $pid . ' «' . $name . '» — сведения о нём у поставщика не получены', (int) $pid,
                        ['a' => 'hide_missing', 'pid' => (int) $pid, 'name' => $name]);
                }
                $s['hidden_missing'] = count($ids);
            } else {
                JongGolf::log($this->runId, 'info', 'Все активные товары ' . JongGolf::TITLE . ' есть у поставщика — скрывать нечего');
            }
        }
        if ($s['unmapped']) {
            arsort($s['unmapped']);
            $s['unmapped'] = array_slice($s['unmapped'], 0, 300, true);
            JongGolf::log($this->runId, 'warn', 'Несопоставленные сочетания (сезон|категория|пол|ряд — цветов): ' . implode('; ', array_map(static fn($k, $n) => $k . ' — ' . $n,
                array_keys(array_slice($s['unmapped'], 0, 30, true)), array_slice($s['unmapped'], 0, 30, true))) . (count($s['unmapped']) > 30 ? '; …' : ''));
        }
        if (!$this->dry && ($s['created'] + $s['updated'] + $s['hidden_color'] + $s['hidden_missing']) > 0) Cache::flush();
    }
}
