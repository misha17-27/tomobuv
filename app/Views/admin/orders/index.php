<?php
/**
 * Список заказов.
 * @var array $f @var array $counts @var int $all @var int $total @var array $orders @var App\Core\Paginator $pg
 * @var array $shipping @var array $payment @var string $qs фильтры списка (для формы массовой смены статуса)
 * @var ?array $newCounts OrdersController::newCounts() — на вкладке «Новые» @var int $bulkMax
 */
use App\Controllers\Admin\BaseController;
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

<?php if ($newCounts && ($newCounts['fresh'] || $newCounts['stale'])):
  // «Новые»: за FRESH_DAYS дней — как в плитке на главной и в меню; старше — отдельно, их можно закрыть массово
  $freshFrom = O::freshFrom();
  $isFresh = $f['from'] === $freshFrom && $f['to'] === '';
  $isStale = $f['from'] === '' && $f['to'] === date('Y-m-d', strtotime($freshFrom . ' -1 day')); ?>
  <div class="flash warn sl-newhint">
    <?php if ($isStale): ?>
      Это «новые» заказы старше <?= O::FRESH_DAYS ?> дней — в основном необработанные заказы со старого сайта. Чтобы закрыть их разом,
      отметьте все заказы на странице (галочка в шапке таблицы), затем «все найденные» и выберите статус (например, «Выполнен» или «Удалён»).
      <a href="<?= e(O::freshUrl()) ?>">Новые за <?= O::FRESH_DAYS ?> дней: <?= number_format($newCounts['fresh'], 0, '', ' ') ?></a>
    <?php else: ?>
      <?php if (!$isFresh): ?><a href="<?= e(O::freshUrl()) ?>">Новые за <?= O::FRESH_DAYS ?> дней (с <?= e(date('d.m.Y', strtotime($freshFrom))) ?>): <?= number_format($newCounts['fresh'], 0, '', ' ') ?></a> — их показывают плитка на главной и счётчик в меню.<?php else: ?>Новые заказы за <?= O::FRESH_DAYS ?> дней — те же, что в плитке на главной и в счётчике меню.<?php endif; ?>
      <?php if ($newCounts['stale']): ?>Ещё <?= number_format($newCounts['stale'], 0, '', ' ') ?> «<?= plural($newCounts['stale'], 'новый', 'новых', 'новых') ?>» старше — в основном необработанные со старого сайта:
        <a href="<?= e(O::staleUrl()) ?>">открыть и закрыть массово</a>.<?php endif; ?>
    <?php endif; ?>
  </div>
<?php endif; ?>

<p class="sl-found muted">Найдено: <b><?= number_format($total, 0, '', ' ') ?></b> <?= plural($total, 'заказ', 'заказа', 'заказов') ?><?= $pg->pages > 1 ? ' · страница ' . $pg->page . ' из ' . $pg->pages : '' ?></p>

<?php if (!$orders): ?>
  <div class="card empty-card"><h2>Заказов не найдено</h2><p>Измените условия поиска или период.</p><?php if ($filtered): ?><a class="btn" href="/admin/orders/">Показать все заказы</a><?php endif; ?></div>
<?php else: ?>
<form class="sl-bulk" method="post" action="/admin/orders/bulk/<?= $qs !== '' ? '?' . e($qs) : '' ?>" data-sl-bulk data-total="<?= (int) $total ?>">
<?= BaseController::tokenField() ?>
<div class="bulk-bar" data-sl-bulk-bar hidden>
  <span>Выбрано: <b data-sl-bulk-n>0</b></span>
  <?php if ($total > count($orders)): ?><label class="chk"><input type="checkbox" name="all" value="1" data-sl-bulk-all<?= $total > $bulkMax ? ' disabled' : '' ?>>
    все найденные (<?= number_format($total, 0, '', ' ') ?>)<?= $total > $bulkMax ? ' — больше ' . number_format($bulkMax, 0, '', ' ') . ', сузьте фильтр' : '' ?></label><?php endif; ?>
  <select name="to_status" aria-label="Новый статус для отмеченных заказов">
    <option value="">Новый статус…</option>
    <?php foreach (O::STATUSES as $st => $label): ?><option value="<?= e($st) ?>"><?= e($label) ?></option><?php endforeach; ?>
  </select>
  <button class="btn btn-p btn-sm" type="submit">Применить</button>
  <span class="muted">письма клиентам не отправляются</span>
</div>
<label class="chk sl-pick-m"><input type="checkbox" data-sl-check-all> Отметить все заказы на странице</label>
<div class="tblwrap">
<table class="tbl sl-orders sl-stack">
  <thead><tr>
    <th><label class="sl-pick"><input type="checkbox" data-sl-check-all aria-label="Отметить все заказы на странице"></label>Номер</th><th>Дата</th><th>Клиент</th><th>Телефон</th><th class="num">Ящ. / пар</th><th class="num">Сумма</th>
    <th>Доставка</th><th>Оплата</th><th>Статус</th><th>Источник</th>
  </tr></thead>
  <tbody>
  <?php foreach ($orders as $o):
    $oid = (int) $o['id']; $num = O::number($oid); $ts = strtotime((string) $o['created_at']); ?>
    <tr class="<?= $o['status'] === 'new' ? 'unread' : '' ?>">
      <td data-l="Номер"><label class="sl-pick"><input type="checkbox" name="ids[]" value="<?= $oid ?>" aria-label="Отметить заказ <?= e($num) ?>"></label><a class="sl-num" href="/admin/orders/<?= $oid ?>/"><?= e($num) ?></a></td>
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
</form>
<?= $pg->html() ?>
<?php endif; ?>
