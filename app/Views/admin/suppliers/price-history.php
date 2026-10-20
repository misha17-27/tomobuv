<?php
/**
 * Карточка товара в админке: «Цена у поставщика: история» — связь с поставщиком автозагрузки (код, цена за пару в последнем
 * проходе, когда был у поставщика) и журнал изменений (SupplierOverview::productHistory): цены «было → стало», добавлен,
 * скрыт, вернулся. Товар без связи и без истории — блока нет.
 * @var array $supLinks @var array $supHistory
 */
use App\Core\Auth;
use App\Services\Suppliers\Suppliers;
use App\Services\Suppliers\SupplierOverview;

if (!$supLinks && !$supHistory) return;
$money = static fn($v) => $v === null || $v === '' ? '—' : rtrim(rtrim(number_format((float) $v, 2, '.', ' '), '0'), '.');
$dt = static fn(?string $d) => $d ? date('d.m.Y H:i', strtotime($d)) : '—';
$admin = Auth::isAdmin();
?>
<section class="card sup-ph" aria-labelledby="h-supprice">
  <h2 id="h-supprice">Цена у поставщика: история</h2>
  <?php foreach ($supLinks as $l): ?>
    <p class="ac-small">
      <b><?= $admin && $l['base'] ? '<a href="' . e($l['base']) . '">' . e($l['title']) . '</a>' : e($l['title']) ?></b>, код <?= e((string) ($l['label'] ?? $l['code'])) ?>:
      за пару <b><?= e($money($l['cost'])) ?></b> грн<?= $l['seen_at'] ? ' · был у поставщика ' . e($dt($l['seen_at'])) : '' ?>
      <?= (int) $l['hidden'] ? '<br><span class="sup-warn">скрыт загрузчиком — нет у поставщика</span>' : ((int) $l['missed'] > 0 ? '<br><span class="sup-warn">не было в ' . (int) $l['missed'] . ' последних полных проходах</span>' : '') ?>
    </p>
  <?php endforeach; ?>
  <?php if ($supHistory): ?>
  <div class="table-scroll"><table class="grid sup-mini"><thead><tr><th>Дата</th><th class="right">Поставщик</th><th class="right">Сайт</th></tr></thead><tbody>
    <?php foreach ($supHistory as $h): $cls = Suppliers::get((string) $h['supplier']); ?>
      <tr><td class="nowrap"><?= e($dt($h['created_at'])) ?><small><?= e(SupplierOverview::KIND_NAMES[$h['kind']] ?? $h['kind']) ?><?= count($supLinks) > 1 && $cls ? ' · ' . e($cls::TITLE) : '' ?></small></td>
      <?php if ($h['kind'] === 'price' || $h['kind'] === 'new'): ?>
        <td class="right nowrap"><?= $h['kind'] === 'price' ? e($money($h['old_cost'])) . ' → ' : '' ?><?= e($money($h['new_cost'])) ?></td>
        <td class="right nowrap"><?= $h['kind'] === 'price' ? e($money($h['old_price'])) . ' → ' : '' ?><b><?= e($money($h['new_price'])) ?></b>
          <?php if ($h['pct'] !== null): ?><small class="<?= $h['pct'] > 0 ? 'sup-up' : 'sup-down' ?>"><?= $h['pct'] > 0 ? '+' : '−' ?><?= e(rtrim(rtrim(number_format(abs((float) $h['pct']), 1, ',', ''), '0'), ',')) ?>%</small><?php endif; ?></td>
      <?php else: ?>
        <td colspan="2"><small><?= e((string) $h['note']) ?></small></td>
      <?php endif; ?></tr>
    <?php endforeach; ?>
  </tbody></table></div>
  <?php else: ?><p class="hint">Изменений пока не было.</p><?php endif; ?>
  <p class="hint">«Поставщик» — его цена за пару (закупочная), «Сайт» — наша цена за пару с наценкой. Пишут только боевые запуски загрузчика.</p>
</section>
