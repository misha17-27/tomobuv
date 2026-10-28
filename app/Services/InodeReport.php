<?php
declare(strict_types=1);

namespace App\Services;

/**
 * Сколько файлов и папок (inodes) занимает аккаунт хостинга — по папкам (решение 28.10.2026: лимит Namecheap 300 тыс.,
 * еженедельные бэкапы — только до 200 тыс.): домашняя папка целиком (первый уровень), внутри сайта — его папки, storage/*
 * и wa-data/* отдельно. Обход по времени (до $maxSeconds), итог — storage/inodes.json; bin/cron.php — раз в 3 ч,
 * «Состояние системы» показывает. Ссылки (public_html → сайт) не обходятся — не считаются дважды.
 */
final class InodeReport
{
    public const LIMIT = 300000;
    public const BACKUP_LIMIT = 200000;

    /** Посчитать сейчас. @return array{at: int, total: int, complete: bool, home: array, site: array, sec: float} */
    public static function run(float $maxSeconds = 120.0): array
    {
        $t0 = microtime(true);
        // cPanel: сайт — /home/{пользователь}/repositories/tomobuv-site, считается вся домашняя папка; иначе — папка над сайтом
        $home = basename(dirname(ROOT)) === 'repositories' ? dirname(ROOT, 2) : dirname(ROOT);
        if (!is_dir($home) || !is_readable($home)) $home = ROOT;
        $rootRel = str_replace('\\', '/', ltrim(substr(ROOT, strlen($home)), '/\\'));
        $top = []; $site = []; $total = 0; $complete = true;
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($home, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST, \RecursiveIteratorIterator::CATCH_GET_CHILD);
        foreach ($it as $path => $f) {
            if (($total & 1023) === 0 && microtime(true) - $t0 > $maxSeconds) { $complete = false; break; }
            $total++;
            $rel = str_replace('\\', '/', substr((string) $path, strlen($home) + 1));
            $parts = explode('/', $rel);
            $top[$parts[0]] = ($top[$parts[0]] ?? 0) + 1;
            if ($rootRel !== '' && str_starts_with($rel . '/', $rootRel . '/') && $rel !== $rootRel) {
                $in = explode('/', substr($rel, strlen($rootRel) + 1));
                $key = $in[0];
                if (in_array($in[0], ['storage', 'vendor'], true) && isset($in[1])) $key = $in[0] . '/' . $in[1];
                if ($in[0] === 'public' && ($in[1] ?? '') === 'wa-data' && isset($in[2])) {
                    $key = 'public/wa-data/' . $in[2] . (isset($in[3]) && $in[3] === 'shop' && isset($in[4]) ? '/shop/' . $in[4] : '');
                }
                if ($in[0] === 'storage' && ($in[1] ?? '') === 'cache' && isset($in[2])) $key = 'storage/cache/' . $in[2];
                $site[$key] = ($site[$key] ?? 0) + 1;
            }
        }
        arsort($top);
        arsort($site);
        $r = ['at' => time(), 'total' => $total, 'complete' => $complete, 'home' => $top, 'site' => array_slice($site, 0, 25, true),
            'root' => $rootRel, 'sec' => round(microtime(true) - $t0, 1)];
        @file_put_contents(STORAGE . '/inodes.json', json_encode($r, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
        return $r;
    }

    /** Последний подсчёт или null */
    public static function last(): ?array
    {
        $j = json_decode((string) @file_get_contents(STORAGE . '/inodes.json'), true);
        return is_array($j) ? $j : null;
    }
}
