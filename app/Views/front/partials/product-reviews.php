<?php
/**
 * Отзывы о товаре: список одобренных + форма (вкладка на странице товара и страница /reviews/).
 * Форма отправляется через product.js (UI.post, CSRF из cookie); без JS — обычный POST,
 * ответ — эта же страница с сообщением (не кэшируется).
 * @var array $p @var array $reviews @var int $rCount @var bool $full @var callable $stars
 * @var ?App\Core\Paginator $pager @var ?array $flash ['ok' => …] | ['error' => …, 'old' => $_POST]
 */
$full ??= false;
$flash ??= null;
$pager ??= null;
$old = $flash['old'] ?? [];
$oldRate = (int) ($old['rate'] ?? 0);
$reviewsUrl = $p['link'] . 'reviews/';
$months = ['января', 'февраля', 'марта', 'апреля', 'мая', 'июня', 'июля', 'августа', 'сентября', 'октября', 'ноября', 'декабря'];
$date = static function (string $dt) use ($months): string {
    $ts = strtotime($dt);
    return $ts ? date('j', $ts) . ' ' . t($months[(int) date('n', $ts) - 1]) . ' ' . date('Y', $ts) : '';
};
$old = array_map(static fn($v) => is_scalar($v) ? (string) $v : '', $old);
?>
<div class="rv" id="reviews">
  <?php if (!empty($flash['ok'])): ?><div class="note ok rv-note" role="status"><?= e($flash['ok']) ?></div><?php endif; ?>

  <?php if ($reviews): ?>
    <div class="rv-list">
      <?php foreach ($reviews as $r): ?>
        <article class="rv-item">
          <header>
            <span class="rv-ava" aria-hidden="true"><?= e(mb_strtoupper(mb_substr(trim((string) $r['name']) ?: '?', 0, 1))) ?></span>
            <div><b><?= e($r['name']) ?></b><time datetime="<?= e(date('Y-m-d', strtotime((string) $r['created_at']) ?: time())) ?>"><?= e($date((string) $r['created_at'])) ?></time></div>
            <?php if ((int) $r['rate'] > 0): ?><?= $stars((float) $r['rate']) ?><?php endif; ?>
          </header>
          <?php if (trim((string) $r['title']) !== ''): ?><h3><?= e($r['title']) ?></h3><?php endif; ?>
          <p><?= nl2br(e($r['text'])) ?></p>
          <?php if (trim((string) ($r['response'] ?? '')) !== ''): ?>
            <div class="rv-resp"><b><?= e(t('Ответ магазина')) ?></b><p><?= nl2br(e($r['response'])) ?></p></div>
          <?php endif; ?>
        </article>
      <?php endforeach; ?>
    </div>
    <?php if ($full && $pager): ?><?= $pager->html() ?><?php endif; ?>
    <?php if (!$full && $rCount > count($reviews)): ?><p class="rv-all"><a class="link" href="<?= e($reviewsUrl) ?>"><?= e(t('Все отзывы ({n})', ['n' => $rCount])) ?> →</a></p><?php endif; ?>
  <?php else: ?>
    <div class="rv-empty"><?= icon('star') ?><p><?= e(t('Отзывов об этом товаре пока нет. Поделитесь мнением первым — это поможет другим покупателям.')) ?></p></div>
  <?php endif; ?>

  <form class="form rv-form" id="rv-form" method="post" action="<?= e($reviewsUrl) ?>" novalidate>
    <h3><?= e(t('Написать отзыв')) ?></h3>
    <?php if (!empty($flash['error'])): ?><div class="note warn" role="alert"><?= e($flash['error']) ?></div><?php endif; ?>
    <?php // в кэшируемой странице токена нет (его подставляет product.js из cookie); ответ на отправку без JS не кэшируется — токен можно вывести ?>
    <input type="hidden" name="_csrf" value="<?= $flash !== null ? e(\App\Core\Csrf::token()) : '' ?>">
    <fieldset class="rv-rate">
      <legend><?= e(t('Оцените товар')) ?> <span class="req" aria-hidden="true">*</span></legend>
      <div class="rv-stars">
        <?php for ($i = 5; $i >= 1; $i--): ?>
          <input type="radio" id="rv-r<?= $i ?>" name="rate" value="<?= $i ?>"<?= $oldRate === $i ? ' checked' : '' ?>><label for="rv-r<?= $i ?>" title="<?= e(t('{n} из 5', ['n' => $i])) ?>"><span class="visually-hidden"><?= e(t('{n} из 5', ['n' => $i])) ?></span></label>
        <?php endfor; ?>
      </div>
    </fieldset>
    <div class="field">
      <label for="rv-name"><?= e(t('Ваше имя')) ?> <span class="req" aria-hidden="true">*</span></label>
      <input class="input" id="rv-name" name="name" maxlength="100" autocomplete="name" required value="<?= e($old['name'] ?? '') ?>">
    </div>
    <div class="field">
      <label for="rv-text"><?= e(t('Отзыв')) ?> <span class="req" aria-hidden="true">*</span></label>
      <textarea class="textarea" id="rv-text" name="text" maxlength="5000" rows="4" required placeholder="<?= e(t('Качество, посадка, соответствие размеру…')) ?>"><?= e($old['text'] ?? '') ?></textarea>
    </div>
    <div class="hidden" aria-hidden="true"><label for="rv-website">Website</label><input type="text" id="rv-website" name="website" tabindex="-1" autocomplete="off"></div>
    <div class="rv-foot">
      <button class="btn btn-o" type="submit"><?= e(t('Отправить отзыв')) ?></button>
      <small class="muted"><?= e(t('Отзыв появится после проверки модератором')) ?></small>
    </div>
  </form>
</div>
