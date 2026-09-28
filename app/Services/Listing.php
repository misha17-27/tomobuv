<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\App;
use App\Core\Cache;
use App\Core\Lang;
use App\Core\Paginator;
use App\Core\Seo;

/**
 * Списки товаров витрины: категория, бренд, поиск, избранное и просмотренные.
 *
 * Адреса — в формате старого сайта (Webasyst):
 *   ?page=N  ?sort=create_datetime|price|name&order=asc|desc  ?price_min=&price_max=
 *   ?{код характеристики}[]={id значения} — например ?brand[]=40&size[]=123
 *   ?view=table — табличный вид для оптовиков.
 * Страницы с параметрами-массивами ({код}[]) PageCache не кэширует и из кэша не отдаёт —
 * отфильтрованная страница никогда не подменяется закэшированной страницей без фильтров.
 *
 * Правило производительности: сначала id по индексу (catalog_index / products),
 * потом Products::cards($ids) одним запросом. total без фильтров — из счётчика
 * (categories.product_count / brands.product_count), с фильтрами — COUNT с кэшем.
 */
final class Listing
{
    public const PER_PAGE = 30;                 // как на старом сайте
    public const MAX_FAV = 200;
    public const MAX_VIEWED = 30;
    /** Значения цвета в Webasyst — отдельная таблица; при переносе их id сдвинуты на 5 000 000 */
    private const COLOR_OFFSET = 5000000;
    /** Сортировки посетителя: код → направление по умолчанию */
    public const SORTS = ['create_datetime' => 'desc', 'price' => 'asc', 'name' => 'asc'];
    /** Имена GET-параметров, которые не могут быть кодами характеристик */
    private const RESERVED = ['page', 'sort', 'order', 'view', 'price_min', 'price_max', 'query', 'q', 'f', 'letter', 'in_stock', '_balance_type'];

    public string $base;
    public string $sort;
    public string $order;
    public string $defaultSort;
    public string $defaultOrder;
    public bool $sortSet = false;          // сортировка выбрана посетителем
    public string $view = 'grid';
    public ?float $priceMin = null;
    public ?float $priceMax = null;
    /** Выбранные значения фильтров: [feature_id => [value_id в базе, …]] */
    public array $features = [];
    public int $page = 1;                  // запрошенная страница (до ограничения Paginator)
    public int $perPage = self::PER_PAGE;
    /** Постоянные параметры адреса (query для поиска, _balance_type) */
    public array $extra = [];

    public array $ids = [];
    public int $total = 0;
    public ?Paginator $pager = null;
    /** Запрошена страница за концом списка (?page=5 при 1 странице): как на старом сайте — 200 и пустой список */
    public bool $outOfRange = false;

    /** Разобрать параметры текущего запроса */
    public static function fromRequest(string $base, string $defaultSort = 'create_datetime', string $defaultOrder = 'desc',
        array $extra = [], bool $withFilters = true): self
    {
        $l = new self();
        $l->base = $base;
        $l->extra = $extra;
        $l->defaultSort = $defaultSort;
        $l->defaultOrder = $defaultOrder;
        $l->sort = $defaultSort;
        $l->order = $defaultOrder;

        $s = self::str('sort');
        if (isset(self::SORTS[$s])) {
            $o = self::str('order');
            $l->sort = $s;
            $l->order = in_array($o, ['asc', 'desc'], true) ? $o : self::SORTS[$s];
            $l->sortSet = !($l->sort === $defaultSort && $l->order === $defaultOrder);
        }
        $l->view = self::str('view') === 'table' ? 'table' : 'grid';
        $p = $_GET['page'] ?? 1;
        $l->page = (is_scalar($p) && ctype_digit((string) $p)) ? max(1, min(100000, (int) $p)) : 1;

        if ($withFilters) {
            $l->priceMin = self::num('price_min');
            $l->priceMax = self::num('price_max');
            if ($l->priceMin !== null && $l->priceMax !== null && $l->priceMin > $l->priceMax) {
                [$l->priceMin, $l->priceMax] = [$l->priceMax, $l->priceMin];
            }
            foreach (self::filterFeatures() as $fid => $f) {
                $raw = $_GET[$f['code']] ?? null;
                if ($raw === null || $raw === '' || $raw === []) continue;
                $vals = [];
                foreach (array_slice(is_array($raw) ? $raw : [$raw], 0, 50) as $v) {
                    if (is_scalar($v) && ctype_digit((string) $v) && (int) $v > 0 && (int) $v < self::COLOR_OFFSET) {
                        $vals[(int) $v + $f['offset']] = 1;
                    }
                }
                if ($vals) {
                    $ids = array_keys($vals);
                    sort($ids);
                    $l->features[(int) $fid] = $ids;
                }
            }
            ksort($l->features);
        }
        return $l;
    }

    private static function str(string $k): string
    {
        $v = $_GET[$k] ?? '';
        return is_scalar($v) ? strtolower(trim((string) $v)) : '';
    }

    private static function num(string $k): ?float
    {
        $v = $_GET[$k] ?? '';
        if (!is_scalar($v)) return null;
        $v = str_replace([' ', ','], ['', '.'], trim((string) $v));
        return ($v !== '' && is_numeric($v) && (float) $v >= 0) ? min((float) $v, 10000000) : null;
    }

    /** Характеристики, по которым можно фильтровать: [id => ['id','code','name','type','offset']] */
    public static function filterFeatures(): array
    {
        static $out = null;
        if ($out === null) {
            $out = [];
            foreach (Catalog::features() as $f) {
                if (!(int) $f['is_filter'] || in_array($f['code'], self::RESERVED, true) || !preg_match('/^[a-z][a-z0-9_]{0,63}$/', (string) $f['code'])) continue;
                $out[(int) $f['id']] = [
                    'id' => (int) $f['id'], 'code' => (string) $f['code'], 'name' => (string) $f['name'], 'type' => (string) $f['type'],
                    'offset' => $f['type'] === 'color' ? self::COLOR_OFFSET : 0,
                ];
            }
        }
        return $out;
    }

    // ------------------------------------------------------------------ состояние и адреса

    public function hasFeatureFilters(): bool
    {
        return $this->features !== [];
    }

    public function hasFilters(): bool
    {
        return $this->features !== [] || $this->priceMin !== null || $this->priceMax !== null;
    }

    /** Отличается от «чистого» адреса (фильтры, сортировка, вид) — такие страницы noindex */
    public function isModified(): bool
    {
        return $this->hasFilters() || $this->sortSet || $this->view !== 'grid';
    }

    /** Ключ набора фильтров (для кэша COUNT) */
    public function filterKey(): string
    {
        return json_encode([$this->priceMin, $this->priceMax, $this->features]);
    }

    /**
     * Параметры адреса. $o — изменения: page, sort, order, view,
     * price => false (убрать цену), remove => [fid, vid] (убрать значение), filters => false (сбросить все фильтры).
     */
    public function params(array $o = []): array
    {
        $q = $this->extra;
        $features = $this->features;
        $pmin = $this->priceMin;
        $pmax = $this->priceMax;
        if (($o['filters'] ?? true) === false) { $features = []; $pmin = $pmax = null; }
        if (($o['price'] ?? true) === false) { $pmin = $pmax = null; }
        if (isset($o['remove'])) {
            [$rf, $rv] = $o['remove'];
            $features[$rf] = array_values(array_diff($features[$rf] ?? [], [$rv]));
            if (!$features[$rf]) unset($features[$rf]);
        }
        $all = self::filterFeatures();
        foreach ($features as $fid => $vals) {
            if (!isset($all[$fid])) continue;
            $q[$all[$fid]['code']] = array_map(static fn($v) => $v - $all[$fid]['offset'], $vals);
        }
        if ($pmin !== null) $q['price_min'] = self::fmt($pmin);
        if ($pmax !== null) $q['price_max'] = self::fmt($pmax);
        $sort = $o['sort'] ?? ($this->sortSet ? $this->sort : null);
        $order = $o['order'] ?? ($this->sortSet ? $this->order : null);
        if ($sort !== null && !($sort === $this->defaultSort && $order === $this->defaultOrder)) {
            $q['sort'] = $sort;
            $q['order'] = $order;
        }
        $view = $o['view'] ?? $this->view;
        if ($view === 'table') $q['view'] = 'table';
        $page = (int) ($o['page'] ?? 1);
        if ($page > 1) $q['page'] = $page;
        return $q;
    }

    public function url(array $o = []): string
    {
        return self::build($this->base, $this->params($o));
    }

    /** Строка запроса: массивы — в формате Webasyst (brand%5B%5D=40&brand%5B%5D=41) */
    public static function build(string $base, array $q): string
    {
        $parts = [];
        foreach ($q as $k => $v) {
            if (is_array($v)) {
                foreach ($v as $x) $parts[] = rawurlencode((string) $k) . '%5B%5D=' . rawurlencode((string) $x);
            } elseif ($v !== null && $v !== '') {
                $parts[] = rawurlencode((string) $k) . '=' . rawurlencode((string) $v);
            }
        }
        return $base . ($parts ? '?' . implode('&', $parts) : '');
    }

    private static function fmt(float $v): string
    {
        return rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');
    }

    /** Значение фильтра для адреса (id без сдвига цвета) */
    public static function urlValue(int $fid, int $dbValue): int
    {
        $f = self::filterFeatures()[$fid] ?? null;
        return $f ? $dbValue - $f['offset'] : $dbValue;
    }

    /** Варианты сортировки для списка: [['label', 'url', 'on']] */
    public function sortOptions(bool $withDefault = false): array
    {
        $opts = [];
        if ($withDefault) $opts[] = [t('По умолчанию'), $this->defaultSort, $this->defaultOrder];
        foreach ([[t('Сначала новые'), 'create_datetime', 'desc'], [t('Сначала старые'), 'create_datetime', 'asc'],
            [t('Сначала дешёвые'), 'price', 'asc'], [t('Сначала дорогие'), 'price', 'desc'], [t('По названию'), 'name', 'asc']] as $x) {
            if ($withDefault && $x[1] === $this->defaultSort && $x[2] === $this->defaultOrder) continue;
            $opts[] = $x;
        }
        $out = [];
        foreach ($opts as [$label, $s, $o]) {
            $out[] = ['label' => $label, 'url' => $this->url(['sort' => $s, 'order' => $o, 'view' => $this->view]),
                'on' => $this->sort === $s && $this->order === $o];
        }
        return $out;
    }

    // ------------------------------------------------------------------ выборки

    /** Пагинатор; false — запрошена несуществующая страница (список пуст, outOfRange) */
    private function paginate(int $total): bool
    {
        $this->total = $total;
        $this->pager = new Paginator($total, $this->perPage, $this->page);
        $this->outOfRange = $this->page > $this->pager->pages;
        return !$this->outOfRange;
    }

    /** Номер страницы из адреса (для SEO: на старом сайте ?page=5 за концом списка — « | Страница 5») */
    public function pageNo(): int
    {
        return $this->outOfRange ? $this->page : $this->pager->page;
    }

    /** Условия фильтров: [sql, params]; $idCol/$priceCol — только внутренние константы */
    private function whereSql(string $idCol, string $priceCol): array
    {
        $w = '';
        $p = [];
        if ($this->priceMin !== null) { $w .= " AND $priceCol >= ?"; $p[] = $this->priceMin; }
        if ($this->priceMax !== null) { $w .= " AND $priceCol <= ?"; $p[] = $this->priceMax; }
        foreach ($this->features as $fid => $vals) {
            [$ph, $v] = App::db()->in($vals);
            $w .= " AND $idCol IN (SELECT pf.product_id FROM product_features pf WHERE pf.feature_id = ? AND pf.value_id IN ($ph))";
            $p[] = (int) $fid;
            array_push($p, ...$v);
        }
        return [$w, $p];
    }

    /**
     * ORDER BY из белого списка. $t: 'ci' — catalog_index, 'p' — products.
     * При равных значениях — по id по возрастанию: так упорядочивает старый сайт (сверено с живым).
     * $rid: вместо «ci.product_id ASC» после DESC — «ci.product_rid DESC» (= 4294967295 − product_id, тот же порядок):
     * одно направление читается по индексу by_new / by_price_rid без сортировки всей категории (database/migrations/perf.sql).
     */
    private function orderSql(string $t, bool $rid = true): string
    {
        $d = $this->order === 'asc' ? 'ASC' : 'DESC';
        if ($t === 'ci') {
            $tie = $rid && $d === 'DESC' ? 'ci.product_rid DESC' : 'ci.product_id ASC';
            return match ($this->sort) {
                'price' => "ci.price $d, $tie",
                'name' => "ci.name $d, ci.product_id ASC",
                'sort' => "ci.sort $d, ci.product_id ASC",
                'edit_datetime' => "p.updated_at $d, ci.product_id ASC",
                default => $d === 'DESC' ? "ci.in_stock DESC, ci.created_at DESC, $tie" : 'ci.created_at ASC, ci.product_id ASC',
            };
        }
        return match ($this->sort) {
            'price' => "p.price $d, p.id ASC",
            'name' => "p.name $d, p.id ASC",
            default => "p.created_at $d, p.id ASC",
        };
    }

    /**
     * Товары категории из catalog_index (с учётом подкатегорий и динамических категорий).
     * Возвращает false, если страница за пределами списка.
     */
    public function runCategory(array $cat): bool
    {
        $cid = (int) $cat['id'];
        [$w, $p] = $this->whereSql('ci.product_id', 'ci.price');
        // «по дате изменения» (как у «Акции») — через products; для больших категорий — по дате добавления
        if ($this->sort === 'edit_datetime' && (int) $cat['product_count'] > 20000) $this->sort = 'create_datetime';
        $join = $this->sort === 'edit_datetime' ? ' JOIN products p ON p.id = ci.product_id' : '';
        $run = fn(bool $rid) => $this->fetchPage('ci.product_id', 'catalog_index ci' . $join, 'ci.category_id = ?' . $w, array_merge([$cid], $p),
            $this->orderSql('ci', $rid), $this->hasFilters() ? null : (int) $cat['product_count'], 'listing.cnt.c' . $cid);
        try {
            return $run(true);
        } catch (\PDOException $e) {
            // база без database/migrations/perf.sql (нет колонки product_rid) — прежний порядок с сортировкой
            if (!str_contains($e->getMessage(), 'product_rid')) throw $e;
            return $run(false);
        }
    }

    /** Товары бренда (индекс products.brand: brand_id, status, created_at) */
    public function runBrand(array $brand): bool
    {
        $bid = (int) $brand['id'];
        [$w, $p] = $this->whereSql('p.id', 'p.price');
        return $this->fetchPage('p.id', 'products p', 'p.brand_id = ? AND p.status = 1' . $w, array_merge([$bid], $p),
            $this->orderSql('p'), $this->hasFilters() ? null : (int) $brand['product_count'], 'listing.cnt.b' . $bid);
    }

    /**
     * Страница id и общее количество. Без фильтров количество известно (счётчик в категории/бренде),
     * с фильтрами — COUNT(*) с кэшем на хеш фильтра (30 мин). Отдельный COUNT быстрее SQL_CALC_FOUND_ROWS:
     * тот отключает сортировку «первых N» и сортирует все подходящие строки (на 57 тыс. — в 4 раза дольше).
     * $select/$from/$where/$order — только внутренние строки, значения — плейсхолдеры.
     */
    private function fetchPage(string $select, string $from, string $where, array $params, string $order, ?int $total, string $cntKey): bool
    {
        $db = App::db();
        if ($total === null) {
            $total = (int) Cache::remember($cntKey . '.' . md5($this->filterKey()), 1800,
                static fn() => (int) $db->value("SELECT COUNT(*) FROM $from WHERE $where", $params));
        }
        if (!$this->paginate($total)) return false;
        if ($total === 0) return true;
        $this->ids = array_map('intval', $db->col("SELECT $select FROM $from WHERE $where ORDER BY $order LIMIT "
            . $this->perPage . ' OFFSET ' . $this->pager->offset, $params));
        return true;
    }

    /** Результаты поиска: полный упорядоченный список id берётся из кэша (10 мин), страница — срез */
    public function runSearch(string $query): bool
    {
        $all = self::searchIds($query, $this->sort, $this->order);
        if (!$this->paginate(count($all))) return false;
        $this->ids = array_slice($all, $this->pager->offset, $this->perPage);
        return true;
    }

    /** Список id в заданном порядке (избранное, просмотренные): только опубликованные товары */
    public function runIds(array $ids): bool
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn($x) => $x > 0)));
        if ($ids) {
            [$ph, $v] = App::db()->in($ids);
            $ok = array_flip(array_map('intval', App::db()->col("SELECT id FROM products WHERE id IN ($ph) AND status = 1", $v)));
            $ids = array_values(array_filter($ids, static fn($x) => isset($ok[$x])));
        }
        if (!$this->paginate(count($ids))) return false;
        $this->ids = array_slice($ids, $this->pager->offset, $this->perPage);
        return true;
    }

    /** id из cookie «fav»/«viewed»: только числа через запятую */
    public static function cookieIds(string $name, int $max): array
    {
        $raw = $_COOKIE[$name] ?? '';
        if (!is_string($raw) || $raw === '') return [];
        $out = [];
        foreach (explode(',', $raw) as $x) {
            $x = trim($x);
            if ($x !== '' && ctype_digit($x) && strlen($x) < 11) $out[(int) $x] = 1;
            if (count($out) >= $max) break;
        }
        return array_keys($out);
    }

    // ------------------------------------------------------------------ поиск

    /** Нормализованный запрос (для кэша и заголовка) */
    public static function normalizeQuery(string $q): string
    {
        $q = trim((string) preg_replace('/\s+/u', ' ', $q));
        return mb_substr($q, 0, 100);
    }

    /**
     * Все id по запросу в нужном порядке (кэш час: изменения товаров сбрасывают кэш — Cache::flush).
     * Полнотекстовый поиск по частому слову («кроссовки» — 42 тыс. товаров) стоит ~120 мс в самой базе,
     * повторы в течение часа берут готовый список (~2 мс).
     * Слова от 3 букв — MATCH(name, sku) AGAINST('+слово*' IN BOOLEAN MODE), короткие слова/цифры — LIKE по найденному;
     * если слов от 3 букв нет или ничего не найдено — LIKE по названию и артикулу (поиск по части артикула).
     * Дополнительно — все товары бренда, если запрос совпадает с его названием.
     */
    public static function searchIds(string $query, string $sort = 'create_datetime', string $order = 'desc'): array
    {
        $query = self::normalizeQuery($query);
        if ($query === '') return [];            // как на старом сайте: ищет и по одному символу (LIKE)
        $sort = isset(self::SORTS[$sort]) ? $sort : 'create_datetime';
        $order = $order === 'asc' ? 'asc' : 'desc';
        $key = 'search.ids.' . md5(mb_strtolower($query) . '|' . $sort . '|' . $order);
        $packed = Cache::remember($key, 3600, static function () use ($query, $sort, $order) {
            $ids = self::searchQuery($query, $sort, $order);
            return implode(',', $ids);
        });
        return $packed === '' ? [] : array_map('intval', explode(',', (string) $packed));
    }

    private static function searchQuery(string $query, string $sort, string $order): array
    {
        $db = App::db();
        $col = match ($sort) { 'price' => 'p.price', 'name' => 'p.name', default => 'p.created_at' };
        $low = mb_strtolower($query);
        $tokens = preg_split('/[^\p{L}\p{N}]+/u', $low, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $long = [];
        $short = [];
        foreach (array_slice(array_unique($tokens), 0, 8) as $t) {
            if (mb_strlen($t) >= 3) $long[] = $t;
            elseif (mb_strlen($t) === 2 || ctype_digit($t)) $short[] = $t;     // одиночные буквы не сужают поиск
        }
        $rows = [];
        if ($long) {
            $against = implode(' ', array_map(static fn($t) => '+' . $t . '*', $long));
            $sql = "SELECT p.id, $col AS k FROM products p WHERE p.status = 1 AND MATCH(p.name, p.sku) AGAINST(? IN BOOLEAN MODE)";
            $params = [$against];
            foreach ($short as $t) {
                $sql .= ' AND (p.name LIKE ? OR p.sku LIKE ?)';
                $params[] = '%' . self::like($t) . '%';
                $params[] = '%' . self::like($t) . '%';
            }
            $rows = $db->pairs($sql, $params);
        }
        if (!$rows) {
            $like = '%' . self::like($low) . '%';
            $rows = $db->pairs("SELECT p.id, $col AS k FROM products p WHERE p.status = 1 AND (p.name LIKE ? OR p.sku LIKE ?)", [$like, $like]);
        }
        // Бренды: точное совпадение названия или начало (от 3 символов)
        $brandIds = [];
        foreach (Catalog::brands() as $b) {
            if ((int) $b['hidden'] || !(int) $b['product_count']) continue;
            $n = mb_strtolower((string) $b['name']);
            if ($n === $low || (mb_strlen($low) >= 3 && str_starts_with($n, $low))) $brandIds[] = (int) $b['id'];
            if (count($brandIds) >= 5) break;
        }
        if ($brandIds) {
            [$ph, $v] = $db->in($brandIds);
            $rows += $db->pairs("SELECT p.id, $col AS k FROM products p WHERE p.brand_id IN ($ph) AND p.status = 1", $v);
        }
        if (!$rows) return [];
        $ids = array_map('intval', array_keys($rows));
        $keys = array_values($rows);
        $dir = $order === 'asc' ? SORT_ASC : SORT_DESC;
        $flag = $sort === 'price' ? SORT_NUMERIC : SORT_STRING;
        if ($sort === 'name') $keys = array_map('mb_strtolower', $keys);
        // DECIMAL приходит строкой «680.00»: числа заранее — сортировка 42 тыс. цен 40 мс → 15 мс
        if ($sort === 'price') $keys = array_map('floatval', $keys);
        array_multisort($keys, $dir, $flag, $ids, SORT_ASC, SORT_NUMERIC);   // равные — по id, как в каталоге
        return $ids;
    }

    private static function like(string $s): string
    {
        return addcslashes($s, '%_\\');
    }

    // ------------------------------------------------------------------ фильтры (фасеты)

    /** Настройка фильтра категории 'price,2,5,8' → [есть ли цена, [id характеристик по порядку]] */
    public static function parseFilterSetting(?string $filter): array
    {
        $price = false;
        $ids = [];
        foreach (explode(',', (string) $filter) as $x) {
            $x = trim($x);
            if ($x === 'price') $price = true;
            elseif (ctype_digit($x) && isset(self::filterFeatures()[(int) $x])) $ids[] = (int) $x;
        }
        return [$price, array_values(array_unique($ids))];
    }

    /** Значения фильтров категории из category_facets (кэш сутки, сбрасывается переиндексацией) */
    public function categoryGroups(int $categoryId, array $featureIds): array
    {
        if (!$featureIds) return [];
        $rows = Cache::remember('listing.facets.c' . $categoryId, 86400, static function () use ($categoryId) {
            $ids = array_keys(self::filterFeatures());
            if (!$ids) return [];
            [$ph, $v] = App::db()->in($ids);
            return App::db()->all("SELECT cf.feature_id, cf.value_id, cf.cnt, fv.value, fv.value_uk, fv.code, fv.sort FROM category_facets cf
                JOIN feature_values fv ON fv.id = cf.value_id WHERE cf.category_id = ? AND cf.feature_id IN ($ph)", array_merge([$categoryId], $v));
        });
        return $this->groups($rows, $featureIds);
    }

    /** Значения фильтров бренда (считаются по товарам бренда, кэш час) */
    public function brandGroups(int $brandId, array $featureIds): array
    {
        if (!$featureIds) return [];
        $rows = Cache::remember('listing.facets.b' . $brandId . '.' . implode('-', $featureIds), 3600, static function () use ($brandId, $featureIds) {
            [$ph, $v] = App::db()->in($featureIds);
            return App::db()->all("SELECT x.feature_id, x.value_id, x.cnt, fv.value, fv.value_uk, fv.code, fv.sort FROM (
                    SELECT pf.feature_id, pf.value_id, COUNT(*) cnt FROM products p
                    JOIN product_features pf ON pf.product_id = p.id AND pf.feature_id IN ($ph)
                    WHERE p.brand_id = ? AND p.status = 1 GROUP BY pf.feature_id, pf.value_id
                ) x JOIN feature_values fv ON fv.id = x.value_id", array_merge($v, [$brandId]));
        });
        return $this->groups($rows, $featureIds);
    }

    /** Группы для шаблона: [['id','code','name','type','values' => [['v','db','name','cnt','color','on']]]] */
    private function groups(array $rows, array $featureIds): array
    {
        $all = self::filterFeatures();
        $byF = [];
        foreach ($rows as $r) $byF[(int) $r['feature_id']][] = $r;
        $out = [];
        foreach ($featureIds as $fid) {
            if (empty($byF[$fid]) || !isset($all[$fid])) continue;
            $f = $all[$fid];
            $sel = array_flip($this->features[$fid] ?? []);
            $vals = [];
            foreach ($byF[$fid] as $r) {
                $name = trim((string) $r['value']);
                if ($name === '') continue;
                $vals[] = [
                    'v' => (int) $r['value_id'] - $f['offset'], 'db' => (int) $r['value_id'], 'name' => $name, 'cnt' => (int) $r['cnt'],
                    'sort' => (int) $r['sort'],
                    'color' => ($f['type'] === 'color' && $r['code'] !== null) ? sprintf('#%06X', (int) $r['code'] & 0xFFFFFF) : null,
                    'on' => isset($sel[(int) $r['value_id']]),
                ];
            }
            if (!$vals) continue;
            usort($vals, static fn($a, $b) => ($b['on'] <=> $a['on']) ?: strnatcasecmp($a['name'], $b['name']));
            $out[] = $f + ['values' => $vals, 'active' => count($sel)];
        }
        return $out;
    }

    /** Диапазон цен категории (для подсказок в полях «от/до») */
    public static function categoryPriceRange(int $categoryId): array
    {
        return Cache::remember('listing.price.c' . $categoryId, 86400, static function () use ($categoryId) {
            $r = App::db()->row('SELECT MIN(price) mn, MAX(price) mx FROM catalog_index WHERE category_id = ? AND price > 0', [$categoryId]);
            return [(int) floor((float) ($r['mn'] ?? 0)), (int) ceil((float) ($r['mx'] ?? 0))];
        });
    }

    public static function brandPriceRange(int $brandId): array
    {
        return Cache::remember('listing.price.b' . $brandId, 3600, static function () use ($brandId) {
            $r = App::db()->row('SELECT MIN(price) mn, MAX(price) mx FROM products WHERE brand_id = ? AND status = 1 AND price > 0', [$brandId]);
            return [(int) floor((float) ($r['mn'] ?? 0)), (int) ceil((float) ($r['mx'] ?? 0))];
        });
    }

    /** Названия выбранных значений для чипсов «активные фильтры»: [['label','url']] */
    public function activeChips(): array
    {
        $out = [];
        if ($this->priceMin !== null || $this->priceMax !== null) {
            $label = t('Цена') . ': ' . ($this->priceMin !== null ? t('от') . ' ' . self::fmt($this->priceMin) . ' ' : '')
                . ($this->priceMax !== null ? t('до') . ' ' . self::fmt($this->priceMax) . ' ' : '') . t('грн.');
            $out[] = ['label' => $label, 'url' => $this->url(['price' => false])];
        }
        if ($this->features) {
            $ids = array_merge(...array_values($this->features));
            [$ph, $v] = App::db()->in($ids);
            $names = [];
            foreach (App::db()->all("SELECT id, feature_id, value, value_uk FROM feature_values WHERE id IN ($ph)", $v) as $r) {
                $names[(int) $r['feature_id'] . ':' . (int) $r['id']] = (string) $r['value'];
            }
            $all = self::filterFeatures();
            foreach ($this->features as $fid => $vals) {
                foreach ($vals as $vid) {
                    // значения нет у этой характеристики (старая ссылка) — фильтр всё равно действует, чип нужен, чтобы его снять
                    $out[] = ['label' => ($all[$fid]['name'] ?? '') . ': ' . ($names[$fid . ':' . $vid] ?? (string) self::urlValue($fid, $vid)),
                        'url' => $this->url(['remove' => [$fid, $vid]])];
                }
            }
        }
        return $out;
    }

    // ------------------------------------------------------------------ пагинация

    /** Пагинация (классы .pager как у Paginator), ссылки — только с разрешёнными параметрами */
    public function pagerHtml(): string
    {
        if (!$this->pager || $this->pager->pages <= 1 || $this->outOfRange) return '';
        $p = $this->pager->page;
        $n = $this->pager->pages;
        $nums = array_unique(array_filter([1, 2, $p - 2, $p - 1, $p, $p + 1, $p + 2, $n - 1, $n], static fn($x) => $x >= 1 && $x <= $n));
        sort($nums);
        $h = '<nav class="pager" aria-label="' . e(t('Страницы')) . '">';
        if ($p > 1) $h .= '<a href="' . e($this->url(['page' => $p - 1])) . '" rel="prev" aria-label="' . e(t('Предыдущая страница')) . '">‹</a>';
        $prev = 0;
        foreach ($nums as $i) {
            if ($prev && $i - $prev > 1) $h .= '<span>…</span>';
            $h .= $i === $p ? '<span class="on" aria-current="page">' . $i . '</span>'
                : '<a href="' . e($this->url(['page' => $i])) . '">' . $i . '</a>';
            $prev = $i;
        }
        if ($p < $n) $h .= '<a href="' . e($this->url(['page' => $p + 1])) . '" rel="next" aria-label="' . e(t('Следующая страница')) . '">›</a>';
        return $h . '</nav>';
    }

    /**
     * SEO пагинации как на старом сайте (« | Страница N», canonical на первую страницу),
     * фильтры/сортировка/вид — noindex, follow; на чистых страницах — rel prev/next.
     */
    public function applySeo(Seo $seo, bool $pageSuffix = true): void
    {
        $page = $this->pageNo();
        $seo->canonical = url($this->base);
        // « | Страница N» (на /ua/ — « | Сторінка N»); у брендов и поиска старый сайт суффикс не добавляет
        if ($pageSuffix) $seo->paginate($page, $this->base);
        if ($this->isModified() || $this->outOfRange) {
            $seo->robots = 'noindex, follow';
        } else {
            if ($page > 1) $seo->prev = url(Lang::path($this->url(['page' => $page - 1])));
            if ($this->pager->hasNext()) $seo->next = url(Lang::path($this->url(['page' => $page + 1])));
        }
    }

    public function nextUrl(): ?string
    {
        return ($this->pager && !$this->outOfRange && $this->pager->hasNext()) ? $this->url(['page' => $this->pager->page + 1]) : null;
    }

    /** Сколько товаров покажет следующая «Показать ещё» */
    public function leftNext(): int
    {
        return ($this->pager && !$this->outOfRange) ? max(0, min($this->perPage, $this->total - $this->pager->page * $this->perPage)) : 0;
    }

    /** Ответ «Показать ещё»: HTML карточек + новая ссылка и пагинация */
    public function ajaxPayload(string $html): array
    {
        return ['ok' => true, 'html' => $html, 'page' => $this->pageNo(), 'pages' => $this->pager->pages,
            'next' => $this->nextUrl(), 'left' => $this->leftNext(), 'pager' => $this->pagerHtml()];
    }
}
