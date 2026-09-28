<?php
/**
 * Общий макет витрины (дизайн 1).
 * @var string $content  HTML страницы
 * @var App\Core\Seo $seo мета-теги
 * @var View $view
 * Необязательно: $bodyClass, $showAlpha (строка «Бренды: A B C…»), $activeCat (id корневой категории)
 */
use App\Core\Lang;
use App\Core\Settings;
use App\Services\Catalog;

$seo ??= App\Core\Seo::make((string) Settings::get('site_title', 'Tomobuv'));
$rates = Settings::json('currencies', ['UAH' => 1]);
$cfg = [
    'rates'   => ['UAH' => 1, 'USD' => (float) ($rates['USD'] ?? 41.5), 'EUR' => (float) ($rates['EUR'] ?? 48.5)],
    'freeBoxes' => (int) Settings::get('free_shipping_boxes', 20),
    'lang'      => Lang::current(),
    'prefix'    => Lang::prefix(),
    'i18n'      => Lang::isUk() ? array_filter(Lang::dict(), static fn($k) => str_starts_with((string) $k, 'js:'), ARRAY_FILTER_USE_KEY) : new stdClass(),
];
$canonical = Lang::absUrl($seo->canonical);
$indexable = !$seo->robots || !str_contains($seo->robots, 'noindex');
// hreflang — от canonical (?page=N, utm_* и т.п. отбрасываются): языковые ссылки ведут только на канонические адреса
$altPath = null;
if ($seo->canonical && str_starts_with($seo->canonical, ($siteBase = rtrim((string) App\Core\App::config('base_url', ''), '/')) . '/')) {
    $altPath = substr($seo->canonical, strlen($siteBase));
    if (str_starts_with($altPath, '/ua/')) $altPath = substr($altPath, 3);
}
?><!DOCTYPE html>
<html lang="<?= Lang::isUk() ? 'uk' : 'ru' ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($seo->title) ?></title>
<?php if ($seo->description !== ''): ?><meta name="description" content="<?= e($seo->description) ?>">
<?php endif; ?>
<?php if ($seo->keywords !== ''): ?><meta name="keywords" content="<?= e($seo->keywords) ?>">
<?php endif; ?>
<?php if ($seo->robots): ?><meta name="robots" content="<?= e($seo->robots) ?>">
<?php endif; ?>
<?php if ($canonical): ?><link rel="canonical" href="<?= e($canonical) ?>">
<?php endif; ?>
<?php if ($indexable): foreach (Lang::LANGS as $lg => $px): ?><link rel="alternate" hreflang="<?= Lang::HREFLANG[$lg] ?>" href="<?= e(Lang::alternate($lg, $altPath)) ?>">
<?php endforeach; ?><link rel="alternate" hreflang="x-default" href="<?= e(Lang::alternate('ru', $altPath)) ?>">
<?php endif; ?>
<?php if ($seo->prev): ?><link rel="prev" href="<?= e($seo->prev) ?>">
<?php endif; ?>
<?php if ($seo->next): ?><link rel="next" href="<?= e($seo->next) ?>">
<?php endif; ?>
<meta property="og:type" content="<?= e($seo->ogType) ?>">
<meta property="og:title" content="<?= e($seo->ogTitle()) ?>">
<?php if ($seo->ogDescription() !== ''): ?><meta property="og:description" content="<?= e($seo->ogDescription()) ?>">
<?php endif; ?>
<meta property="og:url" content="<?= e($canonical ?? Lang::alternate(Lang::current())) ?>">
<meta property="og:locale" content="<?= Lang::isUk() ? 'uk_UA' : 'ru_UA' ?>">
<?php if ($seo->ogImage): ?><meta property="og:image" content="<?= e(str_starts_with($seo->ogImage, 'http') ? $seo->ogImage : url($seo->ogImage)) ?>">
<?php endif; ?>
<link rel="icon" href="/favicon.ico">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<?php // шрифт не блокирует первую отрисовку (на мобильном 3G/4G — минус ~1 с): до загрузки — «Manrope Fallback» из app.css
$fontCss = 'https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap'; ?>
<link rel="preload" as="style" href="<?= e($fontCss) ?>" onload="this.onload=null;this.rel='stylesheet'">
<noscript><link rel="stylesheet" href="<?= e($fontCss) ?>"></noscript>
<?php $imgRemote = parse_url((string) App\Core\App::config('images.remote_base', ''));
if (!empty($imgRemote['host'])): // фото товаров с другого сервера (images.remote_base) — соединение заранее ?>
<link rel="preconnect" href="<?= e(($imgRemote['scheme'] ?? 'https') . '://' . $imgRemote['host'] . (isset($imgRemote['port']) ? ':' . $imgRemote['port'] : '')) ?>">
<?php endif; ?>
<link rel="stylesheet" href="<?= asset('css/app.css') ?>">
<?php foreach ($seo->jsonLd as $ld): ?>
<script type="application/ld+json"><?= json_encode($ld, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?></script>
<?php endforeach; ?>
<script>window.TOM=<?= json_encode($cfg, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;</script>
<script src="<?= asset('js/app.js') ?>" defer></script>
<?php foreach ($scripts ?? [] as $js): ?><script src="<?= asset($js) ?>" defer></script>
<?php endforeach; ?>
</head>
<body class="<?= e($bodyClass ?? '') ?>">
<?= $view->partial('front/partials/sprite') ?>
<?= $view->partial('front/partials/header', ['showAlpha' => $showAlpha ?? false]) ?>
<main id="main">
<?= $content ?>
</main>
<?= $view->partial('front/partials/footer') ?>
<?= $view->partial('front/partials/overlays') ?>
</body>
</html>
