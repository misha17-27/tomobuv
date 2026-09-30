<?php
/**
 * Автоисправление SEO title/description (правила — App\Services\SeoFix, docs/ARCHITECTURE.md → «SEO»).
 *
 *   php bin/seo-autofix.php --dry-run            — показать «было → стало» и итоги по группам, ничего не менять
 *   php bin/seo-autofix.php                      — применить (новый пакет в журнале seo_fix_batches / seo_fix_log)
 *   php bin/seo-autofix.php --revert=N           — откатить пакет N (значения, изменённые после пакета, пропускаются);
 *                                                  пакеты — от последнего к первому: если более поздний неоткаченный пакет
 *                                                  менял те же поля, откат отклоняется («сначала откатите №…»), флага «всё равно» нет
 *   php bin/seo-autofix.php --list               — пакеты
 * Дополнительно: --part=settings,products,categories,brands,pages,blog — только эти части;
 *   --limit=N — сколько строк «было → стало» печатать на группу (по умолчанию 40, 0 — все); --quiet — только итоги.
 * Повторный запуск ничего не меняет (пустой пакет не сохраняется). После применения и отката кэш сайта сбрасывается.
 */
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';

use App\Services\SeoFix;

ini_set('memory_limit', '1024M');
$opt = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $a, $m)) $opt[$m[1]] = $m[2] ?? true;
    else { fwrite(STDERR, "Неизвестный параметр: $a\n"); exit(2); }
}
$fmt = static fn($n) => number_format((int) $n, 0, '', ' ');
$cut = static fn(?string $s) => $s === null ? '∅ (пусто → шаблон)' : '«' . (mb_strlen($s) > 200 ? mb_substr($s, 0, 197) . '…' : $s) . '» (' . mb_strlen(trim($s)) . ')';

if (!SeoFix::hasTables()) {
    fwrite(STDERR, "Нет таблиц журнала — выполните php bin/install.php (database/migrations/seo-autofix.sql).\n");
    exit(1);
}

if (isset($opt['list'])) {
    foreach (SeoFix::batches(50) as $b) {
        $later = $b['reverted_at'] ? [] : SeoFix::laterOverlaps((int) $b['id']);   // откат — только после этих пакетов
        printf("№%-4d %s  %-6s  изменений %7s  части: %s%s\n", $b['id'], $b['created_at'], $b['source'], $fmt($b['changes']), $b['parts'],
            $b['reverted_at'] ? '  — откачен ' . $b['reverted_at'] . ' (возвращено ' . $fmt($b['revert']['restored'] ?? 0) . ', пропущено ' . $fmt($b['revert']['skipped'] ?? 0) . ')'
            : ($later ? '  — откат после №' . implode(', №', array_reverse(array_keys($later))) : ''));
    }
    exit(0);
}

if (isset($opt['revert'])) {
    $id = (int) $opt['revert'];
    try {
        $r = SeoFix::revert($id, null, isset($opt['dry-run']));
    } catch (RuntimeException $e) {
        fwrite(STDERR, $e->getMessage() . "\n");
        exit(1);
    }
    printf("%s пакета №%d: возвращено %s, пропущено %s (значение меняли после пакета).\n", isset($opt['dry-run']) ? 'Проверка отката' : 'Откат',
        $id, $fmt($r['restored']), $fmt($r['skipped']));
    foreach ($r['by_entity'] as $e => $n) echo "  $e: ", $fmt($n), "\n";
    foreach (array_slice($r['skipped_list'], 0, 50) as $s) {
        echo "  пропущено: {$s['entity']} #{$s['id']} {$s['field']} — сейчас ", $cut(is_string($s['now']) ? $s['now'] : null), ', ожидалось ', $cut($s['expected']), "\n";
    }
    exit(0);
}

$dry = isset($opt['dry-run']);
$limit = isset($opt['limit']) ? (int) $opt['limit'] : 40;
$quiet = isset($opt['quiet']);
$parts = isset($opt['part']) && is_string($opt['part']) ? array_filter(array_map('trim', explode(',', $opt['part']))) : array_keys(SeoFix::PARTS);
$bad = array_diff($parts, array_keys(SeoFix::PARTS));
if ($bad) { fwrite(STDERR, 'Неизвестные части: ' . implode(', ', $bad) . '. Есть: ' . implode(', ', array_keys(SeoFix::PARTS)) . "\n"); exit(2); }

$printed = [];
$onChange = static function (array $ch) use (&$printed, $limit, $quiet, $cut): void {
    if ($quiet) return;
    $g = $ch['group'];
    $printed[$g] = ($printed[$g] ?? 0) + 1;
    if ($limit > 0 && $printed[$g] > $limit) {
        if ($printed[$g] === $limit + 1) echo "  … ($g: дальше без печати, --limit=0 — все)\n";
        return;
    }
    $what = $ch['entity'] === 'setting' ? $ch['name'] : $ch['entity'] . ' #' . $ch['id'] . ' ' . mb_substr((string) $ch['name'], 0, 40) . ' · ' . $ch['col'];
    echo '[', $g, '] ', $what, ' [', strtoupper($ch['lang'] === 'uk' ? 'ua' : 'ru'), '] ', SeoFix::RULES[$ch['rule']] ?? $ch['rule'], ":\n    ",
        $cut($ch['old']), "\n  → ", $cut($ch['new']), ($ch['new'] === null && ($ch['shown'] ?? '') !== '' ? "\n    на сайте: " . $cut($ch['shown']) : ''), "\n";
};

$t = microtime(true);
try {
    $r = SeoFix::run(!$dry, ['parts' => $parts, 'source' => 'cli', 'onChange' => $onChange]);
} catch (RuntimeException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

echo "\n== Шаблоны\n";
if (!$r['templates']) echo "  без изменений\n";
foreach ($r['templates'] as $k => $x) echo "  $k — {$x['why']}\n    было:  ", $x['old'] !== '' ? $x['old'] : '(пусто)', "\n    стало: {$x['new']}\n";

echo "\n== Итоги по группам (адреса sitemap; в норме: title 30–70, description 70–170)\n";
foreach ($r['groups'] as $g => $x) {
    if (!$x['stats'] && !$x['changes']) continue;
    printf("  %s: объектов на сайте %s, изменений %s%s\n", $x['label'], $fmt($x['objects']), $fmt($x['changes']),
        $x['rules'] ? ' (' . implode(', ', array_map(static fn($k, $n) => (SeoFix::RULES[$k] ?? $k) . ' ' . number_format($n, 0, '', ' '), array_keys($x['rules']), $x['rules'])) . ')' : '');
    foreach ($x['stats'] as $lang => $fs) {
        foreach ($fs as $f => $s) {
            printf("    %s %-5s в норме %6s → %6s из %6s (%.1f%% → %.1f%%)%s\n", $lang === 'uk' ? 'UA' : 'RU', $f, $fmt($s['before']), $fmt($s['after']), $fmt($s['total']),
                $s['before'] / max(1, $s['total']) * 100, $s['after'] / max(1, $s['total']) * 100,
                $s['total'] > $s['after'] ? sprintf('; не в норме: короче %d, длиннее %d, нет %d', $s['short'], $s['long'], $s['none']) : '');
        }
    }
}
if ($r['left']) {
    echo "\n== Осталось не в норме (первые 30 из ", count($r['left']) >= 200 ? '200+' : count($r['left']), ")\n";
    foreach (array_slice($r['left'], 0, 30) as $l) echo "  [{$l['group']}] {$l['entity']} #{$l['id']} ", mb_substr($l['name'], 0, 40), ' ', strtoupper($l['lang'] === 'uk' ? 'ua' : 'ru'), " {$l['field']}: ", $cut($l['text']), "\n";
}
printf("\n%s: изменений %s за %.1f с.%s\n", $dry ? 'Проверка' : 'Применено', $fmt($r['changes']), microtime(true) - $t,
    $dry ? ' Запуск без --dry-run — применить.' : ($r['batch'] ? " Пакет №{$r['batch']} (откат: php bin/seo-autofix.php --revert={$r['batch']}). Кэш сайта сброшен." : ' Изменений нет — пакет не создан.'));
