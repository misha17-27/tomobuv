<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\App;
use App\Core\Cache;
use App\Core\Image;
use App\Core\Paginator;
use App\Core\Request;
use App\Core\Response;

/** Заявки с сайта: обратный звонок, «купить в 1 клик», подписка, форма контактов. */
final class RequestsController extends BaseController
{
    public const TYPES = ['callback' => 'Обратный звонок', 'quickorder' => 'Купить в 1 клик', 'subscribe' => 'Подписка', 'contact' => 'Контакты'];
    private const STATUSES = ['new' => 'Новые', 'done' => 'Обработанные'];
    private const PER_PAGE = 50;

    public function index(): Response
    {
        $db = App::db();
        $type = Request::get('type');
        if (!isset(self::TYPES[$type])) $type = '';
        $status = Request::get('status');
        if (!isset(self::STATUSES[$status])) $status = '';
        $q = mb_substr(Request::get('q'), 0, 100);

        // счётчики: [type][status] => n (индекс type)
        $counts = [];
        foreach ($db->all('SELECT type, status, COUNT(*) n FROM requests GROUP BY type, status') as $r) $counts[$r['type']][$r['status']] = (int) $r['n'];
        $newBy = static fn(string $t) => $t === '' ? array_sum(array_map(static fn($x) => $x['new'] ?? 0, $counts)) : ($counts[$t]['new'] ?? 0);

        $w = []; $p = [];
        if ($type !== '') { $w[] = 'type = ?'; $p[] = $type; }
        if ($status !== '') { $w[] = 'status = ?'; $p[] = $status; }
        $q = trim($q);
        if ($q !== '') {
            $digits = (string) preg_replace('/\D+/', '', $q);
            if (strlen($digits) >= 5 && preg_match('/^[\d\s()+\-.]+$/', $q)) { $w[] = 'phone LIKE ?'; $p[] = '%' . $digits . '%'; }
            else { $like = '%' . addcslashes($q, '%_\\') . '%'; $w[] = '(name LIKE ? OR email LIKE ? OR text LIKE ?)'; array_push($p, $like, $like, $like); }
        }
        $where = $w ? ' WHERE ' . implode(' AND ', $w) : '';
        $total = (int) $db->value('SELECT COUNT(*) FROM requests' . $where, $p);
        $pg = new Paginator($total, self::PER_PAGE, Request::page());
        $rows = $total ? $db->all('SELECT * FROM requests' . $where . ' ORDER BY created_at DESC, id DESC LIMIT ' . self::PER_PAGE . ' OFFSET ' . $pg->offset, $p) : [];

        // товары и клиенты — пакетно
        $pids = array_values(array_unique(array_filter(array_map(static fn($r) => (int) $r['product_id'], $rows))));
        $products = [];
        if ($pids) {
            [$ph, $vals] = $db->in($pids);
            foreach ($db->all('SELECT id, url, name, sku, price, box_qty, image_id, image_ext, status FROM products WHERE id IN (' . $ph . ')', $vals) as $pr) {
                $pr['img'] = Image::product($pr, '96x96');
                $products[(int) $pr['id']] = $pr;
            }
        }
        $phones = array_values(array_unique(array_filter(array_map(static fn($r) => (string) $r['phone'], $rows))));
        $customers = [];
        if ($phones) {
            [$ph, $vals] = $db->in($phones);
            foreach ($db->all('SELECT id, phone, name, orders_count FROM customers WHERE phone IN (' . $ph . ') ORDER BY id', $vals) as $c) $customers[$c['phone']] ??= $c;
        }

        return $this->render('admin/requests/index', [
            'title' => 'Заявки', 'rows' => $rows, 'pg' => $pg, 'total' => $total, 'type' => $type, 'status' => $status, 'q' => $q,
            'counts' => $counts, 'newBy' => $newBy, 'products' => $products, 'customers' => $customers, 'statuses' => self::STATUSES,
            'styles' => ['admin/sales.css'], 'scripts' => ['admin/sales.js'],
        ]);
    }

    /** POST /admin/requests/{id}/status/ — status: new | done | delete */
    public function status(string $id): Response
    {
        $db = App::db();
        $r = ctype_digit($id) ? $db->row('SELECT id, status FROM requests WHERE id = ?', [(int) $id]) : null;
        if (!$r) return $this->answer(false, 'Заявка не найдена', 404);
        $st = Request::post('status');
        if ($st === 'delete') {
            $db->delete('requests', 'id = ?', [(int) $r['id']]);
            $msg = 'Заявка удалена.';
        } elseif (isset(self::STATUSES[$st])) {
            $db->update('requests', ['status' => $st], 'id = ?', [(int) $r['id']]);
            $msg = $st === 'done' ? 'Заявка отмечена как обработанная.' : 'Заявка снова новая.';
        } else {
            return $this->answer(false, 'Неизвестный статус', 422);
        }
        Cache::forget('admin.tally');   // счётчик новых заявок в меню
        $this->log('request_' . $st, 'request', (int) $r['id']);
        return $this->answer(true, $msg);
    }

    private function answer(bool $ok, string $msg, int $code = 200): Response
    {
        if (Request::isAjax()) return Response::json($ok ? ['ok' => true, 'message' => $msg] : ['ok' => false, 'error' => $msg], $code);
        $this->flash($msg, !$ok);
        return $this->back('/admin/requests/');
    }
}
