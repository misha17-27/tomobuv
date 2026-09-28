<?php
/**
 * Список заказов.
 * @var array $f @var array $counts @var int $all @var int $total @var array $orders @var App\Core\Paginator $pg
 * @var array $shipping @var array $payment
 */
use App\Controllers\Admin\OrdersController as O;

$tabUrl = static function (string $status) use ($f): string {
    $q = array_filter(['status' => $status, 'q' => $f['q'], 'from' => $f['from'], 'to' => $f['to'], 'source' => $f['source'], 'lang' => $f['lang'],
        'sort' => $f['sort'] !== 'new' ? $f['sort'] : ''], static fn($v) => $v !== '');
    return '/admin/orders/' . ($q ? '?' . http_build_query($q) : '');
};
$filtered = $f['q'] !== '' || $f['from'] !== '' || $f['to'] !== '' || $f['source'] !== '' || $f['lang'] !== '';
?>
<nav class="tabs" aria-label="Статусы заказов">
  <a href="<?= e($tabUrl('')) ?>" class="<?= $f['status'] === '' ? 'on' : '' ?>">Все <i><?= number_format($all, 0, "", " ") ?></i></a>
  <?php foreach (O::TABS as $st => $label): ?>
    <a href="<?= e($tabUrl($st)) ?>" class="<?= $f['status'] === $st ? 'on' : '' ?>"><?= e($label) ?>
      <i class="<?= $st === 'new' && !empty($counts['new']) ? 'sl-hot-cnt' : '' ?>"><?= number_format((int) ($counts[$st] ?? 0), 0, "", " ") ?></i></a>
  <?php endforeach; ?>
</nav>

<form class="toolbar sl-filters" method="get" action="/admin/orders/" role="search">
  <?php if ($f['status'] !== ''): ?><input type="hidden" name="status" value="<?= e($f['status']) ?>"><?php endif; ?>
  <label class="sl-q"><span class="sl-sr">Поиск заказа</span>
    <input type="search" name="q" value="<?= e($f['q']) ?>" placeholder="№ заказа, телефон, имя или e-mail"></label>
  <label class="sl-d"><span>с</span><input type="date" name="from" value="<?= e($f['from']) ?>" aria-label="Период с"></label>
  <label class="sl-d"><span>по</span><input type="date" name="to" value="<?= e($f['to']) ?>" aria-label="Период по"></label>
  <select name="source" aria-label="Источник заказа">
    <option value="">Все источники</option>
    <?php foreach (O::SOURCES as $k => $label): ?><option value="<?= e($k) ?>"<?= $f['source'] === $k ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
  </select>
  <select name="lang" aria-label="Язык оформления">
    <option value="">RU и UA</option>
    <?php foreach (\App\Core\Lang::NAMES as $k => $label): ?><option value="<?= e($k) ?>"<?= $f['lang'] === $k ? ' selected' : '' ?>>Только <?= e($label) ?></option><?php endforeach; ?>
  </select>
  <select name="sort" aria-label="Сортировка">
    <option value="new"<?= $f['sort'] === 'new' ? ' selected' : '' ?>>Сначала новые</option>
    <option value="old"<?= $f['sort'] === 'old' ? ' selected' : '' ?>>Сначала старые</option>
    <option value="sum"<?= $f['sort'] === 'sum' ? ' selected' : '' ?>>По сумме</option>
  </select>
  <button class="btn btn-p" type="submit"><?= icon('search') ?> Найти</button>
  <?php if ($filtered): ?><a class="btn" href="<?= e('/admin/orders/' . ($f['status'] !== '' ? '?status=' . $f['status'] : '')) ?>">Сбросить</a><?php endif; ?>
</form>

<p class="sl-found muted">Найдено: <b><?= number_format($total, 0, '', ' ') ?></b> <?= plural($total, 'заказ', 'заказа', 'заказов') ?><?= $pg->pages > 1 ? ' · страница ' . $pg->page . ' из ' . $pg->pages : '' ?></p>

<?php if (!$orders): ?>
  <div class="card empty-card"><h2>Заказов не найдено</h2><p>Измените условия поиска или период.</p><?php if ($filtered): ?><a class="btn" href="/admin/orders/">Показать все заказы</a><?php endif; ?></div>
<?php else: ?>
<div class="tblwrap">
<table class="tbl sl-orders sl-stack">
  <thead><tr>
    <th>Номер</th><th>Дата</th><th>Клиент</th><th>Телефон</th><th class="num">Ящ. / пар</th><th class="num">Сумма</th>
    <th>Доставка</th><th>Оплата</th><th>Статус</th><th>Источник</th>
  </tr></thead>
  <tbody>
  <?php foreach ($orders as $o):
    $oid = (int) $o['id']; $num = O::number($oid); $ts = strtotime((string) $o['created_at']); ?>
    <tr class="<?= $o['status'] === 'new' ? 'unread' : '' ?>">
      <td data-l="Номер"><a class="sl-num" href="/admin/orders/<?= $oid ?>/"><?= e($num) ?></a></td>
      <td data-l="Дата" class="nowrap"><?= e(date('d.m.Y', $ts)) ?><br><span class="muted"><?= e(date('H:i', $ts)) ?></span></td>
      <td data-l="Клиент">
        <?php if ($o['customer_id']): ?><a href="/admin/customers/<?= (int) $o['customer_id'] ?>/"><?= e($o['name'] !== '' ? $o['name'] : 'Клиент #' . $o['customer_id']) ?></a>
        <?php else: ?><?= e($o['name'] !== '' ? $o['name'] : '—') ?><?php endif; ?>
        <?php if ($o['comment']): ?><span class="sl-note" title="<?= e(str_limit($o['comment'], 300)) ?>" aria-label="Есть комментарий клиента"><?= icon('doc') ?></span><?php endif; ?>
      </td>
      <td data-l="Телефон" class="nowrap"><?php if ($o['phone'] !== ''): ?><a href="<?= e(O::tel($o['phone'])) ?>"><?= e(O::phone($o['phone'])) ?></a><?php else: ?>—<?php endif; ?></td>
      <td data-l="Ящ. / пар" class="num"><?= (int) $o['boxes'] ?> / <?= (int) $o['pairs'] ?></td>
      <td data-l="Сумма" class="num"><b><?= e(price_format($o['total'])) ?></b></td>
      <td data-l="Доставка"><?= e(O::methodName($o['shipping_method'], $o['shipping_name'], $shipping) ?: '—') ?><?php if ($o['city']): ?><br><span class="muted"><?= e(str_limit($o['city'], 40)) ?></span><?php endif; ?></td>
      <td data-l="Оплата"><?= e(O::methodName($o['payment_method'], $o['payment_name'], $payment) ?: '—') ?></td>
      <td data-l="Статус">
        <select class="st st-<?= e($o['status']) ?> sl-st" data-order="<?= $oid ?>" data-prev="<?= e($o['status']) ?>" aria-label="Статус заказа <?= e($num) ?>">
          <?php foreach (O::STATUSES as $st => $label): ?><option value="<?= e($st) ?>"<?= $o['status'] === $st ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
          <?php if (!isset(O::STATUSES[$o['status']])): ?><option value="<?= e($o['status']) ?>" selected><?= e($o['status']) ?></option><?php endif; ?>
        </select>
      </td>
      <td data-l="Источник"><span class="badge sl-src-<?= e($o['source']) ?>"><?= e(O::SOURCES[$o['source']] ?? $o['source']) ?></span><?php if (($o['lang'] ?? 'ru') !== 'ru'): ?> <span class="badge sl-lang sl-lang-<?= e($o['lang']) ?>" title="Оформлен на украинской версии сайта"><?= e(O::langName($o['lang'])) ?></span><?php endif; ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?= $pg->html() ?>
<?php endif; ?>
