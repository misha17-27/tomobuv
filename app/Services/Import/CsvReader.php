<?php
declare(strict_types=1);

namespace App\Services\Import;

/**
 * CSV: разделитель ; , TAB или | (определяется автоматически), кодировка UTF-8 / Windows-1251 / UTF-16
 * (при подготовке файл перекодируется в UTF-8), BOM пропускается. Чтение потоковое, с продолжением
 * по байтовому смещению.
 */
final class CsvReader extends Reader
{
    public const DELIMITERS = ['' => 'Автоматически', ';' => 'Точка с запятой ( ; )', ',' => 'Запятая ( , )', 'tab' => 'Табуляция', '|' => 'Вертикальная черта ( | )'];

    public function rows(array $state = []): \Generator
    {
        $this->state = $state;
        $h = @fopen($this->path, 'rb');
        if (!$h) throw new \RuntimeException('Не удалось открыть файл');
        $offset = (int) ($state['offset'] ?? 0);
        if ($offset > 0) {
            fseek($h, $offset);
        } elseif (fread($h, 3) !== "\xEF\xBB\xBF") {
            rewind($h);
        }
        $d = $this->delimiter();
        $n = (int) ($state['n'] ?? 0);
        try {
            while (($cells = fgetcsv($h, 0, $d, '"', '')) !== false) {
                $n++;
                $this->state['n'] = $n;
                $this->state['offset'] = ftell($h);
                if ($cells === [null]) continue;               // пустая строка
                $row = $this->tabular($cells);
                if ($row !== null) yield $n => $row;
            }
        } finally {
            fclose($h);
        }
    }

    private function delimiter(): string
    {
        $d = (string) ($this->opt['delimiter'] ?? '');
        if ($d === 'tab') return "\t";
        if (in_array($d, [';', ',', '|'], true)) return $d;
        return self::sniffDelimiter($this->path);
    }

    /**
     * Проверка файла и приведение к UTF-8 (Windows-1251 и UTF-16 перекодируются в новый файл).
     * @return array{encoding:string, delimiter:string}
     */
    public static function prepareFile(string $path): array
    {
        $h = fopen($path, 'rb');
        $head = (string) fread($h, 262144);
        fclose($h);
        $enc = 'UTF-8';
        if (str_starts_with($head, "\xFF\xFE")) $enc = 'UTF-16LE';
        elseif (str_starts_with($head, "\xFE\xFF")) $enc = 'UTF-16BE';
        elseif (!str_starts_with($head, "\xEF\xBB\xBF")) {
            // Обрезаем по последнему переводу строки, чтобы не разрезать многобайтный символ
            $sample = strlen($head) >= 262144 ? substr($head, 0, (int) strrpos($head, "\n")) : $head;
            if (!mb_check_encoding($sample, 'UTF-8')) $enc = 'Windows-1251';
        }
        if ($enc !== 'UTF-8') self::convert($path, $enc);
        return ['encoding' => $enc === 'Windows-1251' ? 'Windows-1251' : ($enc === 'UTF-8' ? 'UTF-8' : 'UTF-16'), 'delimiter' => self::delimiterCode(self::sniffDelimiter($path))];
    }

    /** Перекодировать файл в UTF-8 порциями (без загрузки целиком в память) */
    private static function convert(string $path, string $from): void
    {
        $tmp = $path . '.utf8';
        $in = fopen($path, 'rb');
        $out = fopen($tmp, 'wb');
        $utf16 = str_starts_with($from, 'UTF-16');
        if ($utf16) fread($in, 2);                                   // BOM
        $carry = '';
        while (!feof($in)) {
            $chunk = $carry . (string) fread($in, 1048576);
            $carry = '';
            if ($utf16) {
                if (strlen($chunk) % 2) { $carry = substr($chunk, -1); $chunk = substr($chunk, 0, -1); }
                // не разрезаем суррогатную пару: старшее слово D800–DBFF в конце порции переносим дальше
                if (strlen($chunk) >= 2) {
                    $last = substr($chunk, -2);
                    $hi = $from === 'UTF-16LE' ? ord($last[1]) : ord($last[0]);
                    if ($hi >= 0xD8 && $hi <= 0xDB && !feof($in)) { $carry = $last . $carry; $chunk = substr($chunk, 0, -2); }
                }
            }
            if ($chunk !== '') fwrite($out, (string) mb_convert_encoding($chunk, 'UTF-8', $from));
        }
        if ($carry !== '' && $utf16 && strlen($carry) % 2 === 0) fwrite($out, (string) mb_convert_encoding($carry, 'UTF-8', $from));
        fclose($in);
        fclose($out);
        if (!@rename($tmp, $path)) {                                  // Windows: rename поверх существующего файла
            @unlink($path);
            rename($tmp, $path);
        }
    }

    /** Разделитель по первым строкам: тот, что встречается чаще и одинаково в каждой строке (вне кавычек) */
    public static function sniffDelimiter(string $path): string
    {
        $h = fopen($path, 'rb');
        $lines = [];
        while (count($lines) < 6 && ($line = fgets($h, 65536)) !== false) {
            $line = preg_replace('/"[^"]*"/', '""', $line);           // содержимое кавычек не считаем
            if (trim((string) $line) !== '') $lines[] = $line;
        }
        fclose($h);
        $best = ';'; $bestScore = 0;
        foreach ([';', "\t", ',', '|'] as $d) {
            $counts = array_map(static fn($l) => substr_count((string) $l, $d), $lines);
            if (!$counts || $counts[0] === 0) continue;
            $same = count(array_unique($counts)) === 1;
            $score = $counts[0] * ($same ? 3 : 1);
            if ($score > $bestScore) { $best = $d; $bestScore = $score; }
        }
        return $best;
    }

    public static function delimiterCode(string $d): string
    {
        return $d === "\t" ? 'tab' : $d;
    }
}
