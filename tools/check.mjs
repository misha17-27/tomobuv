// Автопроверка страниц в headless Chrome (для разработки, на сервер не нужно).
//   node tools/check.mjs http://localhost:8080/ http://localhost:8080/category/dyetskaya-obuv/ [--shots=DIR] [--mobile-only] [--full] [--nocache] [--no-remote]
// Выводит JSON по каждой странице: статус, title, ошибки JS, упавшие запросы (кроме внешних картинок),
// PHP-ошибки в HTML, горизонтальный скролл на 375px. Скриншоты: DIR/<slug>-desktop.png, -mobile.png
import { createRequire } from 'module';
import fs from 'fs';
import path from 'path';

const require = createRequire(import.meta.url);
const GLOBAL = process.env.APPDATA ? path.join(process.env.APPDATA, 'npm', 'node_modules') : '/usr/lib/node_modules';
let puppeteer;
try { puppeteer = require('puppeteer-core'); } catch { puppeteer = require(path.join(GLOBAL, 'lighthouse', 'node_modules', 'puppeteer-core')); }

const args = process.argv.slice(2);
const urls = args.filter(a => !a.startsWith('--'));
const opt = Object.fromEntries(args.filter(a => a.startsWith('--')).map(a => { const [k, v] = a.slice(2).split('='); return [k, v ?? true]; }));
const shots = opt.shots ? path.resolve(opt.shots) : null;
if (shots) fs.mkdirSync(shots, { recursive: true });

const CHROME = [
  'C:/Program Files/Google/Chrome/Application/chrome.exe',
  'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe',
].find(p => fs.existsSync(p));

const browser = await puppeteer.launch({ executablePath: CHROME, headless: 'new', args: ['--no-sandbox', '--disable-gpu'] });
const results = [];
for (const url of urls) {
  const r = { url, errors: [], failed: [], php: [] };
  for (const mode of opt['mobile-only'] ? ['mobile'] : ['desktop', 'mobile']) {
    const page = await browser.newPage();
    if (mode === 'mobile') await page.setViewport({ width: 375, height: 812, isMobile: true, deviceScaleFactor: 1 });
    else await page.setViewport({ width: 1366, height: 900 });
    page.on('pageerror', e => r.errors.push(`[${mode}] ${e.message}`));
    // --no-remote: фото со старого сайта (images.remote_base при разработке без копии wa-data) не грузить —
    // иначе недоступный живой сайт даёт таймауты загрузки и «Failed to load resource» не по вине страницы
    if (opt['no-remote']) {
      await page.setRequestInterception(true);
      page.on('request', q => (/^https?:\/\/(www\.)?tomobuv\.com\.ua\//.test(q.url()) ? q.abort() : q.continue()));
    }
    page.on('console', m => {
      if (m.type() !== 'error') return;
      if (/tomobuv\.com\.ua/.test(m.location()?.url || '') && /Failed to load resource/.test(m.text())) return;   // фото живого сайта
      if (m.location()?.url === url && /Failed to load resource/.test(m.text())) return;   // код самой страницы (404) — уже в status
      r.errors.push(`[${mode}] console: ${m.text()}`);
    });
    page.on('requestfailed', q => { const u = q.url(); if (!/tomobuv\.com\.ua|fonts\.g/.test(u)) r.failed.push(`[${mode}] ${u} ${q.failure()?.errorText}`); });
    page.on('response', s => { const u = s.url(); if (s.status() >= 400 && !/tomobuv\.com\.ua|favicon/.test(u) && u !== url) r.failed.push(`[${mode}] ${s.status()} ${u}`); });
    if (opt.nocache) await page.setCookie({ name: 'nocache', value: '1', url });
    const t0 = Date.now();
    let resp;
    try { resp = await page.goto(url, { waitUntil: 'networkidle2', timeout: 30000 }); }
    catch (e) { r.errors.push(`[${mode}] goto: ${e.message}`); await page.close(); continue; }
    r.status = resp?.status();
    r[`load_${mode}_ms`] = Date.now() - t0;
    r.title = await page.title();
    const html = await page.content();
    for (const m of html.matchAll(/(Fatal error|Warning|Notice|Deprecated|Parse error|Uncaught)[^<]{0,200}/g)) r.php.push(m[0]);
    if (mode === 'mobile') {
      r.hscroll = await page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth + 1
        ? [...document.querySelectorAll('body *')].filter(e => e.getBoundingClientRect().right > document.documentElement.clientWidth + 1).slice(0, 5).map(e => e.tagName + '.' + e.className + (e.getAttribute('href') ? ' ' + e.getAttribute('href').slice(0, 60) : '') + ' →' + Math.round(e.getBoundingClientRect().right))
        : false);
    } else {
      r.h1 = await page.evaluate(() => [...document.querySelectorAll('h1')].map(h => h.textContent.trim().slice(0, 100)));
      r.description = await page.evaluate(() => document.querySelector('meta[name=description]')?.content || '');
      r.canonical = await page.evaluate(() => document.querySelector('link[rel=canonical]')?.href || '');
    }
    if (shots) {
      const slug = url.replace(/^https?:\/\/[^/]+/, '').replace(/[^a-z0-9]+/gi, '_').replace(/^_|_$/g, '') || 'home';
      await page.screenshot({ path: path.join(shots, `${slug}-${mode}.png`), fullPage: !!opt.full });
    }
    await page.close();
  }
  results.push(r);
}
await browser.close();
console.log(JSON.stringify(results, null, 1));
