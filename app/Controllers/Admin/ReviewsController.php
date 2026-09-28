<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\App;
use App\Core\Cache;
use App\Core\Image;
use App\Core\Paginator;
use App\Core\Request;
use App\Core\Response;
use App\Services\CatalogIndexer;

/**
 * Отзывы: о товарах (product_reviews: moderation | approved | hidden) и о магазине (store_reviews: status 0/1).
 * Действия: approve, hide, delete, reply. После изменений — Cache::flush() (отзывы видны на кэшируемых страницах).
 */
final class ReviewsController extends BaseController
{
    public const PRODUCT_STATUSES = ['moderation' => 'На модерации', 'approved' => 'Опубликованы', 'hidden' => 'Скрытые'];
    public const STORE_STATUSES = ['0' => 'Не опубликованы', '1' => 'Опубликованы'];
    private const PER_PAGE = 50;

    public function index(): Response
    {
        $type = Request::get('type') === 'store' ? 'store' : 'product';
        $status = Request::get('status');
        $db = App::db();

        $pc = array_map('intval', $db->pairs('SELECT status, COUNT(*) FROM product_reviews GROUP BY status'));
        $sc = array_map('intval', $db->pairs('SELECT status, COUNT(*) FROM store_reviews GROUP BY status'));

        $products = [];
        if ($type === 'product') {
            if (!isset(self::PRODUCT_STATUSES[$status]) && $status !== 'all') $status = !empty($pc['moderation']) ? 'moderation' : 'all';
            $where = $status === 'all' ? " WHERE status <> 'deleted'" : ' WHERE status = ?';
            $params = $status === 'all' ? [] : [$status];
            $total = (int) $db->value('SELECT COUNT(*) FROM product_reviews' . $where, $params);
            $pg = new Paginator($total, self::PER_PAGE, Request::page());
            $rows = $total ? $db->all('SELECT * FROM product_reviews' . $where . ' ORDER BY created_at DESC, id DESC LIMIT '
                . self::PER_PAGE . ' OFFSET ' . $pg->offset, $params) : [];
            $pids = array_values(array_unique(array_map(static fn($r) => (int) $r['product_id'], $rows)));
            if ($pids) {
                [$ph, $vals] = $db->in($pids);
                foreach ($db->all('SELECT id, url, name, sku, image_id, image_ext, status, rating, rating_count FROM products WHERE id IN (' . $ph . ')', $vals) as $p) {
                    $p['img'] = Image::product($p, '96x96');
                    $products[(int) $p['id']] = $p;
                }
            }
        } else {
            if (!isset(self::STORE_STATUSES[$status]) && $status !== 'all') $status = !empty($sc[0]) ? '0' : 'all';
            $where = $status === 'all' ? '' : ' WHERE status = ?';
            $params = $status === 'all' ? [] : [(int) $status];
            $total = (int) $db->value('SELECT COUNT(*) FROM store_reviews' . $where, $params);
            $pg = new Paginator($total, self::PER_PAGE, Request::page());
            $rows = $total ? $db->all('SELECT * FROM store_reviews' . $where . ' ORDER BY created_at DESC, id DESC LIMIT '
                . self::PER_PAGE . ' OFFSET ' . $pg->offset, $params) : [];
        }

        return $this->render('admin/reviews/index', [
            'title' => 'Отзывы', 'type' => $type, 'status' => (string) $status, 'rows' => $rows, 'pg' => $pg, 'total' => $total,
            'pc' => $pc, 'sc' => $sc, 'products' => $products, 'styles' => ['admin/sales.css'],
        ]);
    }

    /** POST /admin/reviews/{type}/{id}/ — action: approve | hide | delete | reply (+ response) */
    public function action(string $type, string $id): Response
    {
        if (!in_array($type, ['product', 'store'], true) || !ctype_digit($id)) return $this->answer(false, 'Отзыв не найден', 404);
        $db = App::db();
        $table = $type === 'product' ? 'product_reviews' : 'store_reviews';
        $r = $db->row('SELECT * FROM ' . $table . ' WHERE id = ?', [(int) $id]);
        if (!$r) return $this->answer(false, 'Отзыв не найден', 404);
        $rid = (int) $r['id'];
        $action = Request::post('action');

        switch ($action) {
            case 'approve':
                $db->update($table, ['status' => $type === 'product' ? 'approved' : 1], 'id = ?', [$rid]);
                $msg = 'Отзыв опубликован.';
                break;
            case 'hide':
                $db->update($table, ['status' => $type === 'product' ? 'hidden' : 0], 'id = ?', [$rid]);
                $msg = 'Отзыв скрыт с сайта.';
                break;
            case 'delete':
                $db->delete($table, 'id = ?', [$rid]);
                $msg = 'Отзыв удалён.';
                break;
            case 'reply':
                $text = trim(mb_substr((string) ($_POST['response'] ?? ''), 0, 5000));
                $data = ['response' => $text !== '' ? $text : null];
                if ($type === 'product') $data['response_at'] = $text !== '' ? date('Y-m-d H:i:s') : null;
                $db->update($table, $data, 'id = ?', [$rid]);
                $msg = $text !== '' ? 'Ответ сохранён.' : 'Ответ удалён.';
                break;
            default:
                return $this->answer(false, 'Неизвестное действие', 422);
        }
        if ($type === 'product') self::recalcRating((int) $r['product_id']);
        Cache::flush();
        $this->log('review_' . $action, $table, $rid);
        return $this->answer(true, $msg);
    }

    /**
     * Рейтинг товара по опубликованным отзывам. Есть динамические категории с условием по рейтингу (rating>=4) —
     * товар переиндексируется точечно (~20 мс), иначе попал бы в них (или выпал) только при полной перестройке.
     * Без таких категорий рейтинг на индекс не влияет (в catalog_index его нет) — лишней работы нет.
     */
    private static function recalcRating(int $productId): void
    {
        if ($productId <= 0) return;
        $index = CatalogIndexer::conditionUses('rating');
        $snap = $index ? CatalogIndexer::snapshot([$productId]) : null;   // характеристики и бренд рейтинг не меняет
        $changed = App::db()->query("UPDATE products SET
            rating = (SELECT COALESCE(ROUND(AVG(rate), 2), 0) FROM product_reviews WHERE product_id = ? AND status = 'approved' AND rate > 0),
            rating_count = (SELECT COUNT(*) FROM product_reviews WHERE product_id = ? AND status = 'approved')
            WHERE id = ?", [$productId, $productId, $productId])->rowCount();
        if ($index && $changed) CatalogIndexer::products([$productId], $snap, false);   // кэш сбросит action()
    }

    private function answer(bool $ok, string $msg, int $code = 200): Response
    {
        if (Request::isAjax()) return Response::json($ok ? ['ok' => true, 'message' => $msg] : ['ok' => false, 'error' => $msg], $code);
        $this->flash($msg, !$ok);
        return $this->back('/admin/reviews/');
    }
}
