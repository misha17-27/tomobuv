<?php
/**
 * Вкладка «Валюты»: курсы USD и EUR к гривне (settings currencies = {"UAH":1,"USD":41.5,"EUR":48.5}).
 * @var array $rates @var array $errors
 */
$names = ['USD' => 'Доллар США', 'EUR' => 'Евро'];
$fmt = [App\Controllers\Admin\SettingsController::class, 'rateText'];   // тот же формат сравнивается при сохранении
?>
<div class="card narrow-card">
  <h2>Курсы валют</h2>
  <p class="hint" style="margin:-6px 0 16px">Цены в базе — в гривнах. Покупатель может переключить валюту в шапке сайта — цены пересчитываются по этим курсам. Основная валюта — гривна (UAH = 1).</p>
  <?php foreach ($names as $c => $label): $inv = isset($errors[$c]); ?>
    <div class="fld">
      <label class="lbl" for="rate-<?= e($c) ?>"><?= e($label) ?> — сколько гривен за 1 <?= e($c) ?></label>
      <div class="cur-in"><span>1 <?= e($c) ?> =</span><input id="rate-<?= e($c) ?>" type="text" inputmode="decimal" name="rates[<?= e($c) ?>]" value="<?= e($fmt($rates[$c] ?? '')) ?>" data-rate="<?= e($c) ?>" maxlength="12"<?= $inv ? ' aria-invalid="true"' : '' ?>><span>грн</span></div>
      <?php if ($inv): ?><span class="fld-err" role="alert"><?= e($errors[$c]) ?></span><?php endif; ?>
      <small class="hint" data-rate-example="<?= e($c) ?>"></small>
    </div>
  <?php endforeach; ?>
  <p class="hint">Актуальные курсы: <a href="https://bank.gov.ua/ua/markets/exchangerates" target="_blank" rel="noopener">НБУ</a>, <a href="https://privatbank.ua/rates-archive" target="_blank" rel="noopener">ПриватБанк</a>.</p>
</div>
