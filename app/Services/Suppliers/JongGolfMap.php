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
        foreach (App::db()->all('SELECT id, season, category, gender, size, target, category_id FROM supplier_category_map WHERE supplier = ? ORDER BY sort, id', [JongGolf::CODE]) as $r) {
            $out[self::key($r['season'], $r['category'], $r['gender'], $r['size'])] = $r;
        }
        return self::$index = $out;
    }

    public static function forget(): void
    {
        self::$index = null;
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
        $r = App::db()->row('SELECT COUNT(*) total, SUM(category_id IS NULL) unresolved, COUNT(DISTINCT category_id) targets FROM supplier_category_map WHERE supplier = ?', [JongGolf::CODE]);
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
            $db->query('DELETE FROM supplier_category_map WHERE supplier = ?', [JongGolf::CODE]);
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
                    $n += $db->update('supplier_category_map', $set, 'id = ? AND supplier = ? AND NOT (category_id <=> ?)', [(int) $id, JongGolf::CODE, $cid ?: null]);
                }
            });
        }
        self::forget();
        return $n;
    }

    public static function delete(int $id): void
    {
        App::db()->delete('supplier_category_map', 'id = ? AND supplier = ?', [$id, JongGolf::CODE]);
        self::forget();
    }

    /** Страница строк для редактора: фильтр all | unresolved, поиск по тексту */
    public static function page(string $filter, string $q, int $page, int $per): array
    {
        $db = App::db();
        $where = 'supplier = ?';
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
        foreach (App::db()->all('SELECT season, category, gender, size, target, category_id FROM supplier_category_map WHERE supplier = ? ORDER BY sort, id', [JongGolf::CODE]) as $r) {
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
