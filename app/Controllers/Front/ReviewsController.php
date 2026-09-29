<?php
declare(strict_types=1);

namespace App\Controllers\Front;

use App\Core\App;
use App\Core\Cache;
use App\Core\Csrf;
use App\Core\Lang;
use App\Core\Paginator;
use App\Core\RateLimit;
use App\Core\Request;
use App\Core\Response;
use App\Core\Seo;
use App\Core\Str;
use App\Core\View;

/**
 * Отзывы о магазине /reviews/ (адрес как на старом сайте, ссылка «Посмотреть отзывы» на главной).
 * ?page=N вне диапазона — 404, как в блоге и каталоге (на старом сайте — пустая страница с кодом 200).
 * GET — публичная страница, кэшируется. Форма отправляется через AJAX (account.js → UI.post),
 * без JS — обычный POST (ответ без кэша). Новые отзывы — status 0, на модерацию в админке.
 */
final class ReviewsController
{
    private const PER_PAGE = 20;

    public function index(): Response
    {
        if (Request::isPost()) return $this->store();
        $r = self::render();
        return $r->status === 200 ? $r->cache(900) : $r;
    }

    private function store(): Response
    {
        $ajax = Request::isAjax();
        $d = [
            'name'   => mb_substr(Request::post('name'), 0, 100),
            'email'  => mb_substr(Request::post('email'), 0, 190),
            'rating' => max(0, min(5, Request::postInt('rating'))),
            'text'   => mb_substr(Request::post('text'), 0, 3000),
        ];
        $errors = [];
        $code = 422;
        $done = t('Спасибо за отзыв! Он появится на сайте после проверки модератором.');

        if (Request::post('website') !== '') {                 // honeypot — тихо «успех»
            return $ajax ? Response::json(['ok' => true, 'message' => $done]) : self::render(['sent' => $done]);
        }
        if (!Csrf::check()) {
            $errors['form'] = t('Страница устарела. Обновите её и отправьте отзыв ещё раз.');
            $code = 419;
        } else {
            if (mb_strlen(trim($d['name'])) < 2) $errors['name'] = t('Укажите ваше имя');
            if ($d['email'] !== '' && Str::email($d['email']) === '') $errors['email'] = t('Проверьте e-mail: например, name@gmail.com');
            $len = mb_strlen(trim($d['text']));
            if ($len < 10) $errors['text'] = $len ? t('Отзыв слишком короткий — напишите хотя бы пару слов') : t('Напишите отзыв');
            if (!$errors && !RateLimit::hit('review:' . Request::ip(), 3, 600)) {
                $errors['form'] = t('Вы уже отправили несколько отзывов. Попробуйте через 10 минут.');
                $code = 429;
            }
        }

        if ($errors) {
            if ($ajax) {
                $first = $errors['form'] ?? reset($errors);
                return Response::json(['ok' => false, 'error' => $first, 'errors' => $errors], $code);
            }
            return self::render(['errors' => $errors, 'd' => $d], $code === 419 ? 422 : $code);
        }

        $id = App::db()->insert('store_reviews', [
            'name' => trim($d['name']), 'email' => Str::email($d['email']) ?: null, 'text' => trim($d['text']),
            'rating' => $d['rating'], 'status' => 0, 'ip' => Request::ip(), 'lang' => Lang::current(),
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        RequestController::notifyAdmin('Новый отзыв о магазине №' . $id, [
            ['Имя', trim($d['name'])], ['E-mail', Str::email($d['email'])], ['Оценка', $d['rating'] ? $d['rating'] . ' из 5' : 'без оценки'],
            ['Отзыв', trim($d['text'])], ['Язык', Lang::isUk() ? 'украинский' : ''], ['Дата', date('d.m.Y H:i')], ['IP', Request::ip()],
        ], '/admin/reviews/');

        return $ajax ? Response::json(['ok' => true, 'message' => $done]) : self::render(['sent' => $done]);
    }

    /** Страница со списком и формой */
    private static function render(array $extra = [], int $status = 200): Response
    {
        $db = App::db();
        // сводка — из кэша данных (сбрасывается вместе с кэшем сайта при модерации)
        $stats = Cache::remember('reviews.stats', 900, static fn() => $db->row(
            'SELECT COUNT(*) cnt, SUM(rating > 0) rated, AVG(NULLIF(rating, 0)) avg FROM store_reviews WHERE status = 1')) ?: ['cnt' => 0, 'rated' => 0, 'avg' => null];
        $total = (int) $stats['cnt'];
        $pg = new Paginator($total, self::PER_PAGE, Request::page());
        if (!Request::isPost() && Request::page() > $pg->pages) return Response::notFound();   // ?page=999
        $reviews = $total ? $db->all('SELECT id, name, text, rating, response, created_at FROM store_reviews
            WHERE status = 1 ORDER BY created_at DESC, id DESC LIMIT ' . $pg->offset . ', ' . $pg->perPage) : [];

        // мета — настройки seo.reviews_meta_* (на /ua/ — «.uk»), одни на всех страницах списка; пусто — «Отзывы» без описания,
        // как на старом сайте; canonical (на старом его не было) — на первую страницу, как у остальной пагинации сайта
        $seo = Seo::make(Seo::pick('', 'seo.reviews_meta_title', []) ?: t('Отзывы'), Seo::pick('', 'seo.reviews_meta_description', []));
        $seo->canonical = url('/reviews/');
        if ($pg->page > 1) $seo->prev = url(Lang::path($pg->url($pg->page - 1)));
        if ($pg->hasNext()) $seo->next = url(Lang::path($pg->url($pg->page + 1)));
        if (Request::isPost()) $seo->robots = 'noindex, follow';

        $html = View::render('front/reviews', $extra + [
            'seo' => $seo, 'reviews' => $reviews, 'pg' => $pg, 'total' => $total,
            'avg' => (int) $stats['rated'] > 0 ? round((float) $stats['avg'], 1) : null, 'rated' => (int) $stats['rated'],
            'errors' => [], 'd' => ['name' => '', 'email' => '', 'rating' => 0, 'text' => ''], 'sent' => null,
            'scripts' => ['js/account.js'], 'bodyClass' => 'pg-reviews',
        ]);
        return Response::html($html, $status);
    }
}
