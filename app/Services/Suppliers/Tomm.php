<?php
declare(strict_types=1);

namespace App\Services\Suppliers;

use App\Core\App;
use App\Services\Import\Importer;

/**
 * Загрузка товаров бренда Tom.m с официального сайта бренда tomm.ua (Webasyst Shop-Script; магазин — дилер бренда, данные
 * и фото брать можно) — адаптер каркаса поставщиков (WebSupplier). API и фидов у бренда нет: источник — список поиска
 * /ru/search/?query=&page=N с cookie products_per_page=100 — весь каталог за 17 запросов (новые сверху): id, код, раздел,
 * цена пары и ящика, старая цена, пар в ящике, ряд, наличие, фото. Карточка (1 запрос) — только для нового товара
 * (линейка, сезон, материалы); фото — только для новых и найденных без фото. Клиент — TommClient (пауза, robots.txt).
 *
 * Фазы запуска (state, продолжение после обрыва):
 *   start → проверки (robots.txt, наценка, таблица разделов, характеристики, бренд Tom.m на сайте);
 *   list  → страницы списка → storage/import/tomm/runs/N/page-K.html (после обрыва повторно не качаются); full — все
 *           страницы (число — по пагинации первой), quick — сверху, пока на странице есть несвязанные товары (≤ quick_pages);
 *           страница без плиток или не с тем числом плиток (сменился шаблон, сайт не принял cookie) — ошибка: в каталог
 *           ничего не пишется, товары не скрываются;
 *   match → сопоставление всех товаров бренда с нашими сразу (TommSync::plan: связь → точный код → код без приставки
 *           Forsage) — порядок правил общий для всего каталога, как в разборе → runs/N/items.json;
 *   items → товары порциями по 40 (TommSync: цена с наценкой, наличие, запись, карточка и фото новых);
 *   finish→ только полный проход: связанные товары, которых нет у бренда 2 полных прохода подряд, скрыть (защита:
 *           больше 50 % активных — отмена); сброс кэша, уборка.
 * Расписание (решение владельца): полная сверка цен и наличия всех товаров каждые 5 ч круглые сутки — 17 запросов
 * на проход, ≈85 в сутки (плюс карточки и фото новых, robots.txt раз в сутки).
 * Без сети: src = file — сохранённые страницы (кэш разбора или страницы прошлого запуска).
 */
final class Tomm extends WebSupplier
{
    public const CODE = 'tomm';
    public const TITLE = 'Tom.m (tomm.ua)';
    public const SITE = 'https://tomm.ua';
    /** Наличие у поставщика переносится на сайт («нет в наличии», а не скрытие) — список «Нет в наличии» в «Обзоре» */
    public const TRACKS_STOCK = true;
    /** Список — поиск с пустым запросом: весь каталог, сортировка «по дате добавления», новые сверху */
    public const LIST_PATH = '/ru/search/';
    public const PER_PAGE = ['100' => '100 — весь каталог за 17 запросов', '48' => '48 — как в меню сайта (36 запросов)'];

    public const DEFAULTS = [
        'enabled' => '0',
        // решение владельца: полная сверка цен и наличия всех товаров каждые 5 ч круглые сутки (cron раз в час решает, пора ли);
        // полный проход — 17 запросов, поэтому быстрый режим по расписанию не нужен (остаётся для ручного запуска)
        'window_from' => '0', 'window_to' => '23', 'period' => '5', 'full_hours' => '5', 'full_from' => '0', 'full_to' => '23',
        // запросов за запуск: проход — 17, новый товар — карточка и фото (2–3); загрузка с нуля (≈1 150 товаров в наличии) — 3 запуска
        'delay' => '2', 'max_requests' => '1500', 'per_page' => '100', 'quick_pages' => '2',
        // наценка: не задана — включить загрузку нельзя (цена бренда — закупочная)
        'markup_k' => '', 'markup_plus' => '0', 'round_step' => '1', 'round_mode' => 'math',
        'add_new' => '1', 'max_new' => '500', 'card_features' => '1',
        'update_existing' => '1', 'update_category' => '0', 'add_photos' => '1', 'keep_manual_hidden' => '1',
        'hide_missing' => '1', 'missing_runs' => '2', 'adopt' => '1',
        // бренды сайта, товары которых — Tom.m (поиск существующих только среди них); новые — в первый
        'brands' => "Tom.m\nTomm.m",
        'attributes' => "size=Размер\npairs=Кол-во пар\nmaterial=Материал\nlining=Материал внутри\nsole=Материал подошвы\ngender=Пол\nbrand=Бренд",
        'gender' => 'дети',
        'photo_width' => '700', 'photo_height' => '700', 'photo_quality' => '90',
        'log_days' => '7', 'history_days' => '365',
    ];
    public const BOOLS = ['enabled', 'add_new', 'card_features', 'update_existing', 'update_category', 'add_photos', 'keep_manual_hidden', 'hide_missing', 'adopt'];
    /** Поля товара бренда для характеристик (левая часть строк «поле=Характеристика») */
    public const FIELDS = ['size' => 'размерный ряд («Размер»)', 'pairs' => 'пар в ящике', 'material' => 'материал верха (карточка)', 'lining' => 'материал подкладки (карточка)',
        'sole' => 'материал подошвы (карточка)', 'season' => 'сезон (карточка)', 'line' => 'линейка бренда («Бренд» в карточке: BiKi …)', 'gender' => 'пол (настройка «Пол»)',
        'brand' => 'бренд сайта'];

    // ================================================================== настройки

    /** Сохранить настройки из формы. @return array [ошибки по полям] */
    public static function saveSettings(array $post): array
    {
        $in = [];
        $err = [];
        foreach (self::DEFAULTS as $k => $def) {
            $in[$k] = in_array($k, self::BOOLS, true) ? (!empty($post[$k]) ? '1' : '0') : trim(str_replace("\r\n", "\n", (string) ($post[$k] ?? $def)));
        }
        self::checkCommon($in, $err, ['window_from' => [0, 23], 'window_to' => [0, 23], 'period' => [1, 168], 'full_hours' => [1, 720], 'full_from' => [0, 23], 'full_to' => [0, 23],
            'max_requests' => [20, 5000], 'quick_pages' => [1, 40], 'max_new' => [0, 5000], 'missing_runs' => [1, 10],
            'photo_width' => [0, 3000], 'photo_height' => [0, 3000], 'photo_quality' => [30, 100], 'log_days' => [1, 90], 'history_days' => [30, 3650]]);
        if (!isset(self::PER_PAGE[$in['per_page']])) $in['per_page'] = '100';
        $in['brands'] = mb_substr($in['brands'], 0, 1000);
        $in['gender'] = mb_substr($in['gender'], 0, 100);
        if (!self::brandIds($in['brands'])) $err['brands'] = 'На сайте нет ни одного из этих брендов';
        [, $attrErr] = self::attributes($in['attributes']);
        if ($attrErr) $err['attributes'] = implode('; ', $attrErr);
        if ($err) return $err;
        self::store($in);
        return [];
    }

    /** Характеристики из строк «поле=Характеристика сайта». @return array{0: [поле => строка features], 1: string[] ошибки} */
    public static function attributes(?string $text = null): array
    {
        return JongGolf::featureLines($text ?? self::cfg('attributes'), self::FIELDS);
    }

    /** Бренды сайта Tom.m (настройка brands): [id, …] в порядке настройки — первый для новых товаров */
    public static function brandIds(?string $text = null): array
    {
        $want = [];
        foreach (preg_split('~\r\n|\n~', $text ?? self::cfg('brands')) ?: [] as $b) if (($k = HitObuviSync::bkey($b)) !== '') $want[$k] = count($want);
        if (!$want) return [];
        $found = [];
        foreach (App::db()->all('SELECT id, name, product_count FROM brands') as $b) {
            $k = HitObuviSync::bkey((string) $b['name']);
            if (isset($want[$k]) && (!isset($found[$k]) || (int) $b['product_count'] > $found[$k][1])) $found[$k] = [(int) $b['id'], (int) $b['product_count']];
        }
        uksort($found, static fn($a, $b) => $want[$a] <=> $want[$b]);
        return array_values(array_map(static fn($x) => $x[0], $found));
    }

    public static function ownBrandIds(): array
    {
        return self::brandIds();
    }

    /** Код связи — id товара на tomm.ua (стабилен), людям нужен артикул: «60112A (id 24334)» */
    public static function codeLabel(string $code, ?string $ext = null): string
    {
        return $ext !== null && $ext !== '' ? $ext . ' (id ' . $code . ')' : $code;
    }

    // ================================================================== запуски

    /**
     * Начать запуск. $o: src — web | file; dir — папка сохранённых страниц для src = file (кэш разбора, страницы прошлого
     * запуска); cache — сквозной кэш для src = web (проверки: повторно не скачивать); mode — quick | full | auto;
     * limit — не больше N товаров; ids — только эти товары бренда (проверка); origin — admin | cron | cli; user_id.
     * Боевой запуск с сайта — только с наценкой (по расписанию — и при включённой загрузке); запись по файлу — только из CLI.
     */
    public static function start(string $kind, array $o = []): int
    {
        self::ensureSchema();
        if (!isset(self::KINDS[$kind]) || $kind === 'test') throw new \InvalidArgumentException('Неизвестный вид запуска');
        $src = ($o['src'] ?? 'web') === 'file' ? 'file' : 'web';
        $origin = in_array($o['origin'] ?? '', ['cron', 'cli'], true) ? $o['origin'] : 'admin';
        if ($kind === 'run' && $src === 'web' && $origin === 'cron' && !self::enabled()) throw new \RuntimeException('Загрузка Tom.m выключена — запуск по расписанию не выполняется.');
        if ($kind === 'run' && $src === 'file' && $origin !== 'cli') throw new \RuntimeException('Запись по сохранённым страницам — только из командной строки (bin/supplier-tomm.php --write).');
        if ($kind === 'run' && self::markup() === null) throw new \RuntimeException('Наценка не задана — запись товаров невозможна (цены бренда закупочные). Укажите коэффициент в настройках.');
        self::assertIdle();
        $mode = $o['mode'] ?? 'auto';
        if ($mode === 'auto' || !isset(self::MODES[$mode])) $mode = $kind === 'run' ? self::autoMode() : 'full';
        $files = [];
        $dir = null;
        if ($src === 'file') {
            $dir = rtrim(str_replace('\\', '/', (string) ($o['dir'] ?? '')), '/');
            if ($dir === '' || !is_dir($dir)) throw new \RuntimeException('Нет папки с сохранёнными страницами бренда: ' . $dir);
            $files = self::savedPages($dir);
            if (!$files) throw new \RuntimeException('В папке ' . $dir . ' нет сохранённых страниц списка tomm.ua (поиск с products_per_page).');
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', (array) ($o['ids'] ?? [])))));
        $state = [
            'phase' => 'start', 'mode' => $src === 'file' ? 'files' : $mode, 'complete' => false, 'page' => 0, 'pages' => 0, 'pos' => 0, 'count' => 0,
            'per_page' => (int) self::cfg('per_page') ?: 100, 'files' => $files, 'dir' => $dir, 'cache' => $src === 'web' && !empty($o['cache']) ? (string) $o['cache'] : null,
            'limit' => isset($o['limit']) && (int) $o['limit'] > 0 ? (int) $o['limit'] : null, 'ids' => $ids ?: null,
            'fails' => 0, 'retry_at' => 0, 'requests' => 0, 'debug' => App::isDebug(),
        ];
        $id = self::createRun($kind, $src, $origin, !empty($o['user_id']) ? (int) $o['user_id'] : null, $state);
        $m = self::markup();
        $what = match (true) {
            $kind === 'dry' => 'пробный прогон: в каталог ничего не записывается',
            $src === 'file' => 'запись по сохранённым страницам (сеть не используется)',
            default => 'боевой запуск',
        };
        self::log($id, 'info', 'Загрузчик стартовал ' . date('d.m.Y H:i:s') . ' — ' . $what . '; ' . ($src === 'file'
                ? 'страниц в папке: ' . count($files) . ' (' . $dir . ')'
                : 'режим: ' . self::MODES[$mode] . ', список ' . self::SITE . self::LIST_PATH . ' по ' . $state['per_page'] . ' товаров, пауза ' . self::cfg('delay') . ' с, не больше ' . (int) self::cfg('max_requests') . ' запросов')
            . ($state['limit'] ? ', не больше ' . $state['limit'] . ' товаров' : '') . ($ids ? ', только товары бренда ' . implode(', ', $ids) : '') . '. '
            . ($m ? 'Наценка: ×' . $m['k'] . ($m['plus'] ? ' + ' . $m['plus'] . ' грн' : '') . ', округление ' . self::ROUND_STEPS[(string) $m['step']] . ($m['step'] ? ' (' . self::ROUND_MODES[$m['mode']] . ')' : '')
                : 'Наценка НЕ задана — в отчёте цены бренда (закупочные).'));
        self::flushLog();
        return $id;
    }

    /** Адрес страницы списка (как ссылки пагинации сайта: первая — без page) */
    public static function pageUrl(int $n): string
    {
        return self::SITE . self::LIST_PATH . ($n > 1 ? '?page=' . $n . '&query=' : '?query=');
    }

    /**
     * Сохранённые страницы списка в папке: общий кэш (имя.md5.html + .meta с адресом и cookie products_per_page) или папка
     * запуска (page-K.html + page-K.json). @return [[адрес, файл], …] по номеру страницы
     */
    public static function savedPages(string $dir): array
    {
        $out = [];
        foreach (glob($dir . '/*.html') ?: [] as $f) {
            $cached = is_file($f . '.meta');                                   // общий кэш (иначе — папка запуска: page-K.json)
            $meta = $cached ? json_decode((string) file_get_contents($f . '.meta'), true) : (is_file(substr($f, 0, -5) . '.json') ? json_decode((string) file_get_contents(substr($f, 0, -5) . '.json'), true) : null);
            $url = (string) ($meta['url'] ?? '');
            if ($url === '' || (int) ($meta['code'] ?? 200) !== 200 || rtrim((string) parse_url($url, PHP_URL_PATH), '/') !== rtrim(self::LIST_PATH, '/')) continue;
            if ($cached && !str_contains((string) ($meta['cookie'] ?? ''), 'products_per_page=')) continue;   // список без cookie — по 30, не весь каталог
            parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
            if (trim((string) ($q['query'] ?? '')) !== '') continue;
            $out[max(1, (int) ($q['page'] ?? 1))] = [$url, str_replace('\\', '/', $f)];
        }
        ksort($out);
        return array_values($out);
    }

    /** Клиент запуска: пауза и лимит из настроек, cookie числа товаров на странице, сквозной кэш или папка сохранённых страниц */
    private static function client(array $st): TommClient
    {
        $c = new TommClient((float) (Importer::num(self::cfg('delay')) ?? 2.0), max(20, (int) self::cfg('max_requests')), $st['dir'] ?? null, $st['cache'] ?? null);
        $c->setCookie('products_per_page=' . (int) ($st['per_page'] ?? self::cfg('per_page') ?: 100));
        $c->setUsed((int) ($st['requests'] ?? 0));
        return $c;
    }

    public static function viaNames(): array
    {
        return TommSync::VIA;
    }

    public static function skipReasons(): array
    {
        return TommSync::SKIP;
    }

    /** Машина состояний запуска (см. описание класса) */
    protected static function advance(array &$run, float $deadline): void
    {
        $id = (int) $run['id'];
        $st = &$run['state'];
        $dry = $run['kind'] === 'dry';
        unset($st['wait']);
        // наценку сняли во время запуска — дальше товары записались бы по закупочной цене: запуск останавливается с ошибкой
        if (!$dry && self::markup() === null) throw new SupplierError('Наценка не задана (снята во время запуска) — запись остановлена, чтобы не ставить на сайт закупочные цены. Укажите коэффициент и запустите снова.', 'markup');
        if ((int) ($st['retry_at'] ?? 0) > time()) { $st['wait'] = 1; return; }   // пауза после 429/5xx — шаг ничего не делает
        $client = self::client($st);
        $sync = null;
        try {
            while (microtime(true) < $deadline) {
                if (self::stopped($id)) {
                    self::save($run);
                    self::dropPhotoCache($id);
                    return;
                }
                switch ($st['phase']) {
                    case 'start':
                        $map = TommMap::count();
                        if (!$map['cats']) TommMap::seed();
                        $map = TommMap::count();
                        self::log($id, 'info', 'Таблица разделов: разделов бренда ' . $map['cats'] . ' (загружаются ' . $map['active'] . ($map['unresolved'] ? ', без категории сайта ' . $map['unresolved'] : '') . ')');
                        [, $attrErr] = self::attributes();
                        if ($attrErr) throw new \RuntimeException('Характеристики в настройках: ' . implode('; ', $attrErr));
                        if (!self::brandIds()) throw new \RuntimeException('На сайте нет бренда из настройки «Бренды Tom.m на сайте» (' . str_replace("\n", ', ', self::cfg('brands')) . ')');
                        if ($run['src'] === 'web' && !$client->allowed(self::pageUrl(1))) throw new SupplierError('robots.txt бренда запрещает список ' . self::LIST_PATH . ' — загрузка невозможна', 'robots');
                        $st['phase'] = 'list';
                        break;

                    case 'list':
                        self::nextPage($run, $client);
                        break;

                    case 'match':
                        $items = TommSync::plan(self::listItems($run), $id);
                        if ($st['ids']) {
                            // только часть товаров — проход не полный: ничего не скрывает и для расписания не «полная сверка»
                            $items = array_values(array_filter($items, static fn($p) => in_array((int) $p['id'], $st['ids'], true)));
                            $st['complete'] = false;
                        }
                        file_put_contents(self::dir($id) . '/items.json', json_encode($items, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                        $via = array_count_values(array_map(static fn($p) => $p['m']['via'] ?: 'new', $items));
                        self::log($id, 'info', 'Товаров бренда в списке: ' . count($items) . '; сопоставление с нашими Tom.m: ' . implode(', ', array_map(static fn($k, $n) => (TommSync::PLAN[$k] ?? $k) . ' — ' . $n, array_keys($via), $via)));
                        $st['count'] = count($items);
                        $st['pos'] = 0;
                        $st['phase'] = 'items';
                        break;

                    case 'items':
                        $items = self::loadItems($id);
                        $sync ??= new TommSync($id, $dry, $run['stats'], $client);
                        $left = $st['limit'] !== null ? max(0, (int) $st['limit'] - (int) $run['stats']['products']) : null;
                        $st['pos'] = $sync->process($items, (int) $st['pos'], [], $deadline, $left, static function (int $pos) use (&$run, &$st, $id, $client): bool {
                            $st['pos'] = $pos;
                            $st['requests'] = $client->used();
                            $run['stats']['requests'] = $client->used();
                            self::save($run);
                            self::flushLog();
                            return !self::stopped($id);
                        });
                        $st['fails'] = 0;
                        if ($sync->deferred()) {
                            if (empty($st['waits'])) self::log($id, 'info', 'Идёт перестройка индекса каталога или карточки и фото не уложились в шаг — запись порции продолжится следующим шагом');
                            $st['waits'] = (int) ($st['waits'] ?? 0) + 1;
                            $st['wait'] = $sync->deferredBy() === 'lock' ? 1 : 0;
                            $st['requests'] = $client->used();
                            $run['stats']['requests'] = $client->used();
                            self::save($run);
                            self::flushLog();
                            return;
                        }
                        if ($st['limit'] !== null && (int) $run['stats']['products'] >= (int) $st['limit']) {
                            self::log($id, 'info', 'Достигнут лимит ' . $st['limit'] . ' товаров — остальное не обрабатывается, скрытие отсутствующих не выполняется');
                            $st['complete'] = false;
                            $st['phase'] = 'finish';
                            break;
                        }
                        if ($st['pos'] >= count($items)) $st['phase'] = 'finish';
                        break;

                    case 'finish':
                        $sync ??= new TommSync($id, $dry, $run['stats'], $client);
                        // скрытие — только полный проход по всем страницам с сайта (сохранённые страницы — только в пробном прогоне: покажет, что будет скрыто)
                        $sync->finish(!empty($st['complete']) && empty($st['ids']) && ($st['mode'] === 'full' || ($st['mode'] === 'files' && $dry)), static fn(): bool => !self::stopped($id));
                        $s = $run['stats'];
                        self::log($id, 'info', '==========');
                        self::log($id, 'info', 'Запросов к сайту бренда: ' . $s['requests'] . ', страниц списка: ' . $s['pages'] . ', товаров: ' . $s['products']);
                        self::log($id, 'info', ($dry ? 'Будет добавлено: ' : 'Добавлено товаров: ') . $s['created'] . ' (фото: ' . $s['photos'] . ')');
                        self::log($id, 'info', ($dry ? 'Будет изменено: ' : 'Изменено товаров: ') . $s['updated'] . ' (без изменений: ' . $s['same'] . '; нет в наличии у бренда: ' . (int) ($s['out'] ?? 0) . ')');
                        self::log($id, 'info', 'Найдено на сайте, но не под загрузчиком: ' . $s['existing'] . self::foundText($s['found']));
                        self::log($id, 'info', ($dry ? 'Будет скрыто: ' : 'Скрыто: ') . $s['hidden_missing'] . ' (нет у бренда ' . (int) self::cfg('missing_runs') . ' полных проходов подряд)');
                        self::log($id, 'info', 'Пропущено: ' . $s['skipped'] . self::skipText($s['skip']));
                        self::log($id, 'info', 'Работа завершена ' . date('d.m.Y H:i:s'));
                        self::save($run);
                        if (!self::finishRun($id, 'done')) self::log($id, 'warn', 'Запуск остановлен во время завершения — статус «Остановлен» сохранён');
                        self::flushLog();
                        self::dropPhotoCache($id);
                        self::cleanup();
                        return;
                }
                $st['requests'] = $client->used();
                $run['stats']['requests'] = $client->used();
                self::save($run);
                self::flushLog();
            }
        } catch (SupplierError $e) {
            self::onSiteError($run, $e, $client);                          // 429/5xx — пауза, лимит — проход неполный, прочее — ошибка запуска
        }
    }

    /**
     * Следующая страница списка: из папки (src = file) или с сайта. Страница сохраняется в папке запуска (page-K.html +
     * page-K.json). Проверки: плитки есть, на неполной странице — их ровно per_page (иначе сменился шаблон или сайт не
     * принял cookie — ошибка, в каталог ничего не пишется). Последняя страница / быстрый проход — фаза match.
     */
    private static function nextPage(array &$run, TommClient $client): void
    {
        $id = (int) $run['id'];
        $st = &$run['state'];
        $n = (int) $st['page'] + 1;
        $dir = self::dir($id);
        if ($st['mode'] === 'files') {
            [$url, $file] = $st['files'][$n - 1];
            $html = (string) @file_get_contents($file);
        } else {
            $url = self::pageUrl($n);
            $file = $dir . '/page-' . $n . '.html';
            if (is_file($file)) {
                $html = (string) file_get_contents($file);                      // получена до обрыва шага
            } else {
                $r = $client->get($url);
                $st['fails'] = 0;
                if ($r['code'] !== 200) throw new SupplierError('Страница ' . $url . ': ответ ' . $r['code'], $r['code'] === 404 ? 'layout' : 'retry', $r['code']);
                $html = $r['body'];
                file_put_contents($file, $html);
            }
        }
        $page = TommParser::listPage($html);
        if (!$page['items']) throw new SupplierError('Страница ' . $url . ' не похожа на список товаров (нет плиток товаров — сменился шаблон сайта бренда?) — загрузка остановлена, в каталог ничего не записано, товары не скрываются', 'layout');
        if ($n === 1) {
            $st['pages'] = (int) $page['pages'];
            if ($st['mode'] === 'files' && $page['per_page']) $st['per_page'] = (int) $page['per_page'];   // сохранённые — с тем числом на странице, с каким скачаны
        }
        $st['pages'] = max((int) $st['pages'], (int) $page['pages']);
        $per = (int) $st['per_page'];
        if ($n < (int) $st['pages'] && count($page['items']) !== $per) {
            throw new SupplierError('Страница ' . $n . ' из ' . $st['pages'] . ': товаров ' . count($page['items']) . ' вместо ' . $per . ' (сайт бренда не принял «товаров на странице» или сменился шаблон) — загрузка остановлена, в каталог ничего не записано, товары не скрываются', 'layout');
        }
        file_put_contents($dir . '/page-' . $n . '.json', json_encode(['url' => $url, 'code' => 200, 'items' => count($page['items'])], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $run['stats']['pages']++;
        $st['page'] = $n;
        $st['cur'] = ['n' => $n, 'of' => (int) $st['pages']];
        self::log($id, 'info', 'Страница ' . $n . ' из ' . $st['pages'] . ($st['mode'] === 'files' ? ' (сохранённая)' : '') . ': ' . $url . ' — товаров ' . count($page['items']));
        if ($st['mode'] === 'files') {
            // все страницы на месте — пробный прогон покажет и скрытие (запись по сохранённым страницам не скрывает никогда)
            if ($n >= count($st['files'])) { $st['complete'] = $n >= (int) $st['pages']; $st['phase'] = 'match'; }
            return;
        }
        $more = $n < (int) $st['pages'];
        if ($more && $st['mode'] === 'quick') {
            // быстрый: дальше, только пока на странице есть товары бренда, ещё не связанные с нашими (новые — сверху списка)
            [$ph, $v] = App::db()->in(array_map(static fn($p) => (string) $p['id'], $page['items']));
            $linked = (int) App::db()->value("SELECT COUNT(*) FROM supplier_links WHERE supplier = ? AND code IN ($ph)", array_merge([self::CODE], $v));
            $more = $linked < count($page['items']) && $n < (int) self::cfg('quick_pages');
        }
        if (!$more) {
            $st['complete'] = $st['mode'] === 'full' && $n >= (int) $st['pages'];
            $st['phase'] = 'match';
        }
    }

    /** Все товары бренда со страниц запуска (по id, первое вхождение; сдвиг между запросами страниц даёт повтор) */
    private static function listItems(array $run): array
    {
        $id = (int) $run['id'];
        $st = $run['state'];
        $out = [];
        for ($n = 1; $n <= (int) $st['page']; $n++) {
            $file = $st['mode'] === 'files' ? ($st['files'][$n - 1][1] ?? '') : self::dir($id) . '/page-' . $n . '.html';
            foreach (TommParser::listPage((string) @file_get_contents($file))['items'] as $p) $out[(int) $p['id']] ??= $p;
        }
        return array_values($out);
    }

    /** Товары запуска после сопоставления (items.json) */
    private static function loadItems(int $id): array
    {
        static $memo = [];
        if (isset($memo[$id])) return $memo[$id];
        $j = json_decode((string) @file_get_contents(self::dir($id) . '/items.json'), true);
        if (!is_array($j)) throw new \RuntimeException('Нет списка товаров запуска (items.json) — запустите заново');
        $memo = [$id => $j];
        return $j;
    }

    /** Проверка связи: robots.txt и первая страница списка (2 запроса); у бренда ничего не меняется */
    public static function test(int $userId = 0, string $origin = 'admin'): array
    {
        return self::testRun($userId, $origin, self::client([]), static function (TommClient $c): string {
            $url = self::pageUrl(1);
            if (!$c->allowed($url)) throw new SupplierError('robots.txt бренда запрещает ' . self::LIST_PATH, 'robots');
            $r = $c->get($url);
            if ($r['code'] !== 200) throw new SupplierError('Ответ ' . $r['code'] . ' на ' . $url, 'retry', $r['code']);
            $p = TommParser::listPage($r['body']);
            if (!$p['items']) throw new \RuntimeException('Страница ' . $url . ' получена, но товары не читаются (сменился шаблон сайта?)');
            $first = $p['items'][0];
            $in = count(array_filter($p['items'], static fn($x) => $x['stock']));
            return sprintf('список — страниц %d по %d товаров (≈%s товаров бренда); на первой в наличии %d из %d; первый: «%s», пара %s грн, ящик %s грн, %s пар, %s.',
                $p['pages'], count($p['items']), number_format($p['pages'] * count($p['items']), 0, '', ' '), $in, count($p['items']), $first['code'],
                self::num((float) $first['price']), self::num((float) $first['price_box']), (int) $first['pairs'], $first['size'] ?: '—');
        });
    }

    protected static function phaseLabel(array $st): string
    {
        $cur = $st['cur'] ?? [];
        return match ($st['phase'] ?? '') {
            'start' => 'Проверки',
            'list' => 'Страница списка ' . ((int) ($st['page'] ?? 0) + 1) . (!empty($st['pages']) ? ' из ' . (int) $st['pages'] : '') . ' (всего запросов ' . (int) ($st['requests'] ?? 0) . ')',
            'match' => 'Сопоставление с товарами сайта',
            'items' => 'Товар ' . (int) ($st['pos'] ?? 0) . ' из ' . (int) ($st['count'] ?? 0) . (isset($cur['of']) ? ' (страниц прочитано ' . (int) $cur['n'] . ')' : ''),
            'finish' => 'Завершение', default => 'Идёт',
        };
    }

    protected static function percent(array $run): ?int
    {
        $st = $run['state'];
        if ($run['status'] !== 'running') return null;
        $pages = $st['mode'] === 'files' ? count($st['files'] ?? []) : (int) ($st['pages'] ?? 0);
        // список — первые 30 %, товары — остальное
        if (in_array($st['phase'] ?? '', ['start', 'list'], true)) return $pages ? (int) floor((int) ($st['page'] ?? 0) / $pages * 30) : 0;
        if (($st['phase'] ?? '') === 'items' && !empty($st['count'])) return 30 + (int) floor((int) $st['pos'] / (int) $st['count'] * 70);
        return ($st['phase'] ?? '') === 'finish' ? 99 : 30;
    }
}
