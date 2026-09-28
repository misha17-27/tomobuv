<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\App;
use App\Core\Log;
use App\Core\Settings;

/**
 * Автоматическая отправка новых заказов (и, по желанию, заявок) в WhatsApp на номера из админки
 * (раздел «Настройки → WhatsApp», /admin/whatsapp/).
 *
 * Поддерживаются два шлюза:
 *  - Green-API (green-api.com) — подключение вашего WhatsApp по QR-коду, отправка от вашего же номера;
 *    настройки: whatsapp.green_url (apiUrl инстанса, по умолчанию https://api.green-api.com),
 *    whatsapp.green_instance (idInstance), whatsapp.green_token (apiTokenInstance);
 *  - WhatsApp Cloud API (Meta) — официальный API: whatsapp.cloud_phone_id, whatsapp.cloud_token,
 *    whatsapp.cloud_template (+ whatsapp.cloud_lang) — шаблон нужен, если получатель не писал бизнес-номеру
 *    последние 24 часа (правило Meta); без шаблона уходит обычный текст.
 *
 * Отправка — после ответа браузеру (покупатель не ждёт), ошибки не ломают заказ: пишутся в notify_log
 * и в историю заказа.
 */
final class WhatsApp
{
    public const PROVIDERS = ['green' => 'Green-API', 'cloud' => 'WhatsApp Cloud API (Meta)'];

    public static function cfg(string $key, string $default = ''): string
    {
        return trim((string) Settings::get('whatsapp.' . $key, $default));
    }

    public static function enabled(): bool
    {
        return self::cfg('enabled') === '1' && self::configured() && self::recipients();
    }

    public static function configured(): bool
    {
        return match (self::cfg('provider')) {
            'green' => self::cfg('green_instance') !== '' && self::cfg('green_token') !== '',
            'cloud' => self::cfg('cloud_phone_id') !== '' && self::cfg('cloud_token') !== '',
            default => false,
        };
    }

    /** Номера получателей: «+38 (093) 275-30-70, 0507616901» → ['380932753070', '380507616901'] */
    public static function recipients(?string $raw = null): array
    {
        $out = [];
        foreach (preg_split('/[\s,;]+/', $raw ?? self::cfg('to')) ?: [] as $p) {
            $d = preg_replace('/\D+/', '', (string) $p);
            if (strlen($d) === 10 && $d[0] === '0') $d = '38' . $d;
            if (strlen($d) >= 11 && strlen($d) <= 15) $out[$d] = $d;
        }
        return array_values($out);
    }

    /**
     * Отправить текст всем получателям (или указанным).
     * @return array{ok: bool, sent: int, errors: string[]}
     */
    public static function send(string $text, ?array $to = null, string $refType = '', ?int $refId = null): array
    {
        $to ??= self::recipients();
        $res = ['ok' => false, 'sent' => 0, 'errors' => []];
        if (!self::configured()) { $res['errors'][] = 'Шлюз WhatsApp не настроен'; return $res; }
        if (!$to) { $res['errors'][] = 'Не указан номер получателя'; return $res; }
        foreach ($to as $num) {
            $err = self::sendOne($num, $text);
            self::log($num, $err === null, $err, $refType, $refId);
            if ($err === null) $res['sent']++; else $res['errors'][] = $num . ': ' . $err;
        }
        $res['ok'] = $res['sent'] > 0;
        return $res;
    }

    /** null — отправлено, строка — ошибка */
    private static function sendOne(string $num, string $text): ?string
    {
        try {
            if (self::cfg('provider') === 'green') {
                $base = rtrim(self::cfg('green_url', 'https://api.green-api.com'), '/');
                $url = $base . '/waInstance' . rawurlencode(self::cfg('green_instance')) . '/sendMessage/' . rawurlencode(self::cfg('green_token'));
                [$code, $body] = self::http($url, ['chatId' => $num . '@c.us', 'message' => $text]);
                $j = json_decode($body, true);
                if ($code === 200 && !empty($j['idMessage'])) return null;
                return 'Green-API ответил ' . $code . ': ' . mb_substr(trim($body), 0, 200);
            }
            if (self::cfg('provider') === 'cloud') {
                $url = 'https://graph.facebook.com/v21.0/' . rawurlencode(self::cfg('cloud_phone_id')) . '/messages';
                $tpl = self::cfg('cloud_template');
                $payload = $tpl !== ''
                    ? ['messaging_product' => 'whatsapp', 'to' => $num, 'type' => 'template', 'template' => [
                        'name' => $tpl, 'language' => ['code' => self::cfg('cloud_lang', 'ru')],
                        'components' => [['type' => 'body', 'parameters' => [['type' => 'text', 'text' => self::oneLine($text, 1000)]]]]]]
                    : ['messaging_product' => 'whatsapp', 'to' => $num, 'type' => 'text', 'text' => ['preview_url' => false, 'body' => mb_substr($text, 0, 4000)]];
                [$code, $body] = self::http($url, $payload, ['Authorization: Bearer ' . self::cfg('cloud_token')]);
                $j = json_decode($body, true);
                if ($code >= 200 && $code < 300 && !empty($j['messages'][0]['id'])) return null;
                return 'Cloud API ответил ' . $code . ': ' . mb_substr((string) ($j['error']['message'] ?? $body), 0, 200);
            }
            return 'Не выбран шлюз';
        } catch (\Throwable $e) {
            return $e->getMessage();
        }
    }

    /** Параметры шаблонов Meta не допускают переводов строк и табуляций */
    private static function oneLine(string $s, int $max): string
    {
        return mb_substr(trim((string) preg_replace('/\s*\n\s*/u', ' · ', $s)), 0, $max);
    }

    private static function http(string $url, array $json, array $headers = []): array
    {
        $body = json_encode($json, JSON_UNESCAPED_UNICODE);
        $headers = array_merge(['Content-Type: application/json', 'Accept: application/json'], $headers);
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_HTTPHEADER => $headers,
                CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 12, CURLOPT_CONNECTTIMEOUT => 6]);
            $resp = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);
            curl_close($ch);
            if ($resp === false) throw new \RuntimeException('Нет связи с сервисом: ' . $err);
            return [$code, (string) $resp];
        }
        $ctx = stream_context_create(['http' => ['method' => 'POST', 'header' => implode("\r\n", $headers), 'content' => $body, 'timeout' => 12, 'ignore_errors' => true]]);
        $resp = @file_get_contents($url, false, $ctx);
        if ($resp === false) throw new \RuntimeException('Нет связи с сервисом');
        $code = preg_match('#HTTP/\S+\s+(\d+)#', $http_response_header[0] ?? '', $m) ? (int) $m[1] : 0;
        return [$code, (string) $resp];
    }

    private static function log(string $to, bool $ok, ?string $error, string $refType, ?int $refId): void
    {
        try {
            App::db()->insert('notify_log', ['channel' => 'whatsapp', 'target' => $to, 'ok' => $ok ? 1 : 0,
                'error' => $error ? mb_substr($error, 0, 500) : null, 'ref_type' => $refType ?: null, 'ref_id' => $refId]);
        } catch (\Throwable $e) {
            Log::error('notify_log: ' . $e->getMessage());
        }
    }

    // ----------------------------------------------------------------- тексты сообщений

    /** Текст о заказе (на русском — для владельца/менеджера). $o — Orders::find() на русском языке. */
    public static function orderText(array $o): string
    {
        $phone = preg_replace('/\D+/', '', (string) $o['phone']);
        $lines = [];
        $lines[] = ($o['source'] === 'quickorder' ? '⚡ Купить в 1 клик' : '🛒 Новый заказ') . ' ' . $o['number'];
        $lines[] = '';
        $lines[] = '👤 ' . trim((string) $o['name']) . ($phone !== '' ? ' · +' . $phone : '');
        if (!empty($o['email'])) $lines[] = '✉️ ' . $o['email'];
        $ship = trim(implode(', ', array_filter([(string) ($o['shipping_title'] ?? ''), (string) ($o['city'] ?? ''), (string) ($o['address'] ?? '')])));
        if ($ship !== '') $lines[] = '🚚 ' . $ship;
        if (!empty($o['payment_title'])) $lines[] = '💳 ' . $o['payment_title'];
        $lines[] = '';
        $i = 0;
        foreach ($o['items'] as $it) {
            $i++;
            $lines[] = $i . ') ' . $it['name'] . (!empty($it['size']) ? ' (р. ' . $it['size'] . ')' : '')
                . ' — ' . $it['boxes'] . ' ящ. × ' . $it['box_qty'] . ' пар = ' . price_format($it['sum']);
        }
        $lines[] = '';
        $lines[] = 'Итого: ' . (int) $o['boxes'] . ' ящ. / ' . (int) $o['pairs'] . ' пар — ' . price_format($o['total'])
            . ((float) $o['discount'] > 0 ? ' (скидка ' . price_format($o['discount']) . ($o['coupon_code'] !== '' ? ', промокод ' . $o['coupon_code'] : '') . ')' : '');
        if (!empty($o['comment'])) { $lines[] = ''; $lines[] = '💬 ' . trim((string) $o['comment']); }
        if (($o['lang'] ?? 'ru') === 'uk') $lines[] = 'Язык: украинский';
        $lines[] = '';
        $lines[] = url('/admin/orders/' . (int) $o['id'] . '/');
        return implode("\n", $lines);
    }

    /** Отправить заказ (вызывается из Orders::notify, уже после ответа покупателю). Возвращает строку для истории заказа. */
    public static function notifyOrder(array $o): ?string
    {
        if (!self::enabled()) return null;
        $key = $o['source'] === 'quickorder' ? 'notify_quickorder' : 'notify_orders';
        if (self::cfg($key, '1') !== '1') return null;
        $r = self::send(self::orderText($o), null, 'order', (int) $o['id']);
        return $r['ok'] ? 'WhatsApp: заказ отправлен (' . $r['sent'] . ')' : 'WhatsApp: ошибка — ' . implode('; ', $r['errors']);
    }

    /** Заявка с сайта (обратный звонок, контакты) — если включено в настройках. Отправка после ответа посетителю. */
    public static function notifyRequestLater(string $type, int $id, string $title, array $fields): void
    {
        if (!self::enabled() || !in_array($type, ['callback', 'contact'], true) || self::cfg('notify_requests', '1') !== '1') return;
        $lines = ['📞 ' . $title, ''];
        foreach ($fields as [$k, $v]) if (trim((string) $v) !== '') $lines[] = $k . ': ' . trim((string) $v);
        $lines[] = '';
        $lines[] = url('/admin/requests/');
        $text = implode("\n", $lines);
        register_shutdown_function(static function () use ($text, $id): void {
            if (function_exists('fastcgi_finish_request')) @fastcgi_finish_request();
            elseif (function_exists('litespeed_finish_request')) @litespeed_finish_request();
            @ignore_user_abort(true);
            try {
                self::send($text, null, 'request', $id);
            } catch (\Throwable $e) {
                Log::error('WhatsApp (заявка ' . $id . '): ' . $e->getMessage());
            }
        });
    }
}
