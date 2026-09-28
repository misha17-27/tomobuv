<?php
/**
 * Форма статьи блога: RU и UA поля (переключатель «RU | UA»), анонс и текст — HTML-редактор.
 * @var array $post @var bool $isNew @var array $errors @var string $uploadLimit @var string $lang
 * @var array $blogName [ru|uk => название блога] — для подсказки title по умолчанию
 */
use App\Controllers\Admin\BaseController;
use App\Controllers\Admin\BlogController;
use App\Core\App;

$err = static fn(string $k) => isset($errors[$k]) ? '<span class="fld-err" role="alert">' . e($errors[$k]) . '</span>' : '';
$inv = static fn(string $k) => isset($errors[$k]) ? ' aria-invalid="true"' : '';
$action = $isNew ? '/admin/blog/new/' : '/admin/blog/' . (int) $post['id'] . '/';
$dt = $post['published_at'] ? date('Y-m-d\TH:i', strtotime((string) $post['published_at'])) : '';
$mediaBase = (string) App::config('images.remote_base', '');
$serpHost = (string) (parse_url((string) App::config('base_url', ''), PHP_URL_HOST) ?: 'tomobuv.com.ua');   // превью Google
$v = static fn(string $k) => (string) ($post[$k] ?? '');
$ua = '<i class="lp" title="Украинская версия">UA</i>';
?>
<?php if ($errors): ?><div class="flash bad">Проверьте поля формы: <?= e(implode('; ', $errors)) ?></div><?php endif; ?>

<form method="post" action="<?= e($action) ?>" class="ed-form" data-lang="<?= e($lang) ?>" enctype="multipart/form-data" novalidate>
  <?= BaseController::tokenField() ?>
  <?= $view->partial('admin/pages/_lang', ['lang' => $lang, 'what' => 'статьи']) ?>
  <div class="grid3">
    <div>
      <div class="card">
        <label class="fld l-ru"><span>Заголовок *</span>
          <input type="text" name="title" value="<?= e($v('title')) ?>" maxlength="255" required<?= $inv('title') ?>>
          <?= $err('title') ?>
        </label>
        <label class="fld l-uk"><span>Заголовок <?= $ua ?></span>
          <input type="text" name="title_uk" value="<?= e($v('title_uk')) ?>" maxlength="255" placeholder="<?= e($v('title')) ?>" data-uk>
        </label>
        <div class="fld">
          <label class="lbl" for="bl-url">Адрес статьи</label>
          <div class="url-in"><span class="pre">/blog/</span><input id="bl-url" type="text" name="url" value="<?= e($v('url')) ?>" maxlength="190" placeholder="kak-vybrat-obuv" data-url-orig="<?= e($isNew ? '' : $v('url')) ?>" data-slug-from="title" data-slug-noslash<?= $inv('url') ?>><span class="pre">/</span></div>
          <?= $err('url') ?><small class="hint">Латиница, цифры и «-». Пусто — адрес создастся из заголовка. Украинская версия — /ua/blog/…</small>
        </div>
        <?php if (!$isNew && $post['status'] === 'published'): ?>
          <label class="check ed-redirect hidden"><input type="checkbox" name="make_redirect" value="1" checked> Создать 301-редирект со старого адреса /blog/<?= e($v('url')) ?>/</label>
        <?php endif; ?>

        <?php foreach (['' => 'l-ru', '_uk' => 'l-uk'] as $sfx => $cls): $isUk = $sfx !== ''; ?>
        <div class="fld <?= $cls ?>">
          <div class="lbl-row"><label class="lbl" for="bl-cut<?= $sfx ?>">Анонс (текст до «Читать далее»)<?= $isUk ? ' ' . $ua : '' ?></label>
            <?php if ($isUk): ?><button type="button" class="btn btn-sm" data-copy-from="text_before_cut" data-copy-to="text_before_cut_uk">Скопировать русский</button><?php endif; ?></div>
          <textarea id="bl-cut<?= $sfx ?>" name="text_before_cut<?= $sfx ?>" rows="5" data-editor<?= $isUk ? ' data-uk' : '' ?> data-media-base="<?= e($mediaBase) ?>"><?= e($v('text_before_cut' . $sfx)) ?></textarea>
          <small class="hint">Показывается в списке статей и на главной. Пусто — возьмётся начало текста.</small>
        </div>
        <div class="fld <?= $cls ?>">
          <div class="lbl-row"><label class="lbl" for="bl-text<?= $sfx ?>">Текст статьи<?= $isUk ? ' ' . $ua : '' ?></label>
            <?php if ($isUk): ?><button type="button" class="btn btn-sm" data-copy-from="text" data-copy-to="text_uk">Скопировать русский текст</button><?php endif; ?></div>
          <textarea id="bl-text<?= $sfx ?>" name="text<?= $sfx ?>" rows="22" data-editor<?= $isUk ? ' data-uk' : '' ?> data-media-base="<?= e($mediaBase) ?>"><?= e($v('text' . $sfx)) ?></textarea>
          <?php if ($isUk): ?><small class="hint">Пусто — на /ua/ показывается русский текст статьи.</small><?php endif; ?>
        </div>
        <?php endforeach; ?>
      </div>
    </div>

    <div>
      <div class="card">
        <h2>Публикация</h2>
        <label class="fld"><span>Статус</span>
          <select name="status"><?php foreach (BlogController::STATUSES as $k => $label): ?><option value="<?= e($k) ?>"<?= $post['status'] === $k ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select>
        </label>
        <label class="fld"><span>Дата публикации</span>
          <input type="datetime-local" name="published_at" value="<?= e($dt) ?>"<?= $inv('published_at') ?>>
          <?= $err('published_at') ?><small class="hint">Статьи в блоге идут от новых к старым. Дата в будущем — статья появится после этого времени (готовые страницы сайта хранятся в кэше до суток; сразу — кнопка «Очистить кэш» на экране <a href="/admin/status/">«Состояние системы»</a>).</small>
        </label>
      </div>
      <div class="card">
        <h2>Картинка</h2>
        <div class="img-field" data-img-field>
          <div class="img-prev<?= $v('image') ? '' : ' empty' ?>"><img id="bl-prev" src="<?= e(media($v('image'))) ?>" alt="Картинка статьи"><span>Нет картинки</span></div>
          <p><button type="button" class="btn" data-media-pick="#bl-image" data-media-preview="#bl-prev">Выбрать из медиатеки</button></p>
          <label class="fld"><span>или загрузить файл</span><input type="file" name="image_file" accept="image/jpeg,image/png,image/gif,image/webp" data-upload-now<?= $inv('image') ?>>
            <small class="hint">JPG, PNG, WEBP до <?= e($uploadLimit) ?> — файл сразу попадёт в медиатеку.</small></label>
          <label class="fld"><span>Путь к картинке</span>
            <input id="bl-image" type="text" name="image" value="<?= e($v('image')) ?>" maxlength="255" placeholder="/uploads/2026/09/foto.jpg">
          </label>
          <?= $err('image') ?>
          <?php if ($v('image')): ?><label class="check"><input type="checkbox" name="image_remove" value="1"> Убрать картинку</label><?php endif; ?>
        </div>
      </div>
      <div class="card">
        <h2 id="h-seo">SEO</h2>
        <?php foreach (['ru' => '', 'uk' => '_uk'] as $l => $sfx): $isUk = $l === 'uk';
          // что будет в title, если поле пустое (как на витрине): UA — русский meta_title, иначе «Блог » Заголовок»
          $auto = ($blogName[$l] ?? 'Tomobuv') . ' » ' . (($isUk ? $v('title_uk') : '') ?: ($v('title') ?: 'Заголовок'));
          $tph = $isUk ? ($v('meta_title') ?: $auto) : $auto; ?>
        <div class="l-<?= $l ?>">
          <div class="serp" data-serp="<?= $l ?>" aria-label="Как статья выглядит в поиске Google">
            <div class="serp-url"><?= e($serpHost) ?> › <?= $isUk ? 'ua › ' : '' ?>blog › <?= e($v('url') ?: '…') ?></div>
            <div class="serp-title" data-serp-title><?= e($v('meta_title' . $sfx) ?: $tph) ?></div>
            <div class="serp-desc" data-serp-desc><?= e($v('meta_description' . $sfx) ?: ($isUk ? $v('meta_description') : '')) ?></div>
          </div>
          <label class="fld"><span>Title<?= $isUk ? ' ' . $ua : '' ?></span>
            <input type="text" name="meta_title<?= $sfx ?>" value="<?= e($v('meta_title' . $sfx)) ?>" maxlength="500" placeholder="<?= e($tph) ?>" data-counter="70" data-serp-src="title" data-serp-for="<?= $l ?>"<?= $isUk ? ' data-uk' : '' ?>>
            <small class="hint">Пусто — <?= $isUk ? 'русский Title статьи, если он задан, иначе ' : '' ?>«<?= e($blogName[$l] ?? 'Tomobuv') ?> » заголовок», как на старом сайте.</small>
          </label>
          <label class="fld"><span>Meta description<?= $isUk ? ' ' . $ua : '' ?></span>
            <textarea name="meta_description<?= $sfx ?>" rows="4" class="plain" data-counter="160" data-serp-src="desc" data-serp-for="<?= $l ?>"<?= $isUk ? ' data-uk placeholder="' . e($v('meta_description')) . '"' : '' ?>><?= e($v('meta_description' . $sfx)) ?></textarea>
          </label>
          <label class="fld"><span>Meta keywords<?= $isUk ? ' ' . $ua : '' ?></span>
            <textarea name="meta_keywords<?= $sfx ?>" rows="2" class="plain"<?= $isUk ? ' data-uk placeholder="' . e($v('meta_keywords')) . '"' : '' ?>><?= e($v('meta_keywords' . $sfx)) ?></textarea>
          </label>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
  <div class="savebar">
    <button class="btn btn-p" type="submit"><?= $isNew ? 'Создать статью' : 'Сохранить' ?></button>
    <a class="btn" href="/admin/blog/">Отмена</a>
    <span class="hint hide-sm">Ctrl+S — сохранить</span>
    <?php if (!$isNew): ?><span class="sp"></span><button class="btn btn-d" type="submit" form="del-form">Удалить</button><?php endif; ?>
  </div>
</form>
<?php if (!$isNew): ?>
<form id="del-form" method="post" action="/admin/blog/<?= (int) $post['id'] ?>/delete/" data-confirm="Удалить статью «<?= e($post['title']) ?>»?">
  <?= BaseController::tokenField() ?>
</form>
<?php endif; ?>
