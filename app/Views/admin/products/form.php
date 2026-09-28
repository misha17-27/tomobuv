<?php
/**
 * Карточка товара (создание / редактирование).
 * @var array $p @var array $links @var array $state @var array $features @var array $options @var array $cats
 * @var array $images @var array $errors @var array $seoTpl @var array $seoUk @var array $badges @var string $maxUpload
 * @var App\Core\View $view
 */
use App\Controllers\Admin\BaseController;
use App\Services\AdminCatalog;

$id = (int) $p['id'];
$err = static fn(string $k) => isset($errors[$k]) ? '<small class="ac-err">' . e($errors[$k]) . '</small>' : '';
$num = static fn($v) => ($v === null || $v === '') ? '' : rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.');
$badge = (string) ($p['badge'] ?? '');
$badgeCustom = $badge !== '' && !isset($badges[$badge]);
$statusNames = ['public' => '', 'hidden' => 'скрытая', 'private' => 'служебная'];
$i18n = [AdminCatalog::class, 'i18nField'];
$paths = AdminCatalog::paths($cats);
?>
<?php if ($errors): ?><div class="adm-flash err ac-flash">Не сохранено: <?= e(implode(' ', $errors)) ?></div><?php endif; ?>

<form method="post" enctype="multipart/form-data" id="product-form" class="ac-form" action="<?= $id ? '/admin/products/' . $id . '/' : '/admin/products/new/' ?>">
<?= BaseController::tokenField() ?>
<div class="grid3">
  <div class="ac-col">
    <section class="card" aria-labelledby="h-main">
      <div class="ac-cardhd"><h2 id="h-main">Основное</h2><?= AdminCatalog::langTabs() ?></div>
      <?= $i18n('text', 'name', 'Название *', $p, ['required' => true, 'max' => 255, 'id' => 'p-name', 'error' => $err('name')]) ?>
      <div class="fld">
        <label for="p-url"><span>Адрес на сайте</span></label>
        <div class="ac-url"><span class="muted">/product/</span><input type="text" name="url" id="p-url" value="<?= e($p['url']) ?>" maxlength="190" data-id="<?= $id ?>" autocomplete="off"><span class="muted">/</span>
          <button type="button" class="btn btn-sm" id="url-auto">Из названия</button></div>
        <small class="hint" id="url-hint">Пусто — адрес сформируется из названия (транслитом).</small><?= $err('url') ?>
        <?php if ($id): ?><label class="chk ac-small"><input type="checkbox" name="redirect" value="1" checked> При смене адреса — 301-редирект со старого</label><?php endif; ?>
      </div>
      <label class="fld"><span>Артикул</span><input type="text" name="sku" value="<?= e($p['sku']) ?>" maxlength="255"></label>
      <div class="row3">
        <label class="fld"><span>Цена за пару, грн</span><input type="number" name="price" id="p-price" value="<?= e($num($p['price'])) ?>" min="0" step="0.01" inputmode="decimal"></label>
        <label class="fld"><span>Старая цена за пару</span><input type="number" name="compare_price" value="<?= e($num($p['compare_price'])) ?>" min="0" step="0.01" inputmode="decimal"></label>
        <label class="fld"><span>Закупочная, грн</span><input type="number" name="purchase_price" value="<?= e($num($p['purchase_price'])) ?>" min="0" step="0.01" inputmode="decimal"></label>
      </div>
      <div class="row3">
        <label class="fld"><span>Пар в ящике</span><input type="number" name="box_qty" id="p-box" value="<?= e((string) $p['box_qty']) ?>" min="1" step="1"></label>
        <label class="fld"><span>Мин. заказ, пар</span><input type="number" name="min_qty" value="<?= e((string) $p['min_qty']) ?>" min="1" step="1" placeholder="= пар в ящике"></label>
        <label class="fld"><span>Остаток, пар</span><input type="number" name="stock" value="<?= $p['stock'] === null ? '' : (int) $p['stock'] ?>" min="0" step="1" placeholder="не ограничен"></label>
      </div>
      <p class="muted ac-boxprice">Цена за ящик: <b id="box-price"><?= e(price_format((float) $p['price'] * max(1, (int) $p['box_qty']))) ?></b> · остаток пустой — не ограничен, 0 — «нет в наличии».</p>
    </section>

    <section class="card" aria-labelledby="h-feat">
      <h2 id="h-feat">Характеристики</h2>
      <p class="hint ac-small muted">Выберите значение из списка (начните вводить) или добавьте новое — Enter. «Бренд» и «Размер» хранятся и в самом товаре (бренд, размерный ряд).</p>
      <div class="ac-feats">
      <?php foreach ($features as $fid => $f):
        $fid = (int) $fid;
        $multi = (int) $f['multiple'] && !in_array($f['code'], ['brand', 'size'], true);
        $note = $statusNames[$f['status']] ?? '';
        if ($f['code'] === 'size') $note = 'размерный ряд';
        if ($f['code'] === 'brand') $note = 'бренд товара'; ?>
        <div class="fld ac-feat" data-fid="<?= $fid ?>" data-multiple="<?= $multi ? 1 : 0 ?>" data-remote="<?= isset($options[$fid]) ? 0 : 1 ?>" data-color="<?= $f['type'] === 'color' ? 1 : 0 ?>">
          <span id="fl-<?= $fid ?>"><?= e($f['name']) ?><?= $note !== '' ? ' <small class="muted">(' . e($note) . ')</small>' : '' ?><?= $multi ? ' <small class="muted">· несколько</small>' : '' ?></span>
          <div class="ac-chips">
            <?php foreach ($state[$fid] ?? [] as $it): ?>
              <span class="ac-chip" data-text="<?= e(mb_strtolower($it['value'])) ?>"><?php if ($it['code'] !== null && $f['type'] === 'color'): ?><i class="ac-sw" style="background:#<?= e(sprintf('%06x', (int) $it['code'])) ?>"></i><?php endif; ?><input type="hidden" name="<?= $it['id'] ? 'fv' : 'fn' ?>[<?= $fid ?>][]" value="<?= e($it['id'] ?: $it['value']) ?>"><?= e($it['value']) ?><?= $it['id'] ? '' : ' <small>(новое)</small>' ?><button type="button" class="ac-chip-x" aria-label="Убрать «<?= e($it['value']) ?>»">×</button></span>
            <?php endforeach; ?>
            <input type="text" class="ac-chip-in" aria-labelledby="fl-<?= $fid ?>" placeholder="<?= $multi ? 'Добавить…' : 'Выбрать…' ?>" autocomplete="off" maxlength="255">
          </div>
          <div class="ac-dd" role="listbox" hidden></div>
        </div>
      <?php endforeach; ?>
      </div>
      <script type="application/json" id="feat-options"><?= json_encode($options, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
    </section>

    <section class="card" aria-labelledby="h-text">
      <div class="ac-cardhd"><h2 id="h-text">Описание</h2><?= AdminCatalog::langTabs() ?></div>
      <?= $i18n('area', 'summary', 'Краткое описание', $p, ['rows' => 3, 'max' => 60000]) ?>
      <?= $i18n('html', 'description', 'Описание (HTML)', $p, ['rows' => 12]) ?>
    </section>

    <section class="card" aria-labelledby="h-seo">
      <div class="ac-cardhd"><h2 id="h-seo">SEO</h2><?= AdminCatalog::langTabs() ?></div>
      <p class="hint ac-small muted">Заполняйте только если нужно своё значение. Пустое поле — на сайте используется шаблон из настроек SEO (показан серым).</p>
      <div data-l="ru"><?= $view->partial('admin/partials/serp', ['serp' => ['type' => 'product', 'row' => $p]]) ?></div>
      <div data-l="uk" hidden><?= $view->partial('admin/partials/serp', ['serp' => AdminCatalog::serpUk($p['url'] !== '' ? '/ua/product/' . $p['url'] . '/' : '', $p, $seoUk)]) ?></div>
      <?= $i18n('text', 'seo_name', 'SEO-название ({$product.seo_name} в шаблонах)', $p, ['max' => 500, 'placeholder' => $p['name'], 'hint' => 'Пусто — используется название товара.', 'tpl_uk' => $seoUk['seo_name']]) ?>
      <?= $i18n('text', 'h1', 'Заголовок H1', $p, ['max' => 500, 'tpl' => $seoTpl['h1'] ?: $p['name'], 'tpl_uk' => $seoUk['h1']]) ?>
      <?= $i18n('text', 'meta_title', 'Title', $p, ['max' => 500, 'tpl' => $seoTpl['meta_title'] ?: $p['name'], 'tpl_uk' => $seoUk['meta_title']]) ?>
      <?= $i18n('area', 'meta_description', 'Description', $p, ['max' => 5000, 'tpl' => $seoTpl['meta_description'], 'tpl_uk' => $seoUk['meta_description']]) ?>
      <?= $i18n('area', 'meta_keywords', 'Keywords', $p, ['max' => 5000, 'tpl' => $seoTpl['meta_keywords'], 'tpl_uk' => $seoUk['meta_keywords']]) ?>
    </section>
  </div>

  <div class="ac-col">
    <section class="card" aria-labelledby="h-pub">
      <h2 id="h-pub">Публикация</h2>
      <label class="fld"><span>Статус</span><select name="status"><option value="1"<?= (int) $p['status'] ? ' selected' : '' ?>>Опубликован на сайте</option><option value="0"<?= !(int) $p['status'] ? ' selected' : '' ?>>Скрыт</option></select></label>
      <label class="chk ac-mb"><input type="checkbox" name="in_stock" value="1"<?= (int) $p['in_stock'] ? ' checked' : '' ?>> В наличии</label>
      <label class="fld"><span>Бейдж</span>
        <select name="badge" id="p-badge"><?php foreach ($badges as $k => $label): ?><option value="<?= e($k) ?>"<?= !$badgeCustom && $badge === $k ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
          <option value="custom"<?= $badgeCustom ? ' selected' : '' ?>>Свой текст…</option></select></label>
      <label class="fld" id="badge-custom"<?= $badgeCustom ? '' : ' hidden' ?>><span>Текст бейджа</span><input type="text" name="badge_custom" value="<?= $badgeCustom ? e($badge) : '' ?>" maxlength="64"></label>
      <?php if ($id): ?>
        <p class="muted ac-small">№<?= $id ?> · создан <?= e(date('d.m.Y H:i', strtotime((string) $p['created_at']))) ?><?= $p['updated_at'] ? ' · изменён ' . e(date('d.m.Y H:i', strtotime((string) $p['updated_at']))) : '' ?></p>
      <?php endif; ?>
    </section>

    <section class="card" aria-labelledby="h-cats">
      <h2 id="h-cats">Категории</h2>
      <label class="fld"><span>Основная категория</span>
        <select name="category_id" id="p-maincat"><option value="">— не выбрана —</option>
          <?php foreach ($cats as $c): if ((int) $c['type'] === 1) continue; ?>
            <option value="<?= (int) $c['id'] ?>" data-path="<?= e($paths[(int) $c['id']] ?? $c['name']) ?>"<?= (int) $p['category_id'] === (int) $c['id'] ? ' selected' : '' ?>><?= e(str_repeat('— ', (int) $c['depth']) . $c['name'] . (!(int) $c['status'] ? ' (скрыта)' : '')) ?></option>
          <?php endforeach; ?></select><?= $err('category_id') ?>
        <small class="hint"><b id="p-maincat-path" class="ac-path"><?= $p['category_id'] && isset($paths[(int) $p['category_id']]) ? e($paths[(int) $p['category_id']]) : '' ?></b> Хлебные крошки и SEO-шаблоны товара.</small></label>
      <div class="fld">
        <label for="cat-filter"><span>Также показывать в категориях</span></label>
        <input type="search" id="cat-filter" placeholder="Найти категорию…">
        <div class="ac-cattree" id="cat-tree">
          <?php foreach ($cats as $c):
            $cid = (int) $c['id']; $dyn = (int) $c['type'] === 1; ?>
            <label class="chk" style="padding-left:<?= (int) $c['depth'] * 16 ?>px" data-name="<?= e(mb_strtolower($c['name'])) ?>" title="<?= e($paths[$cid] ?? $c['name']) ?>">
              <input type="checkbox" name="cats[]" value="<?= $cid ?>"<?= in_array($cid, $links, true) ? ' checked' : '' ?><?= $dyn ? ' disabled' : '' ?>>
              <span><?= e($c['name']) ?><?= $dyn ? ' <small class="muted">(по условию)</small>' : '' ?><?= !(int) $c['status'] ? ' <small class="muted">(скрыта)</small>' : '' ?></span>
            </label>
          <?php endforeach; ?>
        </div>
        <small class="hint">Товар попадает и во все родительские категории, где включено «показывать товары подкатегорий».</small>
      </div>
    </section>

    <section class="card" aria-labelledby="h-photo">
      <h2 id="h-photo">Фото</h2>
      <?php if ($id): ?>
        <div class="ac-photos" id="photos" data-pid="<?= $id ?>">
          <?php foreach ($images as $i => $im): ?>
            <div class="ac-ph" draggable="true" data-id="<?= (int) $im['id'] ?>">
              <img src="<?= e($im['thumb']) ?>" alt="Фото <?= $i + 1 ?>" loading="lazy">
              <span class="ac-ph-main">Главное</span>
              <div class="ac-ph-tools">
                <button type="button" data-act="left" aria-label="Переместить влево">‹</button>
                <button type="button" data-act="main" aria-label="Сделать главным">★</button>
                <button type="button" data-act="right" aria-label="Переместить вправо">›</button>
                <button type="button" data-act="del" aria-label="Удалить фото">×</button>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
        <label class="ac-drop" id="photo-drop">
          <input type="file" id="photo-input" accept="image/jpeg,image/png,image/webp,image/gif" multiple>
          <span><b>Загрузить фото</b> — выберите файлы или перетащите сюда</span>
          <small class="muted">jpg, png, webp, gif · до 15 МБ (сервер принимает до <?= e($maxUpload) ?>). Порядок меняется перетаскиванием, первое фото — главное.</small>
        </label>
        <p class="ac-small" id="photo-status" role="status" aria-live="polite"></p>
      <?php else: ?>
        <label class="fld"><span>Фото товара</span><input type="file" name="images[]" accept="image/jpeg,image/png,image/webp,image/gif" multiple>
          <small class="hint">Загрузятся при сохранении; первое — главное. Потом можно менять порядок и добавлять ещё.</small></label>
      <?php endif; ?>
    </section>
  </div>
</div>

<div class="form-actions">
  <button type="submit" class="btn btn-p"><?= $id ? 'Сохранить' : 'Создать товар' ?></button>
  <a class="btn" href="/admin/products/">К списку</a>
  <span class="sp"></span>
  <?php if ($id): ?><button type="submit" class="btn btn-d" form="del-form">Удалить товар</button><?php endif; ?>
</div>
</form>
<?php if ($id): ?>
<form id="del-form" method="post" action="/admin/products/<?= $id ?>/delete/" data-confirm="Удалить товар «<?= e($p['name']) ?>» безвозвратно? Фото тоже будут удалены."><?= BaseController::tokenField() ?></form>
<?php endif; ?>
