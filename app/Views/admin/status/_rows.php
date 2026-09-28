<?php
/**
 * Строки таблицы .status для одной группы проверок (используется страницей и ответом catalog.json).
 * @var array $rows [['state' => ok|warn|bad, 'label', 'value', 'note', 'href'?, 'probe'?]]
 */
$icon = [
    'ok'   => '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.4" aria-hidden="true"><path d="M4 12.5l5 5L20 6.5"/></svg>',
    'warn' => '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path d="M12 3l9.5 17H2.5z"/><path d="M12 10v4.5M12 17.2v.1"/></svg>',
    'bad'  => '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.4" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg>',
];
$stateText = ['ok' => 'в порядке', 'warn' => 'стоит посмотреть', 'bad' => 'нужно исправить'];
foreach ($rows as $r): ?>
<tr<?= isset($r['probe']) ? ' data-probe="' . e($r['probe']) . '"' : '' ?>>
  <td class="mark <?= e($r['state']) ?>" title="<?= e($stateText[$r['state']]) ?>"><?= $icon[$r['state']] ?><span class="hidden"><?= e($stateText[$r['state']]) ?></span></td>
  <td class="sys-label"><b><?= e($r['label']) ?></b></td>
  <td class="sys-value"><?= e($r['value']) ?><?php if (!empty($r['href'])): ?> <a href="<?= e($r['href']) ?>" class="sys-go">Открыть →</a><?php endif; ?>
    <?php if ($r['note'] !== ''): ?><small class="sys-note-m"><?= e($r['note']) ?></small><?php endif; ?></td>
  <td class="muted opt sys-note"><?= e($r['note']) ?></td>
</tr>
<?php endforeach;
