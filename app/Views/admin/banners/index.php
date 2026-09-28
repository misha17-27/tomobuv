<?php
/**
 * Баннеры главной по местам показа. Порядок — перетаскивание строк или стрелки (сохраняется сразу, POST /admin/banners/sort/).
 * @var array $groups place → [баннеры]
 */
use App\Controllers\Admin\BaseController;
use App\Controllers\Admin\BannersController;
?>
<?php foreach ($groups as $place => $items): [$label, $hint] = BannersController::PLACES[$place] ?? [$place, 'Место показа из старой версии сайта']; ?>
<section class="card">
  <div class="card-hd">
    <div><h2><?= e($label) ?> <span class="muted">· <?= count($items) ?></span></h2><p class="hint"><?= e($hint) ?></p></div>
    <?php if (isset(BannersController::PLACES[$place])): ?><a class="btn btn-sm" href="/admin/banners/new/?place=<?= e($place) ?>">+ Добавить</a><?php endif; ?>
  </div>
  <?php if (!$items): ?>
    <p class="pad muted">Баннеров нет — на главной этот блок не выводится.</p>
  <?php else: ?>
  <div class="table-scroll">
  <table class="grid bn-tbl">
    <thead><tr><th class="col-drag">Порядок</th><th class="opt">Картинка</th><th>Заголовок</th><th class="opt">Ссылка</th><th>Показ</th><th><span class="sr">Действия</span></th></tr></thead>
    <tbody data-sortable="/admin/banners/sort/">
    <?php foreach ($items as $b): $on = (int) $b['status'] === 1; ?>
      <tr data-id="<?= (int) $b['id'] ?>" class="<?= $on ? '' : 'is-draft' ?>">
        <td class="col-drag"><span class="drag" draggable="true" title="Перетащите, чтобы изменить порядок" aria-hidden="true">⋮⋮</span>
          <span class="arrows"><button type="button" class="btn btn-sm" data-move="-1" aria-label="Поднять баннер «<?= e($b['title']) ?>» выше"><?= icon('up', 'width:14px;height:14px') ?></button><button type="button" class="btn btn-sm" data-move="1" aria-label="Опустить баннер «<?= e($b['title']) ?>» ниже"><?= icon('down', 'width:14px;height:14px') ?></button></span></td>
        <td class="opt"><a href="/admin/banners/<?= (int) $b['id'] ?>/" class="bn-thumb" aria-label="Изменить баннер <?= e($b['title']) ?>"><?php if ($b['image']): ?><img src="<?= e(media($b['image'])) ?>" alt="" loading="lazy"><?php endif; ?></a></td>
        <td><a href="/admin/banners/<?= (int) $b['id'] ?>/"><b><?= e($b['title'] ?: 'Без заголовка') ?></b></a>
          <?php if ($b['text']): ?><small><?= e(str_limit($b['text'], 90)) ?></small><?php endif; ?>
          <?php if ($b['button']): ?><small>Кнопка: «<?= e($b['button']) ?>»</small><?php endif; ?>
          <small><?= $b['title_uk'] ? 'UA: «' . e(str_limit($b['title_uk'], 60)) . '»' : '<span class="warn-txt">UA не заполнен — на /ua/ русский текст</span>' ?></small></td>
        <td class="opt"><?php if ($b['link']): ?><a href="<?= e($b['link']) ?>" target="_blank" rel="noopener"><?= e(str_limit($b['link'], 40)) ?></a><?php else: ?>—<?php endif; ?></td>
        <td>
          <form method="post" action="/admin/banners/<?= (int) $b['id'] ?>/toggle/" class="inline"><?= BaseController::tokenField() ?>
            <button class="st <?= $on ? 'st-completed' : 'st-deleted' ?> st-btn" title="Нажмите, чтобы <?= $on ? 'скрыть' : 'показать' ?>"><?= $on ? 'показан' : 'скрыт' ?></button>
          </form>
        </td>
        <td class="nowrap right">
          <a class="btn btn-sm" href="/admin/banners/<?= (int) $b['id'] ?>/">Изменить</a>
          <form method="post" action="/admin/banners/<?= (int) $b['id'] ?>/delete/" class="inline" data-confirm="Удалить баннер «<?= e($b['title']) ?>»?">
            <?= BaseController::tokenField() ?><button class="btn btn-sm btn-d" aria-label="Удалить баннер <?= e($b['title']) ?>" title="Удалить"><?= icon('trash', 'width:15px;height:15px') ?></button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php endif; ?>
</section>
<?php endforeach; ?>
<p class="hint">Порядок меняется перетаскиванием за «⋮⋮» или стрелками и сохраняется сразу. Скрытые баннеры на сайте не показываются. Нажмите на «показан/скрыт», чтобы переключить.</p>
