<?php
/**
 * Вкладка «Магазин»: название, контакты, телефоны (JSON-список phones), соцсети, параметры каталога.
 * У текстов из SettingsController::STORE_UK есть поле украинского варианта (ключ «<ключ>.uk»).
 * @var array $fields @var array $values @var array $phones @var array $errors
 */
use App\Controllers\Admin\SettingsController;

$f = static function (string $k) use ($fields, $values, $errors): string {
    [$label, $type, $hint] = $fields[$k];
    $v = $values[$k] ?? '';
    $attrs = match ($type) {
        'number' => ' type="number" step="1" min="0"',
        'email'  => ' type="email"',
        'url'    => ' type="text" inputmode="url"',
        default  => ' type="text"',
    };
    $id = 'st-' . str_replace('_', '-', $k);
    $input = '<input id="' . $id . '" name="' . e($k) . '" value="' . e(is_scalar($v) ? $v : '') . '"' . $attrs . ' maxlength="500"'
        . (isset($errors[$k]) ? ' aria-invalid="true"' : '') . '>';
    if (in_array($k, SettingsController::STORE_UK, true)) {
        $uk = $values[$k . '.uk'] ?? '';
        $input = '<div class="i18n"><div class="i18n-in"><b class="lt">RU</b>' . $input . '</div>'
            . '<div class="i18n-in"><b class="lt uk">UA</b><input type="text" name="uk[' . e($k) . ']" value="' . e(is_scalar($uk) ? $uk : '') . '" maxlength="500"'
            . ' aria-label="' . e($label) . ' — украинский вариант" placeholder="' . e(is_scalar($v) ? $v : '') . '"></div></div>';
        $hint = ($hint !== '' ? rtrim($hint, '.') . '. ' : '') . 'UA — для украинской версии сайта (/ua/), пусто — русский текст.';
    }
    return '<div class="fld"><label class="lbl" for="' . $id . '">' . e($label) . '</label>' . $input
        . (isset($errors[$k]) ? '<span class="fld-err" role="alert">' . e($errors[$k]) . '</span>' : '')
        . ($hint !== '' ? '<small class="hint">' . e($hint) . '</small>' : '') . '</div>';
};
if (!$phones) $phones = [''];
?>
<div class="grid2">
  <div class="card">
    <h2>Основное</h2>
    <?= $f('store_name') ?>
    <?= $f('site_title') ?>
    <?= $f('store_email') ?>
    <?= $f('address') ?>
    <?= $f('work_hours') ?>
    <?= $f('since_year') ?>
  </div>
  <div>
    <div class="card">
      <h2>Телефоны</h2>
      <div class="fld"><span id="lbl-phones">Телефоны на сайте (первый — в шапке)</span>
        <div class="list-rows" data-list="phones" role="group" aria-labelledby="lbl-phones">
          <?php foreach ($phones as $i => $p): ?>
            <div class="list-row"><input type="tel" name="phones[]" value="<?= e(is_scalar($p) ? $p : '') ?>" maxlength="40" placeholder="+38 (093) 275-3070" aria-label="Телефон <?= $i + 1 ?>">
              <button type="button" class="btn btn-sm" data-row-up aria-label="Поднять выше" title="Выше"><?= icon('up', 'width:14px;height:14px') ?></button>
              <button type="button" class="btn btn-sm btn-d" data-row-del aria-label="Удалить телефон" title="Удалить"><?= icon('x', 'width:14px;height:14px') ?></button></div>
          <?php endforeach; ?>
        </div>
        <?php if (isset($errors['phones'])): ?><span class="fld-err" role="alert"><?= e($errors['phones']) ?></span><?php endif; ?>
        <div><button type="button" class="btn btn-sm" data-row-add="phones">+ Добавить телефон</button></div>
        <small class="hint">Формат — как показывать на сайте. Ссылка «позвонить» строится из цифр. Первый номер — в шапке сайта, все — в подвале и на странице контактов.</small>
      </div>
      <?= $f('store_phone') ?>
    </div>
    <div class="card">
      <h2>Каталог и доставка</h2>
      <div class="row2"><?= $f('free_shipping_boxes') ?><?= $f('products_per_page') ?></div>
    </div>
  </div>
</div>
<div class="card">
  <h2>Соцсети и мессенджеры</h2>
  <p class="hint" style="margin:-6px 0 12px">Иконки в подвале сайта показываются только для заполненных ссылок.</p>
  <div class="row2"><?= $f('social_telegram') ?><?= $f('social_viber') ?></div>
  <div class="row2"><?= $f('social_instagram') ?><?= $f('social_facebook') ?></div>
</div>
