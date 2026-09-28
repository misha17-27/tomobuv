<?php
/** @var string $error @var string $login */
use App\Controllers\Admin\BaseController;
?><!DOCTYPE html>
<html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex, nofollow">
<title>Вход в админку — Tomobuv</title><link rel="icon" href="/favicon.ico"><link rel="stylesheet" href="<?= asset('admin/admin.css') ?>"></head>
<body class="bare">
<main class="auth">
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
</main>
</body></html>
