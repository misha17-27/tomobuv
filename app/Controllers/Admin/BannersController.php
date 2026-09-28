<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\App;
use App\Core\Cache;
use App\Core\Request;
use App\Core\Response;

/**
 * Баннеры главной: слайдер (home_slider) и широкие баннеры (home_wide). Порядок — перетаскиванием или стрелками.
 * Украинская версия — title_uk, text_uk, button_uk (пусто → на /ua/ русский текст).
 */
final class BannersController extends BaseController
{
    public const PLACES = [
        'home_slider' => ['Слайдер на главной', 'Картинка справа от текста, рекомендуемый размер 760×350 px'],
        'home_wide'   => ['Широкие баннеры на главной', 'Картинка — фон под заголовком (затемняется), около 650×220 px'],
    ];

    public function index(): Response
    {
        $rows = App::db()->all('SELECT * FROM banners ORDER BY place, sort, id');
        $groups = array_fill_keys(array_keys(self::PLACES), []);
        foreach ($rows as $r) $groups[$r['place']][] = $r;   // «чужие» места (home_side и т.п.) тоже показываем
        return $this->render('admin/banners/index', [
            'title' => 'Баннеры', 'groups' => $groups,
            'actions' => '<a class="btn btn-p" href="/admin/banners/new/">+ Новый баннер</a>',
            'styles' => ['admin/content.css'], 'scripts' => ['admin/content.js'],
        ]);
    }

    public function edit(string $id = ''): Response
    {
        $db = App::db();
        $bid = (int) $id;
        $b = $bid ? $db->row('SELECT * FROM banners WHERE id = ?', [$bid]) : null;
        if ($id !== '' && !$b) return $this->missing();
        $isNew = $b === null;
        $place = array_key_exists(Request::get('place'), self::PLACES) ? Request::get('place') : 'home_slider';
        $b ??= ['id' => 0, 'place' => $place, 'title' => '', 'text' => '', 'button' => '', 'link' => '', 'image' => '', 'status' => 1, 'sort' => 0,
            'title_uk' => '', 'text_uk' => '', 'button_uk' => ''];
        $errors = [];
        $lang = Request::post('_lang') === 'uk' || (!Request::isPost() && Request::get('lang') === 'uk') ? 'uk' : 'ru';

        if (Request::isPost()) {
            $data = [
                'place'  => Request::post('place'),
                'title'  => mb_substr(Request::post('title'), 0, 255),
                'text'   => mb_substr(Request::post('text'), 0, 500),
                'button' => mb_substr(Request::post('button'), 0, 64),
                'link'   => Request::post('link'),
                'image'  => Request::post('image'),
                'status' => Request::post('status') === '1' ? 1 : 0,
            ] + PagesController::ukValues(['title_uk' => 255, 'text_uk' => 500, 'button_uk' => 64]);
            // место: из списка или прежнее (для баннеров старых мест)
            if (!array_key_exists($data['place'], self::PLACES) && $data['place'] !== $b['place']) $errors['place'] = 'Выберите место показа';
            if ($data['title'] === '') $errors['title'] = 'Укажите заголовок';
            $link = $data['link'];
            if ($link === '') $errors['link'] = 'Укажите ссылку — куда ведёт баннер';
            elseif (preg_match('#^https?://#i', $link) ? !filter_var($link, FILTER_VALIDATE_URL) : !preg_match('#^/(?!/)[^\s"<>]*$#', $link)) {
                $errors['link'] = 'Ссылка — путь от корня сайта (/category/aktsiya/) или полный адрес https://…';
            }
            $data['link'] = mb_substr($link, 0, 500);
            $file = $_FILES['image_file'] ?? null;
            if ($file && (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                $up = UploadController::save($file);
                if ($up['ok']) $data['image'] = $up['url']; else $errors['image'] = $up['error'];
            }
            if (!isset($errors['image'])) {
                if ($data['image'] === '') $errors['image'] = 'Загрузите картинку баннера';
                elseif (!preg_match('#^(/(?!/)[^\s"<>]*|https?://[^\s"<>]+)$#i', $data['image'])) $errors['image'] = 'Путь к картинке должен начинаться с «/» или https://';
            }

            if (!$errors) {
                if ($isNew || $data['place'] !== $b['place']) {
                    $data['sort'] = (int) $db->value('SELECT COALESCE(MAX(sort), 0) + 1 FROM banners WHERE place = ?', [$data['place']]);
                }
                foreach (['text', 'button', 'title_uk', 'text_uk', 'button_uk'] as $k) $data[$k] = $data[$k] === '' ? null : $data[$k];
                $data['image'] = mb_substr($data['image'], 0, 255);
                if ($isNew) {
                    $bid = $db->insert('banners', $data);
                    $this->log('banner_create', 'banner', $bid, ['title' => $data['title']]);
                } else {
                    $db->update('banners', $data, 'id = ?', [$bid]);
                    $this->log('banner_update', 'banner', $bid, ['title' => $data['title']]);
                }
                Cache::flush();
                $this->flash($isNew ? 'Баннер добавлен.' : 'Баннер сохранён.');
                return Response::redirect('/admin/banners/' . $bid . '/' . ($lang === 'uk' ? '?lang=uk' : ''));
            }
            $b = $data + $b;
        }

        $r = $this->render('admin/banners/edit', [
            'title' => $isNew ? 'Новый баннер' : 'Баннер: ' . $b['title'],
            'b' => $b, 'isNew' => $isNew, 'errors' => $errors, 'uploadLimit' => UploadController::limitText(), 'lang' => $lang,
            'back' => ['/admin/banners/', 'Все баннеры'],
            'styles' => ['admin/content.css'], 'scripts' => ['admin/content.js', 'admin/media.js'],
        ]);
        if ($errors) $r->status = 422;
        return $r;
    }

    /** Новый порядок баннеров одного места: ids[] в нужном порядке (AJAX) */
    public function sort(): Response
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', Request::postArray('ids')))));
        if (!$ids || count($ids) > 200) return Response::json(['ok' => false, 'error' => 'Нет данных'], 422);
        $db = App::db();
        [$ph, $vals] = $db->in($ids);
        $places = $db->col("SELECT DISTINCT place FROM banners WHERE id IN ($ph)", $vals);
        if (count($places) !== 1) return Response::json(['ok' => false, 'error' => 'Баннеры из разных мест'], 422);
        $case = ''; $params = [];
        foreach ($ids as $i => $bid) { $case .= ' WHEN ? THEN ?'; array_push($params, $bid, $i + 1); }
        $db->query("UPDATE banners SET sort = CASE id$case END WHERE id IN ($ph)", array_merge($params, $vals));
        Cache::flush();
        $this->log('banner_sort', 'banner', null, ['place' => $places[0], 'ids' => $ids]);
        return Response::json(['ok' => true]);
    }

    /** Показать/скрыть */
    public function toggle(string $id): Response
    {
        $db = App::db();
        $b = $db->row('SELECT id, status, title FROM banners WHERE id = ?', [(int) $id]);
        if (!$b) return Request::isAjax() ? Response::json(['ok' => false, 'error' => 'Баннер не найден'], 404) : $this->missing();
        $st = (int) $b['status'] ? 0 : 1;
        $db->update('banners', ['status' => $st], 'id = ?', [(int) $b['id']]);
        Cache::flush();
        $this->log($st ? 'banner_show' : 'banner_hide', 'banner', (int) $b['id']);
        if (Request::isAjax()) return Response::json(['ok' => true, 'status' => $st]);
        $this->flash($st ? 'Баннер показан на сайте.' : 'Баннер скрыт.');
        return $this->back('/admin/banners/');
    }

    public function delete(string $id): Response
    {
        $db = App::db();
        $b = $db->row('SELECT id, title FROM banners WHERE id = ?', [(int) $id]);
        if (!$b) return $this->missing();
        $db->delete('banners', 'id = ?', [(int) $b['id']]);
        Cache::flush();
        $this->log('banner_delete', 'banner', (int) $b['id'], ['title' => $b['title']]);
        $this->flash('Баннер «' . $b['title'] . '» удалён.');
        return Response::redirect('/admin/banners/');
    }

    private function missing(): Response
    {
        $r = $this->render('admin/forbidden', ['title' => 'Баннер не найден', 'message' => 'Такого баннера нет — возможно, его уже удалили.']);
        $r->status = 404;
        return $r;
    }
}
