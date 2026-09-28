<?php
/**
 * Все адреса сайта. Адреса витрины повторяют старый сайт на Webasyst один в один (SEO).
 * @var App\Core\Router $router
 */
declare(strict_types=1);

use App\Controllers\Front;

// ---------------------------------------------------------------- витрина
$router->get('/', [Front\HomeController::class, 'index']);

$router->get('/category/{url}/', [Front\CategoryController::class, 'show']);
$router->get('/product/{url}/', [Front\ProductController::class, 'show']);
$router->get('/product/{url}/reviews/', [Front\ProductController::class, 'reviews']);
$router->post('/product/{url}/reviews/', [Front\ProductController::class, 'addReview']);
// HTML-карточки по списку id (?ids=1,2,3) — для блоков «Вы смотрели» и т.п., кэшируется
$router->get('/products/cards/', [Front\ProductController::class, 'cards']);

$router->get('/brand/', [Front\BrandController::class, 'index']);
$router->get('/brand/{name}/', [Front\BrandController::class, 'show']);

// Поиск; ?_balance_type=favorites|viewed — избранное и просмотренные (адреса старой темы)
$router->get('/search/', [Front\SearchController::class, 'index']);
$router->get('/search/suggest/', [Front\SearchController::class, 'suggest']);

$router->get('/compare/', [Front\CompareController::class, 'index']);
$router->get('/compare/{ids}/', [Front\CompareController::class, 'index']);

// Корзина и оформление (/order/ — адрес оформления на старом сайте)
$router->get('/cart/', [Front\CartController::class, 'index']);
$router->post('/cart/', [Front\CheckoutController::class, 'index']);
$router->get('/cart/json/', [Front\CartController::class, 'json']);
$router->post('/cart/add/', [Front\CartController::class, 'add']);
$router->post('/cart/update/', [Front\CartController::class, 'update']);
$router->post('/cart/remove/', [Front\CartController::class, 'remove']);
$router->post('/cart/clear/', [Front\CartController::class, 'clear']);
$router->any('/order/', [Front\CheckoutController::class, 'index']);
$router->get('/order/success/', [Front\CheckoutController::class, 'success']);
$router->get('/checkout/', [Front\CheckoutController::class, 'legacy']);
$router->post('/quickorder/', [Front\CheckoutController::class, 'quick']);

// Заявки: обратный звонок, подписка, форма контактов
$router->post('/request/{type}/', [Front\RequestController::class, 'store']);

// Кабинет покупателя
$router->any('/login/', [Front\AuthController::class, 'login']);
$router->any('/signup/', [Front\AuthController::class, 'signup']);
$router->any('/forgotpassword/', [Front\AuthController::class, 'forgot']);
$router->any('/forgotpassword/reset/', [Front\AuthController::class, 'reset']);
$router->any('/logout/', [Front\AuthController::class, 'logout']);          // выход только с токеном: POST или ?t=
$router->get('/my/', [Front\AccountController::class, 'index']);
$router->get('/my/orders/', [Front\AccountController::class, 'orders']);
$router->get('/my/order/{id}/', [Front\AccountController::class, 'order']);
$router->any('/my/profile/', [Front\AccountController::class, 'profile']);

// Блог (статьи)
$router->get('/blog/', [Front\BlogController::class, 'index']);
$router->get('/blog/{url}/', [Front\BlogController::class, 'post']);

// Отзывы о магазине
$router->any('/reviews/', [Front\ReviewsController::class, 'index']);

// SEO-служебные
$router->get('/robots.txt', [Front\SitemapController::class, 'robots']);
$router->get('/sitemap.xml', [Front\SitemapController::class, 'index']);
$router->get('/sitemap-{name}.xml', [Front\SitemapController::class, 'part']);

// Миниатюры фото товаров (создаются при первом обращении, дальше отдаёт веб-сервер)
$router->get('/wa-data/public/shop/products/{a}/{b}/{pid}/images/{iid}/{file}', [Front\ImageController::class, 'thumb']);

// ---------------------------------------------------------------- админка
require APP . '/routes_admin.php';

// ---------------------------------------------------------------- инфо-страницы (последними)
$router->get('/{path*}', [Front\PageController::class, 'show']);
