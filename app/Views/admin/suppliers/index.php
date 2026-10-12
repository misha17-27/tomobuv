<?php
/**
 * «Каталог → Поставщики»: список поставщиков с автозагрузкой (Suppliers::all) — состояние, последний запуск, товары на сайте.
 * @var array $list [код => ['cls', 'title', 'about', 'enabled', 'on', 'due', 'next', 'last', 'active', 'products']]
 */
$fmt = static fn($n) => number_format((int) $n, 0, '', ' ');
?>
<div class="sup-list">
<?php foreach ($list as $code => $s): $cls = $s['cls']; $last = $s['last']; ?>
  <div class="card sup-item">
    <div class="card-hd"><h2><a href="/admin/suppliers/<?= e($code) ?>/"><?= e($s['title']) ?></a></h2>
      <span class="st <?= $s['enabled'] ? 'st-completed' : 'st-deleted' ?>"><?= $s['enabled'] ? 'Загрузка включена' : ($s['on'] ? 'Включена, но не готова' : 'Выключена') ?></span></div>
    <div class="pad">
      <p class="hint sup-about"><?= e($s['about']) ?></p>
      <dl class="detail jg-facts">
        <div><dt>Товаров на сайте</dt><dd><?= $fmt($s['products']['active']) ?> активных из <?= $fmt($s['products']['total']) ?></dd></div>
        <div><dt>Последний запуск</dt><dd><?php if ($last): ?><a href="/admin/suppliers/<?= e($code) ?>/runs/<?= (int) $last['id'] ?>/">№<?= (int) $last['id'] ?></a> ·
          <?= e(date('d.m.Y H:i', strtotime((string) $last['started_at']))) ?> · <?= e(mb_strtolower($cls::KINDS[$last['kind']] ?? $last['kind'])) ?> · <?= e(mb_strtolower($cls::STATUSES[$last['status']] ?? $last['status'])) ?><?php else: ?>запусков ещё не было<?php endif; ?></dd></div>
        <div><dt>Расписание</dt><dd><?= $s['enabled'] ? ($s['next'] ? 'следующий запуск — около ' . e(date('d.m H:i', (int) $s['next'])) : e($s['due'][1])) : e($s['due'][1]) ?></dd></div>
        <?php if ($s['active']): ?><div><dt>Сейчас</dt><dd><a href="/admin/suppliers/<?= e($code) ?>/runs/<?= (int) $s['active']['id'] ?>/">идёт <?= e(mb_strtolower($cls::KINDS[$s['active']['kind']] ?? '')) ?> №<?= (int) $s['active']['id'] ?></a></dd></div><?php endif; ?>
      </dl>
      <a class="btn btn-p" href="/admin/suppliers/<?= e($code) ?>/">Открыть</a>
    </div>
  </div>
<?php endforeach; ?>
</div>
<p class="hint">Загрузка по расписанию — из <code>bin/cron.php</code> (раз в час), у каждого поставщика своё окно часов и период. Пробный прогон ничего не пишет в каталог.</p>
