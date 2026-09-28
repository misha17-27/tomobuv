<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Шаблоны на чистом PHP (app/Views). Никаких шаблонизаторов — максимум скорости.
 *
 *   View::render('front/product', ['product' => $p], 'front');
 *
 * В шаблоне доступны переменные из массива, хелперы e(), url(), price_html(), icon()…
 * и объект $view: $view->partial('front/partials/card', ['p' => $p]).
 * Мета-теги страницы задаются через $seo (App\Core\Seo) — layout выводит их в <head>.
 */
final class View
{
    private array $data = [];

    public static function render(string $template, array $data = [], ?string $layout = 'front'): string
    {
        $v = new self();
        $v->data = $data;
        $content = $v->capture($template, $data);
        if ($layout === null) return $content;
        return $v->capture('layouts/' . $layout, $data + ['content' => $content]);
    }

    public function partial(string $template, array $data = []): string
    {
        return $this->capture($template, $data + $this->data);
    }

    private function capture(string $template, array $data): string
    {
        $file = APP . '/Views/' . $template . '.php';
        if (!is_file($file)) throw new \RuntimeException('Шаблон не найден: ' . $template);
        $view = $this;
        extract($data, EXTR_SKIP);
        ob_start();
        try {
            include $file;
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }
        return (string) ob_get_clean();
    }
}
