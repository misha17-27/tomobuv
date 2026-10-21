#!/bin/bash
# Деплой на хостинг без терминала (cPanel): вызывается из .cpanel.yml («Deploy HEAD Commit») и bin/auto-update.sh.
#   1) PHP 8.1+ для консоли (ea-php8x или php из PATH; свой — переменная PHP_BIN);
#   2) нет config/config.php — создаётся из config.example.php со случайным app_key: дальше владелец вписывает
#      доступ к базе в File Manager и снова жмёт Deploy;
#   3) php bin/install.php (миграции, повторный запуск безопасен) и bin/cache-clear.php;
#   0) блок версии PHP, который cPanel MultiPHP дописывает в public/.htaccess, сохраняется (bin/deploy-lib.sh);
#   4) первый деплой: если ~/public_html пустой (только cgi-bin, .well-known, страница-заглушка хостинга),
#      он переименовывается в public_html.before-tomobuv-<дата> и заменяется ссылкой на public/ репозитория.
#      Непустой public_html не трогается — только сообщение в журнале.
# Журнал: storage/logs/deploy.log и вывод деплоя cPanel.
set -u
REPO="$(cd "$(dirname "$0")/.." && pwd)"
cd "$REPO" || exit 1
mkdir -p storage/logs storage/cache storage/sessions storage/import storage/uploads 2>/dev/null
LOG="$REPO/storage/logs/deploy.log"
log() { echo "$*" | tee -a "$LOG"; }

log "=== $(date '+%Y-%m-%d %H:%M:%S') деплой $(git log --oneline -1 2>/dev/null)"
# shellcheck source=bin/deploy-lib.sh
source "$REPO/bin/deploy-lib.sh"
keep_cpanel_files   # блок версии PHP от cPanel в public/.htaccess не должен блокировать Deploy

# 1. PHP 8.1+ для консоли
PHP="${PHP_BIN:-}"
if [ -z "$PHP" ]; then
  for c in /opt/cpanel/ea-php84/root/usr/bin/php /opt/cpanel/ea-php83/root/usr/bin/php /opt/cpanel/ea-php82/root/usr/bin/php \
           /opt/cpanel/ea-php81/root/usr/bin/php /usr/local/bin/php /usr/bin/php php; do
    if command -v "$c" >/dev/null 2>&1 && "$c" ${PHP_OPTS:-} -r 'exit(PHP_VERSION_ID >= 80100 ? 0 : 1);' >/dev/null 2>&1; then PHP="$c"; break; fi
  done
fi
if [ -z "$PHP" ]; then log "ОШИБКА: не найден PHP 8.1+ для консоли (проверены ea-php81…84, /usr/local/bin/php, php)"; exit 1; fi
run_php() { "$PHP" ${PHP_OPTS:-} "$@"; }
log "PHP: $PHP ($(run_php -r 'echo PHP_VERSION;'))"
echo "$PHP" > storage/php-cli-path   # путь для команд cron (см. docs/DEPLOY-CPANEL.md)

# 2. config.php
if [ ! -f config/config.php ]; then
  run_php -r '$s = file_get_contents("config/config.example.php");
    $s = str_replace("CHANGE_ME", bin2hex(random_bytes(32)), $s);
    file_put_contents("config/config.php", $s);'
  chmod 600 config/config.php 2>/dev/null
  log "Создан config/config.php из образца (свой app_key). Впишите доступ к базе (db, webasyst_db) в File Manager и снова нажмите Deploy."
  CONFIG_NEW=1
else
  CONFIG_NEW=0
fi

# 3. миграции и кэш (только если config уже заполнен: подключение к базе проверит install.php)
if [ "$CONFIG_NEW" = "0" ]; then
  if run_php bin/install.php >>"$LOG" 2>&1; then
    log "Миграции: готово"
    run_php bin/cache-clear.php >>"$LOG" 2>&1 && log "Кэш сброшен"
    # Режим «Сайт обновляется» (503, public/index.php): база без товаров — включить; товары появились — снять,
    # если режим включал сам деплой (в файле «auto»). Файл, созданный владельцем вручную, деплой не трогает.
    N="$(run_php -r 'require "app/bootstrap.php"; echo (int) App\Core\App::db()->value("SELECT COUNT(*) FROM products");' 2>/dev/null)"
    if [ "${N:-0}" = "0" ]; then
      [ -f storage/maintenance ] || { echo auto > storage/maintenance; log "В базе нет товаров — включён режим «Сайт обновляется» (503). Снимется сам, когда загрузите данные и снова нажмёте Deploy."; }
    elif [ -f storage/maintenance ] && [ "$(cat storage/maintenance 2>/dev/null)" = "auto" ]; then
      rm -f storage/maintenance && log "Товаров в базе: $N — режим «Сайт обновляется» снят"
    fi
  else
    log "ОШИБКА в bin/install.php — подробности выше в этом журнале (часто: неверный доступ к базе в config/config.php)"
  fi
fi

# 4. public_html → public/ (первый деплой)
PH="${HOME:-}/public_html"
if [ -n "${HOME:-}" ]; then
  if [ -L "$PH" ]; then
    if [ "$(readlink -f "$PH")" = "$(readlink -f "$REPO/public")" ]; then log "public_html → public/: на месте"
    else log "ВНИМАНИЕ: public_html — ссылка на $(readlink "$PH"), не трогаю"; fi
  elif [ -d "$PH" ]; then
    OTHER="$(ls -A "$PH" | grep -vxE 'cgi-bin|\.well-known|error_log|index\.html|default\.html|default\.htm|index\.htm|\.htaccess|php\.ini|\.user\.ini' || true)"
    if [ -z "$OTHER" ]; then
      BAK="$PH.before-tomobuv-$(date +%Y%m%d-%H%M%S)"
      if mv "$PH" "$BAK" && ln -s "$REPO/public" "$PH"; then
        [ -d "$BAK/.well-known" ] && cp -R "$BAK/.well-known" "$REPO/public/" 2>/dev/null
        log "public_html → $REPO/public (прежняя папка сохранена как $(basename "$BAK"))"
      else
        log "ОШИБКА: не удалось заменить public_html ссылкой — попросите хостинг указать корень домена: $REPO/public"
        [ -d "$BAK" ] && [ ! -e "$PH" ] && mv "$BAK" "$PH"
      fi
    else
      log "public_html не пустой — ссылку не ставлю. Лишнее: $(echo "$OTHER" | tr '\n' ' ')"
    fi
  fi
fi
log "=== готово"
exit 0
