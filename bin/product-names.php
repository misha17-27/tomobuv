<?php
/**
 * Автоназвание товаров, заведённых одним кодом: «60189A» → «Зимняя обувь Tom.m 60189A» (UA: «Зимове взуття Tom.m 60189A»).
 * Правило — App\Services\ProductName (docs/ARCHITECTURE.md → «Автоназвание товара»).
 *
 *   php bin/product-names.php --dry-run   — показать «было → стало» и число, ничего не менять
 *   php bin/product-names.php             — применить
 *
 * Меняются name, name_uk (если пустое или тоже код) и sku (если пуст — туда код: на сайте «Артикул: 60189A»).
 * Адрес товара (url) и свои SEO-поля товара не меняются. Повторный запуск ничего не меняет.
 * Перенос с Webasyst (bin/import-webasyst.php) делает это сам после шага products.
 */
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';

use App\Services\CatalogIndexer;
use App\Services\ProductName;

$dry = in_array('--dry-run', $argv, true);
$t = microtime(true);

$list = ProductName::scan(false);
$ids = array_keys($list);

// свои SEO-поля товара (шаблоны SEO подставляют новое имя сами, а свои тексты не трогаем — только перечисляем)
$seoCols = ['seo_name', 'h1', 'meta_title', 'meta_description', 'meta_keywords', 'seo_name_uk', 'h1_uk', 'meta_title_uk', 'meta_description_uk', 'meta_keywords_uk'];
$ownSeo = [];
foreach ($list as $id => $x) {
    $code = (string) ProductName::build($x['old'])[2];
    foreach ($seoCols as $c) {
        $v = trim((string) ($x['old'][$c] ?? ''));
        if ($v !== '') $ownSeo[$id][] = $c . (mb_stripos($v, $code) !== false ? ' (с кодом «' . $code . '»)' : '') . ': ' . mb_substr($v, 0, 80);
    }
}

foreach ($list as $id => $x) {
    $o = $x['old'];
    $s = $x['set'];
    $line = sprintf('%8d  %s → %s', $id, $o['name'], $s['name'] ?? $o['name'] . ' (без изменений)');
    if (isset($s['name_uk'])) $line .= ' | UA: ' . $s['name_uk'];
    if (isset($s['sku'])) $line .= ' | артикул: ' . $s['sku'];
    echo $line, "\n";
}
$renamed = count(array_filter($list, static fn($x) => isset($x['set']['name'])));
if (ProductName::$bareLeft) {
    echo "\nНазвание — код, но дополнить нечем (нет категории, бренда нет или он уже в названии; артикул уже есть или код не одно слово с цифрой): "
        . count(ProductName::$bareLeft) . "\n";
    foreach (ProductName::$bareLeft as $id => $name) echo "  $id: $name\n";
}
if ($ownSeo) {
    echo "\nСвои SEO-поля у переименованных товаров (не меняются, проверьте вручную):\n";
    foreach ($ownSeo as $id => $cols) echo "  $id: " . implode('; ', $cols) . "\n";
} elseif ($list) {
    echo "\nСвоих SEO-полей (title, h1, description, keywords, seo_name — RU и UA) у этих товаров нет: страницы берут шаблоны SEO с новым названием.\n";
}

if ($dry) {
    printf("\nБудет изменено товаров: %d (названий: %d). Проверка: %.1f с. Запуск без --dry-run — применить.\n", count($list), $renamed, microtime(true) - $t);
    exit(0);
}
if (!$list) {
    printf("Изменений нет (%.1f с).\n", microtime(true) - $t);
    exit(0);
}

$snap = CatalogIndexer::snapshot($ids);
$done = ProductName::scan(true);          // заново: между проверкой и записью товар могли поправить в админке
CatalogIndexer::products(array_keys($done), $snap);      // catalog_index.name — сортировка по названию; сбрасывает кэш
printf("\nИзменено товаров: %d (названий: %d) за %.1f с. Индекс каталога обновлён, кэш сброшен.\n",
    count($done), count(array_filter($done, static fn($x) => isset($x['set']['name']))), microtime(true) - $t);
