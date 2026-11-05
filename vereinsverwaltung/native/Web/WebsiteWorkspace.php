<?php declare(strict_types=1);
namespace Kulturhus\Native\Web;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use InvalidArgumentException;
use Kulturhus\Native\Shared\Access;
use PDO;
use Kulturhus\Native\Web\Website\{PageSearch,EventSearch,PageOverview};

/** Read-only editorial entry points over the existing, club-bound Pages service. */
final class WebsiteWorkspace
{
    use \Kulturhus\Native\Web\ContentInterfaceText;
    private readonly PDO $db;
    private readonly PageSearch $pageSearch;
    private readonly EventSearch $eventSearch;
    private readonly PageOverview $pageOverview;
    private const PAGE_SIZE = 25;
    public function __construct(PDO $db, private readonly Access $access, private readonly string $csrf = '')
    {
        $this->db=$db;
        $this->pageSearch = new PageSearch($db, $access);
        $this->eventSearch = new EventSearch($db,$access);
        $this->pageOverview = new PageOverview($db,$access);
    }

    public function overview(): string
    {
        $data=$this->pageOverview->read();$rows=$data['recent'];$counts=$data['counts'];
        $published=$counts['published'];$changed=$counts['changed'];$total=$counts['total'];
        $labels = ['website' => [$this->contentText('Seiten'), $this->contentText('Inhalte und feste Informationen bearbeiten.')], 'navigation' => [$this->contentText('Menü'), $this->contentText('Namen, Reihenfolge und sichtbare Menüpunkte verwalten.')], 'news' => [$this->contentText('Aktuelles'), $this->contentText('Beiträge für „Was uns bewegt“ anlegen und steuern.')], 'media' => [$this->contentText('Fotogalerie'), $this->contentText('Alben und Bilder für gemeinsame Erinnerungen.')], 'events' => [$this->contentText('Veranstaltungen'), $this->contentText('Kommende Termine und das Veranstaltungsarchiv.')]];
        $menuCount=$data['menu'];
        $html = ('<div class="website-workspace"><p class="intro">' . Html::e($this->contentText('Die Website an einem Ort pflegen. Änderungen bleiben Entwürfe, bis du sie veröffentlichst.')) . '</p><div class="website-summary" aria-label="'.Html::e($this->contentText('Inhaltsstand')).'"><span><strong>').$total.('</strong> ' . Html::e($this->contentText('Inhalte')) . '</span><span><strong>').$published.('</strong> ' . Html::e($this->contentText('veröffentlicht')) . '</span><span><strong>').($total-$published).('</strong> ' . Html::e($this->contentText('unveröffentlicht')) . '</span><span><strong>').$changed.('</strong> ' . Html::e($this->contentText('mit Entwurfsänderungen')) . '</span><span><strong>').$counts['media'].('</strong> ' . Html::e($this->contentText('Fotoalben')) . '</span><span><strong>').$menuCount.('</strong> ' . Html::e($this->contentText('Menüpunkte')) . '</span></div><div class="website-overview-cards">');
        foreach ($labels as $view => [$label, $description]) {
            $html .= '<a class="website-overview-card" data-nav href="'.Html::e($this->url($view)).'">'.$this->icon($view).'<h2>'.Html::e($this->contentText($label)).'</h2><span class="website-card-count">'.($view==='navigation'?$menuCount:$counts[$view]).'</span><p>'.Html::e($this->contentText($description)).('</p><span class="website-card-open">' . Html::e($this->contentText('Bereich öffnen →')) . '</span></a>');
        }
        $html .= ('</div><section class="panel"><h2>' . Html::e($this->contentText('Zuletzt bearbeitet')) . '</h2>');
        if (!$rows) $html .= ('<p>' . Html::e($this->contentText('Noch keine Inhalte vorhanden. Wähle einen Bereich, um den ersten Inhalt anzulegen.')) . '</p>');
        else {
            $html .= '<ul class="website-recent">';
            foreach ($rows as $row) $html .= '<li><a data-nav href="'.Html::e($this->url($this->view($row), ['item'=>$row['id']])).'">'.Html::e($row['title']).'</a><span>'.$this->badge($row).'</span><time datetime="'.Html::e(str_replace(' ', 'T', $row['updated_at']).'Z').'">'.Html::e($this->updated($row)).'</time></li>';
            $html .= '</ul>';
        }
        return $html.'</section></div>';
    }

    public function pages(array $query = []): string
    {
        $filters = $this->filters($query, false);
        $result = $this->pageSearch->listing($filters, self::PAGE_SIZE);
        $filters['page'] = $result['page'];
        return $this->listing('website', $result['rows'], $filters, false, [], $result['total']);
    }

    public function events(array $query = []): string
    {
        $filters = $this->filters($query, true);
        $result=$this->eventSearch->listing($filters,self::PAGE_SIZE);$filters['page']=$result['page'];
        if(($query['tickets']??null)==='1'){$filters['tickets']='1';return '<h2>Tickets verwalten</h2><p>Wähle die Veranstaltung für Ticketangebot, Bestellungen oder Einlass.</p>'.$this->listing('events',$result['rows'],$filters,true,$result['years'],$result['total']);}
        $workflow=(new \Kulturhus\Native\Events\Workflow($this->db,$this->access))->get();
        $sync='<details class="panel website-eventbrite" id="event-provider-sync"><summary>'.Html::e(\Kulturhus\Native\Events\Workflow::SOURCES[$workflow['source']]).'</summary>';
        try{$this->access->require('website','manage');$sync.=('<a class="btn btn-outline theme-secondary" data-nav href="/?view=settings">' . Html::e($this->contentText('Verbindung einrichten')) . '</a>');}catch(DomainException){}
        if($this->canEdit()&&$workflow['source']==='eventbrite')$sync.='<form method="post">'.Html::hidden('csrf',$this->csrf).Html::hidden('operation','eventbrite-sync').Html::hidden('view','events').('<label class="media-channel"><input class="check" type="checkbox" name="eventbrite_images" value="1"> ' . Html::e($this->contentText('Anbieterbilder übernehmen')) . '</label><button class="btn btn-solid theme-secondary">' . Html::e($this->contentText('Eventbrite synchronisieren')) . '</button></form>');
        if($this->canEdit()&&$workflow['source']==='facebook')$sync.='<form method="post">'.Html::hidden('csrf',$this->csrf).Html::hidden('operation','facebook-events-sync').Html::hidden('view','events').('<button class="btn btn-solid theme-primary">' . Html::e($this->contentText('Facebook-Termine abgleichen')) . '</button></form>');
        return $this->listing('events', $result['rows'], $filters, true, $result['years'],$result['total']).$sync.'</details>';
    }

    private function listing(string $view, array $rows, array $filters, bool $events, array $years = [], ?int $total = null): string
    {
        $canEdit = $this->canEdit();$tickets=($filters['tickets']??null)==='1';
        $html = '<div class="website-workspace"><div class="website-list-heading"><p class="intro">'.($tickets?'Veranstaltung auswählen':($events ? Html::e($this->contentText('Termine und Veranstaltungsarchiv')) : Html::e($this->contentText('Seiten verwalten')))).'</p>';
        if ($canEdit&&!$tickets) $html .= '<a class="btn btn-solid theme-primary button" data-nav href="'.Html::e($this->url($view, ['item'=>'new'])).'">'.($events ? Html::e($this->contentText('+ Neue Veranstaltung')) : Html::e($this->contentText('+ Neue Seite'))).'</a>';
        $html .= '</div>';
        if ($events) {
            $html .= '<nav class="website-periods" aria-label="'.Html::e($this->contentText('Veranstaltungszeitraum')).'">';
            foreach (['upcoming'=>$this->contentText('Aktuell & kommend'), 'archive'=>'Archiv', 'all'=>$this->contentText('Alle Termine')] as $period => $label) {
                $args = array_diff_key($filters, ['page'=>true]); $args['period'] = $period; $args['sort'] = $period === 'archive' ? 'date_desc' : 'date_asc';
                $html .= '<a data-nav href="'.Html::e($this->url($view, $args)).'"'.($filters['period'] === $period ? ' aria-current="page"' : '').'>'.Html::e($this->contentText($label)).'</a>';
            }
            $html .= '</nav>';
        }
        $sorts = $events ? ['date_asc'=>$this->contentText('Termin aufsteigend'), 'date_desc'=>$this->contentText('Termin absteigend'), 'title_asc'=>$this->contentText('Titel A–Z')] : $this->pageSearch->choices($this->contentText(...));
        foreach($sorts as$key=>$label)if(!str_starts_with($key,'custom.'))$sorts[$key]=$this->contentText($label);
        $html .= '<form class="website-filters" method="get" action="/">'.Html::hidden('view', $view).($tickets?Html::hidden('tickets','1'):'').Html::input('q', $this->contentText('Suchen'), $filters['q'], 'search', 'maxlength="120" placeholder="'.Html::e($this->contentText('Titel, Adresse oder Inhalt')).'"');
        if ($events) {
            $html .= Html::hidden('period', $filters['period']).('<label>' . Html::e($this->contentText('Jahr')) . '<select class="form-control" name="year"><option value="">' . Html::e($this->contentText('Alle Jahre')) . '</option>');
            foreach($years as $year)$html.='<option value="'.Html::e($year).'"'.((string)$year===$filters['year']?' selected':'').'>'.Html::e($year).'</option>';
            $html .= '</select></label>';
        }
        $html .= Html::select('status', $this->contentText('Veröffentlichung'), $this->contentOptions(['all'=>$this->contentText('Alle Inhalte'),'published'=>$this->contentText('Veröffentlicht'),'draft'=>$this->contentText('Unveröffentlicht'),'changed'=>$this->contentText('Mit Entwurfsänderungen')]), $filters['status']).Html::select('sort', $this->contentText('Sortieren'), $sorts, $filters['sort']).('<div class="website-filter-actions"><button class="btn btn-solid theme-primary">' . Html::e($this->contentText('Filtern')) . '</button><a data-nav href="').Html::e($this->url($view,$tickets?['tickets'=>'1']:[])).('">' . Html::e($this->contentText('Zurücksetzen')) . '</a></div></form>');
        $count = $total ?? count($rows); $last = max(1, (int)ceil($count/self::PAGE_SIZE)); $page = min($filters['page'], $last);
        $visible = $total === null ? array_slice($rows, ($page-1)*self::PAGE_SIZE, self::PAGE_SIZE) : $rows;
        $html .= '<p class="website-result-count" role="status">'.$count.' '.($events ? Html::e($this->contentText('Veranstaltungen')) : Html::e($this->contentText('Seiten'))).Html::e($this->contentText(' gefunden')).($count > self::PAGE_SIZE ? ' · '.Html::e($this->contentFormat('Seite {page} von {pages}',['page'=>$page,'pages'=>$last])) : '').'</p>';
        if (!$visible) return $html.('<div class="panel website-empty"><h2>' . Html::e($this->contentText('Keine passenden')) . ' ').($events ? Html::e($this->contentText('Veranstaltungen')) : Html::e($this->contentText('Seiten'))).('</h2><p>' . Html::e($this->contentText('Ändere die Suche oder Filter.'))).($canEdit&&!$tickets ? ' '.Html::e($this->contentText('Du kannst auch einen neuen Inhalt anlegen.')) : '').'</p></div></div>';
        $html .= '<div class="website-table-wrap"><table class="table website-content-table"><caption class="visually-hidden">'.($events ? Html::e($this->contentText('Veranstaltungen verwalten')) : Html::e($this->contentText('Seiten verwalten'))).'</caption><thead><tr>'.($events ? ('<th scope="col">' . Html::e($this->contentText('Termin')) . '</th>') : '').('<th scope="col">' . Html::e($this->contentText('Titel')) . '</th><th scope="col">' . Html::e($this->contentText('Status')) . '</th><th scope="col">').($events ? Html::e($this->contentText('Ort')) : Html::e($this->contentText('Vorlage'))).('</th><th scope="col">' . Html::e($this->contentText('Geändert')) . '</th><th scope="col">' . Html::e($this->contentText('Aktionen')) . '</th></tr></thead><tbody>');
        foreach ($visible as $row) {
            $editUrl = $this->url($view, ['item'=>$row['id']]+($tickets?['tickets'=>'1']:[]));
            $html .= '<tr data-website-row="'.Html::e($row['id']).'">';
            if ($events) $html .= '<td>'.($row['event_start'] !== '' ? '<time datetime="'.Html::e($row['event_start']).'">'.Html::e((new DateTimeImmutable($row['event_start'],new DateTimeZone('Europe/Berlin')))->format('d.m.Y · H:i')).'</time>' : Html::e($this->contentText('Termin offen'))).'</td>';
            $html .= '<td><a class="website-row-title" data-nav href="'.Html::e($editUrl).'">'.Html::e($row['title']).'</a><small>/'.Html::e($row['slug']).'</small></td><td>'.$this->badge($row).'</td><td>'.Html::e($events ? ($row['event_location'] ?: $this->contentText('Noch offen')) : ($row['template_definition']['label'] ?? $row['template'])).'</td><td>'.Html::e($this->updated($row)).'</td><td><div class="website-row-actions"><a data-nav href="'.Html::e($editUrl).'">'.Html::e($this->contentText($tickets?'Tickets verwalten':($canEdit ? $this->contentText('Bearbeiten') : $this->contentText('Öffnen')))).'</a>';
            if($events&&!$tickets)$html.='<a data-nav href="'.Html::e($this->url('events',['item'=>$row['id'],'tickets'=>'1'])).'">Tickets verwalten</a>';
            if (!empty($row['published_slug'])) $html .= '<a href="/site/'.Html::e($row['published_slug']).('" target="_blank" rel="noopener">' . Html::e($this->contentText('Ansehen ↗')) . '</a>');
            $html .= '</div></td></tr>';
        }
        $html .= '</tbody></table></div>';
        if ($last > 1) {
            $html .= '<nav class="website-pagination" aria-label="'.Html::e($this->contentText('Ergebnisseiten')).'">';
            foreach (['Vorherige Seite'=>$page-1, 'Nächste Seite'=>$page+1] as $label=>$target) if ($target >= 1 && $target <= $last) $html .= '<a data-nav href="'.Html::e($this->url($view, array_replace($filters, ['page'=>$target]))).'">'.Html::e($this->contentText($label)).'</a>';
            $html .= '</nav>';
        }
        return $html.'</div>';
    }

    private function filters(array $query, bool $events): array
    {
        $text = static function(string $key, string $fallback='') use ($query): string {
            $value=$query[$key]??$fallback;
            if (!is_string($value) || !preg_match('//u',$value) || preg_match('/[\x00-\x1f\x7f]/',$value) || mb_strlen($value)>120) throw new InvalidArgumentException('Ungültiger Listenfilter.');
            return trim($value);
        };
        $status=$text('status','all'); $period=$events?$text('period','upcoming'):''; $year=$events?$text('year'):'';
        $sort=$text('sort',$events?($period==='archive'?'date_desc':'date_asc'):'title_asc'); $page=$text('page','1');
        if (!in_array($status,['all','draft','published','changed'],true) || ($events&&!in_array($period,['upcoming','archive','all'],true)) || ($year!==''&&!preg_match('/^[0-9]{4}$/D',$year)) || !in_array($sort,$events?['date_asc','date_desc','title_asc']:array_keys($this->pageSearch->choices()),true) || !preg_match('/^[1-9][0-9]{0,5}$/D',$page)) throw new InvalidArgumentException('Ungültiger Listenfilter.');
        return ['q'=>$text('q'),'status'=>$status,'period'=>$period,'year'=>$year,'sort'=>$sort,'page'=>(int)$page];
    }
    private function view(array $row): string {return match($row['template_definition']['renderer']??$row['template']){'event'=>'events','news'=>'news','gallery'=>'media',default=>'website'};}
    private function state(array $row): string {return empty($row['published_revision'])?'draft':((int)$row['version']>(int)$row['published_revision']?'changed':'published');}
    private function badge(array $row): string {$state=$this->state($row);return '<span class="website-state state-'.$state.'">'.Html::e($this->contentText(['draft'=>$this->contentText('Unveröffentlicht'),'changed'=>$this->contentText('Entwurf mit Änderungen'),'published'=>$this->contentText('Veröffentlicht')][$state])).'</span>';}
    private function updated(array $row): string {return (new DateTimeImmutable($row['updated_at'],new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Europe/Berlin'))->format('d.m.Y · H:i');}
    private function canEdit(): bool {try{$this->access->require('website','edit');return true;}catch(DomainException){return false;}}
    private function url(string $view,array $query=[]): string {return '/?'.http_build_query(['view'=>$view]+$query);}
    private function icon(string $view): string
    {
        $shape=match($view){
            'website'=>'<path d="M6 3h8l4 4v14H6z M14 3v5h4 M9 12h6 M9 16h6"/>',
            'navigation'=>'<path d="M8 6h12 M8 12h12 M8 18h12"/><circle cx="4" cy="6" r="1"/><circle cx="4" cy="12" r="1"/><circle cx="4" cy="18" r="1"/>',
            'news'=>'<path d="M5 4h14v17H5z M8 8h8 M8 12h8 M8 16h5"/>',
            'media'=>'<rect x="3" y="4" width="18" height="16" rx="1"/><circle cx="8" cy="9" r="2"/><path d="m4 19 6-6 3 3 3-5 4 8"/>',
            default=>'<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M7 2v6 M17 2v6 M3 11h18 M7 15h3 M14 15h3"/>',
        };
        return '<svg class="website-card-icon" viewBox="0 0 24 24" width="28" height="28" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'.$shape.'</svg>';
    }
}
