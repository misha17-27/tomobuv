<?php
/**
 * Переключатель языка полей формы «RU | UA» (страницы, блог, баннеры).
 * Поля с классом l-ru видны в режиме RU, l-uk — в режиме UA (form.ed-form[data-lang]).
 * Поля UA отмечены data-uk — по ним считается, сколько переведено.
 * @var string $lang ru|uk — какой язык открыть @var string $what «страницы», «статьи»…
 */
$lang = ($lang ?? 'ru') === 'uk' ? 'uk' : 'ru';
?>
<div class="lang-bar">
  <div class="lang-sw" role="group" aria-label="Язык полей">
    <button type="button" data-lang-to="ru" aria-pressed="<?= $lang === 'ru' ? 'true' : 'false' ?>">RU <span>русский</span></button>
    <button type="button" data-lang-to="uk" aria-pressed="<?= $lang === 'uk' ? 'true' : 'false' ?>">UA <span data-uk-count>украинский</span></button>
  </div>
  <p class="hint">Украинская версия <?= e($what ?? 'страницы') ?> открывается по адресу с <code>/ua/</code>. Пустое поле UA — на сайте показывается русский текст.</p>
  <input type="hidden" name="_lang" value="<?= e($lang) ?>">
</div>
