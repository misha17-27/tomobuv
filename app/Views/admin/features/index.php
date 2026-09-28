<?php
/**
 * Список характеристик.
 * @var array $features @var array $values @var array $noUk @var array $products @var array $statuses @var array $types
 */
?>
<div class="card flush">
  <div class="card-hd"><h2>Характеристики товаров</h2><span class="muted ac-small">«В фильтре» — показывается в фильтре каталога (если включена в настройках категории)</span></div>
  <div class="table-scroll">
    <table class="tbl">
      <thead><tr><th>Название</th><th>Код</th><th>Тип</th><th>Показ</th><th>В фильтре</th><th class="num">Значений</th><th class="num" title="Значений без украинского перевода">Без UA</th><th class="num">Товаров</th><th class="num">Порядок</th></tr></thead>
      <tbody>
      <?php foreach ($features as $f): $fid = (int) $f['id']; ?>
        <tr class="<?= $f['status'] === 'public' ? '' : 'is-draft' ?>">
          <td><a href="/admin/features/<?= $fid ?>/"><b><?= e($f['name']) ?></b></a><?php if ((string) $f['name_uk'] !== ''): ?> <span class="ac-ltag uk" title="<?= e($f['name_uk']) ?>">UA</span><?php endif; ?><?php if ((int) $f['multiple']): ?><small>несколько значений у товара</small><?php endif; ?></td>
          <td><code><?= e($f['code']) ?></code></td>
          <td><?= e($types[$f['type']] ?? $f['type']) ?></td>
          <td><?= $f['status'] === 'public' ? '<span class="pill ok">на сайте</span>' : '<span class="pill">' . e($f['status'] === 'hidden' ? 'скрыта' : 'служебная') . '</span>' ?></td>
          <td><?= (int) $f['is_filter'] ? '<span class="in-stock">да</span>' : '<span class="muted">нет</span>' ?></td>
          <td class="num"><?= number_format((int) ($values[$fid] ?? 0), 0, '', ' ') ?></td>
          <td class="num"><?php $nu = (int) ($noUk[$fid] ?? 0); ?><?= $nu ? '<a href="/admin/features/' . $fid . '/?nouk=1#values">' . number_format($nu, 0, '', ' ') . '</a>' : '<span class="muted">0</span>' ?></td>
          <td class="num"><?= number_format((int) ($products[$fid] ?? 0), 0, '', ' ') ?></td>
          <td class="num"><?= (int) $f['sort'] ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
