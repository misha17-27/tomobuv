<?php
/**
 * Карточка «Наценка» настроек загрузчика со страниц сайта поставщика (Хит обуви, Tom.m): коэффициент, надбавка,
 * округление, пример цены. Без коэффициента загрузку нельзя ни включить, ни запустить вручную.
 * @var string $sup класс адаптера (WebSupplier) @var array $cfg @var ?array $markup @var string $lead пояснение (HTML, пишет разработчик)
 * @var string $after текст после примера (необязательно)
 */
$money = static fn($v) => rtrim(rtrim(number_format((float) $v, 2, '.', ' '), '0'), '.');
?>
<div class="card">
  <h2>Наценка</h2>
  <p class="hint jg-lead"><?= $lead ?></p>
  <div class="pair">
    <label class="fld"><span>Коэффициент (1,2 — +20 %)</span><input name="markup_k" value="<?= e($cfg['markup_k']) ?>" inputmode="decimal" placeholder="не задан — включить нельзя"></label>
    <label class="fld"><span>Надбавка за пару, грн</span><input name="markup_plus" value="<?= e($cfg['markup_plus']) ?>" inputmode="decimal"></label>
    <label class="fld"><span>Округление</span><select name="round_step"><?php foreach ($sup::ROUND_STEPS as $k => $label): ?><option value="<?= e((string) $k) ?>"<?= (string) $cfg['round_step'] === (string) $k ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
    <label class="fld"><span>Как округлять</span><select name="round_mode"><?php foreach ($sup::ROUND_MODES as $k => $label): ?><option value="<?= e($k) ?>"<?= $cfg['round_mode'] === $k ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
  </div>
  <p class="hint"><?php if ($markup): ?>Пример: пара у поставщика 400 грн → на сайте <b><?= e($money($sup::price(400, $markup))) ?> грн</b>; 495 грн → <?= e($money($sup::price(495, $markup))) ?> грн.<?php else: ?>Наценка не задана: пробный прогон покажет цены поставщика как есть.<?php endif; ?> <?= e($after ?? '') ?></p>
</div>
