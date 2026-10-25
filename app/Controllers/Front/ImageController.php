<?php
declare(strict_types=1);

namespace App\Controllers\Front;

use App\Core\App;
use App\Core\Image;
use App\Core\Response;

/**
 * Миниатюра фото товара «на лету» (адрес как в Webasyst):
 * /wa-data/public/shop/products/20/19/1451920/images/1611693/1611693.400.jpg
 * На диск не пишется (с 25.10.2026: хостинг ограничивает число файлов — 300 тыс., миниатюры давали ~7 файлов и папок на товар):
 * ответ кэшируют Cloudflare и браузеры на год (Cache-Control), сервер делает её из оригинала только при промахе кэша.
 * images.thumbs_on_disk = true в config/config.php — как раньше: файл сохраняется и дальше его отдаёт веб-сервер.
 * Миниатюры, сохранённые раньше (и перенесённые со старого сайта без оригинала), веб-сервер отдаёт как есть;
 * ThumbCleanup (bin/cron.php) удаляет те из них, у которых есть оригинал.
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
        $q = (int) App::config('images.jpeg_quality', 85);
        if (is_file($dst)) {
            $body = (string) file_get_contents($dst);                       // сохранённая раньше
        } elseif (App::config('images.thumbs_on_disk', false)) {
            $body = Image::resize($src, $dst, $m[2], $q) ? (string) file_get_contents($dst) : null;
        } else {
            $body = Image::render($src, $m[2], strtolower($m[3]), $q);
        }
        if ($body === null || $body === '') {
            // Нет оригинала (например, на локальной копии без wa-data) — берём с живого сайта, если настроено
            $remote = (string) App::config('images.remote_base', '');
            return $remote !== '' ? Response::redirect($remote . \App\Core\Request::path(), 302) : Response::notFound();
        }
        $mime = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp'][strtolower($m[3])];
        $r = new Response();
        $r->body = $body;
        $r->headers['Content-Type'] = $mime;
        $r->headers['Cache-Control'] = 'public, max-age=31536000, immutable';
        return $r;
    }
}
