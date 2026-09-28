<?php
/**
 * Список промокодов: сводка за 30 дней, вкладки по состоянию, поиск, массовые действия.
 * @var array $coupons @var array $counts @var string $status @var string $sort @var string $q
 * @var App\Core\Paginator $pg @var int $total @var array $given @var array $names @var array $month
 * @var bool $siteOn @var bool $canEdit @var array $bulk
 */
use App\Controllers\Admin\BaseController;
use App\Controllers\Admin\CouponsController as C;
use App\Services\Coupons;

$fmt = static fn($n) => number_format((float) $n, 0, '', ' ');
$tabUrl = static function (string $st) use ($q, $sort): string {
    $p = array_filter(['status' => $st !== 'active' ? $st : '', 'q' => $q, 'sort' => $sort !== 'new' ? $sort : ''], static fn($v) => $v !== '');
    return '/admin/coupons/' . ($p ? '?' . http_build_query($p) : '');
};
$pill = ['active' => 'ok', 'scheduled' => 'confirmed', 'expired' => 'refunded', 'exhausted' => 'invoiced', 'off' => 'cancelled'];
$now = time();
?>
<div class="stats">
  <a class="stat hot" href="/admin/coupons/"><span><?= $fmt($counts['active']) ?></span>Действующих промокодов</a>
  <div class="stat"><span><?= $fmt($month['n']) ?></span>Применений за 30 дней</div>
  <div class="stat"><span><?= e(price_format($month['disc'], false)) ?></span>Скидок за 30 дней, грн</div>
  <div class="stat"><span><?= e(price_format($month['sum'], false)) ?></span>Заказы с промокодами за 30 дней, грн</div>
</div>

<?php if (!$siteOn): ?>
  <div class="flash bad cp-site-off">
    <span>Приём промокодов на сайте выключен — покупатели не смогут применить ни один код, даже действующий.</span>
    <?php if ($canEdit): ?>
      <form method="post" action="/admin/coupons/site/"><?= BaseController::tokenField() ?><input type="hidden" name="on" value="1"><button class="btn btn-sm btn-p" type="submit">Включить приём</button></form>
    <?php endif; ?>
  </div>
<?php endif; ?>

<nav class="tabs" aria-label="Состояние промокодов">
  <?php foreach (C::TABS as $k => $label): ?>
    <a href="<?= e($tabUrl($k)) ?>" class="<?= $status === $k ? 'on' : '' ?>"><?= e($label) ?> <i><?= $fmt($counts[$k]) ?></i></a>
  <?php endforeach; ?>
</nav>

<form class="filter-bar" method="get" action="/admin/coupons/" role="search">
  <?php if ($status !== 'active'): ?><input type="hidden" name="status" value="<?= e($status) ?>"><?php endif; ?>
  <input type="search" name="q" value="<?= e($q) ?>" placeholder="Код или комментарий" aria-label="Поиск промокода" maxlength="64">
  <select name="sort" aria-label="Сортировка" onchange="this.form.submit()">
    <?php foreach (C::SORTS as $k => [$label]): ?><option value="<?= e($k) ?>"<?= $sort === $k ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
  </select>
  <button class="btn btn-p" type="submit">Найти</button>
  <?php if ($q !== ''): ?><a class="btn" href="<?= e('/admin/coupons/' . ($status !== 'active' ? '?status=' . $status : '')) ?>">Сбросить</a><?php endif; ?>
  <span class="cp-found muted">Найдено: <b><?= $fmt($total) ?></b></span>
  <?php if ($siteOn): ?>
    <span class="cp-site">
      <span class="seo-dot ok"></span> Приём на сайте включён
      <?php if ($canEdit): ?><button class="btn btn-sm" type="submit" form="cp-site-off" data-confirm="Выключить приём промокодов на сайте? Покупатели не смогут применить ни один код.">Выключить</button><?php endif; ?>
    </span>
  <?php endif; ?>
</form>
<?php if ($siteOn && $canEdit): ?>
  <form id="cp-site-off" method="post" action="/admin/coupons/site/" hidden><?= BaseController::tokenField() ?><input type="hidden" name="on" value="0"></form>
<?php endif; ?>

<?php if (!$coupons): ?>
  <div class="card">
    <div class="empty-card">
      <?php if ($q !== ''): ?>
        <h2>Ничего не найдено</h2>
        <p>Нет промокода с «<?= e($q) ?>» в коде или комментарии<?= $status !== 'all' ? ' на этой вкладке' : '' ?>.</p>
        <a class="btn" href="/admin/coupons/?status=all&amp;q=<?= e(rawurlencode($q)) ?>">Искать среди всех</a>
      <?php elseif ($counts['all'] > 0): ?>
        <h2><?= e(['active' => 'Действующих промокодов нет', 'expired' => 'Истёкших промокодов нет', 'off' => 'Выключенных промокодов нет'][$status] ?? 'Промокодов нет') ?></h2>
        <p>Всего промокодов: <?= $fmt($counts['all']) ?>.</p>
        <a class="btn" href="/admin/coupons/?status=all">Показать все</a>
      <?php else: ?>
        <h2>Промокодов пока нет</h2>
        <p>Промокод даёт скидку процентом или суммой в гривнах. Его можно ограничить минимальной суммой или числом ящиков, сроком, количеством применений, категориями и брендами.</p>
        <?php if ($canEdit): ?><a class="btn btn-p" href="/admin/coupons/new/">Создать первый промокод</a><?php endif; ?>
      <?php endif; ?>
    </div>
  </div>
<?php else: ?>
<form class="card flush" method="post" action="/admin/coupons/bulk/" data-cp-bulk>
  <?= BaseController::tokenField() ?>
  <?php if ($canEdit): ?>
  <div class="bulk-bar">
    <select name="action" aria-label="Действие с отмеченными">
      <option value="">Действие с отмеченными…</option>
      <?php foreach ($bulk as $k => $label): ?><option value="<?= e($k) ?>"><?= e($label) ?></option><?php endforeach; ?>
    </select>
    <button class="btn btn-sm" type="submit" disabled data-cp-apply data-confirm="Применить действие к отмеченным промокодам?">Применить</button>
    <span class="muted" data-cp-picked>Отметьте промокоды галочками</span>
  </div>
  <?php endif; ?>
  <div class="table-scroll">
  <table class="grid cp-list">
    <thead><tr>
      <?php if ($canEdit): ?><th class="tick"><input type="checkbox" data-cp-all aria-label="Отметить все"></th><?php endif; ?>
      <th>Промокод</th><th>Скидка</th><th class="opt">Условия</th><th class="opt">На товары</th><th>Применений</th><th>Срок</th><th>Статус</th><th></th>
    </tr></thead>
    <tbody>
    <?php foreach ($coupons as $c):
      $st = Coupons::state($c, $now);
      $cid = (int) $c['id'];
      $cond = [];
      if ($c['min_sum'] > 0) $cond[] = 'от ' . price_format($c['min_sum']);
      if ($c['min_boxes'] > 0) $cond[] = 'от ' . $c['min_boxes'] . ' ящ.';
      if ($c['per_customer_limit'] > 0) $cond[] = $c['per_customer_limit'] . ' раз на клиента';
      $scope = [];
      $full = [];
      foreach (['category' => ['категория', 'категории', 'категорий'], 'brand' => ['бренд', 'бренда', 'брендов'], 'product' => ['товар', 'товара', 'товаров']] as $k => $w) {
          $ids = $c[$k . '_ids'];
          if (!$ids) continue;
          $list = array_map(static fn($id) => $names[$k][$id] ?? '#' . $id, $ids);
          $scope[] = count($ids) === 1 ? $list[0] : count($ids) . ' ' . plural(count($ids), ...$w);
          $full[] = implode(', ', $list);
      }
      $exp = $c['expires_at'] ? strtotime($c['expires_at']) : null;
      $start = $c['starts_at'] ? strtotime($c['starts_at']) : null;
    ?>
      <tr class="<?= $st === 'off' ? 'is-draft' : '' ?>">
        <?php if ($canEdit): ?><td class="tick"><input type="checkbox" name="ids[]" value="<?= $cid ?>" aria-label="Отметить <?= e($c['code']) ?>"></td><?php endif; ?>
        <td>
          <a class="cp-code" href="/admin/coupons/<?= $cid ?>/"><?= e($c['code']) ?></a>
          <?php if ($c['comment']): ?><small><?= e(str_limit($c['comment'], 70)) ?></small><?php endif; ?>
        </td>
        <td class="nowrap"><b><?= e(Coupons::label($c)) ?></b><?php if ($c['type'] === 'percent' && $c['max_discount'] > 0): ?><small>не больше <?= e(price_format($c['max_discount'])) ?></small><?php endif; ?></td>
        <td class="opt"><?= $cond ? e(implode(' · ', $cond)) : '<span class="muted">—</span>' ?></td>
        <td class="opt"><?php if ($scope): ?><span title="<?= e(str_limit(implode(' · ', $full), 400)) ?>"><?= e(str_limit(implode(' + ', $scope), 60)) ?></span><?php else: ?><span class="muted">Все товары</span><?php endif; ?></td>
        <td class="nowrap">
          <a href="/admin/coupons/<?= $cid ?>/usages/"><?= $fmt($c['used']) ?><?= $c['usage_limit'] > 0 ? ' / ' . $fmt($c['usage_limit']) : '' ?></a>
          <?php if ($c['usage_limit'] > 0): ?><span class="meter cp-meter"><i style="width:<?= min(100, (int) round($c['used'] / $c['usage_limit'] * 100)) ?>%"></i></span><?php endif; ?>
          <?php if (!empty($given[$cid]) && (float) $given[$cid] > 0): ?><small>−<?= e(price_format($given[$cid])) ?></small><?php endif; ?>
        </td>
        <td class="nowrap">
          <?php if ($exp): ?>до <?= e(date('d.m.Y', $exp)) ?>
            <?php if ($exp >= $now): $days = (int) ceil(($exp - $now) / 86400); ?><small><?= $days <= 1 ? 'последний день' : 'ещё ' . $days . ' ' . plural($days, 'день', 'дня', 'дней') ?></small><?php endif; ?>
          <?php else: ?><span class="muted">Бессрочно</span><?php endif; ?>
          <?php if ($start && $start > $now): ?><small>с <?= e(date('d.m.Y', $start)) ?></small><?php endif; ?>
        </td>
        <td><span class="pill <?= $pill[$st] ?>"><?= e(Coupons::STATES[$st]) ?></span></td>
        <td class="right"><a href="/admin/coupons/<?= $cid ?>/"><?= $canEdit ? 'Изменить' : 'Открыть' ?></a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</form>
<?= $pg->html() ?>
<?php endif; ?>
