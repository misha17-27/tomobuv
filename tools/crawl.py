#!/usr/bin/env python3
"""
Обход нового сайта по внутренним ссылкам: битые ссылки, ошибки PHP, медленные страницы.

  python tools/crawl.py http://127.0.0.1:8080 --limit 400 [--nocache] [--start /ua/] [--max-variants 4] [--dump FILE]

Отчёт: адреса с кодом ≠ 200/301/302, страницы с текстом ошибок PHP, топ медленных, откуда ведут битые ссылки,
ссылки на другой язык (с /ua/ на русскую страницу и наоборот; переключатель языка с hreflang не считается).
Обход не выходит за свой язык: от /ua/ — только /ua/…, от / — всё, кроме /ua/…
Чтобы не уходить в бесконечные комбинации фильтров/сортировок: у одного адреса не больше --max-variants
разных ?-вариантов и не больше 2 параметров в одном. Очередь идёт по «типам» адресов по кругу
(категории, товары, бренды, блог, инфо-страницы…), так что даже --limit 700 покрывает все виды страниц.
"""
import argparse, re, sys, time, urllib.parse, urllib.request, urllib.error
from collections import deque, defaultdict

sys.stdout.reconfigure(encoding='utf-8')
SKIP = re.compile(r'^(/ua)?/(admin|logout|cart/(add|update|remove|clear)|request/|quickorder|wa-data/|assets/|uploads/)|\.(jpg|jpeg|png|gif|webp|svg|css|js|ico|pdf|xml|txt)$', re.I)
# Адреса без языка: статика, служебные AJAX, админка — могут вести куда угодно
NEUTRAL = re.compile(r'^/(assets/|wa-data/|uploads/|admin|favicon|robots\.txt|sitemap[^/]*\.xml|cart/(add|update|remove|clear|json)/|request/|quickorder/|search/suggest/|products/cards/)', re.I)
TAG = re.compile(r'<(a|link|form|area)\b([^>]*)>', re.I)
ATTR = re.compile(r'\b(href|action|hreflang|rel)\s*=\s*"([^"]*)"', re.I)


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('base')
    ap.add_argument('--limit', type=int, default=300)
    ap.add_argument('--nocache', action='store_true')
    ap.add_argument('--start', default='/', help='стартовый путь, например /ua/')
    ap.add_argument('--max-variants', type=int, default=4, help='сколько разных ?-вариантов одного адреса обходить')
    ap.add_argument('--dump', help='записать в файл все проверенные адреса с кодами')
    a = ap.parse_args()
    base = a.base.rstrip('/')
    bu = urllib.parse.urlparse(base)
    port = bu.port or 80
    # base_url сайта может быть localhost, а обходим 127.0.0.1 — это один и тот же сервер
    hosts = {bu.netloc, f'localhost:{port}', f'127.0.0.1:{port}'}
    start = a.start if a.start.startswith('/') else '/' + a.start
    uk = start == '/ua' or start.startswith('/ua/')
    in_scope = (lambda p: p == '/ua' or p.startswith('/ua/')) if uk else (lambda p: not (p == '/ua' or p.startswith('/ua/')))
    # очередь по «типам» адресов (category, product, brand?letter…): каждый раз берём тип, которого проверено меньше всего,
    # чтобы при --limit обход покрывал все виды страниц, а не только первые 700 товаров
    queues, done_kind, seen = defaultdict(deque), defaultdict(int), {start}
    variants = defaultdict(int)

    def kind(p):
        u = urllib.parse.urlparse(p)
        seg = [x for x in u.path.split('/') if x]
        if seg and seg[0] == 'ua':
            seg = seg[1:]
        k = (seg[0] if seg else '') + ('/' + seg[2] if len(seg) > 2 else '')
        return k + '?' + ','.join(sorted({n for n, _ in urllib.parse.parse_qsl(u.query, keep_blank_values=True)}))

    def pending():
        return sum(len(x) for x in queues.values())
    queues[kind(start)].append(start)
    status, slow, errors, refs, mixed, redirects = {}, [], [], defaultdict(set), defaultdict(set), {}

    class NoRedirect(urllib.request.HTTPRedirectHandler):
        def redirect_request(self, *x, **k):
            return None
    opener = urllib.request.build_opener(NoRedirect)

    def enqueue(p, src):
        refs[p].add(src)
        if p in seen or not in_scope(urllib.parse.urlparse(p).path):
            return
        u = urllib.parse.urlparse(p)
        if u.query:
            if len(urllib.parse.parse_qsl(u.query, keep_blank_values=True)) > 2 or variants[u.path] >= a.max_variants:
                return
            variants[u.path] += 1
        seen.add(p)
        queues[kind(p)].append(p)

    while pending() and len(status) < a.limit:
        k = min((x for x in queues if queues[x]), key=lambda x: done_kind[x])
        done_kind[k] += 1
        path = queues[k].popleft()
        # как браузер: кириллица и пробелы в адресе кодируются, %XX и разделители остаются как есть
        req = urllib.request.Request(base + urllib.parse.quote(path, safe="/%?=&+:@!$'()*,;~#[]"), headers={'User-Agent': 'crawl', **({'Cookie': 'nocache=1'} if a.nocache else {})})
        t = time.time()
        loc = None
        try:
            r = opener.open(req, timeout=30)
            code, body = r.status, r.read().decode('utf-8', 'replace')
        except urllib.error.HTTPError as e:
            code, body = e.code, (e.read().decode('utf-8', 'replace') if e.fp else '')
            loc = e.headers.get('Location') if e.headers else None
        except Exception as e:
            code, body = 0, str(e)
        ms = (time.time() - t) * 1000
        status[path] = code
        slow.append((ms, path))
        if loc:
            lu = urllib.parse.urlparse(urllib.parse.urljoin(base + path, loc))
            lp = lu.path + ('?' + lu.query if lu.query else '')
            redirects[path] = (code, lp if lu.netloc in hosts else loc)
            if lu.netloc in hosts and not SKIP.search(lu.path):
                enqueue(lp, path)
        if re.search(r'(Fatal error|Warning:|Notice:|Deprecated:|Uncaught|<pre style="white-space:pre-wrap">)', body):
            errors.append(path)
        page_uk = path == '/ua' or path.startswith('/ua/')
        for tag, attrs in TAG.findall(body):
            at = {k.lower(): v for k, v in ATTR.findall(attrs)}
            href = at.get('href') or at.get('action')
            if not href or href.startswith(('#', 'tel:', 'mailto:', 'javascript:', 'viber:', 'tg:', 'whatsapp:')):
                continue
            u = urllib.parse.urlparse(urllib.parse.urljoin(base + path, href.replace('&amp;', '&')))
            if u.netloc not in hosts:
                continue
            p = u.path + ('?' + u.query if u.query else '')
            # ссылка на другой язык (переключатель и <link hreflang> — с атрибутом hreflang, это нормально);
            # canonical, rel=next/prev, пункты меню, формы — должны быть своего языка
            if 'hreflang' not in at:
                link_uk = u.path == '/ua' or u.path.startswith('/ua/')
                if link_uk != page_uk and not NEUTRAL.search(u.path[3:] if link_uk else u.path):
                    mixed[p].add(path)
            if tag.lower() == 'link' or tag.lower() == 'form' or SKIP.search(u.path) or 'nocache' in p:
                continue
            enqueue(p, path)
    if a.dump:
        with open(a.dump, 'w', encoding='utf-8') as f:
            f.writelines(f'{c}\t{p}\n' for p, c in status.items())
    bad = {p: c for p, c in status.items() if c not in (200, 301, 302)}
    kinds = defaultdict(int)
    for p in status:
        m = re.match(r'^(?:/ua)?/([^/?]*)', p)
        kinds[(m.group(1) if m and m.group(1) in ('category', 'product', 'brand', 'blog', 'search', 'compare', 'reviews', 'my') else 'другое') + ('?' if '?' in p else '')] += 1
    print(f'Старт: {start}. Проверено страниц: {len(status)}, в очереди осталось: {pending()}')
    print('По типам: ' + ', '.join(f'{k} {n}' for k, n in sorted(kinds.items(), key=lambda x: -x[1])))
    print(f'\nНе 200/30x ({len(bad)}):')
    for p, c in sorted(bad.items()):
        print(f'  {c}  {p}   ← {", ".join(sorted(refs[p])[:3])}')
    print(f'\nОшибки PHP в HTML ({len(errors)}):')
    for p in errors:
        print('  ', p)
    print(f'\nСсылки на другой язык ({len(mixed)}):')
    for p, src in sorted(mixed.items()):
        print(f'  {p}   ← {", ".join(sorted(src)[:3])}{" …" if len(src) > 3 else ""} ({len(src)} стр.)')
    print(f'\nПеренаправления ({len(redirects)}):')
    for p, (c, to) in sorted(redirects.items()):
        print(f'  {c}  {p} → {to}   ← {", ".join(sorted(refs[p])[:2])}')
    print('\nСамые медленные:')
    for ms, p in sorted(slow, reverse=True)[:15]:
        print(f'  {ms:7.0f} мс  {p}')


if __name__ == '__main__':
    main()
