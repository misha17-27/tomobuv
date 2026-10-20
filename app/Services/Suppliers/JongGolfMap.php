<?php
declare(strict_types=1);

namespace App\Services\Suppliers;

use App\Core\App;
use App\Services\Import\CsvReader;
use App\Services\Import\Importer;
use App\Services\Import\XlsxReader;

/**
 * Таблица соответствия категорий Jong•Golf → категории сайта (supplier_category_map).
 * Формат файла — как table/category_table_jonggolf.xls старого загрузчика: первый лист, строка 1 — заголовок,
 * колонки A «Сезон на jonggolf», B «Подкатегория на jonggolf», C «Пол на jonggolf», D «Размерная сетка»
 * (пусто — любой ряд), E «Категория на нашем сайте» — путь «ДЕТСКАЯ ОБУВЬ>Кеды>12-26». Строка берётся,
 * если заполнены A и E; повтор ключа — побеждает последняя строка (как у старого загрузчика).
 *
 * Поиск для товара (find): сначала «сезон|категория|пол|» (строки без размерного ряда), затем
 * «сезон|категория|пол|ряд» — точно по строке ряда. Сравнение без регистра, лишних пробелов и различий
 * между похожими латинскими и кириллическими буквами («Демисезонe» с латинской e).
 * Категории сайта старый загрузчик создавал сам; здесь путь, которого нет на сайте, остаётся без категории
 * и подсвечивается в админке — категорию выбирают вручную.
 */
final class JongGolfMap
{
    public const EXTENSIONS = ['xls', 'xlsx', 'csv', 'txt'];

    private static ?array $index = null;

    /** Нормализованный ключ сочетания (для hash и поиска) */
    public static function key(string $season, string $category, string $gender, string $size): string
    {
        return implode('|', [self::norm($season), self::norm($category), self::norm($gender), self::size($size)]);
    }

    /** Часть ключа: без регистра и лишних пробелов, похожие кириллические буквы → латинские */
    public static function norm(string $s): string
    {
        $s = Importer::nk(html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        return strtr($s, ['а' => 'a', 'е' => 'e', 'ё' => 'e', 'о' => 'o', 'р' => 'p', 'с' => 'c', 'у' => 'y', 'х' => 'x', 'і' => 'i', 'ї' => 'i', 'к' => 'k',
            '’' => "'", 'ʼ' => "'", '᾿' => "'", '`' => "'"]);
    }

    /** Размерный ряд: «19 – 26» → «19-26» */
    public static function size(string $s): string
    {
        return (string) preg_replace(['/\s+/u', '/[‐‑‒–—−]/u'], ['', '-'], trim($s));
    }

    /** Все строки таблицы для поиска: [ключ => строка] */
    public static function index(): array
    {
        if (self::$index !== null) return self::$index;
        $out = [];
        foreach (App::db()->all("SELECT id, season, category, gender, size, target, category_id FROM supplier_category_map WHERE supplier = ? AND kind = '' ORDER BY sort, id", [JongGolf::CODE]) as $r) {
            $out[self::key($r['season'], $r['category'], $r['gender'], $r['size'])] = $r;
        }
        return self::$index = $out;
    }

    public static function forget(): void
    {
        self::$index = null;
        self::$sections = null;
    }

    /**
     * Категория для товара: [строка таблицы | null, ключ, по которому искали (для отчёта о несопоставленных)].
     * Строка есть, но category_id пуст (путь не найден на сайте), — тоже «не сопоставлено».
     */
    public static function find(string $season, string $category, string $gender, string $size): array
    {
        $idx = self::index();
        $k1 = self::key($season, $category, $gender, '');
        if (isset($idx[$k1])) return [$idx[$k1], $season . '|' . $category . '|' . $gender . '|'];
        if (trim($size) !== '') {
            $k2 = self::key($season, $category, $gender, $size);
            if (isset($idx[$k2])) return [$idx[$k2], $season . '|' . $category . '|' . $gender . '|' . $size];
        }
        return [null, $season . '|' . $category . '|' . $gender . '|' . $size];
    }

    public static function count(): array
    {
        $r = App::db()->row("SELECT COUNT(*) total, SUM(category_id IS NULL) unresolved, COUNT(DISTINCT category_id) targets FROM supplier_category_map WHERE supplier = ? AND kind = ''", [JongGolf::CODE]);
        return ['total' => (int) ($r['total'] ?? 0), 'unresolved' => (int) ($r['unresolved'] ?? 0), 'targets' => (int) ($r['targets'] ?? 0)];
    }

    /** Путь категории сайта из файла → id («A>B>C», пробелы вокруг «>» не важны). null — такой категории нет */
    public static function resolve(string $path, ?array $ci = null): ?int
    {
        $ci ??= Importer::categoryIndex();
        $parts = array_values(array_filter(array_map('trim', explode('>', $path)), static fn($p) => $p !== ''));
        if (!$parts) return null;
        $id = $ci['byPath'][Importer::nk(implode(' > ', $parts))] ?? null;
        return $id !== null && (int) ($ci['cats'][$id]['type'] ?? 0) === 0 ? (int) $id : null;
    }

    /**
     * Прочитать файл таблицы (xls / xlsx / csv): строки [номер строки => [A, B, C, D, E]] без заголовка.
     * @throws \RuntimeException понятная ошибка формата
     */
    public static function readFile(string $path, string $name): array
    {
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $cells = [];
        if ($ext === 'xls') {
            foreach (BiffReader::firstSheet($path) as $n => $r) $cells[$n] = $r;
        } elseif ($ext === 'xlsx') {
            XlsxReader::prepareFile($path);
            $reader = new XlsxReader($path, ['header' => 0]);
            foreach ($reader->rows() as $n => $r) $cells[$n] = self::byPosition($r);
        } elseif ($ext === 'csv' || $ext === 'txt') {
            $meta = CsvReader::prepareFile($path);
            $reader = new CsvReader($path, ['header' => 0] + $meta);
            foreach ($reader->rows() as $n => $r) $cells[$n] = self::byPosition($r);
        } else {
            throw new \RuntimeException('Подходят файлы XLS, XLSX и CSV (как таблица старого загрузчика).');
        }
        unset($cells[1]);                                                   // строка 1 — заголовок (как у старого загрузчика)
        $out = [];
        foreach ($cells as $n => $r) {
            $row = [];
            for ($i = 0; $i < 5; $i++) $row[$i] = trim((string) ($r[$i] ?? ''));
            if ($row[0] !== '' || $row[4] !== '') $out[(int) $n] = $row;
        }
        return $out;
    }

    /** Строка Reader без заголовка («Колонка 1» … «Колонка N») → по позициям с 0 */
    private static function byPosition(array $r): array
    {
        $out = [];
        foreach ($r as $k => $v) {
            if (preg_match('/(\d+)$/', (string) $k, $m)) $out[(int) $m[1] - 1] = $v;
        }
        return $out;
    }

    /**
     * Заменить таблицу строками файла (одной транзакцией — поиск во время загрузки видит прежнюю таблицу).
     * @return array статистика: rows, keys, duplicates, conflicts, unresolved, skipped, paths_missing
     */
    public static function import(array $rows): array
    {
        $ci = Importer::categoryIndex();
        $byKey = [];
        $st = ['rows' => 0, 'keys' => 0, 'duplicates' => 0, 'conflicts' => [], 'unresolved' => 0, 'skipped' => 0, 'paths_missing' => []];
        $resolved = [];
        foreach ($rows as $n => [$season, $category, $gender, $size, $target]) {
            if ($season === '' || $target === '') { $st['skipped']++; continue; }   // как старый загрузчик: нужны A и E
            $st['rows']++;
            $k = self::key($season, $category, $gender, $size);
            if (isset($byKey[$k])) {
                $st['duplicates']++;
                if (self::norm($byKey[$k]['target']) !== self::norm($target)) {
                    $st['conflicts'][] = 'строка ' . $n . ': «' . $season . ' | ' . $category . ' | ' . $gender . ' | ' . $size . '» → «' . $target
                        . '» (раньше в строке ' . $byKey[$k]['n'] . ' — «' . $byKey[$k]['target'] . '»)';
                }
            }
            $resolved[$target] ??= self::resolve($target, $ci);
            if ($resolved[$target] === null) $st['paths_missing'][$target] = ($st['paths_missing'][$target] ?? 0) + 1;
            $byKey[$k] = ['n' => $n, 'season' => mb_substr($season, 0, 100), 'category' => mb_substr($category, 0, 100), 'gender' => mb_substr($gender, 0, 100),
                'size' => mb_substr(self::size($size), 0, 32), 'target' => mb_substr($target, 0, 500), 'category_id' => $resolved[$target]];
        }
        if (!$byKey) throw new \RuntimeException('В файле нет строк с заполненными колонками A (сезон) и E (категория сайта) — проверьте, что это таблица соответствия.');
        $st['keys'] = count($byKey);
        $now = date('Y-m-d H:i:s');
        $insert = [];
        $sort = 0;
        foreach ($byKey as $k => $r) {
            if ($r['category_id'] === null) $st['unresolved']++;
            $insert[] = ['supplier' => JongGolf::CODE, 'season' => $r['season'], 'category' => $r['category'], 'gender' => $r['gender'], 'size' => $r['size'],
                'target' => $r['target'], 'category_id' => $r['category_id'], 'hash' => md5($k), 'sort' => ++$sort, 'updated_at' => $now];
        }
        $db = App::db();
        $db->transaction(static function ($db) use ($insert): void {
            $db->query("DELETE FROM supplier_category_map WHERE supplier = ? AND kind = ''", [JongGolf::CODE]);   // разделы бренда (kind 'cat') — не трогаются
            $db->insertMany('supplier_category_map', $insert, false, 300);
        });
        self::forget();
        return $st;
    }

    /** Добавить или заменить одно сочетание (форма «Добавить сопоставление»). @return string|null ошибка */
    public static function put(string $season, string $category, string $gender, string $size, int $categoryId): ?string
    {
        $season = trim($season); $category = trim($category); $gender = trim($gender); $size = self::size($size);
        if ($season === '' || $category === '') return 'Укажите сезон и категорию поставщика.';
        $ci = Importer::categoryIndex();
        if (!isset($ci['cats'][$categoryId]) || (int) $ci['cats'][$categoryId]['type'] !== 0) return 'Выберите категорию сайта.';
        $db = App::db();
        $k = self::key($season, $category, $gender, $size);
        $sort = (int) $db->value('SELECT COALESCE(MAX(sort), 0) + 1 FROM supplier_category_map WHERE supplier = ?', [JongGolf::CODE]);
        $db->query('INSERT INTO supplier_category_map (supplier, season, category, gender, size, target, category_id, hash, sort, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE target = VALUES(target), category_id = VALUES(category_id), updated_at = VALUES(updated_at)',
            [JongGolf::CODE, mb_substr($season, 0, 100), mb_substr($category, 0, 100), mb_substr($gender, 0, 100), mb_substr($size, 0, 32),
                mb_substr(str_replace(' > ', '>', (string) $ci['path'][$categoryId]), 0, 500), $categoryId, md5($k), $sort, date('Y-m-d H:i:s')]);
        self::forget();
        return null;
    }

    /** Сменить категорию сайта у строк: [id строки => id категории | 0 — снять] */
    public static function assign(array $changes): int
    {
        $ci = Importer::categoryIndex();
        $db = App::db();
        $n = 0;
        $now = date('Y-m-d H:i:s');
        foreach (array_chunk($changes, 200, true) as $part) {
            $db->transaction(static function ($db) use ($part, $ci, $now, &$n): void {
                foreach ($part as $id => $cid) {
                    $cid = (int) $cid;
                    if ($cid && (!isset($ci['cats'][$cid]) || (int) $ci['cats'][$cid]['type'] !== 0)) continue;
                    $set = ['category_id' => $cid ?: null, 'updated_at' => $now];
                    if ($cid) $set['target'] = mb_substr(str_replace(' > ', '>', (string) $ci['path'][$cid]), 0, 500);
                    $n += $db->update('supplier_category_map', $set, "id = ? AND supplier = ? AND kind = '' AND NOT (category_id <=> ?)", [(int) $id, JongGolf::CODE, $cid ?: null]);
                }
            });
        }
        self::forget();
        return $n;
    }

    public static function delete(int $id): void
    {
        App::db()->delete('supplier_category_map', "id = ? AND supplier = ? AND kind = ''", [$id, JongGolf::CODE]);
        self::forget();
    }

    /** Страница строк для редактора: фильтр all | unresolved, поиск по тексту */
    public static function page(string $filter, string $q, int $page, int $per): array
    {
        $db = App::db();
        $where = "supplier = ? AND kind = ''";
        $p = [JongGolf::CODE];
        if ($filter === 'unresolved') $where .= ' AND category_id IS NULL';
        if ($q !== '') {
            $where .= ' AND CONCAT_WS(\' \', season, category, gender, size, target) LIKE ?';
            $p[] = '%' . addcslashes($q, '%_\\') . '%';
        }
        $total = (int) $db->value("SELECT COUNT(*) FROM supplier_category_map WHERE $where", $p);
        $rows = $db->all("SELECT * FROM supplier_category_map WHERE $where ORDER BY sort, id LIMIT " . (int) $per . ' OFFSET ' . max(0, ($page - 1) * $per), $p);
        return [$rows, $total];
    }

    /** Выгрузка таблицы в CSV того же формата (можно поправить в Excel и загрузить обратно) */
    public static function csv(): string
    {
        $h = fopen('php://temp', 'w+');
        fwrite($h, "\xEF\xBB\xBF");
        fputcsv($h, ['Сезон на jonggolf', 'Подкатегория на jonggolf', 'Пол на jonggolf', 'Размерная сетка', 'Категория на нашем сайте'], ';', '"', '');
        $ci = Importer::categoryIndex();
        foreach (App::db()->all("SELECT season, category, gender, size, target, category_id FROM supplier_category_map WHERE supplier = ? AND kind = '' ORDER BY sort, id", [JongGolf::CODE]) as $r) {
            $target = $r['category_id'] && isset($ci['path'][(int) $r['category_id']]) ? str_replace(' > ', '>', (string) $ci['path'][(int) $r['category_id']]) : (string) $r['target'];
            // защита от формул Excel: значение с = + - @ в начале — с апострофом
            $cells = array_map(static fn($v) => preg_match('/^[=+\-@]/', (string) $v) && !preg_match('/^-?\d/', (string) $v) ? "'" . $v : (string) $v,
                [$r['season'], $r['category'], $r['gender'], $r['size'], $target]);
            fputcsv($h, $cells, ';', '"', '');
        }
        rewind($h);
        $out = (string) stream_get_contents($h);
        fclose($h);
        return $out;
    }

    // ================================================================== сайт бренда (www.jonggolf.com)

    public const SECTION_MODES = ['' => 'загружать', 'skip' => 'не загружать'];

    private static ?array $draft = null;
    private static ?array $sections = null;

    /** Черновик database/suppliers/jonggolf.php: uk — русские названия сайта → украинские, sections — разделы бренда */
    public static function draft(): array
    {
        return self::$draft ??= (array) include ROOT . '/database/suppliers/jonggolf.php';
    }

    /**
     * Ряд детской подкатегории по размерам товара бренда — по нижнему размеру: ≤ 22 → 12-26, 23–28 → 26-32, ≥ 29 → 32-38
     * (так лежат наши товары бренда: 366 из 413 найденных; правило рядов «Хит обуви» — 337). Буква ряда бренда («(M)» при
     * 30-37) бывает ошибочной — только размер. null — не диапазон.
     */
    public static function row(string $size): ?string
    {
        if (!preg_match('/^(\d{2})(?:[.,]5)?-(\d{2})(?:[.,]5)?$/', self::size($size), $m) || (int) $m[1] > (int) $m[2]) return null;
        $lo = (int) $m[1];
        return $lo <= 22 ? '12-26' : ($lo <= 28 ? '26-32' : '32-38');
    }

    /** Ключ раздела бренда «Сезон|Категория» (русские названия сайта) */
    public static function sectionKey(string $season, string $category): string
    {
        return trim($season) . '|' . trim($category);
    }

    /** Разделы бренда (kind 'cat'): [нормализованный «сезон|категория» => строка] */
    public static function sections(): array
    {
        if (self::$sections !== null) return self::$sections;
        self::seedSections();
        $out = [];
        foreach (App::db()->all("SELECT * FROM supplier_category_map WHERE supplier = ? AND kind = 'cat' ORDER BY sort, id", [JongGolf::CODE]) as $r) {
            $r['category_id'] = $r['category_id'] !== null ? (int) $r['category_id'] : null;
            $out[self::norm((string) $r['category'])] = $r;
        }
        return self::$sections = $out;
    }

    /** Строки разделов для редактора */
    public static function sectionRows(): array
    {
        self::seedSections();
        return App::db()->all("SELECT * FROM supplier_category_map WHERE supplier = ? AND kind = 'cat' ORDER BY sort, id", [JongGolf::CODE]);
    }

    /**
     * Заполнить разделы бренда черновиком: только недостающие (правки владельца и добавленные загрузчиком не трогаются);
     * $force — заменить все разделы черновиком. @return array [added, unresolved => [путь …]]
     */
    public static function seedSections(bool $force = false): array
    {
        $db = App::db();
        $have = $force ? [] : array_flip(array_map([self::class, 'norm'], $db->col("SELECT category FROM supplier_category_map WHERE supplier = ? AND kind = 'cat'", [JongGolf::CODE])));
        $todo = array_filter(self::draft()['sections'] ?? [], static fn($k) => !isset($have[self::norm((string) $k)]), ARRAY_FILTER_USE_KEY);
        if (!$todo && !$force) return ['added' => 0, 'unresolved' => []];
        $ci = Importer::categoryIndex();
        $now = date('Y-m-d H:i:s');
        $sort = (int) $db->value('SELECT COALESCE(MAX(sort), 0) FROM supplier_category_map WHERE supplier = ?', [JongGolf::CODE]);
        $rows = []; $bad = [];
        foreach ($todo as $key => [$target, $bySize, $mode, $note]) {
            [$season, $cat] = array_pad(explode('|', (string) $key, 2), 2, '');
            $cid = $target ? self::resolve(str_replace(' > ', '>', (string) $target), $ci) : null;
            if ($target && $cid === null) $bad[] = (string) $target;
            $rows[] = ['supplier' => JongGolf::CODE, 'kind' => 'cat', 'ext_id' => null, 'season' => mb_substr($season, 0, 100), 'category' => mb_substr((string) $key, 0, 100),
                'gender' => mb_substr($cat, 0, 100), 'size' => '', 'target' => $cid ? mb_substr((string) $ci['path'][$cid], 0, 500) : (string) $target, 'category_id' => $cid,
                'by_size' => (int) $bySize, 'mode' => (string) $mode, 'note' => mb_substr((string) $note, 0, 255), 'hash' => md5('cat|' . self::norm((string) $key)), 'sort' => ++$sort, 'updated_at' => $now];
        }
        $db->transaction(static function ($db) use ($rows, $force): void {
            if ($force) $db->query("DELETE FROM supplier_category_map WHERE supplier = ? AND kind = 'cat'", [JongGolf::CODE]);
            $db->insertMany('supplier_category_map', $rows, true, 300);
        });
        self::$sections = null;
        return ['added' => count($rows), 'unresolved' => $bad];
    }

    /** Раздел бренда, которого нет среди разделов (новый у бренда): без категории сайта — подсветится в админке */
    public static function addSection(string $season, string $category): void
    {
        $key = self::sectionKey($season, $category);
        $db = App::db();
        $sort = (int) $db->value('SELECT COALESCE(MAX(sort), 0) + 1 FROM supplier_category_map WHERE supplier = ?', [JongGolf::CODE]);
        $db->query("INSERT IGNORE INTO supplier_category_map (supplier, kind, ext_id, season, category, gender, size, target, category_id, by_size, `mode`, note, hash, sort, updated_at)
            VALUES (?, 'cat', NULL, ?, ?, ?, '', '', NULL, 1, '', ?, ?, ?, ?)",
            [JongGolf::CODE, mb_substr(trim($season), 0, 100), mb_substr($key, 0, 100), mb_substr(trim($category), 0, 100), 'новый раздел у бренда ' . date('d.m.Y'), md5('cat|' . self::norm($key)), $sort, date('Y-m-d H:i:s')]);
        self::$sections = null;
    }

    /** Правки разделов из админки (общая запись строк kind 'cat' — HitObuviMap::assign) */
    public static function assignSections(array $changes): int
    {
        $n = HitObuviMap::assign($changes, JongGolf::CODE, self::SECTION_MODES);
        self::$sections = null;
        return $n;
    }

    /** Счётчики разделов: всего, загружаемых, загружаемых без категории сайта */
    public static function sectionCount(): array
    {
        return HitObuviMap::count(JongGolf::CODE);
    }

    /** Украинское название (как в справочниках API) для русского названия сайта бренда; нет в черновике — само название */
    public static function uk(string $ru): string
    {
        return self::ukNames($ru)[1] ?? $ru;
    }

    /** Украинские написания русского названия сайта бренда (черновик uk), само название — первым */
    private static function ukNames(string $ru): array
    {
        $out = [$ru];
        foreach (self::draft()['uk'] ?? [] as $k => $list) if (self::norm((string) $k) === self::norm($ru)) foreach ((array) $list as $u) $out[] = (string) $u;
        return array_values(array_unique($out));
    }

    /**
     * Категория сайта для цвета с сайта бренда (русские названия): 1) таблица сочетаний старого загрузчика (как у API: без ряда,
     * затем точный ряд) по русскому ключу; 2) по украинскому (названия — по черновику uk: таблица шла от справочников API);
     * 3) раздел бренда «сезон|категория» (kind 'cat': категория сайта, по рядам — JongGolfMap::row; «не загружать» — skip).
     * Раздела нет — ['cat_id' => null] и ключ для отчёта о несопоставленных.
     * @return array ['cat_id' => ?int, 'skip' => bool, 'key' => «сезон|категория|пол|ряд», 'via' => table | table_uk | section | '', 'by_size' => bool, 'section' => ?строка]
     */
    public static function site(string $season, string $category, string $gender, string $size): array
    {
        $key = $season . '|' . $category . '|' . $gender . '|' . self::size($size);
        $out = ['cat_id' => null, 'skip' => false, 'key' => $key, 'via' => '', 'by_size' => false, 'section' => null];
        $idx = self::index();
        // русские названия — первыми (ukNames: само название, затем украинские), в каждом сочетании — без ряда, затем ряд товара
        foreach (self::ukNames($season) as $s) foreach (self::ukNames($category) as $c) foreach (self::ukNames($gender) as $g) foreach (['', $size] as $z) {
            $row = $idx[self::key($s, $c, $g, $z)] ?? null;
            if (!$row || !$row['category_id']) continue;
            $out['cat_id'] = (int) $row['category_id'];
            $out['via'] = $s === $season && $c === $category && $g === $gender ? 'table' : 'table_uk';
            return $out;
        }
        $sec = self::sections()[self::norm(self::sectionKey($season, $category))] ?? null;
        $out['section'] = $sec;
        if (!$sec) return $out;
        if ($sec['mode'] === 'skip') { $out['skip'] = true; return $out; }
        if (!$sec['category_id']) return $out;
        $out['via'] = 'section';
        $out['by_size'] = (bool) $sec['by_size'];
        $out['cat_id'] = $sec['by_size'] ? HitObuviMap::child((int) $sec['category_id'], self::row($size)) : (int) $sec['category_id'];
        return $out;
    }

    /** Категории сайта, в которые ведёт таблица: [id => путь] (для коэффициентов цен) */
    public static function targets(): array
    {
        $ci = Importer::categoryIndex();
        $out = [];
        foreach (App::db()->col('SELECT DISTINCT category_id FROM supplier_category_map WHERE supplier = ? AND category_id IS NOT NULL', [JongGolf::CODE]) as $id) {
            if (isset($ci['path'][(int) $id])) $out[(int) $id] = $ci['path'][(int) $id];
        }
        asort($out);
        return $out;
    }
}
