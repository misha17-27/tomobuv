<?php
/**
 * Список товаров: активные фильтры, плитка (partials/card) или таблица (partials/row),
 * пустое состояние, кнопка «Показать ещё» (AJAX) и пагинация.
 * @var App\Services\Listing $L
 * @var array  $products   Products::cards()
 * @var bool   $itemsOnly  только карточки/строки (ответ «Показать ещё»)
 * @var string $grid       классы сетки ('grid g4' при боковых фильтрах)
 * @var string $emptyTitle @var string $emptyText @var array $emptyLink [url, текст] @var string $emptyIcon
 * @var View $view
 */
$tpl = $L->view === 'table' ? 'front/partials/row' : 'front/partials/card';
if (!empty($itemsOnly)) {
    foreach ($products as $p) echo $view->partial($tpl, ['p' => $p]);
    return;
}
$grid ??= 'grid g4';
$chips = $L->hasFilters() ? $L->activeChips() : [];
?>
<?php if ($chips): ?>
<div class="active-filters" role="group" aria-label="<?= e(t('Выбранные фильтры')) ?>">
  <?php foreach ($chips as $c): ?><a href="<?= e($c['url']) ?>" rel="nofollow" aria-label="<?= e(t('Убрать фильтр «{name}»', ['name' => $c['label']])) ?>"><?= e($c['label']) ?><?= icon('x') ?></a><?php endforeach; ?>
  <a class="af-reset" href="<?= e($L->url(['filters' => false])) ?>" rel="nofollow"><?= e(t('Сбросить все')) ?></a>
</div>
<?php endif; ?>

<?php if (!$products): ?>
  <div class="empty-state">
    <div class="ic"><?= icon($L->hasFilters() && !$L->outOfRange ? 'filter' : ($emptyIcon ?? 'box'), 'width:30px;height:30px') ?></div>
    <?php if ($L->outOfRange && $L->total > 0): ?>
      <h2><?= e(t('На этой странице товаров нет')) ?></h2>
      <p><?= e(t('Список стал короче: часть товаров уже продана. Начните с первой страницы.')) ?></p>
      <a class="btn btn-o" href="<?= e($L->url()) ?>"><?= e(t('На первую страницу')) ?></a>
    <?php elseif ($L->hasFilters()): ?>
      <h2><?= e(t('Ничего не найдено')) ?></h2>
      <p><?= e(t('По выбранным фильтрам товаров нет. Измените условия или сбросьте фильтры.')) ?></p>
      <a class="btn btn-o" href="<?= e($L->url(['filters' => false])) ?>" rel="nofollow"><?= e(t('Сбросить фильтры')) ?></a>
    <?php else: ?>
      <h2><?= e($emptyTitle ?? t('Товаров пока нет')) ?></h2>
      <p><?= e($emptyText ?? t('Загляните позже или посмотрите другие разделы каталога.')) ?></p>
      <?php if (!empty($emptyLink)): ?><a class="btn btn-o" href="<?= e($emptyLink[0]) ?>"><?= e($emptyLink[1]) ?></a><?php endif; ?>
    <?php endif; ?>
  </div>
<?php elseif ($L->view === 'table'): ?>
  <?php /* --lt-box — подпись цены за ящик в мобильной раскладке строк (без заголовка таблицы) */ ?>
  <div class="tblwrap" style="--lt-box:<?= e(json_encode(t('за ящик'), JSON_UNESCAPED_UNICODE)) ?>">
    <table class="tbl ltbl">
      <thead><tr>
        <th><span class="visually-hidden"><?= e(t('Фото')) ?></span></th><th><?= e(t('Товар')) ?></th><th><?= e(t('Размер')) ?></th><th class="num hide-m"><?= e(t('Пар в ящике')) ?></th>
        <th class="num hide-m"><?= e(t('Цена за пару')) ?></th><th class="num"><?= e(t('За ящик')) ?></th><th><?= e(t('Ящиков')) ?></th>
        <th><span class="visually-hidden"><?= e(t('В корзину')) ?></span></th><th class="hide-m"><span class="visually-hidden"><?= e(t('Избранное и сравнение')) ?></span></th>
      </tr></thead>
      <tbody data-list><?php foreach ($products as $p) echo $view->partial($tpl, ['p' => $p]); ?></tbody>
    </table>
  </div>
<?php else: ?>
  <?php /* первые карточки — на первом экране: без loading="lazy" (ленивая загрузка ждёт раскладку страницы и задерживает LCP), первая — с высоким приоритетом */ ?>
  <div class="<?= e($grid) ?>" data-list><?php $n = 0; foreach ($products as $p) { echo $view->partial($tpl, ['p' => $p, 'eager' => $n === 0 ? 2 : ($n < 4 ? 1 : 0)]); $n++; } ?></div>
<?php endif; ?>

<?php if ($products && ($next = $L->nextUrl())): ?>
  <div class="more-btn"><button type="button" class="btn btn-g" data-more="<?= e($next) ?>"><?= icon('refresh', 'width:18px;height:18px') ?><span data-more-t><?= e(t('Показать ещё {n}', ['n' => $L->leftNext()])) ?></span></button></div>
<?php endif; ?>
<div data-pager><?= $L->pagerHtml() ?></div>
