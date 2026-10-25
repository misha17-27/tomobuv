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
 * Общая часть (транзакция порции, бренды, значения характеристик, фото, пакетный UPDATE) — SupplierSync.
 */
final class JongGolfSync extends SupplierSync
{
    use JongGolfShared;

    protected const SUPPLIER = JongGolf::class;
    private const PORTION = 20;                 // товаров поставщика (≈80 цветов) за транзакцию
    private const EX_COLS = 'id, url, name, name_uk, sku, supplier, supplier_code, category_id, brand_id, price, compare_price, price_manual, nophoto_hidden, box_qty, min_qty, size,
        stock, in_stock, status, image_id, image_ext, meta_title, meta_description, meta_keywords, meta_title_uk, meta_description_uk, meta_keywords_uk';
    private const CHANGE_NAMES = ['price' => 'цена', 'compare_price' => 'старая цена', 'status' => 'на сайте', 'in_stock' => 'наличие', 'stock' => 'остаток',
        'sku' => 'артикул (размерный ряд)', 'supplier' => 'поставщик', 'supplier_code' => 'код поставщика', 'category_id' => 'категория', 'name' => 'название',
        'name_uk' => 'название (укр.)', 'size' => 'размер', 'brand_id' => 'бренд', 'meta_title' => 'SEO title', 'meta_description' => 'SEO description', 'meta_keywords' => 'SEO keywords'];

    /** Причины пропуска (статистика запуска) */
    public const SKIP = [
        'status' => 'товар неактивен у поставщика (status ≠ 1)', 'offdel' => 'снят поставщиком (action_type off/del)',
        'brand' => 'бренд в списке пропускаемых', 'category_skip' => 'категория в списке пропускаемых', 'nocolors' => 'нет цветов (images)',
        'absent' => 'цвет закончился, товара на сайте нет', 'excluded' => 'удалён на сайте вручную — снова не добавляется', 'noadd' => 'новые товары не добавляются (настройка)',
        'category' => 'категория не сопоставлена (новый товар)', 'size' => 'нет размерного ряда', 'price' => 'цена 0',
        'nophoto' => 'новый товар без фото', 'photo' => 'фото не скачалось', 'manual' => 'есть товар, заведённый вручную',
        'dup' => 'повтор кода цвета', 'ambiguous' => 'код совпал с несколькими товарами', 'foreign' => 'эта модель уже на сайте от «Хит обуви» — не дублируется',
    ];

    private array $dict;
    private array $prices = [];
    private int $sizeFeature = 0;
    private array $skipBrands = [];
    private array $skipCats = [];

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
        // транзакция apply() под блокировкой индекса (ждать — не дольше LOCK_WAIT и остатка бюджета шага), повтор при взаимной блокировке
        if (!$this->transact($items, $photos, $deadline)) return $defer();     // фото не удаляются — пригодятся следующему шагу
        $done = true;
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
        $raw = $price;                                                      // цена поставщика за пару до коэффициента (история цен «Обзора»)
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
                'cost' => $raw, 'old_cost' => null, 'returned' => '', 'hide_note' => '',
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

    // ================================================================== поиск существующих товаров

    /** Поиск товаров сайта для цветов страницы (общий с сайтом бренда — JongGolfShared::lookup) */
    private function match(array &$items): void
    {
        $this->lookup($items, self::EX_COLS, $this->cfg['update_features'] === '1', $this->cfg['update_description'] === '1');
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
            $it['old_cost'] = $it['link']['cost'] ?? null;                   // цена поставщика в прошлый раз (история цен «Обзора»)
            $keepSku = JongGolf::code((string) $ex['sku']) === $it['code'];      // артикул = код (товар заведён вручную) — не затираем размерным рядом
            $upd = [];
            if ($it['cstatus'] !== 1) {
                // цвет закончился: товар скрыт, нет в наличии (старый загрузчик: status = 0, count = 0)
                $it['hide_note'] = 'цвет закончился у поставщика';
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
                if ($status === 1 && (int) $ex['status'] === 0 && (int) ($it['link']['hidden'] ?? 0) === 1) $it['returned'] = 'цвет снова есть у поставщика — показан (был скрыт загрузчиком)';
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
                    if ($it['size'] !== '') $upd['size'] = mb_substr($it['size'], 0, 64);
                    $it['brand_ref'] = $this->brandRef($it['brand'], $it['brand_raw']);
                    if (is_int($it['brand_ref'])) $upd['brand_id'] = $it['brand_ref'];
                    elseif ($it['brand_ref'] === null) $upd['brand_id'] = null;
                    $it['features'] = !$this->sameFeatures($it, $ex);           // переписываются, только если отличаются
                }
                if ($c['update_description'] === '1' && trim($it['desc']) !== '') {
                    $html = HtmlSanitizer::supplier($it['desc']);
                    if (trim((string) ($ex['desc'] ?? '')) !== trim($html)) $it['texts'] = ['description' => $html];
                }
                // фото: нет главного (или его строки / файла) либо «Заменить имеющиеся фото»
                $main = $ex['image_id'] !== null && isset($ex['images'][(int) $ex['image_id']]);
                if ($main && $c['photo_check_file'] === '1' && !is_file(Image::originalPath((int) $ex['id'], (int) $ex['image_id'], (string) ($ex['image_ext'] ?: 'jpg')))) $main = false;
                if ($it['url'] !== '' && (!$main || $c['photo_replace'] === '1')) $it['photo'] = true;
                elseif (!$main && $it['url'] === '') $it['warn'][] = 'поставщик не задал фото';
                $it['action'] = 'update';
            }
            // только отличающиеся поля; цена закреплена владельцем (price_manual) — цену и старую цену не менять
            if (!empty($ex['price_manual'])) unset($upd['price'], $upd['compare_price']);
            // скрыт, потому что нет фото (NoPhoto) — показывает его NoPhoto, когда фото появится, а не сверка
            if (!empty($ex['nophoto_hidden']) && (int) ($upd['status'] ?? 0) === 1) unset($upd['status']);
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
        if ($this->excluded((string) $it['code'])) { $this->skip($it, 'excluded', ''); return; }
        if ($it['cat_id'] === null) { $this->skip($it, 'category', 'Ошибка: категория «' . $it['mkey'] . '» не обработана (нет в таблице соответствия) — ' . $it['aid'] . ' не добавлен', 'error'); return; }
        if ($it['size'] === '') { $this->skip($it, 'size', 'Ошибка: для ' . $it['aid'] . ' id-поставщика=' . $it['ext'] . ' не задана размерная сетка', 'error'); return; }
        if ($it['price'] <= 0) { $this->skip($it, 'price', 'Ошибка: цена 0 — ' . $it['aid'] . ' id-поставщика=' . $it['ext'] . ' не добавлен', 'error'); return; }
        // та же модель (код + ряд) уже на сайте от «Хит обуви» (берёт Jong•Golf с фото с 25.10.2026) — не дублируется
        if (($k = \App\Services\LegacyProducts::ownKey('x ' . $it['aid'], (string) $it['aid'], (string) $it['size'])) !== null && isset($this->ownModels([HitObuvi::CODE])[$k])) {
            $this->skip($it, 'foreign', ''); return;
        }
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

    // ================================================================== запись порции

    /** Всё в открытой транзакции (под блокировкой индекса). $moved — перенесённые файлы фото (удаляются при откате) */
    protected function apply(array &$items, array $photos, array &$moved): void
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
        // «Заменить имеющиеся фото»: скачанное после холста — тот же файл, что главное фото товара: замены нет (иначе каждый проход
        // заменял бы фото и сдвигал updated_at — и у товара с несколькими фото, и доп. фото пропадали бы); не осталось изменений — «без изменений»
        foreach ($items as $i => &$it) {
            if ($it['action'] === 'update' && !empty($it['photo']) && isset($photos[$i]['file']) && $it['ex']['image_id'] !== null
                && isset($it['ex']['images'][(int) $it['ex']['image_id']])) {
                $orig = Image::originalPath((int) $it['ex']['id'], (int) $it['ex']['image_id'], (string) ($it['ex']['image_ext'] ?: 'jpg'));
                if (is_file($orig) && md5_file($orig) === md5_file((string) $photos[$i]['file'])) {
                    $it['photo'] = false;
                    $it['changes'] = array_values(array_diff($it['changes'], ['фото']));
                }
            }
            if ($it['action'] === 'update' && !$it['upd'] && empty($it['relink']) && empty($it['features']) && empty($it['texts']) && empty($it['photo'])) $it['action'] = 'same';
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
            $links[] = [JongGolf::CODE, $it['code'], (int) $it['id'], mb_substr($it['ext'], 0, 64), (int) ($it['link_set'] ?? 0), $now, $it['cost'] > 0 ? round((float) $it['cost'], 2) : null, $now, $now];
        }
        foreach (array_chunk($links, 300) as $part) {
            $db->query('INSERT INTO supplier_links (supplier, code, product_id, ext_id, hidden, seen_at, cost, created_at, updated_at) VALUES '
                . implode(',', array_fill(0, count($part), '(?,?,?,?,?,?,?,?,?)'))
                . ' ON DUPLICATE KEY UPDATE product_id = VALUES(product_id), ext_id = VALUES(ext_id), hidden = VALUES(hidden), seen_at = VALUES(seen_at), cost = VALUES(cost), updated_at = VALUES(updated_at)', array_merge(...$part));
        }
        $this->seen($items);
        $this->journal($items, $now);                                       // «Обзор»: добавленные, цены, скрытые, вернувшиеся

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
                if (in_array($why, ['absent', 'noadd', 'excluded'], true)) { $this->skipCount($why, 'Пропущен ' . $it['aid'] . ': ' . self::SKIP[$why]); continue; }
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
                    CatalogIndexer::locked(function () use ($db, $ids): void {
                        $db->transaction(function () use ($db, $ids): void {
                            $snap = CatalogIndexer::snapshot($ids);
                            $codes = [];
                            foreach (array_chunk($ids, 500) as $part) {
                                [$ph, $v] = $db->in($part);
                                $db->query("UPDATE products SET status = 0, updated_at = NOW() WHERE id IN ($ph)", $v);
                                $db->query("UPDATE supplier_links SET hidden = 1, updated_at = NOW() WHERE supplier = ? AND product_id IN ($ph)", array_merge([JongGolf::CODE], $v));
                                $codes += $db->pairs("SELECT product_id, code FROM supplier_links WHERE supplier = ? AND product_id IN ($ph)", array_merge([JongGolf::CODE], $v));
                            }
                            foreach ($ids as $pid) $codes[$pid] ??= '';
                            $this->journalHidden($codes, 'нет в полной выгрузке поставщика');
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
