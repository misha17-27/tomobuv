<?php
/**
 * Карточка товара (боковая колонка) и страница «Вся история»: «Загружен» — дата, время и источник (ProductHistory::origin),
 * «Изменён» — только реальные изменения после загрузки, кем/чем (ProductHistory::lastChange), иначе «изменений не было».
 * В карточке ($full = false) — ещё последние 10 событий (ProductHistory::events) и ссылка «Вся история», если событий больше.
 * @var array $p @var array $origin @var ?array $last @var array $hist ['items', 'more'] @var bool $full
 */
$dt = static fn(?string $d): string => $d ? date('d.m.Y H:i', strtotime($d)) : '—';
$full ??= false;
// автоисправление SEO меняет title/description, но дату изменения не сдвигает — подсказка, если оно было позже
$seo = null;
foreach ($hist['items'] as $e) if ($e['kind'] === 'seo') { $seo = $e; break; }
if ($seo && strtotime($seo['at']) <= strtotime((string) ($last['at'] ?? $p['created_at']))) $seo = null;
?>
<dl class="detail ac-dates-dl">
  <div><dt>Загружен</dt><dd><?= e($dt($p['created_at'])) ?> · <?= e($origin['label']) ?><?php if ($origin['who'] !== ''): ?><small><?= e($origin['who']) ?></small><?php endif; ?></dd></div>
  <div><dt>Изменён</dt><dd><?php if ($last): ?><?= e($dt($last['at'])) ?><small><?= e($last['who']) ?></small>
    <?php else: ?><span class="muted">изменений не было</span><?php endif; ?>
    <?php if ($seo): ?><small>SEO: автоисправление <?= e($dt($seo['at'])) ?> — title и description, дату изменения не сдвигает</small><?php endif; ?></dd></div>
</dl>
<?php if (!$full): ?>
  <h3>История изменений</h3>
  <?= $view->partial('admin/products/events', ['events' => $hist['items']]) ?>
  <?php if ($hist['more']): ?><p class="ac-small ac-allhist"><a href="/admin/products/<?= (int) $p['id'] ?>/history/">Вся история →</a></p><?php endif; ?>
<?php endif; ?>
