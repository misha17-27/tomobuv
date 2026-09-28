<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Отправка писем. Если в конфиге задан SMTP — через SMTP (SSL/TLS, AUTH LOGIN),
 * иначе через mail() хостинга. Ошибки не ломают оформление заказа — только пишутся в лог.
 */
final class Mailer
{
    /** Последняя ошибка отправки (для кнопки «Отправить тестовое письмо» в админке) */
    public static string $lastError = '';

    /**
     * Настройки почты: из админки (таблица settings, ключи mail.*), иначе из config.php.
     * mail.from, mail.from_name, mail.admin_to, mail.smtp_host, mail.smtp_port, mail.smtp_user, mail.smtp_pass, mail.smtp_secure
     */
    public static function cfg(string $key, $default = '')
    {
        $v = Settings::get('mail.' . $key);
        if ($v !== null && $v !== '') return $v;
        return App::config('mail.' . $key, $default);
    }

    /** Адрес для уведомлений о заказах и заявках */
    public static function adminEmail(): string
    {
        return (string) (self::cfg('admin_to') ?: Settings::get('store_email', ''));
    }

    public static function send(string $to, string $subject, string $html): bool
    {
        self::$lastError = '';
        $to = trim($to);
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) { self::$lastError = 'Некорректный адрес получателя'; return false; }
        $from = (string) self::cfg('from', 'noreply@' . ($_SERVER['HTTP_HOST'] ?? 'localhost'));
        $fromName = (string) self::cfg('from_name', 'Tomobuv');
        try {
            if (self::cfg('smtp_host')) return self::smtp($to, $subject, $html, $from, $fromName);
            $headers = [
                'MIME-Version: 1.0',
                'Content-Type: text/html; charset=UTF-8',
                'From: ' . self::encode($fromName) . ' <' . $from . '>',
            ];
            $ok = @mail($to, self::encode($subject), $html, implode("\r\n", $headers));
            if (!$ok) self::$lastError = 'Функция mail() хостинга не отправила письмо — настройте SMTP.';
            return $ok;
        } catch (\Throwable $e) {
            self::$lastError = $e->getMessage();
            Log::error('Mail: ' . $e->getMessage());
            return false;
        }
    }

    private static function encode(string $s): string
    {
        return '=?UTF-8?B?' . base64_encode($s) . '?=';
    }

    private static function smtp(string $to, string $subject, string $html, string $from, string $fromName): bool
    {
        $host = (string) self::cfg('smtp_host');
        $port = (int) self::cfg('smtp_port', 465);
        $secure = (string) self::cfg('smtp_secure', 'ssl');
        $fp = @stream_socket_client(($secure === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port, $errno, $errstr, 15);
        if (!$fp) { self::$lastError = "Не удалось подключиться к SMTP: $errstr"; Log::error("SMTP connect: $errstr"); return false; }
        stream_set_timeout($fp, 15);
        $read = static function () use ($fp): string {
            $data = '';
            while (($line = fgets($fp, 515)) !== false) { $data .= $line; if (isset($line[3]) && $line[3] === ' ') break; }
            return $data;
        };
        // $label — что подписать в ошибке: логин/пароль (base64) и текст письма в сообщение об ошибке не попадают
        $cmd = static function (string $c, array $ok, string $label = '') use ($fp, $read): void {
            fwrite($fp, $c . "\r\n");
            $r = $read();
            if ($r === '') throw new \RuntimeException('SMTP: сервер не ответил на ' . ($label ?: explode(' ', $c)[0]) . ' (таймаут 15 с) — проверьте порт и шифрование');
            if (!in_array((int) substr($r, 0, 3), $ok, true)) throw new \RuntimeException('SMTP: ' . trim($r) . ' on ' . ($label ?: explode(' ', $c)[0]));
        };
        $read();
        $ehlo = 'EHLO ' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
        $cmd($ehlo, [250]);
        if ($secure === 'tls') {
            $cmd('STARTTLS', [220]);
            if (!@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new \RuntimeException('SMTP: не удалось включить шифрование STARTTLS — попробуйте SSL/TLS и порт 465');
            }
            $cmd($ehlo, [250]);
        }
        if (self::cfg('smtp_user')) {
            $cmd('AUTH LOGIN', [334]);
            $cmd(base64_encode((string) self::cfg('smtp_user')), [334], 'AUTH (логин)');
            $cmd(base64_encode((string) self::cfg('smtp_pass')), [235], 'AUTH (пароль)');
        }
        $cmd('MAIL FROM:<' . $from . '>', [250]);
        $cmd('RCPT TO:<' . $to . '>', [250, 251]);
        $cmd('DATA', [354]);
        $msg = 'From: ' . self::encode($fromName) . ' <' . $from . ">\r\n"
            . 'To: <' . $to . ">\r\n"
            . 'Subject: ' . self::encode($subject) . "\r\n"
            . "MIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($html));
        $cmd($msg . "\r\n.", [250], 'DATA (текст письма)');
        fwrite($fp, "QUIT\r\n");
        fclose($fp);
        return true;
    }
}
