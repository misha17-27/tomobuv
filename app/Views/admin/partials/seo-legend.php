<?php
/**
 * Легенда точек Title/Description под списком (как в SEO-обзоре) и ссылка на обзор с вкладкой этой группы
 * (фильтр «Длина не в норме» → чип «Неверная длина» обзора).
 * @var string $seoType product|blog|page|category @var string $seoFilter текущий ?seo= списка
 */
use App\Services\SeoAudit;

$seoLink = '/admin/seo/?' . http_build_query(array_filter(['group' => SeoAudit::LIST_GROUPS[$seoType] ?? '',
    'show' => ($seoFilter ?? '') === 'len' ? 'warn' : ''])) . '#list';
?>
<p class="hint seo-legend">Title и Description — что покажет поисковик, как в SEO-обзоре:
  <span class="nowrap"><i class="seo-dot ok"></i> свой,</span> длина в норме;
  <span class="nowrap"><i class="seo-dot warn"></i> свой,</span> но короче или длиннее нормы (title <?= SeoAudit::TITLE_MIN ?>–<?= SeoAudit::TITLE_MAX ?>, description <?= SeoAudit::DESC_MIN ?>–<?= SeoAudit::DESC_MAX ?> символов);
  <span class="nowrap"><i class="seo-dot auto"></i> своего нет</span> — сайт строит по SEO-шаблону, это нормально;
  <span class="nowrap"><i class="seo-dot none"></i> нет</span> — пусто и на сайте.
  Наведите на точку — текст и состояние украинской версии; нажмите — откроется блок SEO. <a href="<?= e($seoLink) ?>">SEO-обзор →</a></p>
