#!/bin/bash
# Первая (массовая) загрузка «Хит обуви» на хостинге без терминала — то же, что D:\tomobuv.com.ua\load-hitobuvi.bat локально.
# Cron Jobs в cPanel раз в час (минута 5 — не вместе с bin/cron.php в :00):
#   /bin/bash /home/ПОЛЬЗОВАТЕЛЬ/repositories/tomobuv-site/bin/load-hitobuvi.sh > /dev/null 2>&1
# Каждый вызов — bin/supplier-hitobuvi.php --load не дольше 50 мин: продолжает прерванный запуск с того же места или начинает
# полный проход (новых до 50 000, запросов до 60 000 — лимиты только этого запуска, настройки не меняются).
# Всё загружено (код 2) — PHP пишет storage/hitobuvi-load.done с порогом id; дальше каждый вызов сразу выходит (код 4), пока порог
# тот же (задание можно оставить); снизили порог в настройках — загрузка пойдёт снова и добавит только недостающие. «Остановить» в админке (код 3) — новый проход не начинается, пока владелец не нажмёт «Запустить
# сейчас» (этот запуск скрипт и продолжит). Ошибка (поставщик не отвечает и т. п.) — повтор в следующий час, после трёх подряд —
# раз в 6 ч (storage/hitobuvi-load.errors), чтобы не обращаться к недоступному сайту каждый час. Второй вызов, пока идёт первый,
# сразу выходит.
# Журнал: storage/logs/hitobuvi-load.log.
set -u
REPO="$(cd "$(dirname "$0")/.." && pwd)"
cd "$REPO" || exit 1
mkdir -p storage/logs 2>/dev/null
LOG="$REPO/storage/logs/hitobuvi-load.log"
ERR=storage/hitobuvi-load.errors
if [ -f "$ERR" ] && [ "$(cat "$ERR" 2>/dev/null)" -ge 3 ] 2>/dev/null && [ -z "$(find "$ERR" -mmin +355 2>/dev/null)" ]; then exit 0; fi

# не больше одного вызова сразу (шаги запуска и так под блокировкой в базе — это чтобы не плодить процессы)
if command -v flock >/dev/null 2>&1; then
  exec 9>storage/hitobuvi-load.lock
  flock -n 9 || exit 0
fi
# журнал больше 5 МБ — начать заново (старый — .1)
[ -f "$LOG" ] && [ "$(wc -c <"$LOG")" -gt 5242880 ] && mv -f "$LOG" "$LOG.1"
log() { echo "$(date '+%F %T') $*" >>"$LOG"; }

PHP="$(cat storage/php-cli-path 2>/dev/null)"   # путь пишет bin/deploy.sh
[ -n "$PHP" ] && command -v "$PHP" >/dev/null 2>&1 || PHP=/opt/cpanel/ea-php81/root/usr/bin/php
"$PHP" ${PHP_OPTS:-} bin/supplier-hitobuvi.php --load --max-new=50000 --max-requests=60000 --minutes=50 >>"$LOG" 2>&1
RC=$?
case "$RC" in
  0) rm -f "$ERR" ;;
  2) rm -f "$ERR"
     log "Массовая загрузка «Хит обуви» закончена — дальше по расписанию (ночная сверка)" ;;
  4) ;;   # уже всё загружено с этим порогом — ничего не делать
  3) rm -f "$ERR" ;;   # остановлен в админке — причина уже в журнале
  *) N=$(( $(cat "$ERR" 2>/dev/null || echo 0) + 1 )); echo "$N" >"$ERR"
     if [ "$N" -ge 3 ]; then log "Ошибка (код $RC), $N подряд — следующая попытка через 6 ч"; else log "Ошибка (код $RC) — повтор в следующий час"; fi ;;
esac
exit 0
