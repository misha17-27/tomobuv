<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Пагинация в формате старого сайта: ?page=N (первая страница — без параметра).
 *   $pg = new Paginator($total, $perPage, Request::page());
 *   $pg->offset, $pg->pages, $pg->html()
 */
final class Paginator
{
    public int $total;
    public int $perPage;
    public int $page;
    public int $pages;
    public int $offset;

    public function __construct(int $total, int $perPage, int $page)
    {
        $this->total = max(0, $total);
        $this->perPage = max(1, $perPage);
        $this->pages = max(1, (int) ceil($this->total / $this->perPage));
        $this->page = max(1, min($page, $this->pages));
        $this->offset = ($this->page - 1) * $this->perPage;
    }

    public function url(int $page): string
    {
        return Request::withQuery(['page' => $page > 1 ? $page : null]);
    }

    public function hasNext(): bool
    {
        return $this->page < $this->pages;
    }

    public function html(): string
    {
        if ($this->pages <= 1) return '';
        $p = $this->page; $n = $this->pages;
        $nums = array_unique(array_filter([1, 2, $p - 2, $p - 1, $p, $p + 1, $p + 2, $n - 1, $n], static fn($x) => $x >= 1 && $x <= $n));
        sort($nums);
        $h = '<nav class="pager" aria-label="' . e(t('Страницы')) . '">';
        if ($p > 1) $h .= '<a href="' . e($this->url($p - 1)) . '" rel="prev" aria-label="' . e(t('Предыдущая')) . '">‹</a>';
        $prev = 0;
        foreach ($nums as $i) {
            if ($prev && $i - $prev > 1) $h .= '<span>…</span>';
            $h .= $i === $p ? '<span class="on" aria-current="page">' . $i . '</span>' : '<a href="' . e($this->url($i)) . '">' . $i . '</a>';
            $prev = $i;
        }
        if ($p < $n) $h .= '<a href="' . e($this->url($p + 1)) . '" rel="next" aria-label="' . e(t('Следующая')) . '">›</a>';
        return $h . '</nav>';
    }
}
