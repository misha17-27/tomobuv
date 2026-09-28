<?php
/**
 * Превью в поиске Google: адрес, заголовок, описание + счётчики длины с точками .seo-dot
 * (Задан / Длина / По шаблону / Нет — те же правила, что на экране «SEO»). Всё — на классах admin.css.
 * Живое обновление из полей формы делает public/assets/admin/seo.js — партиал подключает его сам (один раз).
 *
 * Все параметры — в одном массиве $serp (чтобы не пересекаться с переменными экрана):
 *   <?= $view->partial('admin/partials/serp', ['serp' => ['type' => 'product', 'row' => $p]]) ?>
 *   <?= $view->partial('admin/partials/serp', ['serp' => ['type' => 'page', 'row' => $page]]) ?>
 *   <?= $view->partial('admin/partials/serp', ['serp' => ['path' => '/x/', 'title' => '', 'titleAuto' => '…', 'desc' => '', 'descAuto' => '…']]) ?>
 *
 *  type   — product | category | page | brand | blog | home: своё значение и «по шаблону» считает
 *           App\Services\SeoAudit::meta() так же, как витрина (row — строка из базы; нужны url, name, meta_*,
 *           у товара ещё seo_name, price, sku, category_id; у категории seo_name). Для нового товара без url — адрес «…».
 *  fields — имена полей формы, из которых брать значения на лету: ['title' => …, 'desc' => …, 'url' => …].
 *           С type по умолчанию title: meta_title (у страницы и бренда — title), desc: meta_description, url: url;
 *           в ручном режиме (без type) — только переданные. Поля ищутся в той же <form>, что и превью
 *           (если превью вне формы — во всём документе); '' — не следить за этим полем.
 *  live   — false: статичное превью (без слежения за полями), например в списках.
 * @var App\Core\View $view @var array $serp
 */
use App\Services\SeoAudit;

$o = isset($serp) && is_array($serp) ? $serp : [];
$type = (string) ($o['type'] ?? '');
if ($type !== '') {
    $m = SeoAudit::meta($type, (array) ($o['row'] ?? []));
} else {
    $tOwn = trim((string) ($o['title'] ?? ''));
    $tAuto = trim((string) ($o['titleAuto'] ?? ''));
    $dOwn = trim((string) ($o['desc'] ?? ''));
    $dAuto = trim((string) ($o['descAuto'] ?? ''));
    $m = ['path' => (string) ($o['path'] ?? ''), 'title_own' => $tOwn, 'title_auto' => $tAuto, 'desc_own' => $dOwn, 'desc_auto' => $dAuto,
        'title_state' => SeoAudit::state($tOwn, 'title', $tAuto), 'desc_state' => SeoAudit::state($dOwn, 'description', $dAuto)];
}

// поля формы по умолчанию (только с type; в ручном режиме — если переданы fields)
$fields = $type !== '' && $type !== 'home'
    ? ['title' => in_array($type, ['page', 'brand'], true) ? 'title' : 'meta_title', 'desc' => 'meta_description', 'url' => 'url']
    : ['title' => '', 'desc' => '', 'url' => ''];
if (isset($o['fields']) && is_array($o['fields'])) $fields = array_merge($fields, $o['fields']);
if ($type === 'home' || ($o['live'] ?? true) === false) $fields = ['title' => '', 'desc' => '', 'url' => ''];
$prefix = ['product' => '/product/', 'category' => '/category/', 'brand' => '/brand/', 'blog' => '/blog/', 'page' => '/'][$type] ?? '';

$host = (string) (parse_url(url('/'), PHP_URL_HOST) ?: 'tomobuv.com.ua');
// «tomobuv.com.ua › product › krossovki-…» — как Google показывает адрес
$crumbs = static function (string $path) use ($host): string {
    $parts = array_values(array_filter(explode('/', rawurldecode(strtok($path, '?') ?: '')), 'strlen'));
    return $host . ($parts ? ' › ' . implode(' › ', $parts) : '');
};
// обрезка как в выдаче: по слову, с « …» (seo.js делает то же самое)
$clip = static function (string $s, int $n): string {
    $s = trim((string) preg_replace('/\s+/u', ' ', $s));
    if (mb_strlen($s) <= $n) return $s;
    $cut = mb_substr($s, 0, $n - 1);
    $sp = mb_strrpos($cut, ' ');
    return rtrim($sp !== false && $sp > $n / 2 ? mb_substr($cut, 0, $sp) : $cut) . ' …';
};
$lim = ['title' => [SeoAudit::TITLE_MIN, SeoAudit::TITLE_MAX], 'desc' => [SeoAudit::DESC_MIN, SeoAudit::DESC_MAX]];
$shown = ['title' => $m['title_own'] !== '' ? $m['title_own'] : $m['title_auto'], 'desc' => $m['desc_own'] !== '' ? $m['desc_own'] : $m['desc_auto']];
$states = ['title' => $m['title_state'], 'desc' => $m['desc_state']];
$note = static function (string $st, int $len, string $k) use ($lim): string {
    return match ($st) {
        'ok'    => 'задан',
        'warn'  => $len < $lim[$k][0] ? 'короче ' . $lim[$k][0] : 'длиннее ' . $lim[$k][1],
        'auto'  => 'по шаблону' . ($len < $lim[$k][0] ? ', короче ' . $lim[$k][0] : ($len > $lim[$k][1] ? ', длиннее ' . $lim[$k][1] : '')),
        default => 'нет: пусто и шаблона нет',
    };
};
$live = array_filter($fields, 'strlen');
?>
<div class="serp" data-serp-box="<?= e(json_encode($live + ['host' => $host, 'prefix' => $prefix], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>"
     data-title-auto="<?= e($m['title_auto']) ?>" data-desc-auto="<?= e($m['desc_auto']) ?>" role="group" aria-label="Как страница выглядит в поиске Google">
  <div class="serp-url" data-serp-url><?= e($m['path'] !== '' ? $crumbs($m['path']) : $host . ' › …') ?></div>
  <div class="serp-title" data-serp-title><?= e($clip($shown['title'], $lim['title'][1]) ?: '(нет заголовка)') ?></div>
  <div class="serp-desc" data-serp-desc><?= $shown['desc'] !== '' ? e($clip($shown['desc'], $lim['desc'][1])) : '<span class="muted">Описания нет — Google возьмёт фрагмент текста со страницы</span>' ?></div>
</div>
<p class="hint" data-serp-lens>
  <?php foreach (['title' => 'Title', 'desc' => 'Description'] as $k => $label): $len = mb_strlen($shown[$k]); ?>
    <span class="nowrap" data-serp-info="<?= $k ?>"><i class="seo-dot <?= e($states[$k]) ?>" data-serp-dot title="<?= e(SeoAudit::STATES[$states[$k]] ?? '') ?>"></i>
      <?= $label ?>: <b data-serp-len><?= $len ?></b> симв. <span class="muted">(<?= $lim[$k][0] ?>–<?= $lim[$k][1] ?>)</span> — <span data-serp-note><?= e($note($states[$k], $len, $k)) ?></span></span><?= $k === 'title' ? ' &nbsp;·&nbsp; ' : '' ?>
  <?php endforeach; ?>
</p>
<?php if (!defined('ADMIN_SERP_JS')): define('ADMIN_SERP_JS', true); ?><script src="<?= e(asset('admin/seo.js')) ?>" defer></script>
<?php endif; ?>
