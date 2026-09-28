<?php
/**
 * Отзывы о магазине: список опубликованных + форма (на модерацию).
 * Страница кэшируется, поэтому токен CSRF в форму подставляет account.js из cookie (UI.csrf());
 * при отправке без JS страница с ошибкой (без кэша) приходит уже с токеном.
 * @var array $reviews @var App\Core\Paginator $pg @var int $total @var ?float $avg @var int $rated
 * @var array $errors @var array $d @var ?string $sent @var View $view
 */
use App\Controllers\Front\AccountController as A;
use App\Controllers\Front\AuthController as F;
use App\Core\Request;

$stars = static function (int $n): string {
    $h = '<span class="srv-stars" role="img" aria-label="' . e(t('Оценка {n} из 5', ['n' => $n])) . '">';
    for ($i = 1; $i <= 5; $i++) $h .= '<span' . ($i <= $n ? ' class="on"' : '') . '>' . icon('star') . '</span>';
    return $h . '</span>';
};
?>
<div class="wrap">
  <div class="page-head">
    <?= $view->partial('front/partials/crumbs', ['items' => [['name' => t('Отзывы')]]]) ?>
    <h1><?= e(t('Отзывы')) ?></h1>
    <p class="acc-sub"><?= e(t('Отзывы наших заказчиков о работе магазина')) ?></p>
  </div>
  <div class="layout-2r srv-layout">
    <div class="srv-list">
      <div class="panel srv-sum">
        <div class="srv-sum-n">
          <?php if ($avg !== null): ?>
            <b><?= e(number_format($avg, 1, ',', '')) ?></b><?= $stars((int) round($avg)) ?>
            <small><?= e(t('Оценок: {n}', ['n' => $rated])) ?></small>
          <?php else: ?>
            <span class="ic"><?= icon('star') ?></span>
          <?php endif; ?>
        </div>
        <div class="srv-sum-t">
          <b><?= e(t('Отзывов: {n}', ['n' => $total])) ?></b>
          <span class="muted"><?= e(t('Оптовики со всей Украины о заказах, качестве обуви и доставке')) ?></span>
        </div>
        <a class="btn btn-o btn-sm" href="#srv-form"><?= icon('doc', 'width:16px') ?><?= e(t('Написать отзыв')) ?></a>
      </div>

      <?php if (!$reviews): ?>
        <div class="empty-state"><div class="ic"><?= icon('star', 'width:30px;height:30px') ?></div><h2><?= e(t('Отзывов пока нет')) ?></h2><p><?= e(t('Будьте первым — расскажите о работе магазина.')) ?></p></div>
      <?php endif; ?>
      <?php foreach ($reviews as $r): $nm = trim((string) $r['name']) ?: t('Покупатель'); ?>
        <article class="panel srv" id="review-<?= (int) $r['id'] ?>">
          <header class="srv-h">
            <span class="av" aria-hidden="true"><?= e(mb_strtoupper(mb_substr($nm, 0, 1))) ?></span>
            <div class="who"><b><?= e($nm) ?></b><time datetime="<?= e(date('c', strtotime((string) $r['created_at']))) ?>"><?= e(A::date($r['created_at'])) ?></time></div>
            <?php if ((int) $r['rating'] > 0): ?><?= $stars((int) $r['rating']) ?><?php endif; ?>
          </header>
          <div class="srv-t"><?= nl2br(e(trim((string) $r['text']))) ?></div>
          <?php if (trim((string) $r['response']) !== ''): ?>
            <div class="srv-ans"><b><?= icon('home', 'width:16px;height:16px') ?><?= e(t('Ответ магазина')) ?></b><p><?= nl2br(e(trim((string) $r['response']))) ?></p></div>
          <?php endif; ?>
        </article>
      <?php endforeach; ?>
      <?= $pg->html() ?>
    </div>

    <aside class="sticky srv-side">
      <section class="panel srv-form" id="srv-form" aria-labelledby="srv-ft">
        <h2 id="srv-ft"><?= e(t('Написать отзыв')) ?></h2>
        <?php if ($sent): ?>
          <div class="note ok" role="status"><?= e($sent) ?></div>
        <?php else: ?>
          <p class="muted srv-lead"><?= e(t('Расскажите, как прошёл заказ: качество обуви, упаковка, доставка, работа менеджеров. Отзыв появится после проверки.')) ?></p>
          <?php if (!empty($errors['form'])): ?><div class="note warn" role="alert"><?= e($errors['form']) ?></div><?php endif; ?>
          <div class="srv-msg" aria-live="polite"></div>
          <form class="form" method="post" action="/reviews/#srv-form" novalidate data-review-form>
            <input type="hidden" name="_csrf" value="<?= Request::isPost() ? e(\App\Core\Csrf::token()) : '' ?>" data-csrf>
            <div class="field">
              <label for="srv-name"><?= e(t('Ваше имя')) ?> <span class="req">*</span></label>
              <input class="input<?= F::inv($errors, 'name') ?>" id="srv-name" name="name" value="<?= e($d['name']) ?>" required maxlength="100" autocomplete="name">
              <?= F::err($errors, 'name') ?>
            </div>
            <div class="field">
              <label for="srv-email">E-mail</label>
              <input class="input<?= F::inv($errors, 'email') ?>" id="srv-email" type="email" name="email" value="<?= e($d['email']) ?>" maxlength="190" autocomplete="email">
              <?= F::err($errors, 'email') ?>
              <?php if (!isset($errors['email'])): ?><div class="hint"><?= e(t('Не публикуется — только для связи с вами')) ?></div><?php endif; ?>
            </div>
            <fieldset class="field srv-rate">
              <legend><?= e(t('Оценка')) ?></legend>
              <div class="rate-in">
                <?php for ($i = 5; $i >= 1; $i--): ?>
                  <input type="radio" id="srv-r<?= $i ?>" name="rating" value="<?= $i ?>"<?= (int) $d['rating'] === $i ? ' checked' : '' ?>>
                  <label for="srv-r<?= $i ?>" title="<?= e(t('Оценка {n} из 5', ['n' => $i])) ?>"><?= icon('star') ?><span class="visually-hidden"><?= e(t('Оценка {n} из 5', ['n' => $i])) ?></span></label>
                <?php endfor; ?>
              </div>
              <input type="radio" id="srv-r0" name="rating" value="0" class="visually-hidden"<?= (int) $d['rating'] === 0 ? ' checked' : '' ?>><label for="srv-r0" class="rate-clear"><?= e(t('без оценки')) ?></label>
            </fieldset>
            <div class="field">
              <label for="srv-text"><?= e(t('Отзыв')) ?> <span class="req">*</span></label>
              <textarea class="textarea<?= F::inv($errors, 'text') ?>" id="srv-text" name="text" rows="6" required maxlength="3000"><?= e($d['text']) ?></textarea>
              <?= F::err($errors, 'text') ?>
            </div>
            <input type="text" name="website" class="hidden" tabindex="-1" autocomplete="off" aria-hidden="true">
            <button class="btn btn-o btn-block" type="submit"><?= e(t('Добавить отзыв')) ?></button>
          </form>
        <?php endif; ?>
      </section>
    </aside>
  </div>
</div>
