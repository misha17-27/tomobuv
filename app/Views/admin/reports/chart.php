<?php
/**
 * График отчёта: столбики — выручка (левая ось, грн), линия — число заказов (правая ось).
 * Чистый SVG без библиотек, как bar_chart() в ARG FLEX. Подсказка: <title> (без JS),
 * reports.js заменяет её всплывашкой из data-атрибутов столбика.
 * @var array $series [ключ 'Y-m-d' | 'Y-m' => ['sum' => float, 'n' => int]] @var bool $monthly
 */
use App\Services\Reports;

$W = 900; $H = 280; $L = 64; $R = 40; $T = 30; $B = 34;
$cw = $W - $L - $R; $ch = $H - $T - $B;
$count = max(1, count($series));
$peakSum = $series ? (float) max(array_column($series, 'sum')) : 0.0;
$peakN = $series ? (int) max(array_column($series, 'n')) : 0;

// «красивый» шаг сетки: 1, 2, 2.5, 5 × 10ⁿ — 4 деления
$nice = static function (float $v): float {
    if ($v <= 0) return 1.0;
    $e = 10 ** floor(log10($v));
    foreach ([1, 2, 2.5, 5, 10] as $m) if ($m * $e >= $v) return $m * $e;
    return 10 * $e;
};
$stepSum = $nice($peakSum / 4);
$maxSum = $stepSum * 4;
$stepN = max(1, (int) ceil($nice($peakN / 4)));
$maxN = $stepN * 4;
$short = static function (float $v): string {
    if ($v >= 1e6) return rtrim(rtrim(number_format($v / 1e6, 2, ',', ''), '0'), ',') . ' млн';
    if ($v >= 1e3) return rtrim(rtrim(number_format($v / 1e3, 1, ',', ''), '0'), ',') . ' тыс';
    return (string) (int) $v;
};
$r2 = static fn(float $v): string => rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');

$slot = $cw / $count;
$gap = $slot >= 14 ? $slot * 0.28 : ($slot >= 5 ? 1.5 : ($slot >= 2.5 ? 0.6 : 0));
$barW = min(56, max(0.8, $slot - $gap));                                // короткий период — не «простыня» на всю ширину

// какие подписи ставить по оси X, чтобы не наезжали друг на друга
$keys = array_keys($series);
$labelAt = [];
if ($monthly) {
    if ($count <= 24) {
        foreach ($keys as $i => $k) $labelAt[$i] = Reports::bucketLabel($k, true, $i === 0 || substr($k, 5, 2) === '01');
        if ($count > 13) foreach ($labelAt as $i => $v) if ($i % 2 && substr($keys[$i], 5, 2) !== '01') unset($labelAt[$i]);
    } elseif ($count <= 48) {
        // по кварталам: «янв 2025», «апр», «июл», «окт»
        foreach ($keys as $i => $k) {
            $m = substr($k, 5, 2);
            if (in_array($m, ['01', '04', '07', '10'], true)) $labelAt[$i] = Reports::bucketLabel($k, true, $m === '01');
        }
    } else {
        $every = $count > 150 ? 2 : 1;                                     // подпись — год, при очень длинном периоде через год
        foreach ($keys as $i => $k) {
            if (substr($k, 5, 2) === '01' && (int) substr($k, 0, 4) % $every === 0) $labelAt[$i] = substr($k, 0, 4);
        }
    }
} else {
    $every = $count <= 16 ? 1 : (int) ceil($count / 9);
    foreach ($keys as $i => $k) {
        // последняя дата подписывается всегда — соседняя подпись не ближе ¾ шага, иначе наедут («23 апр30 апр»)
        if ($i % $every === 0 && ($every === 1 || $count - 1 - $i >= max(2, $every * 0.75))) $labelAt[$i] = Reports::bucketLabel($k, false, false);
    }
    $labelAt[$count - 1] = Reports::bucketLabel($keys[$count - 1], false, false);
}
$ordersWord = static fn(int $n): string => $n . ' ' . plural($n, 'заказ', 'заказа', 'заказов');
$points = [];
?>
<svg class="chart rp-svg" viewBox="0 0 <?= $W ?> <?= $H ?>" role="img" aria-label="Выручка и число заказов <?= $monthly ? 'по месяцам' : 'по дням' ?>" preserveAspectRatio="xMidYMid meet">
  <text class="ax ax-cap" x="<?= $L - 8 ?>" y="12" text-anchor="end">грн</text>
  <text class="ax ax-cap ax-n" x="<?= $W - 2 ?>" y="12" text-anchor="end">заказов</text>
  <?php for ($i = 0; $i <= 4; $i++): $y = $T + $ch - $ch * $i / 4; ?>
    <line class="<?= $i ? 'gl' : 'base' ?>" x1="<?= $L ?>" y1="<?= $r2($y) ?>" x2="<?= $W - $R ?>" y2="<?= $r2($y) ?>"/>
    <text class="ax" x="<?= $L - 8 ?>" y="<?= $r2($y + 4) ?>" text-anchor="end"><?= e($short($stepSum * $i)) ?></text>
    <text class="ax ax-n" x="<?= $W - $R + 8 ?>" y="<?= $r2($y + 4) ?>"><?= (int) ($stepN * $i) ?></text>
  <?php endfor; ?>
  <?php $i = 0; foreach ($series as $k => $s):
      $x = $L + $i * $slot + ($slot - $barW) / 2;
      $bh = $s['sum'] > 0 ? max(1.5, $ch * $s['sum'] / $maxSum) : 0;
      $cx = $L + $i * $slot + $slot / 2;
      $points[] = $r2($cx) . ',' . $r2($T + $ch - ($maxN ? $ch * $s['n'] / $maxN : 0));
      $when = Reports::bucketLabel($k, $monthly);
  ?>
    <g class="col" data-d="<?= e($when) ?>" data-s="<?= e(price_format($s['sum'])) ?>" data-n="<?= e($ordersWord((int) $s['n'])) ?>">
      <title><?= e($when . ' — ' . price_format($s['sum']) . ', ' . $ordersWord((int) $s['n'])) ?></title>
      <?php if ($bh > 0): ?><rect class="b" x="<?= $r2($x) ?>" y="<?= $r2($T + $ch - $bh) ?>" width="<?= $r2($barW) ?>" height="<?= $r2($bh) ?>" rx="<?= $barW > 8 ? 2 : 0 ?>"/><?php endif; ?>
      <rect class="hit" x="<?= $r2($L + $i * $slot) ?>" y="<?= $T ?>" width="<?= $r2($slot) ?>" height="<?= $ch ?>"/>
    </g>
    <?php if (isset($labelAt[$i])): ?>
      <text class="ax" x="<?= $r2($cx) ?>" y="<?= $H - 10 ?>" text-anchor="<?= $i === 0 && $slot < 40 ? 'start' : ($i === $count - 1 && $slot < 40 ? 'end' : 'middle') ?>"><?= e($labelAt[$i]) ?></text>
    <?php endif; ?>
  <?php $i++; endforeach; ?>
  <?php if ($peakN > 0): ?>
    <polyline class="ln" points="<?= implode(' ', $points) ?>"/>
    <?php if ($count <= 62): foreach ($points as $pt): [$px, $py] = explode(',', $pt); ?><circle class="dt" cx="<?= $px ?>" cy="<?= $py ?>" r="2.8"/><?php endforeach; endif; ?>
  <?php endif; ?>
</svg>
