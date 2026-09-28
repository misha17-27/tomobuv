<?php
/**
 * Изображения (медиатека).
 * @var array $stats @var array $missing @var array $res @var App\Core\Paginator $pg @var array $f @var array $folders @var array $sorts
 * @var bool $isAdmin @var int $limit @var string $limitText
 */
use App\Controllers\Admin\BaseController;
use App\Core\Request;
use App\Services\Media;

$fmt = static fn($n) => number_format((float) $n, 0, '', ' ');
$thisMonth = date('Y/m');
$hasMonth = isset($folders[$thisMonth]);
$folderVal = $f['folder'] === null ? '' : ($f['folder'] === '' ? '/' : $f['folder']);
$tab = static fn(string $use) => Request::withQuery(['use' => $use, 'page' => null]);
$filtered = $f['q'] !== '' || $f['folder'] !== null;
$cnt = $res['counts'];
// только что загруженные файлы JS добавляет в сетку, если они попали бы в этот список (первая страница «новых сверху»)
$live = $res['page'] === 1 && $f['sort'] === 'new' && $f['q'] === '' && $f['use'] !== 'used' && ($f['folder'] === null || $f['folder'] === $thisMonth);
?>
<div class="stats">
  <a class="stat" href="/admin/media/"><span><?= $fmt($stats['files']) ?></span>Изображений в медиатеке</a>
  <div class="stat"><span><?= e(Media::sizeText($stats['bytes'])) ?></span>Занимают на диске</div>
  <<?= $hasMonth ? 'a href="/admin/media/?folder=' . e(rawurlencode($thisMonth)) . '"' : 'div' ?> class="stat"><span><?= $fmt($stats['month']) ?></span>Загружено за <?= e(mb_strtolower(Media::folderLabel($thisMonth))) ?></<?= $hasMonth ? 'a' : 'div' ?>>
  <a class="stat<?= $stats['unused'] ? ' hot' : '' ?>" href="/admin/media/?use=unused"><span><?= $fmt($stats['unused']) ?></span>Нигде не используются</a>
</div>

<div class="card">
  <div class="card-hd"><h2>Загрузка изображений</h2><span class="muted">JPG, PNG, WEBP, GIF · до <?= e($limitText) ?></span></div>
  <form class="pad" method="post" action="/admin/media/upload/" enctype="multipart/form-data" data-md-upload>
    <?= BaseController::tokenField() ?>
    <div class="md-drop" data-md-drop data-max="<?= (int) $limit ?>">
      <svg width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 16V4M7 9l5-5 5 5"/><path d="M4 16v3a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1v-3"/></svg>
      <p><b>Перетащите картинки сюда</b> или <label class="md-link" for="md-files">выберите на компьютере</label></p>
      <input type="file" id="md-files" class="md-file" name="files[]" multiple accept="image/jpeg,image/png,image/webp,image/gif">
      <p class="hint">Можно сразу несколько файлов. Тип проверяется по содержимому, а не по имени; картинка пересохраняется,
        большие фото уменьшаются до <?= Media::MAX_SIDE ?> px по большей стороне. Имя — латиницей из названия файла,
        папка — <code>uploads/<?= e($thisMonth) ?>/</code>. GIF сохраняется как PNG.</p>
      <noscript><button class="btn btn-p">Загрузить выбранные</button></noscript>
    </div>
    <ul class="md-queue" data-md-queue hidden></ul>
  </form>
</div>

<div class="tabs">
  <a href="<?= e($tab('')) ?>" class="<?= $f['use'] === '' ? 'on' : '' ?>">Все <i><?= $fmt($cnt['all']) ?></i></a>
  <a href="<?= e($tab('used')) ?>" class="<?= $f['use'] === 'used' ? 'on' : '' ?>">Используются на сайте <i><?= $fmt($cnt['used']) ?></i></a>
  <a href="<?= e($tab('unused')) ?>" class="<?= $f['use'] === 'unused' ? 'on' : '' ?>">Не используются <i><?= $fmt($cnt['unused']) ?></i></a>
</div>

<form class="filter-bar" method="get" action="/admin/media/">
  <?php if ($f['use'] !== ''): ?><input type="hidden" name="use" value="<?= e($f['use']) ?>"><?php endif; ?>
  <input type="search" name="q" value="<?= e($f['q']) ?>" placeholder="Поиск по имени файла" aria-label="Поиск по имени файла">
  <select name="folder" aria-label="Папка / месяц" onchange="this.form.submit()">
    <option value="">Все папки</option>
    <?php foreach ($folders as $dir => $n): $dir = (string) $dir; $v = $dir === '' ? '/' : $dir; ?>
      <option value="<?= e($v) ?>"<?= $folderVal === $v ? ' selected' : '' ?>><?= e(Media::folderLabel($dir)) ?> · <?= $fmt($n) ?></option>
    <?php endforeach; ?>
  </select>
  <select name="sort" aria-label="Порядок" onchange="this.form.submit()">
    <?php foreach ($sorts as $k => $label): ?><option value="<?= e($k) ?>"<?= $f['sort'] === $k ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
  </select>
  <button class="btn btn-p">Найти</button>
  <?php if ($filtered || $f['sort'] !== 'new'): ?><a class="btn" href="/admin/media/<?= $f['use'] !== '' ? '?use=' . e($f['use']) : '' ?>">Сбросить</a><?php endif; ?>
</form>

<div class="card" data-md-lib<?= $live ? ' data-md-live' : '' ?>>
  <div class="card-hd">
    <h2><?= $f['folder'] !== null ? e(Media::folderLabel($f['folder'])) : 'Все изображения' ?><?= $f['q'] !== '' ? ' · «' . e($f['q']) . '»' : '' ?></h2>
    <span class="muted"><span data-md-total><?= $fmt($res['total']) ?></span> <?= plural($res['total'], 'файл', 'файла', 'файлов') ?><?= $res['pages'] > 1 ? ' · стр. ' . $res['page'] . ' из ' . $res['pages'] : '' ?></span>
  </div>
  <?php if ($isAdmin && $res['items']): ?>
    <form class="bulk-bar" id="md-bulk" method="post" action="/admin/media/delete/" data-md-bulk data-confirm="Удалить выбранные файлы? Восстановить их будет нельзя.">
      <?= BaseController::tokenField() ?>
      <label class="check"><input type="checkbox" data-md-all> Выбрать все на странице</label>
      <span class="muted" data-md-selected>Отметьте файлы галочкой, чтобы удалить несколько сразу</span>
      <label class="check" data-md-force><input type="checkbox" name="force" value="1"> Удалить, даже если используется</label>
      <button class="btn btn-d btn-sm" data-md-bulk-btn>Удалить выбранные</button>
    </form>
  <?php endif; ?>
  <?php if ($res['items']): ?>
    <div class="pad">
      <div class="media-grid md-grid" data-md-grid>
        <?php foreach ($res['items'] as $it): ?><?= $view->partial('admin/media/_tile', ['it' => $it, 'isAdmin' => $isAdmin]) ?><?php endforeach; ?>
      </div>
      <?= $pg->html() ?>
    </div>
  <?php elseif ($stats['files'] === 0): ?>
    <div class="empty-card">
      <h2>Здесь пока пусто</h2>
      <p>Загрузите первые картинки — перетащите их в поле выше. Потом их можно вставлять в страницы, статьи, баннеры, категории и бренды.</p>
      <label class="btn btn-p" for="md-files">Загрузить изображения</label>
    </div>
  <?php else: ?>
    <div class="empty-card">
      <h2>Ничего не найдено</h2>
      <p><?= $f['use'] === 'unused' && !$filtered ? 'Все загруженные картинки где-то используются — удалять нечего.' : 'Попробуйте другое имя файла или другую папку.' ?></p>
      <a class="btn" href="/admin/media/">Показать все изображения</a>
    </div>
  <?php endif; ?>
</div>

<?php if ($missing['total']): ?>
  <div class="card">
    <div class="card-hd"><h2>Ссылки на отсутствующие файлы</h2><span class="muted"><?= $fmt($missing['total']) ?> <?= plural($missing['total'], 'файл', 'файла', 'файлов') ?></span></div>
    <div class="pad"><p class="hint">В страницах, статьях, баннерах или описаниях есть ссылки на эти картинки, но в папке <code>public/uploads/</code> их нет —
      на сайте они не покажутся. Обычно это файлы со старого сайта: скопируйте папку <code>uploads/</code> со старого хостинга в <code>public/uploads/</code> (по FTP) —
      они сразу появятся в медиатеке. Или загрузите картинку заново и замените ссылку.</p></div>
    <div class="table-scroll"><table class="grid">
      <thead><tr><th>Путь</th><th class="right">Мест</th></tr></thead>
      <tbody>
      <?php foreach ($missing['items'] as $m): ?>
        <tr><td><code><?= e($m['url']) ?></code></td><td class="right"><?= (int) $m['count'] ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  </div>
<?php endif; ?>
