<?php
/**
 * Вкладка «Обзор» страницы поставщика — одна для всех (Jong•Golf, Хит обуви, Tom.m): ход запуска (опрос без перезагрузки,
 * шаги — suppliers.js), товары поставщика на сайте, сверка наличия, изменения цен, последние запуски, пропущенное.
 * ?list=price|hidden|missing|back|new — полный список с фильтрами и страницами.
 * @var string $sup класс адаптера @var string $base @var array $ov @var array $live @var array $ovRuns @var string $list @var array $f
 * @var int $go @var bool $canRun @var string $runHint @var string $actionsCard HTML блока «Проверка и пробный прогон» адаптера
 * @var array $prev [список => [строки, всего]] (без ?list) @var array $rows @var int $total @var \App\Core\Paginator $pg (с ?list)
 */
use App\Services\Suppliers\SupplierOverview;

$fmt = static fn($n) => number_format((int) $n, 0, '', ' ');
$money = static fn($v) => $v === null || $v === '' ? '—' : rtrim(rtrim(number_format((float) $v, 2, '.', ' '), '0'), '.');
$dt = static fn(?string $d) => $d ? date('d.m.Y H:i', strtotime($d)) : '—';
$pctCell = static function ($p): string {
    if ($p === null) return '<span class="muted">—</span>';
    return '<span class="' . ($p > 0 ? 'sup-up' : 'sup-down') . '">' . ($p > 0 ? '+' : '−') . e(rtrim(rtrim(number_format(abs((float) $p), 1, ',', ''), '0'), ',')) . '%</span>';
};
$prod = static function (array $r) use ($sup): string {
    $pid = (int) $r['product_id'];
    if (($r['name'] ?? null) === null) return '<span class="muted">товар №' . $pid . ' удалён</span>';
    $code = (string) $r['code'] !== '' ? $sup::codeLabel((string) $r['code'], isset($r['ext_id']) ? (string) $r['ext_id'] : null) : '';
    $h = '<a href="/admin/products/' . $pid . '/">' . e((string) $r['name']) . '</a><small>№' . $pid . ($code !== '' ? ' · у поставщика ' . e($code) : '');
    $h .= (int) $r['status'] === 1 ? ' · <a href="/product/' . e(rawurlencode((string) $r['url'])) . '/" target="_blank" rel="noopener">на сайте ↗</a>' : ' · скрыт';
    return $h . '</small>';
};
$listUrl = static fn(string $l, array $q = []) => $base . '?' . http_build_query(['list' => $l] + $q);
$checksMissing = $sup::CHECKS_MISSING;
$counts = $sup::COUNTS_REQUESTS;
$check = $ov['check'];
$lr = $ov['last_run'];
$p = $live['progress'];
$s = $p['stats'] ?? [];
?>
<?php if ($list === ''): ?>
<div class="card sup-live" id="sup-live" data-progress="<?= e($base . 'progress/') ?>" data-base="<?= e($base) ?>" data-id="<?= (int) $live['id'] ?>"
     data-state="<?= $live['running'] ? 'running' : ($live['paused'] ? 'paused' : 'idle') ?>" data-go="<?= (int) $go ?>">
  <div class="card-hd"><h2>Сейчас</h2>
    <span class="st <?= $live['running'] ? 'st-processing' : ($live['paused'] ? 'st-new' : 'st-completed') ?>" id="sup-live-st"><?= $live['running'] ? 'Идёт запуск' : ($live['paused'] ? 'Ждёт продолжения' : 'Запуск не идёт') ?></span></div>
  <div class="pad">
    <p class="sup-live-label" id="sup-live-label" role="status" aria-live="polite"><?php if ($live['id']): ?><?= e($sup::KINDS[$live['kind']] ?? '') ?> №<?= (int) $live['id'] ?> — <?= e($p['label'] ?? '') ?><?php else: ?>Сейчас ничего не загружается.<?php endif; ?></p>
    <div class="jg-bar" id="sup-live-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= (int) ($p['percent'] ?? 0) ?>" aria-labelledby="sup-live-label"<?= $live['id'] ? '' : ' hidden' ?>>
      <i id="sup-live-fill" style="width:<?= (int) ($p['percent'] ?? 0) ?>%"></i><b id="sup-live-pct"><?= (int) ($p['percent'] ?? 0) ?>%</b></div>
    <div class="stats jg-counters" id="sup-live-counters"<?= $live['id'] ? '' : ' hidden' ?>>
      <div class="stat"><span data-c="products"><?= $fmt($s['products'] ?? 0) ?></span>Проверено товаров</div>
      <div class="stat hot"><span data-c="created"><?= $fmt($s['created'] ?? 0) ?></span>Добавлено</div>
      <div class="stat"><span data-c="updated"><?= $fmt($s['updated'] ?? 0) ?></span>Изменено</div>
      <div class="stat"><span data-c="hidden"><?= $fmt(($s['hidden_color'] ?? 0) + ($s['hidden_missing'] ?? 0)) ?></span>Скрыто</div>
      <div class="stat"><span data-c="skipped"><?= $fmt($s['skipped'] ?? 0) ?></span>Пропущено</div>
      <div class="stat"><span data-c="errors"><?= $fmt($s['errors'] ?? 0) ?></span>Ошибок</div>
      <?php if ($counts): ?><div class="stat"><span data-c="requests"><?= $fmt($s['requests'] ?? 0) ?></span>Запросов к сайту поставщика</div><?php endif; ?>
    </div>
    <p class="hint" id="sup-live-next"><?php if ($sup::enabled()): ?>Автозагрузка включена: <?= $live['next'] ? 'следующий запуск по расписанию — около ' . e(date('d.m H:i', (int) $live['next'])) : e($sup::due()[1]) ?>.
      <?php else: ?>Автозагрузка по расписанию выключена<?= $canRun ? ' — можно запустить вручную' : '' ?>.<?php endif; ?>
      Шаги запуска делает эта страница (каждый — до 12 с); если её закрыть, боевой запуск продолжит cron в начале следующего часа.</p>
    <div class="jg-actrow">
      <button class="btn btn-p" type="button" data-sup-run="<?= e($base . 'start/') ?>" data-ask="Запустить загрузку «<?= e($sup::TITLE) ?>» сейчас? Новые товары будут добавлены на сайт, цены и наличие загруженных — обновлены."
        <?= $live['id'] ? ' disabled title="Идёт запуск №' . (int) $live['id'] . '"' : (!$canRun ? ' disabled title="' . e($runHint) . '"' : '') ?>>Запустить сейчас</button>
      <button class="btn" type="button" id="sup-live-go"<?= $live['paused'] ? '' : ' hidden' ?>>Продолжить здесь</button>
      <button class="btn btn-d" type="button" data-sup-stop<?= $live['id'] ? '' : ' hidden' ?>>Остановить</button>
      <a class="btn" id="sup-live-open" href="<?= e($base . 'runs/' . (int) $live['id'] . '/') ?>"<?= $live['id'] ? '' : ' hidden' ?>>Отчёт запуска</a>
    </div>
    <?php if (!$canRun && $runHint !== ''): ?><p class="jg-res jg-red"><?= e($runHint) ?>.</p><?php endif; ?>
    <p class="hint jg-res" id="sup-live-note" role="status"></p>
  </div>
</div>

<?= $actionsCard ?? '' ?>

<div class="stats sup-ov-stats">
  <div class="stat"><span><?= $fmt($ov['linked']) ?></span>Товаров связано с поставщиком</div>
  <div class="stat hot"><span><?= $fmt($ov['active']) ?></span>На сайте (опубликованы)</div>
  <a class="stat<?= $ov['hidden_by_us'] ? ' warn' : '' ?>" href="<?= e($listUrl('hidden')) ?>"><span><?= $fmt($ov['hidden']) ?></span>Скрыто<?= $ov['hidden_by_us'] ? ' (загрузчиком — ' . $fmt($ov['hidden_by_us']) . ')' : '' ?></a>
  <a class="stat" href="<?= e($listUrl('new', ['days' => 30])) ?>"><span><?= $fmt($ov['added_last']) ?> · <?= $fmt($ov['added_7']) ?> · <?= $fmt($ov['added_30']) ?></span>Добавлено: последний запуск · 7 дн. · 30 дн.</a>
  <a class="stat<?= $ov['unmapped'] ? ' warn' : '' ?>" href="<?= e($base . '?tab=map') ?>"><span><?= $fmt($ov['unmapped']) ?></span>Не сопоставлено (категория)<?= $ov['unmapped_cats'] ? ': ' . $fmt($ov['unmapped_cats']) . ' кат.' : '' ?></a>
  <?php if ($check): ?><a class="stat" href="<?= e($base . 'runs/' . (int) $check['id'] . '/?level=skip') ?>"><span><?= $fmt($ov['skipped'] + $ov['existing']) ?></span>Пропущено в проверке №<?= (int) $check['id'] ?></a>
  <?php else: ?><div class="stat"><span>—</span>Пропущено (проверок ещё не было)</div><?php endif; ?>
</div>

<div class="sup-ov-grid">
  <section class="card" aria-labelledby="ov-av">
    <div class="card-hd"><h2 id="ov-av">Сверка наличия</h2><span class="hint">вернулось за 30 дн.: <?= $fmt($ov['back_30']) ?> · скрыто за 30 дн.: <?= $fmt($ov['hidden_30']) ?><?= $sup::TRACKS_STOCK ? ' · «нет в наличии» за 30 дн.: ' . $fmt($ov['out_30']) : '' ?></span></div>
    <div class="pad">
      <?php foreach (array_values(array_filter(['out', 'hidden', 'missing', 'unlinked', 'back'], static fn($k) => isset($prev[$k]))) as $k): [$rows0, $total0] = $prev[$k]; ?>
        <?php if ($k === 'missing' && !$checksMissing): ?>
          <h3 class="sup-h3">Нет у поставщика — ещё на сайте</h3>
          <p class="hint">У этого поставщика товар, которого нет в выгрузке, скрывается в том же запуске («Полное обновление»), отдельной сверки по проходам нет.</p>
          <?php continue; ?>
        <?php endif; ?>
        <h3 class="sup-h3"><?= e(SupplierOverview::LISTS[$k]) ?><?= $k === 'back' ? ' (30 дн.)' : '' ?> <i class="pill"><?= $fmt($total0) ?></i>
          <?php if ($total0 > count($rows0)): ?><a href="<?= e($listUrl($k)) ?>">все →</a><?php endif; ?></h3>
        <?php if ($k === 'missing'): ?><p class="hint">Товара не было в последних полных проходах; скрывается после <?= $fmt($ov['missing_runs']) ?> проходов подряд (сбой одной страницы ничего не скрывает).</p><?php endif; ?>
        <?php if ($k === 'out'): ?><p class="hint">У поставщика есть, но «нет в наличии»: на сайте товар остаётся с пометкой «нет в наличии» и прежней ценой; появится у поставщика — снова «в наличии».</p><?php endif; ?>
        <?php if ($k === 'unlinked'): ?><p class="hint">Товары бренда на сайте, которых загрузчик не нашёл у поставщика (после первого полного боевого прохода — это «нет у бренда»: старые цвета, опечатки в кодах, дубли).
          Загрузчик их не трогает — скрыть или исправить код решает владелец.</p><?php endif; ?>
        <?php if ($rows0): ?>
        <div class="table-scroll"><table class="grid sup-mini"><tbody>
          <?php foreach ($rows0 as $r): ?><tr><td><?= $prod($r) ?></td>
            <td class="nowrap right"><?php if ($k === 'unlinked'): ?><?= e((string) ($r['supplier'] ?: 'заведён вручную')) ?><small>с <?= e($dt($r['created_at'])) ?></small>
              <?php elseif ($k === 'out'): ?><?= !empty($r['out_at']) ? 'с ' . e($dt($r['out_at'])) : 'нет в наличии' ?><small>проверен <?= e($dt($r['seen_at'])) ?></small>
              <?php elseif ($k === 'missing'): ?>нет <?= (int) $r['missed'] ?> из <?= $fmt($ov['missing_runs']) ?><small>был <?= e($dt($r['seen_at'])) ?></small>
              <?php elseif ($k === 'hidden'): ?><?= !empty($r['hidden_at']) ? 'скрыт ' . e($dt($r['hidden_at'])) : 'скрыт загрузчиком' ?><?= $r['seen_at'] ? '<small>был у поставщика ' . e($dt($r['seen_at'])) . '</small>' : '' ?>
              <?php else: ?><?= e($dt($r['created_at'])) ?><small><?= e((string) $r['note']) ?></small><?php endif; ?></td></tr>
          <?php endforeach; ?>
        </tbody></table></div>
        <?php else: ?><p class="hint">Нет.</p><?php endif; ?>
      <?php endforeach; ?>
    </div>
  </section>

  <section class="card" aria-labelledby="ov-pr">
    <div class="card-hd"><h2 id="ov-pr">Изменения цен (30 дн.)</h2><a href="<?= e($listUrl('price')) ?>">Все →</a></div>
    <div class="pad">
      <div class="tabs sup-chips">
        <a href="<?= e($listUrl('price')) ?>">Все <i><?= $fmt($ov['price_30']) ?></i></a>
        <a href="<?= e($listUrl('price', ['dir' => 'up'])) ?>">Подорожали <i><?= $fmt($ov['up_30']) ?></i></a>
        <a href="<?= e($listUrl('price', ['dir' => 'down'])) ?>">Подешевели <i><?= $fmt($ov['down_30']) ?></i></a>
      </div>
      <?php [$rows0] = $prev['price']; if ($rows0): ?>
      <div class="table-scroll"><table class="grid sup-mini"><thead><tr><th>Товар</th><th class="right">У поставщика</th><th class="right">На сайте</th><th class="right">%</th></tr></thead><tbody>
        <?php foreach ($rows0 as $r): ?><tr><td><?= $prod($r) ?></td>
          <td class="right nowrap"><?= e($money($r['old_cost'])) ?> → <?= e($money($r['new_cost'])) ?></td>
          <td class="right nowrap"><?= e($money($r['old_price'])) ?> → <b><?= e($money($r['new_price'])) ?></b><small><?= e($dt($r['created_at'])) ?></small></td>
          <td class="right"><?= $pctCell($r['pct']) ?></td></tr>
        <?php endforeach; ?>
      </tbody></table></div>
      <?php else: ?><p class="hint">За 30 дней цены не менялись (журнал пишет только боевые запуски — пробный прогон ничего не меняет).</p><?php endif; ?>
      <p class="hint">«У поставщика» — его цена за пару (закупочная), «На сайте» — наша цена за пару с наценкой. История цен каждого товара — в его карточке в админке. Журнал хранится <?= $fmt($ov['history_days']) ?> дн.</p>
    </div>
  </section>
</div>

<section class="card" aria-labelledby="ov-runs">
  <div class="card-hd"><h2 id="ov-runs">Последние запуски</h2><a href="<?= e($base . '?tab=runs') ?>">Журнал →</a></div>
  <?php if ($ovRuns): ?>
  <div class="table-scroll"><table class="grid sup-runs"><thead><tr><th>№</th><th>Начат · длительность</th><th>Вид</th><th>Статус</th><th class="right">Проверено</th><th class="right">Добавлено</th>
    <th class="right">Цен изменено</th><th class="right">Скрыто</th><th class="right">Вернулось</th><th class="right">Ошибок</th><?php if ($counts): ?><th class="right">Запросов</th><?php endif; ?></tr></thead><tbody>
  <?php foreach ($ovRuns as $r): $rs = $r['stats']; $dry = $r['kind'] === 'dry'; ?>
    <tr class="<?= $r['status'] === 'running' ? 'unread' : '' ?>"><td><a href="<?= e($base . 'runs/' . (int) $r['id'] . '/') ?>">№<?= (int) $r['id'] ?></a></td>
      <td class="nowrap"><?= e($dt($r['started_at'])) ?><small><?= e(SupplierOverview::duration((int) $r['duration'])) ?> · <?= e(['cron' => 'по расписанию', 'cli' => 'командная строка', 'admin' => 'из админки'][$r['origin']] ?? $r['origin']) ?></small></td>
      <td class="nowrap"><?= e($dry ? 'Пробный' : ($sup::KINDS[$r['kind']] ?? $r['kind'])) ?><small><?= in_array($r['src'], ['file', 'pages'], true) ? 'сохранённые данные' : e($r['mode'] === 'full' ? 'полный проход' : ($r['mode'] === 'quick' ? 'быстрый' : ($r['src'] === 'api' ? 'API' : ''))) ?></small></td>
      <td><span class="st <?= ['done' => 'st-completed', 'error' => 'st-refunded', 'stopped' => 'st-deleted', 'running' => 'st-processing'][$r['status']] ?? '' ?>"><?= e($sup::STATUSES[$r['status']] ?? $r['status']) ?></span>
        <?php if ($r['error']): ?><small><?= e(mb_strimwidth((string) $r['error'], 0, 120, '…')) ?></small><?php endif; ?></td>
      <td class="right"><?= $fmt($rs['products']) ?></td>
      <td class="right"><?= $fmt($rs['created']) ?><?= $dry ? '<small>будет</small>' : '' ?></td>
      <td class="right"><?= $dry ? '—' : $fmt($r['prices']) ?></td>
      <td class="right"><?= $fmt($r['hidden']) ?></td>
      <td class="right"><?= $dry ? '—' : $fmt($r['back']) ?></td>
      <td class="right<?= $rs['errors'] ? ' jg-red' : '' ?>"><?= $fmt($rs['errors']) ?></td>
      <?php if ($counts): ?><td class="right"><?= $fmt($rs['requests'] ?? 0) ?></td><?php endif; ?></tr>
  <?php endforeach; ?>
  </tbody></table></div>
  <?php else: ?><div class="empty-card"><h2>Запусков ещё не было</h2><p>Начните с проверки связи и пробного прогона (ничего не пишет в каталог).</p></div><?php endif; ?>
</section>

<?php if ($check): $skip = $ov['skip']; arsort($skip); ?>
<section class="card" aria-labelledby="ov-skip">
  <div class="card-hd"><h2 id="ov-skip">Не попало на сайт в последней проверке (№<?= (int) $check['id'] ?>, <?= e(mb_strtolower($sup::KINDS[$check['kind']] ?? '')) ?>, <?= e($dt($check['started_at'])) ?>)</h2></div>
  <div class="pad">
    <?php if ($ov['unmapped'] || $ov['existing'] || $skip): ?>
    <ul class="sup-skip">
      <?php if ($ov['unmapped']): ?><li><a href="<?= e($base . '?tab=map') ?>">категория не сопоставлена</a> — <?= $fmt($ov['unmapped']) ?> (<?= $fmt($ov['unmapped_cats']) ?> кат. поставщика)</li><?php endif; ?>
      <?php if ($ov['existing']): ?><li><a href="<?= e($base . 'runs/' . (int) $check['id'] . '/?level=skip&why=existing') ?>">есть на сайте (Forsage / заведены вручную) — не взяты под загрузчик</a> — <?= $fmt($ov['existing']) ?></li><?php endif; ?>
      <?php foreach ($skip as $k => $n): ?><li><a href="<?= e($base . 'runs/' . (int) $check['id'] . '/?level=all&why=' . rawurlencode((string) $k)) ?>"><?= e($sup::skipReasons()[$k] ?? $k) ?></a> — <?= $fmt($n) ?></li><?php endforeach; ?>
    </ul>
    <p class="hint">Массовые причины (старые товары ниже порога, повторы, пропускаемые бренды) в отчёте показаны примерами — остальное только посчитано.</p>
    <?php else: ?><p class="hint">Всё прочитанное у поставщика сопоставлено и обработано.</p><?php endif; ?>
  </div>
</section>
<?php endif; ?>

<?php if (!empty($excluded['n'])): ?>
<section class="card" id="excluded" aria-labelledby="ov-excl">
  <div class="card-hd"><h2 id="ov-excl">Удалены вручную — снова не загружаются (<?= $fmt($excluded['n']) ?>)</h2>
    <form method="post" action="<?= e($base . 'excluded/restore/') ?>" data-confirm="Вернуть к загрузке все <?= $fmt($excluded['n']) ?>? Следующий запуск добавит те, что есть у поставщика.">
      <?= \App\Controllers\Admin\BaseController::tokenField() ?><input type="hidden" name="all" value="1"><button class="btn btn-sm" type="submit">Вернуть все</button></form></div>
  <p class="hint pad">Товары поставщика, которые вы удалили на сайте: загрузчик их больше не добавляет. «Вернуть» — следующий запуск добавит товар снова (если он есть у поставщика).</p>
  <div class="table-scroll"><table class="grid"><thead><tr><th>Товар</th><th>Код у поставщика</th><th>Удалён</th><th></th></tr></thead><tbody>
  <?php foreach ($excluded['rows'] as $r): ?><tr>
    <td><?= $r['name'] !== null && $r['name'] !== '' ? e((string) $r['name']) : '<span class="muted">товар №' . (int) $r['product_id'] . '</span>' ?></td>
    <td><?= str_starts_with((string) $r['code'], 'site:') ? '<span class="muted">не был связан' . ((string) $r['ext_id'] !== '' ? ', Forsage ' . e((string) $r['ext_id']) : '') . '</span>' : e($sup::codeLabel((string) $r['code'], null)) ?></td>
    <td class="nowrap"><?= e($dt($r['created_at'])) ?></td>
    <td class="right"><form method="post" action="<?= e($base . 'excluded/restore/') ?>" class="inline"><?= \App\Controllers\Admin\BaseController::tokenField() ?><input type="hidden" name="code" value="<?= e((string) $r['code']) ?>"><button class="btn btn-sm" type="submit">Вернуть</button></form></td>
  </tr><?php endforeach; ?>
  </tbody></table></div>
  <?php if ($excluded['n'] > count($excluded['rows'])): ?><p class="hint pad">Показаны последние <?= count($excluded['rows']) ?> из <?= $fmt($excluded['n']) ?>.</p><?php endif; ?>
</section>
<?php endif; ?>

<?php else: ?>
<?php /* ---------------------------------------------------------------- полный список */ ?>
<p class="back"><a href="<?= e($base) ?>">← Обзор</a></p>
<div class="card">
  <div class="card-hd"><h2><?= e(SupplierOverview::LISTS[$list]) ?></h2><span class="hint"><?= $fmt($total) ?></span></div>
  <?php if (in_array($list, ['price', 'back', 'new'], true)): ?>
  <form class="filter-bar sup-filter" method="get" action="<?= e($base) ?>">
    <input type="hidden" name="list" value="<?= e($list) ?>">
    <?php if ($list === 'price'): ?>
    <div class="tabs">
      <?php foreach (SupplierOverview::DIRS as $k => $label): ?><a class="<?= $f['dir'] === $k ? 'on' : '' ?>" href="<?= e($listUrl('price', ['dir' => $k, 'days' => $f['days']])) ?>"><?= e($label) ?></a><?php endforeach; ?>
    </div>
    <input type="hidden" name="dir" value="<?= e($f['dir']) ?>">
    <?php endif; ?>
    <label class="sup-period"><span>За период</span>
      <select name="days" onchange="this.form.submit()"><?php foreach (SupplierOverview::PERIODS as $d => $label): ?><option value="<?= (int) $d ?>"<?= $f['days'] === $d ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
    <noscript><button class="btn btn-sm">Показать</button></noscript>
  </form>
  <?php endif; ?>
  <?php if ($rows): ?>
  <div class="table-scroll"><table class="grid">
    <?php if ($list === 'price'): ?>
    <thead><tr><th>Дата</th><th>Товар</th><th class="right">У поставщика, за пару</th><th class="right">На сайте, за пару</th><th class="right">%</th><th>Запуск</th></tr></thead><tbody>
    <?php foreach ($rows as $r): ?><tr><td class="nowrap"><?= e($dt($r['created_at'])) ?></td><td><?= $prod($r) ?></td>
      <td class="right nowrap"><?= e($money($r['old_cost'])) ?> → <?= e($money($r['new_cost'])) ?></td>
      <td class="right nowrap"><?= e($money($r['old_price'])) ?> → <b><?= e($money($r['new_price'])) ?></b></td>
      <td class="right"><?= $pctCell($r['pct']) ?></td>
      <td><?= $r['run_id'] ? '<a href="' . e($base . 'runs/' . (int) $r['run_id'] . '/') . '">№' . (int) $r['run_id'] . '</a>' : '—' ?></td></tr>
    <?php endforeach; ?>
    <?php elseif ($list === 'unlinked'): ?>
    <thead><tr><th>Товар</th><th class="right">Цена</th><th>Откуда</th><th>Заведён</th></tr></thead><tbody>
    <?php foreach ($rows as $r): ?><tr><td><?= $prod($r) ?></td><td class="right"><?= e($money($r['price'])) ?><?= (int) $r['in_stock'] ? '' : '<small>нет в наличии</small>' ?></td>
      <td><?= e((string) ($r['supplier'] ?: 'вручную')) ?></td><td class="nowrap"><?= e($dt($r['created_at'])) ?></td></tr>
    <?php endforeach; ?>
    <?php elseif ($list === 'hidden' || $list === 'missing' || $list === 'out'): ?>
    <thead><tr><th>Товар</th><th class="right">Цена поставщика</th><th>Был у поставщика</th><th><?= ['hidden' => 'Скрыт', 'missing' => 'Нет у поставщика', 'out' => 'Нет в наличии с'][$list] ?></th></tr></thead><tbody>
    <?php foreach ($rows as $r): ?><tr><td><?= $prod($r) ?></td><td class="right"><?= e($money($r['cost'])) ?></td><td class="nowrap"><?= e($dt($r['seen_at'])) ?></td>
      <td class="nowrap"><?= match ($list) {
          'hidden' => !empty($r['hidden_at']) ? e($dt($r['hidden_at'])) : 'загрузчиком (дата не записана)',
          'out' => !empty($r['out_at']) ? e($dt($r['out_at'])) : '—',
          default => (int) $r['missed'] . ' полн. проходов из ' . $fmt($ov['missing_runs']),
      } ?></td></tr>
    <?php endforeach; ?>
    <?php else: ?>
    <thead><tr><th>Дата</th><th>Товар</th><?php if ($list === 'new'): ?><th class="right">Цена поставщика</th><th class="right">На сайте</th><?php else: ?><th>Что было</th><?php endif; ?><th>Запуск</th></tr></thead><tbody>
    <?php foreach ($rows as $r): ?><tr><td class="nowrap"><?= e($dt($r['created_at'])) ?></td><td><?= $prod($r) ?></td>
      <?php if ($list === 'new'): ?><td class="right"><?= e($money($r['new_cost'])) ?></td><td class="right"><?= e($money($r['new_price'])) ?></td><?php else: ?><td><?= e((string) $r['note']) ?></td><?php endif; ?>
      <td><?= $r['run_id'] ? '<a href="' . e($base . 'runs/' . (int) $r['run_id'] . '/') . '">№' . (int) $r['run_id'] . '</a>' : '—' ?></td></tr>
    <?php endforeach; ?>
    <?php endif; ?>
  </tbody></table></div>
  <?= $pg->html() ?>
  <?php else: ?><div class="empty-card"><h2>Пусто</h2><p><?= in_array($list, ['price', 'back', 'new'], true) ? 'За выбранный период таких изменений нет.' : 'Таких товаров сейчас нет.' ?></p></div><?php endif; ?>
</div>
<?php endif; ?>
