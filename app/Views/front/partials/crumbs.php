<?php
/**
 * Хлебные крошки + разметка BreadcrumbList.
 * @var array $items [['name' => 'Детская обувь', 'url' => '/category/…/'], …, ['name' => 'Текущая']]
 */
$all = array_merge([['name' => t('Главная'), 'url' => '/']], $items);
$ld = ['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => []];
foreach ($all as $i => $c) {
    if (!empty($c['url'])) $ld['itemListElement'][] = ['@type' => 'ListItem', 'position' => $i + 1, 'name' => $c['name'], 'item' => url(\App\Core\Lang::path($c['url']))];
}
?>
<nav aria-label="<?= e(t('Хлебные крошки')) ?>"><ol class="crumbs">
<?php foreach ($all as $i => $c): ?>
  <li><?php if ($i < count($all) - 1 && !empty($c['url'])): ?><a href="<?= e($c['url']) ?>"><?= e($c['name']) ?></a><?php else: ?><span aria-current="page"><?= e($c['name']) ?></span><?php endif; ?></li>
<?php endforeach; ?>
</ol></nav>
<script type="application/ld+json"><?= json_encode($ld, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?></script>
