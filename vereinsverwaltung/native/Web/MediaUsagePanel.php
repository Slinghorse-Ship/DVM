<?php declare(strict_types=1);
namespace Kulturhus\Native\Web;
use Kulturhus\Native\Cms\{MediaUsage,MediaReferences};
use InvalidArgumentException;

final class MediaUsagePanel extends Workspace
{
    private const LABELS=['provider-image'=>'Anbieterbild','photo-copy'=>'Bearbeitetes Bild','draft'=>'Aktuelle Fassung','published'=>'Veröffentlicht','history'=>'Frühere Fassung','logo'=>'Vereinslogo','template'=>'Vorlage','template-history'=>'Frühere Vorlage','plan'=>'Gespeicherte Planung','image'=>'Gespeichertes Bild','event-original'=>'Ursprünglicher Termin','browser-original'=>'Gespeicherte Browserausgabe'];
    public function render(string $id,array $query):string
    {
        $globalRequested=array_key_exists('media_global_check',$query);
        if($globalRequested&&$query['media_global_check']!=='1')throw new InvalidArgumentException('Gesamte Bildverwendung prüfen.');
        if($globalRequested)$this->access->require('website','manage');
        $page=(new MediaUsage($this->db,$this->access))->listing($id,$query);$open=$globalRequested||isset($query['media_usage_page'])||isset($query['media_usage_source']);
        $h='<details class="panel media-usage" id="media-usage" tabindex="-1"'.($open?' open':'').'><summary>'.Html::e($this->mediaFormat('Verwendungen · {count}',['count'=>$page['total']])).'</summary>';
        if($this->can('website','manage'))$h.='<form method="get" action="/#media-usage">'.Html::hidden('view','media').Html::hidden('section','library').Html::hidden('item',$id).Html::hidden('media_usage_page','1').Html::hidden('media_global_check','1').'<button class="btn btn-outline theme-secondary">'.Html::e($this->mediaText($globalRequested?'Gesamte Verwendung erneut prüfen':'Gesamte Verwendung prüfen')).'</button></form>';
        if($globalRequested){
            $global=(new MediaReferences($this->db,$this->access))->inspect($id);
            $h.='<p class="badge" data-media-global-state="'.(!$global['complete']?'incomplete':($global['referenced']?'retained':'unreferenced')).'">'.Html::e($this->mediaText(!$global['complete']?'Verwendungen nicht vollständig prüfbar':($global['referenced']?'Bild wird noch verwendet':'Keine Verwendung gefunden'))).'</p>';
        }
        if($page['restricted'])$h.='<p class="badge">'.Html::e($this->mediaText('Eingeschränkte Ansicht')).'</p>';
        $h.='<form method="get" action="/#media-usage">'.Html::hidden('view','media').Html::hidden('section','library').Html::hidden('item',$id).Html::select('media_usage_source',$this->mediaText('Bereich'),$this->mediaOptions(['all'=>'Alle Bereiche']+$page['modules']),$page['source']).'<button class="btn btn-outline theme-secondary">'.Html::e($this->mediaText('Verwendungen anzeigen')).'</button></form>'.$this->pager($id,$page).'<div class="media-usage-list">';
        foreach($page['rows']as$row){$label=$this->mediaText(MediaUsage::MODULES[$row['module']]).' · '.$this->mediaText(self::LABELS[$row['source']]);$title=($row['title']??'')!==''?$row['title']:$label;$link=$this->link($row);
            $h.='<article><span>'.Html::e($label).'</span><strong>'.Html::e($title).'</strong><small>'.Html::e($this->mediaFormat('Fassung {version} · {date} UTC',['version'=>(int)$row['version'],'date'=>$row['created_at']])).'</small>'.($link?'<a class="btn btn-outline theme-secondary" '.($row['source']==='image'?'target="_blank" rel="noopener"':'data-nav').' href="'.Html::e($link).'">'.Html::e($this->mediaText($row['source']==='image'?'Bild öffnen':'Inhalt öffnen')).'</a>':'').'</article>';
        }
        if(!$page['rows'])$h.='<p>'.Html::e($this->mediaText('Keine sichtbaren Verwendungen.')).'</p>';
        return $h.'</div>'.$this->pager($id,$page).'</details>';
    }

    private function link(array $row):?string
    {
        if($row['source']==='provider-image')return '/?'.http_build_query(['view'=>'events','item'=>$row['source_id']]);
        if($row['source']==='photo-copy')return '/?'.http_build_query(['view'=>'media','section'=>'library','item'=>$row['source_id']]);
        if($row['source']==='image')return $row['module']==='social'?'/social-image/'.$row['record_id']:null;
        if($row['module']==='website'&&in_array($row['source'],['draft','published','history'],true)){
            $view=match($row['renderer']){'news'=>'news','event'=>'events','gallery'=>'media',default=>'website'};$args=['view'=>$view,'item'=>$row['source_id']];
            if($row['source']!=='draft')$args+=['compare_from'=>$row['version']];
            return '/?'.http_build_query($args);
        }
        if($row['source']==='draft')return '/?'.http_build_query(['view'=>$row['module'],'section'=>'compose','item'=>$row['source_id']]);
        return null;
    }
    private function pager(string $id,array $page):string
    {
        if($page['pages']===1)return '';
        $h='<nav class="media-usage-pager" aria-label="'.Html::e($this->mediaText('Bildverwendungsseiten')).'"><span>'.$page['page'].'/'.$page['pages'].'</span>';
        foreach([-1=>'Vorherige Verwendungen',1=>'Weitere Verwendungen']as$step=>$label){$number=$page['page']+$step;if($number>=1&&$number<=$page['pages'])$h.='<a class="btn btn-outline theme-secondary" data-nav href="'.Html::e('/?'.http_build_query(['view'=>'media','section'=>'library','item'=>$id,'media_usage_source'=>$page['source'],'media_usage_page'=>$number]).'#media-usage').'">'.Html::e($this->mediaText($label)).'</a>';}
        return $h.'</nav>';
    }
}
