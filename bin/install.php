<?php
/**
 * Создание/обновление таблиц новой базы: php bin/install.php
 * Выполняет database/schema.sql, затем все database/migrations/*.sql (по алфавиту).
 * Безопасно запускать повторно: CREATE TABLE IF NOT EXISTS, ADD COLUMN IF NOT EXISTS.
 *
 * Миграция, упавшая из-за другой, которая идёт позже по алфавиту (например, индекс по orders.lang
 * из admin-sales.sql, а сама колонка — в i18n.sql), выполняется целиком ещё раз после всех остальных.
 * MySQL (в отличие от MariaDB) не знает ADD COLUMN / ADD INDEX IF NOT EXISTS — такие ALTER TABLE
 * выполняются по одному добавлению, «уже есть» пропускается.
 * При ошибках код выхода 1 — дальше (перенос данных) идти нельзя.
 */
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';

use App\Core\App;

$pdo = App::db()->pdo();
$exists = static fn(PDOException $e): bool => (bool) preg_match('/Duplicate (column|key)|already exists/i', $e->getMessage());

/** Части ALTER TABLE через запятую верхнего уровня (запятые в DECIMAL(12,2), списках колонок индекса и строках — не делят) */
$clauses = static function (string $s): array {
    $out = []; $buf = ''; $depth = 0; $quote = null;
    for ($i = 0, $len = strlen($s); $i < $len; $i++) {
        $ch = $s[$i];
        if ($quote !== null) { if ($ch === $quote) $quote = null; }
        elseif ($ch === "'" || $ch === '"' || $ch === '`') $quote = $ch;
        elseif ($ch === '(') $depth++;
        elseif ($ch === ')') $depth--;
        elseif ($ch === ',' && $depth === 0) { $out[] = trim($buf); $buf = ''; continue; }
        $buf .= $ch;
    }
    if (trim($buf) !== '') $out[] = trim($buf);
    return $out;
};

/** Выполнить одну команду; «уже существует» — не ошибка (повторный запуск). Возвращает текст ошибки или null. */
$run = static function (string $stmt) use ($pdo, $exists, $clauses): ?string {
    try {
        $pdo->exec($stmt);
        return null;
    } catch (PDOException $e) {
        if ($exists($e)) return null;
        // MySQL: синтаксическая ошибка на «ADD COLUMN IF NOT EXISTS» — добавляем по одному без IF NOT EXISTS
        if ((int) ($e->errorInfo[1] ?? 0) === 1064 && preg_match('/^ALTER\s+TABLE\s+(`?\w+`?)\s+(.+)$/is', $stmt, $m)
            && preg_match('/\bADD\s+(COLUMN|INDEX|KEY)\s+IF\s+NOT\s+EXISTS\b/i', $m[2])) {
            foreach ($clauses($m[2]) as $part) {
                $part = (string) preg_replace('/\b(ADD\s+(?:COLUMN|INDEX|KEY))\s+IF\s+NOT\s+EXISTS\b/i', '$1', $part);
                try {
                    $pdo->exec("ALTER TABLE {$m[1]} $part");
                } catch (PDOException $e2) {
                    if (!$exists($e2)) return $e2->getMessage();
                }
            }
            return null;
        }
        return $e->getMessage();
    }
};

/** Выполнить файл целиком: [выполнено команд, [ошибки]] */
$runFile = static function (string $file) use ($run): array {
    $sql = (string) preg_replace('/^\s*--.*$/m', '', (string) file_get_contents($file));
    $n = 0; $errors = [];
    foreach (array_filter(array_map('trim', preg_split('/;\s*\n/', $sql) ?: [])) as $stmt) {
        $err = $run($stmt);
        if ($err === null) $n++;
        else $errors[] = $err;
    }
    return [$n, $errors];
};

$files = array_merge([ROOT . '/database/schema.sql'], glob(ROOT . '/database/migrations/*.sql') ?: []);
$total = 0;
$retry = [];
foreach ($files as $file) {
    [$n, $errors] = $runFile($file);
    if ($errors) $retry[] = $file;
    echo basename($file) . ": $n команд" . ($errors ? ' (часть ждёт следующих миграций — повтор в конце)' : '') . "\n";
    $total += $n;
}
// второй проход: миграции, зависящие от более поздних по алфавиту (все миграции идемпотентны)
$failed = 0;
foreach ($retry as $file) {
    [$n, $errors] = $runFile($file);
    echo basename($file) . ' (повтор): ' . ($errors ? "$n команд" : 'выполнено') . "\n";
    foreach ($errors as $err) fwrite(STDERR, basename($file) . ': ' . $err . "\n");
    $failed += count($errors);
}
foreach (['cache/data', 'cache/pages', 'logs', 'sessions', 'uploads', 'import'] as $d) {
    @mkdir(STORAGE . '/' . $d, 0775, true);
}
@mkdir(PUBLIC_DIR . '/uploads', 0775, true);
// права на запись (на хостинге — 775 для storage/ и public/uploads/, public/wa-data/)
$ro = [];
foreach ([STORAGE, STORAGE . '/cache/pages', STORAGE . '/cache/data', STORAGE . '/logs', STORAGE . '/sessions', STORAGE . '/import', PUBLIC_DIR . '/uploads'] as $d) {
    if (!is_dir($d) || !is_writable($d)) $ro[] = substr($d, strlen(ROOT) + 1);
}
if (is_dir(PUBLIC_DIR . '/wa-data') && !is_writable(PUBLIC_DIR . '/wa-data')) $ro[] = 'public/wa-data';
if ($ro) fwrite(STDERR, 'Нет прав на запись: ' . implode(', ', $ro) . " — выставьте 775 (см. docs/INSTALL.md)\n");
if ($failed) {
    fwrite(STDERR, "Ошибок: $failed — таблицы созданы не полностью, исправьте и запустите ещё раз.\n");
    exit(1);
}
echo "Готово: $total SQL-команд.\n";
