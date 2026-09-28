<?php
/**
 * Галерея товара: большое фото (970, srcset 400/750x0/970), миниатюры 96x96, стрелки, свайп и лайтбокс на весь экран
 * (поведение — assets/js/product.js). Адреса фото совместимы со старым сайтом (Image::url).
 * @var array $p товар @var array $images Products::images() @var string $alt
 */
use App\Core\Image;

$list = [];
foreach ($images as $im) {
    $list[] = ['id' => (int) $im['id'], 'ext' => (string) $im['ext'], 'fn' => (string) $im['filename'],
        'w' => (int) $im['width'], 'h' => (int) $im['height'], 'd' => trim((string) ($im['description'] ?? ''))];
}
if (!$list && !empty($p['image_id'])) {
    $list[] = ['id' => (int) $p['image_id'], 'ext' => (string) ($p['image_ext'] ?: 'jpg'), 'fn' => '', 'w' => 0, 'h' => 0, 'd' => ''];
}
/**
 * srcset большого фото: миниатюры 400 (по большей стороне), 750x0 (по ширине) и 970 из Image::SIZES —
 * создаются при первом обращении (ImageController), дальше их отдаёт веб-сервер. Ширина — фактическая:
 * миниатюра не увеличивает фото, поэтому у фото 450×450 остаются 400w и 450w; вариант не шире основного (970) не нужен.
 */
$widthOf = static function (array $im, string $size): int {
    if (!$im['w'] || !$im['h']) return $size === '750x0' ? 750 : (int) $size;   // размеры фото неизвестны — номинальные
    if ($size === '750x0') return min(750, $im['w']);
    return (int) round($im['w'] * min(1, (int) $size / max($im['w'], $im['h'])));
};
foreach ($list as &$im) {
    $im['big'] = Image::url($p['id'], $im['id'], $im['ext'], '970', $im['fn']);
    $im['th'] = Image::url($p['id'], $im['id'], $im['ext'], '96x96', $im['fn']);
    // размеры для резерва места (миниатюра не увеличивает маленькие фото)
    $k = ($im['w'] && $im['h']) ? min(1, 970 / max($im['w'], $im['h'])) : 0;
    $im['bw'] = $k ? (int) round($im['w'] * $k) : 970;
    $im['bh'] = $k ? (int) round($im['h'] * $k) : 970;
    $set = [$widthOf($im, '970') => $im['big']];
    foreach (['750x0', '400'] as $size) {
        $w = $widthOf($im, $size);
        if ($w < min(array_keys($set))) $set[$w] = Image::url($p['id'], $im['id'], $im['ext'], $size, $im['fn']);
    }
    ksort($set);
    $im['set'] = implode(', ', array_map(static fn($w, $u) => $u . ' ' . $w . 'w', array_keys($set), $set));
}
unset($im);
// ширина фото на экране (30-product.css: колонка галереи 540px, на ≤980px — до 560px, на ≤680px — во всю ширину минус поля)
$sizes = '(max-width:680px) calc(100vw - 54px), (max-width:980px) 522px, (max-width:1180px) calc(100vw - 462px), 502px';
$n = count($list);
$badge = $p['off'] ? '<span class="badge">−' . (int) $p['off'] . '%</span>' : ($p['is_new'] ? '<span class="badge nw">' . e(t('Новинка')) . '</span>' : '');
$first = $list[0] ?? null;
?>
<div class="gal" id="pp-gal" data-images="<?= e(json_encode(array_column($list, 'big'), JSON_UNESCAPED_SLASHES)) ?>" data-srcset="<?= e(json_encode(array_column($list, 'set'), JSON_UNESCAPED_SLASHES)) ?>">
  <div class="gal-main">
    <?php if ($first): ?>
      <button type="button" class="gal-open" data-gal-open aria-label="<?= e(t('Открыть фото на весь экран')) ?>">
        <img id="gal-img" src="<?= e($first['big']) ?>" srcset="<?= e($first['set']) ?>" sizes="<?= e($sizes) ?>" alt="<?= e($first['d'] !== '' ? $first['d'] : $alt) ?>" width="<?= $first['bw'] ?>" height="<?= $first['bh'] ?>" fetchpriority="high" decoding="async">
      </button>
      <span class="gal-zoom" aria-hidden="true"><?= icon('zoom', 'width:20px;height:20px') ?></span>
    <?php else: ?>
      <div class="gal-empty"><?= icon('box', 'width:48px;height:48px') ?><span><?= e(t('Фото скоро появится')) ?></span></div>
    <?php endif; ?>
    <?php if ($badge): ?><div class="gal-badges"><?= $badge ?></div><?php endif; ?>
    <?php if ($n > 1): ?>
      <button type="button" class="gal-arr prev" data-gal-go="-1" aria-label="<?= e(t('Предыдущее фото')) ?>"><?= icon('left') ?></button>
      <button type="button" class="gal-arr next" data-gal-go="1" aria-label="<?= e(t('Следующее фото')) ?>"><?= icon('chev') ?></button>
      <div class="gal-count" aria-hidden="true"><span data-gal-cur>1</span> / <?= $n ?></div>
    <?php endif; ?>
  </div>
  <?php if ($n > 1): ?>
    <div class="gal-thumbs" role="group" aria-label="<?= e(t('Все фото товара')) ?>">
      <?php foreach ($list as $i => $im): ?>
        <button type="button" class="gal-th<?= $i ? '' : ' on' ?>" data-gal-i="<?= $i ?>" aria-label="<?= e(t('Фото {i} из {n}', ['i' => $i + 1, 'n' => $n])) ?>"<?= $i ? '' : ' aria-current="true"' ?>><img src="<?= e($im['th']) ?>" alt="" width="96" height="96" loading="lazy" decoding="async" fetchpriority="low"></button>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>
<?php if ($first): ?>
<div class="gal-lb" id="gal-lb" role="dialog" aria-modal="true" aria-label="<?= e(t('{name} — фото', ['name' => $alt])) ?>" hidden>
  <div class="gal-lb-top"><span class="gal-lb-t"><?= e($p['name']) ?></span><?php if ($n > 1): ?><span class="gal-lb-n" aria-live="polite"><span data-gal-cur>1</span> / <?= $n ?></span><?php endif; ?>
    <button type="button" class="gal-lb-x" data-gal-close aria-label="<?= e(t('Закрыть')) ?>"><?= icon('x') ?></button></div>
  <div class="gal-lb-stage" data-gal-stage><img id="gal-lb-img" src="<?= e($first['big']) ?>" alt="<?= e($alt) ?>" loading="lazy" decoding="async"></div>
  <?php if ($n > 1): ?>
    <button type="button" class="gal-arr prev" data-gal-go="-1" aria-label="<?= e(t('Предыдущее фото')) ?>"><?= icon('left') ?></button>
    <button type="button" class="gal-arr next" data-gal-go="1" aria-label="<?= e(t('Следующее фото')) ?>"><?= icon('chev') ?></button>
  <?php endif; ?>
</div>
<?php endif; ?>
