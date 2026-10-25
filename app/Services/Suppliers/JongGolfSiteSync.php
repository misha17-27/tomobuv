<?php
declare(strict_types=1);

namespace App\Services\Suppliers;

use App\Core\App;
use App\Core\Image;
use App\Services\AdminCatalog;
use App\Services\CatalogIndexer;
use App\Services\HtmlSanitizer;
use App\Services\ProductName;

/**
 * Цвета Jong•Golf с официального сайта бренда (www.jonggolf.com) — порциями (общая часть — WebSync / SupplierSync, общие с API
 * правила — JongGolfShared). Цвет бренда (модель × цвет, aid «B40678-3») = товар сайта, связь supplier_links (jonggolf, code = код
 * цвета, ext_id = id модели — тот же, что у API: источник можно переключать без перепривязки).
 *
 * Поиск (как у API): связь → название старого загрузчика → код цвета в артикуле у заведённых вручную (кириллица = латиница);
 * товар, связанный с другим поставщиком, не находится. Неоднозначный — пропуск.
 * Найденный (взять заведённый вручную под загрузчик — «adopt_manual», по умолчанию да; название, адрес и SEO не меняются, если
 * не включено «менять название»): цена пары × наценка, старая цена (скидка бренда), закупочная — цена бренда, ящик, ряд (артикул-ряд
 * старого загрузчика), в наличии; скрытый загрузчиком или «нет в наличии» — снова показан и в наличии; категория — по настройке;
 * характеристики («обновлять характеристики») — только поля списка (пол, ряд, бренд, пар в ящике, цвет); фото — только товару без фото.
 * Цена — на цвет (у 50 из 193 многоцветных моделей цвета стоят по-разному).
 * Новый — только в наличии (бренд показывает только такие), с ценой, категорией (таблица → разделы бренда по рядам), рядом
 * и фото: название ProductName («Категория Jong•Golf Код», RU и UA), артикул — код цвета, бренд — из списка («Jong•Golf™» и пустой —
 * настройка «Бренд»), карточка модели (1 запрос на модель): материалы, повторные размеры, описание, доп. фото цвета; все фото → GD 700×700,
 * SEO — шаблоны сайта; не больше max_new за запуск.
 * Нет в полном проходе (цвета без остатка бренд не показывает): «нет в наличии» сразу, скрыт — после missing_runs проходов подряд (finish).
 */
final class JongGolfSiteSync extends WebSync
{
    use JongGolfShared;

    protected const SUPPLIER = JongGolf::class;
    private const EX_COLS = 'id, url, name, name_uk, sku, supplier, supplier_code, category_id, brand_id, price, compare_price, price_manual, nophoto_hidden, purchase_price, box_qty, min_qty, size,
        stock, in_stock, status, image_id, image_ext, meta_title, meta_description, meta_keywords, meta_title_uk, meta_description_uk, meta_keywords_uk';
    protected const UPDATABLE = ['name', 'name_uk', 'sku', 'supplier', 'supplier_code', 'category_id', 'brand_id', 'price', 'compare_price', 'purchase_price', 'box_qty', 'min_qty',
        'size', 'stock', 'in_stock', 'status', 'meta_title', 'meta_description', 'meta_keywords', 'meta_title_uk', 'meta_description_uk', 'meta_keywords_uk', 'updated_at'];
    protected const MASS_SKIP = ['dup', 'noadd', 'excluded', 'limit_new', 'brand', 'category_skip', 'cat_skip'];
    protected const CHANGE_NAMES = ['price' => 'цена', 'compare_price' => 'старая цена', 'purchase_price' => 'закупочная', 'status' => 'на сайте', 'in_stock' => 'наличие',
        'stock' => 'остаток', 'sku' => 'артикул (ряд)', 'size' => 'размер', 'box_qty' => 'пар в ящике', 'min_qty' => 'мин. заказ', 'supplier' => 'поставщик',
        'supplier_code' => 'код поставщика', 'category_id' => 'категория', 'name' => 'название', 'name_uk' => 'название (укр.)', 'brand_id' => 'бренд',
        'meta_title' => 'SEO title', 'meta_description' => 'SEO description', 'meta_keywords' => 'SEO keywords'];

    public const SKIP = [
        'brand' => 'бренд в списке пропускаемых', 'category_skip' => 'категория в списке пропускаемых', 'cat_skip' => 'раздел бренда не загружается (настройка)',
        'category' => 'категория не сопоставлена (новый товар)', 'size' => 'нет размерного ряда', 'price' => 'цена 0', 'box' => 'не определить пар в ящике',
        'nophoto' => 'новый товар без фото', 'photo' => 'фото не скачалось', 'excluded' => 'удалён на сайте вручную — снова не добавляется', 'noadd' => 'новые товары не добавляются (настройка)',
        'robots_photo' => 'фото бренда закрыты robots.txt — новый товар без фото не создаётся',
        'limit_new' => 'лимит новых товаров за запуск', 'nostock' => 'по карточке модели уже нет в наличии', 'dup' => 'повтор кода цвета',
        'ambiguous' => 'код совпал с несколькими товарами',
    ];
    public const VIA = ['link' => 'по связи', 'name' => 'по названию старого загрузчика', 'code' => 'по коду цвета в артикуле (заведён вручную)'];
    /** Поля настройки характеристик, которые есть у каждого цвета в списке (без карточки): «обновлять характеристики» найденных — только они */
    private const LIST_FIELDS = ['gender', 'size', 'brand', 'pairs', 'color'];
    /** Материалы из карточки модели: поле настройки → поле разбора карточки */
    private const CARD_FIELDS = ['material>outside' => 'outside', 'material>inside' => 'inside', 'material>bottom' => 'bottom', 'material>outsole' => 'outsole'];

    private array $skipBrands = [];
    private array $skipCats = [];
    /** Названия цветов по коду «-3» (справочник API, сохранённая копия product_setting.json): на сайте бренда — только код */
    private array $colors = [];

    public function __construct(int $runId, bool $dry, array &$stats, JongGolfClient $client)
    {
        $this->runId = $runId;
        $this->dry = $dry;
        $this->stats = &$stats;
        $this->client = $client;
        foreach (JongGolf::DEFAULTS as $k => $_) $this->cfg[$k] = JongGolf::cfg($k);
        [$this->attr] = JongGolf::attributes();
        $this->markup = JongGolf::markup();
        $this->brandFeature = AdminCatalog::featureId('brand');
        $this->skipBrands = JongGolf::lines('skip_brands');
        $this->skipCats = JongGolf::lines('skip_categories');
        $this->colors = self::colorNames();
    }

    /** Названия цветов справочника API: [«-3» => «Хаки»] (нет копии — пусто: характеристика «цвет» не заполняется) */
    public static function colorNames(): array
    {
        static $memo = null;
        if ($memo !== null) return $memo;
        $d = json_decode((string) @file_get_contents(JongGolf::dir() . '/product_setting.json'), true);
        $memo = [];
        foreach ((array) ($d['answer']['color'] ?? []) as $k => $c) if (is_array($c) && trim((string) ($c['name'] ?? '')) !== '') $memo[(string) $k] = Supplier::name((string) $c['name']);
        return $memo;
    }

    /** Бренд из списка: «Jong•Golf™» (у цветов без бренда) и пустой → бренд из настройки */
    public static function brand(string $s, string $default): string
    {
        $s = trim(str_replace(['™', '®'], '', Supplier::name($s)));
        return $s === '' || mb_strtolower($s) === 'не указано' ? $default : $s;
    }

    // ================================================================== порции

    /** Цвета порции: разбор, поиск товаров сайта (свежие строки), повторы, решение */
    protected function items(array $slice, array $meta): array
    {
        $items = [];
        foreach ($slice as $t) $items[] = $this->expand($t);
        $this->lookup($items, self::EX_COLS, $this->cfg['update_features'] === '1', false);
        $this->dedupe($items);
        foreach ($items as &$it) $this->decide($it);
        unset($it);
        return $items;
    }

    /** Цвет из списка бренда → строка для решения (цены с наценкой, ящик, категория, фото, характеристики списка) */
    private function expand(array $t): array
    {
        $aid = (string) $t['aid'];
        $brand = self::brand((string) $t['brand'], $this->cfg['brand'] ?: JongGolf::TITLE);
        $size = JongGolfMap::size((string) $t['size']);
        $pair = round((float) $t['pair'], 2);
        $old = (float) $t['old_pair'] > $pair ? (float) $t['old_pair'] : 0.0;
        $price = JongGolf::price($pair, $this->markup);
        $compare = $old > 0 && $pair > 0 ? JongGolf::price($old, $this->markup) : 0.0;
        if ($compare <= $price) $compare = 0.0;
        $box = (int) $t['inbox'];
        $box = $box >= 1 && $box <= 200 ? $box : null;
        $warn = [];
        if ($box && $pair > 0 && (float) $t['box_price'] > 0 && abs((float) $t['box_price'] - $pair * $box) > 1) $warn[] = 'у бренда цена ящика ' . self::num((float) $t['box_price']) . ' ≠ пара × ' . $box . ' — взята цена пары';
        $map = JongGolfMap::site((string) $t['season'], (string) $t['cat'], (string) $t['gender'], $size);
        $photo = JongGolfClient::url((string) $t['photo']) ?? '';
        $it = [
            'model' => (int) $t['model'], 'ext' => (string) (int) $t['model'], 'slug' => (string) $t['slug'], 'color' => (string) $t['color'],
            'aid' => $aid, 'code' => JongGolf::code($aid), 'name' => trim($t['type'] . ' ' . $aid), 'size' => mb_substr($size, 0, 64), 'box' => $box,
            'pair' => $pair, 'old_pair' => $old, 'price' => $price, 'compare' => $compare, 'cost' => $pair, 'old_cost' => null,
            'season' => (string) $t['season'], 'cat' => (string) $t['cat'], 'gender' => (string) $t['gender'], 'brand' => $brand, 'badges' => (array) $t['badges'],
            'map' => $map, 'cat_id' => $map['cat_id'], 'mkey' => $map['key'],
            'supname' => trim(JongGolfMap::uk((string) $t['cat']) . ' ' . $brand . ' ' . $aid),   // название старого загрузчика (категория API — по-украински)
            'url_src' => JongGolfClient::url((string) $t['card']) ?? '', 'photo_url' => $photo, 'photo_urls' => $photo !== '' ? [$photo] : [],
            'feat' => [], 'card' => false, 'stock' => true, 'stock_out' => false,
            'ex' => null, 'via' => '', 'link' => null, 'action' => '', 'why' => '', 'msg' => '', 'level' => 'skip', 'upd' => [], 'changes' => [], 'warn' => $warn,
            'id' => null, 'returned' => '', 'hide_note' => '',
        ];
        $it['feat'] = $this->features($it, null);
        if (isset($this->skipBrands[JongGolfMap::norm($brand)])) $this->skip($it, 'brand', '');
        elseif ($this->catSkipped($it)) $this->skip($it, 'category_skip', '');
        elseif ($map['skip']) $this->skip($it, 'cat_skip', '');
        return $it;
    }

    /** Категория в «Пропускаемых категориях» — русское или украинское название («Кросівки», «Літнє взуття|Кросівки») */
    private function catSkipped(array $it): bool
    {
        if (!$this->skipCats) return false;
        foreach ([[$it['season'], $it['cat']], [JongGolfMap::uk($it['season']), JongGolfMap::uk($it['cat'])]] as [$s, $c]) {
            if (isset($this->skipCats[JongGolfMap::norm($c)]) || isset($this->skipCats[JongGolfMap::norm($s . '|' . $c)])) return true;
        }
        return false;
    }

    /**
     * Характеристики по настройке: из списка — пол, ряд, бренд, пар в ящике, цвет; из карточки ($card) — материалы и повторные
     * размеры. Значения — по-русски (страница RU; украинские слова — SupplierSync::ruValue). @return [fid => ['name', 'value', 'field']]
     */
    private function features(array $it, ?array $card): array
    {
        $out = [];
        foreach ($this->attr as $field => $f) {
            $v = match ($field) {
                'gender' => $it['gender'], 'size' => $it['size'], 'brand' => $it['brand'],
                'pairs' => $it['box'] ? (string) $it['box'] : '',
                'color' => (string) ($this->colors[$it['color']] ?? ''),
                'repeat' => $card ? JongGolfParser::repeats((array) ($card['grid'] ?? [])) : '',
                default => $card && isset(self::CARD_FIELDS[$field]) ? (string) ($card['spec'][self::CARD_FIELDS[$field]] ?? '') : '',
            };
            $v = self::ruValue((string) $v);
            if ($v !== '') $out[(int) $f['id']] = ['name' => (string) $f['name'], 'value' => $v, 'field' => $field];
        }
        return $out;
    }

    /** id характеристик полей списка (для «обновлять характеристики» у найденных) */
    private function listFids(): array
    {
        $out = [];
        foreach ($this->attr as $field => $f) if (in_array($field, self::LIST_FIELDS, true)) $out[] = (int) $f['id'];
        return $out;
    }

    // ================================================================== решение

    private function decide(array &$it): void
    {
        if ($it['action'] === 'skip') return;
        $c = $this->cfg;
        $ex = $it['ex'];
        if ($it['via'] === 'ambiguous') { $this->skip($it, 'ambiguous', 'код ' . $it['aid'] . ' совпал с несколькими товарами без поставщика (' . $it['msg'] . ') — не изменены, новый не создан', 'warn'); return; }
        if ($ex) {
            $it['id'] = (int) $ex['id'];
            $ours = (string) $ex['supplier'] === JongGolf::CODE || $it['via'] === 'link';
            if (!$ours && $c['adopt_manual'] !== '1') { $it['action'] = 'existing'; return; }
            if ($it['pair'] <= 0) { $this->skip($it, 'price', 'у бренда цена 0: ' . $it['aid'] . ' — товар ID ' . $ex['id'] . ' не изменён', 'error'); return; }
            // цена бренда за пару в прошлом проходе (история цен «Обзора»): из связи, у взятого под загрузчик — закупочная в карточке
            $it['old_cost'] = $it['link']['cost'] ?? ((float) $ex['purchase_price'] > 0 ? (float) $ex['purchase_price'] : null);
            $hiddenByUs = (int) ($it['link']['hidden'] ?? 0) === 1;
            $missed = (int) ($it['link']['missed'] ?? 0);
            $manualHidden = (int) $ex['status'] === 0 && !$hiddenByUs && $it['via'] === 'link';
            $status = $c['keep_manual_hidden'] === '1' && $manualHidden ? 0 : 1;
            if ($status === 0) $it['warn'][] = 'скрыт вручную — оставлен скрытым (настройка)';
            if ($status === 1 && (int) $ex['status'] === 0 && $hiddenByUs) $it['returned'] = 'снова есть у бренда — показан (был скрыт загрузчиком)';
            elseif ($status === 1 && $missed > 0) $it['returned'] = 'снова есть у бренда (не было ' . $missed . ' полн. проходов)';
            // в наличии у бренда — без ограничения остатка (stock NULL), как у других загрузчиков
            $upd = ['price' => $it['price'], 'compare_price' => $it['compare'], 'purchase_price' => $it['pair'], 'in_stock' => 1, 'stock' => null, 'status' => $status,
                'supplier' => JongGolf::CODE, 'supplier_code' => $it['ext']];
            // артикул: = код цвета (заведён вручную, новый с сайта) — не трогаем; пустой — код цвета; иначе (ряд старого загрузчика) — ряд бренда
            if (trim((string) $ex['sku']) === '') $upd['sku'] = mb_substr($it['aid'], 0, 255);
            elseif ($it['size'] !== '' && JongGolf::code((string) $ex['sku']) !== $it['code']) $upd['sku'] = $it['size'];
            if ($it['box'] && (int) $ex['box_qty'] !== (int) $it['box']) {
                $upd['box_qty'] = (int) $it['box'];
                if ((int) $ex['min_qty'] === (int) $ex['box_qty'] || (int) $ex['min_qty'] <= 1) $upd['min_qty'] = (int) $it['box'];
            }
            if ($it['cat_id'] !== null && $c['update_category'] === '1' && ((int) $ex['category_id'] !== $it['cat_id'] || $ex['cats'] !== [$it['cat_id']])) {
                $upd['category_id'] = $it['cat_id'];
                $it['relink'] = true;
            }
            if ($it['cat_id'] === null && !$it['map']['skip']) $it['warn'][] = 'категория «' . $it['mkey'] . '» не сопоставлена — привязка не изменена';
            if ($c['update_name'] === '1') {
                [$name, $nameUk] = $this->names($it, $it['cat_id'] ?? ((int) $ex['category_id'] ?: null));
                $upd += ['name' => mb_substr($name, 0, ProductName::MAX), 'name_uk' => $nameUk !== null ? mb_substr($nameUk, 0, ProductName::MAX) : null] + $this->metas($name, $nameUk);
            }
            if ($c['update_features'] === '1') {
                // характеристики списка (пол, ряд, бренд, пар, цвет) — карточку найденного не открываем: материалы не трогаем
                if ($it['size'] !== '') $upd['size'] = $it['size'];
                $it['brand_ref'] = $this->brandRef($it['brand']);
                if (is_int($it['brand_ref'])) $upd['brand_id'] = $it['brand_ref'];
                $it['feat_fids'] = $this->listFids();
                $it['features'] = !$this->sameFeatures($it, $ex, $it['feat_fids']);
            }
            // фото — только если у товара нет главного (или его файла — «проверять файл») и фото можно получить (robots.txt бренда):
            // главное из списка, доп. фото цвета — из карточки модели («доп. фото», 1 запрос на модель)
            $main = $ex['image_id'] !== null && isset($ex['images'][(int) $ex['image_id']]);
            if ($main && $c['photo_check_file'] === '1' && !is_file(Image::originalPath((int) $ex['id'], (int) $ex['image_id'], (string) ($ex['image_ext'] ?: 'jpg')))) $main = false;
            if (!$main && $it['photo_url'] !== '' && $this->client->obtainable($it['photo_url'])) {
                $it['photo'] = true;
                $it['photo_urls'] = [$it['photo_url']];
                if ($c['more_photos'] === '1' && $it['url_src'] !== '') { $it['card'] = true; $it['card_photos'] = true; }
            } elseif (!$main && $it['photo_url'] !== '') {
                $this->stats['photo_robots'] = (int) ($this->stats['photo_robots'] ?? 0) + 1;   // найденный без фото: фото закрыто robots.txt
            }
            $this->diff($it, $ex, $upd);
            if (!empty($it['relink']) && !isset($it['upd']['category_id'])) $it['changes'][] = 'привязка к категории → ' . ($this->ci['path'][$it['cat_id']] ?? $it['cat_id']);
            if (!empty($it['features'])) $it['changes'][] = 'характеристики';
            if (!empty($it['photo'])) $it['changes'][] = 'фото';
            $it['link_set'] = (int) ($it['upd']['status'] ?? $ex['status']) === 1 ? 0 : ($hiddenByUs ? 1 : 0);
            $it['action'] = $it['upd'] || !empty($it['relink']) || !empty($it['features']) || !empty($it['photo']) ? 'update' : 'same';
            return;
        }
        // нового товара на сайте нет (бренд показывает только цвета в наличии — «нет в наличии» новыми не бывают)
        if ($c['add_new'] !== '1') { $this->skip($it, 'noadd', ''); return; }
        if ($this->excluded((string) $it['code'])) { $this->skip($it, 'excluded', ''); return; }
        if ($it['cat_id'] === null) { $this->skip($it, 'category', 'категория «' . $it['mkey'] . '» не сопоставлена — ' . $it['aid'] . ' не добавлен (вкладка «Категории»)', 'warn'); return; }
        if ($it['size'] === '') { $this->skip($it, 'size', 'нет размерного ряда — ' . $it['aid'] . ' не добавлен', 'warn'); return; }
        if ($it['pair'] <= 0) { $this->skip($it, 'price', 'цена 0 — ' . $it['aid'] . ' не добавлен', 'error'); return; }
        if (!$it['box']) { $this->skip($it, 'box', 'не определить пар в ящике — ' . $it['aid'] . ' не добавлен', 'warn'); return; }
        if (!$it['photo_urls'] && $c['add_without_photo'] !== '1') { $this->skip($it, 'nophoto', 'нет фото — ' . $it['aid'] . ' не добавлен', 'warn'); return; }
        if ($it['photo_urls'] && !$this->client->obtainable($it['photo_urls'][0])) {
            // фото бренда закрыты robots.txt (public.jonggolf.com: «Disallow: …/product…» закрывает /files/products/) — не запрашиваем
            $this->stats['photo_robots'] = (int) ($this->stats['photo_robots'] ?? 0) + 1;
            if ($c['add_without_photo'] !== '1') { $this->skip($it, 'robots_photo', 'фото ' . $it['aid'] . ' закрыто robots.txt бренда — новый товар без фото не создаётся'
                . ' (настройка «Добавлять без фото» или разрешение бренда в robots.txt)' . $this->priceText($it)); return; }
            $it['photo_urls'] = [];
            $it['warn'][] = 'фото закрыто robots.txt бренда — добавлен без фото (фото добавится, когда бренд разрешит)';
        }
        if ((int) $c['max_new'] > 0 && (int) $this->stats['created'] + $this->plannedNew >= (int) $c['max_new']) { $this->skip($it, 'limit_new', ''); return; }
        $this->plannedNew++;
        $it['brand_ref'] = $this->brandRef($it['brand']);
        [$name, $nameUk] = $this->names($it, $it['cat_id']);
        $name = mb_substr($name, 0, ProductName::MAX);
        $nameUk = $nameUk !== null ? mb_substr($nameUk, 0, ProductName::MAX) : null;
        $now = date('Y-m-d H:i:s');
        $it['row'] = [
            'url' => '', 'name' => $name, 'name_uk' => $nameUk, 'sku' => mb_substr($it['aid'], 0, 255), 'category_id' => $it['cat_id'],
            'brand_id' => is_int($it['brand_ref']) ? $it['brand_ref'] : null, 'price' => $it['price'], 'compare_price' => $it['compare'], 'purchase_price' => $it['pair'],
            'box_qty' => (int) $it['box'], 'min_qty' => (int) $it['box'], 'size' => $it['size'], 'stock' => null, 'in_stock' => 1, 'status' => 1,
            'supplier' => JongGolf::CODE, 'supplier_code' => mb_substr($it['ext'], 0, 100), 'created_at' => $now, 'updated_at' => $now,
        ] + $this->metas($name, $nameUk);
        $it['url_base'] = substr(Supplier::translit($name), 0, 240) ?: 'product';
        $it['features'] = true;
        $it['photo'] = $it['photo_urls'] !== [];
        $it['card'] = $c['card_features'] === '1' && $it['url_src'] !== '';
        $it['link_set'] = 0;
        $it['action'] = 'create';
    }

    // ================================================================== карточки новых

    private function cardFile(array $it): string
    {
        return JongGolf::dir($this->runId) . '/card-' . preg_replace('~[^a-z0-9_-]~', '_', strtolower((string) $it['slug'])) . '.json';
    }

    /** Нужна карточка модели: новому цвету (характеристики, описание, доп. фото) или найденному без фото (только доп. фото) */
    private function needsCard(array $it): bool
    {
        return !empty($it['card']) && ($it['action'] === 'create' || ($it['action'] === 'update' && !empty($it['card_photos']) && !empty($it['photo'])));
    }

    protected function netCost(array $it): int
    {
        return $this->needsCard($it) && !is_file($this->cardFile($it)) ? 1 : 0;
    }

    /**
     * Карточки моделей новых цветов (1 запрос на модель — у её цветов общая): материалы, повторные размеры, описание, доп. фото цвета;
     * разбор — в папке запуска (отложенная порция и другие цвета модели не качают снова). Карточки нет (404, нет среди сохранённых) —
     * товар создаётся без них, предупреждение; по карточке цвета уже нет в наличии (снята с продажи) — не создаётся. Найденному
     * товару без фото карточка даёт только доп. фото цвета. Доп. фото, закрытые robots.txt бренда, не берутся.
     * @return bool false — время шага кончилось (порция откладывается)
     */
    protected function enrich(array &$items, float $deadline): bool
    {
        $first = true;
        foreach ($items as &$it) {
            if (!$this->needsCard($it)) continue;
            $f = $this->cardFile($it);
            if (!is_file($f)) {
                if (!$first && microtime(true) > $deadline - 4.0) return false;
                $first = false;
                $r = $this->client->get($it['url_src'], false, true);
                $card = !empty($r['missing']) ? ['error' => 'нет среди сохранённых ответов'] : ($r['code'] !== 200 ? ['error' => 'ответ ' . $r['code']] : (JongGolfParser::card($r['body']) ?? ['error' => 'не похоже на карточку модели']));
                file_put_contents($f, json_encode($card, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            }
            $card = json_decode((string) file_get_contents($f), true) ?: ['error' => 'не читается'];
            $create = $it['action'] === 'create';
            if (isset($card['error'])) { $it['warn'][] = 'карточка модели не получена (' . $card['error'] . ')' . ($create ? ' — без материалов и описания' : ' — без доп. фото'); continue; }
            if ($create && (!empty($card['off']) || ($card['colors'] && !isset($card['colors'][$it['color']])))) {
                $this->skip($it, 'nostock', 'по карточке модели ' . $it['aid'] . ' уже нет в наличии у бренда' . (!empty($card['off']) ? ' (модель снята с продажи)' : '') . ' — не добавлен');
                continue;
            }
            if ($create) {
                $model = (string) ($card['spec']['model'] ?? '');
                if ($model !== '' && JongGolf::code($model) !== (string) preg_replace('~-\d+$~', '', $it['code'])) $it['warn'][] = 'в карточке артикул «' . $model . '», в списке «' . $it['aid'] . '»';
                $it['feat'] = $this->features($it, $card);
                if (trim((string) ($card['description'] ?? '')) !== '') $it['texts'] = ['description' => HtmlSanitizer::supplier((string) $card['description'])];
            }
            if ($this->cfg['more_photos'] === '1' && $it['photo_urls']) {
                foreach ((array) ($card['extra'][$it['color']] ?? []) as $u) {
                    if (($abs = JongGolfClient::url((string) $u)) === null || in_array($abs, $it['photo_urls'], true)) continue;
                    if ($this->client->obtainable($abs)) $it['photo_urls'][] = $abs;
                    else $this->stats['photo_robots_extra'] = (int) ($this->stats['photo_robots_extra'] ?? 0) + 1;   // доп. фото закрыто robots.txt
                }
            }
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
            if ($it['action'] === 'create' && $this->cfg['add_without_photo'] !== '1') { $this->skip($it, 'photo', 'не получено фото ' . $it['photo_url'] . ' (' . $err . ') — «' . $it['row']['name'] . '» не добавлен', 'error'); continue; }
            $it['photo'] = false;
            $it['warn'][] = 'фото не загружено: ' . $err;
            $it['changes'] = array_values(array_diff($it['changes'], ['фото']));
            if ($it['action'] === 'update' && !$it['upd'] && empty($it['relink']) && empty($it['features'])) $it['action'] = 'same';
        }
        unset($it);
        foreach ($items as $i => &$it) foreach ($photos[$i]['lost'] ?? [] as $l) $it['warn'][] = 'доп. фото не загружено: ' . $l;
        unset($it);
        $upIds = [];
        foreach ($items as $it) if ($it['action'] === 'update') $upIds[] = (int) $it['id'];
        $snap = CatalogIndexer::snapshot($upIds);

        // 1. новые бренды (id бренда = id значения характеристики «Бренд»)
        $need = [];
        foreach ($items as $it) if (is_string($it['brand_ref'] ?? null) && ($it['action'] === 'create' || !empty($it['features']))) $need[substr($it['brand_ref'], 4)] = 1;
        $newBrand = $this->createBrands($need);
        $brandId = static fn($ref) => is_string($ref) ? ($newBrand[substr($ref, 4)] ?? null) : $ref;

        // 2. новые товары: адрес — транслит названия, свободный
        $creates = array_keys(array_filter($items, static fn($it) => $it['action'] === 'create'));
        if ($creates) {
            $this->allocateUrls($items, $creates);
            foreach ($creates as $i) {
                $items[$i]['row']['created_at'] = $items[$i]['row']['updated_at'] = $now;   // время записи (порция могла ждать фото)
                $items[$i]['row']['brand_id'] = $brandId($items[$i]['brand_ref'] ?? null);
            }
            $db->insertMany('products', array_map(static fn($i) => $items[$i]['row'], $creates), false, 200);
            [$ph, $v] = $db->in(array_map(static fn($i) => $items[$i]['row']['url'], $creates));
            $ids = $db->pairs("SELECT url, id FROM products WHERE url IN ($ph)", $v);
            foreach ($creates as $i) $items[$i]['id'] = (int) ($ids[$items[$i]['row']['url']] ?? 0);
        }

        // 3. изменения найденных — только отличающиеся поля (diff), updated_at — только у реально изменённых
        $upd = [];
        foreach ($items as $it) {
            if ($it['action'] !== 'update') continue;
            $u = $it['upd'];
            if (!empty($it['features']) && is_string($it['brand_ref'] ?? null)) $u['brand_id'] = $brandId($it['brand_ref']);
            if ($u || !empty($it['relink']) || !empty($it['features']) || !empty($it['photo'])) $u['updated_at'] = $now;
            if ($u) $upd[(int) $it['id']] = $u;
        }
        $this->bulkUpdate($upd);

        // 4. категории: новые — по таблице; найденные («менять категорию») — отвязать от прежних и привязать
        $unlink = []; $link = []; $filterCats = [];
        foreach ($items as $it) {
            if (!$it['id']) continue;
            if ($it['action'] === 'create') { $link[] = ['category_id' => (int) $it['cat_id'], 'product_id' => (int) $it['id'], 'sort' => 0]; $filterCats[(int) $it['cat_id']] = 1; }
            elseif ($it['action'] === 'update' && !empty($it['relink'])) { $unlink[] = (int) $it['id']; $link[] = ['category_id' => (int) $it['cat_id'], 'product_id' => (int) $it['id'], 'sort' => 0]; $filterCats[(int) $it['cat_id']] = 1; }
        }
        if ($unlink) { [$ph, $v] = $db->in($unlink); $db->query("DELETE FROM category_products WHERE product_id IN ($ph)", $v); }
        $db->insertMany('category_products', $link, true, 500);

        // 5. характеристики: новые — все поля настройки (список и карточка); найденные («обновлять характеристики») — поля списка
        $want = [];
        foreach ($items as $it) {
            if (!$it['id'] || empty($it['features']) || !in_array($it['action'], ['create', 'update'], true)) continue;
            foreach ($it['feat'] as $fid => $f) if ($fid !== $this->brandFeature) $want[$fid][$f['value']] = 1;
        }
        $vid = $this->valueIds($want);
        $del = []; $ins = [];
        foreach ($items as $it) {
            if (!$it['id'] || empty($it['features']) || !in_array($it['action'], ['create', 'update'], true)) continue;
            $only = $it['action'] === 'update' ? ($it['feat_fids'] ?? []) : null;
            if ($only !== null) foreach ($only as $fid) $del[$fid][] = (int) $it['id'];
            foreach ($it['feat'] as $fid => $f) {
                if ($only !== null && !in_array((int) $fid, $only, true)) continue;
                $val = (int) $fid === $this->brandFeature ? $brandId($it['brand_ref'] ?? null) : ($vid[$fid][self::vkey($f['value'])] ?? null);
                if ($val) $ins[] = ['product_id' => (int) $it['id'], 'feature_id' => (int) $fid, 'value_id' => (int) $val];
            }
            $filterCats[(int) ($it['cat_id'] ?? $it['ex']['category_id'] ?? 0)] = 1;
        }
        foreach ($del as $fid => $pids) {
            [$ph, $v] = $db->in($pids);
            $db->query("DELETE FROM product_features WHERE feature_id = ? AND product_id IN ($ph)", array_merge([$fid], $v));
        }
        $db->insertMany('product_features', $ins, true, 1000);

        // 6. описания новых (из карточки модели; у бренда — только по-русски)
        foreach ($items as $it) {
            if (!$it['id'] || empty($it['texts']) || $it['action'] !== 'create') continue;
            $db->query('INSERT INTO product_texts (product_id, description) VALUES (?, ?) ON DUPLICATE KEY UPDATE description = VALUES(description)', [(int) $it['id'], $it['texts']['description']]);
        }

        // 7. фото: все (главное — первое)
        foreach ($items as $i => &$it) {
            if (!$it['id'] || empty($it['photo']) || !isset($photos[$i]['file']) || !in_array($it['action'], ['create', 'update'], true)) continue;
            $this->storePhotos($it, $photos[$i], $moved, $now);
        }
        unset($it);

        // 8. связи «цвет бренда → товар сайта»: встречен у бренда — не пропал (missed = 0), скрытый загрузчиком — показан;
        //    цена бренда за пару (cost) — для истории цен «Обзора»
        $links = [];
        foreach ($items as $it) {
            if (!$it['id'] || !in_array($it['action'], ['create', 'update', 'same'], true)) continue;
            $links[] = [JongGolf::CODE, $it['code'], (int) $it['id'], mb_substr($it['ext'], 0, 64), (int) ($it['link_set'] ?? 0), $now, $it['pair'] > 0 ? round($it['pair'], 2) : null, $now, $now];
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
            $db->query("UPDATE supplier_links SET missed = 0, seen_at = ? WHERE supplier = ? AND code IN ($ph)", array_merge([$now, JongGolf::CODE], $v));
        }
        $this->seen($items);
        $this->journal($items, $now);                                       // «Обзор»: добавленные, цены, вернувшиеся, прочие изменения

        // 9. фильтры категорий (как старый загрузчик) и индекс каталога — точечно (новые — в снимке «пустыми»)
        $this->categoryFilters(array_values(array_filter(array_keys($filterCats), static fn($k) => $k > 0)));
        $changed = [];
        foreach ($items as $it) if ($it['id'] && in_array($it['action'], ['create', 'update'], true)) $changed[] = (int) $it['id'];
        if ($changed) {
            $snap['ids'] = array_values(array_unique(array_merge($snap['ids'], $changed)));
            CatalogIndexer::products($changed, $snap, false);
        }
    }

    // ================================================================== пропавшие

    /**
     * Связанные товары, которых нет в полном проходе (бренд не показывает цвета без остатка), ещё не скрываемые: на сайте —
     * «нет в наличии» (in_stock = 0, остаток 0, цена прежняя), журнал out; вернётся в список — снова в наличии (journal back).
     */
    protected function missingStock(array $rows): int
    {
        $out = [];
        foreach ($rows as $r) if ((int) $r['status'] === 1 && (int) $r['in_stock'] === 1) $out[(int) $r['product_id']] = $r;
        if (!$out) return 0;
        $n = max(1, (int) $this->cfg['missing_runs']);
        if (!$this->dry) {
            $ids = array_keys($out);
            CatalogIndexer::locked(function () use ($ids, $out): void {
                $db = App::db();
                $db->transaction(function () use ($db, $ids, $out): void {
                    $snap = CatalogIndexer::snapshot($ids);
                    foreach (array_chunk($ids, 500) as $part) {
                        [$ph, $v] = $db->in($part);
                        $db->query("UPDATE products SET in_stock = 0, stock = 0, updated_at = NOW() WHERE id IN ($ph) AND status = 1 AND in_stock = 1", $v);
                    }
                    $now = date('Y-m-d H:i:s');
                    $rows = [];
                    foreach ($out as $pid => $r) {
                        $rows[] = ['supplier' => JongGolf::CODE, 'run_id' => $this->runId ?: null, 'product_id' => $pid, 'code' => mb_substr((string) $r['code'], 0, 100), 'kind' => 'out',
                            'old_cost' => null, 'new_cost' => null, 'old_price' => null, 'new_price' => null, 'note' => 'нет в списке бренда (нет в наличии)', 'created_at' => $now];
                    }
                    static::record($rows);
                    CatalogIndexer::products($ids, $snap, false);
                });
            });
        }
        foreach (array_slice($out, 0, 500, true) as $pid => $r) {
            JongGolf::log($this->runId, 'update', ($this->dry ? 'Будет «нет в наличии»' : '«Нет в наличии»') . ' id=' . $pid . ' «' . $r['name'] . '» (' . $r['code'] . ') — нет в списке бренда'
                . ' (цвета без остатка бренд не показывает); скрыть — если не будет ' . $n . ' полных проходов подряд', $pid, ['a' => 'out', 'pid' => $pid, 'code' => $r['code'], 'name' => $r['name'], 'status' => 1, 'in_stock' => 0]);
        }
        $this->stats['out'] = (int) ($this->stats['out'] ?? 0) + count($out);
        return count($out);
    }

    // ================================================================== отчёт

    /** Подробности строки отчёта (таблица запуска): что будет у товара после записи */
    protected function data(array $it): array
    {
        $ex = $it['ex'];
        $cat = $it['action'] === 'create' || !empty($it['relink']) ? $it['cat_id'] : ($ex['category_id'] ?? $it['cat_id']);
        $feat = [];
        foreach ($it['feat'] as $f) $feat[$f['name']] = $f['value'];
        return [
            'a' => $it['action'], 'pid' => $it['id'] ?: ($ex['id'] ?? null), 'code' => $it['aid'], 'ext' => $it['ext'], 'via' => $it['via'], 'why' => $it['why'] ?: null,
            'name' => $it['row']['name'] ?? ($it['upd']['name'] ?? ($ex['name'] ?? $it['name'])), 'name_uk' => $it['row']['name_uk'] ?? null,
            'url' => ($it['row']['url'] ?? '') ?: ($ex['url'] ?? ($it['url_base'] ?? '')), 'supurl' => $it['url_src'],
            'cat_id' => $cat !== null ? (int) $cat : null, 'category' => $cat !== null ? ($this->ci['path'][(int) $cat] ?? '') : '', 'map_key' => $it['mkey'], 'map_via' => $it['map']['via'],
            'price' => $it['price'], 'purchase' => $it['pair'], 'compare' => $it['compare'], 'old_price' => $ex !== null ? (float) $ex['price'] : null, 'box' => $it['box'],
            'status' => (int) ($it['upd']['status'] ?? ($ex['status'] ?? 1)), 'in_stock' => (int) ($it['upd']['in_stock'] ?? ($ex !== null ? $ex['in_stock'] : 1)),
            'brand' => $it['brand'], 'size' => $it['size'], 'season' => $it['season'], 'supcat' => $it['cat'], 'gender' => $it['gender'], 'badges' => $it['badges'],
            'feat' => $feat, 'changes' => $it['changes'], 'photo' => $it['photo_url'], 'photos' => count($it['photo_urls']),
        ];
    }
}
