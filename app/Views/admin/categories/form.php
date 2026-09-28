<?php
/**
 * Категория: создание / редактирование.
 * @var array $c @var array $cats @var array $features @var array $errors @var array $seoTpl @var array $seoUk @var App\Core\View $view
 * @var array $sorts @var array $brands @var int $direct @var int $mainCount @var int $children @var ?int $dynCount
 */
use App\Controllers\Admin\BaseController;
use App\Services\AdminCatalog;

$id = (int) $c['id'];
$err = static fn(string $k) => isset($errors[$k]) ? '<small class="ac-err">' . e($errors[$k]) . '</small>' : '';
$i18n = [AdminCatalog::class, 'i18nField'];
$dyn = (int) $c['type'] === 1;
// родитель: всё, кроме самой категории и её потомков
$self = $id && isset($cats[$id]) ? $cats[$id] : null;
$parentOptions = '';
foreach ($cats as $x) {
    if ($self && (int) $x['lft'] >= (int) $self['lft'] && (int) $x['rgt'] <= (int) $self['rgt']) continue;
    $parentOptions .= '<option value="' . (int) $x['id'] . '"' . ((int) $c['parent_id'] === (int) $x['id'] ? ' selected' : '') . '>'
        . e(str_repeat('— ', (int) $x['depth']) . $x['name'] . ((int) $x['type'] === 1 ? ' (по условию)' : '')) . '</option>';
}
// фильтры: сначала отмеченные в сохранённом порядке, затем остальные фильтруемые характеристики
$saved = array_values(array_filter(array_map('trim', explode(',', (string) ($c['filter'] ?? ''))), static fn($x) => $x !== ''));
$filterItems = [];
foreach ($saved as $x) {
    if ($x === 'price') $filterItems['price'] = ['Цена', true, true];
    elseif (ctype_digit($x) && isset($features[(int) $x])) $filterItems[$x] = [$features[(int) $x]['name'], true, (int) $features[(int) $x]['is_filter'] === 1];
}
if (!isset($filterItems['price'])) $filterItems['price'] = ['Цена', false, true];
foreach ($features as $fid => $f) {
    if ((int) $f['is_filter'] === 1 && !isset($filterItems[(string) $fid])) $filterItems[(string) $fid] = [$f['name'], false, true];
}
$sortOptions = $sorts;
if (!empty($c['sort_products']) && !isset($sortOptions[$c['sort_products']])) $sortOptions[$c['sort_products']] = $c['sort_products'];
$img = (string) ($c['image'] ?? '');
?>
<?php if ($errors): ?><div class="flash bad">Не сохранено: <?= e(implode(' ', $errors)) ?></div><?php endif; ?>

<form method="post" enctype="multipart/form-data" id="cat-form" class="ac-form" action="<?= $id ? '/admin/categories/' . $id . '/' : '/admin/categories/new/' ?>">
<?= BaseController::tokenField() ?>
<div class="grid3">
  <div class="ac-col">
    <section class="card" aria-labelledby="h-main">
      <div class="ac-cardhd"><h2 id="h-main">Основное</h2><?= AdminCatalog::langTabs() ?></div>
      <?= $i18n('text', 'name', 'Название *', $c, ['required' => true, 'max' => 255, 'id' => 'c-name', 'error' => $err('name')]) ?>
      <div class="fld">
        <label for="c-url"><span>Адрес на сайте</span></label>
        <div class="ac-url"><span class="muted">/category/</span><input type="text" name="url" id="c-url" value="<?= e($c['url']) ?>" maxlength="190" autocomplete="off"><span class="muted">/</span></div>
        <small class="hint">Пусто — адрес сформируется из названия (транслитом). Адрес должен быть уникальным.</small><?= $err('url') ?>
        <?php if ($id): ?><label class="chk ac-small"><input type="checkbox" name="redirect" value="1" checked> При смене адреса — 301-редирект со старого</label><?php endif; ?>
      </div>
      <div class="row2">
        <label class="fld"><span>Родительская категория</span>
          <select name="parent_id"><option value="0">— корень каталога —</option><?= $parentOptions ?></select><?= $err('parent_id') ?></label>
        <label class="fld"><span>Статус</span>
          <select name="status"><option value="1"<?= (int) $c['status'] ? ' selected' : '' ?>>Показывать на сайте</option><option value="0"<?= (int) $c['status'] ? '' : ' selected' ?>>Скрыта</option></select></label>
      </div>
    </section>

    <section class="card" aria-labelledby="h-type">
      <h2 id="h-type">Какие товары показывать</h2>
      <div class="ac-radios" role="radiogroup" aria-labelledby="h-type">
        <label class="chk"><input type="radio" name="type" value="0"<?= $dyn ? '' : ' checked' ?>> Обычная — товары, привязанные вручную</label>
        <label class="chk"><input type="radio" name="type" value="1"<?= $dyn ? ' checked' : '' ?>> Динамическая — товары по условию</label>
      </div>
      <div id="sub-box">
        <label class="chk"><input type="checkbox" name="include_sub" value="1"<?= (int) $c['include_sub'] ? ' checked' : '' ?>> Показывать товары подкатегорий</label>
        <?php if ($id && !$dyn): ?><p class="hint">Привязано прямо к категории: <a href="/admin/products/?category=<?= $id ?>&amp;direct=1"><?= number_format($direct, 0, '', ' ') ?></a> · на сайте с учётом подкатегорий: <?= number_format((int) $c['product_count'], 0, '', ' ') ?></p><?php endif; ?>
      </div>
      <div id="cond-box"<?= $dyn ? '' : ' hidden' ?>>
        <label class="fld"><span>Условие</span><textarea name="conditions" id="p-cond" rows="2" class="code" placeholder="compare_price>0" maxlength="1000"><?= e($c['conditions'] ?? '') ?></textarea><?= $err('conditions') ?></label>
        <div class="ac-condtools">
          <button type="button" class="btn btn-sm" data-cond="compare_price>0">Со скидкой</button>
          <button type="button" class="btn btn-sm" data-cond="price<=500">Цена до 500</button>
          <button type="button" class="btn btn-sm" data-cond="create_datetime>=<?= e(date('Y-m-d', strtotime('-30 days'))) ?>">Новинки за 30 дней</button>
          <label class="sr-only" for="cond-brand">Добавить бренд в условие</label>
          <select id="cond-brand"><option value="">+ бренд…</option><?php foreach ($brands as $b): ?><option value="<?= (int) $b['id'] ?>"><?= e($b['name']) ?></option><?php endforeach; ?></select>
        </div>
        <div class="ac-hint-box">
          <p><b>Формат условия — как в Webasyst.</b> Несколько условий — через <code>&amp;</code> (все должны выполняться).</p>
          <p><code>compare_price&gt;0</code> — со скидкой · <code>price&lt;500</code>, <code>price&gt;=1000</code> — по цене за пару · <code>rating&gt;=4</code></p>
          <p><code>create_datetime&gt;=2026-01-01</code> — добавлены после даты · <code>brand.value_id=40</code> или <code>brand.value_id=40,41</code> — бренды</p>
          <p><code>pol.value_id=12</code>, <code>size.value_id=…</code> — значение характеристики (№ значения — в разделе «Характеристики»)</p>
        </div>
        <?php if ($dynCount !== null): ?><p class="hint">Сейчас под условие подходит товаров на сайте: <b><?= number_format($dynCount, 0, '', ' ') ?></b></p><?php endif; ?>
      </div>
    </section>

    <section class="card" aria-labelledby="h-text">
      <div class="ac-cardhd"><h2 id="h-text">Тексты</h2><?= AdminCatalog::langTabs() ?></div>
      <?= $i18n('html', 'description', 'Описание над списком товаров (HTML)', $c, ['rows' => 8, 'id' => 'c-desc']) ?>
      <?= $i18n('html', 'seo_description', 'SEO-текст под списком товаров (HTML)', $c, ['rows' => 12, 'id' => 'c-seodesc']) ?>
    </section>

    <section class="card" aria-labelledby="h-seo">
      <div class="ac-cardhd"><h2 id="h-seo">SEO</h2><?= AdminCatalog::langTabs() ?></div>
      <p class="hint ac-small muted">Заполняйте только если нужно своё значение. Пустое поле — на сайте используется шаблон из настроек SEO (показан серым).</p>
      <div data-l="ru"><?= $view->partial('admin/partials/serp', ['serp' => ['type' => 'category', 'row' => $c]]) ?></div>
      <div data-l="uk" hidden><?= $view->partial('admin/partials/serp', ['serp' => AdminCatalog::serpUk($c['url'] !== '' ? '/ua/category/' . $c['url'] . '/' : '', $c, $seoUk)]) ?></div>
      <?= $i18n('text', 'seo_name', 'SEO-название ({$category.seo_name} в шаблонах)', $c, ['max' => 500, 'placeholder' => $c['name'], 'hint' => 'Пусто — используется название категории.', 'tpl_uk' => $seoUk['seo_name']]) ?>
      <?= $i18n('text', 'meta_title', 'Title', $c, ['max' => 500, 'tpl' => $seoTpl['meta_title'] ?: $c['name'], 'tpl_uk' => $seoUk['meta_title']]) ?>
      <?= $i18n('area', 'meta_description', 'Description', $c, ['max' => 5000, 'tpl' => $seoTpl['meta_description'], 'tpl_uk' => $seoUk['meta_description']]) ?>
      <?= $i18n('area', 'meta_keywords', 'Keywords', $c, ['max' => 5000, 'tpl' => $seoTpl['meta_keywords'], 'tpl_uk' => $seoUk['meta_keywords']]) ?>
      <?= $i18n('text', 'h1', 'Заголовок H1', $c, ['max' => 500, 'placeholder' => $c['name'],
        'hint' => 'Как на старом сайте, в заголовке страницы категории выводится её название; поле хранится для совместимости со старым сайтом и импортом.']) ?>
    </section>
  </div>

  <div class="ac-col">
    <section class="card" aria-labelledby="h-list">
      <h2 id="h-list">Список товаров</h2>
      <label class="fld"><span>Сортировка по умолчанию</span>
        <select name="sort_products"><?php foreach ($sortOptions as $k => $label): ?><option value="<?= e($k) ?>"<?= (string) ($c['sort_products'] ?? '') === (string) $k ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
      <label class="chk ac-mb"><input type="checkbox" name="enable_sorting" value="1"<?= (int) ($c['enable_sorting'] ?? 1) ? ' checked' : '' ?>> Покупатель может менять сортировку</label>
      <fieldset class="seo-set">
        <legend>Фильтры в каталоге</legend>
        <div class="ac-filterset">
          <?php foreach ($filterItems as $key => [$label, $on, $usable]): ?>
            <label class="chk"><input type="checkbox" name="filter[]" value="<?= e((string) $key) ?>"<?= $on ? ' checked' : '' ?>> <span><?= e($label) ?><?= $usable ? '' : ' <small class="muted">(не фильтруется)</small>' ?></span></label>
          <?php endforeach; ?>
        </div>
        <p class="hint">Порядок фильтров на сайте — как здесь; отмеченные ранее идут первыми. Какие характеристики можно фильтровать — задаётся в разделе <a href="/admin/features/">«Характеристики»</a>.</p>
      </fieldset>
    </section>

    <section class="card" aria-labelledby="h-img">
      <h2 id="h-img">Картинка категории</h2>
      <img class="ac-img-prev" id="c-img-prev" alt="Картинка категории"<?= $img !== '' ? ' src="' . e(media($img)) . '"' : ' hidden' ?>>
      <div class="fld">
        <label for="c-image"><span>Путь к картинке</span></label>
        <div class="ac-url"><input type="text" name="image" id="c-image" value="<?= e($img) ?>" placeholder="/uploads/…" maxlength="255" autocomplete="off">
          <button type="button" class="btn btn-sm" data-clear="#c-image" data-clear-preview="#c-img-prev" aria-label="Убрать картинку" title="Убрать картинку">×</button></div>
        <button type="button" class="btn" data-media-pick="#c-image" data-media-preview="#c-img-prev">Выбрать из медиатеки</button>
        <?= $err('image') ?>
      </div>
      <label class="fld"><span>Или загрузить с компьютера</span><input type="file" name="image_file" accept="image/jpeg,image/png,image/webp,image/gif" data-preview="#c-img-prev">
        <small class="hint">Плитка категории на главной (показываются корневые категории с картинкой). jpg, png, webp, gif до 15 МБ — уменьшится до 800 px и сохранится в /uploads/categories/.</small></label>
    </section>

    <?php if ($id): ?>
    <section class="card" aria-labelledby="h-info">
      <h2 id="h-info">Сведения</h2>
      <dl class="detail">
        <div><dt>№</dt><dd><?= $id ?></dd></div>
        <div><dt>Подкатегорий</dt><dd><?= (int) $children ?></dd></div>
        <div><dt>На сайте</dt><dd><?= number_format((int) $c['product_count'], 0, '', ' ') ?> <?= plural((int) $c['product_count'], 'товар', 'товара', 'товаров') ?></dd></div>
        <?php if (!empty($c['updated_at'])): ?><div><dt>Изменена</dt><dd><?= e(date('d.m.Y H:i', strtotime((string) $c['updated_at']))) ?></dd></div><?php endif; ?>
      </dl>
      <p class="hint"><a href="/admin/categories/new/?parent=<?= $id ?>">+ Добавить подкатегорию</a></p>
    </section>
    <?php endif; ?>
  </div>
</div>

<div class="form-actions">
  <button type="submit" class="btn btn-p"><?= $id ? 'Сохранить' : 'Создать категорию' ?></button>
  <a class="btn" href="/admin/categories/">К списку</a>
  <span class="hint">После сохранения индекс каталога и счётчики товаров пересчитываются автоматически.</span>
</div>
</form>

<?php if ($id): ?>
<form method="post" action="/admin/categories/<?= $id ?>/delete/" class="card" data-confirm="Удалить категорию «<?= e($c['name']) ?>»?">
  <?= BaseController::tokenField() ?>
  <h2>Удаление категории</h2>
  <?php if ($children): ?>
    <p class="muted">В категории есть подкатегории (<?= (int) $children ?>) — сначала перенесите их в другой раздел или удалите.</p>
  <?php else: ?>
    <?php if ($direct || $mainCount): ?>
      <label class="fld"><span>Товары (<?= number_format(max($direct, $mainCount), 0, '', ' ') ?>) перенести в категорию</span>
        <select name="move_to" required><option value="">Выберите…</option>
          <?php foreach ($cats as $x): if ((int) $x['id'] === $id || (int) $x['type'] === 1) continue; ?>
            <option value="<?= (int) $x['id'] ?>"><?= e(str_repeat('— ', (int) $x['depth']) . $x['name']) ?></option>
          <?php endforeach; ?></select>
        <small class="hint">Привязка к удаляемой категории заменится на выбранную, основная категория товаров — тоже.</small></label>
    <?php else: ?>
      <p class="muted">В категории нет привязанных товаров — её можно удалить.</p>
    <?php endif; ?>
    <button type="submit" class="btn btn-d">Удалить категорию</button>
  <?php endif; ?>
</form>
<?php endif; ?>
