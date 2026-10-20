<?php
/**
 * Запуск автозагрузки поставщика (Jong•Golf, Хит обуви, Tom.m): ход (шаги выполняет suppliers.js), итоги и отчёт (как log/*.html старого загрузчика).
 * @var array $run @var array $progress @var array $lines @var string $level @var array $counts @var \App\Core\Paginator $pg
 * @var bool $autostart @var string $base @var ?string $sup класс адаптера (по умолчанию Jong•Golf)
 */
use App\Controllers\Admin\SuppliersController;
use App\Services\Suppliers\JongGolf;
use App\Services\Suppliers\WebSupplier;

$sup ??= JongGolf::class;
// со страниц сайта поставщика (Хит обуви, Tom.m, Jong•Golf с сайта бренда); запуск Jong•Golf по API — нет
$hit = is_subclass_of($sup, WebSupplier::class) && $sup::webRun($run);
$saved = in_array($run['src'], ['file', 'pages'], true);   // сохранённые страницы (без запросов к сайту)

$fmt = static fn($n) => number_format((int) $n, 0, '', ' ');
$s = $run['stats'];
$id = (int) $run['id'];
$dry = $run['kind'] === 'dry';
$running = $run['status'] === 'running';
$cnt = static fn(string $l) => (int) ($counts[$l] ?? 0);
$levelCount = ['all' => array_sum(array_map('intval', $counts)), 'problem' => $cnt('error') + $cnt('warn')];
$pill = ['add' => 'st-completed', 'update' => 'st-processing', 'hide' => 'st-paid', 'same' => 'st-deleted', 'skip' => 'st-deleted', 'warn' => 'st-paid', 'error' => 'st-refunded', 'info' => ''];
$pillText = ['add' => 'добавлен', 'update' => 'изменён', 'hide' => 'скрыт', 'same' => 'без изменений', 'skip' => 'пропуск', 'warn' => 'внимание', 'error' => 'ошибка', 'info' => 'сообщение'];
$money = static fn($v) => $v === null ? '' : rtrim(rtrim(number_format((float) $v, 2, '.', ' '), '0'), '.');
?>
<?php if ($dry): ?><div class="flash warn">Пробный прогон: в каталог ничего не записано<?= $hit ? '' : ', поставщику ничего не подтверждено' ?>. Ниже — что будет при боевом запуске с теми же данными.</div><?php endif; ?>

<div class="card jg-run" id="jg-run-box" data-step="<?= e($base . 'runs/' . $id . '/step/') ?>" data-stop="<?= e($base . 'runs/' . $id . '/stop/') ?>"
     data-auto="<?= $autostart ? '1' : '0' ?>" data-running="<?= $running ? '1' : '0' ?>">
  <div class="card-hd"><h2 id="jg-label"><?= e($progress['label']) ?></h2>
    <span class="st <?= ['done' => 'st-completed', 'error' => 'st-refunded', 'stopped' => 'st-deleted', 'running' => 'st-processing'][$run['status']] ?? '' ?>" id="jg-status"><?= e($sup::STATUSES[$run['status']] ?? $run['status']) ?></span></div>
  <div class="pad">
    <?php if ($running): ?>
    <div class="jg-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= (int) $progress['percent'] ?>" aria-labelledby="jg-label">
      <i id="jg-fill" style="width:<?= (int) $progress['percent'] ?>%"></i><b id="jg-pct"><?= (int) $progress['percent'] ?>%</b></div>
    <?php endif; ?>
    <div class="stats jg-counters">
      <div class="stat"><span id="jg-c-products"><?= $fmt($s['products']) ?></span>Товаров поставщика<?= !empty($s['colors']) ? ' (цветов ' . $fmt($s['colors']) . ')' : '' ?></div>
      <div class="stat hot"><span id="jg-c-created"><?= $fmt($s['created']) ?></span><?= $dry ? 'Будет добавлено' : 'Добавлено' ?></div>
      <div class="stat"><span id="jg-c-updated"><?= $fmt($s['updated']) ?></span><?= $dry ? 'Будет изменено' : 'Изменено' ?></div>
      <div class="stat"><span id="jg-c-same"><?= $fmt($s['same']) ?></span>Без изменений</div>
      <div class="stat"><span id="jg-c-hidden"><?= $fmt($s['hidden_color'] + $s['hidden_missing']) ?></span><?= $dry ? 'Будет скрыто' : 'Скрыто' ?></div>
      <?php if ($hit): ?><div class="stat"><span id="jg-c-existing"><?= $fmt($s['existing'] ?? 0) ?></span>Есть на сайте (Forsage, вручную)</div><?php endif; ?>
      <?php if ($sup::TRACKS_STOCK): ?><div class="stat"><span><?= $fmt($s['out'] ?? 0) ?></span><?= $dry ? 'Будет «нет в наличии»' : 'Нет в наличии у поставщика' ?></div><?php endif; ?>
      <div class="stat"><span id="jg-c-skipped"><?= $fmt($s['skipped']) ?></span>Пропущено</div>
      <div class="stat<?= $s['errors'] ? ' warn' : '' ?>"><span id="jg-c-errors"><?= $fmt($s['errors']) ?></span>Ошибок</div>
    </div>
    <div class="flash bad" id="jg-error"<?= $run['error'] && $run['status'] === 'error' ? '' : ' hidden' ?>><?= e((string) $run['error']) ?></div>
    <dl class="detail jg-facts">
      <div><dt>Начат</dt><dd><?= e(date('d.m.Y H:i:s', strtotime((string) $run['started_at']))) ?> · <?= e(['cron' => 'по расписанию', 'cli' => 'из командной строки', 'admin' => 'из админки'][$run['origin']] ?? $run['origin']) ?></dd></div>
      <?php if ($hit): ?>
      <div><dt>Данные</dt><dd><?= $saved ? 'сохранённые страницы (без запросов к сайту)' : 'сайт поставщика, ' . e(!empty($run['state']['aids']) || !empty($run['state']['ids']) ? 'только выбранные товары' : ($sup::MODES[$run['state']['mode'] ?? ''] ?? '')) ?> · страниц <?= $fmt($s['pages']) ?> · запросов <?= $fmt($s['requests'] ?? 0) ?></dd></div>
      <?php if (!empty($s['photo_robots'])): ?><div><dt>Фото</dt><dd class="jg-red">закрыты robots.txt поставщика — не запрошено главных фото: <?= $fmt($s['photo_robots']) ?><?= !empty($s['photo_robots_extra']) ? ', доп. фото: ' . $fmt($s['photo_robots_extra']) : '' ?></dd></div><?php endif; ?>
      <?php if (!empty($s['found'])): ?><div><dt>Найдено на сайте</dt><dd><?= e(ltrim($sup::foundText($s['found']), ' ')) ?></dd></div><?php endif; ?>
      <?php else: ?>
      <div><dt>Данные</dt><dd><?= $run['src'] === 'file' ? 'сохранённый ответ поставщика (файл)' : 'API поставщика' ?><?= ($s['queue'] ?? null) !== null ? ' · в первой странице очереди — ' . $fmt($s['queue']) . ' товаров' : '' ?> · страниц <?= $fmt($s['pages']) ?></dd></div>
      <div><dt>Подтверждения</dt><dd><?= !empty($run['state']['callback']) ? 'отправляются поставщику после записи каждой страницы (подтверждено товаров: ' . $fmt($s['confirmed'] ?? 0) . ')' : 'не отправляются' . ($dry ? ' (пробный прогон)' : '') ?></dd></div>
      <?php endif; ?>
      <?php if ($run['finished_at']): ?><div><dt>Закончен</dt><dd><?= e(date('d.m.Y H:i:s', strtotime((string) $run['finished_at']))) ?></dd></div><?php endif; ?>
      <?php if ($s['skip']): arsort($s['skip']); ?><div><dt>Пропуски</dt><dd><?= e(implode('; ', array_map(static fn($k, $n) => ($sup::skipReasons()[$k] ?? $k) . ' — ' . $n, array_keys($s['skip']), $s['skip']))) ?></dd></div><?php endif; ?>
      <?php if (!empty($s['hide_cancelled'])): ?><div><dt>Скрытие</dt><dd class="jg-red">отменено: пришлось бы скрыть <?= $fmt($s['hide_cancelled']) ?> товаров (больше половины активных)</dd></div><?php endif; ?>
    </dl>
    <?php if ($s['unmapped']): ?><p class="hint">Несопоставленных <?= $hit ? 'категорий' : 'сочетаний' ?>: <?= $fmt(count($s['unmapped'])) ?> — <a href="<?= e($base . '?tab=map') ?>">добавить в таблицу категорий</a>.</p><?php endif; ?>
    <div class="jg-actrow">
      <?php if ($running): ?>
        <button class="btn btn-p" type="button" id="jg-go"<?= $autostart ? ' hidden' : '' ?>>Продолжить</button>
        <button class="btn" type="button" id="jg-pause"<?= $autostart ? '' : ' hidden' ?>>Пауза</button>
        <button class="btn btn-d" type="button" id="jg-stop">Остановить</button>
        <span class="hint" id="jg-note">Идёт частями по ~12 с. Не закрывайте страницу; если связь оборвётся — «Продолжить».</span>
      <?php endif; ?>
    </div>
  </div>
</div>

<div class="card">
  <div class="card-hd"><h2>Отчёт</h2><span class="hint"><?= $fmt($levelCount['all']) ?> строк</span></div>
  <div class="tabs jg-levels">
    <?php foreach (SuppliersController::LEVELS as $k => $label): $n = $levelCount[$k] ?? $cnt($k); if ($k !== 'all' && !$n) continue; ?>
      <a href="?level=<?= e($k) ?>" class="<?= $level === $k && ($why ?? '') === '' ? 'on' : '' ?>"><?= e($label) ?> <i><?= $fmt($n) ?></i></a>
    <?php endforeach; ?>
  </div>
  <?php if (($why ?? '') !== ''): ?>
  <p class="hint jg-pad">Только причина «<?= e($why === 'existing' ? 'есть на сайте (Forsage / вручную) — не взят под загрузчик' : ($sup::skipReasons()[$why] ?? $why)) ?>» — <?= $fmt($pg->total) ?> строк
    (массовые причины в отчёте — примерами). <a href="?level=<?= e($level) ?>">Показать все строки</a></p>
  <?php endif; ?>
  <?php if ($lines): ?>
  <div class="table-scroll"><table class="grid jg-log"><thead><tr><th>Время</th><th>Что</th><th>Сообщение</th><th>Категория</th><th class="right">Цена</th><th class="right">Ящик</th><th>На сайте</th></tr></thead><tbody>
  <?php foreach ($lines as $l): $d = $l['data']; ?>
    <tr class="jg-l-<?= e($l['level']) ?>"><td class="nowrap"><?= e(date('H:i:s', strtotime((string) $l['created_at']))) ?></td>
      <td><span class="st <?= $pill[$l['level']] ?? '' ?>"><?= e($pillText[$l['level']] ?? $l['level']) ?></span></td>
      <td class="jg-msg"><?= e($l['message']) ?><?php if ($l['product_id'] && !($dry && $l['level'] === 'add')): ?>
        <small><a href="/admin/products/<?= (int) $l['product_id'] ?>/" target="_blank" rel="noopener">товар <?= (int) $l['product_id'] ?></a></small><?php endif; ?>
        <?php if ($d && !empty($d['feat']) && $l['level'] === 'add'): ?><small><?= e(implode(' · ', array_map(static fn($k, $v) => $k . ': ' . preg_replace('/\s+/u', ' ', (string) $v), array_keys($d['feat']), $d['feat']))) ?></small><?php endif; ?></td>
      <td><?= $d ? e((string) ($d['category'] ?? '')) : '' ?></td>
      <td class="right nowrap"><?= $d && isset($d['price']) ? e($money($d['price'])) . (!empty($d['compare']) ? '<small>было ' . e($money($d['compare'])) . '</small>' : '') . (isset($d['purchase']) ? '<small>закупка ' . e($money($d['purchase'])) . '</small>' : '') : '' ?></td>
      <td class="right"><?= $d && isset($d['box']) ? (int) $d['box'] : '' ?></td>
      <td><?= $d && isset($d['status']) ? ((int) $d['status'] ? 'да' : 'нет') . ((int) ($d['in_stock'] ?? 1) ? '' : '<small>нет в наличии</small>') : '' ?></td></tr>
  <?php endforeach; ?>
  </tbody></table></div>
  <?= $pg->html() ?>
  <?php else: ?><div class="empty-card"><h2>Строк нет</h2><p>Выберите другой фильтр.</p></div><?php endif; ?>
</div>
