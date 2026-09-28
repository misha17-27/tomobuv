# Tomobuv — архитектура и правила разработки

Оптовый интернет-магазин обуви на **чистом PHP 8.1+** без фреймворков и Composer.
Рассчитан на обычный хостинг (cPanel/ISPmanager, Apache + MySQL/MariaDB) и каталог 100 000+ товаров.

## Структура

```
public/              ← корень сайта (DocumentRoot): index.php, .htaccess, assets/, wa-data/ (фото со старого сайта)
  assets/css/src/    ← исходники стилей витрины: 00-base.css + по файлу на раздел (20-catalog.css …)
  assets/css/app.css ← собранный файл (php bin/build-assets.php) — руками не править
  assets/js/app.js   ← общий скрипт витрины (корзина, избранное, валюта, поиск, модалки) → window.UI
  assets/js/*.js     ← скрипты отдельных страниц (подключаются через $scripts в шаблоне)
  assets/admin/      ← стили/скрипты админки (admin.css, admin.js + файлы разделов)
app/
  bootstrap.php, helpers.php, routes.php, routes_admin.php, routes/admin_*.php
  Core/              ← ядро: App, DB, Router, Request, Response, View, Cache, PageCache, Session, Csrf, Auth,
                        Settings, Seo, Image, Paginator, Mailer, RateLimit, Str, Log
  Services/          ← бизнес-логика: Catalog (справочники из кэша), Products (карточки), CatalogIndexer, …
  Controllers/Front/ ← витрина;  Controllers/Admin/ ← админка (наследуют BaseController)
  Views/layouts/     ← front.php, admin.php;  Views/front/, Views/admin/, Views/errors/, Views/emails/
bin/                 ← CLI: install, import-webasyst, reindex, cache-clear, cron, create-admin, build-assets
config/              ← config.php (секреты, не в git) и config.example.php
database/schema.sql  ← схема БД
storage/             ← кэш, логи, сессии, загрузки (не в git, закрыта от веба)
tools/check.mjs      ← автопроверка страниц в headless Chrome (только для разработки)
```

## Почему быстро

1. **Кэш готовых страниц** (`Core/PageCache`). Страницы витрины одинаковы для всех посетителей: всё личное
   (корзина, избранное, сравнение, валюта, «Кабинет») рисует JS из cookie. Страница из кэша отдаётся за 1–3 мс
   ещё до подключения к БД. Контроллер включает кэш: `return Response::html($html)->cache(3600);`
   Сброс — `Cache::flush()` (меняет номер версии; старые файлы удаляет `bin/cron.php`).
   **Нельзя** кэшировать страницы с персональными данными: корзина, оформление, кабинет, админка, POST.
2. **Индекс каталога** `catalog_index` (категория → товары с учётом подкатегорий и динамических категорий)
   с покрывающими индексами под каждую сортировку. Список категории = один проход по индексу + `Products::cards($ids)`.
   Порядок «по убыванию, при равных — id по возрастанию» (как на старом сайте) идёт через вычисляемую колонку
   `product_rid = 4294967295 − product_id` (`… DESC, product_rid DESC`): MySQL/MariaDB не читают по индексу смешанные
   направления сортировки (database/migrations/perf.sql). Новый ORDER BY для витрины — сначала EXPLAIN: без «Using filesort».
   После изменения товаров: `$s = CatalogIndexer::snapshot($ids)` до изменения и `CatalogIndexer::products($ids, $s)` после
   (1–10 товаров — ~20 мс; без снимка тоже верно, ~0,15 с), после массового импорта — `CatalogIndexer::rebuildAll()`
   (полные перестройки — по одной за раз: `CatalogIndexer::exclusive`, блокировка `storage/cache/reindex.lock`).
3. **Фильтры**: `product_features (feature_id, value_id, product_id)`, заранее посчитанные `category_facets`.
4. **Файловый кэш данных** (`Core/Cache`) для справочников — категории, бренды, настройки, меню.
5. **Сессия только при необходимости** (`Session::start()` вызывается входом, оформлением, админкой).
6. **Фото** — миниатюры создаются один раз при первом обращении, дальше их отдаёт Apache без PHP.
7. Один CSS (≈75 КБ, 15 КБ в gzip), один общий JS, `loading="lazy"`, размеры у картинок, долгий кэш статики в `.htaccess`.
   Шрифт Manrope не блокирует первую отрисовку (preload → stylesheet), до загрузки — «Manrope Fallback» (Arial с подогнанными
   метриками, 00-base.css) — без сдвига вёрстки. Фото первого экрана — без `loading="lazy"` (первая карточка списка — `fetchpriority="high"`).
   Lighthouse (мобильный, Apache c gzip): производительность 95–100, доступность 96–97.

## Правила кода

- **SQL только через плейсхолдеры**: `App::db()->all('… WHERE id = ?', [$id])`, списки — `[$ph, $vals] = $db->in($ids)`.
  Имена колонок/направления сортировки — только из белого списка, никогда из запроса пользователя.
- **Никаких запросов в цикле.** Сначала id, потом пакетная загрузка (`Products::cards($ids)`).
- **SQL — для MySQL 5.7 / 8.0 и MariaDB 10.3+** (на хостинге обычно строгий sql_mode с ONLY_FULL_GROUP_BY):
  псевдонимы-зарезервированные слова MySQL 8 (`empty`, `rank`, `groups`, `row`, `system`…) — в обратных кавычках;
  нет `REGEXP_REPLACE`/оконных функций/CTE в MySQL 5.7 — либо запасной путь в PHP. Новые колонки и индексы в миграциях —
  через проверку `information_schema` (как в `database/migrations/admin-import.sql`); `bin/install.php` повторяет
  миграцию, зависящую от более поздней по алфавиту.
- **Любой вывод в шаблонах — через `e()`**. HTML из базы (описания, страницы) выводится как есть — его пишет админ.
  HTML-поле формы админки сохраняется через `HtmlSanitizer::staff()` (или `PagesController::html()`): администратору — как есть,
  менеджеру — без скриптов, on*-атрибутов, javascript:-ссылок и чужих iframe; описания из импорта — `HtmlSanitizer::supplier()`,
  из файла своей выгрузки (колонки ID и updated_at, `Importer::ownExport`) — `HtmlSanitizer::clean()`, как у менеджера.
- Формы витрины: CSRF «double submit» — `csrf_field()` в форме или заголовок `X-CSRF-Token` (JS: `UI.post()`),
  проверка `Csrf::check()`. Админка: `BaseController` сам проверяет токен сессии на каждом POST (`BaseController::tokenField()`, JS: `Adm.post()`).
- Спам-защита публичных форм: скрытое поле `website` (honeypot) + `RateLimit::hit("key:ip", N, sec)`.
- Цены в шаблонах: `price_html($uah)` (JS пересчитает в выбранную валюту), текстом — `price_format()`.
- Картинки товара: `Image::product($p, '400')`, другие загруженные файлы — `media($path)`.
- Ссылки: категория `Catalog::categoryUrl($c)`, бренд `Catalog::brandUrl($b)`, товар `$p['link']`.
- Ответы: `Response::html()`, `::json()`, `::redirect()`, `::notFound()`. AJAX-ответы: `{"ok":true,…}` / `{"ok":false,"error":"…"}`.
- Стили раздела — отдельный файл `public/assets/css/src/NN-раздел.css` + `php bin/build-assets.php`. Базовый 00-base.css не трогать без необходимости.
- Комментарии — по-русски, по делу.

## Админка — в стиле админки ARG FLEX

Макет `app/Views/layouts/admin.php`: тёмное меню с группами (Обзор / Каталог / Контент / Настройки) и счётчиками,
шапка с заголовком (`$title`), кнопками раздела (`$actions` — HTML) и «Открыть сайт / Выйти», `$back = ['/admin/orders/', 'Все заказы']`.
Стили `public/assets/admin/admin.css`. Компоненты (используйте их, а не свои):

- показатели: `<div class="stats"><a class="stat hot" href="…"><span>642</span>Новых заказов</a>…</div>`
- карточка с заголовком: `<div class="card"><div class="card-hd"><h2>…</h2><a href="…">Все</a></div><div class="pad">…</div></div>`; простая — `<div class="card">…</div>`
- таблица: `<div class="table-scroll"><table class="grid">…</table></div>` (внутри карточки) или `<div class="tblwrap"><table class="tbl">` отдельно;
  строки: `tr.unread` (новое), `tr.is-draft` (скрыто), колонки `.right`, `.tick` (чекбокс), `.thumb`; мелкий текст под значением — `<small>`
- фильтры списка: `<div class="tabs"><a class="on" href="?status=new">Новые <i>12</i></a>…</div>` (таблетки со счётчиком),
  строка поиска/селектов — `.filter-bar` (или `.toolbar`), массовые действия — `.bulk-bar` в начале карточки
- вкладки настроек/форм — `.subtabs`; чипы-фильтры — `.chip(.on)`; статусы — `.st st-new|processing|paid|shipped|completed|refunded|deleted` или `.pill`
- формы: `<label class="fld"><span>Название</span><input …></label>`, сетки `.pair`/`.row2`, `.triple`/`.row3`, чекбокс `.check`,
  подсказка `.hint`, группа SEO — `<fieldset class="seo-set"><legend>SEO</legend>…`, липкая панель сохранения — `<div class="savebar"><button class="btn btn-p">Сохранить</button><span class="hint">…</span></div>`
- кнопки: `.btn` (контурная), `.btn-p` (оранжевая основная), `.btn-d` (удаление), `.btn-sm`; две колонки редактора — `.two-col` (+ `<aside>` справа)
- детали: `<dl class="detail"><div><dt>Телефон</dt><dd>…</dd></div></dl>`, история — `<ul class="events"><li><b>…</b><span>дата</span><em>текст</em></li>`
- пусто: `<div class="empty-card"><h2>…</h2><p>…</p><a class="btn btn-p">…</a></div>`; превью Google — `.serp > .serp-url/.serp-title/.serp-desc`;
  точки SEO — `.seo-dot ok|warn|auto|none`; медиа-сетка — `.media-grid > figure`; график — `.bars > .bar` или SVG `.chart`
- сообщения: `$this->flash('Сохранено')` → зелёная плашка, `$this->flash('…', true)` → красная.
- картинки для контента: `public/uploads/ГГГГ/ММ/` через `App\Services\Media::store($file)`; выбор картинки на любом экране — `<button type="button" class="btn" data-media-pick="#поле" data-media-preview="#превью">Выбрать из медиатеки</button>` + `'scripts' => ['admin/media.js']` (для textarea вставляет `<img>` в позицию курсора; из JS — `MediaPicker.open({onSelect(file){…}})`).
- редакторы (товар, категория, бренд, характеристика, страница, статья, баннер) — один вид: над формой переключатель «RU | UA» со счётчиком
  заполненных полей UA — `$view->partial('admin/partials/lang-bar', ['lang' => $lang, 'what' => 'страницы'])` внутри области `[data-lang="ru"]`,
  поля языка — `.l-ru` / `.l-uk`, поля UA — с атрибутом `data-uk`; в каталоге поле в двух языках — `AdminCatalog::i18nField()`,
  SEO-поля с одинаковыми подписями — `AdminCatalog::seoField('meta_title', 'meta_title', $row, […])`, метка языка — `AdminCatalog::langTag('uk')`.
  HTML-поле — `<textarea data-editor>`: один редактор `admin/content.js` (режимы «Визуально | HTML», «Медиатека» вставляет `<img>`,
  вставка из буфера чистится); экраны без content.js подключают его партиалом `admin/partials/editor`. Стили — в конце admin.css.

## SEO — адреса и мета-теги как на старом сайте (Webasyst)

| Страница | Адрес | Title / Description |
|---|---|---|
| Главная | `/` | настройки `seo.home_page_meta_*` |
| Категория | `/category/{url}/`, `?page=N` | свои meta_* категории, иначе шаблоны `seo.category_meta_*` ({$category.seo_name} = seo_name или name) |
| Пагинация | `?page=N` | к title и description добавляется « \| Страница N», canonical → первая страница |
| Товар | `/product/{url}/` | свои meta_*, иначе `seo.product_meta_*`; H1 = `seo.product_h1` («{name} оптом в Украине») |
| Бренд | `/brand/{urlencode(name)}/` (`/brand/Mona+Lisa/`) | title = brands.title, иначе имя бренда; description = brands.meta_description |
| Инфо-страница | `/o-kompanii/` и т.п., а также `/pages/o-kompanii/` | pages.title, иначе `seo.page_meta_title` («{name} \| интернет-магазин Tomobuv») |
| Блог | `/blog/`, `/blog/{url}/` | meta_title, иначе заголовок |
| Поиск | `/search/?query=…` | «{запрос} — {site_title}», noindex |

`App\Core\Seo::pick($own, 'seo.product_meta_title', ['product' => …, 'category' => …])` — выбор своего значения или шаблона.
Sitemap: `/sitemap.xml` → `sitemap-shop-N.xml` (по 10 000 адресов), `sitemap-blog.xml`, `sitemap-site.xml`.

## Опт

Цена в базе — за пару (`products.price`), в ящике `box_qty` пар. Корзина и заказ считаются **в ящиках**,
в `order_items.quantity` хранятся **пары** (как в Webasyst). Доставка бесплатна от `settings.free_shipping_boxes` ящиков (20).

## Локальная разработка

```
php bin/install.php                         # таблицы
php bin/import-webasyst.php                 # перенос со старой базы (config webasyst_db)
php bin/i18n-seed-uk.php                    # украинские переводы → колонки *_uk
php -S localhost:8080 -t public public/router.php
node tools/check.mjs http://localhost:8080/ --shots=shots   # ошибки JS/PHP, мобильная вёрстка, скриншоты
```
Проверка без кэша страниц: cookie `nocache=1` (например, `curl -b "nocache=1" …`). Сброс кэша: `php bin/cache-clear.php`.
