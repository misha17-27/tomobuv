<?php
declare(strict_types=1);

namespace App\Core;

/**
 * SEO-мета страницы. Логика повторяет плагин SEO старого сайта (Webasyst shop.seo):
 *   1) если у товара/категории/страницы заполнен свой meta_title/description — берётся он;
 *   2) иначе — шаблон из настроек (seo.product_meta_title и т.д.) с переменными
 *      {$product.name}, {$category.seo_name}, {$store_info.name}, {$page_number}…;
 *   3) на страницах пагинации (?page=N) к title и description добавляется « | Страница N»,
 *      canonical указывает на первую страницу.
 * Шаблоны перенесены из shop_seo_storefront_settings и редактируются в админке (Настройки → SEO).
 */
final class Seo
{
    public string $title = '';
    public string $description = '';
    public string $keywords = '';
    public string $h1 = '';
    public ?string $canonical = null;
    public ?string $robots = null;          // 'noindex, follow' и т.п.
    public string $ogType = 'website';
    public ?string $ogImage = null;
    public ?string $ogTitle = null;
    public ?string $ogDescription = null;
    public array $jsonLd = [];              // массивы schema.org, выводятся в <head>
    public ?string $prev = null;
    public ?string $next = null;

    public static function make(string $title = '', string $description = '', string $keywords = ''): self
    {
        $s = new self();
        $s->title = $title;
        $s->description = $description;
        $s->keywords = $keywords;
        return $s;
    }

    /** Подстановка переменных в шаблон: {$product.name}, {$page_number} */
    public static function tpl(?string $template, array $vars): string
    {
        if ($template === null || $template === '') return '';
        $out = preg_replace_callback('/\{\$([a-z_]+)(?:\.([a-z_]+))?(?:\|[^}]*)?\}/i', static function ($m) use ($vars) {
            $v = $vars[$m[1]] ?? '';
            if (isset($m[2]) && $m[2] !== '') $v = is_array($v) ? ($v[$m[2]] ?? '') : '';
            return is_scalar($v) ? (string) $v : '';
        }, $template);
        return trim((string) $out);
    }

    /** Общие переменные магазина для шаблонов */
    public static function storeInfo(): array
    {
        return [
            'name'  => (string) Settings::get('store_name', 'Tomobuv'),
            'phone' => (string) Settings::get('store_phone', '+(380) 932753070'),
        ];
    }

    /** Взять собственное значение, иначе шаблон из настроек */
    public static function pick(?string $own, string $settingKey, array $vars): string
    {
        $own = trim((string) $own);
        if ($own !== '') return $own;
        return self::tpl((string) Settings::get($settingKey, ''), $vars + ['store_info' => self::storeInfo()]);
    }

    /** Пагинация как на старом сайте: « | Страница N» + canonical на первую страницу */
    public function paginate(int $page, string $basePath, int $pages = 0): self
    {
        if ($page > 1) {
            $suffix = ' | ' . t('Страница') . ' ' . $page;
            if ($this->title !== '') $this->title .= $suffix;
            if ($this->description !== '') $this->description .= $suffix;
            $this->canonical = url($basePath);
        }
        return $this;
    }

    public function ogTitle(): string
    {
        return $this->ogTitle ?? $this->title;
    }

    public function ogDescription(): string
    {
        return $this->ogDescription ?? $this->description;
    }
}
