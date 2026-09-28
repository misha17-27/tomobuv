<?php
/**
 * Форма страницы: RU и UA поля (общий переключатель «RU | UA»), содержимое — HTML-редактор, SEO — общие поля (AdminCatalog::seoField).
 * @var array $page @var bool $isNew @var array $errors @var bool $dup @var ?array $original @var ?array $redirectHere
 * @var array $hints ['ru'|'uk' => ['title','desc','keys']] — что подставят SEO-шаблоны @var string $lang
 */
use App\Controllers\Admin\BaseController;
use App\Core\App;
use App\Services\AdminCatalog;

$err = static fn(string $k) => isset($errors[$k]) ? '<span class="fld-err" role="alert">' . e($errors[$k]) . '</span>' : '';
$inv = static fn(string $k) => isset($errors[$k]) ? ' aria-invalid="true"' : '';
$action = $isNew ? '/admin/pages/new/' : '/admin/pages/' . (int) $page['id'] . '/';
$host = (string) (parse_url((string) App::config('base_url', ''), PHP_URL_HOST) ?: 'tomobuv.com.ua');
$mediaBase = (string) App::config('images.remote_base', '');
$v = static fn(string $k) => (string) ($page[$k] ?? '');
$ru = AdminCatalog::langTag('ru');
$ua = AdminCatalog::langTag('uk');
?>
<?php if ($errors): ?><div class="flash bad">Проверьте поля формы: <?= e(implode('; ', $errors)) ?></div><?php endif; ?>

<form method="post" action="<?= e($action) ?>" class="ed-form" data-lang="<?= e($lang) ?>" novalidate>
  <?= BaseController::tokenField() ?>
  <?= $view->partial('admin/partials/lang-bar', ['lang' => $lang, 'what' => 'страницы']) ?>
  <div class="grid3">
    <div>
      <div class="card">
        <label class="fld l-ru"><span>Название * <?= $ru ?></span>
          <input type="text" name="name" value="<?= e($v('name')) ?>" maxlength="255" required<?= $inv('name') ?>>
          <?= $err('name') ?><small class="hint">Выводится в меню и заголовком страницы (если не задан H1).</small>
        </label>
        <label class="fld l-uk"><span>Название <?= $ua ?></span>
          <input type="text" name="name_uk" value="<?= e($v('name_uk')) ?>" maxlength="255" placeholder="<?= e($v('name')) ?>" data-uk>
          <small class="hint">Для меню и заголовка на /ua/. Пусто — русское название.</small>
        </label>

        <div class="fld">
          <label class="lbl" for="pg-url">Адрес страницы</label>
          <div class="url-in"><span class="pre"><?= e($host) ?>/</span><input id="pg-url" type="text" name="url" value="<?= e($v('url')) ?>" maxlength="255" placeholder="o-kompanii/" data-url-orig="<?= e($isNew ? '' : $v('url')) ?>" data-slug-from="name"<?= $inv('url') ?>></div>
          <?= $err('url') ?><small class="hint">Латиница, цифры, «-»; в конце «/». Пусто — адрес создастся из названия. Украинская версия — тот же адрес с /ua/ в начале. Нельзя занимать разделы сайта: category/, product/, brand/, blog/, cart/, my/, ua/…</small>
        </div>
        <?php if (!$isNew): ?>
          <label class="check ed-redirect hidden"><input type="checkbox" name="make_redirect" value="1" checked> Создать 301-редирект со старого адреса /<?= e($v('url')) ?> на новый</label>
        <?php endif; ?>
        <?php if ($dup): ?>
          <div class="note-box">Это <b>дубль</b> со старого сайта (приложение «Сайт» Webasyst).
            <?php if ($original): ?>Основная страница: <a href="/admin/pages/<?= (int) $original['id'] ?>/">/<?= e($original['url']) ?></a>.
              <button type="button" class="btn btn-sm" data-fill="canonical" data-value="/<?= e($original['url']) ?>">Указать canonical на основную</button>
            <?php endif; ?>
          </div>
        <?php endif; ?>
        <?php if ($redirectHere): ?>
          <div class="note-box warn-box">С этого адреса настроен редирект на <?= e($redirectHere['to_url']) ?>. Пока страница существует, он не срабатывает. <a href="/admin/redirects/?q=<?= e(rawurlencode('/' . $v('url'))) ?>">Открыть редиректы</a></div>
        <?php endif; ?>

        <div class="fld l-ru">
          <div class="lbl-row"><label class="lbl" for="pg-content">Содержимое <?= $ru ?></label></div>
          <textarea id="pg-content" name="content" rows="22" data-editor data-media-base="<?= e($mediaBase) ?>"><?= "\n" . e($v('content')) ?></textarea>
          <?= \App\Services\HtmlSanitizer::hint() ?>
        </div>
        <div class="fld l-uk">
          <div class="lbl-row"><label class="lbl" for="pg-content-uk">Содержимое <?= $ua ?></label>
            <button type="button" class="btn btn-sm" data-copy-from="content" data-copy-to="content_uk">Скопировать русский текст</button></div>
          <textarea id="pg-content-uk" name="content_uk" rows="22" data-editor data-uk data-media-base="<?= e($mediaBase) ?>"><?= "\n" . e($v('content_uk')) ?></textarea>
          <small class="hint">Пусто — на /ua/ показывается русское содержимое. Удобно: скопируйте русский текст и переведите его.</small>
          <?= \App\Services\HtmlSanitizer::hint() ?>
        </div>
      </div>
    </div>

    <div>
      <div class="card">
        <h2>Публикация</h2>
        <label class="check"><input type="checkbox" name="status" value="1"<?= (int) $page['status'] ? ' checked' : '' ?>> Опубликована на сайте</label>
        <label class="check"><input type="checkbox" name="in_menu" value="1"<?= (int) $page['in_menu'] ? ' checked' : '' ?>> Показывать в меню сайта</label>
        <label class="fld" style="margin-top:12px"><span>Порядок в меню</span><input type="number" name="sort" value="<?= (int) $page['sort'] ?>" step="1"></label>
        <?php if (!$isNew && $page['updated_at']): ?><p class="hint">Изменена <?= e(date('d.m.Y H:i', strtotime((string) $page['updated_at']))) ?></p><?php endif; ?>
      </div>
      <div class="card">
        <h2 id="h-seo">SEO</h2>
        <p class="hint"><?= e(AdminCatalog::SEO_INTRO) ?> Шаблоны — в <a href="/admin/settings/seo/">SEO-шаблонах</a>.</p>
        <div class="l-ru"><?= $view->partial('admin/partials/serp', ['serp' => ['type' => 'page', 'row' => $page]]) ?></div>
        <div class="l-uk"><?= $view->partial('admin/partials/serp', ['serp' => AdminCatalog::serpUk($v('url') !== '' ? '/ua/' . $v('url') : '', $page,
          ['title' => $hints['uk']['title'], 'meta_description' => $hints['uk']['desc']], 'title')]) ?></div>
        <?= AdminCatalog::seoField('meta_title', 'title', $page, ['tpl' => $hints['ru']['title'], 'tpl_uk' => $hints['uk']['title']]) ?>
        <?= AdminCatalog::seoField('meta_description', 'meta_description', $page, ['tpl' => $hints['ru']['desc'], 'tpl_uk' => $hints['uk']['desc']]) ?>
        <?= AdminCatalog::seoField('meta_keywords', 'meta_keywords', $page, ['tpl' => $hints['ru']['keys'], 'tpl_uk' => $hints['uk']['keys']]) ?>
        <?= AdminCatalog::seoField('h1', 'h1', $page, ['placeholder' => $v('name'), 'empty' => 'Пусто — название страницы.', 'tpl_uk' => $v('h1') ?: $v('name_uk') ?: $v('name')]) ?>
        <label class="fld" style="margin-top:14px"><span>Канонический адрес</span>
          <input type="text" name="canonical" value="<?= e($v('canonical')) ?>" maxlength="255" placeholder="/o-kompanii/"<?= $inv('canonical') ?>>
          <?= $err('canonical') ?><small class="hint">Для дублей — адрес основной страницы. Обычно пусто.</small>
        </label>
      </div>
    </div>
  </div>
  <div class="savebar">
    <button class="btn btn-p" type="submit"><?= $isNew ? 'Создать страницу' : 'Сохранить' ?></button>
    <a class="btn" href="/admin/pages/">Отмена</a>
    <span class="hint hide-sm">Ctrl+S — сохранить</span>
    <?php if (!$isNew): ?><span class="sp"></span><button class="btn btn-d" type="submit" form="del-form">Удалить</button><?php endif; ?>
  </div>
</form>
<?php if (!$isNew): ?>
<form id="del-form" method="post" action="/admin/pages/<?= (int) $page['id'] ?>/delete/" data-confirm="Удалить страницу «<?= e($page['name']) ?>»? Адрес /<?= e($page['url']) ?> перестанет открываться.">
  <?= BaseController::tokenField() ?>
</form>
<?php endif; ?>
