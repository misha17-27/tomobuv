#!/bin/bash
# Автообновление с GitHub для хостинга без терминала. Cron в cPanel (например, раз в 15 минут):
#   /bin/bash /home/ПОЛЬЗОВАТЕЛЬ/repositories/tomobuv/bin/auto-update.sh
# Есть новые коммиты в origin/main → git merge --ff-only → bin/deploy.sh. Локальные изменения в файлах репозитория
# (кто-то правил код на сервере) — обновление пропускается с записью в журнал, чтобы ничего не затереть.
set -u
REPO="$(cd "$(dirname "$0")/.." && pwd)"
cd "$REPO" || exit 1
mkdir -p storage/logs 2>/dev/null
LOG="$REPO/storage/logs/deploy.log"
git fetch -q origin main 2>>"$LOG" || { echo "$(date '+%F %T') автообновление: git fetch не удался" >>"$LOG"; exit 1; }
[ "$(git rev-parse HEAD)" = "$(git rev-parse origin/main)" ] && exit 0
if [ -n "$(git status --porcelain)" ]; then
  echo "$(date '+%F %T') автообновление пропущено: в папке репозитория есть изменённые файлы: $(git status --porcelain | head -5 | tr '\n' ' ')" >>"$LOG"
  exit 1
fi
if git merge --ff-only -q origin/main >>"$LOG" 2>&1; then
  echo "$(date '+%F %T') автообновление: $(git log --oneline -1)" >>"$LOG"
  /bin/bash "$REPO/bin/deploy.sh" >/dev/null
else
  echo "$(date '+%F %T') автообновление: git merge --ff-only не удался (история разошлась)" >>"$LOG"
  exit 1
fi
