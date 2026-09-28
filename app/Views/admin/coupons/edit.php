<?php
/**
 * Форма промокода: код с генератором, скидка, условия, срок, лимиты, ограничения по товарам, статус.
 * @var array $c @var bool $isNew @var array $errors @var bool $canEdit @var array $picked @var ?array $uses @var bool $siteOn
 */
use App\Controllers\Admin\BaseController;
use App\Services\Coupons;

$err = static fn(string $k) => isset($errors[$k]) ? '<small class="cp-err" role="alert">' . e($errors[$k]) . '</small>' : '';
$bad = static fn(string $k) => isset($errors[$k]) ? ' aria-invalid="true"' : '';
$num = static fn($v) => rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.');
$date = static fn(?string $v) => $v ? substr($v, 0, 10) : '';
$action = $isNew ? '/admin/coupons/new/' : '/admin/coupons/' . (int) $c['id'] . '/';
$st = $isNew ? ($c['status'] ? 'active' : 'off') : Coupons::state($c);
$pill = ['active' => 'ok', 'scheduled' => 'confirmed', 'expired' => 'refunded', 'exhausted' => 'invoiced', 'off' => 'cancelled'];
$pickers = [
    'category' => ['Категории', 'category_ids', 'Найти категорию…', 'Скидка на товары этих категорий и их подкатегорий'],
    'brand'    => ['Бренды', 'brand_ids', 'Найти бренд…', 'Скидка на товары этих брендов'],
    'product'  => ['Отдельные товары', 'product_ids', 'Название, артикул или id…', 'Эти товары получают скидку всегда'],
];
?>
<?php if (!$isNew): ?>
<nav class="subtabs" aria-label="Разделы промокода">
  <a class="on" href="/admin/coupons/<?= (int) $c['id'] ?>/">Настройки</a>
  <a href="/admin/coupons/<?= (int) $c['id'] ?>/usages/">Применения <i class="cp-cnt"><?= (int) ($uses['n'] ?? 0) ?></i></a>
</nav>
<?php endif; ?>

<?php if ($errors): ?><div class="flash bad">Проверьте поля: <?= e(implode('; ', $errors)) ?></div><?php endif; ?>
<?php if (!$canEdit): ?><div class="flash warn">Вы вошли как менеджер — промокод можно посмотреть, изменять его может только администратор.</div><?php endif; ?>
<?php if (!$siteOn): ?><div class="flash warn">Приём промокодов на сайте выключен — этот код сейчас не примут. Включить можно на странице <a href="/admin/coupons/">Промокоды</a>.</div><?php endif; ?>

<form method="post" action="<?= e($action) ?>" id="cp-form" data-cp-form novalidate>
  <?= BaseController::tokenField() ?>
  <fieldset class="two-col cp-fs"<?= $canEdit ? '' : ' disabled' ?>>
    <div>
      <div class="card">
        <div class="card-hd"><h2>Промокод</h2></div>
        <div class="pad">
          <label class="fld"><span>Код *</span>
            <span class="cp-codebox">
              <input type="text" name="code" value="<?= e($c['code']) ?>" maxlength="<?= Coupons::CODE_MAX ?>" required autocomplete="off" spellcheck="false"
                     class="cp-code-in" placeholder="SALE10" data-cp-code<?= $bad('code') ?>>
              <button class="btn" type="button" data-cp-gen title="Случайный код из 8 символов">Сгенерировать</button>
            </span>
            <?= $err('code') ?>
            <small class="hint">Латинские буквы, цифры, «-» и «_». Регистр не важен: покупатель может ввести sale10 или SALE10.
              Код с приставкой: введите её с дефисом (OPT-) и нажмите «Сгенерировать».</small>
          </label>
          <label class="fld"><span>Комментарий</span>
            <input type="text" name="comment" value="<?= e($c['comment']) ?>" maxlength="500" placeholder="Для кого и зачем: «оптовику из Харькова», «рассылка к 1 сентября»">
            <small class="hint">Видят только сотрудники.</small>
          </label>
        </div>
      </div>

      <div class="card">
        <div class="card-hd"><h2>Скидка</h2></div>
        <div class="pad">
          <div class="pair">
            <label class="fld"><span>Тип</span>
              <select name="type" data-cp-type>
                <?php foreach (Coupons::TYPES as $k => $label): ?><option value="<?= e($k) ?>"<?= $c['type'] === $k ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
              </select>
            </label>
            <label class="fld"><span>Размер *</span>
              <span class="cp-unit"><input type="number" name="value" value="<?= e($num($c['value'])) ?>" min="0.01" step="0.01" <?= $c['type'] === 'percent' ? 'max="100"' : '' ?> required inputmode="decimal" data-cp-value<?= $bad('value') ?>><b data-cp-unit><?= $c['type'] === 'percent' ? '%' : 'грн' ?></b></span>
              <?= $err('value') ?>
            </label>
          </div>
          <label class="fld" data-cp-percent<?= $c['type'] === 'percent' ? '' : ' hidden' ?>><span>Не больше, грн</span>
            <span class="cp-unit"><input type="number" name="max_discount" value="<?= e($num($c['max_discount'])) ?>" min="0" step="1" inputmode="decimal"<?= $bad('max_discount') ?>><b>грн</b></span>
            <?= $err('max_discount') ?>
            <small class="hint">Потолок скидки для процента. 0 — без ограничения.</small>
          </label>
          <p class="hint">Процент считается от суммы товаров, на которые действует промокод. Фиксированная сумма вычитается из заказа, но не больше стоимости этих товаров. Скидка округляется до целых гривен, доставка не учитывается.</p>
        </div>
      </div>

      <div class="card">
        <div class="card-hd"><h2>Условия заказа</h2></div>
        <div class="pad">
          <div class="pair">
            <label class="fld"><span>Сумма заказа от, грн</span>
              <span class="cp-unit"><input type="number" name="min_sum" value="<?= e($num($c['min_sum'])) ?>" min="0" step="1" inputmode="decimal"<?= $bad('min_sum') ?>><b>грн</b></span>
              <?= $err('min_sum') ?>
            </label>
            <label class="fld"><span>Ящиков в заказе от</span>
              <span class="cp-unit"><input type="number" name="min_boxes" value="<?= (int) $c['min_boxes'] ?>" min="0" step="1" inputmode="numeric"<?= $bad('min_boxes') ?>><b>ящ.</b></span>
              <?= $err('min_boxes') ?>
            </label>
          </div>
          <p class="hint">0 — без условия. Считается по всей корзине (товары в наличии), до скидки и без доставки.</p>
        </div>
      </div>

      <div class="card">
        <div class="card-hd"><h2>Срок действия</h2></div>
        <div class="pad">
          <div class="pair">
            <label class="fld"><span>Действует с</span>
              <input type="date" name="starts_at" value="<?= e($date($c['starts_at'])) ?>" data-cp-starts<?= $bad('starts_at') ?>><?= $err('starts_at') ?>
            </label>
            <label class="fld"><span>Действует по (включительно)</span>
              <input type="date" name="expires_at" value="<?= e($date($c['expires_at'])) ?>" data-cp-expires<?= $bad('expires_at') ?>><?= $err('expires_at') ?>
            </label>
          </div>
          <div class="cp-quick" role="group" aria-label="Быстрый выбор срока">
            <button type="button" class="chip" data-cp-days="7">Неделя</button>
            <button type="button" class="chip" data-cp-days="30">Месяц</button>
            <button type="button" class="chip" data-cp-days="90">3 месяца</button>
            <button type="button" class="chip" data-cp-days="eom">До конца месяца</button>
            <button type="button" class="chip" data-cp-days="0">Бессрочно</button>
          </div>
          <p class="hint">Пустое поле — без ограничения. Последний день действует до 23:59.</p>
        </div>
      </div>

      <div class="card">
        <div class="card-hd"><h2>Лимиты</h2></div>
        <div class="pad">
          <div class="pair">
            <label class="fld"><span>Всего применений</span>
              <input type="number" name="usage_limit" value="<?= (int) $c['usage_limit'] ?>" min="0" step="1" inputmode="numeric"<?= $bad('usage_limit') ?>><?= $err('usage_limit') ?>
              <small class="hint">0 — без ограничения. «1» — одноразовый код.</small>
            </label>
            <label class="fld"><span>На одного клиента</span>
              <input type="number" name="per_customer_limit" value="<?= (int) $c['per_customer_limit'] ?>" min="0" step="1" inputmode="numeric"<?= $bad('per_customer_limit') ?>><?= $err('per_customer_limit') ?>
              <small class="hint">Клиент узнаётся по аккаунту и телефону. 0 — без ограничения.</small>
            </label>
          </div>
          <?php if (!$isNew): ?>
            <p class="cp-used">Использован: <b><?= number_format((int) $c['used'], 0, '', ' ') ?></b> <?= plural((int) $c['used'], 'раз', 'раза', 'раз') ?><?= $c['usage_limit'] > 0 ? ' из ' . number_format((int) $c['usage_limit'], 0, '', ' ') : '' ?></p>
            <?php if ($c['usage_limit'] > 0): ?><span class="meter"><i style="width:<?= min(100, (int) round($c['used'] / $c['usage_limit'] * 100)) ?>%"></i></span><?php endif; ?>
            <?php if ($c['used'] > 0): ?>
              <label class="check"><input type="checkbox" name="reset_used" value="1"> Обнулить счётчик (история заказов сохранится)</label>
            <?php endif; ?>
          <?php endif; ?>
        </div>
      </div>

      <div class="card">
        <div class="card-hd"><h2>На какие товары</h2></div>
        <div class="pad">
          <p class="hint cp-lead">Ничего не выбрано — скидка на весь заказ. Если заданы и категории, и бренды — товар должен подходить под оба условия (например, бренд Jong Golf только в «Детской обуви»). Отдельно выбранные товары получают скидку всегда.</p>
          <?php foreach ($pickers as $kind => [$label, $field, $ph, $hint]): ?>
            <div class="fld cp-pick" data-cp-pick="<?= e($kind) ?>" data-name="<?= e($field) ?>[]">
              <span><?= e($label) ?></span>
              <div class="cp-chips" data-cp-chips>
                <?php foreach ($picked[$kind] as $it): ?>
                  <span class="chip on" data-id="<?= (int) $it['id'] ?>"><?= e($it['name']) ?><input type="hidden" name="<?= e($field) ?>[]" value="<?= (int) $it['id'] ?>"><?php if ($canEdit): ?><button type="button" class="cp-x" aria-label="Убрать «<?= e($it['name']) ?>»">×</button><?php endif; ?></span>
                <?php endforeach; ?>
              </div>
              <?php if ($canEdit): ?>
              <div class="cp-search">
                <input type="search" placeholder="<?= e($ph) ?>" autocomplete="off" aria-label="<?= e($label) ?>: поиск" data-cp-q>
                <ul class="cp-drop" role="listbox" hidden data-cp-drop></ul>
              </div>
              <?php endif; ?>
              <small class="hint"><?= e($hint) ?></small>
              <?= $err($field) ?>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

    <aside>
      <div class="card">
        <div class="card-hd"><h2>Статус</h2><?php if (!$isNew): ?><span class="pill <?= $pill[$st] ?>"><?= e(Coupons::STATES[$st]) ?></span><?php endif; ?></div>
        <div class="pad">
          <label class="check"><input type="checkbox" name="status" value="1"<?= $c['status'] ? ' checked' : '' ?> data-cp-status> Промокод действует</label>
          <p class="hint">Выключенный код покупатель ввести не сможет, но история применений сохранится.</p>
          <div class="cp-summary" data-cp-summary aria-live="polite"></div>
        </div>
      </div>

      <?php if (!$isNew): ?>
      <div class="card">
        <div class="card-hd"><h2>Применения</h2><a href="/admin/coupons/<?= (int) $c['id'] ?>/usages/">Все</a></div>
        <div class="pad">
          <dl class="detail">
            <div><dt>Заказов</dt><dd><?= number_format((int) $uses['n'], 0, '', ' ') ?></dd></div>
            <div><dt>Скидок выдано</dt><dd><?= e(price_format($uses['disc'])) ?></dd></div>
            <div><dt>Последнее</dt><dd><?= $uses['last'] ? e(date('d.m.Y H:i', strtotime((string) $uses['last']))) : '—' ?></dd></div>
            <div><dt>Создан</dt><dd><?= $c['created_at'] ? e(date('d.m.Y H:i', strtotime((string) $c['created_at']))) : '—' ?></dd></div>
            <?php if (!empty($c['updated_at'])): ?><div><dt>Изменён</dt><dd><?= e(date('d.m.Y H:i', strtotime((string) $c['updated_at']))) ?></dd></div><?php endif; ?>
          </dl>
        </div>
      </div>
      <?php if ($canEdit): ?>
      <div class="card">
        <div class="card-hd"><h2>Удаление</h2></div>
        <div class="pad">
          <p class="hint cp-del-hint">В заказах, где промокод уже применён, скидка останется. Чтобы просто остановить код, снимите галочку «Промокод действует».</p>
          <button class="btn btn-d block" type="submit" form="cp-del">Удалить промокод</button>
        </div>
      </div>
      <?php endif; ?>
      <?php endif; ?>
    </aside>
  </fieldset>

  <?php if ($canEdit): ?>
  <div class="savebar">
    <button class="btn btn-p" type="submit"><?= $isNew ? 'Создать промокод' : 'Сохранить' ?></button>
    <a class="btn" href="/admin/coupons/">Отмена</a>
    <span class="hint" data-cp-dirty hidden>Есть несохранённые изменения</span>
  </div>
  <?php endif; ?>
</form>
<?php if (!$isNew && $canEdit): ?>
<form id="cp-del" method="post" action="/admin/coupons/<?= (int) $c['id'] ?>/delete/"
      data-confirm="Удалить промокод <?= e($c['code']) ?>?<?= $c['used'] ? ' Он применён в ' . (int) $c['used'] . ' ' . plural((int) $c['used'], 'заказе', 'заказах', 'заказах') . ' — скидка в них останется.' : '' ?>">
  <?= BaseController::tokenField() ?>
</form>
<?php endif; ?>
