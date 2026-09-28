<?php
/**
 * Итог импорта: счётчики, параметры, созданные товары, ошибки / пропуски / предупреждения по строкам.
 * @var array $job @var array $progress @var array $counts @var string $level @var array $levels @var array $rows
 * @var App\Core\Paginator $pg @var array $createdRows @var bool $active @var bool $fileExists @var ?array $profile
 * @var int $conflictTotal @var array $conflictRows — товары, изменённые на сайте после выгрузки файла (не перезаписаны)
 */
use App\Controllers\Admin\BaseController;
use App\Services\Import\Importer;

$p = $progress;
$st = $job['state'];
$o = $job['options'];
$id = (int) $job['id'];
$fmt = static fn($n) => number_format((int) $n, 0, '', ' ');
$dur = static function (int $ms): string {
    $s = (int) round($ms / 1000);
    return $s >= 60 ? intdiv($s, 60) . ' мин ' . ($s % 60) . ' с' : max(1, $s) . ' с';
};
$reindex = ['full' => 'полная перестройка индекса каталога', 'partial' => 'точечное обновление изменённых товаров', 'none' => 'не требовалась'];
?>
<?php if ($active): ?>
  <div class="flash warn">Импорт ещё не закончен (<?= e($p['label']) ?>, <?= (int) $p['percent'] ?>%). <a href="/admin/import/<?= $id ?>/">Открыть ход импорта →</a></div>
<?php elseif ($job['status'] === 'error'): ?>
  <div class="flash bad">Импорт остановлен с ошибкой: <?= e((string) $job['errors']) ?></div>
<?php else: ?>
  <div class="flash">Импорт завершён<?= $job['finished_at'] ? ' ' . e(date('d.m.Y в H:i', strtotime((string) $job['finished_at']))) : '' ?>. Каталог и кэш сайта обновлены — изменения уже видны покупателям.</div>
<?php endif; ?>

<div class="stats">
  <div class="stat<?= $p['created'] ? ' hot' : '' ?>"><span><?= $fmt($p['created']) ?></span>Создано товаров</div>
  <div class="stat"><span><?= $fmt($p['updated']) ?></span>Обновлено</div>
  <div class="stat"><span><?= $fmt($p['unchanged']) ?></span>Без изменений</div>
  <div class="stat"><span><?= $fmt(max(0, $p['skipped'] - $conflictTotal)) ?></span>Пропущено</div>
  <?php if ($conflictTotal): ?><a class="stat warn" href="#conflicts"><span><?= $fmt($conflictTotal) ?></span>Изменены на сайте — не перезаписаны</a><?php endif; ?>
  <div class="stat<?= $p['errors'] ? ' warn' : '' ?>"><span><?= $fmt($p['errors']) ?></span>Ошибок</div>
  <?php if (isset($st['hidden'])): ?><div class="stat"><span><?= $fmt($st['hidden']) ?></span>Скрыто (нет в файле)</div><?php endif; ?>
  <?php if ($p['images']['total']): ?><div class="stat"><span><?= $fmt($p['images']['done']) ?> / <?= $fmt($p['images']['total']) ?></span>Фото загружено</div><?php endif; ?>
</div>

<div class="grid2">
  <div class="card">
    <div class="card-hd"><h2>Параметры</h2></div>
    <div class="pad">
      <dl class="detail">
        <div><dt>Файл</dt><dd><?= e($job['name']) ?> <small class="muted">· <?= e(strtoupper((string) $job['format'])) ?> · строк: <?= $fmt($job['total']) ?></small></dd></div>
        <?php if ($profile): ?><div><dt>Профиль</dt><dd><?= e($profile['name']) ?></dd></div><?php endif; ?>
        <div><dt>Ключ поиска</dt><dd><?= e(Importer::KEYS[$o['key']] ?? $o['key']) ?><?= $o['supplier'] !== '' ? ' · поставщик «' . e($o['supplier']) . '»' : '' ?></dd></div>
        <div><dt>Режим</dt><dd><?= e(Importer::MODES[$o['mode']] ?? $o['mode']) ?><?= !empty($o['price_only']) ? ' · только цена и наличие' : '' ?><?= !empty($o['overwrite']) ? ' · изменённые на сайте после выгрузки — перезаписаны' : '' ?></dd></div>
        <div><dt>Наценка</dt><dd><?= (float) $o['markup'] ? e(rtrim(rtrim(number_format((float) $o['markup'], 2, '.', ''), '0'), '.')) . ' %' : 'нет' ?><?= (int) $o['round'] ? ' · ' . e(mb_strtolower(Importer::ROUNDS[(int) $o['round']] ?? '')) : '' ?></dd></div>
        <div><dt>Время</dt><dd><?= !empty($st['time_ms']) ? e($dur((int) $st['time_ms'])) : '—' ?><?= !empty($st['last_step_ms']) ? ' <small class="muted">· последний шаг ' . round($st['last_step_ms'] / 1000, 1) . ' с</small>' : '' ?></dd></div>
        <?php if (!empty($st['reindex'])): ?><div><dt>Каталог</dt><dd><?= e($reindex[$st['reindex']] ?? $st['reindex']) ?><?= !empty($st['reindex_ms']) ? ' <small class="muted">· ' . round($st['reindex_ms'] / 1000, 1) . ' с</small>' : '' ?></dd></div><?php endif; ?>
        <?php if (!empty($st['hide_note'])): ?><div><dt>Скрытие</dt><dd class="im-bad"><?= e($st['hide_note']) ?></dd></div><?php endif; ?>
      </dl>
    </div>
  </div>
  <div class="card">
    <div class="card-hd"><h2>Действия</h2></div>
    <div class="pad im-logacts">
      <?php if (!$active && $fileExists): ?>
        <form method="post" action="/admin/import/<?= $id ?>/restart/" data-confirm="Разобрать этот файл заново? Настройки сохранятся, запуск — после проверки предпросмотра.">
          <?= BaseController::tokenField() ?><button class="btn" type="submit">Повторить импорт этого файла</button></form>
      <?php elseif (!$fileExists): ?><p class="hint">Файл задания удалён (хранится 7 дней).</p><?php endif; ?>
      <?php if (array_sum($counts)): ?><a class="btn" href="/admin/import/<?= $id ?>/errors.csv">Скачать журнал (CSV)</a><?php endif; ?>
      <form method="post" action="/admin/import/profiles/" class="im-profrow">
        <?= BaseController::tokenField() ?><input type="hidden" name="job_id" value="<?= $id ?>"><input type="hidden" name="profile_id" value="<?= (int) ($profile['id'] ?? 0) ?>">
        <label class="sr-only" for="im-pn">Название профиля</label>
        <input type="text" name="profile_name" id="im-pn" maxlength="190" value="<?= e($profile['name'] ?? ($o['supplier'] ?? '')) ?>" placeholder="Название профиля">
        <button class="btn" type="submit"><?= $profile ? 'Обновить профиль' : 'Сохранить как профиль' ?></button>
      </form>
      <form method="post" action="/admin/import/<?= $id ?>/delete/" data-confirm="Удалить задание и его журнал? Товары на сайте не изменятся.">
        <?= BaseController::tokenField() ?><button class="btn btn-d" type="submit">Удалить задание</button></form>
      <a href="/admin/import/">← Ко всем заданиям</a>
    </div>
  </div>
</div>

<?php if ($conflictTotal): ?>
<div class="card" id="conflicts">
  <div class="card-hd"><h2>Изменены на сайте после выгрузки — не перезаписаны: <?= $fmt($conflictTotal) ?></h2>
    <?php if ($conflictTotal > count($conflictRows)): ?><a href="?level=conflict#rows">Все в журнале</a><?php endif; ?></div>
  <div class="pad">
    <p class="hint im-m0">Эти товары кто-то изменил на сайте позже, чем был выгружен файл (колонка <code>updated_at</code>), — данные файла их правки не затёрли.
      Проверьте товары; если файл всё-таки главнее — повторите импорт с перезаписью.</p>
  </div>
  <?php if ($conflictRows): ?>
  <div class="table-scroll"><table class="grid">
    <thead><tr><th>ID</th><th>Товар</th><th>Изменён на сайте</th><th>Статус</th></tr></thead>
    <tbody>
    <?php foreach ($conflictRows as $r): ?>
      <tr class="<?= (int) $r['status'] ? '' : 'is-draft' ?>">
        <td><?= (int) $r['id'] ?></td>
        <td><a href="/admin/products/<?= (int) $r['id'] ?>/"><?= e($r['name']) ?></a></td>
        <td class="nowrap"><?= $r['updated_at'] ? e(date('d.m.Y H:i:s', strtotime((string) $r['updated_at']))) : '—' ?></td>
        <td><?= (int) $r['status'] ? 'на сайте' : 'скрыт' ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
  <?php if (!$active && $fileExists): ?>
  <div class="pad">
    <form method="post" action="/admin/import/<?= $id ?>/restart/" data-confirm="Разобрать файл заново с галочкой «Перезаписать всё равно»? Запуск — после проверки предпросмотра; данные файла запишутся и поверх правок на сайте.">
      <?= BaseController::tokenField() ?><input type="hidden" name="overwrite" value="1"><button class="btn" type="submit">Повторить импорт с перезаписью</button></form>
  </div>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($createdRows): ?>
<div class="card">
  <div class="card-hd"><h2>Новые товары</h2><?php if ($p['created'] > count($createdRows)): ?><a href="/admin/products/?sort=id_desc">Все в списке товаров</a><?php endif; ?></div>
  <div class="table-scroll"><table class="grid">
    <thead><tr><th>ID</th><th>Товар</th><th class="right">Цена/пара</th><th>Статус</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($createdRows as $r): ?>
      <tr class="<?= (int) $r['status'] ? '' : 'is-draft' ?>">
        <td><?= (int) $r['id'] ?></td>
        <td><a href="/admin/products/<?= (int) $r['id'] ?>/"><?= e($r['name']) ?></a></td>
        <td class="right"><?= e(price_format($r['price'])) ?></td>
        <td><?= (int) $r['status'] ? 'на сайте' : 'скрыт' ?></td>
        <td class="right"><a href="/product/<?= e($r['url']) ?>/" target="_blank" rel="noopener">на сайте ↗</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>
<?php endif; ?>

<div class="card" id="rows">
  <div class="card-hd"><h2>Журнал по строкам</h2></div>
  <div class="pad im-pb0">
    <div class="tabs">
      <?php foreach ($levels as $k => $label): ?>
        <a class="<?= $level === $k ? 'on' : '' ?>" href="?level=<?= e($k) ?>#rows"><?= e($label) ?> <i><?= $fmt($counts[$k] ?? 0) ?></i></a>
      <?php endforeach; ?>
    </div>
  </div>
  <?php if (!$rows): ?>
    <div class="empty-card"><h2><?= e(['error' => 'Ошибок нет', 'conflict' => 'Товаров, изменённых на сайте после выгрузки, нет', 'skip' => 'Пропущенных строк нет'][$level] ?? 'Предупреждений нет') ?></h2><p>Все строки этого типа обработаны без замечаний.</p></div>
  <?php else: ?>
  <div class="table-scroll"><table class="grid im-logtable">
    <thead><tr><th class="right">Строка</th><th>Сообщение</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?><tr><td class="num"><?= (int) $r['n'] ?: '—' ?></td><td><?= e($r['message']) ?></td></tr><?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>
<?= $pg->html() ?>
