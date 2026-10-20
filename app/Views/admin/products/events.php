<?php
/**
 * Лента событий товара (ProductHistory::events): новые сверху — что, когда, кто/чем и что изменилось.
 * @var array $events [['at', 'title', 'who', 'lines', 'kind'], …]
 */
$maxLines = 12;   // строк изменений у одного события, дальше — «…и ещё N»
if (!$events): ?><p class="hint">Событий в журналах нет.</p><?php return; endif; ?>
<ul class="events ac-events">
  <?php foreach ($events as $e):
    $lines = array_slice($e['lines'], 0, $maxLines);
    if (count($e['lines']) > $maxLines) $lines[] = '…и ещё ' . (count($e['lines']) - $maxLines); ?>
    <li class="ev-<?= e($e['kind']) ?>"><b><?= e($e['title']) ?></b><span><?= e(date('d.m.Y H:i', strtotime($e['at']))) ?><?= $e['who'] !== '' ? ' · ' . e($e['who']) : '' ?></span><?php if ($lines): ?><em><?= implode('<br>', array_map('e', $lines)) ?></em><?php endif; ?></li>
  <?php endforeach; ?>
</ul>
