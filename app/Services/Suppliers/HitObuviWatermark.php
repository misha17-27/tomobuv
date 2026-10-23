<?php
declare(strict_types=1);

namespace App\Services\Suppliers;

use App\Core\App;
use App\Core\Cache;
use App\Core\Image;
use App\Services\AdminCatalog;

/**
 * Замена водяного знака поставщика «hit-obuvi.com.ua» (серая надпись справа внизу фото) на «tomobuv.com.ua» — настройка
 * «Хит обуви» watermark (по умолчанию включена):
 *   - новые фото — при вписывании в холст (HitObuviSync::retouch, SupplierSync::frame);
 *   - фото, загруженные раньше, — backlog() из bin/cron.php (раз в час, порциями): обработанное фото сохраняется под новым
 *     id (новый адрес — Cloudflare и браузеры кэшируют миниатюры на год), старое удаляется.
 * Поиск (find): в правом нижнем углу — строка тонких штрихов (пиксель темнее соседей с обеих сторон: края фона и обуви
 * не в счёт), подходящая по высоте, ширине и пропорциям; затем сверка с образцом надписи поставщика
 * (database/suppliers/watermark/hitobuvi.png, усреднён по фото поставщика): корреляция ≥ MIN_CORR. Не нашлось или не похоже —
 * фото не меняется (своя надпись повторно не трогается: с образцом поставщика она не совпадает).
 * Замена: полоса надписи закрашивается по столбцам плавным переходом от фона над ней к фону под ней (переходы фона
 * сохраняются), поверх — «tomobuv.com.ua» (database/suppliers/watermark/tomobuv.png) той же высоты, прижатая вправо,
 * полупрозрачным тёмным (как надпись поставщика).
 */
final class HitObuviWatermark
{
    private const TEMPLATE = '/database/suppliers/watermark/hitobuvi.png';
    private const TEXT = '/database/suppliers/watermark/tomobuv.png';
    private const MIN_CORR = 0.6;
    /** Непрозрачность своей надписи (тёмным по фону): на белом — серый ≈ 200, как у надписи поставщика */
    private const ALPHA = 0.22;
    private const STATE = '/hitobuvi-watermark.json';
    /** Версия поиска: выросла — backlog проверяет заново все фото «Хит обуви» (2: надпись у нижнего края, сетка/ткань «штриховее» надписи) */
    private const VERSION = 2;

    /** Найти и заменить надпись поставщика. @return bool заменена */
    public static function apply(\GdImage $im): bool
    {
        $box = self::find($im);
        if ($box === null) return false;
        self::erase($im, $box);
        self::draw($im, $box);
        return true;
    }

    /**
     * Рамка надписи поставщика [x0, y0, x1, y1] (включительно) или null. Ищется в нижних 40 % справа (от 30 % ширины до края,
     * до нижнего края): строки с больше всего тонких штрихов — кандидаты (ткань, сетка, шнурки бывают «штриховее» надписи,
     * поэтому до 6 полос, в каждой — все куски подходящего размера); надпись — кусок с лучшей сверкой с образцом (≥ MIN_CORR).
     */
    public static function find(\GdImage $im): ?array
    {
        $W = imagesx($im); $H = imagesy($im);
        if ($W < 200 || $H < 200) return null;
        if (!imageistruecolor($im)) imagepalettetotruecolor($im);
        $rx0 = (int) (.3 * $W); $ry0 = (int) (.6 * $H); $rw = $W - $rx0; $rh = $H - $ry0;
        $g = [];
        for ($y = 0; $y < $rh; $y++) {
            $row = [];
            for ($x = 0; $x < $rw; $x++) $row[] = self::gray($im, $rx0 + $x, $ry0 + $y);
            $g[] = $row;
        }
        // тонкие штрихи: пиксель (не тёмная обувь) темнее больше чем на 12 обеих сторон по горизонтали или по вертикали (до 3 px)
        $dip = array_fill(0, $rh, []);
        $P = array_fill(0, $rh, 0);
        for ($y = 3; $y < $rh - 3; $y++) {
            $u1 = $g[$y - 1]; $u2 = $g[$y - 2]; $u3 = $g[$y - 3]; $d1 = $g[$y + 1]; $d2 = $g[$y + 2]; $d3 = $g[$y + 3]; $r = $g[$y];
            for ($x = 3; $x < $rw - 3; $x++) {
                $v = $r[$x];
                if ($v <= 90) continue;
                $h = min(max($r[$x - 1], $r[$x - 2], $r[$x - 3]), max($r[$x + 1], $r[$x + 2], $r[$x + 3]));
                $vv = min(max($u1[$x], $u2[$x], $u3[$x]), max($d1[$x], $d2[$x], $d3[$x]));
                if (max($h, $vv) - $v > 12) { $dip[$y][$x] = true; $P[$y]++; }
            }
        }
        $rows = $P;
        arsort($rows);
        $gap = (int) (.02 * $W);
        $best = null; $bestCorr = self::MIN_CORR; $used = []; $tried = 0;
        foreach ($rows as $yc => $n) {
            if ($n < 10 || $tried >= 6) break;
            if (isset($used[$yc])) continue;
            $tried++;
            // полоса: строка и соседние с не меньше чем четвертью её штрихов
            $t = max(6, .25 * $n);
            $a = $yc; while ($a > 0 && $P[$a - 1] >= $t) $a--;
            $z = $yc; while ($z < $rh - 1 && $P[$z + 1] >= $t) $z++;
            for ($y = $a - 6; $y <= $z + 6; $y++) $used[$y] = true;          // соседние строки той же полосы не перебирать
            // по ширине: столбцы со штрихами, разрывы больше 2 % ширины делят на куски — каждый подходящий кусок сверяется
            $Q = array_fill(0, $rw, 0);
            for ($y = $a; $y <= $z; $y++) foreach ($dip[$y] as $x => $_) $Q[$x]++;
            $segs = []; $s = null; $prev = 0;
            for ($x = 0; $x < $rw; $x++) {
                if (!$Q[$x]) continue;
                if ($s !== null && $x - $prev > $gap) { $segs[] = [$s, $prev]; $s = null; }
                if ($s === null) $s = $x;
                $prev = $x;
            }
            if ($s !== null) $segs[] = [$s, $prev];
            foreach ($segs as [$s0, $s1]) {
                [$x0, $y0, $x1, $y1] = [$s0 + $rx0, $a + $ry0, $s1 + $rx0, $z + $ry0];
                $w = $x1 - $x0 + 1; $h = $y1 - $y0 + 1;
                if ($h < .012 * $H || $h > .05 * $H || $w < .15 * $W || $w > .5 * $W || $w / $h < 9 || $w / $h > 22 || $x1 < .6 * $W) continue;
                // место для закраски: полоса выше надписи (верх букв h, t, b) и фон слева
                if (self::eraseTop($y0, $y1) - 2 < 0 || $x0 - 3 < 0) continue;
                $corr = self::similarity($im, [$x0, $y0, $x1, $y1]);
                if ($corr >= $bestCorr) { $bestCorr = $corr; $best = [$x0, $y0, $x1, $y1]; }
            }
        }
        return $best;
    }

    /** Корреляция затемнения в рамке (фон по столбцам минус пиксель; $light — светлая надпись: пиксель минус фон) с образцом надписи поставщика */
    private static function similarity(\GdImage $im, array $box, bool $light = false): float
    {
        [$x0, $y0, $x1, $y1] = $box;
        $w = $x1 - $x0 + 1; $h = $y1 - $y0 + 1;
        $tpl = @imagecreatefrompng(ROOT . self::TEMPLATE);
        if (!$tpl) return 0.0;
        $tw = imagesx($tpl); $th = imagesy($tpl);
        $a = $y0 - 3; $b = $y1 + 3;
        $hasBot = $b + 2 <= imagesy($im) - 1;                              // надпись у нижнего края — фон только сверху
        $D = []; $max = 0.0;
        for ($x = $x0; $x <= $x1; $x++) {
            $top = (self::gray($im, $x, $a - 2) + self::gray($im, $x, $a - 1) + self::gray($im, $x, $a)) / 3;
            $bot = $hasBot ? (self::gray($im, $x, $b) + self::gray($im, $x, $b + 1) + self::gray($im, $x, $b + 2)) / 3 : $top;
            for ($y = $y0; $y <= $y1; $y++) {
                $k = ($y - $a) / ($b - $a);
                $bg = $top * (1 - $k) + $bot * $k;
                $v = max(0.0, $light ? self::gray($im, $x, $y) - $bg : $bg - self::gray($im, $x, $y));
                $D[$y - $y0][$x - $x0] = $v;
                if ($v > $max) $max = $v;
            }
        }
        if ($max <= 0) { imagedestroy($tpl); return 0.0; }
        // затемнение — в картинку и к размеру образца
        $src = imagecreatetruecolor($w, $h);
        foreach ($D as $yy => $row) foreach ($row as $xx => $v) { $c = (int) round($v / $max * 255); imagesetpixel($src, $xx, $yy, ($c << 16) | ($c << 8) | $c); }
        $dst = imagecreatetruecolor($tw, $th);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $tw, $th, $w, $h);
        $A = []; $B = [];
        for ($y = 0; $y < $th; $y++) for ($x = 0; $x < $tw; $x++) { $A[] = imagecolorat($dst, $x, $y) & 255; $B[] = imagecolorat($tpl, $x, $y) & 255; }
        imagedestroy($src); imagedestroy($dst); imagedestroy($tpl);
        $ma = array_sum($A) / count($A); $mb = array_sum($B) / count($B);
        $sab = $saa = $sbb = 0.0;
        foreach ($A as $i => $va) { $da = $va - $ma; $db = $B[$i] - $mb; $sab += $da * $db; $saa += $da * $da; $sbb += $db * $db; }
        return $saa > 0 && $sbb > 0 ? $sab / sqrt($saa * $sbb) : 0.0;
    }

    /** Верх закраски: над найденной строкой — ещё 60 % её высоты (верх букв найденная строка не захватывает) */
    private static function eraseTop(int $y0, int $y1): int
    {
        return $y0 - 3 - (int) round(.6 * ($y1 - $y0 + 1));
    }

    /** Закрасить надпись: по столбцам — плавный переход от фона над полосой к фону под ней */
    private static function erase(\GdImage $im, array $box): void
    {
        [$x0, $y0, $x1, $y1] = $box;
        $a = self::eraseTop($y0, $y1); $b = min($y1 + 4, imagesy($im) - 1);
        $hasBot = $b + 2 <= imagesy($im) - 1;                              // надпись у нижнего края — закраска цветом фона сверху
        $xe = min(imagesx($im) - 1, $x1 + 3);
        for ($x = $x0 - 3; $x <= $xe; $x++) {
            $top = self::avg($im, $x, $a - 2, $a); $bot = $hasBot ? self::avg($im, $x, $b, $b + 2) : $top;
            for ($y = $a; $y <= $b; $y++) {
                $k = ($y - $a) / ($b - $a);
                imagesetpixel($im, $x, $y, self::rgb($top[0] * (1 - $k) + $bot[0] * $k, $top[1] * (1 - $k) + $bot[1] * $k, $top[2] * (1 - $k) + $bot[2] * $k));
            }
        }
    }

    /** Своя надпись: высота — как у надписи поставщика (с верхом букв), прижата к её правому краю и низу */
    private static function draw(\GdImage $im, array $box): void
    {
        [, $y0, $x1, $y1] = $box;
        $mask = @imagecreatefrompng(ROOT . self::TEXT);
        if (!$mask) return;
        $th = $y1 - $y0 + 4;
        $tw = (int) round(imagesx($mask) * $th / imagesy($mask));
        $xs = $x1 - $tw + 1; $ys = $y1 - $th + 1;
        if ($xs < 0 || $ys < 0) { imagedestroy($mask); return; }
        $m = imagecreatetruecolor($tw, $th);
        imagecopyresampled($m, $mask, 0, 0, 0, 0, $tw, $th, imagesx($mask), imagesy($mask));
        for ($y = 0; $y < $th; $y++) {
            for ($x = 0; $x < $tw; $x++) {
                $k = (imagecolorat($m, $x, $y) & 255) / 255 * self::ALPHA;
                if ($k <= 0) continue;
                $c = imagecolorat($im, $xs + $x, $ys + $y);
                imagesetpixel($im, $xs + $x, $ys + $y, self::rgb((($c >> 16) & 255) * (1 - $k), (($c >> 8) & 255) * (1 - $k), ($c & 255) * (1 - $k)));
            }
        }
        imagedestroy($m); imagedestroy($mask);
    }

    private static function gray(\GdImage $im, int $x, int $y): int
    {
        $c = imagecolorat($im, $x, $y);
        return intdiv(299 * (($c >> 16) & 255) + 587 * (($c >> 8) & 255) + 114 * ($c & 255), 1000);
    }

    /** Средний цвет столбца $x в строках $ya…$yb: [r, g, b] */
    private static function avg(\GdImage $im, int $x, int $ya, int $yb): array
    {
        $s = [0, 0, 0];
        for ($y = $ya; $y <= $yb; $y++) { $c = imagecolorat($im, $x, $y); $s[0] += ($c >> 16) & 255; $s[1] += ($c >> 8) & 255; $s[2] += $c & 255; }
        $n = $yb - $ya + 1;
        return [$s[0] / $n, $s[1] / $n, $s[2] / $n];
    }

    private static function rgb(float $r, float $g, float $b): int
    {
        return (max(0, min(255, (int) round($r))) << 16) | (max(0, min(255, (int) round($g))) << 8) | max(0, min(255, (int) round($b)));
    }

    // ================================================================== фото, загруженные раньше

    /**
     * Обработать фото товаров «Хит обуви», загруженные до включения замены: от последнего обработанного id до id, который был
     * последним при первом вызове (новые фото обрабатывает сам загрузчик), не дольше $maxTime с. Заменённое — новая строка
     * product_images и новый файл (новый адрес миниатюр); старая строка удаляется сразу, старые файлы — после сброса кэша
     * страниц (до него страницы из кэша ссылаются на старые миниатюры). Один процесс за раз (flock; второй — сразу выход).
     * Состояние — storage/hitobuvi-watermark.json (после каждой порции). $dry — только посчитать (ничего не пишется).
     * @return array ['checked', 'replaced', 'left', 'done']
     */
    public static function backlog(callable $say, float $maxTime = 600.0, bool $dry = false): array
    {
        $out = ['checked' => 0, 'replaced' => 0, 'left' => 0, 'done' => false];
        if (HitObuvi::cfg('watermark') !== '1') return $out;
        $lock = @fopen(STORAGE . '/hitobuvi-watermark.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) return $out;          // идёт другой процесс (cron, --watermark)
        $db = App::db();
        $file = STORAGE . self::STATE;
        $old = [];                                                              // [pid, iid, ext] — файлы удалить после сброса кэша
        try {
            $st = json_decode((string) @file_get_contents($file), true) ?: [];
            if ((int) ($st['ver'] ?? 1) < self::VERSION) $st = ['ver' => self::VERSION, 'replaced' => (int) ($st['replaced'] ?? 0)];   // все заново
            if (!empty($st['done'])) return ['done' => true] + $out;
            $st['until'] ??= (int) $db->value('SELECT COALESCE(MAX(id), 0) FROM product_images');
            $st['cursor'] ??= 0;
            $st['replaced'] = (int) ($st['replaced'] ?? 0);
            $quality = max(30, min(100, (int) HitObuvi::cfg('photo_quality')));
            $t0 = microtime(true);
            $sql = "SELECT pi.* FROM product_images pi JOIN supplier_links sl ON sl.product_id = pi.product_id AND sl.supplier = 'hitobuvi'
                WHERE pi.id > ? AND pi.id <= ? ORDER BY pi.id LIMIT 200";
            while (microtime(true) - $t0 < $maxTime) {
                $rows = $db->all($sql, [(int) $st['cursor'], (int) $st['until']]);
                if (!$rows) { $st['done'] = true; break; }
                foreach ($rows as $r) {
                    if (microtime(true) - $t0 >= $maxTime) break;
                    $out['checked']++;
                    if (self::fixImage($r, $quality, $dry, $old)) { $out['replaced']++; $st['replaced']++; }
                    if (!$dry) $st['cursor'] = (int) $r['id'];
                }
                if ($dry) break;
                @file_put_contents($file, json_encode($st));
            }
            if (!$dry) @file_put_contents($file, json_encode($st));
            $out['done'] = !empty($st['done']);
            $out['left'] = $out['done'] ? 0 : (int) $db->value("SELECT COUNT(*) FROM product_images pi JOIN supplier_links sl ON sl.product_id = pi.product_id
                AND sl.supplier = 'hitobuvi' WHERE pi.id > ? AND pi.id <= ?", [(int) $st['cursor'], (int) $st['until']]);
        } finally {
            if ($old) {
                Cache::flush();                                                 // страницы с прежними адресами фото
                foreach ($old as [$pid, $iid, $ext]) AdminCatalog::deleteImageFiles($pid, $iid, $ext);
            }
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        if ($out['checked']) $say('фото «Хит обуви»: проверено ' . $out['checked'] . ', надпись заменена на ' . $out['replaced'] . ($out['left'] ? ', осталось ' . $out['left'] : ', готово'));
        return $out;
    }

    /**
     * Замену снова включили: фото, загруженные, пока она была выключена (id больше прежней границы), — тоже в обработку
     * (граница пересчитается при следующем вызове backlog; уже обработанные пропускаются: своя надпись с образцом не совпадает)
     */
    public static function rescan(): void
    {
        $file = STORAGE . self::STATE;
        $st = json_decode((string) @file_get_contents($file), true);
        if (!$st) return;
        unset($st['until'], $st['done']);
        @file_put_contents($file, json_encode($st));
    }

    /**
     * Одно фото: надпись найдена — новая строка и файл под новым id, главное фото товара — на него. Старая строка удаляется
     * первой (не удалилась — фото уже обработал другой процесс), её файлы — в $old (удалит backlog после сброса кэша).
     * @return bool заменено
     */
    private static function fixImage(array $r, int $quality, bool $dry, array &$old): bool
    {
        $pid = (int) $r['product_id']; $iid = (int) $r['id']; $ext = (string) ($r['ext'] ?: 'jpg');
        $src = Image::originalPath($pid, $iid, $ext);
        $im = is_file($src) ? match (strtolower($ext)) {
            'jpg', 'jpeg' => @imagecreatefromjpeg($src),
            'png' => @imagecreatefrompng($src),
            'webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($src) : false,
            default => false,
        } : false;
        if (!$im) return false;
        if (!self::apply($im)) { imagedestroy($im); return false; }
        if ($dry) { imagedestroy($im); return true; }
        $db = App::db();
        if ($db->query('DELETE FROM product_images WHERE id = ?', [$iid])->rowCount() !== 1) { imagedestroy($im); return false; }
        $row = $r; unset($row['id']);
        $row['ext'] = 'jpg';
        $new = $db->insert('product_images', $row);
        $dst = Image::originalPath($pid, $new, 'jpg');
        @mkdir(dirname($dst), 0775, true);
        $ok = imagejpeg($im, $dst . '.part', $quality) && @rename($dst . '.part', $dst);
        imagedestroy($im);
        if (!$ok) {                                                             // не записалось — вернуть как было
            @unlink($dst . '.part');
            $db->query('DELETE FROM product_images WHERE id = ?', [$new]);
            $db->insert('product_images', $r);
            return false;
        }
        $db->query('UPDATE products SET image_id = ?, image_ext = ? WHERE id = ? AND image_id = ?', [$new, 'jpg', $pid, $iid]);
        $old[] = [$pid, $iid, $ext];
        return true;
    }
}
