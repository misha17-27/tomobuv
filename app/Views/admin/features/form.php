<?php
/**
 * Характеристика: настройки + значения.
 * @var array $f @var array $errors @var array $statuses @var array $types @var array $rows @var array $counts
 * @var ?App\Core\Paginator $pg @var ?array $dups @var string $vq @var string $vsort @var bool $unused @var bool $nouk
 * @var bool $dupsTooMany @var ?array $usedBy
 */
use App\Controllers\Admin\BaseController;
use App\Services\AdminCatalog;

$id = (int) $f['id'];
$err = static fn(string $k) => isset($errors[$k]) ? '<small class="ac-err">' . e($errors[$k]) . '</small>' : '';
$isColor = $f['type'] === 'color';
$qs = http_build_query(array_filter(['vq' => $vq, 'vsort' => $vsort !== 'sort' ? $vsort : '', 'unused' => $unused ? '1' : '', 'nouk' => $nouk ? '1' : '',
    'page' => ($pg && $pg->page > 1) ? $pg->page : ''], static fn($v) => $v !== ''));
?>
<?php if ($errors): ?><div class="flash bad">Не сохранено: <?= e(implode(' ', $errors)) ?></div><?php endif; ?>

<div class="lang-scope" data-lang="ru">
<?= $view->partial('admin/partials/lang-bar', ['note' => 'Названия характеристики и её значений на украинской версии сайта (<code>/ua/</code>). Пустое поле UA — показывается русское название.']) ?>
<form method="post" class="card ed-form" action="<?= $id ? '/admin/features/' . $id . '/' : '/admin/features/new/' ?>">
  <?= BaseController::tokenField() ?>
  <h2>Настройки</h2>
  <div class="row2">
    <div><?= AdminCatalog::i18nField('text', 'name', 'Название *', $f, ['required' => true, 'max' => 255, 'error' => $err('name')]) ?></div>
    <label class="fld"><span>Код</span><input type="text" name="code" value="<?= e($f['code']) ?>" <?= $id ? 'readonly' : 'required pattern="[a-z][a-z0-9_]{1,63}"' ?> maxlength="64">
      <small class="hint"><?= $id ? 'Код не меняется: на него ссылаются условия категорий и импорт.' : 'Латиница, цифры и «_», например material_verha.' ?></small><?= $err('code') ?></label>
  </div>
  <div class="row3">
    <label class="fld"><span>Тип</span>
      <?php if ($id): ?><input type="text" value="<?= e($types[$f['type']] ?? $f['type']) ?>" readonly>
      <?php else: ?><select name="type"><?php foreach ($types as $k => $label): ?><option value="<?= e($k) ?>"<?= $f['type'] === $k ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select><?php endif; ?></label>
    <label class="fld"><span>Показ</span><select name="status"><?php foreach ($statuses as $k => $label): ?><option value="<?= e($k) ?>"<?= $f['status'] === $k ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
    <label class="fld"><span>Порядок</span><input type="number" name="sort" value="<?= (int) $f['sort'] ?>" step="1"></label>
  </div>
  <label class="chk"><input type="checkbox" name="is_filter" value="1"<?= (int) $f['is_filter'] ? ' checked' : '' ?>> <span>Можно использовать в фильтре каталога <small class="muted">(какие фильтры показывать — задаётся в настройках категории)</small></span></label>
  <label class="chk"><input type="checkbox" name="multiple" value="1"<?= (int) $f['multiple'] ? ' checked' : '' ?>> У товара может быть несколько значений (например, несколько цветов)</label>
  <div class="form-actions"><button class="btn btn-p" type="submit"><?= $id ? 'Сохранить' : 'Создать' ?></button><a class="btn" href="/admin/features/">К списку</a><span class="hint hide-sm">Ctrl+S — сохранить</span></div>
</form>

<?php if ($id): ?>
<div class="card flush" id="values">
  <div class="card-hd"><h2>Значения<?= isset($vtotal) ? ' · ' . number_format((int) $vtotal, 0, '', ' ') : '' ?></h2>
    <?php if ($dupsTooMany): ?><span class="muted ac-small">Поиск похожих — для характеристик до 30 000 значений</span>
    <?php else: ?><a href="/admin/features/<?= $id ?>/?dups=1#values">Найти похожие (дубли)</a><?php endif; ?></div>
  <form method="get" action="/admin/features/<?= $id ?>/" class="bulk-bar" role="search">
    <label class="sr-only" for="vq">Поиск значения</label><input type="search" id="vq" name="vq" value="<?= e($vq) ?>" placeholder="Найти значение или №" class="ac-vsearch">
    <label class="sr-only" for="vsort">Сортировка</label>
    <select name="vsort" id="vsort"><option value="sort"<?= $vsort === 'sort' ? ' selected' : '' ?>>Как на сайте</option><option value="value"<?= $vsort === 'value' ? ' selected' : '' ?>>По алфавиту</option><option value="id"<?= $vsort === 'id' ? ' selected' : '' ?>>Сначала новые</option></select>
    <label class="chk"><input type="checkbox" name="unused" value="1"<?= $unused ? ' checked' : '' ?>> Только без товаров</label>
    <label class="chk"><input type="checkbox" name="nouk" value="1"<?= $nouk ? ' checked' : '' ?>> Без перевода UA</label>
    <button class="btn btn-sm" type="submit">Показать</button>
    <?php if ($vq !== '' || $unused || $nouk || $vsort !== 'sort'): ?><a href="/admin/features/<?= $id ?>/#values" class="ac-small">сбросить</a><?php endif; ?>
  </form>

  <?php if ($dups !== null): ?>
  <div class="pad">
    <h3>Похожие значения</h3>
    <?php if (!$dups): ?><p class="muted">Похожих значений не найдено.</p><?php else: ?>
    <p class="hint">Совпадают без учёта регистра, пробелов и знаков. Объединение переносит товары на значение с наибольшим числом товаров.</p>
    <div class="ac-dups">
      <?php foreach ($dups as $g): $t = $g[0]; ?>
        <div class="ac-dup">
          <?php foreach ($g as $i => $v): ?><span><?= $i ? '' : '<b>' ?>«<?= e($v['value']) ?>» <small class="muted">№<?= $v['id'] ?>, товаров: <?= $v['n'] ?></small><?= $i ? '' : '</b>' ?></span><?php endforeach; ?>
          <form method="post" action="/admin/features/<?= $id ?>/values/" data-confirm="Объединить в «<?= e($t['value']) ?>»?">
            <?= BaseController::tokenField() ?><input type="hidden" name="act" value="merge"><input type="hidden" name="target" value="<?= $t['id'] ?>"><input type="hidden" name="qs" value="dups=1">
            <?php foreach ($g as $v): ?><input type="hidden" name="ids[]" value="<?= $v['id'] ?>"><?php endforeach; ?>
            <button class="btn btn-sm" type="submit">Объединить в «<?= e(mb_strimwidth($t['value'], 0, 30, '…')) ?>»</button>
          </form>
        </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <form method="post" action="/admin/features/<?= $id ?>/values/" id="values-form">
    <?= BaseController::tokenField() ?>
    <input type="hidden" name="qs" value="<?= e($qs) ?>">
    <?php if (!$rows): ?>
      <div class="empty-card"><p><?= $vq !== '' || $unused ? 'Ничего не найдено.' : 'Значений пока нет — добавьте ниже.' ?></p></div>
    <?php else: ?>
    <div class="table-scroll">
      <table class="tbl ac-values">
        <thead><tr><th class="tick"><input type="checkbox" id="values-all" aria-label="Отметить все"></th><th title="Какое значение оставить при объединении">Оставить</th><th>№</th><th>Значение <span class="l-ru"><?= AdminCatalog::langTag('ru') ?></span><span class="l-uk"><?= AdminCatalog::langTag('uk') ?></span></th><th class="num">Товаров</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $v): $vid = (int) $v['id']; $n = (int) ($counts[$vid] ?? 0); ?>
          <tr>
            <td class="tick"><input type="checkbox" name="ids[]" value="<?= $vid ?>" aria-label="Отметить «<?= e($v['value']) ?>»"></td>
            <td class="tick"><input type="radio" name="target" value="<?= $vid ?>" aria-label="Оставить «<?= e($v['value']) ?>» при объединении"></td>
            <td class="muted"><?= $vid ?></td>
            <td><div class="ac-url"><?php if ($isColor && $v['code'] !== null): ?><i class="ac-sw" style="background:#<?= e(sprintf('%06x', (int) $v['code'])) ?>"></i><?php endif; ?>
              <input type="text" name="names[<?= $vid ?>]" value="<?= e($v['value']) ?>" maxlength="255" aria-label="Название значения №<?= $vid ?>" class="ac-vname l-ru">
              <input type="text" name="names_uk[<?= $vid ?>]" value="<?= e((string) $v['value_uk']) ?>" maxlength="255" placeholder="<?= e($v['value']) ?>" aria-label="Украинское название значения №<?= $vid ?>" class="ac-vname l-uk" data-uk></div></td>
            <td class="num"><?php if ($n): ?><a href="/admin/products/?<?= e(http_build_query($f['code'] === 'brand' ? ['brand' => $vid] : ['ff' => $id, 'fv' => $vid])) ?>"><?= number_format($n, 0, '', ' ') ?></a><?php else: ?><span class="muted">0</span><?php endif; ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div class="bulk-bar">
      <button class="btn btn-p btn-sm" type="submit" name="act" value="save">Сохранить названия</button>
      <span class="hint ac-small">UA: пусто — на украинской версии показывается русское значение.</span>
      <button class="btn btn-sm" type="submit" name="act" value="merge">Объединить отмеченные</button>
      <button class="btn btn-sm btn-d" type="submit" name="act" value="delete">Удалить отмеченные</button>
      <span class="sp"></span>
      <button class="btn btn-sm btn-d" type="submit" name="act" value="delete_unused" data-confirm="Удалить все значения этой характеристики, у которых нет товаров?">Удалить все без товаров</button>
    </div>
    <?php endif; ?>
  </form>
  <?php if ($pg && $pg->pages > 1): ?><div class="pad"><?= $pg->html() ?></div><?php endif; ?>
</div>

<form method="post" action="/admin/features/<?= $id ?>/values/" class="card">
  <?= BaseController::tokenField() ?><input type="hidden" name="act" value="add"><input type="hidden" name="qs" value="<?= e($qs) ?>">
  <label class="fld"><span>Добавить значения</span><textarea name="new" rows="3" class="plain" placeholder="По одному в строке"></textarea>
    <small class="hint">Совпадающие с существующими (без учёта регистра) не дублируются.<?= $f['code'] === 'brand' ? ' Для каждого нового значения создаётся бренд.' : '' ?></small></label>
  <button class="btn btn-p" type="submit">Добавить</button>
</form>

<?php if ($usedBy !== null && !in_array($f['code'], ['brand', 'size'], true)):
  $canDelete = !$usedBy['products'] && !$usedBy['conditions']; ?>
<form method="post" action="/admin/features/<?= $id ?>/delete/" class="card" data-confirm="Удалить характеристику «<?= e($f['name']) ?>» вместе со всеми её значениями?">
  <?= BaseController::tokenField() ?>
  <h2>Удаление характеристики</h2>
  <?php if ($usedBy['products']): ?>
    <p class="muted">Характеристика заполнена у товаров — удалить можно только неиспользуемую. Чтобы скрыть её с сайта, выберите показ «Служебная».</p>
  <?php elseif ($usedBy['conditions']): ?>
    <p class="muted">Используется в условии категорий: <?= e(implode(', ', $usedBy['conditions'])) ?> — сначала измените условие.</p>
  <?php else: ?>
    <p class="muted">Ни у одного товара нет значений этой характеристики<?= $usedBy['filters'] ? ' (она будет убрана из фильтров ' . (int) $usedBy['filters'] . ' ' . plural((int) $usedBy['filters'], 'категории', 'категорий', 'категорий') . ')' : '' ?>.</p>
  <?php endif; ?>
  <button class="btn btn-d" type="submit"<?= $canDelete ? '' : ' disabled' ?>>Удалить характеристику</button>
</form>
<?php endif; ?>
<?php endif; ?>
</div>
<?= $view->partial('admin/partials/editor') ?>
