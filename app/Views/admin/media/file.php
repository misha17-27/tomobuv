<?php
/**
 * Карточка файла медиатеки.
 * @var array $item @var array $usage @var string $fullUrl @var bool $isAdmin
 */
use App\Controllers\Admin\BaseController;
use App\Services\Media;

$dims = $item['width'] ? (int) $item['width'] . ' × ' . (int) $item['height'] . ' px' : '—';
$html = '<img src="' . $item['url'] . '" alt=""' . ($item['width'] ? ' width="' . (int) $item['width'] . '" height="' . (int) $item['height'] . '"' : '') . '>';
$n = count($usage);
?>
<div class="two-col md-two">
  <div>
    <div class="card">
      <div class="card-hd"><h2>Просмотр</h2><span class="muted"><?= e($dims) ?> · <?= e($item['size_h']) ?></span></div>
      <div class="md-view"><a href="<?= e($item['url']) ?>" target="_blank" rel="noopener" title="Открыть в полном размере"><img src="<?= e($item['url']) ?>" alt="<?= e($item['name']) ?>"<?= $item['width'] ? ' width="' . (int) $item['width'] . '" height="' . (int) $item['height'] . '"' : '' ?>></a></div>
    </div>

    <div class="card">
      <div class="card-hd"><h2>Где используется</h2><span class="muted"><?= $n ? $n . ' ' . plural($n, 'место', 'места', 'мест') : 'нигде' ?></span></div>
      <?php if ($usage): ?>
        <div class="table-scroll"><table class="grid">
          <thead><tr><th>Раздел</th><th>Название</th><th class="right">Изменить</th></tr></thead>
          <tbody>
          <?php foreach ($usage as $u): ?>
            <tr>
              <td><span class="pill"><?= e($u['label']) ?></span></td>
              <td><?= e($u['title']) ?></td>
              <td class="right"><a href="<?= e($u['link']) ?>">Открыть →</a></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table></div>
      <?php else: ?>
        <div class="pad"><p class="muted">Ссылок на этот файл нет ни в страницах, ни в статьях блога, баннерах, категориях, брендах,
          описаниях товаров или настройках. Если он не нужен — его можно удалить.</p></div>
      <?php endif; ?>
    </div>
  </div>

  <aside>
    <div class="card">
      <div class="card-hd"><h2>Файл</h2><span class="seo-dot <?= $n ? 'ok' : 'auto' ?>" title="<?= $n ? 'Используется' : 'Не используется' ?>"></span></div>
      <div class="pad">
        <dl class="detail">
          <div><dt>Имя</dt><dd class="md-break"><?= e($item['name']) ?></dd></div>
          <div><dt>Папка</dt><dd>uploads/<?= e($item['dir'] !== '' ? $item['dir'] . '/' : '') ?><?= $item['dir'] !== '' ? ' <small class="muted">' . e(Media::folderLabel($item['dir'])) . '</small>' : '' ?></dd></div>
          <div><dt>Размеры</dt><dd><?= e($dims) ?></dd></div>
          <div><dt>Вес</dt><dd><?= e($item['size_h']) ?></dd></div>
          <div><dt>Тип</dt><dd><?= e($item['mime'] ?: '—') ?></dd></div>
          <div><dt>Изменён</dt><dd><?= e($item['date']) ?></dd></div>
        </dl>
        <div class="fld"><span>Ссылка для вставки</span>
          <div class="md-copyrow"><input type="text" readonly value="<?= e($item['url']) ?>" aria-label="Ссылка для вставки" data-md-select><button type="button" class="btn btn-sm" data-md-copy="<?= e($item['url']) ?>">Копировать</button></div>
        </div>
        <div class="fld"><span>Полный адрес</span>
          <div class="md-copyrow"><input type="text" readonly value="<?= e($fullUrl) ?>" aria-label="Полный адрес" data-md-select><button type="button" class="btn btn-sm" data-md-copy="<?= e($fullUrl) ?>">Копировать</button></div>
        </div>
        <div class="fld"><span>HTML-код картинки</span>
          <div class="md-copyrow"><input type="text" readonly value="<?= e($html) ?>" aria-label="HTML-код" data-md-select><button type="button" class="btn btn-sm" data-md-copy="<?= e($html) ?>">Копировать</button></div>
        </div>
      </div>
    </div>

    <?php if ($isAdmin): ?>
      <form class="card" id="del" method="post" action="/admin/media/delete/" data-confirm="Удалить файл «<?= e($item['name']) ?>»? Восстановить его будет нельзя.">
        <h2>Удаление</h2>
        <?= BaseController::tokenField() ?>
        <input type="hidden" name="f" value="<?= e($item['path']) ?>">
        <input type="hidden" name="from" value="file">
        <?php if ($usage): ?>
          <div class="flash warn">Файл используется на сайте (<?= $n ?>). После удаления картинка пропадёт на этих страницах — сначала замените её там.</div>
          <label class="check"><input type="checkbox" name="force" value="1" required> Понимаю, удалить всё равно</label>
        <?php else: ?>
          <p class="muted">Файл нигде не используется — удаление ничего на сайте не сломает.</p>
        <?php endif; ?>
        <p><button class="btn btn-d">Удалить файл</button></p>
      </form>
    <?php else: ?>
      <div class="card"><p class="muted">Удалять файлы может только администратор.</p></div>
    <?php endif; ?>
  </aside>
</div>
