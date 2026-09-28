<?php
/**
 * Страница товара (дизайн 1) и страница отзывов о товаре ($mode = 'reviews').
 * Страница кэшируется для всех: корзина, избранное, сравнение, «Вы недавно смотрели» — в JS из cookie.
 * @var array $p товар (Products::byUrl) @var App\Core\Seo $seo @var array $crumbs
 * @var array $reviews @var int $rCount @var float $rAvg @var array $phones @var int $freeBoxes @var View $view
 * Товар: @var array $images @var array $features @var array $similar @var ?array $category @var float $rating @var int $ratingCount
 * Отзывы: @var App\Core\Paginator $pager @var ?array $flash
 */
use App\Services\Catalog;

$mode ??= 'product';
$brand = Catalog::brand($p['brand_id'] ? (int) $p['brand_id'] : null);
$box = (int) $p['box_qty'];
$tel = static fn(string $s): string => 'tel:+' . preg_replace('/\D/', '', $s);
$summary = trim((string) ($p['summary'] ?? ''));
$descr = trim((string) ($p['description'] ?? ''));
$inStock = (bool) $p['in_stock'];
// «8 пар», «3 ящика» — ключи словаря целыми фразами (в украинском свои окончания)
$pairs = static fn(int $n): string => t(plural($n, '{n} пара', '{n} пары', '{n} пар'), ['n' => $n]);
$boxes = static fn(int $n): string => t(plural($n, '{n} ящик', '{n} ящика', '{n} ящиков'), ['n' => $n]);
$stars = static function (float $r): string {
    $h = '<span class="stars" role="img" aria-label="' . e(t('Оценка {r} из 5', ['r' => rtrim(rtrim(number_format($r, 1, '.', ''), '0'), '.')])) . '">';
    for ($i = 1; $i <= 5; $i++) $h .= '<i class="' . ($r >= $i - 0.25 ? 'on' : ($r >= $i - 0.75 ? 'half' : '')) . '"></i>';
    return $h . '</span>';
};
$reviewsUrl = $p['link'] . 'reviews/';
?>
<?php if ($mode === 'reviews'): ?>
<div class="wrap pp pp-rvpage">
  <div class="page-head">
    <?= $view->partial('front/partials/crumbs', ['items' => $crumbs]) ?>
    <h1><?= e($seo->h1) ?></h1>
    <?php if ($rCount): ?><div class="pp-rvsum"><?= $stars($rAvg) ?> <b><?= e(number_format($rAvg, 1, ',', '')) ?></b> · <?= e(t(plural($rCount, '{n} отзыв', '{n} отзыва', '{n} отзывов'), ['n' => $rCount])) ?></div><?php endif; ?>
  </div>
  <div class="layout-2r">
    <div class="pp-rvmain">
      <?= $view->partial('front/partials/product-reviews', ['p' => $p, 'reviews' => $reviews, 'rCount' => $rCount, 'full' => true, 'pager' => $pager, 'flash' => $flash ?? null, 'stars' => $stars]) ?>
    </div>
    <aside class="pp-mini panel sticky" data-id="<?= (int) $p['id'] ?>" aria-label="<?= e(t('Товар')) ?>">
      <a class="pp-mini-ph" href="<?= e($p['link']) ?>"><img src="<?= e($p['img']) ?>" alt="<?= e($p['name']) ?>" width="200" height="200" loading="lazy"></a>
      <a class="pp-mini-nm" href="<?= e($p['link']) ?>"><?= e($p['name']) ?></a>
      <div class="pp-mini-meta muted"><?= $p['size'] !== '' ? e(t('р.')) . ' ' . e($p['size']) . ' · ' : '' ?><?= e(t('{pairs} в ящике', ['pairs' => $pairs($box)])) ?></div>
      <div class="pp-mini-pr"><?= price_html($p['box_price'], 'b') ?> <span class="muted"><?= e(t('за ящик')) ?> · <?= price_html($p['price']) ?> / <?= e(t('пара')) ?></span></div>
      <div class="pp-mini-buy">
        <div class="qty"><button type="button" data-q="-1" aria-label="<?= e(t('Меньше ящиков')) ?>">−</button><input value="1" inputmode="numeric" aria-label="<?= e(t('Количество ящиков')) ?>"><button type="button" data-q="1" aria-label="<?= e(t('Больше ящиков')) ?>">+</button></div>
        <button class="btn btn-o" data-act="cart"<?= $inStock ? '' : ' disabled' ?>><?= icon('cart', 'width:18px') ?><?= e(t('В корзину')) ?></button>
      </div>
      <a class="link" href="<?= e($p['link']) ?>">← <?= e(t('Вернуться к товару')) ?></a>
    </aside>
  </div>
</div>
<?php return; endif; ?>

<?php
$off = (int) $p['off'];
$oldBox = $p['compare_price'] * $box;
$hasKolVo = false;
foreach ($features as $f) if ($f['code'] === 'kol_vo_par') $hasKolVo = true;
$phones = array_values(array_filter(array_map('strval', $phones)));
$ico = static fn(string $n, int $s = 18): string => icon($n, "width:{$s}px;height:{$s}px");
?>
<div class="wrap pp" data-viewed="<?= (int) $p['id'] ?>">
  <div class="pp-head">
    <?= $view->partial('front/partials/crumbs', ['items' => $crumbs]) ?>
    <h1 class="pp-h1"><?= e($seo->h1) ?></h1>
    <div class="pp-meta">
      <span class="pp-stock<?= $inStock ? '' : ' out' ?>"><?= e(t($inStock ? 'В наличии' : 'Нет в наличии')) ?></span>
      <?php if ($p['sku'] !== ''): ?><span class="pp-sku"><?= e(t('Артикул')) ?>: <b><?= e($p['sku']) ?></b></span><?php endif; ?>
      <?php if ($brand): ?><span><?= e(t('Производитель')) ?>: <a class="pp-brand" href="<?= e(Catalog::brandUrl($brand)) ?>"><?= e($brand['name']) ?></a></span><?php endif; ?>
      <?php if ($rating > 0): ?><span class="pp-rating" title="<?= e(t('Средняя оценка покупателей: {r} / 5', ['r' => number_format($rating, 1, '.', '')])) ?>"><?= $stars($rating) ?><b><?= e(number_format($rating, 1, ',', '')) ?></b></span><?php endif; ?>
      <a class="pp-rvlink" href="#reviews" data-pp-reviews><?= $ico('star', 16) ?><?= e(t('Отзывы ({n})', ['n' => $rCount])) ?></a>
    </div>
  </div>

  <div class="pp-top">
    <div class="pp-gal"><?= $view->partial('front/partials/gallery', ['p' => $p, 'images' => $images, 'alt' => $seo->h1]) ?></div>

    <div class="pp-mid">
      <?php if ($summary !== ''): ?><p class="pp-summary"><?= e($summary) ?></p><?php endif; ?>
      <?php if ($features): ?>
        <h2 class="pp-mid-h"><?= e(t('Характеристики')) ?></h2>
        <dl class="pp-short">
          <?php foreach (array_slice($features, 0, 6) as $f): ?><div><dt><?= e($f['name']) ?></dt><dd><?= e(implode(', ', $f['values'])) ?></dd></div><?php endforeach; ?>
        </dl>
        <a class="link pp-allspec" href="#pp-info" data-pp-specs><?= e(t('Все характеристики')) ?></a>
      <?php endif; ?>
      <?php if ($category): ?>
        <div class="pp-cats"><span class="muted"><?= e(t('Категория')) ?>:</span> <a class="chip" href="<?= e(Catalog::categoryUrl($category)) ?>"><?= e(nice_case((string) $category['name'])) ?></a></div>
      <?php endif; ?>
      <ul class="pp-adv">
        <li><span class="ic"><?= $ico('truck') ?></span><span><?= t(plural($freeBoxes, 'Доставка бесплатно от <b>{n} ящика</b>', 'Доставка бесплатно от <b>{n} ящиков</b>', 'Доставка бесплатно от <b>{n} ящиков</b>'), ['n' => $freeBoxes]) ?></span></li>
        <li><span class="ic"><?= $ico('refresh') ?></span><span><?= e(t('Ежедневное обновление ассортимента')) ?></span></li>
        <li><span class="ic"><?= $ico('card') ?></span><span><?= e(t('Удобная оплата: на карту или наложенным платежом')) ?></span></li>
        <li><span class="ic"><?= $ico('gift') ?></span><span><?= e(t('Бонусы и скидки постоянным клиентам')) ?></span></li>
      </ul>
    </div>

    <section class="pp-buy panel" id="pp-buy" data-id="<?= (int) $p['id'] ?>" data-box="<?= $box ?>" data-pair="<?= (int) round($p['price']) ?>" data-name="<?= e($p['name']) ?>" aria-label="<?= e(t('Покупка')) ?>">
      <div class="pp-boxinfo"><?= $ico('box', 20) ?><span><?= t('В одном ящике: <b>{pairs}</b> (минимальный заказ)', ['pairs' => e($pairs($box))]) ?><?= $p['size'] !== '' ? '<br>' . e(t('Размерный ряд')) . ': <b>' . e($p['size']) . '</b>' : '' ?></span></div>

      <div class="pp-price">
        <div class="pp-price-top">
          <div>
            <small><?= e(t('Цена за ящик')) ?></small>
            <?= price_html($p['box_price'], 'div', 'pp-boxprice') ?>
          </div>
          <?php if ($off): ?><span class="pp-off">−<?= $off ?>%</span><?php endif; ?>
        </div>
        <?php if ($off): ?><div class="pp-old"><s data-uah="<?= (int) round($oldBox) ?>"><?= e(price_format($oldBox)) ?></s> <?= e(t('за ящик')) ?></div><?php endif; ?>
        <div class="pp-pair"><?= e(t('Цена за пару')) ?>: <b><?= price_html($p['price']) ?></b><?php if ($off): ?> <s data-uah="<?= (int) round($p['compare_price']) ?>"><?= e(price_format($p['compare_price'])) ?></s><?php endif; ?></div>
        <?php if ($off): ?><div class="pp-save"><?= $ico('percent') ?><span><?= t('Экономия <b>{p}%</b> — {sum} на каждом ящике', ['p' => $off, 'sum' => '<b data-uah="' . (int) round($oldBox - $p['box_price']) . '">' . e(price_format($oldBox - $p['box_price'])) . '</b>']) ?></span></div><?php endif; ?>
      </div>

      <div class="pp-cart">
        <div class="pp-qtyrow">
          <span class="pp-qtyl" id="pp-qtyl"><?= e(t('Количество ящиков')) ?></span>
          <div class="qty pp-qty"><button type="button" data-q="-1" aria-label="<?= e(t('Меньше ящиков')) ?>"><?= $ico('minus') ?></button><input id="pp-qty" value="1" inputmode="numeric" maxlength="3" aria-labelledby="pp-qtyl"><button type="button" data-q="1" aria-label="<?= e(t('Больше ящиков')) ?>"><?= $ico('plus') ?></button></div>
        </div>
        <div class="pp-calc" id="pp-calc" aria-live="polite"><span data-pp-boxes><?= e($boxes(1)) ?></span> = <span data-pp-pairs><?= e($pairs($box)) ?></span> = <b data-pp-sum data-uah="<?= (int) round($p['box_price']) ?>"><?= e(price_format($p['box_price'])) ?></b></div>
        <?php if (!$inStock): ?><p class="note warn pp-na" role="status"><?= e(t('Сейчас этой модели нет в наличии. Позвоните нам — подберём похожую.')) ?></p><?php endif; ?>
        <div class="pp-btns">
          <button class="btn btn-o pp-add" id="pp-add" data-act="cart"<?= $inStock ? '' : ' disabled' ?>><?= $ico('cart', 20) ?><?= e(t('В корзину')) ?></button>
          <button class="btn btn-g pp-one" type="button" data-pp-quick<?= $inStock ? '' : ' disabled' ?>><?= e(t('Купить в 1 клик')) ?></button>
        </div>
        <div class="pp-acts">
          <button type="button" class="pp-act fav" data-act="fav" aria-label="<?= e(t('Добавить в избранное')) ?>"><?= $ico('heart') ?><span><?= e(t('В избранное')) ?></span></button>
          <button type="button" class="pp-act" data-act="cmp" aria-label="<?= e(t('Добавить к сравнению')) ?>"><?= $ico('cmp') ?><span><?= e(t('Сравнить')) ?></span></button>
        </div>
      </div>

      <?php if ($phones): ?>
      <div class="pp-phone">
        <span class="ic"><?= $ico('phone', 20) ?></span>
        <div><small><?= e(t('Заказ по телефону')) ?></small>
          <?php foreach ($phones as $ph): ?><a href="<?= e($tel($ph)) ?>"><?= e($ph) ?></a><?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>
    </section>

    <div class="pp-info" id="pp-info">
      <div class="tabline" role="tablist" aria-label="<?= e(t('Информация о товаре')) ?>">
        <button type="button" class="on" role="tab" id="tb-spec" data-tab="t-spec" aria-controls="t-spec" aria-selected="true"><?= e(t('Характеристики')) ?></button>
        <button type="button" role="tab" id="tb-desc" data-tab="t-desc" aria-controls="t-desc" aria-selected="false"><?= e(t('Описание')) ?></button>
        <button type="button" role="tab" id="tb-rev" data-tab="t-rev" aria-controls="t-rev" aria-selected="false"><?= e(t('Отзывы ({n})', ['n' => $rCount])) ?></button>
      </div>
      <div class="panel pp-pane" id="t-spec" role="tabpanel" aria-labelledby="tb-spec">
        <dl class="pp-specs">
          <?php if ($p['sku'] !== ''): ?><div><dt><?= e(t('Артикул')) ?></dt><dd><?= e($p['sku']) ?></dd></div><?php endif; ?>
          <?php foreach ($features as $f): ?>
            <div><dt><?= e($f['name']) ?></dt><dd><?php if ($f['code'] === 'brand' && $brand): ?><a class="link" href="<?= e(Catalog::brandUrl($brand)) ?>"><?= e(implode(', ', $f['values'])) ?></a><?php else: ?><?= e(implode(', ', $f['values'])) ?><?php endif; ?></dd></div>
          <?php endforeach; ?>
          <?php if (!$hasKolVo): ?><div><dt><?= e(t('Пар в ящике')) ?></dt><dd><?= $box ?></dd></div><?php endif; ?>
          <?php if ($category): ?><div><dt><?= e(t('Категория')) ?></dt><dd><a class="link" href="<?= e(Catalog::categoryUrl($category)) ?>"><?= e(nice_case((string) $category['name'])) ?></a></dd></div><?php endif; ?>
        </dl>
      </div>
      <div class="panel pp-pane hidden" id="t-desc" role="tabpanel" aria-labelledby="tb-desc">
        <div class="prose">
          <?php if ($descr !== ''): ?>
            <?= content_html($descr) ?>
          <?php else: ?>
            <p><?= e($brand
                ? t('{name} от производителя {brand} — оптом, ящиками по {pairs}.', ['name' => $p['name'], 'brand' => $brand['name'], 'pairs' => $pairs($box)])
                : t('{name} — оптом, ящиками по {pairs}.', ['name' => $p['name'], 'pairs' => $pairs($box)])) ?><?= $p['size'] !== '' ? ' ' . e(t('Размерный ряд')) . ': ' . e($p['size']) . '.' : '' ?></p>
            <p><?= e(t('Цена за ящик — {box} ({pair} за пару). Отправляем по всей Украине, при заказе от {n} ящиков доставка бесплатная.', ['pair' => price_format($p['price']), 'box' => price_format($p['box_price']), 'n' => $freeBoxes])) ?></p>
          <?php endif; ?>
        </div>
      </div>
      <div class="panel pp-pane hidden" id="t-rev" role="tabpanel" aria-labelledby="tb-rev">
        <?= $view->partial('front/partials/product-reviews', ['p' => $p, 'reviews' => $reviews, 'rCount' => $rCount, 'full' => false, 'stars' => $stars]) ?>
      </div>
    </div>
  </div>

  <?php if ($similar): ?>
  <section class="sec pp-sec" aria-labelledby="pp-sim-h">
    <div class="sh"><h2 id="pp-sim-h"><?= e(t('Похожие товары')) ?></h2>
      <div class="pp-railnav"><?php if ($category): ?><a class="more" href="<?= e(Catalog::categoryUrl($category)) ?>"><?= e(t('Все товары категории')) ?> <?= icon('arrow', 'width:18px') ?></a><?php endif; ?>
        <button type="button" class="pp-go" data-rail-go="-1" aria-label="<?= e(t('Прокрутить назад')) ?>"><?= $ico('left') ?></button><button type="button" class="pp-go" data-rail-go="1" aria-label="<?= e(t('Прокрутить вперёд')) ?>"><?= $ico('chev') ?></button></div>
    </div>
    <div class="pp-rail" data-rail><div class="grid"><?php foreach ($similar as $s) echo $view->partial('front/partials/card', ['p' => $s]); ?></div></div>
  </section>
  <?php endif; ?>

  <section class="sec pp-sec hidden" id="pp-viewed" aria-labelledby="pp-viewed-h">
    <div class="sh"><h2 id="pp-viewed-h"><?= e(t('Вы недавно смотрели')) ?></h2>
      <div class="pp-railnav"><a class="more" href="/search/?_balance_type=viewed"><?= e(t('Все просмотренные')) ?> <?= icon('arrow', 'width:18px') ?></a>
        <button type="button" class="pp-go" data-rail-go="-1" aria-label="<?= e(t('Прокрутить назад')) ?>"><?= $ico('left') ?></button><button type="button" class="pp-go" data-rail-go="1" aria-label="<?= e(t('Прокрутить вперёд')) ?>"><?= $ico('chev') ?></button></div>
    </div>
    <div class="pp-rail" data-rail><div class="grid" id="pp-viewed-list"></div></div>
  </section>
</div>

<div class="pp-bar" id="pp-bar" role="region" aria-label="<?= e(t('Быстрая покупка')) ?>">
  <div class="pp-bar-pr"><small data-pp-bar-l><?= e(t('За ящик')) ?> · <?= e($pairs($box)) ?></small><?= price_html($p['box_price'], 'b', 'pp-bar-sum') ?></div>
  <button type="button" class="btn btn-o" data-pp-cart<?= $inStock ? '' : ' disabled' ?>><?= $ico('cart') ?><?= e(t('В корзину')) ?></button>
</div>
