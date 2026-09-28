<?php
declare(strict_types=1);

namespace App\Controllers\Front;

use App\Core\App;
use App\Core\Image;
use App\Core\Response;

/**
 * Создание миниатюры фото товара «на лету» (адрес как в Webasyst).
 * Вызывается только если файла ещё нет — дальше его отдаёт веб-сервер напрямую.
 * /wa-data/public/shop/products/20/19/1451920/images/1611693/1611693.400.jpg
 */
final class ImageController
{
    public function thumb(string $a, string $b, string $pid, string $iid, string $file): Response
    {
        if (!ctype_digit($pid) || !ctype_digit($iid)
            || !preg_match('/^([A-Za-z0-9_\-]+)\.((?:\d+x\d+)|\d+|(?:\d+x0)|(?:0x\d+))\.(jpe?g|png|gif|webp)$/i', $file, $m)
            || !in_array($m[2], Image::SIZES, true)) {
            return Response::notFound();
        }
        $pid = (int) $pid; $iid = (int) $iid;
        if (Image::productDir($pid) !== $a . '/' . $b . '/' . $pid) return Response::notFound();
        $img = App::db()->row('SELECT id, ext, filename FROM product_images WHERE id = ? AND product_id = ?', [$iid, $pid]);
        if (!$img) return Response::notFound();
        // имя файла — только id фото или его SEO-имя из базы: иначе любые имена (a.400.jpg, b.400.jpg…) плодили бы файлы на диске
        if ($m[1] !== (string) $iid && $m[1] !== (string) $img['filename']) return Response::notFound();
        $src = Image::originalPath($pid, $iid, (string) $img['ext']);
        $dst = Image::thumbPath($pid, $iid, $m[1], $m[2], strtolower($m[3]));
        if (!is_file($dst) && !Image::resize($src, $dst, $m[2], (int) App::config('images.jpeg_quality', 85))) {
            // Нет оригинала (например, на локальной копии без wa-data) — берём с живого сайта, если настроено
            $remote = (string) App::config('images.remote_base', '');
            return $remote !== '' ? Response::redirect($remote . \App\Core\Request::path(), 302) : Response::notFound();
        }
        $mime = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp'][strtolower($m[3])];
        $r = new Response();
        $r->body = (string) file_get_contents($dst);
        $r->headers['Content-Type'] = $mime;
        $r->headers['Cache-Control'] = 'public, max-age=31536000, immutable';
        return $r;
    }
}
