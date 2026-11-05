<?php declare(strict_types=1);
namespace Kulturhus\Native\Web\Website;
use Kulturhus\Native\Cms\TreeCopyJobs;
use Kulturhus\Native\Shared\Access;
use Kulturhus\Native\Web\Html;
use PDO;

/** Compact progress workspace; never renders thousands of pages. */
final class TreeCopyJobPanel
{
    public function __construct(private PDO $db,private Access $access,private string $csrf){}
    public function render(array $query):string
    {
        $service=new TreeCopyJobs($this->db,$this->access);$id=$query['copy_job']??'';
        if(!is_string($id))throw new \InvalidArgumentException('Kopierauftragskennung prüfen.');
        $h='<div class="tree-copy-jobs"><h2>Teilbaum kopieren</h2>';
        if($id!==''){
            $job=$service->get($id);$active=in_array($job['status'],['ready','paused'],true);
            $h.='<section class="panel" data-tree-copy-job="'.Html::e($id).'"><p role="status">'.$job['next_index'].' von '.$job['total'].' Seiten vorbereitet · '.['ready'=>'Bereit','paused'=>'Angehalten','completed'=>'Vollständig angelegt','cancelled'=>'Abgebrochen'][$job['status']].'</p>';
            if($job['last_reason'])$h.='<p class="alert theme-warning">'.Html::e($job['last_reason']).'</p>';
            if($active){
                $h.='<p>Die Originale bleiben erhalten. Während der Vorbereitung entstehen keine Seitenkopien.</p>';
                try{$this->access->require('website','edit');$allowed=true;}catch(\DomainException){$allowed=false;}
                if($allowed){
                    $complete=$job['next_index']===$job['total'];
                    $h.=$this->form($job,$complete?'complete':'advance',$complete?'Vollständigen Teilbaum privat anlegen':'Nächste 25 Seiten vorbereiten',$complete);
                    $h.='<details><summary>Kopierauftrag abbrechen</summary>'.$this->form($job,'cancel','Vorbereitung abbrechen',false).'</details>';
                }
            }else{
                if($job['status']==='completed')$h.='<p><a class="btn btn-solid theme-primary" data-nav href="/?view=website&amp;item='.Html::e($job['root_copy_id']).'">Kopierten Teilbaum öffnen</a></p>';
                try{$this->access->require('website','manage');$manage=true;}catch(\DomainException){$manage=false;}
                if($manage)$h.='<details><summary>Auftragsdaten entfernen</summary><form method="post">'.Html::hidden('csrf',$this->csrf).Html::hidden('operation','tree-copy-forget').Html::hidden('copy_job',$job['id']).Html::hidden('version',$job['version']).'<label class="check"><input type="checkbox" name="copy_forget_confirm" value="1" required> Gespeicherte Auftragsdaten endgültig löschen</label><button class="btn btn-solid theme-secondary">Auftragsdaten entfernen</button></form></details>';
            }
            $h.='</section>';
        }
        $h.='<details'.($id===''?' open':'').'><summary>Letzte Kopieraufträge</summary><ul class="list-group">';
        foreach($service->recent()as $job)$h.='<li class="list-group-item"><a data-nav href="/?view=website&amp;section=copy-jobs&amp;copy_job='.Html::e($job['id']).'">'.$job['next_index'].' / '.$job['total'].' Seiten · '.Html::e(['ready'=>'Vorbereitung','paused'=>'Angehalten','completed'=>'Abgeschlossen','cancelled'=>'Abgebrochen'][$job['status']]).'</a></li>';
        return $h.'</ul></details><p><a data-nav href="/?view=website">Zur Website-Redaktion</a></p></div>';
    }
    private function form(array $job,string $action,string $label,bool $confirmed):string
    {
        return '<form method="post">'.Html::hidden('csrf',$this->csrf).Html::hidden('operation','tree-copy-'.$action).Html::hidden('copy_job',$job['id']).Html::hidden('version',$job['version']).($confirmed?'<label class="check"><input class="check" type="checkbox" name="copy_confirm" value="1" required> Alle '.$job['total'].' Kopien als private Entwürfe anlegen</label>':'').'<button class="btn btn-solid theme-'.($action==='cancel'?'secondary':'primary').'">'.Html::e($label).'</button></form>';
    }
}
