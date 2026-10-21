# Деплой на cPanel из GitHub (без терминала)

Код берётся только из GitHub: `https://github.com/misha17-27/tomobuv.git`. Сайт работает прямо из папки репозитория
`~/repositories/tomobuv` — `public_html` становится ссылкой на её `public/`, код и настройки снаружи не видны.

## Первый раз

1. **Git Version Control → Create**: «Clone a Repository» — включено, Clone URL — адрес выше,
   Repository Path — `repositories/tomobuv`. Папка должна быть пустой или отсутствовать.
2. **MySQL Databases**: новая база и пользователь с правами на неё (для старой базы Webasyst — отдельная база, см. ниже).
3. **Git Version Control → Manage → Pull or Deploy → Deploy HEAD Commit**. Первый деплой (`bin/deploy.sh`):
   - создаёт `config/config.php` из образца со своим `app_key`;
   - если `public_html` пустой (только `cgi-bin`, `.well-known`, страница-заглушка) — переименовывает его в
     `public_html.before-tomobuv-<дата>` и ставит на его место ссылку на `repositories/tomobuv/public`.
4. **File Manager → repositories/tomobuv/config/config.php**: впишите доступ к базе (`db`), к старой базе
   (`webasyst_db`), `base_url` → `https://tomobuv.com.ua`, `debug` → `false`.
5. Ещё раз **Deploy HEAD Commit** — создаются таблицы (`bin/install.php`).
6. Перенос со старого сайта, админ, переводы — разовыми заданиями **Cron Jobs** (см. ниже), результат — в файлах журналов.

Журнал деплоя: `repositories/tomobuv/storage/logs/deploy.log`. Путь к PHP для консоли деплой записывает в
`storage/php-cli-path`.

## Обновления

- Вручную: **Pull or Deploy → Update from Remote**, затем **Deploy HEAD Commit** (миграции и сброс кэша).
- Автоматически: **Cron Jobs** раз в 15 минут:
  `/bin/bash /home/tomobuvcom/repositories/tomobuv/bin/auto-update.sh`
  (есть новые коммиты — забирает и деплоит; если файлы репозитория меняли на сервере — пропускает с записью в журнал).

Файлы, которые пишет сам сайт или хостинг (`config/config.php`, `storage/`, `public/wa-data/`, `public/uploads/`,
`public/.well-known/`, `error_log`), в git не попадают — деплой они не блокируют.
Версию PHP для домена в **MultiPHP Manager** без нужды не меняйте: cPanel дописывает обработчик в `public/.htaccess`,
это «изменённый файл» репозитория — тогда Deploy и автообновление откажутся работать. Нужна версия по умолчанию 8.1+.

## Разовые команды без терминала

Cron Jobs → задание «раз в 5 минут» с командой, через 5–6 минут — удалить задание, журнал открыть в File Manager.
`PHP` — путь из `storage/php-cli-path` (например `/opt/cpanel/ea-php83/root/usr/bin/php`):

```
cd ~/repositories/tomobuv && PHP bin/import-webasyst.php > ~/import.log 2>&1
cd ~/repositories/tomobuv && PHP bin/i18n-seed-uk.php > ~/seed.log 2>&1
cd ~/repositories/tomobuv && PHP bin/create-admin.php you@mail.com 'Пароль_от_10_символов' admin > ~/admin.log 2>&1
```

Постоянный cron сайта (раз в час): `cd ~/repositories/tomobuv && PHP bin/cron.php > /dev/null 2>&1`.
