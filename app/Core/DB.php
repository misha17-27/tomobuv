<?php
declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOStatement;

/**
 * Тонкая обёртка над PDO. ВСЕ запросы — только с плейсхолдерами (защита от SQL-инъекций).
 *
 *   $db->all('SELECT * FROM products WHERE brand_id = ? LIMIT 24', [$id]);
 *   $db->row('SELECT * FROM products WHERE url = :url', ['url' => $url]);
 *   $db->value('SELECT COUNT(*) FROM orders');
 *   $db->col('SELECT id FROM products WHERE status = 1');
 *   $db->pairs('SELECT id, name FROM brands');           // [id => name]
 *   $db->insert('orders', [...]) → id;  $db->update('orders', [...], 'id = ?', [$id]);
 *   $db->in([1,2,3]) → ['?,?,?', [1,2,3]] — для WHERE id IN (...)
 */
final class DB
{
    private PDO $pdo;
    public int $queries = 0;
    /** Украинская версия витрины: подставлять колонки x_uk вместо x (см. Lang::localize) */
    public static bool $localize = false;

    public function __construct(array $c)
    {
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $c['host'] ?? 'localhost', (int) ($c['port'] ?? 3306), $c['name'], $c['charset'] ?? 'utf8mb4');
        $this->pdo = new PDO($dsn, $c['user'] ?? '', $c['password'] ?? '', [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => true,   // быстрее на shared-хостинге, безопасно с utf8mb4
            PDO::ATTR_STRINGIFY_FETCHES  => false,
        ]);
        $this->pdo->exec("SET time_zone = '" . date('P') . "'");
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    public function query(string $sql, array $params = []): PDOStatement
    {
        $this->queries++;
        $st = $this->pdo->prepare($sql);
        foreach ($params as $k => $v) {
            $key = is_int($k) ? $k + 1 : (str_starts_with((string) $k, ':') ? $k : ':' . $k);
            $type = is_int($v) ? PDO::PARAM_INT : ($v === null ? PDO::PARAM_NULL : (is_bool($v) ? PDO::PARAM_BOOL : PDO::PARAM_STR));
            $st->bindValue($key, $v, $type);
        }
        $st->execute();
        return $st;
    }

    public function all(string $sql, array $params = []): array
    {
        $rows = $this->query($sql, $params)->fetchAll();
        return self::$localize ? array_map([Lang::class, 'localize'], $rows) : $rows;
    }

    public function row(string $sql, array $params = []): ?array
    {
        $r = $this->query($sql, $params)->fetch();
        if ($r === false) return null;
        return self::$localize ? Lang::localize($r) : $r;
    }

    public function value(string $sql, array $params = [])
    {
        $v = $this->query($sql, $params)->fetchColumn();
        return $v === false ? null : $v;
    }

    public function col(string $sql, array $params = []): array
    {
        return $this->query($sql, $params)->fetchAll(PDO::FETCH_COLUMN);
    }

    /** [первая колонка => вторая колонка] */
    public function pairs(string $sql, array $params = []): array
    {
        return $this->query($sql, $params)->fetchAll(PDO::FETCH_KEY_PAIR);
    }

    /** [значение первой колонки => вся строка] */
    public function keyed(string $sql, array $params = []): array
    {
        $out = [];
        foreach ($this->query($sql, $params) as $r) {
            $out[reset($r)] = self::$localize ? Lang::localize($r) : $r;
        }
        return $out;
    }

    public function insert(string $table, array $data, bool $ignore = false): int
    {
        $cols = array_keys($data);
        $sql = 'INSERT ' . ($ignore ? 'IGNORE ' : '') . 'INTO `' . $table . '` (`' . implode('`,`', $cols) . '`) VALUES ('
            . implode(',', array_fill(0, count($cols), '?')) . ')';
        $this->query($sql, array_values($data));
        return (int) $this->pdo->lastInsertId();
    }

    /** INSERT … ON DUPLICATE KEY UPDATE для перечисленных колонок */
    public function upsert(string $table, array $data, array $updateCols): void
    {
        $cols = array_keys($data);
        $upd = implode(',', array_map(static fn($c) => "`$c` = VALUES(`$c`)", $updateCols));
        $sql = 'INSERT INTO `' . $table . '` (`' . implode('`,`', $cols) . '`) VALUES ('
            . implode(',', array_fill(0, count($cols), '?')) . ') ON DUPLICATE KEY UPDATE ' . $upd;
        $this->query($sql, array_values($data));
    }

    /** Пакетная вставка: rows — массив одинаковых ассоциативных массивов */
    public function insertMany(string $table, array $rows, bool $ignore = false, int $chunk = 500): void
    {
        if (!$rows) return;
        $cols = array_keys(reset($rows));
        foreach (array_chunk($rows, $chunk) as $part) {
            $ph = '(' . implode(',', array_fill(0, count($cols), '?')) . ')';
            $sql = 'INSERT ' . ($ignore ? 'IGNORE ' : '') . 'INTO `' . $table . '` (`' . implode('`,`', $cols) . '`) VALUES '
                . implode(',', array_fill(0, count($part), $ph));
            $params = [];
            foreach ($part as $r) foreach ($cols as $c) $params[] = $r[$c] ?? null;
            $this->query($sql, $params);
        }
    }

    public function update(string $table, array $data, string $where, array $params = []): int
    {
        $set = implode(',', array_map(static fn($c) => "`$c` = ?", array_keys($data)));
        return $this->query('UPDATE `' . $table . '` SET ' . $set . ' WHERE ' . $where,
            array_merge(array_values($data), $params))->rowCount();
    }

    public function delete(string $table, string $where, array $params = []): int
    {
        return $this->query('DELETE FROM `' . $table . '` WHERE ' . $where, $params)->rowCount();
    }

    /** Плейсхолдеры для IN: [$ph, $vals] = $db->in($ids); "... WHERE id IN ($ph)" */
    public function in(array $values): array
    {
        $values = array_values($values);
        if (!$values) return ['NULL', []];
        return [implode(',', array_fill(0, count($values), '?')), $values];
    }

    public function transaction(callable $fn)
    {
        $this->pdo->beginTransaction();
        try {
            $r = $fn($this);
            $this->pdo->commit();
            return $r;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }
}
