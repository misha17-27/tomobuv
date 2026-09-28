#!/usr/bin/env python3
"""
Сверка SEO старого (живого) и нового сайта по списку адресов.

  python tools/seo-compare.py --new http://127.0.0.1:8080 --sample 300          # выборка из живого sitemap
  python tools/seo-compare.py --new http://127.0.0.1:8080 /category/aktsiya/ /product/60189b/

Сравнивает: HTTP-код, title, meta description, meta keywords, первый H1, canonical, robots.
Печатает расхождения и итог; --json out.json — полный отчёт.
Для нового сайта запросы идут с cookie nocache=1 (без кэша страниц).
"""
import argparse, html, json, random, re, sys, time, urllib.request, urllib.error

sys.stdout.reconfigure(encoding='utf-8')
LIVE = 'https://tomobuv.com.ua'
UA = {'User-Agent': 'Mozilla/5.0 (seo-compare)'}


def fetch(url, cookie=None):
    h = dict(UA)
    if cookie:
        h['Cookie'] = cookie
    req = urllib.request.Request(url, headers=h)

    class NoRedirect(urllib.request.HTTPRedirectHandler):
        def redirect_request(self, *a, **k):
            return None
    opener = urllib.request.build_opener(NoRedirect)
    try:
        r = opener.open(req, timeout=40)
        return r.status, r.read().decode('utf-8', 'replace'), r.headers.get('Location')
    except urllib.error.HTTPError as e:
        body = e.read().decode('utf-8', 'replace') if e.fp else ''
        return e.code, body, e.headers.get('Location')
    except Exception as e:
        return 0, str(e), None


def norm(s):
    return re.sub(r'\s+', ' ', html.unescape(s or '')).strip()


def meta(body):
    def g(p):
        m = re.search(p, body, re.S | re.I)
        return norm(m.group(1)) if m else ''
    h1 = g(r'<h1[^>]*>(.*?)</h1>')
    return {
        'title': g(r'<title>(.*?)</title>'),
        'description': g(r'<meta\s+name="description"\s+content="([^"]*)"'),
        'keywords': g(r'<meta\s+name="keywords"\s+content="([^"]*)"'),
        'h1': norm(re.sub(r'<[^>]+>', '', h1)),
        'canonical': re.sub(r'^https?://[^/]+', '', g(r'<link\s+rel="canonical"\s+href="([^"]*)"')),
        'robots': g(r'<meta\s+name="robots"\s+content="([^"]*)"'),
    }


def sample_paths(n):
    paths = ['/', '/brand/', '/blog/']
    _, idx, _ = fetch(LIVE + '/sitemap.xml')
    maps = re.findall(r'<loc>([^<]+)</loc>', idx)
    pool = []
    for sm in maps:
        _, body, _ = fetch(sm)
        pool += [u.replace(LIVE, '') or '/' for u in re.findall(r'<loc>([^<]+)</loc>', body)]
        time.sleep(0.3)
    cats = [p for p in pool if p.startswith('/category/')]
    brands = [p for p in pool if p.startswith('/brand/')]
    other = [p for p in pool if not p.startswith(('/category/', '/brand/', '/product/'))]
    prods = [p for p in pool if p.startswith('/product/')]
    random.seed(42)
    pick = cats + other + random.sample(brands, min(30, len(brands)))
    pick += [c + '?page=2' for c in random.sample(cats, min(8, len(cats)))]
    pick += random.sample(prods, max(0, min(len(prods), n - len(pick))))
    return list(dict.fromkeys(paths + pick))


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--new', required=True)
    ap.add_argument('--sample', type=int, default=0)
    ap.add_argument('--json')
    ap.add_argument('--delay', type=float, default=0.4)
    ap.add_argument('paths', nargs='*')
    a = ap.parse_args()
    paths = a.paths or sample_paths(a.sample or 200)
    report, bad, added = [], 0, {}
    for i, p in enumerate(paths, 1):
        s1, b1, l1 = fetch(LIVE + p)
        s2, b2, l2 = fetch(a.new.rstrip('/') + p, cookie='nocache=1')
        m1, m2 = meta(b1), meta(b2)
        diffs = {}
        if s1 != s2:
            diffs['status'] = [s1, s2, l1, l2]
        if s1 == 200 and s2 == 200:
            for k in m1:
                if m1[k] != m2[k]:
                    if m1[k] == '' and k in ('h1', 'canonical'):
                        added.setdefault(p, []).append(k)   # на старом не было — это улучшение, не расхождение
                    else:
                        diffs[k] = [m1[k], m2[k]]
        report.append({'path': p, 'live_status': s1, 'new_status': s2, 'diffs': diffs})
        if diffs:
            bad += 1
            print(f'[{i}/{len(paths)}] ✗ {p}')
            for k, (v1, v2, *_) in diffs.items():
                print(f'     {k}:\n       живой: {str(v1)[:200]}\n       новый: {str(v2)[:200]}')
        else:
            print(f'[{i}/{len(paths)}] ✓ {p}')
        time.sleep(a.delay)
    print(f'\nИтого: {len(paths)} адресов, совпало {len(paths) - bad}, расхождений {bad}; '
          f'добавлены h1/canonical там, где на старом их не было: {len(added)}')
    if a.json:
        json.dump(report, open(a.json, 'w', encoding='utf-8'), ensure_ascii=False, indent=1)


if __name__ == '__main__':
    main()
