<?php
declare(strict_types=1);

namespace App\Services\Suppliers;

/**
 * Чтение старого формата Excel 97–2003 (.xls, BIFF8) без библиотек — для таблиц соответствия поставщиков
 * (таблица категорий старого загрузчика Jong•Golf хранится именно так).
 *
 *   $rows = BiffReader::firstSheet($path);   // [номер строки с 1 => [номер колонки с 0 => текст]]
 *
 * Файл — составной документ OLE2 (CFB): из него достаётся поток «Workbook», в нём — записи BIFF8.
 * Читаются значения ячеек первого листа: общие строки (SST + CONTINUE), LABEL/RSTRING, числа (NUMBER, RK, MULRK),
 * формулы с готовым результатом. Оформление, формулы и прочие листы не нужны и пропускаются.
 * Файл целиком в памяти — таблицы соответствия маленькие (до ~10 МБ).
 */
final class BiffReader
{
    private const MAX_BYTES = 20 * 1048576;
    private const END = 0xFFFFFFFE;

    private string $data;
    private int $sector = 512;
    private array $fat = [];

    /** Строки первого листа. Ошибка формата — RuntimeException с понятным текстом. */
    public static function firstSheet(string $path): array
    {
        $size = @filesize($path);
        if ($size === false || $size < 512) throw new \RuntimeException('Файл пустой или повреждён');
        if ($size > self::MAX_BYTES) throw new \RuntimeException('Файл XLS больше 20 МБ');
        $r = new self();
        $r->data = (string) file_get_contents($path);
        if (!str_starts_with($r->data, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1")) {
            throw new \RuntimeException('Это не книга Excel 97–2003 (XLS). Сохраните таблицу как XLSX или CSV.');
        }
        $book = $r->stream(['Workbook', 'Book']);
        if ($book === null) throw new \RuntimeException('В файле нет книги Excel');
        return self::sheet($book);
    }

    // ------------------------------------------------------------------ OLE2 (составной документ)

    /** Поток по имени (первое найденное из $names) или null */
    private function stream(array $names): ?string
    {
        $d = $this->data;
        $shift = self::u16($d, 0x1E);
        if ($shift < 7 || $shift > 16) throw new \RuntimeException('Файл XLS повреждён (заголовок)');
        $this->sector = 1 << $shift;
        $miniSector = 1 << self::u16($d, 0x20);
        $cutoff = self::u32($d, 0x38);
        // таблица FAT: первые 109 номеров — в заголовке, остальные — в цепочке DIFAT
        $fatSectors = [];
        for ($i = 0; $i < 109; $i++) {
            $s = self::u32($d, 0x4C + $i * 4);
            if ($s >= self::END) break;
            $fatSectors[] = $s;
        }
        $difat = self::u32($d, 0x44);
        $guard = 0;
        while ($difat < self::END && $guard++ < 10000) {
            $off = $this->offset($difat);
            $n = intdiv($this->sector, 4) - 1;
            for ($i = 0; $i < $n; $i++) {
                $s = self::u32($d, $off + $i * 4);
                if ($s < self::END) $fatSectors[] = $s;
            }
            $difat = self::u32($d, $off + $n * 4);
        }
        $fat = [];
        foreach ($fatSectors as $s) {
            $chunk = substr($d, $this->offset($s), $this->sector);
            $fat = array_merge($fat, array_values(unpack('V*', $chunk) ?: []));
        }
        $this->fat = $fat;

        // каталог: записи по 128 байт
        $dir = $this->chain(self::u32($d, 0x30));
        $entries = [];
        for ($off = 0; $off + 128 <= strlen($dir); $off += 128) {
            $len = self::u16($dir, $off + 0x40);
            if ($len < 2 || $len > 64) continue;
            $name = mb_convert_encoding(substr($dir, $off, $len - 2), 'UTF-8', 'UTF-16LE');
            $entries[] = ['name' => $name, 'type' => ord($dir[$off + 0x42]), 'start' => self::u32($dir, $off + 0x74), 'size' => self::u32($dir, $off + 0x78)];
        }
        $root = null;
        foreach ($entries as $e) if ($e['type'] === 5) { $root = $e; break; }
        foreach ($names as $want) {
            foreach ($entries as $e) {
                if ($e['type'] !== 2 || strcasecmp($e['name'], $want) !== 0) continue;
                if ($e['size'] < $cutoff && $root !== null) {
                    // маленький поток — в мини-потоке корневой записи, адресация по мини-FAT
                    $mini = $this->chain($root['start']);
                    $miniFat = array_values(unpack('V*', $this->chain(self::u32($d, 0x3C))) ?: []);
                    $out = ''; $s = $e['start']; $guard = 0;
                    while ($s < self::END && $guard++ < 1000000 && strlen($out) < $e['size']) {
                        $out .= substr($mini, $s * $miniSector, $miniSector);
                        $s = $miniFat[$s] ?? self::END;
                    }
                    return substr($out, 0, $e['size']);
                }
                return substr($this->chain($e['start']), 0, $e['size']);
            }
        }
        return null;
    }

    /** Данные цепочки секторов, начиная с $start */
    private function chain(int $start): string
    {
        $out = ''; $s = $start; $guard = 0; $max = count($this->fat) + 1;
        while ($s < self::END && $guard++ < $max) {
            $out .= substr($this->data, $this->offset($s), $this->sector);
            $s = $this->fat[$s] ?? self::END;
        }
        return $out;
    }

    private function offset(int $sector): int
    {
        $off = ($sector + 1) * $this->sector;
        if ($off >= strlen($this->data)) throw new \RuntimeException('Файл XLS повреждён или обрезан');
        return $off;
    }

    // ------------------------------------------------------------------ BIFF8

    /** Ячейки первого листа книги */
    private static function sheet(string $b): array
    {
        $len = strlen($b);
        $sst = [];
        $sheetPos = null;
        $pos = 0;
        // глобальная часть книги: версия, общие строки, адрес первого листа
        while ($pos + 4 <= $len) {
            $id = self::u16($b, $pos);
            $size = self::u16($b, $pos + 2);
            $body = substr($b, $pos + 4, $size);
            $next = $pos + 4 + $size;
            if ($id === 0x0809 && $pos === 0 && self::u16($body, 0) !== 0x0600) {
                throw new \RuntimeException('Файл Excel 95 и старше не поддерживается — сохраните таблицу как XLSX или CSV');
            }
            if ($id === 0x0085 && $sheetPos === null && ord($body[5] ?? "\0") === 0) $sheetPos = self::u32($body, 0);   // первый рабочий лист
            if ($id === 0x00FC) {
                $chunks = [$body];
                while ($next + 4 <= $len && self::u16($b, $next) === 0x003C) {   // CONTINUE
                    $cs = self::u16($b, $next + 2);
                    $chunks[] = substr($b, $next + 4, $cs);
                    $next += 4 + $cs;
                }
                $sst = self::sst($chunks);
            }
            if ($id === 0x000A) break;                                      // конец глобальной части
            $pos = $next;
        }
        if ($sheetPos === null || $sheetPos >= $len) throw new \RuntimeException('В книге нет листа с данными');

        $rows = [];
        $pos = $sheetPos;
        $pendingFormula = null;
        $guard = 0;
        while ($pos + 4 <= $len && $guard++ < 5000000) {
            $id = self::u16($b, $pos);
            $size = self::u16($b, $pos + 2);
            $body = substr($b, $pos + 4, $size);
            $pos += 4 + $size;
            if ($id === 0x000A) break;                                      // конец листа
            switch ($id) {
                case 0x00FD:                                                // LABELSST
                    $rows[self::u16($body, 0) + 1][self::u16($body, 2)] = $sst[self::u32($body, 6)] ?? '';
                    break;
                case 0x0204:                                                // LABEL
                case 0x00D6:                                                // RSTRING
                    $rows[self::u16($body, 0) + 1][self::u16($body, 2)] = self::xlString($body, 6)[0];
                    break;
                case 0x0203:                                                // NUMBER
                    $rows[self::u16($body, 0) + 1][self::u16($body, 2)] = self::num(unpack('e', substr($body, 6, 8))[1]);
                    break;
                case 0x027E:                                                // RK
                    $rows[self::u16($body, 0) + 1][self::u16($body, 2)] = self::num(self::rk(self::u32($body, 6)));
                    break;
                case 0x00BD:                                                // MULRK
                    $row = self::u16($body, 0) + 1;
                    $col = self::u16($body, 2);
                    for ($o = 4; $o + 6 <= $size - 2; $o += 6, $col++) $rows[$row][$col] = self::num(self::rk(self::u32($body, $o + 2)));
                    break;
                case 0x0006:                                                // FORMULA: готовый результат
                    $row = self::u16($body, 0) + 1;
                    $col = self::u16($body, 2);
                    if (substr($body, 12, 2) === "\xFF\xFF") {
                        $t = ord($body[6]);
                        if ($t === 0) $pendingFormula = [$row, $col];          // строка — в следующей записи STRING
                        elseif ($t === 1) $rows[$row][$col] = ord($body[8]) ? '1' : '0';
                        elseif ($t === 3) $rows[$row][$col] = '';
                    } else {
                        $rows[$row][$col] = self::num(unpack('e', substr($body, 6, 8))[1]);
                    }
                    break;
                case 0x0207:                                                // STRING (результат формулы)
                    if ($pendingFormula) { $rows[$pendingFormula[0]][$pendingFormula[1]] = self::xlString($body, 0)[0]; $pendingFormula = null; }
                    break;
                case 0x0205:                                                // BOOLERR
                    if (ord($body[7] ?? "\0") === 0) $rows[self::u16($body, 0) + 1][self::u16($body, 2)] = ord($body[6]) ? '1' : '0';
                    break;
            }
        }
        ksort($rows);
        foreach ($rows as &$r) ksort($r);
        unset($r);
        return $rows;
    }

    /**
     * Таблица общих строк: строки могут разрываться между записями CONTINUE; при разрыве внутри символов
     * продолжение начинается с байта флагов (1 — два байта на символ, 0 — один).
     */
    private static function sst(array $chunks): array
    {
        $out = [];
        $ci = 0; $p = 8;                                                    // пропуск «всего» и «уникальных»
        $total = self::u32($chunks[0], 4);
        $cur = static function () use (&$chunks, &$ci, &$p): ?string {
            while ($ci < count($chunks) && $p >= strlen($chunks[$ci])) { $ci++; $p = 0; }
            return $chunks[$ci] ?? null;
        };
        // $n байт подряд (служебные данные разрываются без флагов)
        $take = static function (int $n) use (&$chunks, &$ci, &$p, $cur): string {
            $s = '';
            while ($n > 0 && ($c = $cur()) !== null) {
                $part = substr($c, $p, $n);
                $s .= $part; $p += strlen($part); $n -= strlen($part);
            }
            return $s;
        };
        for ($i = 0; $i < $total; $i++) {
            if ($cur() === null) break;
            $cch = self::u16($take(2), 0);
            $flags = ord($take(1));
            $wide = ($flags & 0x01) === 1;
            $runs = ($flags & 0x08) ? self::u16($take(2), 0) : 0;
            $ext = ($flags & 0x04) ? self::u32($take(4), 0) : 0;
            $str = '';
            $left = $cch;
            while ($left > 0 && ($c = $cur()) !== null) {
                $avail = intdiv(strlen($c) - $p, $wide ? 2 : 1);
                if ($avail <= 0) {                                          // символы кончились на границе записи
                    $ci++; $p = 0;
                    if (($c2 = $cur()) === null) break;
                    $wide = (ord($c2[0]) & 0x01) === 1;                     // байт флагов продолжения
                    $p = 1;
                    continue;
                }
                $n = min($left, $avail);
                $bytes = substr($c, $p, $n * ($wide ? 2 : 1));
                $str .= $wide ? mb_convert_encoding($bytes, 'UTF-8', 'UTF-16LE') : mb_convert_encoding($bytes, 'UTF-8', 'ISO-8859-1');
                $p += strlen($bytes);
                $left -= $n;
                if ($left > 0) {                                            // строка продолжается в следующей записи
                    $ci++; $p = 0;
                    if (($c2 = $cur()) === null) break;
                    $wide = (ord($c2[0]) & 0x01) === 1;
                    $p = 1;
                }
            }
            if ($runs) $take($runs * 4);
            if ($ext) $take($ext);
            $out[] = $str;
        }
        return $out;
    }

    /** Строка XLUnicodeString (длина 2 байта, флаги, символы) с позиции $o: [текст, байт занято] */
    private static function xlString(string $s, int $o): array
    {
        $cch = self::u16($s, $o);
        $flags = ord($s[$o + 2] ?? "\0");
        $p = $o + 3;
        if ($flags & 0x08) $p += 2;
        if ($flags & 0x04) $p += 4;
        $bytes = substr($s, $p, $cch * (($flags & 0x01) ? 2 : 1));
        $text = ($flags & 0x01) ? mb_convert_encoding($bytes, 'UTF-8', 'UTF-16LE') : mb_convert_encoding($bytes, 'UTF-8', 'ISO-8859-1');
        return [$text, $p + strlen($bytes) - $o];
    }

    /** Число RK: целое (×100) или старшие 30 бит double (×100) */
    private static function rk(int $rk): float
    {
        if ($rk & 0x02) {
            $v = (float) ($rk >> 2);
            if ($rk & 0x80000000) $v = (float) (($rk >> 2) - 0x40000000);   // отрицательное целое
        } else {
            $v = unpack('e', "\0\0\0\0" . pack('V', $rk & 0xFFFFFFFC))[1];
        }
        return ($rk & 0x01) ? $v / 100 : $v;
    }

    /** Число без «хвостов» двоичной арифметики: 26.0 → «26», 1.1000000001 → «1.1» */
    private static function num(float $v): string
    {
        if (is_nan($v) || is_infinite($v)) return '';
        if (abs($v - round($v)) < 1e-9 && abs($v) < 1e15) return (string) (int) round($v);
        return rtrim(rtrim(sprintf('%.10F', $v), '0'), '.');
    }

    private static function u16(string $s, int $o): int
    {
        return isset($s[$o + 1]) ? unpack('v', $s, $o)[1] : 0;
    }

    private static function u32(string $s, int $o): int
    {
        return isset($s[$o + 3]) ? unpack('V', $s, $o)[1] : 0;
    }
}
