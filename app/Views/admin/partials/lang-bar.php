<?php
/**
 * Переключатель языка полей «RU | UA» — один и тот же во всех редакторах
 * (товар, категория, бренд, характеристика, страница, статья, баннер).
 * Ставится внутри области с data-lang="ru|uk" (форма или обёртка): поля .l-ru видны в режиме RU, .l-uk — в режиме UA.
 * Поля UA с атрибутом data-uk считаются в «заполнено N из M». Логика — content.js (initLang), стили — admin.css.
 * Выбор уходит с формой (_lang — контроллеры страниц, статей и баннеров открывают тот же язык после сохранения)
 * и запоминается на вкладку браузера.
 * @var string $lang ru|uk — какой язык открыть @var string $what «страницы», «товара»… @var ?string $note — своя подсказка
 */
$lang = ($lang ?? 'ru') === 'uk' ? 'uk' : 'ru';
$note ??= 'Украинская версия ' . ($what ?? 'страницы') . ' открывается по адресу с <code>/ua/</code>. Пустое поле UA — на сайте показывается русский текст.';
?>
<div class="lang-bar">
  <div class="lang-sw" role="group" aria-label="Язык полей">
    <button type="button" data-lang-to="ru" aria-pressed="<?= $lang === 'ru' ? 'true' : 'false' ?>">RU <span class="lw">русский</span></button>
    <button type="button" data-lang-to="uk" aria-pressed="<?= $lang === 'uk' ? 'true' : 'false' ?>">UA <span data-uk-count><span class="lw">украинский</span></span></button>
  </div>
  <p class="hint"><?= $note ?></p>
  <input type="hidden" name="_lang" value="<?= e($lang) ?>">
</div>
