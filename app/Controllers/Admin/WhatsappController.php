<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\App;
use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Core\Settings;
use App\Services\Orders;
use App\Services\WhatsApp;

/** «Настройки → WhatsApp»: куда и через какой сервис отправлять новые заказы. Менять может только администратор. */
final class WhatsappController extends BaseController
{
    private const KEYS = ['enabled', 'to', 'provider', 'green_url', 'green_instance', 'green_token', 'cloud_phone_id', 'cloud_token',
        'cloud_template', 'cloud_lang', 'notify_orders', 'notify_quickorder', 'notify_requests'];
    private const SECRET = ['green_token', 'cloud_token'];

    public function index(): Response
    {
        $errors = [];
        if (Request::isPost()) {
            if (!Auth::isAdmin()) {
                $this->flash('Менять настройки WhatsApp может только администратор.', true);
                return Response::redirect('/admin/whatsapp/');
            }
            $in = [];
            foreach (self::KEYS as $k) $in[$k] = trim((string) ($_POST[$k] ?? ''));
            foreach (['enabled', 'notify_orders', 'notify_quickorder', 'notify_requests'] as $k) $in[$k] = isset($_POST[$k]) ? '1' : '0';
            if (!isset(WhatsApp::PROVIDERS[$in['provider']])) $in['provider'] = '';
            $nums = WhatsApp::recipients($in['to']);
            if ($in['to'] !== '' && !$nums) $errors['to'] = 'Номер не распознан. Пример: +38 093 275 30 70';
            $in['to'] = implode(', ', array_map(static fn($n) => '+' . $n, $nums));
            if ($in['green_url'] !== '' && !preg_match('#^https://[a-z0-9.\-]+(:\d+)?/?$#i', $in['green_url'])) $errors['green_url'] = 'Адрес вида https://1103.api.green-api.com';
            if (!preg_match('/^[a-z]{2}(_[A-Z]{2})?$/', $in['cloud_lang'] ?: 'ru')) $errors['cloud_lang'] = 'Код языка шаблона, например ru, uk';
            if ($in['enabled'] === '1' && !$nums) $errors['to'] = 'Укажите хотя бы один номер';
            if ($in['enabled'] === '1' && $in['provider'] === '') $errors['provider'] = 'Выберите сервис отправки';
            if (!$errors) {
                foreach ($in as $k => $v) {
                    if (in_array($k, self::SECRET, true) && $v === '') continue;   // пустое поле токена = не менять
                    Settings::set('whatsapp.' . $k, $v);
                }
                $this->log('whatsapp_settings', 'settings');
                $this->flash('Настройки WhatsApp сохранены.' . (WhatsApp::enabled() ? ' Новые заказы будут приходить в WhatsApp.' : ''));
                return Response::redirect('/admin/whatsapp/');
            }
            $this->flash('Проверьте поля формы.', true);
        }
        $db = App::db();
        $lastOrderId = (int) $db->value('SELECT id FROM orders ORDER BY id DESC LIMIT 1');
        $preview = $lastOrderId ? WhatsApp::orderText(Orders::find($lastOrderId)) : '';
        return $this->render('admin/whatsapp/index', [
            'title'   => 'WhatsApp',
            'cfg'     => array_combine(self::KEYS, array_map(static fn($k) => WhatsApp::cfg($k), self::KEYS)),
            'post'    => Request::isPost() ? $_POST : null,
            'errors'  => $errors,
            'enabled' => WhatsApp::enabled(),
            'canEdit' => Auth::isAdmin(),
            'preview' => $preview,
            'log'     => $db->all("SELECT * FROM notify_log WHERE channel = 'whatsapp' ORDER BY id DESC LIMIT 30"),
            'stats'   => $db->row("SELECT SUM(ok = 1) sent, SUM(ok = 0) failed FROM notify_log WHERE channel = 'whatsapp' AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)"),
        ]);
    }

    /** Тестовое сообщение на указанные номера (с текущими сохранёнными настройками) */
    public function test(): Response
    {
        if (!Auth::isAdmin()) return Response::json(['ok' => false, 'error' => 'Только для администратора'], 403);
        $lastOrderId = (int) App::db()->value('SELECT id FROM orders ORDER BY id DESC LIMIT 1');
        $text = "✅ Тестовое сообщение с сайта Tomobuv\nТак будут приходить новые заказы:\n\n"
            . ($lastOrderId ? WhatsApp::orderText(Orders::find($lastOrderId)) : '(заказов пока нет)');
        $r = WhatsApp::send($text, WhatsApp::recipients(), 'test');
        $this->log('whatsapp_test', 'settings', null, $r);
        return Response::json($r['ok']
            ? ['ok' => true, 'message' => 'Отправлено: ' . $r['sent'] . '. Проверьте WhatsApp.']
            : ['ok' => false, 'error' => implode('; ', $r['errors']) ?: 'Не отправлено']);
    }
}
