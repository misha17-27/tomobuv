<?php
/**
 * Список клиентов.
 * @var array $rows @var App\Core\Paginator $pg @var int $total @var string $q @var string $filter @var string $sort @var array $filters
 */
use App\Controllers\Admin\CustomersController as C;
use App\Controllers\Admin\OrdersController as O;

$link = static function (array $ch) use ($q, $filter, $sort): string {
    $p = array_merge(['q' => $q, 'filter' => $filter !== 'all' ? $filter : '', 'sort' => $sort !== 'new' ? $sort : ''], $ch);
    $p = array_filter($p, static fn($v) => $v !== '' && $v !== null);
    return '/admin/customers/' . ($p ? '?' . http_build_query($p) : '');
};
$sorts = ['new' => 'Сначала новые', 'spent' => 'По сумме покупок', 'orders' => 'По числу заказов', 'login' => 'По последнему входу', 'name' => 'По имени'];
?>
<nav class="tabs" aria-label="Фильтр клиентов">
  <?php foreach ($filters as $k => $label): ?><a class="<?= $filter === $k ? 'on' : '' ?>" href="<?= e($link(['filter' => $k === 'all' ? '' : $k, 'page' => null])) ?>"><?= e($label) ?></a><?php endforeach; ?>
</nav>
<form class="toolbar sl-filters" method="get" action="/admin/customers/" role="search">
  <label class="sl-q"><span class="sl-sr">Поиск клиента</span>
    <input type="search" name="q" value="<?= e($q) ?>" placeholder="Имя, телефон, e-mail, город или ID"></label>
  <?php if ($filter !== 'all'): ?><input type="hidden" name="filter" value="<?= e($filter) ?>"><?php endif; ?>
  <select name="sort" aria-label="Сортировка">
    <?php foreach ($sorts as $k => $label): ?><option value="<?= e($k) ?>"<?= $sort === $k ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
  </select>
  <button class="btn btn-p" type="submit"><?= icon('search') ?> Найти</button>
  <?php if ($q !== ''): ?><a class="btn" href="<?= e($link(['q' => ''])) ?>">Сбросить</a><?php endif; ?>
</form>
<p class="sl-found muted">Найдено: <b><?= number_format($total, 0, '', ' ') ?></b><?= $pg->pages > 1 ? ' · страница ' . $pg->page . ' из ' . $pg->pages : '' ?></p>

<?php if (!$rows): ?>
  <div class="card empty-card"><h2>Клиенты не найдены</h2><p>Измените условия поиска.</p></div>
<?php else: ?>
<div class="tblwrap">
<table class="tbl sl-stack sl-cust">
  <thead><tr>
    <th><a class="<?= $sort === 'name' ? 'sorted' : '' ?>" href="<?= e($link(['sort' => 'name', 'page' => null])) ?>">Клиент</a></th><th>Телефон</th><th>E-mail</th><th>Город</th>
    <th class="num"><a class="<?= $sort === 'orders' ? 'sorted' : '' ?>" href="<?= e($link(['sort' => 'orders', 'page' => null])) ?>">Заказов</a></th>
    <th class="num"><a class="<?= $sort === 'spent' ? 'sorted' : '' ?>" href="<?= e($link(['sort' => 'spent', 'page' => null])) ?>">Сумма покупок</a></th>
    <th><a class="<?= $sort === 'new' ? 'sorted' : '' ?>" href="<?= e($link(['sort' => '', 'page' => null])) ?>">Регистрация</a></th>
  </tr></thead>
  <tbody>
  <?php foreach ($rows as $r): $cid = (int) $r['id']; ?>
    <tr class="<?= (int) $r['status'] ? '' : 'is-draft' ?>">
      <td data-l="Клиент" class="sl-full">
        <a href="/admin/customers/<?= $cid ?>/"><b><?= e($r['name'] !== '' ? $r['name'] : 'Клиент #' . $cid) ?></b></a>
        <?php if ($r['role'] !== 'customer'): ?> <span class="sl-role sl-role-<?= e($r['role']) ?>"><?= e(C::ROLES[$r['role']] ?? $r['role']) ?></span><?php endif; ?>
        <?php if (!(int) $r['status']): ?> <span class="sl-role sl-blocked">Заблокирован</span><?php endif; ?>
        <small>#<?= $cid ?><?= $r['company'] !== '' ? ' · ' . e($r['company']) : '' ?></small>
      </td>
      <td data-l="Телефон" class="nowrap"><?php if ($r['phone']): ?><a href="<?= e(O::tel($r['phone'])) ?>"><?= e(O::phone($r['phone'])) ?></a><?php else: ?>—<?php endif; ?></td>
      <td data-l="E-mail" class="sl-mail"><?php if ($r['email']): ?><a href="mailto:<?= e($r['email']) ?>"><?= e($r['email']) ?></a><?php else: ?>—<?php endif; ?></td>
      <td data-l="Город"><?= e($r['city'] ? str_limit($r['city'], 40) : '—') ?></td>
      <td data-l="Заказов" class="num"><?php if ((int) $r['orders_count']): ?><a href="/admin/customers/<?= $cid ?>/#orders"><?= (int) $r['orders_count'] ?></a><?php else: ?>0<?php endif; ?></td>
      <td data-l="Сумма покупок" class="num"><?= e(price_format($r['total_spent'])) ?></td>
      <td data-l="Регистрация" class="nowrap"><?= e(date('d.m.Y', strtotime((string) $r['created_at']))) ?><?php if ($r['last_login_at']): ?><small title="Последний вход">вход <?= e(date('d.m.Y', strtotime((string) $r['last_login_at']))) ?></small><?php endif; ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?= $pg->html() ?>
<?php endif; ?>
