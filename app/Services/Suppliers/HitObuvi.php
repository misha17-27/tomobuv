<?php
declare(strict_types=1);

namespace App\Services\Suppliers;

use App\Core\App;
use App\Core\Settings;
use App\Services\Import\Importer;

/**
 * Загрузка товаров поставщика «Хит обуви» (hit-obuvi.com.ua, Joomla + JoomShopping) по страницам категорий его сайта —
 * адаптер общего каркаса поставщиков (Supplier). API и выгрузок у поставщика нет: единственный источник — HTML списков
 * (в нём есть всё: название, цена ящика и пары, ряд, цвет, материал, пар в упаковке, фото). Карточки товаров не
 * запрашиваются; фото (full_…) — только для новых товаров. Клиент — HitObuviClient (пауза, robots.txt, User-Agent).
 *
 * Фазы запуска (state, продолжение после обрыва):
 *   start → проверки (robots.txt, наценка, таблица категорий, характеристики);
 *   page  → очередная страница раздела (/zhenskaya-obuv?limit=100&start=N) → storage/import/hitobuvi/runs/N/page-K.html;
 *           на первой — меню сайта: новые категории поставщика попадают в таблицу без категории сайта (подсветка);
 *           режим full — разделы до конца (≈1 100 запросов при limit=100), quick — сверху, пока на странице есть
 *           несвязанные товары новее уже связанных (не больше quick_pages страниц на раздел);
 *   items → товары страницы порциями (HitObuviSync: поиск R1–R4, цена с наценкой, запись, фото новых);
 *   finish→ только полный проход по всем страницам: связанные товары, которых не было N полных проходов подряд, скрыть
 *           (защита: больше 50 % активных — отмена); сброс кэша, уборка.
 * Ошибки сайта: 429/5xx — пауза 60 с с удвоением (шаг заканчивается с wait), после 3 подряд — ошибка; 403 и проверка
 * Cloudflare — сразу ошибка «нужна проверка». Неполный проход (ошибка, лимит, остановка) товары не скрывает.
 * Без сети: src = file — сохранённые страницы из папки (общий кэш проверок или страницы прошлого запуска).
 */
final class HitObuvi extends Supplier
{
    public const CODE = 'hitobuvi';
    public const TITLE = 'Хит обуви';
    public const SITE = 'https://hit-obuvi.com.ua';
    protected const LIVE_SRC = 'web';
    public const CHECKS_MISSING = true;
    public const COUNTS_REQUESTS = true;
    /** Корень раздела «Новинки» (весь каталог по дате, без категорий) — не обходится */
    private const SERVICE_ROOTS = ['/novinki', '/kozhanaya-obuv'];

    public const DEFAULTS = [
        'enabled' => '0',
        // сверка каждые 5 ч круглые сутки (cron хостинга раз в час решает, пора ли); полный проход — не чаще раза в full_hours ч
        // (по умолчанию каждый запуск — полный: цены и наличие всех товаров), остальные — быстрые (только новые сверху списков)
        'window_from' => '0', 'window_to' => '23', 'period' => '5', 'full_hours' => '5',
        'delay' => '2', 'max_requests' => '2000', 'page_limit' => '100', 'quick_pages' => '5',
        'roots' => "/zhenskaya-obuv\n/muzhskaya-obuv\n/detskaya-obuv\n/podrostkovaya-obuv",
        // наценка: не задана — включить загрузку нельзя (цена поставщика — закупочная)
        'markup_k' => '', 'markup_plus' => '0', 'round_step' => '1', 'round_mode' => 'math',
        'add_new' => '1', 'max_new' => '300', 'min_id' => '1550000',
        'update_existing' => '1', 'update_category' => '1', 'keep_manual_hidden' => '1',
        'hide_missing' => '1', 'missing_runs' => '2', 'adopt' => '0',
        'skip_brands' => 'JONG GOLF',
        'attributes' => "size=Размер\ncolor=Цвет\nmaterial=Материал\nlining=Материал внутри\ngender=Пол\nbrand=Бренд",
        'photo_width' => '700', 'photo_height' => '700', 'photo_quality' => '90',
        'log_days' => '7', 'history_days' => '365',
    ];
    public const BOOLS = ['enabled', 'add_new', 'update_existing', 'update_category', 'keep_manual_hidden', 'hide_missing', 'adopt'];
    /** Поля товара поставщика для характеристик (левая часть строк «поле=Характеристика») */
    public const FIELDS = ['size' => 'размерный ряд', 'color' => 'цвет', 'material' => 'материал верха', 'lining' => 'материал внутри (подкладка)',
        'gender' => 'пол (по разделу)', 'brand' => 'бренд', 'country' => 'страна производства', 'maker' => 'фабрика (производитель из описания)'];
    public const ROUND_STEPS = ['0' => 'до копеек', '1' => 'до 1 грн', '5' => 'до 5 грн', '10' => 'до 10 грн'];
    public const ROUND_MODES = ['math' => 'по правилам математики', 'up' => 'всегда вверх'];
    public const MODES = ['quick' => 'быстрый (новые товары сверху списков)', 'full' => 'полный проход'];

    // ================================================================== настройки

    /** Включено владельцем и задана наценка (без неё цены были бы закупочными) */
    public static function enabled(): bool
    {
        return self::on('enabled') && self::markup() !== null;
    }

    /** «Запустить сейчас» — и при выключенной автозагрузке (ручной запуск), но только с наценкой: цены поставщика — закупочные */
    public static function manualAllowed(): bool
    {
        return self::markup() !== null;
    }

    public static function manualHint(): string
    {
        return self::markup() === null ? 'Сначала задайте наценку (коэффициент) в настройках — цены поставщика закупочные' : '';
    }

    /** Наценка: ['k', 'plus', 'step', 'mode'] или null — коэффициент не задан */
    public static function markup(): ?array
    {
        $k = Importer::num(self::cfg('markup_k'));
        if ($k === null || $k <= 0) return null;
        return ['k' => $k, 'plus' => Importer::num(self::cfg('markup_plus')) ?? 0.0, 'step' => (int) self::cfg('round_step'), 'mode' => self::cfg('round_mode')];
    }

    /** Цена на сайте за пару: цена поставщика за пару × k + надбавка, с округлением; без наценки — как у поставщика */
    public static function price(float $pair, ?array $m): float
    {
        if ($pair <= 0) return 0.0;
        if ($m === null) return round($pair, 2);
        $v = $pair * $m['k'] + $m['plus'];
        $step = (int) $m['step'];
        if ($step <= 0) return round($v, 2);
        $v = $m['mode'] === 'up' ? ceil(round($v / $step, 6)) * $step : round($v / $step) * $step;
        return max((float) $step, (float) $v);
    }

    /** Разделы поставщика для обхода: [путь => название] (настройка roots, только известные разделы) */
    public static function roots(): array
    {
        $all = HitObuviMap::roots();
        $out = [];
        foreach (preg_split('~\r\n|\n~', self::cfg('roots')) ?: [] as $r) {
            $r = '/' . trim(trim($r), '/');
            if (isset($all[$r]) && !in_array($r, self::SERVICE_ROOTS, true)) $out[$r] = $all[$r];
        }
        return $out;
    }

    /**
     * Сохранить настройки из формы. @return array [ошибки по полям]
     */
    public static function saveSettings(array $post): array
    {
        $in = [];
        $err = [];
        foreach (self::DEFAULTS as $k => $def) {
            if ($k === 'roots') continue;
            $in[$k] = in_array($k, self::BOOLS, true) ? (!empty($post[$k]) ? '1' : '0') : trim(str_replace("\r\n", "\n", (string) ($post[$k] ?? $def)));
        }
        $roots = array_keys(HitObuviMap::roots());
        $in['roots'] = implode("\n", array_values(array_intersect($roots, array_map('strval', (array) ($post['roots'] ?? [])))));
        foreach (['window_from' => [0, 23], 'window_to' => [0, 23], 'period' => [1, 168], 'full_hours' => [1, 720], 'max_requests' => [10, 5000],
                     'page_limit' => [20, 200], 'quick_pages' => [1, 50], 'max_new' => [0, 20000], 'missing_runs' => [1, 10],
                     'photo_width' => [0, 3000], 'photo_height' => [0, 3000], 'photo_quality' => [30, 100], 'log_days' => [1, 90], 'history_days' => [30, 3650]] as $k => [$min, $max]) {
            if (!preg_match('/^\d{1,5}$/', $in[$k]) || (int) $in[$k] < $min || (int) $in[$k] > $max) $err[$k] = "Число от $min до $max";
        }
        if (!isset($err['window_from'], $err['window_to']) && (int) $in['window_from'] > (int) $in['window_to']) $err['window_to'] = 'Конец окна раньше начала';
        $delay = Importer::num($in['delay']);
        if ($delay === null || $delay < HitObuviClient::MIN_DELAY || $delay > 30) $err['delay'] = 'Пауза — от 1,5 до 30 с';
        else $in['delay'] = rtrim(rtrim(number_format($delay, 1, '.', ''), '0'), '.');
        if ($in['min_id'] !== '' && !preg_match('/^\d{1,9}$/', $in['min_id'])) $err['min_id'] = 'Номер товара поставщика (цифры) или пусто';
        if ($in['markup_k'] !== '') {
            $k = Importer::num($in['markup_k']);
            if ($k === null || $k < 0.5 || $k > 10) $err['markup_k'] = 'Коэффициент от 0,5 до 10 (1,2 — +20 %)';
            else $in['markup_k'] = rtrim(rtrim(number_format($k, 4, '.', ''), '0'), '.');
        }
        $plus = Importer::num($in['markup_plus'] === '' ? '0' : $in['markup_plus']);
        if ($plus === null || abs($plus) > 10000) $err['markup_plus'] = 'Надбавка — число от −10 000 до 10 000 грн';
        else $in['markup_plus'] = rtrim(rtrim(number_format($plus, 2, '.', ''), '0'), '.');
        if (!isset(self::ROUND_STEPS[$in['round_step']])) $in['round_step'] = '1';
        if (!isset(self::ROUND_MODES[$in['round_mode']])) $in['round_mode'] = 'math';
        $in['skip_brands'] = mb_substr($in['skip_brands'], 0, 5000);
        [, $attrErr] = self::attributes($in['attributes']);
        if ($attrErr) $err['attributes'] = implode('; ', $attrErr);
        if ($in['enabled'] === '1' && ($in['markup_k'] === '' || isset($err['markup_k']))) $err['enabled'] = 'Чтобы включить загрузку, укажите наценку (коэффициент): цены поставщика — закупочные';
        if ($in['enabled'] === '1' && $in['roots'] === '') $err['roots'] = 'Отметьте хотя бы один раздел поставщика';
        if ($err) return $err;
        self::store($in);
        return [];
    }

    /** Характеристики из строк «поле=Характеристика сайта». @return array{0: [поле => строка features], 1: string[] ошибки} */
    public static function attributes(?string $text = null): array
    {
        return JongGolf::featureLines($text ?? self::cfg('attributes'), self::FIELDS);
    }

    // ================================================================== запуски

    public static function emptyStats(): array
    {
        return ['pages' => 0, 'requests' => 0, 'products' => 0, 'created' => 0, 'updated' => 0, 'same' => 0, 'hidden_color' => 0, 'hidden_missing' => 0,
            'skipped' => 0, 'errors' => 0, 'warnings' => 0, 'photos' => 0, 'existing' => 0, 'skip' => [], 'unmapped' => [], 'found' => [], 'new_cats' => []];
    }

    /**
     * Из cron: как у всех поставщиков, но шагами не дольше 40 мин — полный проход (≈1,5 ч) продолжается следующим
     * часовым cron (запуск без движения больше STALE = 15 мин считается прерванным и подхватывается)
     */
    public static function cron(callable $say, float $maxTime = 2400.0): void
    {
        parent::cron($say, $maxTime);
    }

    /**
     * Режим боевого запуска «по расписанию»: полный (сверка цен и наличия всех товаров), если с начала последнего завершённого
     * полного прохода прошло не меньше full_hours ч (−10 мин, как расписание), иначе быстрый (только новые сверху списков)
     */
    public static function autoMode(): string
    {
        $last = App::db()->value("SELECT MAX(started_at) FROM supplier_runs WHERE supplier = ? AND kind = 'run' AND src = ? AND status = 'done' AND state LIKE ?",
            [self::CODE, self::LIVE_SRC, '%"mode":"full"%"complete":true%']);
        return !$last || strtotime((string) $last) <= time() - max(1, (int) self::cfg('full_hours')) * 3600 + 600 ? 'full' : 'quick';
    }

    /**
     * Начать запуск. $o: src — web | file; dir — папка сохранённых страниц для src = file (общий кэш проверок, страницы
     * прошлого запуска); cache — сквозной кэш для src = web (проверки: повторно не скачивать); mode — quick | full | auto;
     * limit — не больше N товаров; ids — только эти товары поставщика (проверка); origin — admin | cron | cli; user_id.
     * Боевой запуск с сайта — только при включённой загрузке и заданной наценке; запись по файлу — только из CLI.
     */
    public static function start(string $kind, array $o = []): int
    {
        self::ensureSchema();
        if (!isset(self::KINDS[$kind]) || $kind === 'test') throw new \InvalidArgumentException('Неизвестный вид запуска');
        $src = ($o['src'] ?? 'web') === 'file' ? 'file' : 'web';
        $origin = in_array($o['origin'] ?? '', ['cron', 'cli'], true) ? $o['origin'] : 'admin';
        // боевой запуск с сайта: из cron — только при включённой загрузке (due), вручную («Запустить сейчас», --now) — и при
        // выключенной, но только с наценкой
        if ($kind === 'run' && $src === 'web' && $origin === 'cron' && !self::enabled()) throw new \RuntimeException('Загрузка «Хит обуви» выключена — запуск по расписанию не выполняется.');
        if ($kind === 'run' && $src === 'file' && $origin !== 'cli') throw new \RuntimeException('Запись по сохранённым страницам — только из командной строки (bin/supplier-hitobuvi.php --write).');
        if ($kind === 'run' && self::markup() === null) throw new \RuntimeException('Наценка не задана — запись товаров невозможна (цены поставщика закупочные). Укажите коэффициент в настройках.');
        self::assertIdle();
        $mode = $o['mode'] ?? 'auto';
        if ($mode === 'auto' || !isset(self::MODES[$mode])) $mode = $kind === 'run' ? self::autoMode() : 'quick';
        $files = [];
        if ($src === 'file') {
            $dir = rtrim(str_replace('\\', '/', (string) ($o['dir'] ?? '')), '/');
            if ($dir === '' || !is_dir($dir)) throw new \RuntimeException('Нет папки с сохранёнными страницами поставщика: ' . $dir);
            $files = self::savedPages($dir);
            if (!$files) throw new \RuntimeException('В папке ' . $dir . ' нет сохранённых страниц списков «Хит обуви».');
        }
        $roots = self::roots();
        if (!$roots) throw new \RuntimeException('Не выбран ни один раздел поставщика (настройки → «Разделы для обхода»).');
        $ids = array_values(array_unique(array_filter(array_map('intval', (array) ($o['ids'] ?? [])))));
        $state = [
            'phase' => 'start', 'mode' => $src === 'file' ? 'files' : $mode, 'complete' => false,
            'roots' => array_keys($roots), 'r' => 0, 'start' => 0, 'last' => null, 'rpages' => 0, 'page' => 0, 'pos' => 0, 'count' => 0,
            'files' => $files, 'dir' => $src === 'file' ? $dir : null, 'cache' => $src === 'web' && !empty($o['cache']) ? (string) $o['cache'] : null,
            'limit' => isset($o['limit']) && (int) $o['limit'] > 0 ? (int) $o['limit'] : null, 'ids' => $ids ?: null,
            'linked_max' => (int) App::db()->value('SELECT COALESCE(MAX(CAST(code AS UNSIGNED)), 0) FROM supplier_links WHERE supplier = ?', [self::CODE]),
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
                ? 'страниц в папке: ' . count($files)
                : 'режим: ' . self::MODES[$mode] . ', разделы: ' . implode(', ', $roots) . ', по ' . (int) self::cfg('page_limit') . ' товаров на странице, пауза ' . self::cfg('delay') . ' с, не больше ' . (int) self::cfg('max_requests') . ' запросов')
            . ($state['limit'] ? ', не больше ' . $state['limit'] . ' товаров' : '') . ($ids ? ', только товары ' . implode(', ', $ids) : '') . '. '
            . ($m ? 'Наценка: ×' . $m['k'] . ($m['plus'] ? ' + ' . $m['plus'] . ' грн' : '') . ', округление ' . self::ROUND_STEPS[(string) $m['step']] . ($m['step'] ? ' (' . self::ROUND_MODES[$m['mode']] . ')' : '')
                : 'Наценка НЕ задана — в отчёте цены поставщика (закупочные).'));
        self::flushLog();
        return $id;
    }

    /**
     * Сохранённые страницы списков в папке: общий кэш (имя.md5.html + .meta с адресом) или папка запуска (page-N.html +
     * page-N.json). Только списки категорий выбранных разделов (не карточки, не «Новинки»), порядок: разделы, затем листья. @return [[адрес, файл], …]
     */
    public static function savedPages(string $dir): array
    {
        $out = [];
        $roots = self::roots();
        foreach (glob($dir . '/*.html') ?: [] as $f) {
            $meta = is_file($f . '.meta') ? json_decode((string) file_get_contents($f . '.meta'), true) : (is_file(substr($f, 0, -5) . '.json') ? json_decode((string) file_get_contents(substr($f, 0, -5) . '.json'), true) : null);
            $url = (string) ($meta['url'] ?? '');
            if ($url === '' || (int) ($meta['code'] ?? 200) !== 200 || str_contains($url, '/uk/')) continue;
            $path = rtrim((string) parse_url($url, PHP_URL_PATH), '/');
            $root = '/' . explode('/', trim($path, '/'))[0];
            $isRoot = $path === $root;
            if (!isset($roots[$root])) continue;                                // не выбранный раздел, «Новинки», robots.txt, sitemap.xml
            if (!$isRoot && !HitObuviMap::category($path, null)) continue;      // карточка товара или неизвестная страница
            $out[] = [$url, str_replace('\\', '/', $f), $isRoot ? 0 : 1];
        }
        usort($out, static fn($a, $b) => [$a[2], $a[0]] <=> [$b[2], $b[0]]);
        return array_map(static fn($x) => [$x[0], $x[1]], $out);
    }

    protected static function failure(\Throwable $e): array
    {
        // ошибка сайта поставщика (HitObuviError) — ожидаемая: в отчёт запуска, не в журнал ошибок сайта
        return [$e instanceof \RuntimeException ? $e->getMessage() : 'Ошибка: ' . $e->getMessage(), $e instanceof HitObuviError];
    }

    /** Клиент запуска: пауза и лимит из настроек, сквозной кэш или папка сохранённых страниц */
    private static function client(array $st): HitObuviClient
    {
        $c = new HitObuviClient((float) (Importer::num(self::cfg('delay')) ?? 2.0), max(10, (int) self::cfg('max_requests')), $st['dir'] ?? null, $st['cache'] ?? null);
        $c->setUsed((int) ($st['requests'] ?? 0));
        return $c;
    }

    /** Адрес страницы раздела: limit, start (сортировка по умолчанию — по дате, новые сверху) */
    public static function pageUrl(string $root, int $start, int $limit): string
    {
        return HitObuviClient::BASE . $root . '?limit=' . $limit . ($start > 0 ? '&start=' . $start : '');
    }

    /** Машина состояний запуска (см. описание класса) */
    protected static function advance(array &$run, float $deadline): void
    {
        $id = (int) $run['id'];
        $st = &$run['state'];
        $dry = $run['kind'] === 'dry';
        unset($st['wait']);
        // наценку сняли во время запуска — дальше товары записались бы по закупочной цене: запуск останавливается с ошибкой
        if (!$dry && self::markup() === null) throw new HitObuviError('Наценка не задана (снята во время запуска) — запись остановлена, чтобы не ставить на сайт закупочные цены. Укажите коэффициент и запустите снова.', 'markup');
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
                        $map = HitObuviMap::count();
                        if (!$map['cats']) HitObuviMap::seed();
                        $map = HitObuviMap::count();
                        self::log($id, 'info', 'Таблица категорий: категорий поставщика ' . $map['cats'] . ' (загружаются ' . $map['active'] . ($map['unresolved'] ? ', без категории сайта ' . $map['unresolved'] : '')
                            . '), правил «тип из названия» ' . $map['types']);
                        [, $attrErr] = self::attributes();
                        if ($attrErr) throw new \RuntimeException('Характеристики в настройках: ' . implode('; ', $attrErr));
                        if ($run['src'] === 'web' && !$client->allowed(HitObuviClient::BASE . $st['roots'][0] . '?limit=100')) throw new \RuntimeException('robots.txt поставщика запрещает списки категорий — загрузка невозможна');
                        $st['phase'] = 'page';
                        break;

                    case 'page':
                        if (!self::nextPage($run, $client)) break;           // раздел закончился / страница пропущена — следующий круг
                        $st['phase'] = 'items';
                        break;

                    case 'items':
                        [$page, $meta] = self::loadPage($id, (int) $st['page']);
                        $items = $page['items'];
                        if ($st['ids']) $items = array_values(array_filter($items, static fn($p) => in_array((int) $p['id'], $st['ids'], true)));
                        $sync ??= new HitObuviSync($id, $dry, $run['stats'], $client);
                        $left = $st['limit'] !== null ? max(0, (int) $st['limit'] - (int) $run['stats']['products']) : null;
                        $st['pos'] = $sync->process($items, (int) $st['pos'], $meta, $deadline, $left, static function (int $pos) use (&$run, &$st, $id, $client): bool {
                            $st['pos'] = $pos;
                            $st['requests'] = $client->used();
                            $run['stats']['requests'] = $client->used();
                            self::save($run);
                            self::flushLog();
                            return !self::stopped($id);
                        });
                        $st['count'] = count($items);
                        if ($sync->deferred()) {
                            if (empty($st['waits'])) self::log($id, 'info', 'Идёт перестройка индекса каталога или скачивание фото не уложилось в шаг — запись порции продолжится следующим шагом');
                            $st['waits'] = (int) ($st['waits'] ?? 0) + 1;
                            $st['wait'] = $sync->deferredBy() === 'lock' ? 1 : 0;
                            self::save($run);
                            self::flushLog();
                            return;
                        }
                        if ($st['limit'] !== null && (int) $run['stats']['products'] >= (int) $st['limit']) {
                            self::log($id, 'info', 'Достигнут лимит ' . $st['limit'] . ' товаров — остальное не обрабатывается, скрытие отсутствующих не выполняется');
                            $st['phase'] = 'finish';
                            break;
                        }
                        if ($st['pos'] >= count($items)) { $st['phase'] = 'page'; $st['pos'] = 0; }
                        break;

                    case 'finish':
                        $sync ??= new HitObuviSync($id, $dry, $run['stats'], $client);
                        $sync->finish(!empty($st['complete']) && $st['mode'] === 'full', static fn(): bool => !self::stopped($id));
                        $s = $run['stats'];
                        self::log($id, 'info', '==========');
                        self::log($id, 'info', 'Запросов к сайту поставщика: ' . $s['requests'] . ', страниц списков: ' . $s['pages'] . ', товаров на них: ' . $s['products']);
                        self::log($id, 'info', ($dry ? 'Будет добавлено: ' : 'Добавлено товаров: ') . $s['created'] . ' (фото: ' . $s['photos'] . ')');
                        self::log($id, 'info', ($dry ? 'Будет изменено: ' : 'Изменено товаров: ') . $s['updated'] . ' (без изменений: ' . $s['same'] . ')');
                        self::log($id, 'info', 'Найдено на сайте, но не под загрузчиком (Forsage, вручную): ' . $s['existing'] . self::foundText($s['found']));
                        self::log($id, 'info', ($dry ? 'Будет скрыто: ' : 'Скрыто: ') . $s['hidden_missing'] . ' (нет у поставщика ' . (int) self::cfg('missing_runs') . ' полных проходов подряд)');
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
        } catch (HitObuviError $e) {
            $st['requests'] = $client->used();
            $run['stats']['requests'] = $client->used();
            if ($e->kind === 'retry') {
                $st['fails'] = (int) ($st['fails'] ?? 0) + 1;
                if ($st['fails'] >= 3) throw new HitObuviError($e->getMessage() . ' — третий раз подряд, загрузка остановлена (товары не скрываются)', 'retry', $e->http);
                $pause = 60 * (2 ** ($st['fails'] - 1));
                $st['retry_at'] = time() + $pause;
                $st['wait'] = 1;
                $run['stats']['warnings']++;
                self::log($id, 'warn', $e->getMessage() . ' — пауза ' . $pause . ' с, затем повтор (' . $st['fails'] . ' из 3)');
                self::save($run);
                self::flushLog();
                return;
            }
            if ($e->kind === 'limit') {
                self::log($id, 'warn', $e->getMessage() . ' — проход неполный: остальное — в следующий запуск, товары не скрываются');
                $run['stats']['warnings']++;
                $st['complete'] = false;
                $st['phase'] = 'finish';
                self::save($run);
                self::flushLog();
                return;
            }
            throw $e;
        }
    }

    /**
     * Следующая страница: из папки (src = file) или с сайта (раздел за разделом). Страница сохраняется в папке запуска
     * (page-K.html + page-K.json: адрес, раздел, категория). @return bool страница готова к разбору (false — круг ещё раз)
     */
    private static function nextPage(array &$run, HitObuviClient $client): bool
    {
        $id = (int) $run['id'];
        $st = &$run['state'];
        $n = (int) $st['page'] + 1;
        $dir = self::dir($id);
        if ($st['mode'] === 'files') {
            if ($n > count($st['files'])) { $st['complete'] = false; $st['phase'] = 'finish'; return false; }
            [$url, $file] = $st['files'][$n - 1];
            $html = (string) @file_get_contents($file);
            $path = rtrim((string) parse_url($url, PHP_URL_PATH), '/');
            $root = '/' . explode('/', trim($path, '/'))[0];
        } else {
            if ($st['r'] >= count($st['roots'])) {
                // полный проход — только если все разделы прочитаны (404 раздела, пустой список — нет) и без отбора товаров (ids)
                $st['complete'] = empty($st['broken']) && empty($st['ids']);
                $st['phase'] = 'finish';
                return false;
            }
            $root = $st['roots'][$st['r']];
            $limit = max(20, min(200, (int) self::cfg('page_limit')));
            $url = self::pageUrl($root, (int) $st['start'], $limit);
            $path = $root;
            $file = $dir . '/page-' . $n . '.html';
            if (is_file($file)) {
                $html = (string) file_get_contents($file);                      // получена до обрыва шага
            } else {
                $r = $client->get($url);
                $st['fails'] = 0;
                if ($r['code'] === 404) {
                    self::log($id, 'error', 'Раздел ' . $root . ' не найден у поставщика (404) — пропущен, проход неполный');
                    $run['stats']['errors']++;
                    $st['broken'] = true;
                    $st['r']++; $st['start'] = 0; $st['last'] = null; $st['rpages'] = 0;
                    return false;
                }
                if ($r['code'] !== 200) throw new HitObuviError('Страница ' . $url . ': ответ ' . $r['code'], 'retry', $r['code']);
                $html = $r['body'];
                file_put_contents($file, $html);
            }
        }
        $page = HitObuviParser::listPage($html);
        if (!$page['items'] && !$page['empty']) {
            // нет ни товаров, ни признаков пустой категории — сменился шаблон сайта или пришла чужая страница: дальше не идём
            if ($st['mode'] === 'files') { self::log($id, 'warn', 'Страница ' . $url . ' не читается как список — пропущена'); $st['page'] = $n; return false; }
            throw new \RuntimeException('Страница ' . $url . ' не похожа на список товаров (нет блоков productitem_ — сменился шаблон сайта поставщика?) — загрузка остановлена, товары не скрываются');
        }
        if (!$page['items'] && $st['mode'] !== 'files') {
            // раздел поставщика (тысячи товаров) пустым не бывает: пустой список — сбой сайта, остаток раздела не прочитан
            self::log($id, 'warn', 'Страница ' . $url . ' без товаров — проход неполный, товары не скрываются');
            $run['stats']['warnings']++;
            $st['broken'] = true;
        }
        $pageCat = $path !== $root ? HitObuviMap::category($path, null) : null;
        file_put_contents($dir . '/page-' . $n . '.json', json_encode(['url' => $url, 'code' => 200, 'root' => $root, 'path' => $path, 'cat' => $pageCat['category'] ?? null], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        if ($n === 1 || empty($st['menu_done'])) self::checkMenu($run, $html);
        $mf = HitObuviParser::manufacturers($html);
        if ($mf) @file_put_contents(self::dir() . '/manufacturers.json', json_encode($mf, JSON_UNESCAPED_UNICODE));
        $run['stats']['pages']++;
        $st['page'] = $n;
        $st['pos'] = 0;
        $st['count'] = count($page['items']);
        // для экрана: раздел r из R, страница n из m раздела (m — по ссылке «В конец» этой страницы)
        $lim = max(20, min(200, (int) self::cfg('page_limit')));
        $st['cur'] = $st['mode'] === 'files' ? ['url' => $url, 'root' => $root, 'n' => $n, 'of' => count($st['files'])]
            : ['url' => $url, 'root' => $root, 'n' => intdiv((int) $st['start'], $lim) + 1, 'of' => intdiv((int) $page['last_start'], $lim) + 1, 'r' => (int) $st['r'] + 1, 'rs' => count($st['roots'])];
        self::log($id, 'info', 'Страница ' . $n . ': ' . $url . ' — товаров ' . count($page['items']) . ($page['last_start'] && $st['mode'] !== 'files' ? ' (раздел до start=' . $page['last_start'] . ')' : ''));
        if ($st['mode'] !== 'files') {
            // следующая страница раздела: full — до последней, quick — пока внизу страницы есть несвязанные товары новее связанных
            $limit = max(20, min(200, (int) self::cfg('page_limit')));
            $st['last'] = (int) $page['last_start'];
            $st['rpages'] = (int) ($st['rpages'] ?? 0) + 1;
            $more = (int) $st['start'] + $limit <= (int) $st['last'] && $page['items'];
            if ($more && $st['mode'] === 'quick') {
                $tail = end($page['items']);
                $more = (int) $tail['id'] > (int) $st['linked_max'] && $st['rpages'] < (int) self::cfg('quick_pages');
            }
            if ($more) $st['start'] = (int) $st['start'] + $limit;
            else { $st['r']++; $st['start'] = 0; $st['last'] = null; $st['rpages'] = 0; }
        }
        return true;
    }

    /** Страница запуска из папки: [разбор, метаданные] */
    private static function loadPage(int $id, int $n): array
    {
        static $memo = [];
        $k = $id . ':' . $n;
        if (isset($memo[$k])) return $memo[$k];
        $dir = self::dir($id);
        $meta = json_decode((string) @file_get_contents($dir . '/page-' . $n . '.json'), true);
        if (!is_array($meta)) throw new \RuntimeException('Нет сохранённой страницы ' . $n . ' запуска');
        $run = self::run($id);
        $file = $run && $run['state']['mode'] === 'files' ? ($run['state']['files'][$n - 1][1] ?? '') : $dir . '/page-' . $n . '.html';
        $memo = [$k => [HitObuviParser::listPage((string) @file_get_contents($file)), $meta]];
        return $memo[$k];
    }

    /** Меню сайта поставщика: новые категории — в таблицу без категории сайта (боевой запуск), пропавшие — в отчёт */
    private static function checkMenu(array &$run, string $html): void
    {
        $menu = HitObuviParser::menu($html);
        if (count($menu) < 20) return;                                      // меню на странице нет (или оно урезано) — на следующей
        $st = &$run['state'];
        $st['menu_done'] = true;
        $idx = HitObuviMap::index();
        $roots = HitObuviMap::roots();
        $new = [];
        foreach ($menu as $path => $name) {
            if (isset($roots[$path]) || in_array($path, self::SERVICE_ROOTS, true) || isset($idx['cat'][$path]) || substr_count($path, '/') < 2) continue;
            $new[$path] = $name;
        }
        $gone = [];
        foreach ($idx['cat'] as $path => $r) {
            if ($r['category'] !== $path || ($r['mode'] ?? '') === 'skip' || ($r['ext_id'] ?? null) === null) continue;
            if (!isset($menu[$path])) $gone[] = $path . ' «' . $r['gender'] . '»';
        }
        foreach ($new as $path => $name) {
            if ($run['kind'] === 'run') HitObuviMap::addCategory($path, $name);
            $run['stats']['new_cats'][$path] = $name;
        }
        if ($new) {
            $run['stats']['warnings']++;
            self::log((int) $run['id'], 'warn', 'Новые категории поставщика в меню: ' . implode('; ', array_map(static fn($p, $n) => $p . ' «' . $n . '»', array_keys($new), $new))
                . ($run['kind'] === 'run' ? ' — добавлены в таблицу категорий без категории сайта: выберите её' : ' — при боевом запуске попадут в таблицу категорий'));
        }
        if ($gone) self::log((int) $run['id'], 'info', 'Нет в меню поставщика (категория убрана или переименована): ' . implode('; ', array_slice($gone, 0, 20)));
    }

    /** «: по связи 10, по forsage-коду 3…» */
    public static function foundText(array $found): string
    {
        if (!$found) return '';
        $names = HitObuviSync::VIA;
        $parts = [];
        foreach ($found as $k => $n) $parts[] = ($names[$k] ?? $k) . ' — ' . $n;
        return ' (найдено: ' . implode(', ', $parts) . ')';
    }

    /** Проверка связи: robots.txt и первая страница первого раздела (2 запроса); у поставщика ничего не меняется */
    public static function test(int $userId = 0, string $origin = 'admin'): array
    {
        self::ensureSchema();
        $db = App::db();
        $id = $db->insert('supplier_runs', ['supplier' => self::CODE, 'kind' => 'test', 'src' => self::LIVE_SRC, 'origin' => $origin, 'status' => 'running',
            'user_id' => $userId ?: null, 'stats' => '{}', 'state' => '{}', 'started_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]);
        $t = microtime(true);
        try {
            $c = self::client([]);
            $root = array_key_first(self::roots()) ?? '/zhenskaya-obuv';
            $url = self::pageUrl($root, 0, 20);
            if (!$c->allowed($url)) throw new HitObuviError('robots.txt поставщика запрещает ' . $root, 'robots');
            $r = $c->get($url);
            if ($r['code'] !== 200) throw new HitObuviError('Ответ ' . $r['code'] . ' на ' . $url, 'retry', $r['code']);
            $p = HitObuviParser::listPage($r['body']);
            if (!$p['items']) throw new \RuntimeException('Страница ' . $url . ' получена, но товары не читаются (сменился шаблон сайта?)');
            $first = $p['items'][0];
            $msg = sprintf('Связь есть (%.1f с, запросов %d): «%s» — в разделе ≈%s товаров; первый: «%s», ящик %s грн, пара %s грн, %s.', microtime(true) - $t, $c->used(),
                $p['h1'], number_format((int) $p['last_start'] + count($p['items']), 0, '', ' '), $first['name'], self::num((float) $first['price_box']), self::num((float) $first['price_unit']), $first['desc']['size'] ?? '—');
            self::log($id, 'info', $msg);
            self::flushLog();
            self::finishRun($id, 'done');
            return ['ok' => true, 'message' => $msg];
        } catch (\Throwable $e) {
            self::log($id, 'error', $e->getMessage());
            self::flushLog();
            self::finishRun($id, 'error', mb_substr($e->getMessage(), 0, 1000));
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    private static function num(float $v): string
    {
        return rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');
    }

    protected static function phaseLabel(array $st): string
    {
        $roots = HitObuviMap::roots();
        $cur = $st['cur'] ?? [];
        $where = ($st['mode'] ?? '') === 'files' ? 'Сохранённая страница ' . (int) ($st['page'] ?? 0) . ' из ' . count($st['files'] ?? [])
            : 'Раздел ' . (isset($cur['r']) ? (int) $cur['r'] . ' из ' . (int) $cur['rs'] . ' ' : '') . '«' . ($roots[$cur['root'] ?? ''] ?? ($cur['root'] ?? '—')) . '», страница '
                . (isset($cur['n']) ? (int) $cur['n'] . ' из ' . (int) $cur['of'] : (int) ($st['page'] ?? 0));
        return match ($st['phase'] ?? '') {
            'start' => 'Проверки', 'page' => 'Запрос страницы ' . ((int) ($st['page'] ?? 0) + 1) . ' (всего запросов ' . (int) ($st['requests'] ?? 0) . ')',
            'items' => $where . ': товар ' . (int) $st['pos'] . ' из ' . (int) ($st['count'] ?? 0),
            'finish' => 'Завершение', default => 'Идёт',
        };
    }

    protected static function waitLabel(array $st): string
    {
        return (int) ($st['retry_at'] ?? 0) > time() ? ' — пауза поставщика до ' . date('H:i:s', (int) $st['retry_at']) : ' — ждёт окончания перестройки индекса каталога';
    }

    protected static function percent(array $run): ?int
    {
        $st = $run['state'];
        if ($run['status'] !== 'running') return null;
        if (($st['mode'] ?? '') === 'files') return count($st['files'] ?? []) ? (int) floor(max(0, (int) ($st['page'] ?? 0) - 1) / count($st['files']) * 100) : 0;
        $roots = max(1, count($st['roots'] ?? []));
        $inRoot = !empty($st['last']) ? min(1.0, (int) $st['start'] / max(1, (int) $st['last'])) : 0.0;
        return (int) floor(((int) ($st['r'] ?? 0) + $inRoot) / $roots * 100);
    }

    protected static function progressLine(array $p): string
    {
        $s = $p['stats'];
        return $p['label'] . ': запросов ' . ($s['requests'] ?? 0) . ', товаров ' . $s['products'] . ', добавлено ' . $s['created'] . ', изменено ' . $s['updated']
            . ', без изменений ' . $s['same'] . ', найдено чужих ' . ($s['existing'] ?? 0) . ', пропущено ' . $s['skipped'];
    }

    protected static function stopNote(array $run): string
    {
        return ' — проход неполный, товары не скрываются';
    }

    public static function skipReasons(): array
    {
        return HitObuviSync::SKIP;
    }

    /** Уже есть хоть один запуск с сайта (для подсказок на экране) */
    public static function lastWeb(): ?array
    {
        $id = App::db()->value("SELECT id FROM supplier_runs WHERE supplier = ? AND src = ? AND kind IN ('run', 'dry') ORDER BY id DESC LIMIT 1", [self::CODE, self::LIVE_SRC]);
        return $id ? self::run((int) $id) : null;
    }

    /** Связанных товаров: всего, скрыто загрузчиком */
    public static function linkCounts(): array
    {
        $r = App::db()->row('SELECT COUNT(*) total, SUM(hidden) hidden, SUM(missed > 0) missed FROM supplier_links WHERE supplier = ?', [self::CODE]);
        return ['total' => (int) ($r['total'] ?? 0), 'hidden' => (int) ($r['hidden'] ?? 0), 'missed' => (int) ($r['missed'] ?? 0)];
    }

    /** Сохранить настройку одной строкой (для CLI проверок) */
    public static function set(string $k, string $v): void
    {
        if (!array_key_exists($k, self::DEFAULTS)) throw new \InvalidArgumentException('Нет такой настройки: ' . $k);
        Settings::set(self::CODE . '.' . $k, $v);
    }
}
