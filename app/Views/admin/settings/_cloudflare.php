<?php
/**
 * Вкладка «Cloudflare»: готовые страницы витрины хранит Cloudflare (App\Services\Cloudflare) — ответ посетителю без запроса
 * к хостингу; после изменений на сайте страницы удаляются из Cloudflare по API (токен с правом только Cache Purge).
 * Токен в форму не выводится (только «сохранён»). Кнопка «Проверить» — POST /admin/settings/cloudflare/purge/.
 * @var array $values @var array $errors @var array $cf
 */
$v = static fn(string $k, string $d = '') => (string) ($values[$k] ?? $d);
$err = static fn(string $k) => isset($errors[$k]) ? '<span class="fld-err" role="alert">' . e($errors[$k]) . '</span>' : '';
$st = $cf['status'];
$rule = '(http.host eq "' . $cf['host'] . '" and not starts_with(http.request.uri.path, "/admin") and not starts_with(http.request.uri.path, "/ua/admin") and not http.cookie contains "tsid=" and not http.cookie contains "nocache=")';
?>
<div class="grid2">
  <div class="card">
    <h2>Хранить готовые страницы в Cloudflare</h2>
    <p class="hint">Страницы каталога, товаров и брендов одинаковы для всех посетителей (корзина и кабинет подгружаются отдельно), поэтому их может отдавать сам Cloudflare — без запроса к хостингу в США: ответ ~0,05 с вместо ~0,3 с, и сервер почти не нагружен посетителями. После изменений в админке, загрузок поставщиков и обновлений сайт сам удаляет страницы из Cloudflare.</p>
    <label class="chk"><input type="checkbox" name="cf_edge" value="1"<?= $v('cloudflare.edge', '0') === '1' ? ' checked' : '' ?>> Включено</label>
    <?= $err('cf_edge') ?>
    <div class="fld">
      <label class="lbl" for="cf-ttl">Сколько хранить страницу, минут</label>
      <input id="cf-ttl" type="number" name="cf_ttl" min="1" max="60" value="<?= e($v('cloudflare.edge_ttl', '5')) ?>"<?= isset($errors['cf_ttl']) ? ' aria-invalid="true"' : '' ?>>
      <?= $err('cf_ttl') ?>
      <small class="hint">Дольше — быстрее и меньше нагрузка. Если очистка по API не работает, изменения появятся на сайте не позже чем через это время.</small>
    </div>
    <div class="fld">
      <label class="lbl" for="cf-zone">Zone ID</label>
      <input id="cf-zone" type="text" name="cf_zone" maxlength="64" value="<?= e($v('cloudflare.zone_id')) ?>" autocomplete="off" spellcheck="false" placeholder="32 знака, например 0123456789abcdef0123456789abcdef"<?= isset($errors['cf_zone']) ? ' aria-invalid="true"' : '' ?>>
      <?= $err('cf_zone') ?>
    </div>
    <div class="fld">
      <label class="lbl" for="cf-token">API-токен (только право Cache Purge)</label>
      <input id="cf-token" type="password" name="cf_token" maxlength="200" value="" autocomplete="new-password" spellcheck="false" placeholder="<?= $cf['token'] ? 'сохранён — оставьте пустым, чтобы не менять' : 'вставьте токен' ?>"<?= isset($errors['cf_token']) ? ' aria-invalid="true"' : '' ?>>
      <?= $err('cf_token') ?>
      <?php if ($cf['token']): ?><label class="chk"><input type="checkbox" name="cf_token_clear" value="1"> удалить сохранённый токен</label><?php endif; ?>
    </div>
  </div>
  <div class="card">
    <h2>Состояние</h2>
    <dl class="detail">
      <div><dt>Страницы в Cloudflare</dt><dd><?= $cf['ttl'] ? 'хранятся до ' . (int) ($cf['ttl'] / 60) . ' мин' : '<span class="muted">не хранятся</span>' ?></dd></div>
      <div><dt>Токен</dt><dd><?= $cf['token'] ? 'сохранён' : '<span class="warn-txt">не задан</span>' ?></dd></div>
      <div><dt>Последняя очистка</dt><dd><?php if (!$st): ?><span class="muted">не было</span><?php else: ?><?= e(date('d.m.Y H:i', (int) $st['at'])) ?> — <?= !empty($st['ok']) ? '<span class="ok-txt">успешно</span>' : '<span class="warn-txt">ошибка: ' . e((string) ($st['error'] ?? '')) . '</span>' ?><?php endif; ?></dd></div>
      <?php if ($cf['pending']): ?><div><dt>Ожидает очистки</dt><dd>да — в течение минуты; при ошибке Cloudflare — повтор (и в cron раз в час)</dd></div><?php endif; ?>
    </dl>
    <p class="link-row"><button class="btn" type="submit" formaction="/admin/settings/cloudflare/purge/" formnovalidate>Проверить токен — очистить страницы в Cloudflare сейчас</button></p>
    <p class="hint">Фото товаров не очищаются никогда (их адреса постоянные) — очищаются только страницы.</p>
  </div>
</div>
<div class="card">
  <h2>Как включить (один раз, в Cloudflare)</h2>
  <ol class="steps">
    <li><b>API-токен.</b> Cloudflare → значок профиля справа вверху → <i>My Profile → API Tokens → Create Token → Custom token (Get started)</i>. Название — любое, например «tomobuv cache». <i>Permissions</i>: <b>Zone → Cache Purge → Purge</b>. <i>Zone Resources</i>: <b>Include → Specific zone → <?= e($cf['host']) ?></b>. <i>Continue to summary → Create Token</i>, скопируйте токен (он показывается один раз) и вставьте в поле выше.</li>
    <li><b>Zone ID.</b> Cloudflare → сайт <?= e($cf['host']) ?> → <i>Overview</i> (Обзор), справа внизу блок <i>API</i> → <i>Zone ID</i> → скопировать.</li>
    <li>Здесь: вставьте токен и Zone ID, <b>Сохранить</b>, затем «Проверить токен» — должно быть «успешно».</li>
    <li><b>Правило кэширования.</b> Cloudflare → сайт → <i>Caching → Cache Rules → Create rule</i>. Название — «Страницы сайта». <i>If incoming requests match… → Custom filter expression → Edit expression</i>, вставьте:
      <pre class="code" style="white-space:pre-wrap;word-break:break-all;user-select:all"><?= e($rule) ?></pre>
      <i>Then → Cache eligibility</i>: <b>Eligible for cache</b>. <i>Edge TTL</i>: <b>Use cache-control header if present, bypass cache if not</b>. <i>Browser TTL</i>: <b>Respect origin TTL</b>. Остальное не трогать → <b>Deploy</b>.</li>
    <li>Здесь: отметьте «Включено» и <b>Сохранить</b>. Проверка: откройте любую категорию два раза — во второй раз страница придёт из Cloudflare.</li>
  </ol>
  <p class="hint">Сотрудники, вошедшие в админку (cookie <code>tsid</code>), видят страницы прямо с сайта — изменения сразу. Корзина, оформление заказа, кабинет и админка Cloudflare не хранит (сайт запрещает это в заголовках). Выключить — снять «Включено» и сохранить: сайт сам удалит страницы из Cloudflare (если токен задан), новые там не хранятся.</p>
</div>
