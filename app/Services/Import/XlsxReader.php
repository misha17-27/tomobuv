<?php
declare(strict_types=1);

namespace App\Services\Import;

/**
 * XLSX без библиотек: ZipArchive + XMLReader. Читается первый лист книги (по workbook.xml),
 * строки идут потоком, в памяти только таблица общих строк (sharedStrings.xml).
 * Продолжение после остановки — по номеру строки (уже прочитанные строки пропускаются без разбора).
 */
final class XlsxReader extends Reader
{
    private const MAX_ENTRY = 1024 * 1024 * 1024;   // защита от «zip-бомбы»: распакованный лист не больше 1 ГБ
    private const MAX_RATIO = 100;                  // …и сжат не сильнее 1:100 (обычный лист Excel — 1:5…1:20)
    private const RATIO_FROM = 20 * 1024 * 1024;    // степень сжатия проверяется у частей больше 20 МБ

    public function rows(array $state = []): \Generator
    {
        $this->state = $state;
        [$sheet, $shared] = self::locate($this->path);
        $strings = $shared !== null ? self::sharedStrings($this->path, $shared) : [];
        $r = new \XMLReader();
        if (!$r->open(self::entryUri($this->path, $sheet), null, self::XML_FLAGS)) {
            throw new \RuntimeException('Не удалось прочитать лист книги');
        }
        $doc = new \DOMDocument();
        $skip = (int) ($state['n'] ?? 0);
        $auto = 0;
        $prev = self::xmlErrorsOn();
        try {
            $ok = @$r->read();
            while ($ok) {
                self::checkDoctype($r, false);
                if ($r->nodeType === \XMLReader::ELEMENT && $r->localName === 'row') {
                    $n = (int) $r->getAttribute('r');
                    $n = $n > 0 ? $n : $auto + 1;
                    $auto = $n;
                    if ($n > $skip) {
                        $node = @$r->expand($doc);
                        if (!$node instanceof \DOMElement) self::badNode('Строка ' . $n . ' листа');
                        $cells = self::cells($node, $strings);
                        $this->state['n'] = $n;
                        $row = $this->tabular($cells);
                        if ($row !== null) yield $n => $row;
                    }
                    $ok = @$r->next();
                    continue;
                }
                $ok = @$r->read();
            }
            self::xmlFatal($prev, 'листе книги');
        } finally {
            $r->close();
            libxml_use_internal_errors($prev);
        }
    }

    /** Значения ячеек строки по позициям (пропущенные ячейки — пустые строки) */
    private static function cells(\DOMElement $row, array $strings): array
    {
        $out = [];
        $pos = 0;
        foreach ($row->childNodes as $c) {
            if (!$c instanceof \DOMElement || $c->localName !== 'c') continue;
            $ref = $c->getAttribute('r');
            $idx = $ref !== '' ? self::colIndex($ref) : $pos;
            $pos = $idx + 1;
            $t = $c->getAttribute('t');
            $v = '';
            foreach ($c->childNodes as $x) {
                if (!$x instanceof \DOMElement) continue;
                if ($x->localName === 'v') $v = $x->textContent;
                elseif ($x->localName === 'is') $v = self::richText($x);
            }
            $out[$idx] = match ($t) {
                's'         => (string) ($strings[(int) $v] ?? ''),
                'inlineStr' => $v,
                'str', 'b'  => $v,
                'e'         => '',
                default     => self::cleanNumber($v),
            };
        }
        if (!$out) return [];
        $max = max(array_keys($out));
        if ($max > 1000) $max = 1000;                                // лишние пустые колонки не тянем
        $row2 = array_fill(0, $max + 1, '');
        foreach ($out as $i => $v) if ($i <= $max) $row2[$i] = $v;
        return $row2;
    }

    /** «AB12» → 27 (номер колонки с нуля) */
    private static function colIndex(string $ref): int
    {
        $n = 0;
        $len = strlen($ref);
        for ($i = 0; $i < $len; $i++) {
            $ch = ord($ref[$i]);
            if ($ch < 65 || $ch > 90) break;
            $n = $n * 26 + ($ch - 64);
        }
        return max(0, $n - 1);
    }

    /** Текст элемента <si>/<is>: все <t>, кроме фонетических подсказок <rPh> */
    private static function richText(\DOMElement $el): string
    {
        $s = '';
        foreach ($el->childNodes as $x) {
            if (!$x instanceof \DOMElement) continue;
            if ($x->localName === 't') $s .= $x->textContent;
            elseif ($x->localName === 'r') {
                foreach ($x->childNodes as $t) if ($t instanceof \DOMElement && $t->localName === 't') $s .= $t->textContent;
            }
        }
        return $s;
    }

    /** Таблица общих строк — потоково */
    private static function sharedStrings(string $path, string $entry): array
    {
        $r = new \XMLReader();
        if (!$r->open(self::entryUri($path, $entry), null, self::XML_FLAGS)) return [];
        $doc = new \DOMDocument();
        $out = [];
        $prev = self::xmlErrorsOn();
        try {
            $ok = @$r->read();
            while ($ok) {
                self::checkDoctype($r, false);
                if ($r->nodeType === \XMLReader::ELEMENT && $r->localName === 'si') {
                    $node = @$r->expand($doc);
                    if (!$node instanceof \DOMElement) self::badNode('Текст №' . (count($out) + 1) . ' книги');
                    $out[] = self::richText($node);
                    $ok = @$r->next();
                    continue;
                }
                $ok = @$r->read();
            }
            self::xmlFatal($prev, 'таблице строк книги');
        } finally {
            $r->close();
            libxml_use_internal_errors($prev);
        }
        return $out;
    }

    /**
     * Путь к первому листу и к таблице общих строк внутри архива.
     * @return array{0:string, 1:?string}
     */
    private static function locate(string $path): array
    {
        $zip = new \ZipArchive();
        if ($zip->open($path, \ZipArchive::RDONLY) !== true) throw new \RuntimeException('Файл XLSX повреждён');
        $sheet = null; $shared = null;
        $rels = [];
        $relXml = $zip->getFromName('xl/_rels/workbook.xml.rels');
        if ($relXml !== false && ($x = @simplexml_load_string($relXml, 'SimpleXMLElement', LIBXML_NONET)) !== false) {
            foreach ($x->Relationship as $rel) {
                $target = (string) $rel['Target'];
                $target = str_starts_with($target, '/') ? ltrim($target, '/') : 'xl/' . $target;
                $rels[(string) $rel['Id']] = $target;
                if (str_ends_with((string) $rel['Type'], '/sharedStrings')) $shared = $target;
            }
        }
        $wb = $zip->getFromName('xl/workbook.xml');
        if ($wb !== false && ($x = @simplexml_load_string($wb, 'SimpleXMLElement', LIBXML_NONET)) !== false) {
            $x->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
            foreach ($x->xpath('//m:sheets/m:sheet') ?: [] as $s) {
                $rid = (string) $s->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
                if ($rid !== '' && isset($rels[$rid])) { $sheet = $rels[$rid]; break; }
            }
        }
        $sheet ??= 'xl/worksheets/sheet1.xml';
        if ($shared === null && $zip->locateName('xl/sharedStrings.xml') !== false) $shared = 'xl/sharedStrings.xml';
        foreach (array_filter([$sheet, $shared]) as $e) {
            $st = $zip->statName($e);
            if ($st === false) { $zip->close(); throw new \RuntimeException('В книге нет листа с данными'); }
            if ((int) $st['size'] > self::MAX_ENTRY) { $zip->close(); throw new \RuntimeException('Лист книги слишком большой'); }
            if ((int) $st['size'] > self::RATIO_FROM && (int) $st['size'] > self::MAX_RATIO * max(1, (int) $st['comp_size'])) {
                $zip->close();
                throw new \RuntimeException('Файл XLSX сжат подозрительно сильно (похоже на zip-бомбу) — сохраните прайс заново в Excel или как CSV');
            }
        }
        $zip->close();
        return [$sheet, $shared];
    }

    private static function entryUri(string $path, string $entry): string
    {
        return 'zip://' . str_replace('\\', '/', (string) realpath($path)) . '#' . $entry;
    }

    /** Проверка: архив открывается, есть книга и лист. Возвращает имя первого листа. */
    public static function prepareFile(string $path): array
    {
        if (!class_exists(\ZipArchive::class) || !class_exists(\XMLReader::class)) {
            throw new \RuntimeException('На сервере нет расширений zip/xmlreader — сохраните файл как CSV');
        }
        $zip = new \ZipArchive();
        if ($zip->open($path, \ZipArchive::RDONLY) !== true || $zip->locateName('xl/workbook.xml') === false) {
            throw new \RuntimeException('Это не книга Excel (XLSX). Старый формат XLS сохраните как XLSX или CSV.');
        }
        $name = '';
        $wb = $zip->getFromName('xl/workbook.xml');
        if ($wb !== false && preg_match('/<(?:\w+:)?sheet\b[^>]*\bname="([^"]*)"/', $wb, $m)) $name = html_entity_decode($m[1], ENT_QUOTES | ENT_XML1, 'UTF-8');
        $zip->close();
        [$sheet, $shared] = self::locate($path);
        self::checkProlog($path, array_filter([$sheet, $shared]));
        return ['sheet' => $name];
    }

    /** В настоящих XLSX нет DOCTYPE — лист или таблица строк с ним (XXE, «XML-бомба») отклоняются сразу при загрузке */
    private static function checkProlog(string $path, array $entries): void
    {
        foreach ($entries as $e) {
            $r = new \XMLReader();
            if (!@$r->open(self::entryUri($path, $e), null, self::XML_FLAGS)) continue;
            $prev = self::xmlErrorsOn();
            try {
                while (@$r->read()) {
                    self::checkDoctype($r, false);
                    if ($r->nodeType === \XMLReader::ELEMENT) break;
                }
            } finally {
                $r->close();
                libxml_clear_errors();
                libxml_use_internal_errors($prev);
            }
        }
    }
}
