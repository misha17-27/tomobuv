<?php
/**
 * Вкладка «SEO-шаблоны»: у каждого шаблона русский и украинский вариант (ключ «<ключ>.uk», пусто — русский).
 * Под полем — пример результата на реальных данных (для UA — на украинских названиях).
 * @var array $groups @var array $values @var array $other @var array $blog @var array $service
 * @var array $sample @var array $sampleUk @var array $errors @var array $toggles группа → ключ флага «шаблоны включены»
 */
$varHelp = [
    'product.name' => 'название товара', 'product.seo_name' => 'SEO-название товара (или название)', 'product.format_price' => 'цена за пару «1 020 грн.»',
    'product.box_qty' => 'пар в ящике (с |plural:пара,пары,пар — «8 пар»)', 'product.sizes' => 'размеры «36-41» (мусор в поле — пусто)',
    'category.name' => 'название категории', 'category.seo_name' => 'SEO-название категории (или название)',
    'category.full_name' => 'полное имя «Детская обувь: кеды 26-32», «Детская зимняя обувь 12-26»', 'category.product_count' => 'моделей в категории (|plural:модель,модели,моделей)',
    'category.root_name' => 'корневой раздел «Женская обувь», «Детская обувь» (не про обувь — «Обувь»)',
    'brand.product_count' => 'моделей бренда (|plural:модель,модели,моделей)',
    'store_info.name' => 'название магазина', 'store_info.phone' => 'телефон для SEO', 'page.name' => 'название страницы',
    'brand.name' => 'название бренда', 'page_number' => 'номер страницы (2, 3…)',
];
/** Поле шаблона: RU и UA рядом. $name — имя массива в форме (seo|blog), $tpl — показывать пример по переменным */
$field = static function (string $key, string $label, bool $long, string $name = 'seo', bool $tpl = true) use ($values, $errors): string {
    $id = 'f-' . preg_replace('/[^a-z0-9_]/i', '-', $key);
    $cell = static function (string $lang) use ($key, $label, $long, $name, $tpl, $values, $errors, $id): string {
        $k = $lang === 'uk' ? $key . '.uk' : $key;
        $v = (string) ($values[$k] ?? '');
        $n = ($lang === 'uk' ? $name . '_uk' : $name) . '[' . e($key) . ']';
        $kind = \App\Core\Seo::fieldOf($key) ?? '';
        $attr = ' id="' . $id . ($lang === 'uk' ? '-uk' : '') . '" name="' . $n . '"' . ($tpl ? ' data-seo="' . $lang . '"' . ($kind !== '' ? ' data-seo-kind="' . $kind . '"' : '') : '')
            . (isset($errors[$k]) ? ' aria-invalid="true"' : '')
            . ($lang === 'uk' ? ' aria-label="' . e($label) . ' — украинский вариант" placeholder="пусто — русский вариант"' : '');
        $input = $long ? '<textarea' . $attr . ' rows="3" class="plain">' . e($v) . '</textarea>' : '<input type="text"' . $attr . ' value="' . e($v) . '">';
        return '<div class="i18n-in"><b class="lt' . ($lang === 'uk' ? ' uk' : '') . '">' . ($lang === 'uk' ? 'UA' : 'RU') . '</b><div>' . $input
            . (isset($errors[$k]) ? '<span class="fld-err" role="alert">' . e($errors[$k]) . '</span>' : '')
            . ($tpl ? '<small class="seo-prev" aria-live="polite"></small>' : '') . '</div></div>';
    };
    return '<div class="fld seo-fld"><label class="lbl" for="' . $id . '">' . e($label) . ' <code class="key">' . e($key) . '</code></label>'
        . '<div class="i18n i18n-2">' . $cell('ru') . $cell('uk') . '</div></div>';
};
?>
<div class="note-box">Шаблоны используются, когда у товара, категории, страницы или бренда не заполнены свои мета-теги.
  Нажмите на переменную, чтобы вставить её в поле, где стоит курсор. Под полем — пример результата на реальных данных.
  <b>UA</b> — шаблон для украинской версии сайта (/ua/…); пусто — используется русский шаблон.
  На страницах 2, 3… категории к title и description дополнительно добавляется «| Страница N».
  <br><b>[[…]]</b> — необязательная часть: пропадает, если в ней пустая переменная, а если title длиннее 70 или description длиннее 170 символов —
  такие части убираются справа налево (последней пишите наименее важную); <code>{$product.box_qty|plural:пара,пары,пар}</code> — «8 пар».
  Что всё равно длиннее нормы — сайт сокращает сам: title по слову, description по предложению.</div>
<script type="application/json" id="seo-sample"><?= json_encode(['ru' => $sample, 'uk' => $sampleUk], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>

<?php foreach ($groups as $g => [$title, $fields, $vars]): ?>
<div class="card seo-group" data-seo-group>
  <div class="seo-hd"><h2><?= e($title) ?></h2>
    <?php if (isset($toggles[$g])): $tk = $toggles[$g]; ?><input type="hidden" name="enabled[<?= e($tk) ?>]" value="0">
      <label class="check"><input type="checkbox" name="enabled[<?= e($tk) ?>]" value="1"<?= (string) ($values[$tk] ?? '1') !== '0' ? ' checked' : '' ?>> Использовать шаблоны</label><?php endif; ?></div>
  <div class="vars" role="group" aria-label="Переменные — нажмите, чтобы вставить"><?php foreach ($vars as $var): ?><button type="button" class="var" data-var="{$<?= e($var) ?>}" title="<?= e($varHelp[$var] ?? '') ?>">{$<?= e($var) ?>}</button><?php endforeach; ?></div>
  <?php foreach ($fields as $f => $label): ?>
    <?= $field('seo.' . $g . '_' . $f, $label, $f === 'meta_description' || $f === 'meta_keywords') ?>
  <?php endforeach; ?>
</div>
<?php endforeach; ?>

<div class="card">
  <h2>Блог — страница /blog/</h2>
  <p class="hint" style="margin:-6px 0 12px">Обычный текст, без переменных. Пусто — заголовок «<?= e((string) ($values['store_name'] ?? 'Tomobuv')) ?>» (название магазина). У статей — свои мета-теги в <a href="/admin/blog/">блоге</a>.</p>
  <?php foreach ($blog as $k => $label): ?><?= $field($k, $label, $k === 'blog.meta_description' || $k === 'blog.meta_keywords', 'blog', false) ?><?php endforeach; ?>
</div>

<?php if ($other): ?>
<div class="card seo-group" data-seo-group>
  <h2>Другие шаблоны (со старого сайта)</h2>
  <div class="vars" role="group" aria-label="Переменные — нажмите, чтобы вставить"><?php foreach (['product.name', 'category.name', 'brand.name', 'page.name', 'store_info.name'] as $var): ?><button type="button" class="var" data-var="{$<?= e($var) ?>}">{$<?= e($var) ?>}</button><?php endforeach; ?></div>
  <?php foreach ($other as $k => $label): ?><?= $field($k, $label, false) ?><?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($service): ?>
<details class="card">
  <summary><b>Служебные параметры Webasyst</b> <span class="muted">— только для справки, новым сайтом почти не используются</span></summary>
  <div class="table-scroll" style="margin-top:12px"><table class="grid"><tbody>
  <?php foreach ($service as $k => $v): ?><tr><td><code><?= e($k) ?></code></td><td><?= e($v) ?></td></tr><?php endforeach; ?>
  </tbody></table></div>
</details>
<?php endif; ?>
