#!/usr/bin/env python3
"""
Фразы интерфейса без украинского перевода.

  python tools/i18n-missing.py              # все непереведённые фразы из шаблонов, контроллеров и JS
  python tools/i18n-missing.py app/Views/front/cart.php public/assets/js/checkout.js   # только эти файлы
  python tools/i18n-missing.py --php        # вывести готовые строки для вставки в lang/uk/<раздел>.php

PHP: t('Фраза'), JS: t('Фраза') / UI.t('Фраза') → в словаре ключ 'js:Фраза'.
Словарь: lang/uk/*.php — массивы 'Русская фраза' => 'Українська фраза'.
"""
import glob
import os
import re
import sys

sys.stdout.reconfigure(encoding='utf-8')
ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
PHP_T = re.compile(r"""\bt\(\s*'((?:[^'\\]|\\.)*)'""")
JS_T = re.compile(r"""(?:\bUI\.t|\bt)\(\s*'((?:[^'\\]|\\.)*)'""")
DICT_KEY = re.compile(r"""^\s*'((?:[^'\\]|\\.)*)'\s*=>""", re.M)


def unescape(s):
    return s.replace("\\'", "'").replace('\\\\', '\\')


def known():
    keys = set()
    for f in glob.glob(os.path.join(ROOT, 'lang', 'uk', '*.php')):
        keys |= {unescape(k) for k in DICT_KEY.findall(open(f, encoding='utf-8').read())}
    return keys


def scan(files):
    found = {}
    for f in files:
        s = open(f, encoding='utf-8', errors='replace').read()
        is_js = f.endswith('.js')
        for m in (JS_T if is_js else PHP_T).finditer(s):
            k = ('js:' if is_js else '') + unescape(m.group(1))
            found.setdefault(k, os.path.relpath(f, ROOT))
    return found


def main():
    args = [a for a in sys.argv[1:] if not a.startswith('--')]
    if args:
        files = [os.path.join(ROOT, a) for a in args]
    else:
        files = glob.glob(os.path.join(ROOT, 'app', '**', '*.php'), recursive=True)
        files = [f for f in files if '\\admin' not in f.lower().replace('/', '\\') + '\\' or 'views\\admin' not in f.lower().replace('/', '\\')]
        files = [f for f in files if os.sep + 'Admin' + os.sep not in f and os.sep + 'admin' + os.sep not in f]
        files += glob.glob(os.path.join(ROOT, 'public', 'assets', 'js', '*.js'))
    have = known()
    miss = {k: f for k, f in scan(files).items() if k not in have}
    if '--php' in sys.argv:
        for k in miss:
            print("    '" + k.replace('\\', '\\\\').replace("'", "\\'") + "' => '',")
    else:
        for k, f in sorted(miss.items(), key=lambda x: x[1]):
            print(f'{f}: {k}')
        print(f'\nБез перевода: {len(miss)}')


if __name__ == '__main__':
    main()
