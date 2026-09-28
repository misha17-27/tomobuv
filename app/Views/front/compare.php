<?php
/**
 * Сравнение товаров.
 * @var array $products (Products::cards) @var array $rows @var bool $hasDiff @var bool $fromUrl @var View $view
 */
$n = count($products);
?>
<div class="wrap">
  <div class="page-head">
    <?= $view->partial('front/partials/crumbs', ['items' => [['name' => t('Сравнение товаров')]]]) ?>
    <h1><?= e(t('Сравнить товары')) ?></h1>
  </div>

  <div class="empty-state cmp-empty<?= $products ? ' hidden' : '' ?>" id="cmp-empty">
    <div class="ic"><?= icon('cmp', 'width:30px;height:30px') ?></div>
    <h2><?= e(t('Список товаров для сравнения пуст.')) ?></h2>
    <p><?= t('Нажимайте <b>«К сравнению»</b> в карточках товаров — здесь появится таблица с ценами, материалами и размерами.') ?></p>
    <a class="btn btn-o" href="/category/dyetskaya-obuv/"><?= e(t('Перейти в каталог')) ?></a>
  </div>

  <?php if ($products): ?>
  <div class="cmp-box" id="cmp-box" data-from-url="<?= $fromUrl ? '1' : '0' ?>">
    <div class="cmp-bar">
      <label class="check"><input type="checkbox" id="cmp-diff"<?= $hasDiff ? '' : ' disabled' ?>> <?= e(t('Только отличия')) ?></label>
      <span class="muted" id="cmp-count"><?= e(t('Товаров: {n}', ['n' => $n])) ?></span>
      <button type="button" class="btn btn-g btn-sm" id="cmp-clear"><?= icon('trash', 'width:16px') ?><?= e(t('Очистить список')) ?></button>
    </div>
    <div class="tblwrap cmp-wrap">
      <table class="tbl cmp-tbl" id="cmp-table">
        <caption class="visually-hidden"><?= e(t('Сравнение товаров')) ?></caption>
        <thead>
          <tr>
            <th scope="col" class="cmp-lab"><span class="cmp-hint"><?= e(t('Строки с разными значениями подсвечены')) ?></span></th>
            <?php foreach ($products as $p): ?>
              <th scope="col" class="cmp-col" data-col="<?= (int) $p['id'] ?>">
                <div class="cmp-card" data-id="<?= (int) $p['id'] ?>" data-max="<?= \App\Services\Cart::maxBoxes($p) ?>">
                  <button type="button" class="cmp-rm" data-cmp-remove="<?= (int) $p['id'] ?>" aria-label="<?= e(t('Удалить из сравнения: {name}', ['name' => $p['name']])) ?>" title="<?= e(t('Удалить из сравнения')) ?>"><?= icon('x', 'width:16px;height:16px') ?></button>
                  <a class="ph" href="<?= e($p['link']) ?>" tabindex="-1" aria-hidden="true"><img src="<?= e($p['img']) ?>" alt="" width="160" height="160" loading="lazy"></a>
                  <a class="nm" href="<?= e($p['link']) ?>"><?= e($p['name']) ?></a>
                  <div class="pr"><?= price_html($p['box_price'], 'b') ?> <small><?= e(t('за ящик')) ?></small></div>
                  <div class="buy">
                    <div class="qty"><button type="button" data-q="-1" aria-label="<?= e(t('Меньше ящиков')) ?>">−</button><input value="1" inputmode="numeric" aria-label="<?= e(t('Количество ящиков')) ?>"><button type="button" data-q="1" aria-label="<?= e(t('Больше ящиков')) ?>">+</button></div>
                    <button type="button" class="btn btn-b btn-sm" data-act="cart"<?= $p['in_stock'] ? '' : ' disabled' ?>><?= icon('cart', 'width:16px') ?><span><?= e(t('В корзину')) ?></span></button>
                  </div>
                </div>
              </th>
            <?php endforeach; ?>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $r): ?>
            <tr class="<?= $r['diff'] ? 'diff' : 'same' ?>">
              <th scope="row" class="cmp-lab"><?= e($r['label']) ?></th>
              <?php foreach ($products as $p): $v = $r['values'][$p['id']] ?? ''; ?>
                <?php if ($r['kind'] === 'price'): ?>
                  <td data-col="<?= (int) $p['id'] ?>" data-v="<?= (int) round((float) $v) ?>"><?= price_html($v) ?></td>
                <?php else: ?>
                  <td data-col="<?= (int) $p['id'] ?>" data-v="<?= e(mb_strtolower(trim((string) $v))) ?>"><?= (string) $v !== '' ? e($v) : '<span class="muted">—</span>' ?></td>
                <?php endif; ?>
              <?php endforeach; ?>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <p class="muted cmp-note"><?= e(t('Цены — оптовые, за ящик и за пару. Прокрутите таблицу вбок, если товаров много.')) ?></p>
  </div>
  <?php endif; ?>
</div>
