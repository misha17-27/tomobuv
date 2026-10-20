<?php
/**
 * Вкладка «Журнал» загрузчика со страниц сайта поставщика (Хит обуви, Tom.m): запуски с запросами, найденными «чужими»,
 * скрытыми и пропущенными.
 * @var string $sup класс адаптера (WebSupplier) @var string $base @var array $runs @var array $cfg
 */
$fmt = static fn($n) => number_format((int) $n, 0, '', ' ');
$dt = static fn(?string $d) => $d ? date('d.m.Y H:i', strtotime($d)) : '—';
?>
<div class="card">
  <?php if ($runs): ?>
  <div class="table-scroll"><table class="grid"><thead><tr><th>№</th><th>Начат</th><th>Вид</th><th>Статус</th><th class="right">Запросов</th><th class="right">Товаров</th><th class="right">Добавлено</th><th class="right">Изменено</th>
    <th class="right">Есть на сайте</th><th class="right">Скрыто</th><th class="right">Пропущено</th><th class="right">Ошибок</th></tr></thead><tbody>
  <?php foreach ($runs as $r): $s = $r['stats']; $mode = $r['state']['mode'] ?? ''; ?>
    <tr class="<?= $r['status'] === 'running' ? 'unread' : '' ?>"><td><a href="<?= e($base . 'runs/' . (int) $r['id'] . '/') ?>">№<?= (int) $r['id'] ?></a></td>
      <td class="nowrap"><?= e($dt($r['started_at'])) ?><small><?= e(['cron' => 'по расписанию', 'cli' => 'командная строка', 'admin' => 'из админки'][$r['origin']] ?? $r['origin']) ?></small></td>
      <td><?= e($sup::KINDS[$r['kind']] ?? $r['kind']) ?><small><?= $r['src'] === 'file' ? 'сохранённые страницы' : e($sup::MODES[$mode] ?? '') ?></small></td>
      <td><span class="st <?= ['done' => 'st-completed', 'error' => 'st-refunded', 'stopped' => 'st-deleted', 'running' => 'st-processing'][$r['status']] ?? '' ?>"><?= e($sup::STATUSES[$r['status']] ?? $r['status']) ?></span>
        <?php if ($r['error']): ?><small><?= e(mb_strimwidth((string) $r['error'], 0, 140, '…')) ?></small><?php endif; ?></td>
      <td class="right"><?= $fmt($s['requests'] ?? 0) ?></td><td class="right"><?= $r['kind'] === 'test' ? '—' : $fmt($s['products']) ?></td><td class="right"><?= $fmt($s['created']) ?></td><td class="right"><?= $fmt($s['updated']) ?></td>
      <td class="right"><?= $fmt($s['existing'] ?? 0) ?></td><td class="right"><?= $fmt($s['hidden_missing']) ?></td><td class="right"><?= $fmt($s['skipped']) ?></td><td class="right"><?= $fmt($s['errors']) ?></td></tr>
  <?php endforeach; ?>
  </tbody></table></div>
  <p class="hint jg-pad">Отчёты хранятся <?= (int) $cfg['log_days'] ?> дн. (последние 5 запусков — всегда). Причины пропусков: <?= e(implode('; ', $sup::skipReasons())) ?>.</p>
  <?php else: ?><div class="empty-card"><h2>Запусков ещё не было</h2><p>Начните с «Проверить связь» и «Пробный прогон».</p></div><?php endif; ?>
</div>
