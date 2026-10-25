<?php
declare(strict_types=1);

namespace App\Services\Suppliers;

use App\Core\App;
use App\Core\Cache;
use App\Services\AdminCatalog;
use App\Services\CatalogIndexer;
use App\Services\Import\Importer;

/**
 * Общая часть порций загрузчиков со страниц сайта поставщика (HitObuviSync, TommSync): товары страницы — порциями
 * (одна транзакция под блокировкой записи индекса — SupplierSync::transact), сеть — до транзакции и только в бюджете шага:
 * в порцию берётся столько товаров с недостающими фото (и карточками — enrich), сколько успеем скачать; скачанное лежит
 * в папке запуска, отложенная порция его не качает снова. Встреченные товары запуска (supplier_run_seen — и пропущенные),
 * отчёт, скрытие пропавших после N полных проходов подряд (finish, защита «больше 50 % активных — отмена»).
 * Наследник: items() — разбор и решение по товарам порции (action create | update | same | existing | skip), apply() — запись,
 * data() — подробности строки отчёта; при необходимости prepare(), enrich(), netCost().
 */
abstract class WebSync extends SupplierSync
{
    protected const PORTION = 40;
    /** Сколько строк «без изменений» / «есть на сайте» писать в отчёт (дальше — только счётчики) */
    protected const LOG_SAME = 200;
    /** Массовые причины пропуска: в отчёт — только примерами (SupplierSync::skipCount) */
    protected const MASS_SKIP = ['dup', 'taken'];
    /** Как найден товар сайта: [ключ => подпись] */
    public const VIA = [];
    protected const CHANGE_NAMES = ['price' => 'цена', 'compare_price' => 'старая цена', 'purchase_price' => 'закупочная', 'status' => 'на сайте', 'in_stock' => 'наличие',
        'stock' => 'остаток', 'sku' => 'артикул (ряд)', 'size' => 'размер', 'box_qty' => 'пар в ящике', 'min_qty' => 'мин. заказ', 'supplier' => 'поставщик',
        'supplier_code' => 'код поставщика', 'category_id' => 'категория'];

    protected SiteClient $client;
    protected ?array $markup = null;
    protected string $deferredBy = '';
    protected int $plannedNew = 0;

    /** Разбор и решение по товарам порции: строки с action, ex, upd, changes, warn … */
    abstract protected function items(array $slice, array $meta): array;

    /** Подробности строки отчёта (таблица запуска): что будет у товара после записи */
    abstract protected function data(array $it): array;

    /** Причина последней отложенной порции: lock — идёт перестройка индекса (ждать), photos — сеть не уложилась в шаг */
    public function deferredBy(): string
    {
        return $this->deferredBy;
    }

    /** Справочники шага (категории, бренды) */
    protected function prepare(): void
    {
        $this->ci = Importer::categoryIndex();
        $this->loadBrands();
    }

    /** Сетевые данные порции, кроме фото (карточки новых товаров): false — время шага кончилось, порция откладывается */
    protected function enrich(array &$items, float $deadline): bool
    {
        return true;
    }

    /** Запросов в сеть, нужных товару порции, кроме фото (ещё не скачанная карточка) */
    protected function netCost(array $it): int
    {
        return 0;
    }

    /** Адреса фото товара (главное — первым) */
    protected function photoUrls(array $it): array
    {
        return array_values(array_filter(array_map('strval', $it['photo_urls'] ?? [$it['photo_url'] ?? '']), static fn($u) => $u !== ''));
    }

    // ================================================================== порции

    /**
     * Товары страницы с позиции $pos до $deadline (и не больше $limit). $meta — данные страницы для items(). $saved($pos) —
     * после каждой порции; false — запуск остановлен. @return int новая позиция
     */
    public function process(array $list, int $pos, array $meta, float $deadline, ?int $limit, ?callable $saved = null): int
    {
        $this->prepare();
        $this->deferred = false;
        $this->deferredBy = '';
        $first = true;
        while ($pos < count($list) && ($limit === null || $limit > 0) && microtime(true) < $deadline - ($first ? 1.0 : self::MIN_LEFT)) {
            $first = false;
            $n = static::PORTION;
            if ($limit !== null) $n = min($n, $limit);
            $used = $this->portion(array_slice($list, $pos, $n), $meta, $deadline);
            if ($used === 0) { $this->deferred = true; break; }
            $pos += $used;
            if ($limit !== null) $limit -= $used;
            if ($saved && $saved($pos) === false) break;
        }
        return $pos;
    }

    /** Одна порция; @return int сколько товаров обработано (0 — отложена: блокировка индекса или сеть не уложилась в шаг) */
    protected function portion(array $slice, array $meta, float $deadline): int
    {
        $sup = static::SUPPLIER;
        $statsBefore = $this->stats;
        $logMark = $sup::logMark();
        $plannedBefore = $this->plannedNew;
        $items = $this->items($slice, $meta);
        if (!$this->dry) {
            // фото и карточки — по одному с паузой: в порцию берём столько товаров с недостающим, сколько успеем скачать в этом шаге
            // (первый такой — всегда: иначе товар, которому нужно больше запросов, чем влезает в шаг, не прошёл бы никогда)
            $per = $this->client->isOffline() ? 0.05 : (float) (Importer::num($this->cfg['delay']) ?? 2.0) + 1.5;
            $fit = max(1, (int) floor(($deadline - microtime(true) - 4.0) / $per));
            $need = 0;
            foreach ($items as $i => $it) {
                $n = $this->netCost($it);
                if (!empty($it['photo'])) foreach ($this->photoUrls($it) as $u) if (!is_file($this->photoFile($u))) $n++;
                if ($n === 0) continue;
                if ($need > 0 && $need + $n > $fit) {
                    foreach (array_slice($items, $i) as $rest) if ($rest['action'] === 'create') $this->plannedNew--;
                    $items = array_slice($items, 0, $i);
                    break;
                }
                $need += $n;
            }
        }
        if ($this->dry) {
            $this->report($items);
            $this->seen($items);
            $this->plannedNew = 0;                                          // новые порции уже в stats['created']
            $sup::flushLog();
            return count($items);
        }
        $defer = function (string $why) use ($statsBefore, $logMark, $plannedBefore, $sup): int {
            $this->stats = $statsBefore;
            $this->plannedNew = $plannedBefore;
            $sup::logRollback($logMark);
            $this->deferredBy = $why;
            return 0;
        };
        if (!CatalogIndexer::locked(static function (): void {}, max(0.0, min(self::PRECHECK, $deadline - microtime(true) - 1.0)), false)) return $defer('lock');
        if (!$this->enrich($items, $deadline)) return $defer('photos');
        $photos = $this->photos($items, $deadline);
        foreach ($items as $i => $it) if (!empty($it['photo']) && ($photos[$i]['error'] ?? '') === 'time') return $defer('photos');   // скачанные — в папке запуска
        if (!$this->transact($items, $photos, $deadline)) return $defer('lock');
        foreach ($items as $it) foreach ($it['drop_files'] ?? [] as [$pid, $iid, $ext]) AdminCatalog::deleteImageFiles($pid, $iid, $ext);
        $this->dropPhotos($photos);
        $this->report($items);
        $this->plannedNew = 0;                                              // созданные — в stats['created'], не созданные (нет фото) — не в счёт лимита
        $sup::flushLog();
        return count($items);
    }

    /** Повтор товара поставщика в этом запуске (другой раздел, сдвиг страниц) — второй раз не обрабатывается */
    protected function dedupe(array &$items): void
    {
        if (!$items) return;
        $db = App::db();
        [$ph, $v] = $db->in(array_values(array_unique(array_column($items, 'code'))));
        $seen = array_flip($db->col("SELECT code FROM supplier_run_seen WHERE run_id = ? AND code IN ($ph)", array_merge([$this->runId], $v)));
        foreach ($items as &$it) {
            if (isset($seen[$it['code']])) { $it['action'] = 'skip'; $it['why'] = 'dup'; $it['msg'] = 'повтор товара поставщика ' . $it['code'] . ' — уже обработан в этом запуске'; continue; }
            $seen[$it['code']] = 1;
        }
        unset($it);
    }

    protected function skip(array &$it, string $why, string $msg, string $level = 'skip'): void
    {
        $it['action'] = 'skip';
        $it['why'] = $why;
        $it['msg'] = $msg;
        $it['level'] = $level;
    }

    protected function show(string $col, $v): string
    {
        if ($v === null || $v === '') return '—';
        if ($col === 'status' || $col === 'in_stock') return (int) $v ? 'да' : 'нет';
        if ($col === 'category_id') return (string) ($this->ci['path'][(int) $v] ?? $v);
        if (in_array($col, ['price', 'compare_price', 'purchase_price'], true)) return self::num((float) $v);
        return mb_strimwidth((string) $v, 0, 40, '…');
    }

    /** Изменения $upd относительно строки товара $ex: только отличающиеся поля → $it['upd'], подписи → $it['changes'] */
    protected function diff(array &$it, array $ex, array $upd): void
    {
        // цена закреплена владельцем (products.price_manual) — цену и старую цену сверка не меняет; закупочная — как у поставщика
        if (!empty($ex['price_manual'])) unset($upd['price'], $upd['compare_price']);
        // скрыт, потому что нет фото (NoPhoto) — показывает его NoPhoto, когда фото появится, а не сверка
        if (!empty($ex['nophoto_hidden']) && (int) ($upd['status'] ?? 0) === 1) unset($upd['status']);
        foreach ($upd as $col => $val) {
            $old = $ex[$col] ?? null;
            $same = match ($col) {
                'price', 'compare_price', 'purchase_price' => $old !== null && abs((float) $old - (float) $val) < 0.005,
                'stock', 'category_id' => ($old === null && $val === null) || ($old !== null && $val !== null && (int) $old === (int) $val),
                'status', 'in_stock', 'box_qty', 'min_qty' => (int) $old === (int) $val,
                default => trim((string) $old) === trim((string) $val),
            };
            if ($same) continue;
            $it['upd'][$col] = $val;
            $it['changes'][] = (static::CHANGE_NAMES[$col] ?? $col) . ': ' . $this->show($col, $old) . ' → ' . $this->show($col, $val);
        }
    }

    // ================================================================== фото

    /**
     * Фото новых товаров (и найденных без фото) — по одному через бережный клиент (пауза, robots.txt), вписывание в холст
     * (SupplierSync::frame), готовое — в папке запуска: отложенная порция не качает его снова. Время шага кончилось —
     * ['error' => 'time'] (порция отложится). @return [индекс => ['file', 'w', 'h', 'more' => [доп. фото …]] | ['error']]
     */
    protected function photos(array $items, float $deadline): array
    {
        $sup = static::SUPPLIER;
        $out = [];
        $first = true;
        foreach ($items as $i => $it) {
            if (empty($it['photo']) || !in_array($it['action'], ['create', 'update'], true)) continue;
            foreach ($this->photoUrls($it) as $k => $url) {
                $f = $this->photoFile($url);
                $info = is_file($f) ? @getimagesize($f) : false;
                if ($info) {
                    $res = ['file' => $f, 'w' => (int) $info[0], 'h' => (int) $info[1]];
                } elseif (!$first && microtime(true) > $deadline - 3.0) {
                    $res = ['error' => 'time'];
                } elseif (!$this->client->obtainable($url, true)) {
                    $res = ['error' => 'фото закрыто robots.txt поставщика (или не на его сайте) — не запрашиваю'];   // запроса нет
                } else {
                    $first = false;
                    $r = $this->client->get($url, true);
                    if (!empty($r['missing'])) $res = ['error' => 'нет среди сохранённых ответов'];
                    elseif ($r['code'] !== 200) $res = ['error' => 'ответ ' . $r['code']];
                    else {
                        $tmp = $sup::dir($this->runId) . '/dl-' . md5($url) . '.bin';
                        file_put_contents($tmp, $r['body']);
                        $res = $this->frame($tmp, $f);
                        @unlink($tmp);
                    }
                }
                if ($k === 0) { $out[$i] = $res; if (!isset($res['file'])) break; continue; }   // главное не получено — остальные не нужны
                if (($res['error'] ?? '') === 'time') { $out[$i] = ['error' => 'time']; break; }
                if (isset($res['file'])) $out[$i]['more'][] = $res;
                else $out[$i]['lost'][] = $url . ' (' . ($res['error'] ?? '?') . ')';  // доп. фото не скачалось — товар с остальными
            }
        }
        return $out;
    }

    protected function dropPhotos(array $photos): void
    {
        foreach ($photos as $p) {
            if (isset($p['file'])) @unlink($p['file']);
            foreach ($p['more'] ?? [] as $m) if (isset($m['file'])) @unlink($m['file']);
        }
    }

    /** Главное и дополнительные фото товара ($photo — элемент photos()): строки product_images, файлы, главное фото */
    protected function storePhotos(array &$it, array $photo, array &$moved, string $now): void
    {
        $before = count($it['stored'] ?? []);
        $this->storePhoto($it, $photo, [], $moved, $now);
        $main = (int) ($it['stored'][$before] ?? 0);
        $sort = 0;
        foreach ($photo['more'] ?? [] as $m) {
            $this->storePhoto($it, $m, [], $moved, $now);
            App::db()->update('product_images', ['sort' => ++$sort], 'id = ?', [(int) end($it['stored'])]);
        }
        if ($main && count($it['stored']) > $before + 1) App::db()->update('products', ['image_id' => $main], 'id = ?', [(int) $it['id']]);   // главное — первое
    }

    // ================================================================== встреченные и отчёт

    /** Встреченные товары поставщика (для повторов и скрытия пропавших): записанные и связанные, даже если пропущены */
    protected function seen(array $items): void
    {
        $rows = [];
        foreach ($items as $it) {
            if (!in_array($it['action'], ['create', 'update', 'same'], true) && !($it['via'] === 'link' && $it['ex'])) continue;
            $rows[$it['code']] = [$this->runId, $it['code'], $it['id'] ? (int) $it['id'] : ($it['ex']['id'] ?? null)];
        }
        // пропущенные и чужие — тоже отмечаются кодом (повтор на другой странице не обрабатывается заново)
        foreach ($items as $it) if (!isset($rows[$it['code']]) && $it['why'] !== 'dup') $rows[$it['code']] = [$this->runId, $it['code'], null];
        foreach (array_chunk(array_values($rows), 500) as $part) {
            App::db()->query('INSERT IGNORE INTO supplier_run_seen (run_id, code, product_id) VALUES ' . implode(',', array_fill(0, count($part), '(?,?,?)')), array_merge(...$part));
        }
    }

    /** « — пара: закупка 400 → сайт 480 грн (старая 520), ящик 8» */
    protected function priceText(array $it): string
    {
        return ' — пара: закупка ' . self::num((float) $it['pair']) . ' → сайт ' . self::num((float) $it['price']) . ' грн' . ((float) $it['compare'] > 0 ? ' (старая ' . self::num((float) $it['compare']) . ')' : '')
            . ', ящик ' . (int) $it['box'];
    }

    protected function report(array $items): void
    {
        $sup = static::SUPPLIER;
        $dry = $this->dry;
        foreach ($items as $it) {
            $this->stats['products']++;
            $a = $it['action'];
            $price = $this->priceText($it);
            $warn = $it['warn'] ? ' (' . implode('; ', $it['warn']) . ')' : '';
            if ($it['warn']) $this->stats['warnings']++;
            if ($it['cat_id'] === null && !$it['map']['skip'] && in_array($a, ['create', 'update', 'same', 'existing'], true) || $a === 'skip' && $it['why'] === 'category') {
                $this->stats['unmapped'][$it['map']['key']] = ($this->stats['unmapped'][$it['map']['key']] ?? 0) + 1;
            }
            if (in_array($a, ['update', 'same', 'existing'], true) && $it['via'] !== '') $this->stats['found'][$it['via']] = ($this->stats['found'][$it['via']] ?? 0) + 1;
            if (!empty($it['stock_out']) && in_array($a, ['update', 'same'], true)) $this->stats['out'] = (int) ($this->stats['out'] ?? 0) + 1;
            switch ($a) {
                case 'skip':
                    $this->stats['skipped']++;
                    $why = $it['why'] ?: 'other';
                    if (in_array($why, static::MASS_SKIP, true)) { $this->skipCount($why, 'Пропущен «' . $it['name'] . '» (' . $it['code'] . '): ' . (static::SKIP[$why] ?? $why)); continue 2; }
                    $this->stats['skip'][$why] = ($this->stats['skip'][$why] ?? 0) + 1;
                    if ($it['level'] === 'error') $this->stats['errors']++;
                    if ($it['level'] === 'warn') $this->stats['warnings']++;
                    $n = $this->stats['examples'][$why] = (int) ($this->stats['examples'][$why] ?? 0) + 1;
                    if ($n > 50 && $it['level'] === 'skip') continue 2;
                    $sup::log($this->runId, $it['level'] === 'skip' ? 'skip' : $it['level'], ($it['msg'] !== '' ? $it['msg'] : 'Пропущен «' . $it['name'] . '» (' . $it['code'] . '): ' . (static::SKIP[$why] ?? $why)),
                        $it['ex']['id'] ?? null, $this->data($it));
                    break;
                case 'existing':
                    $this->stats['existing']++;
                    $n = $this->stats['examples']['existing'] = (int) ($this->stats['examples']['existing'] ?? 0) + 1;
                    if ($n <= static::LOG_SAME) $sup::log($this->runId, 'skip', 'Есть на сайте: id=' . $it['id'] . ' «' . $it['ex']['name'] . '» (' . ((string) $it['ex']['supplier'] !== '' ? $it['ex']['supplier'] : 'заведён вручную')
                        . ', найден ' . (static::VIA[$it['via']] ?? $it['via']) . ') — не взят под загрузчик, дубль не создан' . $price, (int) $it['id'], $this->data($it));
                    break;
                case 'create':
                    $this->stats['created']++;
                    $this->plannedNew = max(0, $this->plannedNew - 1);
                    $this->stats['photos'] += count($it['stored'] ?? []);
                    $sup::log($this->runId, 'add', ($dry ? 'Будет добавлен' : 'Добавлен id=' . $it['id']) . ' «' . $it['row']['name'] . '» (' . $it['code'] . ', ' . $it['size'] . ') в «'
                        . ($this->ci['path'][$it['cat_id']] ?? $it['cat_id']) . '»' . $price . $warn, $it['id'] ?: null, $this->data($it));
                    break;
                case 'update':
                    $this->stats['updated']++;
                    $this->stats['photos'] += count($it['stored'] ?? []);
                    $sup::log($this->runId, 'update', ($dry ? 'Будет изменён' : 'Изменён') . ' id=' . $it['id'] . ' «' . $it['ex']['name'] . '»: ' . implode('; ', $it['changes']) . $price . $warn
                        . ($it['via'] !== 'link' ? ' [найден ' . (static::VIA[$it['via']] ?? $it['via']) . ', взят под загрузчик]' : ''), (int) $it['id'], $this->data($it));
                    break;
                case 'same':
                    $this->stats['same']++;
                    $n = $this->stats['examples']['same'] = (int) ($this->stats['examples']['same'] ?? 0) + 1;
                    if ($n <= static::LOG_SAME) $sup::log($this->runId, 'same', 'Без изменений id=' . $it['id'] . ' «' . $it['ex']['name'] . '»' . $price . $warn, (int) $it['id'], $this->data($it));
                    break;
            }
        }
    }

    // ================================================================== завершение

    /**
     * Пропавшие в полном проходе связанные товары, которые ещё не скрываются (меньше N проходов подряд): $rows — code,
     * product_id, missed (до этого прохода), status, in_stock, name. По умолчанию ничего (товар бренда «нет в наличии» видно
     * в списке поставщика — Tom.m); у поставщика, который товары без остатка не показывает (Jong•Golf), — «нет в наличии».
     * @return int сколько товаров получат «нет в наличии»
     */
    protected function missingStock(array $rows): int
    {
        return 0;
    }

    /**
     * Полный проход по всем страницам ($complete): связанные товары, которых не было в проходе, получают +1 к missed;
     * достигшие N (настройка missing_runs, по умолчанию 2) полных проходов подряд — скрываются (не удаляются). Защита: пропало
     * больше половины активных товаров поставщика — похоже на сбой, ничего не меняется. Товары, не взятые под загрузчик,
     * и товары других поставщиков не трогаются. В конце — сброс кэша сайта.
     */
    public function finish(bool $complete, ?callable $alive = null): void
    {
        $sup = static::SUPPLIER;
        $code = $sup::CODE;
        $db = App::db();
        $s = &$this->stats;
        $n = max(1, (int) $this->cfg['missing_runs']);
        $out = 0;
        if (!$complete) {
            $sup::log($this->runId, 'info', 'Скрытие пропавших не выполнялось: не полный проход по всем страницам (быстрый режим, сохранённые страницы, лимит, ошибка или только часть товаров)');
        } elseif ($this->cfg['hide_missing'] !== '1') {
            $sup::log($this->runId, 'info', '«Скрывать пропавшие у поставщика» выключено — товары не скрываются');
        } elseif ($alive && !$alive()) {
            $sup::log($this->runId, 'info', 'Запуск остановлен — скрытие пропавших не выполнялось');
        } elseif ((int) $s['products'] === 0) {
            $sup::log($this->runId, 'warn', 'У поставщика не прочитано ни одного товара — скрытие отменено');
        } else {
            $missing = $db->all('SELECT l.code, l.product_id, l.missed, l.hidden, p.status, p.in_stock, p.name FROM supplier_links l JOIN products p ON p.id = l.product_id
                LEFT JOIN supplier_run_seen s ON s.run_id = ? AND s.code = l.code WHERE l.supplier = ? AND s.code IS NULL ORDER BY l.product_id', [$this->runId, $code]);
            $active = (int) $db->value('SELECT COUNT(*) FROM products WHERE supplier = ? AND status = 1', [$code]);
            $missingActive = count(array_filter($missing, static fn($r) => (int) $r['status'] === 1));
            if ($missingActive && $missingActive > $active * 0.5) {
                $s['warnings']++;
                $s['hide_cancelled'] = $missingActive;
                $sup::log($this->runId, 'warn', 'Скрытие отменено: в проходе нет ' . $missingActive . ' из ' . $active . ' активных товаров «' . $sup::TITLE . '» — похоже на сбой сайта поставщика или смену шаблона. Счётчики пропусков не изменены.');
            } elseif ($missing) {
                $hide = []; $codes = [];
                foreach ($missing as $r) if ((int) $r['missed'] + 1 >= $n && (int) $r['status'] === 1) { $hide[(int) $r['product_id']] = (string) $r['name']; $codes[(int) $r['product_id']] = (string) $r['code']; }
                $ids = array_keys($hide);
                if (!$this->dry) {
                    foreach (array_chunk(array_column($missing, 'code'), 500) as $part) {
                        [$ph, $v] = $db->in($part);
                        $db->query("UPDATE supplier_links SET missed = LEAST(missed + 1, 60000), updated_at = NOW() WHERE supplier = ? AND code IN ($ph)", array_merge([$code], $v));
                    }
                    if ($ids) {
                        CatalogIndexer::locked(function () use ($db, $ids, $codes, $n, $code): void {
                            $db->transaction(function () use ($db, $ids, $codes, $n, $code): void {
                                $snap = CatalogIndexer::snapshot($ids);
                                foreach (array_chunk($ids, 500) as $part) {
                                    [$ph, $v] = $db->in($part);
                                    $db->query("UPDATE products SET status = 0, updated_at = NOW() WHERE id IN ($ph)", $v);
                                    $db->query("UPDATE supplier_links SET hidden = 1 WHERE supplier = ? AND product_id IN ($ph)", array_merge([$code], $v));
                                }
                                $this->journalHidden($codes, 'нет у поставщика ' . $n . ' полн. проходов подряд');
                                CatalogIndexer::products($ids, $snap, false);
                            });
                        });
                    }
                }
                foreach (array_slice($hide, 0, 500, true) as $pid => $name) {
                    $sup::log($this->runId, 'hide', ($this->dry ? 'Будет скрыт' : 'Скрыт') . ' id=' . $pid . ' «' . $name . '» — нет у поставщика ' . $n . ' полных проходов подряд', $pid, ['a' => 'hide_missing', 'pid' => $pid, 'name' => $name]);
                }
                $s['hidden_missing'] = count($ids);
                // остальные пропавшие (ещё не N проходов) — адаптер, у которого «нет на сайте поставщика» = «нет в наличии» (Jong•Golf), ставит «нет в наличии»
                $out = $this->missingStock(array_values(array_filter($missing, static fn($r) => !isset($hide[(int) $r['product_id']]))));
                $sup::log($this->runId, 'info', 'Нет в этом проходе: ' . count($missing) . ' связанных товаров (активных ' . $missingActive . ')' . ($this->dry ? ' — при записи счётчик пропусков +1' : '')
                    . '; скрыто — ' . count($ids) . ' (нет ' . $n . ' прохода подряд)' . ($out ? '; «нет в наличии» — ' . $out : ''));
            } else {
                $sup::log($this->runId, 'info', 'Все связанные товары есть у поставщика — скрывать нечего');
            }
        }
        if ($s['unmapped']) {
            arsort($s['unmapped']);
            $s['unmapped'] = array_slice($s['unmapped'], 0, 300, true);
            $sup::log($this->runId, 'warn', 'Несопоставленные категории (раздел|категория поставщика|название — товаров): ' . implode('; ', array_map(static fn($k, $v) => $k . ' — ' . $v,
                array_keys(array_slice($s['unmapped'], 0, 30, true)), array_slice($s['unmapped'], 0, 30, true))) . (count($s['unmapped']) > 30 ? '; …' : ''));
        }
        if (!$this->dry && ($s['created'] + $s['updated'] + $s['hidden_missing'] + $out) > 0) Cache::flush();
    }
}
