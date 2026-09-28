<?php
/**
 * Плитка файла в сетке медиатеки (JS строит такую же для только что загруженных — media.js tile()).
 * @var array $it @var bool $isAdmin
 */
$fileUrl = '/admin/media/file/?f=' . rawurlencode($it['path']);
?>
<figure class="md-fig" data-md-item data-path="<?= e($it['path']) ?>" data-name="<?= e($it['name']) ?>">
  <?php if ($isAdmin): ?><label class="md-tick" title="Выбрать"><input type="checkbox" name="f[]" value="<?= e($it['path']) ?>" form="md-bulk" data-md-tick><span class="md-sr">Выбрать <?= e($it['name']) ?></span></label><?php endif; ?>
  <a class="md-thumb" href="<?= e($fileUrl) ?>" title="<?= e($it['path']) ?>"><img src="<?= e($it['url']) ?>" alt="" loading="lazy"<?= $it['width'] ? ' width="' . (int) $it['width'] . '" height="' . (int) $it['height'] . '"' : '' ?>></a>
  <figcaption>
    <a class="md-name" href="<?= e($fileUrl) ?>" title="<?= e($it['name']) ?>"><?= e($it['name']) ?></a>
    <span class="md-meta"><?= $it['width'] ? (int) $it['width'] . '×' . (int) $it['height'] . ' · ' : '' ?><?= e($it['size_h']) ?> · <?= e(date('d.m.Y', (int) $it['mtime'])) ?></span>
    <span class="md-use"><i class="seo-dot <?= $it['used'] ? 'ok' : 'auto' ?>"></i><?= $it['used'] ? 'используется: ' . (int) $it['used'] : 'не используется' ?></span>
  </figcaption>
  <div class="md-acts">
    <button type="button" class="btn btn-sm" data-md-copy="<?= e($it['url']) ?>" title="Скопировать ссылку <?= e($it['url']) ?>">Ссылка</button>
    <?php if ($isAdmin): ?><a class="btn btn-sm btn-d" href="<?= e($fileUrl) ?>#del" data-md-del>Удалить</a><?php endif; ?>
  </div>
</figure>
