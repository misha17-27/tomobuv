<?php
declare(strict_types=1);

namespace App\Services\Suppliers;

use App\Core\App;
use App\Services\AdminCatalog;
use App\Services\CatalogIndexer;
use App\Services\Import\Importer;
use App\Services\ProductHistory;

/**
 * Общая часть записи товаров поставщика (JongGolfSync, HitObuviSync): порция — одна транзакция под блокировкой
 * записи индекса (CatalogIndexer::locked, взятой до транзакции) с повтором при взаимной блокировке; пакетный UPDATE
 * по белому списку колонок; значения характеристик и бренды без дублей (без учёта регистра и HTML-сущностей);
 * фото — вписывание в холст через GD (как load_photos старого загрузчика) с копией в папке запуска; встреченные товары
 * запуска (supplier_run_seen) и примеры пропусков в отчёте. Наследник задаёт SUPPLIER (класс адаптера), SKIP и apply().
 */
abstract class SupplierSync
{
    /** Класс адаптера поставщика (Supplier) — настройки, папка запуска, отчёт */
    protected const SUPPLIER = Supplier::class;
    protected const MIN_LEFT = 5.0;             // следующая порция шага — только если до конца бюджета осталось столько секунд
    protected const LOCK_WAIT = 10.0;           // ждать блокировку индекса не дольше (и не дольше остатка бюджета шага)
    protected const PRECHECK = 2.0;             // проверка «не идёт ли перестройка» до скачивания фото — ждать не дольше
    protected const IMG_MAX = 10485760;
    /** Колонки products, которые может менять загрузчик (белый список UPDATE) */
    protected const UPDATABLE = ['name', 'name_uk', 'sku', 'supplier', 'supplier_code', 'category_id', 'brand_id', 'price', 'compare_price', 'size',
        'stock', 'in_stock', 'status', 'meta_title', 'meta_description', 'meta_keywords', 'meta_title_uk', 'meta_description_uk', 'meta_keywords_uk', 'updated_at'];
    /** Причины пропуска [ключ => текст] (статистика запуска) */
    public const SKIP = [];

    protected int $runId;
    protected bool $dry;
    protected array $stats;
    protected array $cfg = [];
    /** Коды, товары которых владелец удалил на сайте (supplier_excluded): [код => true], загружаются при первом обращении */
    private ?array $excludedCodes = null;
    protected array $attr = [];
    protected array $ci = [];
    protected array $brandByNk = [];
    protected array $values = [];               // [fid => [нормализованное значение => id]]
    protected array $brandByNorm = [];          // [ключ раскодированного названия => [id, …]] — «J&amp;amp;G» на сайте = «J&G»
    protected int $brandFeature = 0;
    protected bool $deferred = false;           // порция отложена: блокировка индекса занята (идёт полная перестройка)

    /** Запись порции в открытой транзакции (под блокировкой индекса). $moved — перенесённые файлы фото (удаляются при откате) */
    abstract protected function apply(array &$items, array $photos, array &$moved): void;

    /** Последняя порция отложена (блокировка индекса занята) — шаг заканчивается и повторяется позже */
    public function deferred(): bool
    {
        return $this->deferred;
    }

    /**
     * Записать порцию: блокировка записи индекса (не дольше LOCK_WAIT и остатка бюджета шага) → транзакция apply().
     * Взаимная блокировка — транзакция откачена, повтор (до 3 раз) с чистыми id; другая ошибка — фото порции удаляются.
     * @return bool false — блокировка занята (порция не записана, фото остаются для следующего шага)
     */
    protected function transact(array &$items, array $photos, float $deadline): bool
    {
        for ($try = 1; ; $try++) {
            $moved = [];
            try {
                $ok = CatalogIndexer::locked(function () use (&$items, $photos, &$moved): void {
                    App::db()->transaction(function () use (&$items, $photos, &$moved): void {
                        $this->apply($items, $photos, $moved);
                    });
                }, max(0.0, min(static::LOCK_WAIT, $deadline - microtime(true) - 1.0)), false);
                return $ok;
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
    }

    // ================================================================== бренды и характеристики

    /**
     * Бренды сайта: [ключ названия как в базе => id] и [ключ раскодированного названия => [id, …]] (Supplier::name —
     * «J&amp;amp;G» и «J&amp;G» из старой базы оба «J&G»); значения характеристик — заново (после отката транзакции
     * в кэше могли остаться несуществующие id)
     */
    protected function loadBrands(): void
    {
        $this->brandByNk = [];
        $this->brandByNorm = [];
        $this->values = [];
        $norm = [];
        foreach (App::db()->all('SELECT id, name, product_count FROM brands ORDER BY id') as $b) {
            $this->brandByNk[Importer::nk((string) $b['name'])] ??= (int) $b['id'];
            $norm[Importer::nk(Supplier::name((string) $b['name']))][(int) $b['id']] = (int) $b['product_count'];
        }
        foreach ($norm as $k => $list) {                                    // больше товаров — первым, при равенстве — меньший id
            uksort($list, static fn($a, $b) => [$list[$b], $a] <=> [$list[$a], $b]);
            $this->brandByNorm[$k] = array_keys($list);
        }
    }

    /**
     * Бренд: id существующего, «new:Название» (создаётся при записи) или null — не указан. $brand — раскодированное
     * название (Supplier::name: «J&G»), $raw — как в ответе поставщика (экранировано многократно: «J&amp;amp;amp;G»).
     * На сайте такой бренд мог остаться со старого сайта экранированным («J&amp;amp;G», id 72267; есть и «J&amp;G»,
     * id 71814) — загрузчик его не переименовывает (сменился бы адрес /brand/…/ — решение владельца), а находит:
     *  1) бренд с раскодированным названием «J&G» (владелец переименовал — берётся он);
     *  2) название как в базе — от ответа поставщика, раскодируя по одному уровню: первое совпадение (ближайшее
     *     к ответу поставщика — то, что записывал старый загрузчик: «J&amp;amp;G» = id 72267);
     *  3) по раскодированному названию: из нескольких — с большим числом товаров, при равенстве — меньший id;
     *  4) нет — новый бренд с раскодированным названием («J&G»).
     */
    protected function brandRef(string $brand, string $raw = ''): int|string|null
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

    /**
     * Новые бренды порции (id бренда = id значения характеристики «Бренд», AdminCatalog::valueId): [название => id | null].
     * $need — [название => 1] из ссылок «new:Название».
     */
    protected function createBrands(array $need): array
    {
        $sup = static::SUPPLIER;
        $out = [];
        foreach (array_keys($need) as $name) {
            $name = (string) $name;
            $bid = $this->brandByNk[Importer::nk($name)] ?? ($this->brandFeature ? AdminCatalog::valueId($this->brandFeature, $name) : 0);
            $out[$name] = $bid ?: null;
            if ($bid && !isset($this->brandByNk[Importer::nk($name)])) {
                $this->brandByNk[Importer::nk($name)] = $bid;
                $sup::log($this->runId, 'info', 'Добавлен бренд «' . $name . '» (id ' . $bid . ')');
            }
        }
        return $out;
    }

    /**
     * Значение характеристики для русской версии: украинские слова → русские по словарю database/suppliers/uk-values.php
     * («чорний» → «черный», «штучна шкіра» → «искусственная кожа»), незнакомое — как есть
     */
    public static function ruValue(string $v): string
    {
        static $dict = null, $re = null;
        if ($dict === null) {
            $dict = [];
            foreach ((array) include ROOT . '/database/suppliers/uk-values.php' as $uk => $ru) $dict[mb_strtolower((string) $uk)] = (string) $ru;
            $keys = array_keys($dict);
            usort($keys, static fn($a, $b) => mb_strlen($b) <=> mb_strlen($a));   // длинные фразы раньше
            $re = $keys ? '/(?<!\p{L})(' . implode('|', array_map(static fn($k) => preg_quote($k, '/'), $keys)) . ')(?!\p{L})/iu' : null;
        }
        $v = Supplier::name($v);
        if ($re === null || $v === '' || !preg_match('/\p{Cyrillic}/u', $v)) return $v;
        return (string) preg_replace_callback($re, static function ($m) use ($dict): string {
            $ru = $dict[mb_strtolower($m[1])] ?? $m[1];
            $first = mb_substr($m[1], 0, 1);
            return $first !== mb_strtolower($first) ? mb_strtoupper(mb_substr($ru, 0, 1)) . mb_substr($ru, 1) : $ru;
        }, $v);
    }

    /** Ключ значения характеристики: раскодированное (значение сайта «Шкіра &amp;amp; текстиль» = «Шкіра & текстиль» поставщика) */
    protected static function vkey(string $v): string
    {
        return Importer::nk(Supplier::name($v));
    }

    /**
     * id значений характеристик: существующее — без учёта регистра и лишних пробелов («велюр» = «Велюр»,
     * «60% PU,\n 40% Тканина» = «60% PU, 40% Тканина» — фильтр не раздваивается), новое — создаётся в конце списка.
     * @return [fid => [ключ значения => id]]
     */
    protected function valueIds(array $want): array
    {
        $sup = static::SUPPLIER;
        $out = [];
        foreach ($want as $fid => $vals) {
            $this->loadValues((int) $fid);
            foreach (array_keys($vals) as $val) {
                $k = static::vkey((string) $val);
                if (!isset($this->values[$fid][$k])) {
                    $clean = mb_substr(trim((string) preg_replace('/\s+/u', ' ', (string) $val)), 0, 255);
                    $this->values[$fid][$k] = AdminCatalog::valueId((int) $fid, $clean);
                    $sup::log($this->runId, 'info', 'Новое значение характеристики: ' . $this->featureName((int) $fid) . ' = «' . $clean . '»');
                }
                $out[$fid][$k] = $this->values[$fid][$k];
            }
        }
        return $out;
    }

    /** Значения характеристики на сайте: [ключ значения => id] — один раз за шаг (после отката транзакции — заново, loadBrands) */
    private function loadValues(int $fid): void
    {
        if (isset($this->values[$fid])) return;
        $this->values[$fid] = [];
        foreach (App::db()->all('SELECT id, value FROM feature_values WHERE feature_id = ? ORDER BY id', [$fid]) as $r) $this->values[$fid][static::vkey((string) $r['value'])] ??= (int) $r['id'];
    }

    /** Есть ли на сайте такое значение характеристики (без учёта регистра и лишних пробелов) */
    protected function hasValue(int $fid, string $v): bool
    {
        $this->loadValues($fid);
        return isset($this->values[$fid][static::vkey($v)]);
    }

    /** id характеристик из настройки (поле поставщика → строка features) */
    protected function attrIds(): array
    {
        $out = [];
        foreach ($this->attr as $f) $out[(int) $f['id']] = 1;
        return $out;
    }

    protected function featureName(int $fid): string
    {
        foreach ($this->attr as $f) if ((int) $f['id'] === $fid) return (string) $f['name'];
        return '#' . $fid;
    }

    /** UPDATE products SET col = CASE id WHEN … END — пачкой по 200 (колонки — только из белого списка UPDATABLE) */
    protected function bulkUpdate(array $upd): void
    {
        if (!$upd) return;
        $db = App::db();
        foreach (array_chunk($upd, 200, true) as $part) {
            $cols = [];
            foreach ($part as $id => $u) foreach ($u as $c => $v) $cols[$c][$id] = $v;
            $set = []; $params = [];
            foreach ($cols as $c => $vals) {
                if (!in_array($c, static::UPDATABLE, true)) continue;
                $sql = "`$c` = CASE id";
                foreach ($vals as $id => $v) { $sql .= ' WHEN ? THEN ?'; $params[] = (int) $id; $params[] = is_float($v) ? (string) round($v, 2) : $v; }
                $set[] = $sql . " ELSE `$c` END";
            }
            if (!$set) continue;
            [$ph, $ids] = $db->in(array_keys($part));
            $db->query('UPDATE products SET ' . implode(', ', $set) . " WHERE id IN ($ph)", array_merge($params, $ids));
        }
    }

    /**
     * Свободные адреса новых товаров: url_base, url_base-2 … -9, иначе со случайным хвостом. $creates — индексы $items
     * с action = create; адрес пишется в $items[i]['row']['url']
     */
    protected function allocateUrls(array &$items, array $creates): void
    {
        if (!$creates) return;
        $db = App::db();
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
    }

    /**
     * «Один товар — один поставщик»: из $pids — товары, связанные (supplier_links) с другим поставщиком, чем этот.
     * Такие товары загрузчик не находит, не берёт под себя и не меняет. @return [id товара => код другого поставщика]
     */
    protected function foreignLinked(array $pids): array
    {
        $pids = array_values(array_unique(array_filter(array_map('intval', $pids))));
        if (!$pids) return [];
        $sup = static::SUPPLIER;
        $out = [];
        foreach (array_chunk($pids, 1000) as $part) {
            [$ph, $v] = App::db()->in($part);
            foreach (App::db()->all("SELECT product_id, supplier FROM supplier_links WHERE product_id IN ($ph) AND supplier <> ?", array_merge($v, [$sup::CODE])) as $r) $out[(int) $r['product_id']] = (string) $r['supplier'];
        }
        return $out;
    }

    /** Встреченные коды и товары запуска (для скрытия отсутствующих и повторов) */
    protected function seen(array $items): void
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

    // ================================================================== журнал изменений («Обзор»)

    /**
     * Записанное порцией — в журнал изменений supplier_changes (только боевой запуск, в транзакции порции):
     *   new    — добавлен (цена поставщика за пару $it['cost'] → наша цена);
     *   price  — изменилась цена поставщика за пару ($it['old_cost'] → $it['cost']) или наша цена (upd.price): только реальные изменения;
     *   hidden — скрыт загрузчиком (status 1 → 0; $it['hide_note'] — почему);
     *   out    — нет в наличии у поставщика: на сайте остаётся, но «нет в наличии» (in_stock 1 → 0 при status 1);
     *   back   — снова есть у поставщика ($it['returned'] — текст: был скрыт загрузчиком / пропадал N проходов)
     *            или снова в наличии (in_stock 0 → 1 при status 1);
     *   edit   — остальные реальные изменения записи (editNote: взят под загрузчик, старая и закупочная цена, ряд, ящик,
     *            артикул, название, категория, бренд, характеристики, описание, фото) — «было → стало» в note.
     */
    protected function journal(array $items, string $now): void
    {
        $rows = [];
        foreach ($items as $it) {
            $pid = (int) ($it['id'] ?? 0);
            if (!$pid) continue;
            $cost = isset($it['cost']) && (float) $it['cost'] > 0 ? round((float) $it['cost'], 2) : null;
            $old = isset($it['old_cost']) && (float) $it['old_cost'] > 0 ? round((float) $it['old_cost'], 2) : null;
            if ($it['action'] === 'create') {
                $rows[] = $this->change($it, 'new', null, $cost, null, (float) ($it['row']['price'] ?? 0), $now);
                continue;
            }
            if (!in_array($it['action'], ['update', 'hide', 'same'], true) || empty($it['ex'])) continue;
            $ex = $it['ex'];
            $was = round((float) $ex['price'], 2);
            $price = array_key_exists('price', $it['upd'] ?? []) ? round((float) $it['upd']['price'], 2) : $was;
            $costLogged = $cost !== null && $old !== null && abs($cost - $old) >= 0.005;
            if (abs($price - $was) >= 0.005 || $costLogged) {
                $rows[] = $this->change($it, 'price', $old, $cost, $was, $price, $now);
            }
            // закупочная = цена поставщика: в строке price — только если её записали (иначе «закупочная: 0 → 665» — в строке edit)
            if ($it['action'] !== 'same' && ($note = $this->editNote($it, $ex, $costLogged)) !== '') $rows[] = $this->change($it, 'edit', null, null, null, null, $now, $note);
            $st = (int) $ex['status'];
            $st2 = array_key_exists('status', $it['upd'] ?? []) ? (int) $it['upd']['status'] : $st;
            $in = (int) ($ex['in_stock'] ?? 1);
            $in2 = array_key_exists('in_stock', $it['upd'] ?? []) ? (int) $it['upd']['in_stock'] : $in;
            if ($st === 1 && $st2 === 0) $rows[] = $this->change($it, 'hidden', null, null, null, null, $now, (string) ($it['hide_note'] ?? ''));
            elseif (!empty($it['returned'])) $rows[] = $this->change($it, 'back', null, null, null, null, $now, (string) $it['returned']);
            elseif ($st2 === 1 && $in === 1 && $in2 === 0) $rows[] = $this->change($it, 'out', null, null, null, null, $now, (string) ($it['out_note'] ?? 'нет в наличии у поставщика'));
            elseif ($st === 1 && $st2 === 1 && $in === 0 && $in2 === 1) $rows[] = $this->change($it, 'back', null, null, null, null, $now, 'снова в наличии у поставщика');
        }
        static::record($rows);
    }

    /**
     * Прочие изменения записи товара для журнала (kind edit) — всё, что не цена, видимость и наличие (у них свои строки):
     * «взят под загрузчик (был forsage); размеры: 38-38 → 28-35; пар в ящике: 5 → 8; фото загружено (1)». Формат «было → стало» —
     * ProductHistory::describe/line, как у правок в админке. $costKnown — цена поставщика есть в строке price (закупочная = она).
     * '' — менять нечего (сравнение уже сделано: в upd только отличающиеся поля).
     */
    protected function editNote(array $it, array $ex, bool $costKnown): string
    {
        $upd = (array) ($it['upd'] ?? []);
        $skip = ['price', 'status', 'in_stock', 'updated_at', 'supplier_code'];
        if (array_key_exists('in_stock', $upd)) $skip[] = 'stock';           // остаток — вместе с наличием (строка out / back)
        if ($costKnown) $skip[] = 'purchase_price';                           // закупочная = цена поставщика (строка price)
        $set = array_diff_key($upd, array_flip($skip));
        $lines = [];
        if (array_key_exists('supplier', $set)) {
            $was = (string) ($ex['supplier'] ?? '');
            $lines[] = 'взят под загрузчик (' . ($was !== '' ? 'был ' . $was : 'был заведён вручную') . ')';
            unset($set['supplier']);
        }
        foreach (ProductHistory::describe($set, $ex) as $ch) {
            // категория — с родителем: ряды с одним именем («26-32 → 26-32»: Весна-Осень > 26-32 → Туфли > 26-32) и разделы
            // с одним именем в разных корнях («Весна-Осень → 26-32»: ЖЕНСКАЯ ОБУВЬ > Весна-Осень → Весна-Осень > 26-32)
            if (($ch[0] ?? '') === 'category_id' && count($ch) >= 3) {
                $ch[1] = $this->catShort((int) ($ex['category_id'] ?? 0));
                $ch[2] = $this->catShort((int) $set['category_id']);
            }
            $lines[] = ProductHistory::line($ch);
        }
        if (!empty($it['relink']) && !array_key_exists('category_id', $set)) $lines[] = 'привязка к категории';
        if (!empty($it['features'])) $lines[] = 'характеристики';
        if (!empty($it['texts'])) $lines[] = 'описание изменено';
        if (!empty($it['stored'])) $lines[] = 'фото загружено (' . count($it['stored']) . ')';
        return implode('; ', array_values(array_filter($lines, static fn($l) => $l !== '')));
    }

    /** Категория сайта для журнала: две последние ступени пути («Весна-Осень > 26-32») */
    private function catShort(int $id): string
    {
        $path = (string) (($this->ci['path'] ?? [])[$id] ?? ProductHistory::categoryName($id));
        return implode(' > ', array_slice(array_map('trim', explode('>', $path)), -2));
    }

    /** Строка журнала изменений */
    protected function change(array $it, string $kind, ?float $oldCost, ?float $newCost, ?float $oldPrice, ?float $newPrice, string $now, string $note = ''): array
    {
        $sup = static::SUPPLIER;
        return ['supplier' => $sup::CODE, 'run_id' => $this->runId ?: null, 'product_id' => (int) $it['id'], 'code' => mb_substr((string) ($it['code'] ?? ''), 0, 100), 'kind' => $kind,
            'old_cost' => $oldCost, 'new_cost' => $newCost, 'old_price' => $oldPrice, 'new_price' => $newPrice, 'note' => mb_substr($note, 0, 255), 'created_at' => $now];
    }

    /** Скрытые при завершении (нет у поставщика) — в журнал изменений: [product_id => code] */
    protected function journalHidden(array $codes, string $note): void
    {
        $sup = static::SUPPLIER;
        $now = date('Y-m-d H:i:s');
        $rows = [];
        foreach ($codes as $pid => $code) {
            $rows[] = ['supplier' => $sup::CODE, 'run_id' => $this->runId ?: null, 'product_id' => (int) $pid, 'code' => mb_substr((string) $code, 0, 100), 'kind' => 'hidden',
                'old_cost' => null, 'new_cost' => null, 'old_price' => null, 'new_price' => null, 'note' => mb_substr($note, 0, 255), 'created_at' => $now];
        }
        static::record($rows);
    }

    protected static function record(array $rows): void
    {
        if ($rows) App::db()->insertMany('supplier_changes', $rows, false, 500);
    }

    // ================================================================== отчёт

    protected function skipCount(string $why, string $msg, int $n = 1): void
    {
        $this->stats['skip'][$why] = ($this->stats['skip'][$why] ?? 0) + $n;
        $this->example($why, $msg);
    }

    /** В отчёт — первые 20 примеров каждой массовой причины пропуска, остальное — только в счётчики */
    protected function example(string $why, string $msg): void
    {
        $sup = static::SUPPLIER;
        $n = $this->stats['examples'][$why] = (int) ($this->stats['examples'][$why] ?? 0) + 1;
        if ($n <= 20) $sup::log($this->runId, 'skip', $msg);
        elseif ($n === 21) $sup::log($this->runId, 'skip', '…дальше такие пропуски («' . (static::SKIP[$why] ?? $why) . '») только считаются');
    }

    protected static function num(float $v): string
    {
        return rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');
    }

    // ================================================================== фото

    /** Готовое фото по ссылке в папке запуска (с параметрами холста: другие настройки — другой файл) */
    protected function photoFile(string $url): string
    {
        $sup = static::SUPPLIER;
        $c = $this->cfg;
        return $sup::dir($this->runId) . '/photo-' . md5($url . '|' . $c['photo_width'] . 'x' . $c['photo_height'] . 'q' . $c['photo_quality']
            . (($c['watermark'] ?? '0') === '1' ? 'wm' : '')) . '.jpg';
    }

    /**
     * Вписать фото в холст photo_width × photo_height по центру на белом фоне, JPEG с качеством photo_quality
     * (как load_photos старого загрузчика; ширина или высота 0 — без холста, как есть). Пишется целиком или никак.
     * @return array ['file', 'w', 'h'] | ['error']
     */
    protected function frame(string $src, string $dst): array
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
        $this->retouch($canvas);
        $tmp = $dst . '.part';                                              // целиком или никак: недописанный файл не примется за готовый
        $ok = imagejpeg($canvas, $tmp, max(30, min(100, (int) $this->cfg['photo_quality'])));
        imagedestroy($canvas);
        if (!$ok || !@rename($tmp, $dst)) { @unlink($tmp); return ['error' => 'не удалось сохранить JPEG']; }
        return ['file' => $dst, 'w' => $cw, 'h' => $ch];
    }

    /** Товар с этим кодом владелец удалил на сайте — новый не создавать (supplier_excluded; вернуть — «Обзор» поставщика) */
    protected function excluded(string $code): bool
    {
        if ($this->excludedCodes === null) {
            $sup = static::SUPPLIER;
            try {
                $this->excludedCodes = array_fill_keys(array_map('strval', App::db()->col('SELECT code FROM supplier_excluded WHERE supplier = ?', [$sup::CODE])), true);
            } catch (\PDOException) {
                $this->excludedCodes = [];                                          // таблицы ещё нет (до bin/install.php)
            }
        }
        return isset($this->excludedCodes[$code]);
    }

    /** Правка готового холста перед сохранением (адаптер: «Хит обуви» — замена водяного знака поставщика) */
    protected function retouch(\GdImage $canvas): void
    {
    }

    protected function dropPhotos(array $photos): void
    {
        foreach ($photos as $p) if (isset($p['file'])) @unlink($p['file']);
    }

    /** У товара есть главное фото и его оригинал на диске ($ex — строка products с image_id, image_ext) */
    protected static function photoOnDisk(array $ex): bool
    {
        return !empty($ex['image_id'])
            && is_file(\App\Core\Image::originalPath((int) $ex['id'], (int) $ex['image_id'], (string) (($ex['image_ext'] ?? '') ?: 'jpg')));
    }

    /** Убрать записи product_images без файла оригинала (фото не перенесено); главное фото — сбросить, если пропало */
    protected static function dropMissingImages(int $pid): int
    {
        $db = App::db();
        $gone = [];
        foreach ($db->all('SELECT id, ext FROM product_images WHERE product_id = ?', [$pid]) as $r) {
            if (!is_file(\App\Core\Image::originalPath($pid, (int) $r['id'], (string) ($r['ext'] ?: 'jpg')))) $gone[] = (int) $r['id'];
        }
        if (!$gone) return 0;
        [$ph, $vals] = $db->in($gone);
        $db->query("DELETE FROM product_images WHERE id IN ($ph)", $vals);
        $db->query("UPDATE products SET image_id = NULL WHERE id = ? AND image_id IN ($ph)", array_merge([$pid], $vals));
        return count($gone);
    }

    /**
     * Фото товара: строка product_images → файл оригинала (путь как в Webasyst, Image::originalPath) → главное фото.
     * Замена ($replace — старые [iid => ext]): старые строки удаляются сразу, их файлы — после COMMIT ($it['drop_files']).
     */
    protected function storePhoto(array &$it, array $photo, array $replace, array &$moved, string $now): void
    {
        $db = App::db();
        $pid = (int) $it['id'];
        if ($replace) {
            foreach ($replace as $iid => $ext) $it['drop_files'][] = [$pid, (int) $iid, (string) $ext];
            $db->query('DELETE FROM product_images WHERE product_id = ?', [$pid]);
        }
        $iid = $db->insert('product_images', ['product_id' => $pid, 'sort' => 0, 'ext' => 'jpg', 'width' => min(65535, $photo['w']), 'height' => min(65535, $photo['h']),
            'filename' => '', 'description' => null, 'created_at' => $now]);
        $dst = \App\Core\Image::originalPath($pid, $iid, 'jpg');
        @mkdir(dirname($dst), 0775, true);
        if (!@copy($photo['file'], $dst)) throw new \RuntimeException('Не удалось сохранить фото в ' . dirname($dst) . ' — проверьте права на public/wa-data');
        $moved[] = $dst;
        $db->update('products', ['image_id' => $iid, 'image_ext' => 'jpg'], 'id = ?', [$pid]);
        $it['stored'][] = $iid;
    }
}
