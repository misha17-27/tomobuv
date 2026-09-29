<?php
declare(strict_types=1);

namespace App\Services\Suppliers;

use App\Core\App;

/**
 * API Jong•Golf (https://www.jonggolf.com/api/json/): POST x-www-form-urlencoded, ответ JSON, ключ — access_key
 * в теле запроса, плюс белый список IP у поставщика (с чужого IP — текст «Access denied for this IP.»).
 *
 *   dictionary()  — export_product_setting: справочники (сезоны, категории, бренды, материалы, пол). Только чтение.
 *   page($clean)  — export_product: очередь товаров, не больше 300 за ответ. Без clean_export — только чтение.
 *                   clean_export=1 заново ставит в очередь весь каталог — МЕНЯЕТ состояние у поставщика.
 *   confirm($ids) — export_product_callback: «эти товары обработаны», убирает их из очереди — МЕНЯЕТ состояние.
 *
 * Очередь общая на ключ: подтверждение из теста «украдёт» обновления у другого загрузчика с тем же ключом (старый
 * сайт до переключения). Поэтому clean_export и callback разрешены только боевому клиенту (new self($key, true)),
 * и даже ему — только когда автозагрузка включена в настройках и сайт не в режиме разработки (config debug = false).
 * Проверка — здесь, в единственном месте, которое умеет их отправить.
 *
 * Ключ не попадает ни в сообщения об ошибках, ни в журналы (mask), ни в сохранённые ответы (он только в теле запроса).
 */
final class JongGolfApi
{
    public const BASE = 'https://www.jonggolf.com/api/json/';
    /** Не чаще одного запроса в секунду (старый загрузчик — 24 мс; бережнее к поставщику) */
    private const GAP = 1.0;
    private const TIMEOUT = 30;
    private const TRIES = 3;

    private static float $last = 0.0;

    public function __construct(private string $key, private bool $live = false)
    {
    }

    /** Можно ли менять состояние у поставщика (clean_export, callback) */
    public function canChangeQueue(): bool
    {
        return $this->live && !App::isDebug() && JongGolf::enabled();
    }

    /** Справочники export_product_setting → answer */
    public function dictionary(): array
    {
        $r = $this->call('export_product_setting', []);
        if (($r['type'] ?? '') !== 'success' || !is_array($r['answer'] ?? null)) {
            throw new JongGolfError('Справочники не получены: ' . $this->describe($r));
        }
        return $r;
    }

    /**
     * Очередная страница очереди товаров. $clean — clean_export=1 (весь каталог заново; только боевой запуск).
     * @return array ответ поставщика: ['type' => 'success'|'error', 'answer' => [id => товар]]
     */
    public function page(bool $clean): array
    {
        $p = [];
        if ($clean) {
            if (!$this->canChangeQueue()) throw new \LogicException('clean_export запрещён: не боевой запуск, автозагрузка выключена или режим разработки');
            $p['clean_export'] = 1;
        }
        return $this->call('export_product', $p);
    }

    /** Подтвердить обработку товаров страницы (callback). Только боевой запуск. @return string ответ поставщика (msg) */
    public function confirm(array $pids): string
    {
        if (!$this->canChangeQueue()) throw new \LogicException('export_product_callback запрещён: не боевой запуск, автозагрузка выключена или режим разработки');
        $pids = array_values(array_filter(array_map(static fn($v) => (string) (int) $v, $pids), static fn($v) => $v !== '0'));
        if (!$pids) return '';
        $r = $this->call('export_product_callback', ['pids' => implode(',', $pids)]);
        if (($r['type'] ?? '') !== 'success') throw new JongGolfError('Поставщик не подтвердил обработку: ' . $this->describe($r));
        return (string) ($r['msg'] ?? '');
    }

    /** POST к методу API → разобранный JSON. Пустой ответ — повтор (до TRIES раз), остальное — JongGolfError. */
    private function call(string $method, array $params): array
    {
        if ($this->key === '') throw new JongGolfError('Не задан ключ API — укажите его в настройках.');
        if (!function_exists('curl_init')) throw new JongGolfError('На сервере нет расширения curl — запросы к поставщику невозможны.');
        $body = '';
        for ($try = 1; $try <= self::TRIES; $try++) {
            $wait = self::GAP - (microtime(true) - self::$last);
            if ($wait > 0) usleep((int) ($wait * 1e6));
            self::$last = microtime(true);
            $base = self::base();
            $ch = curl_init($base . $method . '/');
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => http_build_query(['access_key' => $this->key] + $params),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT => self::TIMEOUT,
                CURLOPT_PROTOCOLS => str_starts_with($base, 'http://127.0.0.1:') ? CURLPROTO_HTTP : CURLPROTO_HTTPS,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; TomobuvSupplier/1.0)',
                CURLOPT_ENCODING => '',
                CURLOPT_HTTPHEADER => ['Accept: application/json'],
            ]);
            $cainfo = (string) App::config('suppliers.cainfo', '');
            if ($cainfo !== '') curl_setopt($ch, CURLOPT_CAINFO, $cainfo);
            $res = curl_exec($ch);
            $err = curl_error($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);
            if ($res === false) {
                if ($try < self::TRIES) { sleep(1); continue; }
                throw new JongGolfError('Нет связи с поставщиком: ' . $this->mask($err ?: 'ошибка соединения'));
            }
            $body = (string) $res;
            if ($code >= 500 || trim($body) === '') {                        // сбой у поставщика или пустой ответ — повтор
                if ($try < self::TRIES) { sleep(1); continue; }
                throw new JongGolfError($code >= 500 ? 'Сервер поставщика ответил ошибкой ' . $code : 'Поставщик вернул пустой ответ (' . self::TRIES . ' попытки)');
            }
            break;
        }
        $text = trim($body);
        if (stripos($text, 'Access denied') === 0) {
            throw new JongGolfError('Поставщик отклонил запрос: «' . $this->mask(mb_substr($text, 0, 100)) . '» — IP этого сервера не в белом списке Jong•Golf.'
                . ' Сообщите поставщику внешний IP хостинга (старый сайт работает со своего IP).', 'ip');
        }
        $j = json_decode($text, true);
        if (!is_array($j)) throw new JongGolfError('Непонятный ответ поставщика (не JSON): «' . $this->mask(mb_substr(strip_tags($text), 0, 200)) . '»');
        $j['_bytes'] = strlen($body);
        $j['_raw'] = $body;
        return $j;
    }

    /**
     * Адрес API: BASE; config suppliers.jonggolf_api — только для проверки на своём сервере-имитаторе
     * (http://127.0.0.1:порт/…); на хостинге не задаётся.
     */
    private static function base(): string
    {
        $b = (string) App::config('suppliers.jonggolf_api', '');
        return $b !== '' && preg_match('#^(https://|http://127\.0\.0\.1:\d+/)#', $b) ? rtrim($b, '/') . '/' : self::BASE;
    }

    private function describe(array $r): string
    {
        $msg = $r['msg'] ?? ($r['message'] ?? null);
        return $this->mask(is_scalar($msg) && (string) $msg !== '' ? (string) $msg : 'type=' . (string) ($r['type'] ?? '?'));
    }

    /** Убрать ключ из текста (сообщения об ошибках, ответы) */
    public function mask(string $s): string
    {
        return $this->key !== '' ? str_replace([$this->key, rawurlencode($this->key)], '***', $s) : $s;
    }
}
