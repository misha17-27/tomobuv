<?php
/**
 * Заявки с сайта.
 * @var array $rows @var App\Core\Paginator $pg @var int $total @var string $type @var string $status @var string $q
 * @var array $counts @var callable $newBy @var array $products @var array $customers @var array $statuses
 */
use App\Controllers\Admin\OrdersController as O;
use App\Controllers\Admin\RequestsController as R;

$link = static function (array $ch) use ($type, $status, $q): string {
    $p = array_filter(array_merge(['type' => $type, 'status' => $status, 'q' => $q], $ch), static fn($v) => $v !== '' && $v !== null);
    return '/admin/requests/' . ($p ? '?' . http_build_query($p) : '');
};
$tabTotal = static function (string $t) use ($counts): int {
    return $t === '' ? array_sum(array_map('array_sum', $counts)) : array_sum($counts[$t] ?? []);
};
?>
<nav class="tabs" aria-label="Типы заявок">
  <a href="<?= e($link(['type' => ''])) ?>" class="<?= $type === '' ? 'on' : '' ?>">Все <i class="<?= $newBy('') ? 'sl-hot-cnt' : '' ?>" title="<?= $newBy('') ? 'Новых' : 'Всего' ?>"><?= $newBy('') ?: $tabTotal('') ?></i></a>
  <?php foreach (R::TYPES as $t => $label): ?>
    <a href="<?= e($link(['type' => $t])) ?>" class="<?= $type === $t ? 'on' : '' ?>"><?= e($label) ?>
      <i class="<?= $newBy($t) ? 'sl-hot-cnt' : '' ?>" title="<?= $newBy($t) ? 'Новых' : 'Всего' ?>"><?= $newBy($t) ?: $tabTotal($t) ?></i></a>
  <?php endforeach; ?>
</nav>

<form class="toolbar sl-filters" method="get" action="/admin/requests/" role="search">
  <?php if ($type !== ''): ?><input type="hidden" name="type" value="<?= e($type) ?>"><?php endif; ?>
  <?php if ($status !== ''): ?><input type="hidden" name="status" value="<?= e($status) ?>"><?php endif; ?>
  <label class="sl-q"><span class="sl-sr">Поиск заявки</span><input type="search" name="q" value="<?= e($q) ?>" placeholder="Телефон, имя, e-mail или текст"></label>
  <button class="btn btn-p" type="submit"><?= icon('search') ?> Найти</button>
  <?php if ($q !== ''): ?><a class="btn" href="<?= e($link(['q' => ''])) ?>">Сбросить</a><?php endif; ?>
</form>
<div class="sl-chips">
  <a class="chip<?= $status === '' ? ' on' : '' ?>" href="<?= e($link(['status' => ''])) ?>">Все статусы</a>
  <?php foreach ($statuses as $k => $label): ?><a class="chip<?= $status === $k ? ' on' : '' ?>" href="<?= e($link(['status' => $k])) ?>"><?= e($label) ?></a><?php endforeach; ?>
</div>
<p class="sl-found muted">Найдено: <b><?= number_format($total, 0, '', ' ') ?></b><?= $pg->pages > 1 ? ' · страница ' . $pg->page . ' из ' . $pg->pages : '' ?></p>

<?php if (!$rows): ?>
  <div class="card empty-card"><h2><?= $status === 'new' ? 'Новых заявок нет' : 'Заявок нет' ?></h2><p>Заявки с сайта (обратный звонок, «купить в 1 клик», подписка, форма контактов) появятся здесь.</p></div>
<?php else: ?>
<div class="tblwrap">
<table class="tbl sl-stack sl-req">
  <thead><tr><th>Дата</th><th>Заявка</th><th>Клиент</th><th>Сообщение и товар</th><th class="right">Действия</th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r):
    $rid = (int) $r['id']; $isNew = $r['status'] === 'new'; $ts = strtotime((string) $r['created_at']);
    $p = $r['product_id'] ? ($products[(int) $r['product_id']] ?? null) : null;
    $cust = $r['phone'] ? ($customers[$r['phone']] ?? null) : null; ?>
    <tr class="<?= $isNew ? 'unread' : 'sl-req-done' ?>" data-req-row="<?= $rid ?>"<?= $status !== '' ? ' data-req-filtered' : '' ?>>
      <td data-l="Дата" class="nowrap"><?= e(date('d.m.Y', $ts)) ?><small><?= e(date('H:i', $ts)) ?></small></td>
      <td data-l="Заявка"><b class="sl-req-type"><?= e(R::TYPES[$r['type']] ?? $r['type']) ?></b>
        <span class="st <?= $isNew ? 'st-new' : 'st-completed' ?>" data-req-st><?= $isNew ? 'Новая' : 'Обработана' ?></span></td>
      <td data-l="Клиент" class="sl-req-who">
        <?= $r['name'] ? '<b>' . e($r['name']) . '</b>' : '' ?>
        <?php if ($r['phone']): ?><a class="nowrap" href="<?= e(O::tel($r['phone'])) ?>"><?= e(O::phone($r['phone'])) ?></a><?php endif; ?>
        <?php if ($r['email']): ?><a href="mailto:<?= e($r['email']) ?>"><?= e($r['email']) ?></a><?php endif; ?>
        <?php if ($cust): ?><a class="muted" href="/admin/customers/<?= (int) $cust['id'] ?>/">клиент #<?= (int) $cust['id'] ?> · заказов: <?= (int) $cust['orders_count'] ?></a><?php endif; ?>
        <?php if (!$r['name'] && !$r['phone'] && !$r['email']): ?><span class="muted">—</span><?php endif; ?>
      </td>
      <td data-l="Сообщение и товар" class="sl-full sl-req-text">
        <?php if ($r['text']): ?><div><?= nl2br(e(str_limit($r['text'], 600))) ?></div><?php endif; ?>
        <?php if ($p): ?>
          <div class="sl-prod"><img src="<?= e($p['img']) ?>" alt="" width="40" height="40" loading="lazy">
            <span><a href="/product/<?= e($p['url']) ?>/" target="_blank" rel="noopener"><?= e(str_limit($p['name'], 70)) ?></a>
              <small><?= e(price_format($p['price'])) ?> × <?= (int) $p['box_qty'] ?> пар<?= (int) $p['status'] ? '' : ' · скрыт' ?> · <a href="/admin/products/<?= (int) $p['id'] ?>/">в админке</a></small></span></div>
        <?php elseif ($r['product_id']): ?><span class="muted">товар #<?= (int) $r['product_id'] ?> удалён</span><?php endif; ?>
        <?php if (!$r['text'] && !$r['product_id']): ?><span class="muted">—</span><?php endif; ?>
      </td>
      <td data-l="Действия" class="sl-full">
        <div class="sl-btns">
          <?php if ($r['phone']): ?><a class="btn btn-sm sl-call" href="<?= e(O::tel($r['phone'])) ?>" aria-label="Позвонить <?= e(O::phone($r['phone'])) ?>"><?= icon('phone') ?> Позвонить</a><?php endif; ?>
          <?php if (in_array($r['type'], ['quickorder', 'callback'], true)): ?><a class="btn btn-sm" href="/admin/orders/new/?request=<?= $rid ?>" title="Оформить заказ по заявке"><?= icon('cart') ?> Заказ</a><?php endif; ?>
          <button type="button" class="btn btn-sm btn-p<?= $isNew ? '' : ' hidden' ?>" data-req="done"><?= icon('check') ?> Обработана</button>
          <button type="button" class="btn btn-sm<?= $isNew ? ' hidden' : '' ?>" data-req="new">В новые</button>
          <button type="button" class="btn btn-sm btn-d" data-req="delete" aria-label="Удалить заявку"><?= icon('trash') ?></button>
        </div>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?= $pg->html() ?>
<?php endif; ?>
