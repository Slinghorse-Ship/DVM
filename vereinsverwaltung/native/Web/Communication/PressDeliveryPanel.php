<?php declare(strict_types=1);
namespace Kulturhus\Native\Web\Communication;

use Kulturhus\Native\Communication\PressDeliveryCatalogue as Catalogue;
use Kulturhus\Native\Web\{Workspace,Html};

final class PressDeliveryPanel extends Workspace
{
    public function render(): string
    {
        $page=Catalogue::page($this->db,$this->access,$this->box(),$_GET);$e=Html::e(...);
        $route=['view'=>'press','section'=>'planning','press_item'=>$page['item'],'press_state'=>$page['state']];
        $h='<section class="panel press-deliveries" aria-label="Presseversand"><h2>Presseversand</h2><form method="get">'.Html::hidden('view','press').Html::hidden('section','planning').Html::hidden('press_item',$page['item']).Html::select('press_state','Status',Catalogue::STATES,$page['state']).'<button class="btn btn-solid theme-primary">Anzeigen</button></form><p role="status">'.$page['total'].' Zustellungen · Seite '.$page['page'].' von '.$page['pages'].'</p>';
        if($page['item']!=='')$h.='<p><a data-nav href="/?view=press&amp;section=planning">Alle Pressemitteilungen</a></p>';
        foreach($page['rows']as$row){
            $h.='<article class="press-delivery" id="press-job-'.$row['id'].'"><h3>'.$e($row['name']?:$row['email']).'</h3><p class="press-delivery-address">'.$e($row['email']).($row['outlet']!==''?' · '.$e($row['outlet']):'').'</p><p><span class="badge">'.$e(Catalogue::STATES[$row['status']]??'Stand prüfen').'</span> · '.$e($this->displayTime($row['due_at'])).'</p><p><a data-nav href="/?view=press&amp;item='.$row['item_id'].'">'.$e($row['title']).'</a> · Fassung '.(int)$row['item_version'].'</p>';
            if($row['status']==='uncertain')$h.='<p class="press-delivery-attention">Versandergebnis prüfen.</p>';
            if(isset(\Kulturhus\Native\Delivery\DeliveryNotStarted::LABELS[$row['reason']??'']))$h.='<p>'.$e(\Kulturhus\Native\Delivery\DeliveryNotStarted::LABELS[$row['reason']]).'</p>';
            $h.='<details><summary>Zustelldetails</summary><p>Versandversuche: '.(int)$row['attempts'].'</p>';
            if($row['completed_at'])$h.='<p>Letzter Stand: '.$e($this->displayTime($row['completed_at'])).'</p>';
            if($row['external_id'])$h.='<p>Nachrichtenkennung: '.$e($row['external_id']).'</p>';
            $h.='</details></article>';
        }
        if(!$page['rows'])$h.='<p>Keine Zustellungen vorhanden.</p>';
        $h.='<nav class="press-delivery-pages" aria-label="Zustellseiten">';
        foreach(['Zurück'=>-1,'Weiter'=>1]as$label=>$delta){$target=$page['page']+$delta;if($target>0&&$target<=$page['pages'])$h.='<a class="btn btn-outline theme-primary" data-nav href="/?'.$e(http_build_query($route+['press_page'=>$target])).'">'.$label.'</a>';}
        return $h.'</nav></section>';
    }
}
