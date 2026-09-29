<?php
declare(strict_types=1);

namespace App\Services;

use App\Controllers\Front\BlogController;
use App\Core\App;
use App\Core\Cache;
use App\Core\Lang;
use App\Core\Seo;
use App\Core\Settings;

/**
 * Автоисправление SEO title/description (норма — как в SEO-обзоре: title 30–70, description 70–170 символов).
 * Смотрит на то, что выводит витрина: своё значение объекта, иначе результат SEO-шаблона (App\Core\Seo::build).
 *
 * Правила (одно поле объекта, RU и UA решаются вместе):
 *   а) своё значение в норме не меняется никогда;
 *   б) «машинное» своё (title = название, description «купить … в Одессе» из выгрузки Forsage) и длинный title — набор
 *      ключевых слов вне нормы очищаются — дальше работает шаблон; но если русское своё остаётся, а украинское машинное вне нормы, *_uk не очищается
 *      (иначе /ua/ покажет русский текст) — туда пишется результат украинского шаблона;
 *   в) своё «человеческое» вне нормы исправляется: повторы слов, заглавная буква, пробел после точки; короткое —
 *      дополняется хвостом (« оптом», « — купить в Одессе», « | Tomobuv»; у description — фраза про опт и доставку),
 *      длинное — сокращается (title по слову, description по предложению); нет перевода — UA по русскому с украинским хвостом;
 *   г) у инфо-страниц и статей без своего description витрина берёт отрывок текста (Seo::excerpt), затем шаблон;
 *   д) служебные страницы (отзывы, карта сайта, список блога) — готовые тексты в настройках, если своих нет или они не в норме;
 *   е) шаблон группы заменяется стандартным (TEMPLATES), только если он пуст, это шаблон Webasyst, который возвращает перенос
 *      (LEGACY), или по нему в норме меньше 98% объектов группы. Удачный шаблон владельца не трогается.
 *
 * Каждое применение — пакет в журнале (seo_fix_batches + seo_fix_log: объект, поле, язык, было, стало, время);
 * откат пакета (revert) возвращает «было», только если значение с тех пор не меняли. Повторный запуск ничего не меняет.
 * Вызовы: bin/seo-autofix.php, админка «SEO → Исправить автоматически», последний шаг bin/import-webasyst.php
 * и bin/i18n-seed-uk.php (перенос не откатывает тексты к старым).
 */
final class SeoFix
{
    public const VERSION = 1;
    private const LOCK = 'tomobuv_seo_autofix';
    private const CHUNK = 5000;
    /** Доля объектов группы, у которых шаблон в норме, чтобы шаблон владельца остался */
    private const KEEP_SHARE = 0.98;

    /** Части (группы) → подпись */
    public const PARTS = ['settings' => 'Шаблоны и служебные страницы', 'categories' => 'Категории', 'brands' => 'Бренды',
        'pages' => 'Инфо-страницы', 'blog' => 'Статьи блога', 'products' => 'Товары'];

    /** Группы итогов «в норме» → подпись (service — главная, отзывы, карта сайта, список блога) */
    public const GROUPS = ['service' => 'Главная и служебные', 'pages' => 'Инфо-страницы', 'categories' => 'Категории',
        'brands' => 'Бренды', 'blog' => 'Статьи блога', 'products' => 'Товары'];

    /** Что сделано с полем → подпись */
    public const RULES = [
        'clear'    => 'машинное → по шаблону',
        'keywords' => 'набор ключевых слов → по шаблону',
        'fix'      => 'своё исправлено',
        'uk_tpl'   => 'UA: машинное → текст шаблона (русское своё осталось)',
        'uk_fill'  => 'UA: перевода нет — по исправленному русскому',
        'template' => 'шаблон заменён стандартным',
        'text'     => 'текст служебной страницы',
    ];

    /** Стандартные шаблоны групп: ключ настройки → [RU, UA («<ключ>.uk»)] */
    public const TEMPLATES = [
        'seo.product_meta_title' => [
            '{$product.name} оптом[[ — купить в Одессе]][[ | {$store_info.name}]]',
            '{$product.name} оптом[[ — купити в Одесі]][[ | {$store_info.name}]]'],
        'seo.product_meta_description' => [
            '{$product.name} оптом[[, {$product.format_price} за пару]][[, в ящике {$product.box_qty|plural:пара,пары,пар}]] — купить в Одессе на 7 км или с доставкой по Украине.[[ Размеры {$product.sizes}.]][[ Интернет-магазин {$store_info.name}.]]',
            '{$product.name} оптом[[, {$product.format_price} за пару]][[, у ящику {$product.box_qty|plural:пара,пари,пар}]] — купити в Одесі на 7 км або з доставкою по Україні.[[ Розміри {$product.sizes}.]][[ Інтернет-магазин {$store_info.name}.]]'],
        'seo.category_meta_title' => [
            '{$category.full_name} оптом[[ — купить в Одессе]][[ | {$store_info.name}]]',
            '{$category.full_name} оптом[[ — купити в Одесі]][[ | {$store_info.name}]]'],
        'seo.category_meta_description' => [
            '{$category.full_name} оптом — купить ящиками в Одессе на 7 км или с доставкой по Украине.[[ В каталоге {$category.product_count|plural:модель,модели,моделей}.]][[ Интернет-магазин {$store_info.name}.]]',
            '{$category.full_name} оптом — купити ящиками в Одесі на 7 км або з доставкою по Україні.[[ У каталозі {$category.product_count|plural:модель,моделі,моделей}.]][[ Інтернет-магазин {$store_info.name}.]]'],
        'seo.brand_meta_title' => [
            '{$brand.name} — обувь оптом[[, купить в Одессе]][[ | {$store_info.name}]]',
            '{$brand.name} — взуття оптом[[, купити в Одесі]][[ | {$store_info.name}]]'],
        'seo.brand_meta_description' => [
            'Обувь {$brand.name} оптом[[: {$brand.product_count|plural:модель,модели,моделей} в каталоге]]. Купить ящиками в Одессе на 7 км или с доставкой по Украине в интернет-магазине {$store_info.name}.',
            'Взуття {$brand.name} оптом[[: {$brand.product_count|plural:модель,моделі,моделей} у каталозі]]. Купити ящиками в Одесі на 7 км або з доставкою по Україні в інтернет-магазині {$store_info.name}.'],
        'seo.page_meta_description' => [
            '{$page.name} — оптовый интернет-магазин обуви {$store_info.name}: опт ящиками в Одессе на 7 км и доставка по Украине.',
            '{$page.name} — оптовий інтернет-магазин взуття {$store_info.name}: опт ящиками в Одесі на 7 км і доставка по Україні.'],
    ];

    /**
     * Служебные страницы: ключ → [RU, UA]. seo.* — шаблоны (витрина подставляет {$store_info.name}),
     * blog.* — обычный текст (Front\BlogController выводит как есть): {$store_info.name} подставляется при записи.
     */
    public const SERVICE = [
        'seo.reviews_meta_title' => ['Отзывы о магазине {$store_info.name} — обувь оптом в Одессе',
            'Відгуки про магазин {$store_info.name} — взуття оптом в Одесі'],
        'seo.reviews_meta_description' => [
            'Отзывы покупателей о магазине {$store_info.name}: обувь, заказ и доставка по Украине. Опт ящиками в Одессе на 7 км — читайте и оставляйте свой отзыв.',
            'Відгуки покупців про магазин {$store_info.name}: взуття, замовлення та доставка по Україні. Опт ящиками в Одесі на 7 км — читайте та залишайте свій відгук.'],
        'seo.sitemap_meta_title' => ['Карта сайта: все разделы каталога обуви оптом | {$store_info.name}',
            'Карта сайту: усі розділи каталогу взуття оптом | {$store_info.name}'],
        'seo.sitemap_meta_description' => [
            'Все разделы оптового интернет-магазина обуви {$store_info.name}: детская, женская, мужская и подростковая обувь, бренды, доставка и оплата.',
            'Усі розділи оптового інтернет-магазину взуття {$store_info.name}: дитяче, жіноче, чоловіче та підліткове взуття, бренди, доставка й оплата.'],
        'blog.meta_title' => ['Блог {$store_info.name}: статьи о выборе и уходе за детской обувью',
            'Блог {$store_info.name}: статті про вибір і догляд за дитячим взуттям'],
        'blog.meta_description' => [
            'Советы родителям и оптовым покупателям: как выбрать детскую обувь, ортопедические модели, уход и хранение, обзоры брендов — блог магазина {$store_info.name}.',
            'Поради батькам і оптовим покупцям: як вибрати дитяче взуття, ортопедичні моделі, догляд і зберігання, огляди брендів — блог магазину {$store_info.name}.'],
    ];

    /**
     * Шаблоны старого сайта (Webasyst), которые возвращает перенос (шаг settings bin/import-webasyst.php) и
     * i18n-seed-uk --force: вне нормы или с ошибками по смыслу («детскую обувь» для всех, «от 12 ящиков», «круглосуточно»),
     * шаблоны бренда старый сайт вообще не применял. Сравнение — Seo::norm (без регистра и лишних пробелов).
     */
    private const LEGACY = [
        'Купить обувь  {$product.name} оптом в Украине от интернет-магазина {$store_info.name}',
        'Купити взуття {$product.name} оптом в Україні від інтернет-магазину {$store_info.name}',
        'Интернет-магазин {$store_info.name} предлагает  детскую обувь оптом в Украине, модель обуви {$product.name} с размерним рядом  {$category.seo_name}. Цена на детскую обувь {$product.format_price} за пару. Бесплатная доставка по Украине при заказе от 12 ящиков. Звоните {$store_info.phone} круглосуточно.',
        'Інтернет-магазин {$store_info.name} пропонує дитяче взуття оптом в Україні, модель взуття {$product.name} з розмірним рядом {$category.seo_name}. Ціна на дитяче взуття {$product.format_price} за пару. Безкоштовна доставка по Україні при замовленні від 12 ящиків. Телефонуйте {$store_info.phone} цілодобово.',
        '{$category.seo_name} купить оптом в интернет-магазине {$store_info.name}',
        '{$category.seo_name} купити оптом в інтернет-магазині {$store_info.name}',
        'Купить обувь {$category.seo_name} оптом от производителя, лучшие бренды обуви в  интернет магазин Томобувь Украина. Предлагаем вам оптом брендовую обувь  {$category.seo_name} Звоните {$store_info.phone}',
        'Купити взуття {$category.seo_name} оптом від виробника, найкращі бренди взуття в інтернет-магазині ТомВзуття Україна. Пропонуємо вам оптом брендове взуття {$category.seo_name}. Телефонуйте {$store_info.phone}',
        '{$brand.name} купить в интернет-магазине {$store_info.name}',
        '{$brand.name} купити в інтернет-магазині {$store_info.name}',
        'Детская обувь бренда  {$brand.name}   от производителя, лучшие бренды детской обуви в  интернет магазин Томобувь. Предлагаем вам оптом брендовую обувь  {$brand.name}',
        'Дитяче взуття бренду {$brand.name} від виробника, найкращі бренди дитячого взуття в інтернет-магазині ТомВзуття. Пропонуємо вам оптом брендове взуття {$brand.name}',
    ];

    /** Хвосты короткого description своего значения: самый длинный из влезающих */
    private const DESC_TAILS = [
        'ru' => [' Купить оптом ящиками в Одессе на 7 км или с доставкой по Украине — интернет-магазин {store}.',
                 ' Опт ящиками в Одессе на 7 км и доставка по Украине — {store}.'],
        'uk' => [' Купити оптом ящиками в Одесі на 7 км або з доставкою по Україні — інтернет-магазин {store}.',
                 ' Опт ящиками в Одесі на 7 км і доставка по Україні — {store}.'],
    ];

    /** Сущность → [таблица, колонка title, колонка description, часть, группа итогов] */
    private const ENTITIES = [
        'product'  => ['products', 'meta_title', 'meta_description', 'products', 'products'],
        'category' => ['categories', 'meta_title', 'meta_description', 'categories', 'categories'],
        'brand'    => ['brands', 'title', 'meta_description', 'brands', 'brands'],
        'page'     => ['pages', 'title', 'meta_description', 'pages', 'pages'],
        'blog'     => ['blog_posts', 'meta_title', 'meta_description', 'blog', 'blog'],
    ];

    // ------------------------------------------------------------------ состояние запуска
    private bool $apply;
    private array $parts;
    private array $opt;
    private ?int $batch = null;
    private string $store;
    private string $now;
    /** Шаблоны до запуска и после решения по ним: [ru|uk][ключ] → текст (у uk — «.uk», иначе русский, как Settings::get) */
    private array $tplBefore = [];
    private array $tplAfter = [];
    private array $raw = [];
    private array $storeInfo = [];
    private array $same = [];
    private array $report = [];
    private int $examplesPer;

    private function __construct(bool $apply, array $opt)
    {
        $this->apply = $apply;
        $this->opt = $opt;
        $parts = $opt['parts'] ?? array_keys(self::PARTS);
        $this->parts = array_values(array_intersect(array_keys(self::PARTS), $parts));
        $this->examplesPer = (int) ($opt['examples'] ?? 6);
        $this->now = date('Y-m-d H:i:s');
        $this->raw = App::db()->pairs('SELECT name, value FROM settings');
        $this->store = trim((string) ($this->raw['store_name'] ?? '')) ?: 'Tomobuv';
        $this->report = ['apply' => $apply, 'batch' => null, 'parts' => $this->parts, 'changes' => 0, 'rules' => [],
            'groups' => [], 'examples' => [], 'left' => [], 'templates' => [], 'time' => 0.0];
        foreach (self::GROUPS as $g => $label) $this->report['groups'][$g] = ['label' => $label, 'objects' => 0, 'changes' => 0, 'rules' => [], 'stats' => []];
    }

    /**
     * Проверка (apply = false) или применение одним пакетом. $opt: parts — части (ключи PARTS, по умолчанию все),
     * source — cli|admin|import|i18n, user_id, note, examples — сколько примеров на (группа, правило, язык),
     * onChange — callable(array $change) для построчной печати «было → стало».
     * Возвращает отчёт: batch, changes, rules, groups (итоги «в норме» до/после по языкам и полям), examples, left, templates.
     */
    public static function run(bool $apply, array $opt = []): array
    {
        $t = microtime(true);
        if (function_exists('set_time_limit')) @set_time_limit(0);
        $f = new self($apply, $opt);
        $db = App::db();
        if ($apply && (int) $db->value('SELECT GET_LOCK(?, 10)', [self::LOCK]) !== 1) {
            throw new \RuntimeException('Автоисправление SEO уже выполняется — повторите позже.');
        }
        try {
            Catalog::categories();            // справочник — в русской версии до расчётов /ua/ (как SeoAudit::inLang)
            SeoVars::reset();
            if ($apply) {
                $f->batch = $db->insert('seo_fix_batches', ['created_at' => $f->now, 'user_id' => $opt['user_id'] ?? null,
                    'source' => mb_substr((string) ($opt['source'] ?? 'cli'), 0, 16), 'note' => mb_substr((string) ($opt['note'] ?? ''), 0, 255),
                    'version' => self::VERSION, 'parts' => implode(',', $f->parts)]);
                $f->report['batch'] = $f->batch;
            }
            $f->templates();
            if (in_array('settings', $f->parts, true)) $f->servicePages();
            if (in_array('categories', $f->parts, true)) $f->smallGroup('category');
            if (in_array('brands', $f->parts, true)) $f->smallGroup('brand');
            if (in_array('pages', $f->parts, true)) $f->smallGroup('page');
            if (in_array('blog', $f->parts, true)) $f->smallGroup('blog');
            if (in_array('products', $f->parts, true)) $f->products();
            $f->report['time'] = round(microtime(true) - $t, 1);
            if ($apply) {
                if ($f->report['changes'] === 0) {
                    $db->delete('seo_fix_batches', 'id = ?', [$f->batch]);     // пустой пакет не храним (повторный запуск)
                    $f->report['batch'] = null;
                } else {
                    $db->update('seo_fix_batches', ['changes' => $f->report['changes'],
                        'summary' => json_encode(self::summary($f->report), JSON_UNESCAPED_UNICODE)], 'id = ?', [$f->batch]);
                    if (in_array('settings', $f->parts, true)) Settings::set('seo.standard_version', (string) self::VERSION);
                    Settings::reset();
                    Cache::flush();
                    SeoVars::reset();
                }
            }
        } catch (\Throwable $e) {
            // прервано на середине: записанные пачки (каждая — своя транзакция с журналом) остаются в пакете — его можно откатить
            if ($apply && $f->batch) {
                $n = (int) $db->value('SELECT COUNT(*) FROM seo_fix_log WHERE batch_id = ?', [$f->batch]);
                if ($n === 0) $db->delete('seo_fix_batches', 'id = ?', [$f->batch]);
                else $db->update('seo_fix_batches', ['changes' => $n, 'note' => mb_substr('прервано: ' . $e->getMessage(), 0, 255)], 'id = ?', [$f->batch]);
                Settings::reset();
                Cache::flush();
            }
            throw $e;
        } finally {
            if ($apply) $db->value('SELECT RELEASE_LOCK(?)', [self::LOCK]);
        }
        return $f->report;
    }

    /** Итоги для таблицы пакетов (без примеров) */
    private static function summary(array $r): array
    {
        $g = [];
        foreach ($r['groups'] as $k => $x) if ($x['changes'] || $x['objects']) $g[$k] = ['changes' => $x['changes'], 'rules' => $x['rules'], 'stats' => $x['stats']];
        return ['rules' => $r['rules'], 'groups' => $g, 'time' => $r['time'], 'parts' => $r['parts'], 'left' => count($r['left'])];
    }

    // ================================================================== шаблоны групп и служебные страницы

    /** Решить по шаблонам групп (правило е); при parts без settings — только прочитать текущие */
    private function templates(): void
    {
        $withSettings = in_array('settings', $this->parts, true);
        $keys = array_unique(array_merge(array_keys(self::TEMPLATES), ['seo.page_meta_title']));
        foreach ($keys as $key) {
            $ru = trim((string) ($this->raw[$key] ?? ''));
            $uk = trim((string) ($this->raw[$key . '.uk'] ?? ''));
            $this->tplBefore['ru'][$key] = $ru;
            $this->tplBefore['uk'][$key] = $uk !== '' ? $uk : $ru;
            $newRu = $ru;
            $newUk = $uk;
            if ($withSettings && isset(self::TEMPLATES[$key])) {
                [$stdRu, $stdUk] = self::TEMPLATES[$key];
                $whyRu = $this->templateVerdict($key, 'ru', $ru, $stdRu);
                if ($whyRu !== null) $newRu = $stdRu;
                $whyUk = null;
                if ($uk === '') {
                    // пустой UA — на /ua/ работает русский шаблон; русский стандартный — пишем украинский стандарт
                    if (Seo::norm($newRu) === Seo::norm($stdRu)) { $newUk = $stdUk; $whyUk = 'нет украинского варианта'; }
                } else {
                    $whyUk = $this->templateVerdict($key, 'uk', $uk, $stdUk);
                    if ($whyUk !== null) $newUk = $stdUk;
                }
                foreach ([['ru', $key, $ru, $newRu, $whyRu], ['uk', $key . '.uk', $uk, $newUk, $whyUk]] as [$lang, $name, $old, $new, $why]) {
                    if ($why === null || $new === $old) continue;
                    $this->setting($name, $lang, $this->raw[$name] ?? null, $new, 'template', $why);
                    $this->report['templates'][$name] = ['old' => $old, 'new' => $new, 'why' => $why];
                }
            }
            $this->tplAfter['ru'][$key] = $newRu;
            $this->tplAfter['uk'][$key] = $newUk !== '' ? $newUk : $newRu;
        }
    }

    /** Почему шаблон заменяется стандартным (null — остаётся): пуст, шаблон Webasyst, в норме < 98% объектов группы */
    private function templateVerdict(string $key, string $lang, string $cur, string $std): ?string
    {
        if (Seo::norm($cur) === Seo::norm($std)) return null;
        if ($cur === '') return 'шаблон пуст';
        foreach (self::LEGACY as $l) if (Seo::norm($l) === Seo::norm($cur)) return 'шаблон старого сайта (Webasyst)';
        $share = $this->share($key, $lang, $cur);
        return $share < self::KEEP_SHARE ? sprintf('в норме %.1f%% объектов группы (нужно от %d%%)', $share * 100, self::KEEP_SHARE * 100) : null;
    }

    /** Доля объектов группы (на сайте), у которых шаблон в норме сам (без обрезки Seo::fit), «как будто своих нет» */
    private function share(string $key, string $lang, string $tpl): float
    {
        $field = Seo::fieldOf($key) ?? 'title';
        $store = ['store_info' => $this->storeInfo($lang)];
        $n = 0;
        $ok = 0;
        $count = static function (string $text) use (&$n, &$ok, $field): void {
            $n++;
            if (Seo::inNorm($text, $field)) $ok++;
        };
        SeoAudit::inLang($lang, function () use ($key, $tpl, $field, $store, $count): void {
            $db = App::db();
            if (str_starts_with($key, 'seo.product_')) {
                $last = 0;
                while ($rows = $db->query('SELECT id, name, name_uk, sku, seo_name, seo_name_uk, price, box_qty, size, category_id FROM products
                    WHERE status = 1 AND id > ? ORDER BY id LIMIT ' . self::CHUNK, [$last])->fetchAll()) {
                    foreach ($rows as $p) {
                        $last = (int) $p['id'];
                        $p = $this->loc($p);
                        $count(Seo::build($tpl, SeoVars::product($p, SeoVars::productCategory($p)) + $store, $field, false));
                    }
                }
            } elseif (str_starts_with($key, 'seo.category_')) {
                foreach ($db->query('SELECT id, parent_id, depth, name, name_uk, seo_name, seo_name_uk, product_count FROM categories WHERE status = 1')->fetchAll() as $c) {
                    $count(Seo::build($tpl, SeoVars::category($this->loc($c)) + $store, $field, false));
                }
            } elseif (str_starts_with($key, 'seo.brand_')) {
                foreach ($db->query('SELECT id, name, name_uk, product_count FROM brands WHERE hidden = 0 AND product_count > 0')->fetchAll() as $b) {
                    $count(Seo::build($tpl, SeoVars::brand($this->loc($b)) + $store, $field, false));
                }
            } elseif (str_starts_with($key, 'seo.page_')) {
                foreach ($db->query('SELECT id, name, name_uk FROM pages WHERE status = 1')->fetchAll() as $p) {
                    $p = $this->loc($p);
                    $count(Seo::build($tpl, ['page' => ['name' => (string) $p['name'], 'title' => '']] + $store, $field, false));
                }
            }
        });
        return $n ? $ok / $n : 1.0;
    }

    /** Служебные страницы (правило д): отзывы, карта сайта, список блога; главная — только в итогах */
    private function servicePages(): void
    {
        $g = 'service';
        foreach (self::SERVICE as $key => [$stdRu, $stdUk]) {
            $field = Seo::fieldOf($key) ?? 'title';
            $plain = str_starts_with($key, 'blog.');          // blog.* витрина выводит как есть — подставляем магазин сейчас
            if ($plain) {
                $stdRu = str_replace('{$store_info.name}', $this->store, $stdRu);
                $stdUk = str_replace('{$store_info.name}', $this->store, $stdUk);
            }
            $ru = trim((string) ($this->raw[$key] ?? ''));
            $uk = trim((string) ($this->raw[$key . '.uk'] ?? ''));
            $shown = fn(string $v, string $lang) => $plain ? $v : Seo::build($v, ['store_info' => $this->storeInfo($lang)], $field);
            $newRu = ($ru === '' || !Seo::inNorm($shown($ru, 'ru'), $field)) ? $stdRu : $ru;
            $newUk = $uk;
            if ($uk === '' ? Seo::norm($newRu) === Seo::norm($stdRu) : !Seo::inNorm($shown($uk, 'uk'), $field)) $newUk = $stdUk;
            foreach ([['ru', $key, $ru, $newRu], ['uk', $key . '.uk', $uk, $newUk]] as [$lang, $name, $old, $new]) {
                if ($new === $old) continue;
                $this->setting($name, $lang, $this->raw[$name] ?? null, $new, 'text', $old === '' ? 'пусто' : 'не в норме');
            }
            $this->tplAfter['ru'][$key] = $newRu;
            $this->tplAfter['uk'][$key] = $newUk !== '' ? $newUk : $newRu;
            $this->tplBefore['ru'][$key] = $ru;
            $this->tplBefore['uk'][$key] = $uk !== '' ? $uk : $ru;
        }
        // итоги: главная (не меняется), /reviews/, /sitemap/, /blog/ — что выводит витрина до и после
        foreach (['ru', 'uk'] as $lang) {
            SeoAudit::inLang($lang, function () use ($lang, $g): void {
                $st = ['store_info' => $this->storeInfo($lang)];
                $home = [(string) Settings::get('seo.home_page_meta_title', (string) Settings::get('site_title', '')), (string) Settings::get('seo.home_page_meta_description', '')];
                $this->count($g, $lang, 'title', $home[0], $home[0]);
                $this->count($g, $lang, 'desc', $home[1], $home[1]);
                $blogName = (string) Settings::get('blog.name', $this->store);
                foreach ([['reviews', t('Отзывы'), ''], ['sitemap', t('Карта сайта') . ' — ' . $this->store, ''], ['blog', $blogName, '']] as [$page, $defT, $defD]) {
                    $kt = $page === 'blog' ? 'blog.meta_title' : "seo.{$page}_meta_title";
                    $kd = $page === 'blog' ? 'blog.meta_description' : "seo.{$page}_meta_description";
                    $val = function (array $tpl, string $k, string $field) use ($lang, $page, $st): string {
                        $v = (string) ($tpl[$lang][$k] ?? '');
                        return $page === 'blog' ? trim($v) : Seo::build($v, $st, $field);
                    };
                    $this->count($g, $lang, 'title', $val($this->tplBefore, $kt, 'title') ?: $defT, $val($this->tplAfter, $kt, 'title') ?: $defT);
                    $this->count($g, $lang, 'desc', $val($this->tplBefore, $kd, 'description') ?: $defD, $val($this->tplAfter, $kd, 'description') ?: $defD);
                }
            });
        }
        $this->report['groups'][$g]['objects'] = 4;
    }

    /** Изменение настройки: запись (при применении) + журнал + отчёт */
    private function setting(string $name, string $lang, ?string $old, string $new, string $rule, string $why): void
    {
        $ch = ['group' => 'service', 'entity' => 'setting', 'id' => 0, 'name' => $name, 'col' => $name, 'lang' => $lang,
            'field' => Seo::fieldOf($name) === 'description' ? 'desc' : 'title', 'old' => $old, 'new' => $new, 'rule' => $rule, 'why' => $why];
        if ($this->apply) {
            App::db()->transaction(function ($db) use ($name, $new, $ch): void {
                $db->upsert('settings', ['name' => $name, 'value' => $new], ['value']);
                $this->journal([$ch]);
            });
        }
        $this->record($ch);
    }

    // ================================================================== категории, бренды, страницы, статьи

    /** Небольшая группа целиком (одна транзакция с блокировкой строк на время решения) */
    private function smallGroup(string $entity): void
    {
        [$table, $cT, $cD, , $g] = self::ENTITIES[$entity];
        $cols = match ($entity) {
            'category' => 'id, parent_id, depth, name, name_uk, url, status, seo_name, seo_name_uk, product_count',
            'brand'    => 'id, name, name_uk, url, hidden, product_count',
            'page'     => 'id, url, name, name_uk, status, content, content_uk',
            'blog'     => 'id, url, title, title_uk, status, published_at, text_before_cut, text_before_cut_uk, text, text_uk',
        };
        $cols .= ", $cT, {$cT}_uk, $cD, {$cD}_uk";
        $work = function (bool $lock) use ($table, $cols, $entity): void {
            $rows = App::db()->query("SELECT $cols FROM `$table` ORDER BY id" . ($lock ? ' FOR UPDATE' : ''))->fetchAll();
            $this->process($entity, $rows);
        };
        if ($this->apply) App::db()->transaction(static fn() => $work(true));
        else $work(false);
    }

    // ================================================================== товары

    /** Товары — пачками по id: чтение с блокировкой, решение в PHP, до 8 UPDATE на пачку, журнал; одна транзакция на пачку */
    private function products(): void
    {
        $db = App::db();
        $last = 0;
        $sql = 'SELECT id, url, name, name_uk, sku, seo_name, seo_name_uk, price, box_qty, size, category_id, status,
            meta_title, meta_title_uk, meta_description, meta_description_uk FROM products WHERE id > ? ORDER BY id LIMIT ' . self::CHUNK;
        while (true) {
            $n = 0;
            $step = function (bool $lock) use ($db, $sql, &$last, &$n): void {
                $rows = $db->query($sql . ($lock ? ' FOR UPDATE' : ''), [$last])->fetchAll();
                $n = count($rows);
                if (!$n) return;
                $last = (int) end($rows)['id'];
                $this->process('product', $rows);
            };
            if ($this->apply) $db->transaction(static fn() => $step(true));
            else $step(false);
            if ($n < self::CHUNK) break;
        }
    }

    // ================================================================== решение по объектам

    /** Решить по строкам группы, записать изменения (при применении) и посчитать итоги */
    private function process(string $entity, array $rows): void
    {
        [$table, $cT, $cD, , $g] = self::ENTITIES[$entity];
        $changes = [];
        $data = [];
        // русская версия
        SeoAudit::inLang('ru', function () use ($entity, $rows, $cT, $cD, &$data): void {
            foreach ($rows as $i => $r) {
                $o = ['row' => $r, 'live' => $this->live($entity, $r), 'names' => $this->names($entity, $r)];
                $auto = null;
                $autoFn = function (string $f) use (&$auto, $entity, $r): string {
                    $auto ??= $this->auto($entity, $r, 'after');
                    return $auto[$f];
                };
                foreach (['title' => $cT, 'desc' => $cD] as $f => $col) {
                    $o[$f] = $this->decideRu($f, (string) ($r[$col] ?? ''), $o['names'], $autoFn);
                    $own = trim((string) ($r[$col] ?? ''));
                    // «после» — и у скрытых (для примеров «станет на сайте»), «до» — только для итогов по адресам sitemap
                    $o[$f]['after_ru'] = $o[$f]['final'] !== '' ? $o[$f]['final'] : $autoFn($f);
                    if ($o['live']) $o[$f]['before_ru'] = $own !== '' ? $own : ($this->sameTpl('ru') ? $o[$f]['after_ru'] : $this->autoBefore($entity, $r, 'ru')[$f]);
                }
                $data[$i] = $o;
            }
        });
        // украинская версия (/ua/): свои *_uk, иначе русское своё, иначе украинский шаблон
        SeoAudit::inLang('uk', function () use ($entity, $rows, $cT, $cD, &$data): void {
            foreach ($rows as $i => $r) {
                $o = &$data[$i];
                $auto = null;
                $autoFn = function (string $f) use (&$auto, $entity, $r): string {
                    $auto ??= $this->auto($entity, $this->loc($r), 'after');
                    return $auto[$f];
                };
                foreach (['title' => $cT, 'desc' => $cD] as $f => $col) {
                    $rawUk = (string) ($r[$col . '_uk'] ?? '');
                    $o[$f]['uk'] = $this->decideUk($f, $rawUk, (string) ($r[$col] ?? ''), $o['names'], $o[$f], $autoFn);
                    $finUk = $o[$f]['uk']['final'];
                    $o[$f]['after_uk'] = $finUk !== '' ? $finUk : ($o[$f]['final'] !== '' ? $o[$f]['final'] : $autoFn($f));
                    if ($o['live']) {
                        $ownUk = trim($rawUk);
                        $ownRu = trim((string) ($r[$col] ?? ''));
                        $o[$f]['before_uk'] = $ownUk !== '' ? $ownUk : ($ownRu !== '' ? $ownRu
                            : ($this->sameTpl('uk') ? $autoFn($f) : $this->autoBefore($entity, $this->loc($r), 'uk')[$f]));
                    }
                }
                unset($o);
            }
        });
        foreach ($rows as $i => $r) {
            $o = $data[$i];
            $id = (int) $r['id'];
            $name = (string) ($r['name'] ?? $r['title'] ?? '');
            if ($o['live']) $this->report['groups'][$g]['objects']++;
            foreach (['title' => $cT, 'desc' => $cD] as $f => $col) {
                $x = $o[$f];
                foreach (['ru' => [$col, $x['change'] ?? null], 'uk' => [$col . '_uk', $x['uk']['change'] ?? null]] as $lang => [$c, $ch]) {
                    if ($o['live']) $this->count($g, $lang, $f, $x['before_' . $lang], $x['after_' . $lang]);
                    if ($ch === null) continue;
                    $changes[] = ['group' => $g, 'entity' => $entity, 'id' => $id, 'name' => $name, 'col' => $c, 'lang' => $lang, 'field' => $f,
                        'old' => $r[$c], 'new' => $ch['new'], 'rule' => $ch['rule'], 'live' => $o['live'], 'shown' => $x['after_' . $lang]];
                }
                if ($o['live']) {
                    foreach (['ru', 'uk'] as $lang) {
                        $shown = $x['after_' . $lang];
                        if (!Seo::inNorm($shown, $f === 'title' ? 'title' : 'description') && count($this->report['left']) < 200) {
                            $this->report['left'][] = ['group' => $g, 'entity' => $entity, 'id' => $id, 'name' => $name, 'lang' => $lang, 'field' => $f,
                                'text' => $shown, 'len' => mb_strlen(trim($shown))];
                        }
                    }
                }
            }
        }
        if (!$changes) return;
        if ($this->apply) $this->write($table, $changes);
        foreach ($changes as $ch) $this->record($ch);
    }

    /** Русское своё значение: [final — что останется своим ('' — пусто, работает шаблон), change — null или [new, rule]] */
    private function decideRu(string $f, string $raw, array $names, callable $auto): array
    {
        $field = $f === 'title' ? 'title' : 'description';
        $own = trim($raw);
        if ($own === '' || Seo::inNorm($own, $field)) return ['final' => $own, 'change' => null];
        $machine = Seo::isMachine($own, $names, $field);
        if (($machine || self::isKeywordSet($own, $field)) && Seo::inNorm($auto($f), $field)) {
            return ['final' => '', 'change' => ['new' => null, 'rule' => $machine ? 'clear' : 'keywords']];
        }
        $fixed = $this->fixOwn($own, $field, 'ru');
        return $fixed !== $raw ? ['final' => $fixed, 'change' => ['new' => $fixed, 'rule' => 'fix']] : ['final' => $own, 'change' => null];
    }

    /** Украинское своё значение (*_uk) при уже решённом русском $ru */
    private function decideUk(string $f, string $raw, string $rawRu, array $names, array $ru, callable $auto): array
    {
        $field = $f === 'title' ? 'title' : 'description';
        $own = trim($raw);
        if ($own === '') {
            // русское своё исправлено, украинского нет — то же с украинским хвостом (иначе /ua/ покажет русский текст с русским хвостом)
            if (($ru['change']['rule'] ?? '') === 'fix' && $ru['final'] !== '') {
                $uk = $this->fixOwn(trim($rawRu), $field, 'uk');
                if ($uk !== $ru['final']) return ['final' => $uk, 'change' => ['new' => $uk, 'rule' => 'uk_fill']];
            }
            return ['final' => '', 'change' => null];
        }
        if (Seo::inNorm($own, $field)) return ['final' => $own, 'change' => null];
        $machine = Seo::isMachine($own, $names, $field);
        if ($machine || self::isKeywordSet($own, $field)) {
            $a = $auto($f);
            if (Seo::inNorm($a, $field)) {
                if ($ru['final'] === '') return ['final' => '', 'change' => ['new' => null, 'rule' => $machine ? 'clear' : 'keywords']];
                // русское своё остаётся: пустой *_uk показал бы на /ua/ русский текст — пишем текст украинского шаблона
                return ['final' => $a, 'change' => ['new' => $a, 'rule' => 'uk_tpl']];
            }
        }
        $fixed = $this->fixOwn($own, $field, 'uk');
        return $fixed !== $raw ? ['final' => $fixed, 'change' => ['new' => $fixed, 'rule' => 'fix']] : ['final' => $own, 'change' => null];
    }

    /**
     * Исправить своё значение вне нормы (правило в): повторы подряд слова/фразы до 3 слов, заглавная буква,
     * пробел после точки перед заглавной («Украине.Милые»; «Y.TOP» не трогается). Короткий title — хвост из частей,
     * которых ещё нет в тексте (« оптом», « — купить в Одессе», « | Tomobuv»), не влезает — части снимаются с конца;
     * короткий description — точка и самый длинный влезающий хвост; длинное — Seo::fit.
     */
    public function fixOwn(string $text, string $field, string $lang): string
    {
        $s = self::clean($text);
        [$min, $max] = Seo::LIMITS[$field];
        $len = mb_strlen($s);
        if ($len > $max) return Seo::fit($s, $field);
        if ($len >= $min) return $s;
        $lc = mb_strtolower($s);
        $brand = str_contains($lc, mb_strtolower($this->store)) || preg_match('/том\s?(обувь|взуття)|tomobuv/u', $lc);
        if ($field === 'title') {
            $tails = [];
            if (!str_contains($lc, 'опт')) $tails[] = ' оптом';
            if (!str_contains($lc, 'купи') && !str_contains($lc, 'одес')) $tails[] = $lang === 'uk' ? ' — купити в Одесі' : ' — купить в Одессе';
            if (!$brand) $tails[] = ' | ' . $this->store;
            while ($tails) {
                $cand = $s . implode('', $tails);
                if (mb_strlen($cand) <= $max) return $cand;
                array_pop($tails);
            }
            return $s;
        }
        $base = preg_match('/[.!?…]$/u', $s) ? $s : $s . '.';
        foreach (self::DESC_TAILS[$lang === 'uk' ? 'uk' : 'ru'] as $tail) {
            $cand = $base . str_replace('{store}', $this->store, $tail);
            if (mb_strlen($cand) <= $max) return $cand;
        }
        return $base;
    }

    /**
     * Длинный title — набор ключевых слов («оптовый интернет магазин обуви украина украина детская обувь оптом оптовая продажа…»):
     * от 8 слов, без знаков препинания, основы слов повторяются (три разные или одна трижды). Обрезка по слову такой набор
     * не спасает — как и машинное значение, он очищается, если шаблон даёт текст в норме.
     */
    public static function isKeywordSet(string $s, string $field): bool
    {
        $s = self::clean($s);                 // повтор подряд («Том Обувь Том Обувь») — не набор, его уберёт чистка
        if ($field !== 'title' || mb_strlen($s) <= Seo::TITLE_MAX || preg_match('/[.,!?;:|—–«»()]/u', $s)) return false;
        $words = preg_split('/\s+/u', mb_strtolower($s), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (count($words) < 8) return false;
        $stems = [];
        foreach ($words as $w) if (mb_strlen($w) >= 4) $stems[mb_substr($w, 0, 5)] = ($stems[mb_substr($w, 0, 5)] ?? 0) + 1;
        $rep = array_filter($stems, static fn($n) => $n >= 2);
        return count($rep) >= 3 || max($stems ?: [0]) >= 3;
    }

    /** Названия городов и страны в своих текстах — с заглавной («одесса» → «Одесса», «україні» → «Україні») */
    private const PROPER = '/(?<!\p{L})(одесс[аеуыой]|одесой|одес[аіиу]|одесою|украин[аеуыой]|украиной|україн[аіиуою]|україною|киев[аеу]?|київ|києв[іу]?)(?!\p{L})/u';

    /** Чистка своего текста: пробелы, повторы подряд (до 3 слов), заглавная буква, пробел после точки, «Одесса», «Украина» */
    public static function clean(string $s): string
    {
        $s = trim((string) preg_replace('/\s+/u', ' ', $s));
        do {
            $prev = $s;
            $s = (string) preg_replace('/(?<![\p{L}\p{N}])((?:[\p{L}\p{N}][\p{L}\p{N}\-]*\s+){0,2}[\p{L}\p{N}][\p{L}\p{N}\-]*)(?:\s+\1)+(?![\p{L}\p{N}])/iu', '$1', $s);
        } while ($s !== $prev);
        $s = (string) preg_replace('/(?<=\p{Ll})([.!?])(?=\p{Lu})/u', '$1 ', $s);
        $s = (string) preg_replace_callback(self::PROPER, static fn($m) => mb_strtoupper(mb_substr($m[1], 0, 1)) . mb_substr($m[1], 1), $s);
        $s = Seo::tidy($s);
        $first = mb_substr($s, 0, 1);
        return $first !== '' && $first !== mb_strtoupper($first) ? mb_strtoupper($first) . mb_substr($s, 1) : $s;
    }

    // ================================================================== что выводит витрина

    /** Объект есть в sitemap.xml (итоги «в норме» — только по ним; исправляются все) */
    private function live(string $entity, array $r): bool
    {
        return match ($entity) {
            'product', 'category', 'page' => (int) $r['status'] === 1,
            'brand' => !(int) $r['hidden'] && (int) $r['product_count'] > 0,
            'blog'  => $r['status'] === 'published' && (string) $r['published_at'] <= $this->now,
        };
    }

    /** Названия объекта на обоих языках — для распознавания «машинного» title */
    private function names(string $entity, array $r): array
    {
        $n = match ($entity) {
            'blog'  => [$r['title'], $r['title_uk']],
            default => [$r['name'], $r['name_uk']],
        };
        if ($entity === 'product') {
            $n[] = Seo::machineName((string) $r['meta_description']);
            $n[] = Seo::machineName((string) $r['meta_description_uk']);
        }
        return array_values(array_filter(array_map(static fn($v) => trim((string) $v), $n), 'strlen'));
    }

    /** Шаблоны версии сайта не менялись этим запуском — «до» без своего значения = «после» (не считаем дважды) */
    private function sameTpl(string $lang): bool
    {
        return $this->same[$lang] ??= ($this->tplBefore[$lang] ?? []) == ($this->tplAfter[$lang] ?? []);
    }

    /** Что покажет витрина без своего значения — шаблоны до запуска */
    private function autoBefore(string $entity, array $r, string $lang): array
    {
        return $this->auto($entity, $r, 'before');
    }

    /**
     * Title/description без своего значения — как контроллеры витрины (Front\ProductController::show, CategoryController::seo,
     * BrandController::seo, PageController::seo, BlogController::post); $r — строка на языке текущей версии (для /ua/ — с *_uk).
     * $when: before — шаблоны до запуска, after — после решения по шаблонам.
     */
    private function auto(string $entity, array $r, string $when): array
    {
        $lang = Lang::current();
        $tpl = ($when === 'before' ? $this->tplBefore : $this->tplAfter)[$lang] ?? [];
        $st = ['store_info' => $this->storeInfo($lang)];
        $b = static fn(string $key, array $vars) => Seo::build((string) ($tpl[$key] ?? ''), $vars, Seo::fieldOf($key));
        switch ($entity) {
            case 'product':
                $vars = SeoVars::product($r, SeoVars::productCategory($r)) + $st;
                return ['title' => $b('seo.product_meta_title', $vars) ?: (string) $r['name'], 'desc' => $b('seo.product_meta_description', $vars)];
            case 'category':
                $on = (bool) ($this->raw['seo.category_is_enabled'] ?? 1);
                $vars = SeoVars::category($r) + $st;
                return ['title' => ($on ? $b('seo.category_meta_title', $vars) : '') ?: (string) $r['name'],
                        'desc'  => $on ? $b('seo.category_meta_description', $vars) : ''];
            case 'brand':
                $on = (string) ($this->raw['seo.brand_is_enabled'] ?? '1') !== '0';
                $vars = SeoVars::brand($r) + $st;
                return ['title' => ($on ? $b('seo.brand_meta_title', $vars) : '') ?: trim((string) $r['name']),
                        'desc'  => $on ? $b('seo.brand_meta_description', $vars) : ''];
            case 'page':
                $on = (string) ($this->raw['seo.page_is_enabled'] ?? '1') !== '0';
                $vars = ['page' => ['name' => (string) $r['name'], 'title' => '']] + $st;
                $desc = Seo::excerpt((string) $r['content']);
                return ['title' => ($on ? $b('seo.page_meta_title', $vars) : '') ?: (string) $r['name'],
                        'desc'  => $desc !== '' ? $desc : ($on ? $b('seo.page_meta_description', $vars) : '')];
            case 'blog':
                $blogName = (string) Settings::get('blog.name', $this->store);
                return ['title' => $blogName . ' » ' . $r['title'], 'desc' => Seo::excerpt(BlogController::excerptSource($r))];
        }
        return ['title' => '', 'desc' => ''];
    }

    /** {$store_info.*} для версии сайта (store_name.uk, если задан) — один раз на запуск */
    private function storeInfo(string $lang): array
    {
        return $this->storeInfo[$lang] ??= SeoAudit::inLang($lang, static fn() => Seo::storeInfo());
    }

    /** Строка базы глазами /ua/: x_uk вместо x, если заполнено (как DB::$localize) */
    private function loc(array $r): array
    {
        return Lang::localize($r);
    }

    // ================================================================== запись, журнал, отчёт

    /** Записать изменения строк таблицы: очистки — UPDATE … IN, значения — UPDATE … CASE, пачками; журнал */
    private function write(string $table, array $changes): void
    {
        $db = App::db();
        $clear = [];
        $set = [];
        foreach ($changes as $ch) {
            if ($ch['new'] === null) $clear[$ch['col']][] = $ch['id'];
            else $set[$ch['col']][$ch['id']] = $ch['new'];
        }
        foreach ($clear as $col => $ids) {
            foreach (array_chunk($ids, 1000) as $part) {
                [$ph, $vals] = $db->in($part);
                $db->query("UPDATE `$table` SET `$col` = NULL WHERE id IN ($ph)", $vals);
            }
        }
        foreach ($set as $col => $map) self::caseUpdate($table, $col, $map);
        $this->journal($changes);
    }

    /** UPDATE t SET col = CASE id WHEN ? THEN ? … END WHERE id IN (…) — пачками по 500 */
    private static function caseUpdate(string $table, string $col, array $map): void
    {
        $db = App::db();
        foreach (array_chunk($map, 500, true) as $part) {
            $sql = "UPDATE `$table` SET `$col` = CASE id";
            $params = [];
            foreach ($part as $id => $v) {
                $sql .= ' WHEN ? THEN ?';
                $params[] = (int) $id;
                $params[] = $v;
            }
            [$ph, $vals] = $db->in(array_map('intval', array_keys($part)));
            $db->query($sql . " ELSE `$col` END WHERE id IN ($ph)", array_merge($params, $vals));
        }
    }

    private function journal(array $changes): void
    {
        if (!$this->batch || !$changes) return;
        $rows = [];
        foreach ($changes as $ch) {
            $rows[] = ['batch_id' => $this->batch, 'entity' => $ch['entity'], 'entity_id' => $ch['id'], 'field' => $ch['col'], 'lang' => $ch['lang'],
                'rule' => $ch['rule'], 'old_value' => $ch['old'], 'new_value' => $ch['new'], 'created_at' => $this->now];
        }
        App::db()->insertMany('seo_fix_log', $rows);
    }

    /** Итоги «в норме» до и после по группе, языку и полю */
    private function count(string $g, string $lang, string $f, string $before, string $after): void
    {
        $field = $f === 'title' ? 'title' : 'description';
        $x = &$this->report['groups'][$g]['stats'][$lang][$f];
        $x ??= ['total' => 0, 'before' => 0, 'after' => 0, 'short' => 0, 'long' => 0, 'none' => 0];
        $x['total']++;
        if (Seo::inNorm($before, $field)) $x['before']++;
        if (Seo::inNorm($after, $field)) {
            $x['after']++;
        } else {
            $n = mb_strlen(trim($after));
            $x[$n === 0 ? 'none' : ($n < Seo::LIMITS[$field][0] ? 'short' : 'long')]++;
        }
        unset($x);
    }

    /** Учесть изменение в отчёте: счётчики, примеры, построчная печать */
    private function record(array $ch): void
    {
        $g = $ch['group'];
        $this->report['changes']++;
        $this->report['rules'][$ch['rule']] = ($this->report['rules'][$ch['rule']] ?? 0) + 1;
        $this->report['groups'][$g]['changes']++;
        $this->report['groups'][$g]['rules'][$ch['rule']] = ($this->report['groups'][$g]['rules'][$ch['rule']] ?? 0) + 1;
        $key = $g . '|' . $ch['rule'] . '|' . $ch['lang'] . '|' . $ch['field'];
        $ex = &$this->report['examples'][$key];
        $ex ??= [];
        if (count($ex) < $this->examplesPer) {
            $ex[] = ['group' => $g, 'entity' => $ch['entity'], 'id' => $ch['id'], 'name' => $ch['name'], 'col' => $ch['col'], 'lang' => $ch['lang'],
                'field' => $ch['field'], 'rule' => $ch['rule'], 'old' => $ch['old'], 'new' => $ch['new'], 'shown' => $ch['shown'] ?? ($ch['new'] ?? ''),
                'why' => $ch['why'] ?? ''];
        }
        unset($ex);
        if (isset($this->opt['onChange'])) ($this->opt['onChange'])($ch);
    }

    /** Примеры для предпросмотра: поровну из каждой группы/правила, всего не больше $limit */
    public static function examples(array $report, int $limit = 50): array
    {
        $buckets = array_values($report['examples']);
        $out = [];
        for ($i = 0; count($out) < $limit; $i++) {
            $added = false;
            foreach ($buckets as $b) {
                if (isset($b[$i]) && count($out) < $limit) { $out[] = $b[$i]; $added = true; }
            }
            if (!$added) break;
        }
        $order = array_flip(array_keys(self::GROUPS));
        usort($out, static fn($a, $b) => [$order[$a['group']] ?? 9, $a['entity'], $a['id'], $a['field'], $a['lang']]
            <=> [$order[$b['group']] ?? 9, $b['entity'], $b['id'], $b['field'], $b['lang']]);
        return $out;
    }

    // ================================================================== пакеты и откат

    /** Последние пакеты */
    public static function batches(int $limit = 20): array
    {
        if (!self::hasTables()) return [];
        $rows = App::db()->all('SELECT b.*, u.name AS user_name FROM seo_fix_batches b LEFT JOIN customers u ON u.id = b.user_id
            ORDER BY b.id DESC LIMIT ' . max(1, $limit));
        foreach ($rows as &$r) {
            $r['summary'] = json_decode((string) $r['summary'], true) ?: [];
            $r['revert'] = json_decode((string) ($r['revert_summary'] ?? ''), true) ?: [];
        }
        unset($r);
        return $rows;
    }

    /** Таблицы журнала есть (database/migrations/seo-autofix.sql выполнена) */
    public static function hasTables(): bool
    {
        return (int) App::db()->value("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME IN ('seo_fix_batches', 'seo_fix_log')") === 2;
    }

    /**
     * Откатить пакет: вернуть «было» там, где сейчас всё ещё «стало»; значения, изменённые с тех пор (вручную, импортом,
     * другим пакетом), пропускаются и перечисляются. $dry — только посчитать. Возвращает [restored, skipped, skipped_list].
     */
    public static function revert(int $batchId, ?int $userId = null, bool $dry = false): array
    {
        $db = App::db();
        $b = $db->row('SELECT * FROM seo_fix_batches WHERE id = ?', [$batchId]);
        if (!$b) throw new \RuntimeException("Пакета №$batchId нет.");
        if ($b['reverted_at'] !== null) throw new \RuntimeException("Пакет №$batchId уже откачен " . date('d.m.Y H:i', strtotime((string) $b['reverted_at'])) . '.');
        if (!$dry && (int) $db->value('SELECT GET_LOCK(?, 10)', [self::LOCK]) !== 1) {
            throw new \RuntimeException('Автоисправление SEO уже выполняется — повторите позже.');
        }
        $res = ['batch' => $batchId, 'restored' => 0, 'skipped' => 0, 'skipped_list' => [], 'by_entity' => []];
        try {
            $last = PHP_INT_MAX;
            while (true) {
                $log = $db->query('SELECT id, entity, entity_id, field, lang, old_value, new_value FROM seo_fix_log
                    WHERE batch_id = ? AND id < ? ORDER BY id DESC LIMIT 2000', [$batchId, $last])->fetchAll();
                if (!$log) break;
                $last = (int) end($log)['id'];
                $step = static function () use ($db, $log, $dry, &$res): void {
                    $byEntity = [];
                    foreach ($log as $l) $byEntity[$l['entity']][] = $l;
                    foreach ($byEntity as $entity => $list) {
                        if ($entity === 'setting') {
                            [$ph, $vals] = $db->in(array_unique(array_column($list, 'field')));
                            $cur = $db->pairs("SELECT name, value FROM settings WHERE name IN ($ph)" . ($dry ? '' : ' FOR UPDATE'), $vals);
                            foreach ($list as $l) {
                                $now = $cur[$l['field']] ?? null;
                                if ($now !== $l['new_value']) { self::skip($res, $l, $now); continue; }
                                if (!$dry) {
                                    if ($l['old_value'] === null) $db->delete('settings', 'name = ?', [$l['field']]);
                                    else $db->upsert('settings', ['name' => $l['field'], 'value' => $l['old_value']], ['value']);
                                }
                                $cur[$l['field']] = $l['old_value'];
                                self::restored($res, $entity);
                            }
                            continue;
                        }
                        $table = self::ENTITIES[$entity][0] ?? null;
                        if ($table === null) continue;
                        $allowed = [];
                        foreach ([1, 2] as $k) { $c = self::ENTITIES[$entity][$k]; $allowed[$c] = true; $allowed[$c . '_uk'] = true; }
                        $cols = array_values(array_intersect(array_keys($allowed), array_unique(array_column($list, 'field'))));
                        if (!$cols) continue;
                        [$ph, $vals] = $db->in(array_values(array_unique(array_map('intval', array_column($list, 'entity_id')))));
                        $cur = $db->keyed('SELECT id, `' . implode('`, `', $cols) . "` FROM `$table` WHERE id IN ($ph)" . ($dry ? '' : ' FOR UPDATE'), $vals);
                        $clear = [];
                        $set = [];
                        foreach ($list as $l) {
                            $id = (int) $l['entity_id'];
                            if (!isset($allowed[$l['field']])) continue;
                            $now = isset($cur[$id]) ? $cur[$id][$l['field']] : false;
                            if ($now === false || $now !== $l['new_value']) { self::skip($res, $l, $now === false ? '(объект удалён)' : $now); continue; }
                            if ($l['old_value'] === null) $clear[$l['field']][] = $id;
                            else $set[$l['field']][$id] = $l['old_value'];
                            $cur[$id][$l['field']] = $l['old_value'];
                            self::restored($res, $entity);
                        }
                        if ($dry) continue;
                        foreach ($clear as $col => $ids) {
                            foreach (array_chunk($ids, 1000) as $part) {
                                [$p2, $v2] = $db->in($part);
                                $db->query("UPDATE `$table` SET `$col` = NULL WHERE id IN ($p2)", $v2);
                            }
                        }
                        foreach ($set as $col => $map) self::caseUpdate($table, $col, $map);
                    }
                };
                if ($dry) $step(); else $db->transaction(static fn() => $step());
            }
            if (!$dry) {
                $db->update('seo_fix_batches', ['reverted_at' => date('Y-m-d H:i:s'), 'reverted_by' => $userId,
                    'revert_summary' => json_encode(['restored' => $res['restored'], 'skipped' => $res['skipped'],
                        'skipped_list' => array_slice($res['skipped_list'], 0, 50)], JSON_UNESCAPED_UNICODE)], 'id = ?', [$batchId]);
                Settings::reset();
                Cache::flush();
                SeoVars::reset();
            }
        } finally {
            if (!$dry) $db->value('SELECT RELEASE_LOCK(?)', [self::LOCK]);
        }
        return $res;
    }

    private static function restored(array &$res, string $entity): void
    {
        $res['restored']++;
        $res['by_entity'][$entity] = ($res['by_entity'][$entity] ?? 0) + 1;
    }

    private static function skip(array &$res, array $l, $now): void
    {
        $res['skipped']++;
        if (count($res['skipped_list']) < 200) {
            $res['skipped_list'][] = ['entity' => $l['entity'], 'id' => (int) $l['entity_id'], 'field' => $l['field'], 'lang' => $l['lang'],
                'expected' => $l['new_value'], 'now' => $now];
        }
    }
}
