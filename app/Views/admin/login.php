<?php
/**
 * Вход в админку; с $logout = true — подтверждение выхода (/admin/logout/ открыли без токена).
 * @var string $error @var string $login @var ?bool $logout @var ?bool $stale @var ?array $user
 */
use App\Controllers\Admin\BaseController;

$logout = !empty($logout);
?><!DOCTYPE html>
<html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex, nofollow">
<title><?= $logout ? 'Выход из админки' : 'Вход в админку' ?> — Tomobuv</title><link rel="icon" href="/favicon.ico"><link rel="stylesheet" href="<?= asset('admin/admin.css') ?>"></head>
<body class="bare">
<main class="auth">
<?php if ($logout): ?>
  <form method="post" action="/admin/logout/" class="auth-box">
    <img src="/assets/img/logo.png" alt="Tomobuv" width="150" height="39">
    <h1>Выйти из админки?</h1>
    <?php if (!empty($stale)): ?><div class="flash bad" role="alert">Сессия устарела — нажмите «Выйти» ещё раз.</div><?php endif; ?>
    <?php $who = (string) (($user['name'] ?? '') ?: ($user['email'] ?? '')); if ($who !== ''): ?><p class="hint">Вы вошли как <b><?= e($who) ?></b>.</p><?php endif; ?>
    <?= BaseController::tokenField() ?>
    <button class="btn btn-p">Выйти</button>
    <p class="hint" style="margin-top:14px"><a href="/admin/">Вернуться в админку</a></p>
  </form>
<?php else: ?>
  <form method="post" class="auth-box" autocomplete="on">
    <img src="/assets/img/logo.png" alt="Tomobuv" width="150" height="39">
    <h1>Вход в админку</h1>
    <?php if ($error): ?><div class="flash bad" role="alert"><?= e($error) ?></div><?php endif; ?>
    <?= BaseController::tokenField() ?>
    <label class="fld"><span>E-mail или телефон</span><input name="login" value="<?= e($login) ?>" required autofocus autocomplete="username"></label>
    <label class="fld"><span>Пароль</span><input name="password" type="password" required autocomplete="current-password"></label>
    <button class="btn btn-p">Войти</button>
    <p class="hint" style="margin-top:14px">Пароль тот же, что был в админке Webasyst. Нет доступа — на сервере: <code>php bin/create-admin.php e-mail пароль</code></p>
  </form>
<?php endif; ?>
</main>
</body></html>
