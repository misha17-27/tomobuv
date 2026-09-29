<?php
/**
 * Макет админки (в стиле админки ARG FLEX).
 * @var string $content @var string $title @var ?array $user @var ?string $flash @var ?string $flashError
 * Необязательно: $styles (['admin/import.css']), $scripts (['admin/import.js']), $actions (HTML кнопок в шапке), $back (['url','текст'])
 */
use App\Core\App;
use App\Core\Auth;
use App\Core\Cache;
use App\Core\Csrf;

$here = current_path();
$isOn = static function (string $prefix, string $not = '') use ($here): bool {
    if ($prefix === '/admin/') return $here === '/admin/';
    return str_starts_with($here, $prefix) && ($not === '' || !str_starts_with($here, $not));
};
// счётчики в меню (индексы status; кэш на минуту). Новые заказы — без кэша и по той же логике, что плитка на главной
// и список по её ссылке (OrdersController::newCounts: один запрос по индексу status)
$tally = Cache::remember('admin.tally', 60, static function () {
    $db = App::db();
    $t = ['requests' => 0, 'reviews' => 0];
    try {
        $t['requests'] = (int) $db->value("SELECT COUNT(*) FROM requests WHERE status = 'new'");
        $t['reviews'] = (int) $db->value("SELECT COUNT(*) FROM product_reviews WHERE status = 'moderation'")
            + (int) $db->value('SELECT COUNT(*) FROM store_reviews WHERE status = 0 AND created_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)');
    } catch (\Throwable $e) {
    }
    return $t;
});
try {
    $tally['orders'] = \App\Controllers\Admin\OrdersController::newCounts()['fresh'];
} catch (\Throwable $e) {
    $tally['orders'] = 0;
}
$ic = [
    'dashboard'  => '<path d="M3 12l9-8 9 8"/><path d="M5 10v10h14V10"/>',
    'orders'     => '<path d="M4 5h2l2.2 10.4a2 2 0 0 0 2 1.6h6.9a2 2 0 0 0 2-1.55L21 8H6.5"/><circle cx="10" cy="20" r="1.3"/><circle cx="18" cy="20" r="1.3"/>',
    'requests'   => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 7l9 6 9-6"/>',
    'customers'  => '<circle cx="9" cy="8" r="3.4"/><path d="M2.5 20c0-3.4 2.9-5.5 6.5-5.5s6.5 2.1 6.5 5.5"/><path d="M16 5.2a3.4 3.4 0 0 1 0 5.6"/><path d="M18 14.8c2.1.7 3.5 2.4 3.5 5.2"/>',
    'reports'    => '<path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/>',
    'products'   => '<path d="M3 7l9-4 9 4-9 4z"/><path d="M3 7v10l9 4 9-4V7"/>',
    'categories' => '<path d="M4 6h16M4 12h16M4 18h10"/>',
    'brands'     => '<path d="M3 12V4h8l10 10-8 8z"/><circle cx="7.5" cy="8.5" r="1.5"/>',
    'features'   => '<path d="M4 7h6M4 12h10M4 17h7"/><circle cx="17" cy="7" r="2"/><circle cx="19" cy="17" r="2"/>',
    'import'     => '<path d="M12 3v12M7 10l5 5 5-5"/><path d="M4 17v3h16v-3"/>',
    'suppliers'  => '<path d="M3 6h11v10H3z"/><path d="M14 9h4l3 3v4h-7"/><circle cx="7" cy="17.5" r="1.6"/><circle cx="17" cy="17.5" r="1.6"/>',
    'coupons'    => '<path d="M4 9V7a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v2a2 2 0 0 0 0 6v2a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2v-2a2 2 0 0 0 0-6z"/><path d="M14 8.5l-4 7"/>',
    'reviews'    => '<path d="M12 3.5l2.6 5.4 5.9.8-4.3 4.1 1.1 5.8-5.3-2.8-5.3 2.8 1.1-5.8L3.5 9.7l5.9-.8z"/>',
    'pages'      => '<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5"/>',
    'blog'       => '<path d="M5 4h14v16H5z"/><path d="M8 8h8M8 12h8M8 16h5"/>',
    'banners'    => '<rect x="3" y="5" width="18" height="10" rx="2"/><path d="M7 19h10"/>',
    'media'      => '<rect x="3" y="5" width="18" height="14" rx="2"/><circle cx="9" cy="10" r="1.6"/><path d="M21 16l-5-5-6 6"/>',
    'seo'        => '<circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/>',
    'mail'       => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 7l9 6 9-6"/>',
    'redirects'  => '<path d="M4 12h13M13 6l6 6-6 6"/>',
    'whatsapp'   => '<path d="M12 3a9 9 0 0 0-7.8 13.5L3 21l4.6-1.2A9 9 0 1 0 12 3z"/><path d="M9 8.5c.3 2.7 2.8 5.2 5.5 5.5l1-1.4-1.8-1-1 .8a4.6 4.6 0 0 1-2.1-2.1l.8-1-1-1.8z"/>',
    'settings'   => '<circle cx="12" cy="12" r="3"/><path d="M19 12a7 7 0 0 0-.1-1l2-1.5-2-3.4-2.3 1a7 7 0 0 0-1.7-1L14.5 3h-4l-.4 2.6a7 7 0 0 0-1.7 1l-2.3-1-2 3.4L6 11a7 7 0 0 0 0 2l-2 1.5 2 3.4 2.3-1a7 7 0 0 0 1.7 1l.4 2.6h4l.4-2.6a7 7 0 0 0 1.7-1l2.3 1 2-3.4-2-1.5c.06-.33.1-.66.1-1z"/>',
    'security'   => '<path d="M12 3l7.5 3v5.2c0 4.6-3.1 8.3-7.5 9.8-4.4-1.5-7.5-5.2-7.5-9.8V6z"/><path d="M9 12l2 2 4-4"/>',
    'users'      => '<circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 3.6-6.5 8-6.5s8 2.5 8 6.5"/>',
    'status'     => '<path d="M3 12h4l3-7 4 14 3-7h4"/>',
];
$groups = [
    'Обзор' => [
        ['/admin/', 'dashboard', 'Главная'],
        ['/admin/orders/', 'orders', 'Заказы', 'orders'],
        ['/admin/requests/', 'requests', 'Заявки', 'requests'],
        ['/admin/customers/', 'customers', 'Клиенты'],
        ['/admin/reports/', 'reports', 'Отчёты'],
    ],
    'Каталог' => [
        ['/admin/products/', 'products', 'Товары'],
        ['/admin/categories/', 'categories', 'Категории'],
        ['/admin/brands/', 'brands', 'Бренды'],
        ['/admin/features/', 'features', 'Характеристики'],
        ['/admin/import/', 'import', 'Импорт / экспорт'],
        ['/admin/suppliers/', 'suppliers', 'Поставщики'],
        ['/admin/coupons/', 'coupons', 'Промокоды'],
        ['/admin/reviews/', 'reviews', 'Отзывы', 'reviews'],
    ],
    'Контент' => [
        ['/admin/pages/', 'pages', 'Страницы'],
        ['/admin/blog/', 'blog', 'Блог'],
        ['/admin/banners/', 'banners', 'Баннеры'],
        ['/admin/media/', 'media', 'Изображения'],
    ],
    'Настройки' => [
        ['/admin/seo/', 'seo', 'SEO'],
        ['/admin/redirects/', 'redirects', 'Редиректы'],
        ['/admin/mail/', 'mail', 'Почта (SMTP)'],
        ['/admin/whatsapp/', 'whatsapp', 'WhatsApp'],
        ['/admin/settings/', 'settings', 'Настройки'],
        ['/admin/security/', 'security', 'Безопасность'],
        ['/admin/users/', 'users', 'Сотрудники'],
        ['/admin/status/', 'status', 'Состояние системы'],
    ],
];
// «Безопасность», «Сотрудники» и «Поставщики» (ключ API, автозагрузка) — только администратору (менеджеру там 403)
if (!Auth::isAdmin()) {
    $groups['Каталог'] = array_values(array_filter($groups['Каталог'], static fn($l) => $l[0] !== '/admin/suppliers/'));
    $groups['Настройки'] = array_values(array_filter($groups['Настройки'], static fn($l) => !in_array($l[0], ['/admin/security/', '/admin/users/'], true)));
}
?><!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<meta name="csrf-token" content="<?= e(Csrf::sessionToken()) ?>">
<title><?= e($title ?? 'Админка') ?> — Tomobuv</title>
<link rel="icon" href="/favicon.ico">
<link rel="stylesheet" href="<?= asset('admin/admin.css') ?>">
<?php foreach ($styles ?? [] as $css): ?><link rel="stylesheet" href="<?= asset($css) ?>">
<?php endforeach; ?>
<script src="<?= asset('admin/admin.js') ?>" defer></script>
<?php foreach ($scripts ?? [] as $js): ?><script src="<?= asset($js) ?>" defer></script>
<?php endforeach; ?>
</head>
<body>
<?= $view->partial('front/partials/sprite') ?>
<div class="shell">
  <aside class="side" id="adm-side">
    <a class="brand" href="/admin/"><img src="/assets/img/logo.png" alt="Tomobuv" width="108" height="28"><span>Админка</span></a>
    <button class="burger" type="button" aria-label="Меню" onclick="this.parentNode.classList.toggle('open')"><?= icon('menu') ?></button>
    <nav>
      <?php foreach ($groups as $label => $links): ?>
        <div class="navgroup"><?= e($label) ?></div>
        <?php foreach ($links as $l): [$href, $icon, $text] = $l; $t = isset($l[3]) ? (int) ($tally[$l[3]] ?? 0) : 0; ?>
          <a href="<?= e($href) ?>" class="<?= $isOn($href) ? 'on' : '' ?>">
            <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><?= $ic[$icon] ?></svg>
            <?= e($text) ?><?php if ($t): ?><i class="tally"><?= $t > 999 ? '999+' : $t ?></i><?php endif; ?>
          </a>
        <?php endforeach; ?>
      <?php endforeach; ?>
    </nav>
    <div class="side-foot">
      <a href="/" target="_blank" rel="noopener">Открыть сайт ↗</a>
      <a href="/admin/account/"><?= e($user['name'] ?? $user['email'] ?? '') ?><?= Auth::isAdmin() ? '' : ' · менеджер' ?></a>
      <a href="/admin/logout/?t=<?= e(Auth::logoutToken(true)) ?>" class="out">Выйти</a><?php /* выход — только с токеном (Admin\AuthController::logout) */ ?>
    </div>
  </aside>

  <main class="main">
    <?php if (!empty($back)): ?><p class="back"><a href="<?= e($back[0]) ?>">← <?= e($back[1]) ?></a></p><?php endif; ?>
    <header class="top">
      <h1><?= e($title ?? 'Админка') ?></h1>
      <div class="top-right">
        <?= $actions ?? '' ?>
        <div class="top-acts">
          <a class="top-btn" href="/" target="_blank" rel="noopener"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M14 4h6v6"/><path d="M20 4l-9 9"/><path d="M18 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5"/></svg><span>Открыть сайт</span></a>
          <a class="top-btn out" href="/admin/logout/?t=<?= e(Auth::logoutToken(true)) ?>"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M9 20H5a1 1 0 0 1-1-1V5a1 1 0 0 1 1-1h4"/><path d="M16 16l4-4-4-4"/><path d="M20 12H9"/></svg><span>Выйти</span></a>
        </div>
      </div>
    </header>
    <?php if (!empty($flash)): ?><div class="flash"><?= e($flash) ?></div><?php endif; ?>
    <?php if (!empty($flashError)): ?><div class="flash bad"><?= e($flashError) ?></div><?php endif; ?>
    <?= $content ?>
  </main>
</div>
</body>
</html>
