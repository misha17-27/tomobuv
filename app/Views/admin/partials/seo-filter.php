<?php
/**
 * Чипы фильтра «SEO» над списком (статьи, страницы, категории): ?seo=notitle|nodesc|len, остальные параметры сохраняются.
 * @var string $seoFilter текущий фильтр @var array $seoCounts ['' => всего, 'notitle' => …] — под остальные фильтры списка
 */
use App\Core\Request;
use App\Services\SeoAudit;
?>
<div class="filter-bar seo-chips" role="group" aria-label="Фильтр по SEO">
  <span class="muted">SEO:</span>
  <?php foreach (SeoAudit::LIST_FILTERS as $k => $label): ?>
    <a class="chip<?= $seoFilter === $k ? ' on' : '' ?>" href="<?= e(Request::withQuery(['seo' => $k, 'page' => null])) ?>"<?= $seoFilter === $k ? ' aria-current="true"' : '' ?>><?= e($label) ?> <b><?= number_format((int) ($seoCounts[$k] ?? 0), 0, '', ' ') ?></b></a>
  <?php endforeach; ?>
</div>
