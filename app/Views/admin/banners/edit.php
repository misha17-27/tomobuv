<?php
/**
 * Форма баннера с живым предпросмотром; тексты RU и UA (общий переключатель «RU | UA», admin/partials/lang-bar).
 * @var array $b @var bool $isNew @var array $errors @var string $uploadLimit @var string $lang
 */
use App\Controllers\Admin\BaseController;
use App\Controllers\Admin\BannersController;
use App\Services\AdminCatalog;

$err = static fn(string $k) => isset($errors[$k]) ? '<span class="fld-err" role="alert">' . e($errors[$k]) . '</span>' : '';
$inv = static fn(string $k) => isset($errors[$k]) ? ' aria-invalid="true"' : '';
$action = $isNew ? '/admin/banners/new/' : '/admin/banners/' . (int) $b['id'] . '/';
$places = BannersController::PLACES;
if (!isset($places[$b['place']])) $places[$b['place']] = [$b['place'], 'Место из старой версии сайта'];
$v = static fn(string $k) => (string) ($b[$k] ?? '');
$ru = AdminCatalog::langTag('ru');
$ua = AdminCatalog::langTag('uk');
// что показать в предпросмотре для открытого языка (UA пусто → русский текст)
$pv = static fn(string $k) => $lang === 'uk' && $v($k . '_uk') !== '' ? $v($k . '_uk') : $v($k);
?>
<?php if ($errors): ?><div class="flash bad">Проверьте поля формы: <?= e(implode('; ', $errors)) ?></div><?php endif; ?>

<form method="post" action="<?= e($action) ?>" class="ed-form" data-lang="<?= e($lang) ?>" enctype="multipart/form-data" novalidate data-banner-form>
  <?= BaseController::tokenField() ?>
  <?= $view->partial('admin/partials/lang-bar', ['lang' => $lang, 'what' => 'главной']) ?>
  <div class="grid3">
    <div>
      <div class="card">
        <fieldset class="fld bare"><legend class="lbl">Место показа</legend>
          <div class="place-row">
          <?php foreach ($places as $k => [$label, $hint]): ?>
            <label class="place-opt"><input type="radio" name="place" value="<?= e($k) ?>"<?= $b['place'] === $k ? ' checked' : '' ?>><span><b><?= e($label) ?></b><small><?= e($hint) ?></small></span></label>
          <?php endforeach; ?>
          </div>
          <?= $err('place') ?>
        </fieldset>

        <label class="fld l-ru"><span>Заголовок * <?= $ru ?></span>
          <input type="text" name="title" value="<?= e($v('title')) ?>" maxlength="255" required data-prev="title"<?= $inv('title') ?>><?= $err('title') ?>
        </label>
        <label class="fld l-uk"><span>Заголовок <?= $ua ?></span>
          <input type="text" name="title_uk" value="<?= e($v('title_uk')) ?>" maxlength="255" placeholder="<?= e($v('title')) ?>" data-prev="title" data-uk>
        </label>
        <label class="fld l-ru"><span>Текст <?= $ru ?></span>
          <textarea name="text" rows="3" maxlength="500" class="plain" data-prev="text"><?= e($v('text')) ?></textarea>
          <small class="hint">Для слайдера — 1–2 предложения. У широких баннеров текст не выводится.</small>
        </label>
        <label class="fld l-uk"><span>Текст <?= $ua ?></span>
          <textarea name="text_uk" rows="3" maxlength="500" class="plain" placeholder="<?= e($v('text')) ?>" data-prev="text" data-uk><?= e($v('text_uk')) ?></textarea>
        </label>
        <div class="row2">
          <label class="fld l-ru"><span>Надпись на кнопке <?= $ru ?></span>
            <input type="text" name="button" value="<?= e($v('button')) ?>" maxlength="64" placeholder="Смотреть" data-prev="button">
          </label>
          <label class="fld l-uk"><span>Надпись на кнопке <?= $ua ?></span>
            <input type="text" name="button_uk" value="<?= e($v('button_uk')) ?>" maxlength="64" placeholder="<?= e($v('button') ?: 'Дивитися') ?>" data-prev="button" data-uk>
          </label>
          <label class="fld"><span>Ссылка *</span>
            <input type="text" name="link" value="<?= e($v('link')) ?>" maxlength="500" placeholder="/category/aktsiya/" required<?= $inv('link') ?>><?= $err('link') ?>
            <small class="hint">На /ua/ ссылка сама получит префикс /ua/.</small>
          </label>
        </div>
        <fieldset class="fld bare img-field" data-img-field><legend class="lbl">Картинка *</legend>
          <div class="img-row">
            <div class="img-prev sm<?= $v('image') ? '' : ' empty' ?>"><img id="bn-prev" src="<?= e(media($v('image'))) ?>" alt="Картинка баннера"><span>Нет картинки</span></div>
            <div class="img-acts">
              <button type="button" class="btn" data-media-pick="#bn-image" data-media-preview="#bn-prev">Выбрать из медиатеки</button>
              <label class="fld"><span>или загрузить файл</span><input type="file" name="image_file" accept="image/jpeg,image/png,image/gif,image/webp" data-upload-now<?= $inv('image') ?>></label>
            </div>
          </div>
          <label class="fld"><span>Путь к картинке</span>
            <input id="bn-image" type="text" name="image" value="<?= e($v('image')) ?>" maxlength="255" placeholder="/uploads/2026/09/banner.jpg" data-prev="image"<?= $inv('image') ?>>
            <small class="hint">JPG, PNG, WEBP до <?= e($uploadLimit) ?> — загруженный файл сразу попадёт в медиатеку. Одна картинка для обеих версий сайта.</small>
          </label>
          <?= $err('image') ?>
        </fieldset>
        <label class="check"><input type="checkbox" name="status" value="1"<?= (int) $b['status'] ? ' checked' : '' ?>> Показывать на сайте</label>
      </div>
    </div>
    <div>
      <div class="card">
        <h2>Как будет выглядеть <span class="muted" data-prev-lang><?= $lang === 'uk' ? 'UA' : 'RU' ?></span></h2>
        <div class="bn-prev" data-place="<?= e($b['place']) ?>"<?= media('/wa-data/x') !== '/wa-data/x' ? ' data-media-base="' . e(rtrim((string) App\Core\App::config('images.remote_base', ''), '/')) . '"' : '' ?>>
          <div class="bn-slide">
            <div class="t"><b data-out="title"><?= e($pv('title') ?: 'Заголовок') ?></b><p data-out="text"><?= e($pv('text')) ?></p><span class="b" data-out="button"><?= e($pv('button') ?: ($lang === 'uk' ? 'Дивитися' : 'Смотреть')) ?></span></div>
            <div class="p"><img src="<?= e(media($v('image'))) ?>" alt="" data-out="image"<?= $v('image') ? '' : ' hidden' ?>></div>
          </div>
          <div class="bn-wide">
            <img src="<?= e(media($v('image'))) ?>" alt="" data-out="image"<?= $v('image') ? '' : ' hidden' ?>>
            <div><b data-out="title"><?= e($pv('title') ?: 'Заголовок') ?></b><span class="b" data-out="button"><?= e($pv('button') ?: ($lang === 'uk' ? 'Перейти' : 'Перейти')) ?></span></div>
          </div>
        </div>
        <p class="hint">Упрощённый вид — точные размеры зависят от ширины экрана.</p>
      </div>
    </div>
  </div>
  <div class="savebar">
    <button class="btn btn-p" type="submit"><?= $isNew ? 'Добавить баннер' : 'Сохранить' ?></button>
    <a class="btn" href="/admin/banners/">Отмена</a>
    <?php if (!$isNew): ?><span class="sp"></span><button class="btn btn-d" type="submit" form="del-form">Удалить</button><?php endif; ?>
  </div>
</form>
<?php if (!$isNew): ?>
<form id="del-form" method="post" action="/admin/banners/<?= (int) $b['id'] ?>/delete/" data-confirm="Удалить баннер «<?= e($b['title']) ?>»?">
  <?= BaseController::tokenField() ?>
</form>
<?php endif; ?>
