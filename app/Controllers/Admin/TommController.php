<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\App;
use App\Core\Request;
use App\Core\Response;
use App\Services\Import\Importer;
use App\Services\Suppliers\Tomm;
use App\Services\Suppliers\TommMap;

/**
 * «Каталог → Поставщики → Tom.m» (только администратор): загрузка бренда Tom.m с официального сайта tomm.ua — настройки
 * (наценка, расписание, бережность к сайту), таблица «раздел бренда → категория сайта», журнал; «Обзор», проверка связи,
 * пробный прогон, запуск, отчёт, шаги и остановка — общие (SupplierPages, suppliers.js).
 */
final class TommController extends BaseController
{
    use SupplierPages;

    protected const MANAGER_ALLOWED = false;

    private const BASE = '/admin/suppliers/tomm/';
    public const TABS = ['overview' => 'Обзор', 'settings' => 'Настройки', 'map' => 'Категории', 'runs' => 'Журнал'];

    protected static function sup(): string
    {
        return Tomm::class;
    }

    protected static function supBase(): string
    {
        return self::BASE;
    }

    public function index(): Response
    {
        Tomm::ensureSchema();
        TommMap::seed();
        $tab = Request::get('tab', 'overview');
        if (!isset(self::TABS[$tab])) $tab = 'overview';
        $cfg = array_combine(array_keys(Tomm::DEFAULTS), array_map([Tomm::class, 'cfg'], array_keys(Tomm::DEFAULTS)));
        $db = App::db();
        $bids = Tomm::brandIds();
        $ours = ['total' => 0, 'forsage' => 0, 'manual' => 0, 'tomm' => 0];
        $brandNames = [];
        if ($bids) {
            [$ph, $v] = $db->in($bids);
            $brandNames = $db->pairs("SELECT id, name FROM brands WHERE id IN ($ph)", $v);
            foreach ($db->all("SELECT IFNULL(supplier, '') s, COUNT(*) n FROM products WHERE brand_id IN ($ph) GROUP BY IFNULL(supplier, '')", $v) as $r) {
                $k = match ((string) $r['s']) { '' => 'manual', 'forsage' => 'forsage', Tomm::CODE => 'tomm', default => 'other' };
                $ours[$k] = ($ours[$k] ?? 0) + (int) $r['n'];
                $ours['total'] += (int) $r['n'];
            }
        }
        $data = [
            'title' => 'Поставщики → ' . Tomm::TITLE, 'tab' => $tab, 'base' => self::BASE,
            'styles' => ['admin/suppliers.css'], 'scripts' => ['admin/suppliers.js'],
            'cfg' => $cfg, 'enabled' => Tomm::enabled(), 'markup' => Tomm::markup(), 'debug' => App::isDebug(),
            'due' => Tomm::due(), 'next' => Tomm::nextRun(), 'nextMode' => Tomm::autoMode(), 'active' => Tomm::active(), 'last' => Tomm::runs(1)[0] ?? null,
            'lastFinished' => Tomm::lastFinished(), 'mapCount' => TommMap::count(), 'products' => Tomm::productCounts(), 'links' => Tomm::linkCounts(),
            'hasPages' => (bool) Tomm::lastWeb(), 'ours' => $ours, 'brandNames' => $brandNames,
        ];
        if ($tab === 'overview') {
            $data += $this->overviewData();
        } elseif ($tab === 'settings') {
            [, $attrErr] = Tomm::attributes();
            $data += ['attrErr' => $attrErr];
        } elseif ($tab === 'map') {
            $data += ['rows' => TommMap::all(), 'choices' => Importer::categoryChoices(), 'unmapped' => $data['lastFinished']['stats']['unmapped'] ?? []];
        } else {
            $data += ['runs' => Tomm::runs(60)];
        }
        $data['actions'] = $this->headerActions($data['live'] ?? null);
        return $this->render('admin/suppliers/tomm', $data);
    }

    /** Таблица разделов: категория сайта, загружать / нет, по рядам */
    public function mapSave(): Response
    {
        $changes = [];
        foreach (Request::postArray('map') as $id => $row) {
            if (!ctype_digit((string) $id) || !is_array($row)) continue;
            $changes[(int) $id] = ['category_id' => (int) ($row['category_id'] ?? 0), 'mode' => is_scalar($row['mode'] ?? null) ? (string) $row['mode'] : '', 'by_size' => !empty($row['by_size'])];
        }
        $n = TommMap::assign($changes);
        if ($n) $this->log('tomm_map', 'settings', null, ['rows' => $n]);
        $this->flash($n ? 'Сохранено строк: ' . $n . ' — сработает со следующего запуска.' : 'Изменений нет.');
        return $this->back(self::BASE . '?tab=map');
    }
}
