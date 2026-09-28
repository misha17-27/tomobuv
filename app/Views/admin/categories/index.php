<?php
/**
 * Дерево категорий.
 * @var array $cats [id => row] в порядке дерева (lft) @var array $direct [category_id => прямых товаров]
 */
use App\Controllers\Admin\BaseController;

$token = BaseController::tokenField();
// соседи по родителю — чтобы не показывать «вверх» у первой и «вниз» у последней
$siblings = [];
foreach ($cats as $c) $siblings[(int) $c['parent_id']][] = (int) $c['id'];
$fmt = static fn($n) => number_format((int) $n, 0, '', ' ');
$options = static function (array $cats, int $selected = -1, int $skip = 0): string {
    $h = '';
    $skipL = $skipR = 0;
    if ($skip && isset($cats[$skip])) { $skipL = (int) $cats[$skip]['lft']; $skipR = (int) $cats[$skip]['rgt']; }
    foreach ($cats as $c) {
        if ($skip && (int) $c['lft'] >= $skipL && (int) $c['rgt'] <= $skipR) continue;
        $h .= '<option value="' . (int) $c['id'] . '"' . ((int) $c['id'] === $selected ? ' selected' : '') . '>'
            . e(str_repeat('— ', (int) $c['depth']) . $c['name']) . '</option>';
    }
    return $h;
};
$visible = count(array_filter($cats, static fn($c) => (int) $c['status'] === 1));
?>
<div class="stats">
  <div class="stat"><span><?= $fmt(count($cats)) ?></span>Всего категорий</div>
  <div class="stat"><span><?= $fmt($visible) ?></span>На сайте</div>
  <div class="stat"><span><?= $fmt(count(array_filter($cats, static fn($c) => (int) $c['type'] === 1))) ?></span>Динамических (по условию)</div>
  <div class="stat"><span><?= $fmt(count(array_filter($cats, static fn($c) => (string) ($c['name_uk'] ?? '') === ''))) ?></span>Без перевода на украинский</div>
</div>

<div class="card flush">
  <div class="card-hd">
    <h2>Дерево категорий</h2>
    <label class="sr-only" for="cat-q">Найти категорию</label>
    <input type="search" id="cat-q" class="ac-treeq" placeholder="Найти категорию…" autocomplete="off">
  </div>
  <?php if (!$cats): ?>
    <div class="empty-card"><h2>Категорий пока нет</h2><p>Создайте первую — товары привязываются к категориям.</p><a class="btn btn-p" href="/admin/categories/new/">Добавить категорию</a></div>
  <?php else: ?>
  <div class="table-scroll">
    <table class="tbl ac-tree" id="cat-tree-table">
      <thead><tr><th>Категория</th><th>Тип</th><th class="num" title="Активных товаров на сайте с учётом подкатегорий">На сайте</th><th class="num" title="Товаров, привязанных прямо к категории (любой статус)">Привязано</th><th>Статус</th><th>Порядок</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($cats as $c):
        $cid = (int) $c['id'];
        $sib = $siblings[(int) $c['parent_id']] ?? [];
        $pos = array_search($cid, $sib, true);
        $dyn = (int) $c['type'] === 1; ?>
        <tr class="<?= (int) $c['depth'] === 0 ? 'ac-root' : '' ?><?= (int) $c['status'] ? '' : ' is-draft' ?>" data-name="<?= e(mb_strtolower($c['name'] . ' ' . $c['url'] . ' ' . ($c['name_uk'] ?? ''))) ?>">
          <td class="ac-cname" style="padding-left:<?= 16 + (int) $c['depth'] * 22 ?>px">
            <?php if ((int) $c['depth']): ?><span class="ac-ind" aria-hidden="true">└</span> <?php endif; ?>
            <a href="/admin/categories/<?= $cid ?>/"><?= e($c['name']) ?></a>
            <?php if ((string) ($c['name_uk'] ?? '') !== ''): ?><span class="ac-ltag uk" title="<?= e($c['name_uk']) ?>">UA</span><?php endif; ?>
            <small>/category/<?= e($c['url']) ?>/</small>
          </td>
          <td class="ac-small"><?= $dyn ? '<span class="pill new">по условию</span><small><code>' . e(mb_strimwidth((string) $c['conditions'], 0, 40, '…')) . '</code></small>' : 'обычная' . ((int) $c['include_sub'] ? '' : '<small>без подкатегорий</small>') ?></td>
          <td class="num"><a href="/category/<?= e(rawurlencode((string) $c['url'])) ?>/" target="_blank" rel="noopener" title="Открыть на сайте"><?= $fmt($c['product_count']) ?></a></td>
          <td class="num"><?php if ($dyn): ?><span class="muted">—</span><?php else: ?><a href="/admin/products/?category=<?= $cid ?>&amp;direct=1"><?= $fmt($direct[$cid] ?? 0) ?></a><?php endif; ?></td>
          <td><?= (int) $c['status'] ? '<span class="pill ok">на сайте</span>' : '<span class="pill">скрыта</span>' ?></td>
          <td class="nowrap">
            <div class="ac-move">
              <form method="post" action="/admin/categories/move/"><?= $token ?><input type="hidden" name="id" value="<?= $cid ?>"><input type="hidden" name="dir" value="up">
                <button class="btn btn-sm" type="submit" aria-label="Выше: «<?= e($c['name']) ?>»" title="Выше"<?= $pos === 0 ? ' disabled' : '' ?>>↑</button></form>
              <form method="post" action="/admin/categories/move/"><?= $token ?><input type="hidden" name="id" value="<?= $cid ?>"><input type="hidden" name="dir" value="down">
                <button class="btn btn-sm" type="submit" aria-label="Ниже: «<?= e($c['name']) ?>»" title="Ниже"<?= $pos === count($sib) - 1 ? ' disabled' : '' ?>>↓</button></form>
            </div>
          </td>
          <td class="nowrap"><a class="btn btn-sm" href="/admin/categories/new/?parent=<?= $cid ?>" title="Добавить подкатегорию" aria-label="Добавить подкатегорию в «<?= e($c['name']) ?>»">+ Подкатегория</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <p class="empty-card" id="cat-q-empty" hidden>Ничего не найдено.</p>
  <?php endif; ?>
</div>

<?php if ($cats): ?>
<form method="post" action="/admin/categories/move/" class="card">
  <?= $token ?>
  <h2>Переместить в другой раздел</h2>
  <div class="row3 ac-moverow">
    <label class="fld"><span>Категория</span><select name="id" required><option value="">Выберите…</option><?= $options($cats) ?></select></label>
    <label class="fld"><span>Новый родитель</span><select name="parent_id"><option value="0">— корень каталога —</option><?= $options($cats) ?></select></label>
    <div class="fld"><span aria-hidden="true">&nbsp;</span><button class="btn btn-p" type="submit">Переместить</button></div>
  </div>
  <p class="hint">Категория переносится вместе с подкатегориями и встаёт последней среди новых соседей. Индекс каталога и счётчики товаров пересчитываются автоматически.</p>
</form>
<?php endif; ?>
