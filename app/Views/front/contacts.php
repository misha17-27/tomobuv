<?php
/**
 * Блок страницы «Контакты»: телефоны, график, e-mail, адрес (из настроек), карта Google и форма обратной связи.
 * Форму отправляет app.js (data-request="contact" → POST /request/contact/).
 * @var array $c ['phones', 'hours', 'email', 'address', 'social', 'map' (запрос Google Карт), 'place' (адрес для подписи), 'mapLang', 'showMap']
 */
$tel = static fn(string $p) => 'tel:+' . preg_replace('/\D/', '', $p);
$mapSrc = 'https://www.google.com/maps?' . http_build_query(['q' => $c['map'], 'hl' => $c['mapLang'] ?? 'ru', 'z' => 16, 'output' => 'embed']);
$mapLink = 'https://www.google.com/maps/search/?' . http_build_query(['api' => 1, 'query' => $c['map']]);
?>
<section class="cp-contacts" aria-label="<?= e(t('Контактная информация')) ?>">
  <div class="cp-cards">
    <?php if ($c['phones']): ?>
    <div class="panel cp-card">
      <span class="cp-ic"><?= icon('phone') ?></span>
      <div><h2><?= e(t('Телефоны')) ?></h2>
        <?php foreach ($c['phones'] as $p): ?><a class="cp-tel" href="<?= e($tel((string) $p)) ?>"><?= e($p) ?></a><?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>
    <?php if ($c['hours'] !== ''): ?>
    <div class="panel cp-card">
      <span class="cp-ic"><?= icon('clock') ?></span>
      <div><h2><?= e(t('График работы')) ?></h2><p><?= e($c['hours']) ?></p></div>
    </div>
    <?php endif; ?>
    <?php if ($c['email'] !== ''): ?>
    <div class="panel cp-card">
      <span class="cp-ic"><?= icon('mail') ?></span>
      <div><h2>E-mail</h2><a class="link" href="mailto:<?= e($c['email']) ?>"><?= e($c['email']) ?></a></div>
    </div>
    <?php endif; ?>
    <?php if ($c['address'] !== ''): ?>
    <div class="panel cp-card">
      <span class="cp-ic"><?= icon('map') ?></span>
      <div><h2><?= e(t('Адрес склада')) ?></h2><p><?= e($c['address']) ?></p><a class="link" href="<?= e($mapLink) ?>" target="_blank" rel="noopener"><?= e(t('Открыть в Google Картах')) ?> →</a></div>
    </div>
    <?php endif; ?>
  </div>

  <?php if ($c['social']): ?>
  <div class="cp-social">
    <span><?= e(t('Мы в мессенджерах и соцсетях:')) ?></span>
    <?php foreach ($c['social'] as $s): ?><a class="btn btn-g btn-sm" href="<?= e($s['url']) ?>" target="_blank" rel="noopener"><?= icon($s['icon'], 'width:18px;height:18px') ?><?= e($s['name']) ?></a><?php endforeach; ?>
  </div>
  <?php endif; ?>

  <div class="cp-row<?= $c['showMap'] ? '' : ' cp-row-1' ?>">
    <?php if ($c['showMap']): ?>
    <div class="panel cp-mapbox">
      <iframe class="cp-map" src="<?= e($mapSrc) ?>" loading="lazy" referrerpolicy="no-referrer-when-downgrade" allowfullscreen title="<?= e(t('Карта проезда: {place}', ['place' => $c['place'] ?? $c['map']])) ?>"></iframe>
    </div>
    <?php endif; ?>
    <div class="panel cp-formbox">
      <h2 id="cp-form-h"><?= e(t('Напишите нам')) ?></h2>
      <p class="muted"><?= e(t('Ответим на вопросы об ассортименте, ценах и условиях опта в рабочее время.')) ?></p>
      <form class="form" data-request="contact" novalidate aria-labelledby="cp-form-h">
        <div class="row2">
          <div class="field"><label for="cf-name"><?= e(t('Ваше имя')) ?> <span class="req">*</span></label><input class="input" id="cf-name" name="name" required autocomplete="name" maxlength="100"></div>
          <div class="field"><label for="cf-phone"><?= e(t('Телефон')) ?> <span class="req">*</span></label><input class="input" id="cf-phone" type="tel" name="phone" required autocomplete="tel" placeholder="+38 (0__) ___-__-__" maxlength="30"></div>
        </div>
        <div class="field"><label for="cf-email">E-mail</label><input class="input" id="cf-email" type="email" name="email" autocomplete="email" maxlength="190"></div>
        <div class="field"><label for="cf-text"><?= e(t('Сообщение')) ?> <span class="req">*</span></label><textarea class="textarea" id="cf-text" name="text" required rows="4" maxlength="3000"></textarea></div>
        <input type="text" name="website" class="hidden" tabindex="-1" autocomplete="off" aria-hidden="true">
        <button class="btn btn-o btn-block"><?= e(t('Отправить сообщение')) ?></button>
      </form>
    </div>
  </div>
</section>
