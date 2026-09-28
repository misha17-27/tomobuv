<?php
/**
 * «Заказ оформлен» — /order/success/?id=…&k=… (только по подписанной ссылке: после оформления и из письма).
 * Заказ старше часа ($fresh = false) — нейтральный заголовок «Заказ #…» и текущий статус вместо «Спасибо».
 * @var array $order Orders::find() @var bool $fresh @var ?array $user @var array $phones @var int $free @var View $view
 */
use App\Services\Orders;

$o = $order;
$statuses = ['new' => t('Новый'), 'processing' => t('В обработке'), 'paid' => t('Оплачен'), 'shipped' => t('Отправлен'),
    'completed' => t('Выполнен'), 'refunded' => t('Возврат'), 'deleted' => t('Отменён')];
$pl = static fn(int $n, string $one, string $few, string $many): string => t(plural($n, $one, $few, $many));
$pickup = !empty($o['params']['pickup']);
$shipCost = $o['shipping_method'] === null ? t('уточнит менеджер') : ($pickup || (int) $o['boxes'] >= $free ? t('бесплатно') : t('по тарифам перевозчика'));
$phone = $phones[0] ?? '';
$address = (string) preg_replace('/^Когда заберут: /u', '', (string) $o['address']);
$info = array_filter([
    t('Получатель') => (string) $o['name'],
    t('Телефон') => Orders::formatPhone($o['phone']),
    'E-mail' => (string) $o['email'],
    t('Доставка') => $o['shipping_title'],
    t('Область') => $o['region_name'],
    t('Город') => (string) $o['city'],
    ($pickup ? t('Когда заберёте') : t('Отделение / адрес')) => $address,
    t('Оплата') => $o['payment_title'],
    t('Промокод') => $o['coupon_code'],
    t('Комментарий') => (string) $o['comment'],
], static fn($v) => $v !== '' && $v !== null);
$lines = count($o['items']);
$fresh = $fresh ?? true;
$status = $statuses[$o['status']] ?? $o['status'];
$date = date('d.m.Y H:i', strtotime((string) $o['created_at']));
$own = $user && (int) $user['id'] === (int) $o['customer_id'];
?>
<div class="wrap co-page">
  <div class="page-head">
    <?= $view->partial('front/partials/crumbs', ['items' => [['name' => $fresh ? t('Заказ оформлен') : t('Заказ {n}', ['n' => $o['number']])]]]) ?>
    <h1><?= e($fresh ? t('Спасибо! Заказ {n} оформлен', ['n' => $o['number']]) : t('Заказ {n}', ['n' => $o['number']])) ?></h1>
  </div>
  <div class="layout-2r co">
    <div class="co-main">
      <?php if ($fresh): ?>
      <div class="panel co-done">
        <span class="co-done-ic" aria-hidden="true"><?= icon('check', 'width:28px;height:28px') ?></span>
        <div>
          <p class="co-done-t"><?= e(t('Ваш заказ успешно оформлен. Мы свяжемся с вами в ближайшее время.')) ?></p>
          <p><?= t('Номер вашего заказа {n} от {date}.', ['n' => '<b>' . e($o['number']) . '</b>', 'date' => e($date)]) ?>
            <?php if (!empty($o['email'])): ?><?= t('Подтверждение и состав заказа отправлены на {email}.', ['email' => '<b>' . e($o['email']) . '</b>']) ?><?php endif; ?></p>
        </div>
      </div>
      <?php else: ?>
      <div class="panel co-done co-state">
        <div>
          <p class="co-done-t"><?= t('Статус заказа: {status}', ['status' => '<span class="co-status st-' . e($o['status']) . '">' . e($status) . '</span>']) ?></p>
          <p><?= t('Заказ {n} оформлен {date}.', ['n' => '<b>' . e($o['number']) . '</b>', 'date' => e($date)]) ?>
            <?= $own ? t('Все ваши заказы — в {link}.', ['link' => '<a class="link" href="/my/orders/">' . e(t('личном кабинете')) . '</a>']) : e(t('Чтобы узнать подробности, позвоните нам и назовите номер заказа.')) ?></p>
        </div>
      </div>
      <?php endif; ?>

      <section class="panel co-sec" aria-labelledby="os-h1">
        <div class="co-sec-h"><h2 id="os-h1"><?= e(t('Состав заказа')) ?></h2><span class="co-lines muted"><?= $lines ?> <?= e($pl($lines, 'позиция', 'позиции', 'позиций')) ?></span></div>
        <div class="tblwrap co-tblwrap">
          <table class="tbl co-tbl co-tbl-ro">
            <thead><tr>
              <th scope="col"><span class="visually-hidden"><?= e(t('Фото')) ?></span></th><th scope="col"><?= e(t('Товар')) ?></th>
              <th scope="col" class="num c-hide"><?= e(t('Пар в ящике')) ?></th><th scope="col" class="num c-hide"><?= e(t('Цена за пару')) ?></th>
              <th scope="col" class="num"><?= e(t('Ящиков')) ?></th><th scope="col" class="num"><?= e(t('Сумма')) ?></th>
            </tr></thead>
            <tbody>
            <?php foreach ($o['items'] as $i): ?>
              <tr>
                <td class="c-img"><?php if ($i['link']): ?><a href="<?= e($i['link']) ?>" tabindex="-1" aria-hidden="true"><img src="<?= e($i['img']) ?>" alt="" width="64" height="64" loading="lazy"></a><?php else: ?><img src="<?= e($i['img']) ?>" alt="" width="64" height="64" loading="lazy"><?php endif; ?></td>
                <td class="c-name">
                  <?php if ($i['link']): ?><a class="co-nm" href="<?= e($i['link']) ?>"><?= e($i['name']) ?></a><?php else: ?><span class="co-nm"><?= e($i['name']) ?></span><?php endif; ?>
                  <div class="co-attr"><?php if ($i['size'] !== ''): ?><span><?= e(t('Размеры:')) ?> <b><?= e($i['size']) ?></b></span><?php endif; ?><?php if ($i['brand'] !== ''): ?><span><?= e($i['brand']) ?></span><?php endif; ?></div>
                  <div class="co-meta"><span><?= e(t('{n} {pairs} в ящике', ['n' => (int) $i['box_qty'], 'pairs' => $pl((int) $i['box_qty'], 'пара', 'пары', 'пар')])) ?></span> · <span><?= price_html($i['price']) ?> / <?= e(t('пара')) ?></span></div>
                </td>
                <td class="num c-hide"><?= (int) $i['box_qty'] ?></td>
                <td class="num c-hide"><?= price_html($i['price']) ?></td>
                <td class="num c-boxes"><b><?= (int) $i['boxes'] ?></b> <?= e(t('ящ.')) ?><small><?= (int) $i['quantity'] ?> <?= e($pl((int) $i['quantity'], 'пара', 'пары', 'пар')) ?></small></td>
                <td class="num c-sum"><b><?= price_html($i['sum']) ?></b></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </section>

      <section class="panel co-sec" aria-labelledby="os-h2">
        <div class="co-sec-h"><h2 id="os-h2"><?= e(t('Получатель и доставка')) ?></h2></div>
        <dl class="co-dl">
          <?php foreach ($info as $k => $v): ?><div><dt><?= e($k) ?></dt><dd><?= nl2br(e($v)) ?></dd></div><?php endforeach; ?>
        </dl>
      </section>
    </div>

    <aside class="co-side" aria-label="<?= e(t('Итоги заказа')) ?>">
      <div class="panel summary sticky co-sum">
        <h2><?= e(t('Заказ {n}', ['n' => $o['number']])) ?></h2>
        <div class="line"><span><?= e(t('Статус')) ?></span><span class="co-status st-<?= e($o['status']) ?>"><?= e($status) ?></span></div>
        <div class="line"><span><?= e(t('Ящиков')) ?></span><b><?= (int) $o['boxes'] ?></b></div>
        <div class="line"><span><?= e(t('Пар')) ?></span><b><?= (int) $o['pairs'] ?></b></div>
        <div class="line"><span><?= e(t('Товары на сумму')) ?></span><?= price_html($o['subtotal'], 'b') ?></div>
        <div class="line"><span><?= e(t('Доставка')) ?></span><span><?= e($shipCost) ?></span></div>
        <?php if ($o['discount'] > 0): ?><div class="line co-disc"><span><?= e(t('Скидка')) ?><?= $o['coupon_code'] !== '' ? ' <small>' . e($o['coupon_code']) . '</small>' : '' ?></span><b class="co-disc-v">−<?= price_html($o['discount']) ?></b></div><?php endif; ?>
        <div class="line total"><span><?= e(t('Итого')) ?></span><?= price_html($o['total']) ?></div>
        <a class="btn btn-o btn-block co-submit" href="/"><?= e(t('Продолжить покупки')) ?></a>
        <?php if ($own): ?><a class="btn btn-g btn-block btn-sm co-quick" href="/my/orders/"><?= e(t('Мои заказы')) ?></a><?php endif; ?>
        <?php if ($phone !== ''): ?><p class="co-help"><?= t('Вопросы по заказу: {phone}. Назовите номер заказа {n}.', [
            'phone' => '<a href="tel:+' . e(preg_replace('/\D/', '', $phone)) . '">' . e($phone) . '</a>', 'n' => e($o['number'])]) ?></p><?php endif; ?>
      </div>
    </aside>
  </div>
</div>
