<?php
/**
 * «SEO → Исправить автоматически» (App\Services\SeoFix): предпросмотр — что изменится по группам и языкам,
 * шаблоны, 50 примеров «было → стало», что останется не в норме; кнопка «Применить»; пакеты с откатом.
 * @var App\Core\View $view @var bool $ready @var ?array $report @var array $examples @var array $batches
 */
use App\Core\Seo;
use App\Services\SeoFix;
use App\Controllers\Admin\BaseController;

$fmt = static fn($n) => number_format((int) $n, 0, '', ' ');
$pct = static fn($a, $n) => $n ? number_format($a / $n * 100, $a === $n ? 0 : 1, ',', '') . '%' : '—';
$ent = ['product' => 'Товар', 'category' => 'Категория', 'brand' => 'Бренд', 'page' => 'Страница', 'blog' => 'Статья', 'setting' => 'Настройка'];
$edit = static fn(string $e, int $id): string => match ($e) {
    'product' => '/admin/products/' . $id . '/#h-seo', 'category' => '/admin/categories/' . $id . '/#h-seo', 'brand' => '/admin/brands/' . $id . '/#h-seo',
    'page' => '/admin/pages/' . $id . '/#h-seo', 'blog' => '/admin/blog/' . $id . '/#h-seo', default => '/admin/settings/seo/',
};
$txt = static function (?string $s, string $field = '') use ($fmt): string {
    if ($s === null || trim($s) === '') return '<span class="muted">пусто</span>';
    $n = mb_strlen(trim($s));
    $ok = $field === '' || Seo::inNorm($s, $field === 'title' ? 'title' : 'description');
    return '<span>' . e($s) . '</span> <em' . ($ok ? '' : ' class="warn"') . '>' . $fmt($n) . ' симв.</em>';
};
$lang = static fn(string $l): string => '<span class="seo-lang">' . ($l === 'uk' ? 'UA' : 'RU') . '</span>';
$rulesLine = static function (array $rules) use ($fmt): string {
    $out = [];
    foreach ($rules as $k => $n) $out[] = e(SeoFix::RULES[$k] ?? $k) . ' — ' . $fmt($n);
    return implode('; ', $out);
};
?>
<?php if (!$ready): ?>
  <div class="empty-card"><h2>Нет таблиц журнала</h2><p>Выполните <code>php bin/install.php</code> (database/migrations/seo-autofix.sql) — журнал нужен для отката.</p>
    <a class="btn" href="/admin/seo/">SEO-обзор</a></div>
<?php return; endif; ?>

<div class="card pad-card seo-fix">
  <h2>Что изменится</h2>
  <p class="muted">Норма — как в SEO-обзоре: title <?= Seo::TITLE_MIN ?>–<?= Seo::TITLE_MAX ?>, description <?= Seo::DESC_MIN ?>–<?= Seo::DESC_MAX ?> символов,
    по тому, что выводит сайт (своё значение или результат шаблона), в русской и украинской версии. Своё значение в норме не меняется никогда;
    «машинное» (title = название, description «купить … в Одессе» из выгрузки) очищается — дальше работает шаблон; своё вне нормы дополняется
    или сокращается (title по слову, description по предложению); пустое заполняет шаблон. Шаблоны Webasyst и шаблоны, которые в норме меньше
    чем у 98% объектов, заменяются стандартными. Всё — одним пакетом в журнале, пакет можно откатить.</p>
  <div class="stats">
    <span class="stat"><span><?= $fmt($report['changes']) ?></span>значений изменится</span>
    <?php foreach ($report['rules'] as $k => $n): ?><span class="stat"><span><?= $fmt($n) ?></span><?= e(SeoFix::RULES[$k] ?? $k) ?></span><?php endforeach; ?>
  </div>
  <form method="post" action="/admin/seo/autofix/" class="seo-fix-act" data-confirm="Применить автоисправление: <?= $fmt($report['changes']) ?> значений одним пакетом? Пакет можно откатить.">
    <?= BaseController::tokenField() ?>
    <button class="btn btn-p" type="submit"<?= $report['changes'] ? '' : ' disabled' ?>>Применить</button>
    <a class="btn" href="/admin/seo/autofix/?fresh=1">Пересчитать</a>
    <span class="hint">Предпросмотр посчитан в <?= e(date('H:i', (int) ($report['at'] ?? time()))) ?> за <?= e((string) $report['time']) ?> с.
      «Применить» считает заново на свежих данных (около минуты на весь каталог), потом кэш сайта сбрасывается.</span>
  </form>
</div>

<div class="card">
  <div class="card-hd"><h2>По группам: в норме сейчас → после</h2><a href="/admin/seo/">SEO-обзор</a></div>
  <div class="table-scroll">
    <table class="grid seo-fix-groups">
      <thead><tr><th>Группа</th><th class="right">Адресов</th><th>Title RU</th><th>Description RU</th><th>Title UA</th><th>Description UA</th><th>Изменений</th></tr></thead>
      <tbody>
      <?php foreach ($report['groups'] as $g => $x): if (!$x['stats'] && !$x['changes']) continue; ?>
        <tr>
          <td><b><?= e($x['label']) ?></b></td>
          <td class="right"><?= $fmt($x['objects']) ?></td>
          <?php foreach (['ru' => ['title', 'desc'], 'uk' => ['title', 'desc']] as $l => $fs): foreach ($fs as $f): $s = $x['stats'][$l][$f] ?? null; ?>
            <td><?php if ($s): ?><b><?= $pct($s['before'], $s['total']) ?> → <?= $pct($s['after'], $s['total']) ?></b>
              <small><?= $fmt($s['before']) ?> → <?= $fmt($s['after']) ?> из <?= $fmt($s['total']) ?><?= $s['total'] > $s['after'] ? ' · не в норме: ' . e(implode(', ', array_filter([
                  $s['short'] ? 'короче ' . $fmt($s['short']) : '', $s['long'] ? 'длиннее ' . $fmt($s['long']) : '', $s['none'] ? 'нет ' . $fmt($s['none']) : '']))) : '' ?></small>
            <?php else: ?><span class="muted">—</span><?php endif; ?></td>
          <?php endforeach; endforeach; ?>
          <td><b><?= $fmt($x['changes']) ?></b><?php if ($x['rules']): ?><small><?= $rulesLine($x['rules']) ?></small><?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="pad"><p class="hint">«Сейчас» — что выводит сайт с текущими значениями и шаблонами; товары и статьи считаются по адресам sitemap.xml
    (скрытые не в итогах, но исправляются тоже). Страницы пагинации («| Страница N») не считаются.</p></div>
</div>

<?php if ($report['templates']): ?>
<div class="card">
  <div class="card-hd"><h2>Шаблоны и служебные страницы</h2><a href="/admin/settings/seo/">SEO-шаблоны</a></div>
  <div class="table-scroll">
    <table class="grid seo-table">
      <thead><tr><th>Настройка</th><th>Было</th><th>Станет</th></tr></thead>
      <tbody>
      <?php foreach ($report['templates'] as $k => $t): ?>
        <tr><td class="seo-fld"><code><?= e($k) ?></code><small><?= e($t['why']) ?></small></td>
          <td class="seo-code"><?= $t['old'] !== '' ? '<code>' . e($t['old']) . '</code>' : '<span class="muted">пусто</span>' ?></td>
          <td class="seo-code"><code><?= e($t['new']) ?></code></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<div class="card">
  <div class="card-hd"><h2>Примеры «было → стало»</h2><span class="muted"><?= count($examples) ?> из <?= $fmt($report['changes']) ?></span></div>
  <div class="table-scroll">
    <table class="grid seo-table seo-fix-ex">
      <thead><tr><th>Объект</th><th>Было</th><th>Станет на сайте</th><th>Правило</th></tr></thead>
      <tbody>
      <?php foreach ($examples as $x): $f = $x['field']; ?>
        <tr>
          <td class="seo-page"><b><?= e($ent[$x['entity']] ?? $x['entity']) ?><?= $x['id'] ? ' №' . (int) $x['id'] : '' ?></b>
            <small><?= $x['entity'] === 'setting' ? '<code>' . e($x['name']) . '</code>' : '<a href="' . e($edit($x['entity'], (int) $x['id'])) . '">' . e(mb_substr((string) $x['name'], 0, 60)) . '</a>' ?></small>
            <small><?= $lang($x['lang']) ?> <?= $f === 'title' ? 'Title' : 'Description' ?> · <code><?= e($x['col']) ?></code></small></td>
          <td class="seo-cell seo-wrap"><?= $txt($x['old'], $x['entity'] === 'setting' ? '' : $f) ?></td>
          <td class="seo-cell seo-wrap"><?= $x['new'] === null ? '<span class="muted">пусто → шаблон:</span> ' . $txt($x['shown'], $f) : $txt($x['entity'] === 'setting' ? $x['new'] : $x['shown'], $x['entity'] === 'setting' ? '' : $f) ?></td>
          <td><small><?= e(SeoFix::RULES[$x['rule']] ?? $x['rule']) ?><?= $x['why'] !== '' ? ': ' . e($x['why']) : '' ?></small></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$examples): ?><tr><td colspan="4" class="seo-empty">Исправлять нечего — всё в норме.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if ($report['left']): ?>
<div class="card">
  <div class="card-hd"><h2>Останется не в норме</h2><span class="muted"><?= count($report['left']) >= 200 ? '200+' : count($report['left']) ?></span></div>
  <div class="pad"><p class="hint">Автоматически не исправить (например, своё короткое значение, в котором уже есть «опт», «Одесса» и название магазина) — поправьте вручную.</p></div>
  <div class="table-scroll">
    <table class="grid seo-table">
      <thead><tr><th>Объект</th><th>Что выведет сайт</th></tr></thead>
      <tbody>
      <?php foreach (array_slice($report['left'], 0, 50) as $l): ?>
        <tr><td class="seo-page"><b><a href="<?= e($edit($l['entity'], (int) $l['id'])) ?>"><?= e(mb_substr((string) $l['name'], 0, 60)) ?></a></b>
          <small><?= $lang($l['lang']) ?> <?= $l['field'] === 'title' ? 'Title' : 'Description' ?></small></td>
          <td class="seo-cell seo-wrap"><?= $txt($l['text'], $l['field']) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<div class="card" id="batches">
  <div class="card-hd"><h2>Пакеты</h2><span class="muted">командная строка: <code>php bin/seo-autofix.php --list</code></span></div>
  <div class="table-scroll">
    <table class="grid">
      <thead><tr><th>№</th><th>Когда</th><th>Откуда</th><th class="right">Изменений</th><th>Итог</th><th class="right"></th></tr></thead>
      <tbody>
      <?php foreach ($batches as $b): $sum = $b['summary']; $rv = $b['revert']; ?>
        <tr class="<?= $b['reverted_at'] ? 'is-draft' : '' ?>">
          <td><b><?= (int) $b['id'] ?></b></td>
          <td><?= e(date('d.m.Y H:i', strtotime((string) $b['created_at']))) ?></td>
          <td><?= e(['admin' => 'админка', 'cli' => 'командная строка', 'import' => 'перенос с Webasyst', 'i18n' => 'сид переводов'][$b['source']] ?? $b['source']) ?>
            <?= $b['user_name'] ? '<small>' . e($b['user_name']) . '</small>' : '' ?><small><?= e(str_replace(',', ', ', (string) $b['parts'])) ?></small></td>
          <td class="right"><?= $fmt($b['changes']) ?></td>
          <td><small><?= $rulesLine((array) ($sum['rules'] ?? [])) ?></small>
            <?php if ($b['reverted_at']): ?><small>Откачен <?= e(date('d.m.Y H:i', strtotime((string) $b['reverted_at']))) ?>: возвращено <?= $fmt($rv['restored'] ?? 0) ?>,
              пропущено <?= $fmt($rv['skipped'] ?? 0) ?><?php if (!empty($rv['skipped_list'])): ?>
              (<?= e(implode(', ', array_map(static fn($s) => ($s['entity'] ?? '') . ' №' . ($s['id'] ?? 0) . ' ' . ($s['field'] ?? ''), array_slice($rv['skipped_list'], 0, 10)))) ?><?= count($rv['skipped_list']) > 10 ? '…' : '' ?>)<?php endif; ?></small><?php endif; ?></td>
          <td class="right">
            <?php if (!$b['reverted_at']): ?>
              <form method="post" action="/admin/seo/autofix/<?= (int) $b['id'] ?>/revert/" class="inline"
                    data-confirm="Откатить пакет № <?= (int) $b['id'] ?>? Вернутся прежние значения (<?= $fmt($b['changes']) ?>), кроме изменённых после пакета.">
                <?= BaseController::tokenField() ?><button class="btn btn-sm btn-d" type="submit">Откатить</button></form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$batches): ?><tr><td colspan="6" class="seo-empty">Пакетов ещё нет.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
