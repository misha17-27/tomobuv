<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\App;
use App\Core\Cache;
use App\Core\Response;

final class DashboardController extends BaseController
{
    public function index(): Response
    {
        $db = App::db();
        $today = date('Y-m-d');
        $stats = [
            'orders_today'  => (int) $db->value('SELECT COUNT(*) FROM orders WHERE created_at >= ?', [$today]),
            'orders_new'    => (int) $db->value("SELECT COUNT(*) FROM orders WHERE status = 'new'"),
            'sum_month'     => (float) $db->value("SELECT COALESCE(SUM(total),0) FROM orders WHERE created_at >= ? AND status NOT IN ('deleted','refunded')", [date('Y-m-01')]),
            'products'      => (int) $db->value('SELECT COUNT(*) FROM products WHERE status = 1'),
            'hidden'        => (int) $db->value('SELECT COUNT(*) FROM products WHERE status = 0'),
            'out_of_stock'  => (int) $db->value('SELECT COUNT(*) FROM products WHERE status = 1 AND in_stock = 0'),
            'customers'     => (int) $db->value("SELECT COUNT(*) FROM customers WHERE role = 'customer'"),
            'requests_new'  => (int) $db->value("SELECT COUNT(*) FROM requests WHERE status = 'new'"),
        ];
        $lastOrders = $db->all('SELECT id, name, phone, total, status, boxes, created_at FROM orders ORDER BY id DESC LIMIT 10');
        $raw = $db->pairs("SELECT DATE(created_at) d, COUNT(*) FROM orders WHERE created_at >= ? AND status <> 'deleted' GROUP BY d", [date('Y-m-d', strtotime('-29 days'))]);
        $byDay = [];
        for ($i = 29; $i >= 0; $i--) {                       // все 30 дней, включая дни без заказов
            $d = date('Y-m-d', strtotime("-$i days"));
            $byDay[$d] = (int) ($raw[$d] ?? 0);
        }
        if (!array_sum($byDay)) $byDay = [];
        return $this->render('admin/dashboard', ['title' => 'Главная', 'stats' => $stats, 'lastOrders' => $lastOrders, 'byDay' => $byDay]);
    }

    public function clearCache(): Response
    {
        Cache::flush();
        $this->log('cache_clear');
        $this->flash('Кэш сайта очищен — изменения видны посетителям.');
        return $this->back();
    }

    public function notFound(string $rest = ''): Response
    {
        $r = $this->render('admin/forbidden', ['title' => 'Раздел не найден', 'message' => 'Такого раздела нет.'])->header('X-Robots-Tag', 'noindex');
        $r->status = 404;
        return $r;
    }
}
