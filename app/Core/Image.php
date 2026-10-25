<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Фото товаров — пути полностью совместимы со старым сайтом (Webasyst), чтобы сохранить
 * позиции в Google/Яндекс Картинках и не перекладывать 100 000+ файлов:
 *
 *   оригинал:  public/wa-data/protected/shop/products/{id%100}/{id/100%100}/{id}/images/{image_id}.{ext}
 *   миниатюра: public/wa-data/public/shop/products/{…}/{id}/images/{image_id}/{image_id}.{size}.{ext}
 *
 * Миниатюры создаются при первом обращении (как в Webasyst): если файла нет, запрос попадает
 * в ImageController::thumb, который уменьшает оригинал через GD и сохраняет результат —
 * дальше файл отдаёт веб-сервер напрямую, без PHP.
 */
final class Image
{
    /** Разрешённые размеры (защита от генерации тысяч вариантов злоумышленником) */
    public const SIZES = ['48x48', '96x96', '100x100', '200', '200x0', '300', '400', '400x0', '500', '750x0', '970', '1200'];

    public static function productDir(int $productId): string
    {
        return sprintf('%02d/%02d/%d', $productId % 100, intdiv($productId, 100) % 100, $productId);
    }

    /**
     * URL миниатюры товара. $as — формат миниатюры (webp — для <picture> на витрине, в ~1,5–2 раза меньше jpg), если он
     * отличается от оригинала: сервер делает её из оригинала (ImageController); фото без оригинала (перенесённые со старого
     * сайта миниатюрами) — только в своём формате.
     */
    public static function url(int $productId, ?int $imageId, ?string $ext, string $size = '400', string $filename = '', ?string $as = null): string
    {
        if (!$imageId) return '/assets/img/no-photo.svg';
        $ext = $ext ?: 'jpg';
        $name = $filename !== '' ? $filename : (string) $imageId;
        $base = '/wa-data/public/shop/products/' . self::productDir($productId) . '/images/' . $imageId . '/' . $name . '.' . $size . '.';
        $path = $base . $ext;
        if (is_file(self::originalPath($productId, (int) $imageId, $ext))) return $base . ($as ?? $ext);
        $remote = (string) App::config('images.remote_base', '');
        if ($remote !== '') return rtrim($remote, '/') . $base . ($as ?? $ext);   // разработка: фото с живого сайта
        // ни оригинала, ни готовой миниатюры (фото не перенесено) — заглушка вместо битой картинки (404)
        return is_file(self::thumbPath($productId, (int) $imageId, $name, $size, $ext)) ? $path : '/assets/img/no-photo.svg';
    }

    /** Фото для карточки/строки товара ($as — формат миниатюры, см. url) */
    public static function product(array $p, string $size = '400', ?string $as = null): string
    {
        return self::url((int) $p['id'], isset($p['image_id']) ? (int) $p['image_id'] : null, $p['image_ext'] ?? 'jpg', $size, '', $as);
    }

    public static function originalPath(int $productId, int $imageId, string $ext): string
    {
        return PUBLIC_DIR . '/' . App::config('images.wa_data', 'wa-data') . '/protected/shop/products/'
            . self::productDir($productId) . '/images/' . $imageId . '.' . $ext;
    }

    public static function thumbPath(int $productId, int $imageId, string $name, string $size, string $ext): string
    {
        return PUBLIC_DIR . '/' . App::config('images.wa_data', 'wa-data') . '/public/shop/products/'
            . self::productDir($productId) . '/images/' . $imageId . '/' . $name . '.' . $size . '.' . $ext;
    }

    /**
     * Уменьшить картинку. $size: «400» — по большей стороне, «96x96» — квадрат с обрезкой,
     * «750x0» — по ширине, «0x300» — по высоте. Не увеличивает маленькие картинки.
     */
    public static function resize(string $src, string $dst, string $size, int $quality = 85): bool
    {
        $out = self::scaled($src, $size);
        if (!$out) return false;
        @mkdir(dirname($dst), 0775, true);
        $ok = self::encode($out, strtolower(pathinfo($dst, PATHINFO_EXTENSION)), $quality, $dst);
        imagedestroy($out);
        return $ok;
    }

    /** Уменьшенная картинка в памяти, без файла на диске (миниатюры — ImageController): байты или null */
    public static function render(string $src, string $size, string $ext, int $quality = 85): ?string
    {
        $out = self::scaled($src, $size);
        if (!$out) return null;
        ob_start();
        $ok = self::encode($out, strtolower($ext), $quality, null);
        $data = (string) ob_get_clean();
        imagedestroy($out);
        return $ok && $data !== '' ? $data : null;
    }

    /** Картинка $src, уменьшенная до $size (правила — resize) */
    private static function scaled(string $src, string $size): ?\GdImage
    {
        if (!is_file($src) || !function_exists('imagecreatetruecolor')) return null;
        $info = @getimagesize($src);
        if (!$info) return null;
        [$w, $h] = $info;
        $mime = $info['mime'];
        $img = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($src),
            'image/png'  => @imagecreatefrompng($src),
            'image/gif'  => @imagecreatefromgif($src),
            'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($src) : false,
            default      => false,
        };
        if (!$img) return null;

        $cx = 0; $cy = 0; $cw = $w; $ch = $h;
        if (preg_match('/^(\d+)x(\d+)$/', $size, $m) && $m[1] !== '0' && $m[2] !== '0') {
            $tw = (int) $m[1]; $th = (int) $m[2];                       // обрезка по центру
            $scale = max($tw / $w, $th / $h);
            $cw = (int) round($tw / $scale); $ch = (int) round($th / $scale);
            $cx = (int) (($w - $cw) / 2); $cy = (int) (($h - $ch) / 2);
            if ($scale > 1) { $tw = $cw; $th = $ch; }
        } elseif (preg_match('/^(\d+)x0$/', $size, $m)) {
            $tw = min((int) $m[1], $w); $th = (int) round($h * $tw / $w);
        } elseif (preg_match('/^0x(\d+)$/', $size, $m)) {
            $th = min((int) $m[1], $h); $tw = (int) round($w * $th / $h);
        } elseif (ctype_digit($size)) {
            $max = (int) $size; $scale = min(1, $max / max($w, $h));
            $tw = (int) round($w * $scale); $th = (int) round($h * $scale);
        } else {
            return null;
        }

        $out = imagecreatetruecolor(max(1, $tw), max(1, $th));
        if ($mime === 'image/png' || $mime === 'image/gif' || $mime === 'image/webp') {
            imagealphablending($out, false);
            imagesavealpha($out, true);
        }
        imagecopyresampled($out, $img, 0, 0, $cx, $cy, $tw, $th, $cw, $ch);
        imagedestroy($img);
        return $out;
    }

    /** Сохранить в файл $to (null — в вывод) в формате по расширению */
    private static function encode(\GdImage $out, string $ext, int $quality, ?string $to): bool
    {
        return (bool) match ($ext) {
            'png'  => imagepng($out, $to, 6),
            'gif'  => imagegif($out, $to),
            'webp' => function_exists('imagewebp') ? imagewebp($out, $to, $quality) : false,
            default => imagejpeg($out, $to, $quality),
        };
    }
}
