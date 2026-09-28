<?php
/**
 * Вкладка «Доставка и оплата»: списки способов (settings shipping_methods / payment_methods).
 * Код способа (p4, p10…) сохраняется — по нему связаны старые заказы. У каждого способа — название и описание
 * на русском и украинском (name_uk / description_uk).
 * @var array $lists key → [заголовок, строки] @var array $used 's:код'/'p:код' → число заказов @var array $errors
 */
$row = static function (string $field, $i, array $r, int $used, int $pos): string {
    $n = e($field) . '[' . e((string) $i) . ']';
    $s = static fn(string $k) => e(is_scalar($r[$k] ?? null) ? (string) $r[$k] : '');
    $code = (string) ($r['code'] ?? '');
    $name = (string) ($r['name'] ?? '');
    return '<div class="list-row sm-row' . (empty($r['status']) ? ' is-off' : '') . '">'
        . '<div class="sm-ord"><input type="hidden" name="' . $n . '[sort]" value="' . $pos . '">'
        . '<input type="hidden" name="' . $n . '[id]" value="' . (int) ($r['id'] ?? 0) . '"><input type="hidden" name="' . $n . '[code]" value="' . e($code) . '">'
        . '<button type="button" class="btn btn-sm" data-row-up aria-label="Поднять выше «' . e($name) . '»" title="Выше">' . icon('up', 'width:14px;height:14px') . '</button></div>'
        . '<div class="sm-main">'
        . '<div class="i18n"><div class="i18n-in"><b class="lt">RU</b><input type="text" name="' . $n . '[name]" value="' . $s('name') . '" maxlength="190" aria-label="Название" placeholder="Название"></div>'
        . '<div class="i18n-in"><b class="lt uk">UA</b><input type="text" name="' . $n . '[name_uk]" value="' . $s('name_uk') . '" maxlength="190" aria-label="Название — украинский" placeholder="' . ($name !== '' ? e($name) : 'Назва') . '"></div></div>'
        . '<small class="muted">' . ($code !== '' ? 'код ' . e($code) . ($used ? ' · в заказах: ' . number_format($used, 0, '', ' ') : '') : 'новый способ') . '</small></div>'
        . '<div class="sm-desc">'
        . '<div class="i18n"><div class="i18n-in"><b class="lt">RU</b><textarea name="' . $n . '[description]" rows="1" maxlength="1000" class="plain" aria-label="Описание" placeholder="Описание для покупателя">' . $s('description') . '</textarea></div>'
        . '<div class="i18n-in"><b class="lt uk">UA</b><textarea name="' . $n . '[description_uk]" rows="1" maxlength="1000" class="plain" aria-label="Описание — украинский" placeholder="Опис для покупця">' . $s('description_uk') . '</textarea></div></div></div>'
        . '<div class="sm-act"><label class="check"><input type="checkbox" name="' . $n . '[status]" value="1"' . (!empty($r['status']) ? ' checked' : '') . '> включён</label>'
        . '<button type="button" class="btn btn-sm btn-d" data-row-del' . ($used ? ' data-used="' . $used . '"' : '') . ' aria-label="Удалить «' . e($name ?: 'новый способ') . '»" title="Удалить">' . icon('x', 'width:14px;height:14px') . '</button></div>'
        . '</div>';
};
?>
<?php foreach ($lists as $key => [$title, $items]): $field = $key === 'shipping_methods' ? 'shipping' : 'payment'; $pref = $field === 'shipping' ? 's:' : 'p:'; ?>
<div class="card">
  <h2><?= e($title) ?> <span class="muted">· <?= count($items) ?></span></h2>
  <?php if (isset($errors[$key])): ?><p class="fld-err" role="alert"><?= e($errors[$key]) ?></p><?php endif; ?>
  <div class="sm-head" aria-hidden="true"><span></span><span>Название</span><span>Описание (пусто — подсказка по умолчанию)</span><span></span></div>
  <div class="list-rows sm-list" data-list="<?= e($field) ?>" data-renumber>
    <?php foreach (array_values($items) as $i => $r): ?><?= $row($field, $i, (array) $r, (int) ($used[$pref . ($r['code'] ?? '')] ?? 0), $i + 1) ?><?php endforeach; ?>
  </div>
  <template data-row-tpl="<?= e($field) ?>"><?= $row($field, '__i__', ['status' => 1], 0, 99) ?></template>
  <p style="margin-top:12px"><button type="button" class="btn btn-sm" data-row-add="<?= e($field) ?>">+ Добавить способ</button></p>
</div>
<?php endforeach; ?>
<p class="hint">Порядок — кнопкой «↑», сохраняется вместе с формой. Выключенные способы не показываются при оформлении заказа, но остаются в старых заказах.
  Удалять способ, по которому есть заказы, не нужно — лучше выключить. UA — названия для украинской версии оформления заказа (/ua/order/), пусто — русское.</p>
