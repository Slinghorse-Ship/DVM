<?php declare(strict_types=1);
namespace Kulturhus\Native\Web\Website;
use Kulturhus\Native\Cms\{PageBatches,Pages};
use Kulturhus\Native\Shared\Access;
use Kulturhus\Native\Web\Html;
use PDO;

final class PageBatchPanel
{
    public function __construct(private PDO $db,private Access $access,private string $csrf){}
    public function render(array $query):string
    {
        $service=new PageBatches($this->db,$this->access);$id=$query['batch']??'';
        if(!is_string($id))throw new \InvalidArgumentException('Stapelkennung prüfen.');
        $h='<div class="page-batch-panel"><h2>Seiten stapelweise aufräumen</h2><p>Ausgewählte gespeicherte Originale schrittweise in den Papierkorb verschieben oder privat zurückholen. Jeder Inhalt wird mit seinem ursprünglichen Versionsstand geprüft. Nach einer Unterbrechung bleibt der Fortschritt erhalten.</p>';
        if($id!==''){
            $job=$service->get($id);$active=in_array($job['status'],['ready','paused'],true);
            $h.='<section class="panel" data-page-batch="'.Html::e($id).'"><h3>'.($job['direction']==='trash'?'In den Papierkorb':'Privat zurückholen').'</h3><p role="status">'.$job['next_index'].' von '.$job['total'].' geprüft · '.['ready'=>'Bereit zum Fortsetzen','paused'=>'Zur Korrektur angehalten','completed'=>'Abgeschlossen','cancelled'=>'Abgebrochen'][$job['status']].'</p>';
            if($job['last_reason'])$h.='<p class="alert theme-warning">'.Html::e($job['last_reason']).'</p>';
            if($active){
                $item=$job['items'][$job['next_index']];$page=(new Pages($this->db,$this->access))->get($item['id']);
                $h.='<p>Nächster Inhalt: <a data-nav href="/?view=website&amp;item='.Html::e($item['id']).'">'.Html::e($page['title']).'</a> · ursprünglich gewählte Version '.$item['version'].'</p>';
                try{$this->access->require('website',$job['direction']==='trash'?'publish':'edit');$allowed=true;}catch(\DomainException){$allowed=false;}
                if($allowed){
                    foreach(['advance'=>'Bis zu 25 Inhalte fortsetzen','skip'=>'Diesen Konflikt unverändert überspringen','cancel'=>'Stapel abbrechen']as $action=>$label){
                        if($action==='skip'&&$job['status']!=='paused')continue;
                        $h.='<form method="post">'.Html::hidden('csrf',$this->csrf).Html::hidden('operation','page-batch-'.$action).Html::hidden('batch',$id).Html::hidden('version',$job['version']);
                        if($action==='skip')$h.='<label><input class="check" type="checkbox" name="batch_confirm" value="1" required> Dieses Original unverändert belassen</label>';
                        $h.='<button class="btn btn-solid theme-'.($action==='advance'?'primary':'secondary').'">'.$label.'</button></form>';
                    }
                }
            }
            if($job['skipped']){$h.='<details><summary>'.count($job['skipped']).' Originale bewusst übersprungen</summary><ul>';foreach($job['skipped']as $item)$h.='<li><a data-nav href="/?view=website&amp;item='.Html::e($item['id']).'">Original öffnen</a> · '.Html::e($item['reason']).'</li>';$h.='</ul></details>';}
            $h.='<p>Abbrechen macht bereits ausgeführte Schritte nicht rückgängig. Zurückgeholte Inhalte bleiben unveröffentlicht.</p></section>';
        }
        $direction=$query['direction']??'trash';$q=$query['q']??'';$page=$query['page']??'1';
        if(!is_string($direction)||!is_string($q)||!is_string($page)||!preg_match('/\A[1-9][0-9]{0,5}\z/D',$page))throw new \InvalidArgumentException('Stapel-Auswahl prüfen.');
        $data=$service->directory($direction,$q,(int)$page);
        $h.='<form method="get" action="/">'.Html::hidden('view','website').Html::hidden('section','bulk').Html::select('direction','Aktion',['trash'=>'In den Papierkorb','restore'=>'Privat zurückholen'],$direction).Html::input('q','Originale suchen',$q,'search','maxlength="120"').'<button class="btn btn-solid theme-secondary">Auswahl anzeigen</button></form><p>'.$data['total'].' Inhalte · Seite '.$data['page'].' von '.$data['last'].'</p>';
        try{$this->access->require('website',$direction==='trash'?'publish':'edit');$allowed=true;}catch(\DomainException){$allowed=false;}
        if($allowed&&$data['rows']){
            $h.='<form method="post">'.Html::hidden('csrf',$this->csrf).Html::hidden('operation','page-batch-create').Html::hidden('direction',$direction).'<fieldset><legend>Originale dieser Trefferseite auswählen</legend>';
            foreach($data['rows']as $row)$h.='<label><input class="check" type="checkbox" name="batch_items[]" value="'.Html::e($row['id'].':'.$row['version']).'"> '.Html::e($row['title']).' <small>/'.Html::e($row['slug']).' · Version '.Html::e($row['version']).'</small></label>';
            $h.='</fieldset><label><input class="check" type="checkbox" name="batch_confirm" value="1" required> Diese gespeicherten Originale für die gewählte Aktion vorbereiten</label><button class="btn btn-solid theme-primary">Stapel vorbereiten</button></form>';
        }
        if($allowed&&$data['total']>0&&$data['total']<=PageBatches::LIMIT){
            $h.='<details class="panel"><summary>Alle '.$data['total'].' Treffer vorbereiten</summary><p>Die vollständige Suchauswahl über alle Trefferseiten wird mit den aktuellen gespeicherten Originalversionen festgehalten. Der Stapel führt erst beim bewussten Fortsetzen Änderungen aus.</p><form method="post">'.Html::hidden('csrf',$this->csrf).Html::hidden('operation','page-batch-create').Html::hidden('batch_scope','all').Html::hidden('direction',$direction).Html::hidden('batch_query',$q).'<label><input class="check" type="checkbox" name="batch_confirm" value="1" required> Alle '.$data['total'].' Suchtreffer für die gewählte Aktion vorbereiten</label><button class="btn btn-solid theme-primary">Alle Suchtreffer vorbereiten</button></form></details>';
        }
        if($data['last']>1){$h.='<nav aria-label="Stapel-Auswahlseiten">';foreach(['Vorherige Seite'=>$data['page']-1,'Nächste Seite'=>$data['page']+1]as $label=>$target)if($target>=1&&$target<=$data['last'])$h.='<a data-nav href="/?'.Html::e(http_build_query(['view'=>'website','section'=>'bulk','direction'=>$direction,'q'=>$q,'page'=>$target])).'">'.$label.'</a> ';$h.='</nav>';}
        $h.='<h3>Letzte Stapel</h3><ul>';foreach($service->recent()as $job)$h.='<li><a data-nav href="/?view=website&amp;section=bulk&amp;batch='.Html::e($job['id']).'">'.($job['direction']==='trash'?'Papierkorb':'Privat zurückholen').' · '.$job['next_index'].' / '.$job['total'].'</a></li>';
        return $h.'</ul><p><a data-nav href="/?view=website&amp;section=trash">Papierkorb öffnen</a></p></div>';
    }
}
