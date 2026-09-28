<?php
/**
 * Фильтры списка в формате старого сайта: price_min / price_max и {код}[]=id значения.
 * На широком экране — колонка слева (применяются сразу при выборе), на мобильном — выезжающая
 * панель .filters-wrap (кнопка «Фильтры» в тулбаре, применение кнопкой «Показать товары»).
 * @var App\Services\Listing $L
 * @var array $groups     Listing::categoryGroups()/brandGroups()
 * @var bool  $hasPrice
 * @var array $priceRange [min, max]
 * @var array $extraLinks необязательно: [['name','url','count']] — блок ссылок над фильтрами (категории бренда)
 * @var string $extraTitle
 */
use App\Services\Listing;

$limit = 8;                                  // сколько значений видно сразу, остальные — «Показать ещё»
$shown = array_flip(array_column($groups, 'id'));
$all = Listing::filterFeatures();
$priceRange ??= [0, 0];
$extraLinks ??= [];
?>
<aside class="filters-wrap" id="cat-filters" aria-label="<?= e(t('Фильтры')) ?>">
  <form class="filters" method="get" action="<?= e($L->base) ?>" data-filters>
    <div class="fh">
      <span class="fh-t"><?= icon('filter', 'width:18px;height:18px') ?> <?= e(t('Фильтры')) ?></span>
      <?php if ($L->hasFilters()): ?><a class="link fh-reset" href="<?= e($L->url(['filters' => false])) ?>" rel="nofollow"><?= e(t('Сбросить')) ?></a><?php endif; ?>
      <button type="button" class="fh-x" data-close aria-label="<?= e(t('Закрыть фильтры')) ?>"><?= icon('x') ?></button>
    </div>
    <?php foreach ($L->extra as $k => $v): ?><input type="hidden" name="<?= e($k) ?>" value="<?= e($v) ?>"><?php endforeach; ?>
    <?php if ($L->sortSet): ?><input type="hidden" name="sort" value="<?= e($L->sort) ?>"><input type="hidden" name="order" value="<?= e($L->order) ?>"><?php endif; ?>
    <?php if ($L->view === 'table'): ?><input type="hidden" name="view" value="table"><?php endif; ?>
    <?php foreach ($L->features as $fid => $vals): if (isset($shown[$fid]) || !isset($all[$fid])) continue; ?>
      <?php foreach ($vals as $vid): ?><input type="hidden" name="<?= e($all[$fid]['code']) ?>[]" value="<?= Listing::urlValue($fid, $vid) ?>" data-fkeep><?php endforeach; ?>
    <?php endforeach; ?>

    <?php if ($extraLinks): ?>
      <details class="fgroup" open>
        <summary><?= e($extraTitle ?? t('Категории')) ?><?= icon('chev') ?></summary>
        <div class="fb flinks">
          <?php foreach ($extraLinks as $x): ?><a href="<?= e($x['url']) ?>" rel="nofollow"><span class="fn"><?= e($x['name']) ?></span><span class="cnt"><?= (int) $x['count'] ?></span></a><?php endforeach; ?>
        </div>
      </details>
    <?php endif; ?>

    <?php if ($hasPrice): ?>
      <details class="fgroup" open>
        <summary><?= e(t('Цена за пару, грн')) ?><?php if ($L->priceMin !== null || $L->priceMax !== null): ?> <b class="fcnt">1</b><?php endif; ?><?= icon('chev') ?></summary>
        <div class="fb">
          <div class="price-range">
            <label><span class="visually-hidden"><?= e(t('Цена от, грн')) ?></span><input class="input" type="number" inputmode="numeric" min="0" step="1" name="price_min" value="<?= $L->priceMin !== null ? e((string) (float) $L->priceMin) : '' ?>" placeholder="<?= e(t('от')) ?> <?= (int) $priceRange[0] ?>" data-price></label>
            <label><span class="visually-hidden"><?= e(t('Цена до, грн')) ?></span><input class="input" type="number" inputmode="numeric" min="0" step="1" name="price_max" value="<?= $L->priceMax !== null ? e((string) (float) $L->priceMax) : '' ?>" placeholder="<?= e(t('до')) ?> <?= (int) $priceRange[1] ?>" data-price></label>
          </div>
          <button type="submit" class="btn btn-g btn-sm btn-block fprice-go"><?= e(t('Применить')) ?></button>
        </div>
      </details>
    <?php endif; ?>

    <?php foreach ($groups as $i => $g): $n = count($g['values']); $hidden = 0; foreach ($g['values'] as $k => $v) if ($k >= $limit && !$v['on']) $hidden++; ?>
      <details class="fgroup" data-fgroup<?= ($i < 4 || $g['active']) ? ' open' : '' ?>>
        <summary><?= e($g['name']) ?><?php if ($g['active']): ?> <b class="fcnt"><?= (int) $g['active'] ?></b><?php endif; ?><?= icon('chev') ?></summary>
        <div class="fb">
          <?php if ($n > 12): ?><input class="input fsearch" type="search" placeholder="<?= e(t('Найти…')) ?>" aria-label="<?= e(t('Найти значение: {name}', ['name' => $g['name']])) ?>" data-fsearch><?php endif; ?>
          <?php foreach ($g['values'] as $k => $v): ?>
            <label class="check fv<?= ($k >= $limit && !$v['on']) ? ' x' : '' ?>"><input type="checkbox" name="<?= e($g['code']) ?>[]" value="<?= (int) $v['v'] ?>"<?= $v['on'] ? ' checked' : '' ?>><?php if ($v['color']): ?><i class="sw" style="background:<?= e($v['color']) ?>"></i><?php endif; ?><span class="fn"><?= e($v['name']) ?></span><span class="cnt"><?= (int) $v['cnt'] ?></span></label>
          <?php endforeach; ?>
          <?php if ($hidden): ?><button type="button" class="fmore" data-fmore aria-expanded="false"><?= e(t('Показать ещё {n}', ['n' => $hidden])) ?></button><?php endif; ?>
        </div>
      </details>
    <?php endforeach; ?>

    <div class="fact">
      <button type="submit" class="btn btn-o btn-block"><?= e(t('Показать товары')) ?></button>
      <?php if ($L->hasFilters()): ?><a class="btn btn-g btn-block" href="<?= e($L->url(['filters' => false])) ?>" rel="nofollow"><?= e(t('Сбросить фильтры')) ?></a><?php endif; ?>
    </div>
  </form>
</aside>
