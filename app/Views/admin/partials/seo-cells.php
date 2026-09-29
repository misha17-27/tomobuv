<?php
/**
 * Ячейки «Title» и «Description» строки списка админки (товары, статьи, страницы, категории).
 * Точка .seo-dot — состояние как в SEO-обзоре, ссылка на блок SEO редактора; подсказка — своё/по шаблону/нет,
 * длина, сам текст (до 120 символов) и что на украинской версии /ua/. Подсказка (title) — она же доступное имя ссылки.
 * @var ?array $seoCell ячейки строки из SeoAudit::listCells() @var string $seoEdit адрес редактора с #h-seo
 */
use App\Services\SeoAudit;

foreach (['title' => 'Title', 'desc' => 'Description'] as $f => $label):
    $c = $seoCell[$f] ?? null;
    if (!$c): ?><td class="seo-col"><span class="muted">—</span></td><?php continue; endif;
    [$min, $max] = $f === 'title' ? [SeoAudit::TITLE_MIN, SeoAudit::TITLE_MAX] : [SeoAudit::DESC_MIN, SeoAudit::DESC_MAX];
    $len = number_format($c['len'], 0, '', ' ') . ' симв.';
    $head = $label . ': ' . match ($c['state']) {
        'ok'    => 'свой, ' . $len,
        // «Длина» бывает и у результата шаблона (SeoAudit::state): своего нет — «по шаблону», а не «свой»
        'warn'  => ($c['own'] ? 'свой, ' : 'по шаблону, ') . $len . ' — ' . ($c['len'] < $min ? 'короче ' . $min : 'длиннее ' . $max),
        'auto'  => 'по шаблону, ' . $len,
        default => 'нет — пусто и шаблона нет, на сайте тега не будет',
    };
    $uk = 'UA: ' . mb_strtolower(SeoAudit::UK_STATES[$c['uk']] ?? '')
        . ($c['uk'] === 'own' ? ', ' . number_format($c['uk_len'], 0, '', ' ') . ' симв.'
            . ($c['uk_len'] < $min || $c['uk_len'] > $max ? ' — ' . ($c['uk_len'] < $min ? 'короче ' . $min : 'длиннее ' . $max) : '') : '');
    // текст — как в <title> сайта: без strip_tags из str_limit («<b>» в мета-теге выводится буквами и входит в длину)
    $text = trim((string) preg_replace('/\s+/u', ' ', $c['text']));
    if (mb_strlen($text) > 120) $text = rtrim(mb_substr($text, 0, 119)) . '…';
    $tip = $head . ($text !== '' ? "\n" . $text : '') . "\n" . $uk; ?>
<td class="seo-col"><a class="seo-dot <?= e($c['state']) ?>" href="<?= e($seoEdit) ?>" title="<?= e($tip) ?>"></a></td>
<?php endforeach;
