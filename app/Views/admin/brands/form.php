<?php
/**
 * Бренд: создание / редактирование.
 * @var array $b @var array $errors @var int $all @var array $dynCats @var App\Core\View $view
 */
use App\Controllers\Admin\BaseController;
use App\Services\AdminCatalog;
use App\Services\Catalog;

$id = (int) $b['id'];
$err = static fn(string $k) => isset($errors[$k]) ? '<small class="ac-err">' . e($errors[$k]) . '</small>' : '';
$i18n = [AdminCatalog::class, 'i18nField'];
$name = (string) $b['name'];
$ruTitle = trim((string) ($b['title'] ?? '')) ?: $name;
$ruH1 = trim((string) ($b['h1'] ?? '')) ?: $name;
$img = (string) ($b['image'] ?? '');
// что покажет /ua/ при пустом украинском поле — своё русское значение, иначе название бренда
$ukFb = ['title' => $ruTitle, 'meta_description' => trim((string) ($b['meta_description'] ?? '')), 'h1' => $ruH1];
?>
<?php if ($errors): ?><div class="flash bad">Не сохранено: <?= e(implode(' ', $errors)) ?></div><?php endif; ?>

<form method="post" enctype="multipart/form-data" id="brand-form" class="ac-form ed-form" data-lang="ru" action="<?= $id ? '/admin/brands/' . $id . '/' : '/admin/brands/new/' ?>">
<?= BaseController::tokenField() ?>
<?= $view->partial('admin/partials/lang-bar', ['what' => 'бренда']) ?>
<div class="grid3">
  <div class="ac-col">
    <section class="card" aria-labelledby="h-main">
      <h2 id="h-main">Основное</h2>
      <label class="fld"><span>Название *</span><input type="text" name="name" value="<?= e($name) ?>" required maxlength="255">
        <small class="hint">Это же значение характеристики «Бренд» у товаров. На украинской версии — то же название, если не задано поле UA.</small><?= $err('name') ?></label>
      <div class="fld l-uk"><label class="lbl" for="b-name-uk">Название <?= AdminCatalog::langTag('uk') ?></label><input type="text" name="name_uk" id="b-name-uk" value="<?= e((string) ($b['name_uk'] ?? '')) ?>" maxlength="255" placeholder="<?= e($name) ?>">
        <small class="hint">Только для служебных названий («Не указано» → «Не вказано»). Торговые марки не переводятся. Пусто — на украинской версии русское название. Адрес бренда от этого поля не зависит.</small></div>
      <div class="fld">
        <label for="b-url"><span>Адрес на сайте</span></label>
        <div class="ac-url"><span class="muted">/brand/</span><input type="text" name="url" id="b-url" value="<?= e($b['url']) ?>" maxlength="190" autocomplete="off"><span class="muted">/</span></div>
        <small class="hint">Как на старом сайте — название бренда (пробел в адресе станет «+»: /brand/Mona+Lisa/). Пусто — по названию.<?= $id ? ' Сейчас: <a href="' . e(Catalog::brandUrl($b)) . '" target="_blank" rel="noopener">' . e(rawurldecode(Catalog::brandUrl($b))) . '</a>' : '' ?></small><?= $err('url') ?>
        <?php if ($id): ?><label class="chk ac-small"><input type="checkbox" name="redirect" value="1" checked> При смене адреса — 301-редирект со старого</label><?php endif; ?>
      </div>
    </section>

    <section class="card" aria-labelledby="h-text">
      <h2 id="h-text">Тексты страницы бренда</h2>
      <?= $i18n('area', 'summary', 'Краткое описание', $b, ['rows' => 2, 'max' => 500, 'hint' => 'Показывается в списке брендов и под заголовком.']) ?>
      <?= $i18n('html', 'description', 'Описание над товарами', $b, ['rows' => 8, 'id' => 'b-desc']) ?>
      <?= $i18n('html', 'seo_description', 'SEO-текст под товарами', $b, ['rows' => 10, 'id' => 'b-seodesc']) ?>
    </section>

    <section class="card" aria-labelledby="h-seo">
      <h2 id="h-seo">SEO</h2>
      <p class="hint"><?= e(AdminCatalog::SEO_INTRO) ?></p>
      <div class="l-ru"><?= $view->partial('admin/partials/serp', ['serp' => ['type' => 'brand', 'row' => $b]]) ?></div>
      <div class="l-uk"><?= $view->partial('admin/partials/serp', ['serp' => AdminCatalog::serpUk($b['url'] !== '' ? '/ua' . Catalog::brandUrl($b) : '', $b, $ukFb, 'title')]) ?></div>
      <?= AdminCatalog::seoField('meta_title', 'title', $b, ['placeholder' => $name, 'empty' => 'Пусто — название бренда.', 'tpl_uk' => $ukFb['title']]) ?>
      <?= AdminCatalog::seoField('meta_description', 'meta_description', $b) ?>
      <?= AdminCatalog::seoField('meta_keywords', 'meta_keywords', $b) ?>
      <?= AdminCatalog::seoField('h1', 'h1', $b, ['placeholder' => $name, 'empty' => 'Пусто — название бренда.', 'tpl_uk' => $ukFb['h1']]) ?>
    </section>
  </div>

  <div class="ac-col">
    <section class="card" aria-labelledby="h-pub">
      <h2 id="h-pub">Показ</h2>
      <label class="chk ac-mb"><input type="checkbox" name="hidden" value="1"<?= (int) $b['hidden'] ? ' checked' : '' ?>> Скрыть из списка брендов на сайте</label>
      <label class="fld"><span>Порядок</span><input type="number" name="sort" value="<?= (int) $b['sort'] ?>" step="1"></label>
      <?php if ($id): ?>
      <dl class="detail">
        <div><dt>№</dt><dd><?= $id ?></dd></div>
        <div><dt>На сайте</dt><dd><a href="/admin/products/?brand=<?= $id ?>&amp;status=1"><?= number_format((int) $b['product_count'], 0, '', ' ') ?></a> <?= plural((int) $b['product_count'], 'товар', 'товара', 'товаров') ?></dd></div>
        <div><dt>Всего</dt><dd><a href="/admin/products/?brand=<?= $id ?>"><?= number_format($all, 0, '', ' ') ?></a> (со скрытыми)</dd></div>
      </dl>
      <?php endif; ?>
    </section>

    <section class="card" aria-labelledby="h-img">
      <h2 id="h-img">Логотип</h2>
      <img class="ac-img-prev" id="b-img-prev" alt="Логотип <?= e($name) ?>"<?= $img !== '' ? ' src="' . e(media($img)) . '"' : ' hidden' ?>>
      <div class="fld">
        <label for="b-image"><span>Путь к логотипу</span></label>
        <div class="ac-url"><input type="text" name="image" id="b-image" value="<?= e($img) ?>" placeholder="/uploads/…" maxlength="255" autocomplete="off">
          <button type="button" class="btn btn-sm" data-clear="#b-image" data-clear-preview="#b-img-prev" aria-label="Убрать логотип" title="Убрать логотип">×</button></div>
        <button type="button" class="btn" data-media-pick="#b-image" data-media-preview="#b-img-prev">Выбрать из медиатеки</button>
        <?= $err('image') ?>
      </div>
      <label class="fld"><span>Или загрузить с компьютера</span><input type="file" name="image_file" accept="image/jpeg,image/png,image/webp,image/gif" data-preview="#b-img-prev">
        <small class="hint">jpg, png, webp, gif до 15 МБ — уменьшится до 400 px и сохранится в /uploads/brands/.</small></label>
    </section>

    <?php if ($dynCats): ?>
    <section class="card" aria-labelledby="h-dyn">
      <h2 id="h-dyn">В условиях категорий</h2>
      <ul class="ac-list"><?php foreach ($dynCats as $c): ?><li><a href="/admin/categories/<?= (int) $c['id'] ?>/"><?= e($c['name']) ?></a> <small class="muted"><code><?= e($c['conditions']) ?></code></small></li><?php endforeach; ?></ul>
    </section>
    <?php endif; ?>
  </div>
</div>

<div class="form-actions">
  <button type="submit" class="btn btn-p"><?= $id ? 'Сохранить' : 'Создать бренд' ?></button>
  <a class="btn" href="/admin/brands/">К списку</a>
  <span class="hint hide-sm">Ctrl+S — сохранить</span>
  <span class="sp"></span>
  <?php if ($id && !$all && !$dynCats): ?><button type="submit" class="btn btn-d" form="del-form">Удалить бренд</button><?php endif; ?>
</div>
</form>
<?php if ($id && !$all && !$dynCats): ?>
<form id="del-form" method="post" action="/admin/brands/<?= $id ?>/delete/" data-confirm="Удалить бренд «<?= e($name) ?>»?"><?= BaseController::tokenField() ?></form>
<?php endif; ?>
<?= $view->partial('admin/partials/editor') ?>
