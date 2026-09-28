<div class="wrap" style="padding-top:40px;padding-bottom:20px">
  <div class="empty-state">
    <div class="ic"><?= icon('search', 'width:30px;height:30px') ?></div>
    <h1 style="font-size:26px;margin-bottom:6px"><?= e(t('Страница не найдена')) ?></h1>
    <p><?= e(t('Возможно, товар снят с продажи или адрес введён с ошибкой. Попробуйте поиск или перейдите в каталог.')) ?></p>
    <form action="/search/" method="get" style="display:flex;gap:8px;max-width:460px;margin:0 auto 16px"><input class="input" name="query" placeholder="<?= e(t('Артикул или название')) ?>" aria-label="<?= e(t('Поиск')) ?>"><button class="btn btn-b"><?= e(t('Найти')) ?></button></form>
    <a class="btn btn-o" href="/"><?= e(t('На главную')) ?></a>
  </div>
</div>
