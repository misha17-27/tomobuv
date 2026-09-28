<?php
declare(strict_types=1);

namespace App\Services\Import;

/**
 * Потоковое чтение файлов поставщиков (CSV, XLSX, XML/YML) — файл целиком в память не загружается.
 *
 *   $meta   = Reader::prepare('csv', $path);                 // проверка, перекодировка, разделитель / путь к товару
 *   $reader = Reader::make('csv', $path, $meta + $opt);
 *   foreach ($reader->rows($state) as $n => $row) { … }      // $row = [колонка => значение], $n — номер строки файла
 *   $state  = $reader->state();                              // для продолжения с того же места
 */
abstract class Reader
{
    public const EXTENSIONS = ['csv' => 'csv', 'txt' => 'csv', 'tsv' => 'csv', 'xlsx' => 'xlsx', 'xml' => 'xml', 'yml' => 'xml'];

    protected string $path;
    protected array $opt;
    protected array $state = [];

    public function __construct(string $path, array $opt = [])
    {
        $this->path = $path;
        $this->opt = $opt;
    }

    public static function make(string $format, string $path, array $opt = []): self
    {
        return match ($format) {
            'csv'        => new CsvReader($path, $opt),
            'xlsx'       => new XlsxReader($path, $opt),
            'xml', 'yml' => new XmlReader($path, $opt),
            default      => throw new \InvalidArgumentException('Неизвестный формат файла: ' . $format),
        };
    }

    /** Формат по расширению и первым байтам. null — файл не подходит. */
    public static function detect(string $path, string $name): ?string
    {
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $format = self::EXTENSIONS[$ext] ?? null;
        if ($format === null || !is_file($path)) return null;
        $h = fopen($path, 'rb');
        $head = (string) fread($h, 4096);
        fclose($h);
        if ($head === '') return null;
        $isZip = str_starts_with($head, "PK\x03\x04");
        $body = ltrim(preg_replace('/^(\xEF\xBB\xBF|\xFF\xFE|\xFE\xFF)/', '', $head));
        if ($format === 'xlsx') return $isZip ? 'xlsx' : null;
        if ($isZip) return null;
        if ($format === 'xml') return str_starts_with($body, '<') ? 'xml' : null;
        // CSV: текст (для UTF-16 нули допустимы), без признаков двоичного файла или HTML
        $utf16 = str_starts_with($head, "\xFF\xFE") || str_starts_with($head, "\xFE\xFF");
        if (!$utf16 && str_contains($head, "\0")) return null;
        if (preg_match('/^<(\?php|!doctype|html|script)/i', $body)) return null;
        return 'csv';
    }

    /**
     * Проверка и подготовка файла перед чтением. Возвращает найденные параметры:
     * CSV — encoding, delimiter; XML — item_path, yml; XLSX — sheet.
     */
    public static function prepare(string $format, string $path, array $opt = []): array
    {
        return match ($format) {
            'csv'        => CsvReader::prepareFile($path),
            'xlsx'       => XlsxReader::prepareFile($path),
            'xml', 'yml' => XmlReader::prepareFile($path, (string) ($opt['item_path'] ?? '')),
            default      => throw new \InvalidArgumentException('Неизвестный формат файла'),
        };
    }

    /** Строки файла: ключ — номер строки (или товара в XML), значение — [колонка => значение] */
    abstract public function rows(array $state = []): \Generator;

    /** Состояние для продолжения чтения с места остановки */
    public function state(): array
    {
        return $this->state;
    }

    /**
     * Табличная строка (значения по позициям) → [колонка => значение].
     * Первая непустая строка — заголовок (если opt header = 1), иначе колонки «Колонка N».
     * Пустые строки — null.
     */
    protected function tabular(array $cells): ?array
    {
        $empty = true;
        foreach ($cells as $c) {
            if ($c !== null && trim((string) $c) !== '') { $empty = false; break; }
        }
        if ($empty) return null;
        if (!isset($this->state['header'])) {
            if (!array_key_exists('header', $this->opt) || !empty($this->opt['header'])) {
                $this->state['header'] = self::headerNames($cells);
                return null;
            }
            $this->state['header'] = [];
        }
        $row = [];
        foreach ($cells as $i => $v) {
            $v = trim((string) $v);
            if (!isset($this->state['header'][$i])) {
                if ($v === '') continue;
                $this->state['header'][$i] = self::uniqueName('Колонка ' . ($i + 1), $this->state['header']);
            }
            $row[$this->state['header'][$i]] = $v;
        }
        return $row;
    }

    /** Имена колонок из строки заголовка: без BOM и пробелов по краям, пустые — «Колонка N», дубли — «Имя (2)» */
    protected static function headerNames(array $cells): array
    {
        $out = [];
        foreach ($cells as $i => $v) {
            $name = trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $v));
            $name = mb_substr(preg_replace('/\s+/u', ' ', $name), 0, 100);
            if ($name === '') $name = 'Колонка ' . ($i + 1);
            $out[$i] = self::uniqueName($name, $out);
        }
        return $out;
    }

    protected static function uniqueName(string $name, array $taken): string
    {
        $base = $name; $k = 2;
        while (in_array($name, $taken, true)) $name = $base . ' (' . $k++ . ')';
        return $name;
    }

    /** Число из ячейки XLSX без «хвостов» двоичной арифметики: 1019.9999999999 → 1020 */
    protected static function cleanNumber(string $v): string
    {
        if ($v === '' || !is_numeric($v) || (!str_contains($v, '.') && !str_contains($v, 'E') && !str_contains($v, 'e'))) return $v;
        $f = (float) $v;
        if (abs($f) >= 1e15) return $v;
        $s = rtrim(rtrim(sprintf('%.10F', round($f, 10)), '0'), '.');
        return $s === '-0' ? '0' : $s;
    }

    /**
     * Флаги XMLReader. Без LIBXML_PARSEHUGE: работают защитные лимиты libxml (текстовый узел ≤ 10 МБ,
     * вложенность, «раздувание» сущностей) — zip-бомба с гигантской ячейкой или XML-бомба дают ошибку разбора,
     * а не съедают память сервера. Большим файлам (400 000+ строк) лимиты не мешают.
     */
    protected const XML_FLAGS = LIBXML_NONET | LIBXML_COMPACT;

    /**
     * DOCTYPE в XML: объявления сущностей (<!ENTITY>, в т. ч. внешние — XXE) запрещены; простой
     * «<!DOCTYPE yml_catalog SYSTEM "shops.dtd">» из YML допустим ($allowDtd), DTD при этом не загружается.
     */
    protected static function checkDoctype(\XMLReader $r, bool $allowDtd): void
    {
        if ($r->nodeType !== \XMLReader::DOC_TYPE) return;
        if (!$allowDtd || stripos((string) $r->readOuterXml(), '<!ENTITY') !== false) {
            throw new \RuntimeException('XML с объявлениями <!DOCTYPE>/<!ENTITY> не поддерживается — выгрузите файл без DOCTYPE');
        }
    }

    /** Элемент не разворачивается (битая разметка, обрыв файла, текст больше 10 МБ) — ошибка, а не молча пропущенная строка */
    protected static function badNode(string $what): never
    {
        $e = libxml_get_last_error();
        throw new \RuntimeException($what . ' не читается' . ($e ? ': ' . trim((string) $e->message) : '')
            . '. Файл повреждён, обрезан или в ячейке слишком много текста (больше 10 МБ).');
    }

    /** Начать чтение XML: ошибки libxml копятся внутри (без предупреждений PHP), см. xmlFatal() */
    protected static function xmlErrorsOn(): bool
    {
        $prev = libxml_use_internal_errors(true);
        libxml_clear_errors();
        return $prev;
    }

    /**
     * Чтение XML закончилось: если libxml остановился на фатальной ошибке (обрыв файла, битая разметка,
     * слишком большой элемент) — исключение, иначе файл молча обрезался бы на месте ошибки.
     */
    protected static function xmlFatal(bool $prev, string $what = 'XML'): void
    {
        $fatal = null;
        foreach (libxml_get_errors() as $e) {
            if ($e->level === LIBXML_ERR_FATAL) { $fatal = $e; break; }
        }
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        if ($fatal) {
            throw new \RuntimeException('Ошибка в ' . $what . ($fatal->line > 1 ? ' (строка ' . $fatal->line . ')' : '') . ': ' . trim((string) $fatal->message)
                . '. Проверьте файл — он повреждён, обрезан или содержит слишком большой элемент.');
        }
    }
}
