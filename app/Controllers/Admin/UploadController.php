<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Response;
use App\Services\Media;

/**
 * Загрузка картинок из админки (HTML-редактор, баннеры, обложки статей) — файл попадает в медиатеку.
 * Вся проверка и сохранение — в App\Services\Media::store(): только JPG/PNG/WEBP/GIF (тип по содержимому,
 * getimagesize), без «двойных» расширений, пересохранение через GD (всё постороннее внутри файла пропадает),
 * безопасное имя (транслит), папка public/uploads/ГГГГ/ММ/.
 *
 *   POST /admin/upload/ (поле file) → {"ok":true,"url":"/uploads/2026/09/foto.jpg","width":…,"height":…} | {"ok":false,"error":"…"}
 *   В контроллерах: UploadController::save($_FILES['image'] ?? null) → ['ok'=>…, 'url'|'error'=>…]
 */
final class UploadController extends BaseController
{
    public function store(): Response
    {
        // Файл больше post_max_size: PHP отбрасывает всё тело запроса (BaseController отвечает 413 раньше, это запасной вариант)
        if (!$_FILES && !$_POST && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
            return Response::json(['ok' => false, 'error' => 'Файл слишком большой (максимум ' . self::limitText() . ')'], 413);
        }
        $r = self::save($_FILES['file'] ?? ($_FILES['image'] ?? null));
        if ($r['ok']) $this->log('upload', 'file', null, $r['url']);
        return Response::json($r, $r['ok'] ? 200 : 422);
    }

    /** Сохранить загруженный файл-картинку в медиатеку. $file — элемент $_FILES (или null). */
    public static function save(?array $file): array
    {
        $r = Media::store($file);
        if (!$r['ok']) {
            // «"foto.pdf": можно загружать только…» → с заглавной буквы для сообщения под полем
            $r['error'] = mb_strtoupper(mb_substr((string) $r['error'], 0, 1)) . mb_substr((string) $r['error'], 1);
        }
        return $r;
    }

    public static function limitText(): string
    {
        return Media::limitText();
    }
}
