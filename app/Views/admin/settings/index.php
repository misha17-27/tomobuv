<?php
/**
 * Настройки сайта: вкладки. Содержимое вкладки — в settings/_<tab>.php.
 * Менеджер видит значения в режиме просмотра (fieldset disabled), сохраняет только администратор.
 * @var string $tab @var array $tabs @var bool $canEdit @var array $errors
 */
use App\Controllers\Admin\BaseController;
use App\Controllers\Admin\SettingsController;
?>
<nav class="subtabs" aria-label="Разделы настроек">
  <?php foreach ($tabs as $k => $label): ?>
    <a href="<?= e(SettingsController::tabUrl($k)) ?>" class="<?= $tab === $k ? 'on' : '' ?>"<?= $tab === $k ? ' aria-current="page"' : '' ?>><?= e($label) ?></a>
  <?php endforeach; ?>
  <span class="st-other" aria-hidden="true"></span>
  <a href="/admin/mail/" class="st-ext" title="Отдельный экран: SMTP, тестовое письмо">Почта (SMTP) →</a>
  <a href="/admin/whatsapp/" class="st-ext" title="Отдельный экран: уведомления о заказах в WhatsApp">WhatsApp →</a>
</nav>
<?php if (!$canEdit): ?><div class="flash warn">Изменять настройки может только администратор — у вас режим просмотра.</div><?php endif; ?>
<?php if ($errors): ?><div class="flash bad">Не сохранено — исправьте ошибки: <?= e(implode('; ', array_unique($errors))) ?></div><?php endif; ?>

<form method="post" action="<?= e(SettingsController::tabUrl($tab)) ?>" class="ed-form st-form" novalidate>
  <?= BaseController::tokenField() ?>
  <fieldset class="st-fs"<?= $canEdit ? '' : ' disabled' ?>>
    <legend class="sr"><?= e($tabs[$tab]) ?></legend>
    <?= $view->partial('admin/settings/_' . $tab) ?>
  </fieldset>
  <?php if ($canEdit): ?>
  <div class="savebar"><button class="btn btn-p" type="submit">Сохранить</button><a class="btn" href="<?= e(SettingsController::tabUrl($tab)) ?>">Отменить изменения</a>
    <span class="hint hide-sm">Изменения видны на сайте сразу после сохранения.</span></div>
  <?php endif; ?>
</form>
