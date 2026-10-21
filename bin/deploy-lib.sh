#!/bin/bash
# Общие функции bin/deploy.sh и bin/auto-update.sh (подключаются через source; текущая папка — корень репозитория).
#
# cPanel MultiPHP Manager при выборе версии PHP дописывает в корневой .htaccess сайта блок
#   # php -- BEGIN cPanel-generated handler, do not edit … # php -- END cPanel-generated handler, do not edit
# Корень сайта — public/ репозитория, поэтому меняется отслеживаемый public/.htaccess: git видит «незакоммиченные
# изменения», и cPanel отключает Deploy, а автообновление пропускается. Здесь этот блок сохраняется в
# storage/htaccess-php-handler, а файл помечается skip-worktree (git его «не замечает»), пока в нём нет других правок.
# MultiPHP INI Editor так же переписывает public/.user.ini — его локальная версия просто остаётся (skip-worktree).

HANDLER_BEGIN='# php -- BEGIN cPanel-generated handler'
HANDLER_END='# php -- END cPanel-generated handler'
HANDLER_SAVE='storage/htaccess-php-handler'

is_skip() { git ls-files -v -- "$1" 2>/dev/null | grep -q '^S'; }

# убрать блок cPanel и пустые строки — для сравнения с версией из git
strip_handler() { sed "/$HANDLER_BEGIN/,/$HANDLER_END/d" "$1" | grep -v '^[[:space:]]*$'; }

# Изменения public/.htaccess только в блоке cPanel → сохранить блок, skip-worktree. Вызывать до проверки «чистоты».
keep_cpanel_files() {
  local f=public/.htaccess
  if [ -f "$f" ] && ! is_skip "$f" && ! git diff --quiet -- "$f" 2>/dev/null; then
    if grep -q "$HANDLER_BEGIN" "$f" && [ "$(strip_handler "$f")" = "$(git show "HEAD:$f" | grep -v '^[[:space:]]*$')" ]; then
      sed -n "/$HANDLER_BEGIN/,/$HANDLER_END/p" "$f" > "$HANDLER_SAVE"
      git update-index --skip-worktree -- "$f"
      log "public/.htaccess: блок версии PHP от cPanel сохранён (storage/htaccess-php-handler), файл помечен skip-worktree"
    else
      log "ВНИМАНИЕ: public/.htaccess изменён на сервере не только cPanel (версия PHP) — не трогаю; Deploy и автообновление будут заблокированы, пока файл не вернут к версии из git"
    fi
  fi
  local u=public/.user.ini
  if [ -f "$u" ] && ! is_skip "$u" && ! git diff --quiet -- "$u" 2>/dev/null; then
    git update-index --skip-worktree -- "$u"
    log "public/.user.ini изменён на сервере (MultiPHP INI Editor) — оставлен как есть, помечен skip-worktree"
  fi
}

# Перед git merge: если обновление меняет public/.htaccess, а он помечен skip-worktree — вернуть файл из git,
# после слияния дописать сохранённый блок cPanel и снова пометить. Возвращает 0, если блок нужно вернуть после merge.
before_merge_htaccess() {
  local f=public/.htaccess
  is_skip "$f" || return 1
  git diff --quiet HEAD "$1" -- "$f" && return 1   # в обновлении файл не менялся — merge его не тронет
  [ -s "$HANDLER_SAVE" ] || sed -n "/$HANDLER_BEGIN/,/$HANDLER_END/p" "$f" > "$HANDLER_SAVE"
  git update-index --no-skip-worktree -- "$f"
  git checkout -- "$f"
  return 0
}

after_merge_htaccess() {
  local f=public/.htaccess
  if [ -s "$HANDLER_SAVE" ] && ! grep -q "$HANDLER_BEGIN" "$f"; then
    { echo; cat "$HANDLER_SAVE"; } >> "$f"
  fi
  git update-index --skip-worktree -- "$f"
  log "public/.htaccess обновлён из git, блок версии PHP от cPanel возвращён"
}
