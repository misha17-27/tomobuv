<?php
/**
 * Отчёты о продажах (как reports в админке ARG FLEX): периоды-таблетки, показатели, график,
 * лучшие товары / категории / бренды / виды обуви / клиенты, из чего сумма, статусы, доставка, оплата, источники, промокоды.
 * @var array $period (Reports::period) @var array $r (Reports::build) @var string $top boxes|sum
 */
use App\Services\Reports;

$t = $r['totals'];
$p = $r['prev'];
$fmt = static fn($n): string => number_format((float) $n, 0, '', ' ');
$pct = static fn(float $v): string => rtrim(rtrim(number_format($v, 1, ',', ''), '0'), ',') . '%';
$dmy = static fn(string $d): string => date('d.m.Y', strtotime($d));
$isCustom = $period['key'] === 'custom';
$maxDate = max(date('Y-m-d'), $period['to']);   // период из адреса может быть в будущем — иначе форма не отправится
$self = static fn(array $extra = []): string => '/admin/reports/?' . http_build_query($period['query'] + $extra);
$ordersUrl = static fn(array $extra = []): string => '/admin/orders/?' . http_build_query(array_filter($extra + ['from' => $period['from'], 'to' => $period['to']], static fn($v) => $v !== null && $v !== ''));
$exportUrl = static fn(string $type): string => '/admin/reports/export/?' . http_build_query($period['query'] + ['type' => $type]);
$meter = static function (float $v, float $peak): string {
    $w = $peak > 0 ? max(2, (int) round($v / $peak * 100)) : 0;
    return '<span class="meter"><i style="width:' . $w . '%"></i></span>';
};
// изменение к прошлому периоду: ▲ 12,5% / ▼ 3%
$delta = static function (float $now, string $key) use ($p, $pct, $period): string {
    if (!$p) return '';
    $d = Reports::delta($now, (float) ($p[$key] ?? 0));
    if ($d === null) return '';
    $cls = $d > 0 ? 'up' : ($d < 0 ? 'down' : '');
    return ' <small class="rp-delta ' . $cls . '" title="' . e($period['prev']['label']) . '">'
        . ($d > 0 ? '▲ ' : ($d < 0 ? '▼ ' : '')) . e($pct(abs($d))) . '</small>';
};
$ordersWord = static fn(int $n): string => plural($n, 'заказ', 'заказа', 'заказов');
$productsWord = static fn(int $n): string => number_format($n, 0, '', ' ') . ' ' . plural($n, 'товар', 'товара', 'товаров');
$share = static function (float $part, float $whole) use ($pct): string {
    if ($whole <= 0 || $part <= 0) return '0%';
    $v = round($part / $whole * 100, 1);
    return $v < 0.1 ? '<0,1%' : $pct($v);
};
?>
<div class="rp-period">
  <div class="tabs">
    <?php foreach (Reports::RANGES as $k => $label): ?>
      <a href="/admin/reports/?range=<?= e((string) $k) ?>" class="<?= $period['key'] === (string) $k ? 'on' : '' ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
  </div>
  <form class="filter-bar rp-custom<?= $isCustom ? ' on' : '' ?>" method="get" action="/admin/reports/">
    <input type="date" name="from" value="<?= e($period['from']) ?>" max="<?= e($maxDate) ?>" min="2000-01-01" aria-label="Начало периода" required>
    <span class="muted">—</span>
    <input type="date" name="to" value="<?= e($period['to']) ?>" max="<?= e($maxDate) ?>" min="2000-01-01" aria-label="Конец периода" required>
    <button class="btn btn-sm<?= $isCustom ? ' btn-p' : '' ?>" type="submit">Показать</button>
  </form>
</div>
<div class="rp-when">
  <b><?= e($dmy($period['from'])) ?> — <?= e($dmy($period['to'])) ?></b>
  · <?= $fmt($period['days']) ?> <?= plural($period['days'], 'день', 'дня', 'дней') ?>
  <?php if ($p && $p['orders'] && $t['orders']): ?><span class="muted">· ▲▼ — <?= e($period['prev']['label']) ?></span><?php endif; ?>
  <span class="muted">· данные на <?= e(date('H:i', strtotime($r['built_at']))) ?>, обновляются раз в 5 минут ·</span>
  <form class="rp-refresh" method="post" action="/admin/reports/refresh/">
    <?= \App\Controllers\Admin\BaseController::tokenField() ?>
    <?php foreach ($period['query'] + ['top' => $top] as $k => $v): ?><input type="hidden" name="<?= e($k) ?>" value="<?= e($v) ?>"><?php endforeach; ?>
    <button type="submit">Обновить сейчас</button>
  </form>
</div>

<?php if (!$t['orders_all']): ?>
  <div class="card">
    <div class="empty-card">
      <h2>Нет заказов за <?= $isCustom ? 'период ' . e($period['label']) : e(mb_strtolower($period['label'])) ?></h2>
      <p>Цифры появятся здесь, как только клиенты оформят заказы.
        <?php if (!empty($r['last_order'])): ?>Последний заказ был <?= e($dmy((string) $r['last_order'])) ?>.<?php endif; ?>
        Всё на этом экране считается из заказов в базе — ничего включать не нужно.</p>
      <a class="btn btn-p" href="/admin/reports/?range=all">Показать за всё время</a>
    </div>
  </div>
<?php else: ?>

  <div class="stats rp-stats">
    <a class="stat hot" href="<?= e($ordersUrl()) ?>"><span><?= e(price_format($t['revenue'], false)) ?></span>Выручка, грн<?= $delta($t['revenue'], 'revenue') ?></a>
    <a class="stat" href="<?= e($ordersUrl()) ?>"><span><?= $fmt($t['orders']) ?></span><?= e(mb_convert_case($ordersWord($t['orders']), MB_CASE_TITLE)) ?><?= $delta($t['orders'], 'orders') ?>
      <?php if ($t['excluded']): ?><small class="rp-sub">ещё <?= $fmt($t['excluded']) ?> удалено или возвращено — не учтены</small><?php endif; ?></a>
    <div class="stat"><span><?= e(price_format($t['average'], false)) ?></span>Средний чек, грн<?= $delta($t['average'], 'average') ?></div>
    <a class="stat" href="/admin/customers/"><span><?= $fmt($t['buyers']) ?></span><?= plural($t['buyers'], 'Покупатель', 'Покупателя', 'Покупателей') ?>
      <small class="rp-sub">клиентов с заказами в периоде</small></a>
    <div class="stat"><span><?= $t['boxes_guess'] ? '≈ ' : '' ?><?= $fmt($t['boxes']) ?></span><?= plural($t['boxes'], 'Ящик', 'Ящика', 'Ящиков') ?><?= $delta($t['boxes'], 'boxes') ?>
      <?php if ($t['boxes_guess']): ?><small class="rp-sub" title="У заказов старого сайта размер ящика не сохранился: до 12 пар — один ящик, больше — по 8 пар">оценка для <?= $fmt($t['boxes_guess']) ?> пар старых заказов</small><?php endif; ?></div>
    <div class="stat"><span><?= $fmt($t['pairs']) ?></span><?= plural($t['pairs'], 'Пара', 'Пары', 'Пар') ?> обуви<?= $delta($t['pairs'], 'pairs') ?>
      <?php if ($t['orders']): ?><small class="rp-sub"><?= e(number_format($t['pairs'] / $t['orders'], 0, ',', ' ')) ?> пар в среднем заказе</small><?php endif; ?></div>
    <div class="stat"><span><?= $fmt($t['new_buyers']) ?></span>Новых клиентов<small class="rp-sub">первый заказ — в этом периоде</small></div>
    <div class="stat"><span><?= e($pct($t['repeat_share'])) ?></span>Повторных заказов<small class="rp-sub"><?= $fmt($t['repeat_orders']) ?> из <?= $fmt($t['repeat_base'] ?? $t['orders']) ?> — от постоянных клиентов</small></div>
  </div>

  <div class="card">
    <div class="card-hd">
      <h2>Выручка и заказы <?= $period['monthly'] ? 'по месяцам' : 'по дням' ?></h2>
      <span class="rp-legend"><span><i class="lg-bar"></i>Выручка, грн</span><span><i class="lg-line"></i>Заказов</span></span>
    </div>
    <div class="pad">
      <div class="rp-chart"><?= $view->partial('admin/reports/chart', ['series' => $r['series'], 'monthly' => $period['monthly']]) ?></div>
      <p class="hint">Наведите на столбик — дата, выручка и число заказов. Удалённые заказы и возвраты не учитываются.<?= $period['monthly'] ? '' : ' Периоды длиннее 120 дней показываются по месяцам.' ?></p>
    </div>
  </div>

  <?php if (!$t['orders']): ?>
    <div class="card">
      <div class="empty-card">
        <h2>Все заказы периода удалены или возвращены</h2>
        <p>В выручку ничего не попало — разбивка по статусам ниже.</p>
        <a class="btn" href="<?= e($ordersUrl(['status' => 'deleted'])) ?>">Открыть заказы</a>
      </div>
    </div>
  <?php endif; ?>

  <div class="two-col rp-cols">
    <div>
      <?php if ($r['top_boxes']): ?>
      <div class="card" id="top">
        <div class="card-hd">
          <h2>Лучшие товары</h2>
          <div class="rp-switch">
            <a class="chip<?= $top === 'boxes' ? ' on' : '' ?>" data-top="boxes" href="<?= e($self(['top' => 'boxes'])) ?>#top">По ящикам</a>
            <a class="chip<?= $top === 'sum' ? ' on' : '' ?>" data-top="sum" href="<?= e($self(['top' => 'sum'])) ?>#top">По выручке</a>
          </div>
        </div>
        <?php foreach (['boxes' => $r['top_boxes'], 'sum' => $r['top_sum']] as $by => $list): $peak = $list ? max(array_column($list, $by)) : 0; ?>
        <div class="table-scroll" data-top-list="<?= $by ?>"<?= $by === $top ? '' : ' hidden' ?>>
          <table class="grid">
            <thead><tr><th class="rp-n">#</th><th>Товар</th><th class="right">Ящиков</th><th class="right">Пар</th><th class="right">Сумма</th><th class="opt"></th></tr></thead>
            <tbody>
            <?php foreach ($list as $n => $x): ?>
              <tr class="<?= !$x['exists'] || $x['hidden'] ? 'is-draft' : '' ?>">
                <td class="rp-n"><?= $n + 1 ?></td>
                <td>
                  <?php if ($x['exists']): ?>
                    <a href="/admin/products/<?= (int) $x['id'] ?>/"><b><?= e($x['name']) ?></b></a>
                    <small>
                      <?php if ($x['category'] !== ''): ?><?= e($x['category']) ?> · <?php endif; ?>
                      <?php if ($x['sizes'] !== ''): ?>р. <?= e($x['sizes']) ?> · <?php endif; ?>
                      <?php if ($x['box_qty'] > 1): ?><?= (int) $x['box_qty'] ?> пар в ящике · <?php endif; ?>
                      <?= $fmt($x['orders']) ?> <?= $ordersWord($x['orders']) ?>
                      · <a href="<?= e($x['url']) ?>" target="_blank" rel="noopener">на сайте ↗</a><?= $x['hidden'] ? ' · <span class="pill">скрыт</span>' : '' ?>
                    </small>
                  <?php else: ?>
                    <b><?= e($x['name']) ?></b>
                    <small><?= $fmt($x['orders']) ?> <?= $ordersWord($x['orders']) ?> · удалён из каталога</small>
                  <?php endif; ?>
                </td>
                <td class="right"><?= $by === 'boxes' ? '<b>' : '' ?><?= $x['guess'] ? '≈' : '' ?><?= $fmt($x['boxes']) ?><?= $by === 'boxes' ? '</b>' : '' ?></td>
                <td class="right"><?= $fmt($x['pairs']) ?></td>
                <td class="right"><?= $by === 'sum' ? '<b>' . e(price_format($x['sum'])) . '</b>' : e(price_format($x['sum'])) ?></td>
                <td class="opt rp-m"><?= $meter((float) $x[$by], (float) $peak) ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endforeach; ?>
        <div class="pad rp-foot"><p class="hint">Сумма — по позициям заказа (цена за пару × пары), без доставки и скидок.
          <?php if ($t['boxes_guess']): ?>«≈» — у заказов старого сайта размер ящика не сохранился: до 12 пар считаем одним ящиком, больше — по 8 пар.<?php endif; ?></p></div>
      </div>
      <?php endif; ?>

      <?php foreach (['categories' => ['Категории', 'Категория', 'Без категории'], 'brands' => ['Бренды', 'Бренд', 'Без бренда']] as $key => [$h, $col, $noneLabel]):
          $g = $r[$key];
          if (!$g || (!$g['rows'] && !$g['none'] && !$g['deleted'])) continue;
          $peak = $g['rows'] ? max(array_column($g['rows'], 'sum')) : 0; ?>
      <div class="card">
        <div class="card-hd"><h2><?= e($h) ?></h2><span class="muted">по выручке</span></div>
        <div class="table-scroll">
          <table class="grid">
            <thead><tr><th><?= e($col) ?></th><th class="right">Ящиков</th><th class="right">Пар</th><th class="right">Сумма</th><th class="opt"></th></tr></thead>
            <tbody>
            <?php foreach ($g['rows'] as $x): ?>
              <tr class="<?= $x['hidden'] ? 'is-draft' : '' ?>">
                <td>
                  <?php if ($x['admin'] !== ''): ?><a href="<?= e($x['admin']) ?>"><b><?= e($x['name']) ?></b></a><?php else: ?><b><?= e($x['name']) ?></b><?php endif; ?>
                  <small>
                    <?php if ($x['parent'] !== ''): ?><?= e(nice_case($x['parent'])) ?> · <?php endif; ?>
                    <?= e($productsWord($x['products'])) ?><?php if ($x['by_name']): ?>, <?= $x['by_name'] === $x['products'] ? 'все удалены' : $fmt($x['by_name']) . ' из них удалены' ?><?php endif; ?>
                    · <?= e($share($x['sum'], $g['total'])) ?> выручки
                    <?php if ($x['url'] !== '' && !$x['hidden']): ?> · <a href="<?= e($x['url']) ?>" target="_blank" rel="noopener">на сайте ↗</a><?php endif; ?>
                    <?= $x['hidden'] ? ' · скрыт' : '' ?>
                  </small>
                </td>
                <td class="right"><?= $fmt($x['boxes']) ?></td>
                <td class="right"><?= $fmt($x['pairs']) ?></td>
                <td class="right"><b><?= e(price_format($x['sum'])) ?></b></td>
                <td class="opt rp-m"><?= $meter($x['sum'], (float) $peak) ?></td>
              </tr>
            <?php endforeach; ?>
            <?php foreach (['none' => $noneLabel, 'deleted' => $key === 'brands' ? 'Бренд не определён (товар удалён)' : 'Товары удалены из каталога'] as $k => $label): if (empty($g[$k])) continue; $x = $g[$k]; ?>
              <tr class="is-draft">
                <td><b><?= e($label) ?></b><small><?= e($productsWord($x['products'])) ?> · <?= e($share($x['sum'], $g['total'])) ?> выручки</small></td>
                <td class="right"><?= $fmt($x['boxes']) ?></td>
                <td class="right"><?= $fmt($x['pairs']) ?></td>
                <td class="right"><?= e(price_format($x['sum'])) ?></td>
                <td class="opt"></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php if ($g['more'] || $g['deleted'] || !empty($g['by_name'])): ?>
          <div class="pad rp-foot"><p class="hint">
            <?php if ($g['more']): ?>Показаны <?= count($g['rows']) ?> лучших, ещё <?= $fmt($g['more']) ?> — с меньшей выручкой.<?php endif; ?>
            <?php if ($key === 'categories' && $g['deleted']): ?>Категория берётся из карточки товара; у товаров, удалённых ещё на старом сайте, её уже не узнать — смотрите «Виды обуви».<?php endif; ?>
            <?php if ($key === 'brands' && !empty($g['by_name'])): ?>Бренд удалённого товара определён по его названию («Кроссовки Jong•Golf B30113-0» → Jong•Golf).<?php endif; ?>
          </p></div>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>

      <?php if ($r['types']): $peak = max(array_column($r['types'], 'sum')); ?>
      <div class="card">
        <div class="card-hd"><h2>Виды обуви</h2><span class="muted">по названию товара или категории</span></div>
        <div class="table-scroll">
          <table class="grid">
            <thead><tr><th>Вид</th><th class="right">Ящиков</th><th class="right">Пар</th><th class="right">Сумма</th><th class="opt"></th></tr></thead>
            <tbody>
            <?php foreach ($r['types'] as $x): ?>
              <tr class="<?= empty($x['rest']) ? '' : 'is-draft' ?>">
                <td><b><?= e($x['name']) ?></b><small><?= e($productsWord($x['products'])) ?></small></td>
                <td class="right"><?= $fmt($x['boxes']) ?></td>
                <td class="right"><?= $fmt($x['pairs']) ?></td>
                <td class="right"><b><?= e(price_format($x['sum'])) ?></b></td>
                <td class="opt rp-m"><?= empty($x['rest']) ? $meter($x['sum'], (float) $peak) : '' ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
      <?php endif; ?>

      <?php if ($r['customers']): $peak = max(array_column($r['customers'], 'sum')); ?>
      <div class="card">
        <div class="card-hd"><h2>Лучшие клиенты</h2><a href="/admin/customers/">Все клиенты</a></div>
        <div class="table-scroll">
          <table class="grid">
            <thead><tr><th class="rp-n">#</th><th>Клиент</th><th class="right">Заказов</th><th class="right">Ящиков</th><th class="right">Сумма</th><th class="opt"></th></tr></thead>
            <tbody>
            <?php foreach ($r['customers'] as $n => $x): ?>
              <tr>
                <td class="rp-n"><?= $n + 1 ?></td>
                <td>
                  <?php if ($x['exists']): ?><a href="/admin/customers/<?= (int) $x['id'] ?>/"><b><?= e($x['name']) ?></b></a><?php else: ?><b><?= e($x['name']) ?></b><?php endif; ?>
                  <small><?= e(implode(' · ', array_filter([$x['company'], $x['city'], $x['phone']]))) ?><?= ($x['company'] . $x['city'] . $x['phone']) !== '' ? ' · ' : '' ?>последний заказ <?= e($dmy($x['last_at'])) ?></small>
                </td>
                <td class="right"><?php if ($x['phone'] !== ''): ?><a href="<?= e($ordersUrl(['q' => $x['phone']])) ?>"><?= $fmt($x['orders']) ?></a><?php else: ?><?= $fmt($x['orders']) ?><?php endif; ?></td>
                <td class="right"><?= $fmt($x['boxes']) ?></td>
                <td class="right"><b><?= e(price_format($x['sum'])) ?></b></td>
                <td class="opt rp-m"><?= $meter($x['sum'], (float) $peak) ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
      <?php endif; ?>
    </div>

    <aside>
      <?php if ($t['orders']): ?>
      <div class="card">
        <h2>Из чего сумма</h2>
        <dl class="facts">
          <dt>Товары</dt><dd><?= e(price_format($t['subtotal'])) ?></dd>
          <?php if ($t['discount'] > 0): ?><dt>Скидки</dt><dd>&minus;<?= e(price_format($t['discount'])) ?></dd><?php endif; ?>
          <dt>Доставка</dt><dd><?= e(price_format($t['shipping'])) ?></dd>
          <dt>Итого</dt><dd><b><?= e(price_format($t['revenue'])) ?></b></dd>
          <?php if ($t['excluded']): ?><dt>Не учтено</dt><dd class="muted"><?= $fmt($t['excluded']) ?> <?= $ordersWord($t['excluded']) ?> на <?= e(price_format($t['excluded_sum'])) ?></dd><?php endif; ?>
        </dl>
      </div>
      <?php endif; ?>

      <div class="card">
        <h2>Статусы заказов</h2>
        <dl class="facts rp-facts">
          <?php foreach ($r['statuses'] as $st => $s): ?>
            <dt><span class="st st-<?= e($st) ?>"><?= e(Reports::STATUSES[$st] ?? $st) ?></span></dt>
            <dd><a href="<?= e($ordersUrl(['status' => isset(Reports::STATUSES[$st]) ? $st : null])) ?>"><?= $fmt($s['n']) ?></a><small><?= e(price_format($s['sum'])) ?></small></dd>
          <?php endforeach; ?>
        </dl>
      </div>

      <?php foreach (['shipping' => 'Доставка', 'payment' => 'Оплата', 'sources' => 'Источники заказов'] as $key => $h): if (!$r[$key]) continue; $peak = max(array_column($r[$key], 'n')); ?>
      <div class="card">
        <div class="card-hd"><h2><?= e($h) ?></h2><span class="muted">заказов</span></div>
        <table class="grid rp-mini">
          <tbody>
          <?php foreach ($r[$key] as $x): ?>
            <tr>
              <td><b><?= e($x['label']) ?></b><?= $meter((float) $x['n'], (float) $peak) ?><small><?= e(price_format($x['sum'])) ?></small></td>
              <td class="right">
                <?php if ($key === 'sources' && isset(Reports::SOURCES[$x['key']])): ?><a href="<?= e($ordersUrl(['source' => $x['key']])) ?>"><b><?= $fmt($x['n']) ?></b></a><?php else: ?><b><?= $fmt($x['n']) ?></b><?php endif; ?>
                <small><?= e($share($x['n'], $t['orders'])) ?></small>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endforeach; ?>

      <?php if (!empty($r['coupons'])): $peak = max(array_column($r['coupons'], 'n')); ?>
      <div class="card">
        <div class="card-hd"><h2>Промокоды</h2><a href="/admin/coupons/">Все</a></div>
        <table class="grid rp-mini">
          <tbody>
          <?php foreach ($r['coupons'] as $x): ?>
            <tr>
              <td>
                <?php if ($x['coupon_id'] > 0): ?><a href="/admin/coupons/<?= (int) $x['coupon_id'] ?>/"><b><?= e($x['code']) ?></b></a><?php else: ?><b><?= e($x['code']) ?></b> <span class="pill">удалён</span><?php endif; ?>
                <?= $meter((float) $x['n'], (float) $peak) ?>
                <small>скидка <?= e(price_format($x['discount'])) ?> · заказы на <?= e(price_format($x['sum'])) ?></small>
              </td>
              <td class="right"><b><?= $fmt($x['n']) ?></b><small><?= e($share($x['n'], $t['orders'])) ?></small></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>

      <div class="card">
        <h2>Экспорт</h2>
        <p class="hint">За выбранный период, для Excel (UTF-8, разделитель «;»).</p>
        <div class="rp-export">
          <a class="btn block" href="<?= e($exportUrl('orders')) ?>">Заказы — строка на заказ</a>
          <a class="btn block" href="<?= e($exportUrl('summary')) ?>">Сводка отчёта</a>
        </div>
      </div>
    </aside>
  </div>
<?php endif; ?>
