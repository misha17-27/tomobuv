<?php
/**
 * Вся история товара: даты и последние события всех журналов (ProductHistory::events).
 * @var array $p @var array $origin @var ?array $last @var array $hist ['items', 'more'] @var int $cap
 */
?>
<div class="two-col ac-hist">
  <div class="card">
    <div class="card-hd"><h2>История изменений</h2><a href="/admin/products/<?= (int) $p['id'] ?>/">К товару</a></div>
    <div class="pad">
      <?= $view->partial('admin/products/events', ['events' => $hist['items']]) ?>
      <?php if ($hist['more']): ?><p class="hint">Показаны последние <?= (int) $cap ?> событий.</p><?php endif; ?>
    </div>
  </div>
  <aside>
    <section class="card ac-dates" aria-labelledby="h-dates">
      <h2 id="h-dates">Даты</h2>
      <?= $view->partial('admin/products/dates', ['full' => true]) ?>
      <p class="hint">Журналы: правки в админке и массовые действия, импорт прайсов, загрузчики поставщиков, автоназвание, автоисправление SEO.
        Дата изменения сдвигается только реальными изменениями данных товара (автоисправление SEO её не сдвигает).</p>
    </section>
  </aside>
</div>
