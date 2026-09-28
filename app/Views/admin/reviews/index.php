<?php
/**
 * Отзывы о товарах и о магазине.
 * @var string $type @var string $status @var array $rows @var App\Core\Paginator $pg @var int $total @var array $pc @var array $sc @var array $products
 */
use App\Controllers\Admin\BaseController;
use App\Controllers\Admin\ReviewsController as RC;

$isProduct = $type === 'product';
$statuses = $isProduct ? RC::PRODUCT_STATUSES : RC::STORE_STATUSES;
$counts = $isProduct ? $pc : $sc;
$stars = static function (int $n): string {
    $n = max(0, min(5, $n));
    return '<span class="sl-stars" aria-label="Оценка ' . $n . ' из 5">' . str_repeat('★', $n) . '<i>' . str_repeat('★', 5 - $n) . '</i></span>';
};
$pendingProduct = (int) ($pc['moderation'] ?? 0);
$pendingStore = (int) ($sc[0] ?? 0);
?>
<nav class="tabs" aria-label="Тип отзывов">
  <a href="/admin/reviews/" class="<?= $isProduct ? 'on' : '' ?>">Отзывы о товарах <i class="<?= $pendingProduct ? 'sl-hot-cnt' : '' ?>"><?= $pendingProduct ?: array_sum($pc) - (int) ($pc['deleted'] ?? 0) ?></i></a>
  <a href="/admin/reviews/?type=store" class="<?= !$isProduct ? 'on' : '' ?>">Отзывы о магазине <i class="<?= $pendingStore ? 'sl-hot-cnt' : '' ?>"><?= $pendingStore ?: array_sum($sc) ?></i></a>
</nav>
<div class="sl-chips">
  <?php foreach ($statuses as $k => $label): $k = (string) $k; ?>
    <a class="chip<?= $status === $k ? ' on' : '' ?>" href="/admin/reviews/?<?= e(http_build_query(['type' => $isProduct ? null : 'store', 'status' => $k])) ?>"><?= e($label) ?> <b><?= (int) ($counts[$k] ?? 0) ?></b></a>
  <?php endforeach; ?>
  <a class="chip<?= $status === 'all' ? ' on' : '' ?>" href="/admin/reviews/?<?= e(http_build_query(['type' => $isProduct ? null : 'store', 'status' => 'all'])) ?>">Все</a>
</div>
<p class="sl-found muted">Найдено: <b><?= $total ?></b><?= $pg->pages > 1 ? ' · страница ' . $pg->page . ' из ' . $pg->pages : '' ?>
  <?= $isProduct ? '' : ' · <a href="/reviews/" target="_blank" rel="noopener">страница отзывов на сайте</a>' ?></p>

<?php if (!$rows): ?>
  <div class="card empty-card"><h2><?= $status === 'moderation' || $status === '0' ? 'Нет отзывов, ожидающих модерации.' : 'Отзывов нет.' ?></h2><p>Отзывы покупателей с сайта появятся здесь.</p></div>
<?php endif; ?>

<?php foreach ($rows as $r):
  $rid = (int) $r['id'];
  $st = (string) $r['status'];
  $published = $isProduct ? $st === 'approved' : (int) $st === 1;
  $pending = $isProduct ? $st === 'moderation' : !$published;
  $rate = (int) ($isProduct ? $r['rate'] : $r['rating']);
  $p = $isProduct ? ($products[(int) $r['product_id']] ?? null) : null;
  $action = '/admin/reviews/' . $type . '/' . $rid . '/';
  $stLabel = $published ? 'Опубликован' : ($isProduct ? ($st === 'moderation' ? 'На модерации' : ($st === 'hidden' ? 'Скрыт' : $st)) : 'Не опубликован'); ?>
  <article class="card sl-rev<?= $pending ? ' sl-rev-mod' : '' ?>">
    <div class="sl-rev-h">
      <b><?= e($r['name'] ?: 'Без имени') ?></b>
      <?php if ($rate > 0): ?><?= $stars($rate) ?><?php endif; ?>
      <span class="st <?= $published ? 'st-completed' : ($pending ? 'st-processing' : 'st-deleted') ?>"><?= e($stLabel) ?></span>
      <time class="muted"><?= e(date('d.m.Y H:i', strtotime((string) $r['created_at']))) ?></time>
      <?php if ($r['email']): ?><a class="muted" href="mailto:<?= e($r['email']) ?>"><?= e($r['email']) ?></a><?php endif; ?>
      <?php if (($r['lang'] ?? 'ru') === 'uk'): ?><span class="badge sl-lang sl-lang-uk" title="Оставлен на украинской версии сайта">UA</span><?php endif; ?>
      <?php if (!empty($r['ip'])): ?><span class="muted">IP <?= e($r['ip']) ?></span><?php endif; ?>
    </div>
    <?php if ($isProduct): ?>
      <div class="sl-rev-prod">
        <?php if ($p): ?><img src="<?= e($p['img']) ?>" alt="" width="40" height="40" loading="lazy">
          <span><a href="/product/<?= e($p['url']) ?>/reviews/" target="_blank" rel="noopener"><?= e($p['name']) ?></a>
            <span class="muted"> · <a href="/admin/products/<?= (int) $p['id'] ?>/">в админке</a><?= (int) $p['rating_count'] ? ' · рейтинг ' . e((string) round((float) $p['rating'], 1)) . ' (' . (int) $p['rating_count'] . ')' : '' ?></span></span>
        <?php else: ?><span class="muted">Товар #<?= (int) $r['product_id'] ?> удалён</span><?php endif; ?>
      </div>
      <?php if (!empty($r['title'])): ?><div class="sl-rev-title"><?= e($r['title']) ?></div><?php endif; ?>
    <?php endif; ?>
    <div class="sl-rev-text"><?= nl2br(e($r['text'])) ?></div>
    <?php if (!empty($r['response'])): ?><div class="sl-resp"><b>Ответ магазина<?= !empty($r['response_at']) ? ' · ' . e(date('d.m.Y', strtotime((string) $r['response_at']))) : '' ?></b><?= nl2br(e($r['response'])) ?></div><?php endif; ?>
    <div class="sl-rev-f">
      <?php if (!$published): ?>
        <form method="post" action="<?= e($action) ?>"><?= BaseController::tokenField() ?><input type="hidden" name="action" value="approve">
          <button class="btn btn-sm btn-p" type="submit"><?= icon('check') ?> Опубликовать</button></form>
      <?php else: ?>
        <form method="post" action="<?= e($action) ?>"><?= BaseController::tokenField() ?><input type="hidden" name="action" value="hide">
          <button class="btn btn-sm" type="submit"><?= icon('eye') ?> Скрыть</button></form>
      <?php endif; ?>
      <form method="post" action="<?= e($action) ?>" data-confirm="Удалить отзыв навсегда?"><?= BaseController::tokenField() ?><input type="hidden" name="action" value="delete">
        <button class="btn btn-sm btn-d" type="submit" aria-label="Удалить отзыв"><?= icon('trash') ?> Удалить</button></form>
    <details>
      <summary class="btn btn-sm"><?= icon('mail') ?> <?= !empty($r['response']) ? 'Изменить ответ' : 'Ответить' ?></summary>
      <form method="post" action="<?= e($action) ?>">
        <?= BaseController::tokenField() ?><input type="hidden" name="action" value="reply">
        <label class="fld"><span>Ответ магазина (публикуется под отзывом; пусто — удалить ответ)</span>
          <textarea name="response" rows="3" maxlength="5000"><?= e($r['response'] ?? '') ?></textarea></label>
        <div><button class="btn btn-p btn-sm" type="submit">Сохранить ответ</button></div>
      </form>
    </details>
    </div>
  </article>
<?php endforeach; ?>
<?= $pg->html() ?>
