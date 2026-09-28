<?php
use App\Core\Settings;
use App\Services\Catalog;
use App\Services\Content;

$phones = Settings::json('phones', []);
$since = (string) Settings::get('since_year', '2011');
?>
<footer><div class="wrap">
  <div class="cols">
    <div><a class="logo" href="/"><img src="/assets/img/logo.png" alt="Tomobuv" width="154" height="40" loading="lazy"></a>
      <p><?= e(t('Оптовый интернет-магазин обуви. Работаем с {year} года. Большой ассортимент, отличное качество, приятные цены.', ['year' => $since])) ?></p>
      <div class="soc">
        <?php foreach (['telegram' => 'tg', 'viber' => 'viber', 'instagram' => 'ig', 'facebook' => 'fb'] as $k => $ic): if ($u = Settings::get('social_' . $k)): ?>
          <a href="<?= e($u) ?>" target="_blank" rel="noopener" aria-label="<?= e(ucfirst($k)) ?>"><?= icon($ic, 'width:18px') ?></a>
        <?php endif; endforeach; ?>
      </div>
    </div>
    <div><h2><?= e(t('Компания')) ?></h2><ul>
      <?php foreach (Content::menuPages() as $p): ?><li><a href="/<?= e($p['url']) ?>"><?= e($p['name']) ?></a></li><?php endforeach; ?>
      <li><a href="/brand/"><?= e(t('Бренды')) ?></a></li><li><a href="/blog/"><?= e(t('Блог')) ?></a></li><li><a href="/reviews/"><?= e(t('Отзывы')) ?></a></li>
    </ul></div>
    <div><h2><?= e(t('Каталог')) ?></h2><ul>
      <?php foreach (Catalog::roots() as $c): ?><li><a href="<?= e(Catalog::categoryUrl($c)) ?>"><?= e(nice_case($c['name'])) ?></a></li><?php endforeach; ?>
    </ul></div>
    <div><h2><?= e(t('Контакты')) ?></h2><ul>
      <?php foreach ($phones as $p): ?><li><a class="ph" href="tel:+<?= e(preg_replace('/\D/', '', $p)) ?>"><?= e($p) ?></a></li><?php endforeach; ?>
      <li><?= e(Settings::get('work_hours', '')) ?></li>
      <li><a href="mailto:<?= e(Settings::get('store_email', '')) ?>"><?= e(Settings::get('store_email', '')) ?></a></li>
      <li><?= e(Settings::get('address', '')) ?></li>
      <li><a href="/kontakty/" style="color:#6fc2f0"><?= e(t('Карта проезда')) ?> →</a></li>
    </ul></div>
  </div>
  <div class="bottom"><span>© Tomobuv.com.ua, <?= e($since) ?>–<?= date('Y') ?></span><span><?= e(t('Оптовый интернет-магазин обуви в Одессе')) ?></span></div>
</div></footer>
