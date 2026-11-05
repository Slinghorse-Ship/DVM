<?php declare(strict_types=1);
namespace Kulturhus\Native\Web\Communication;

use Kulturhus\Native\Web\{Workspace,Html};
use Kulturhus\Native\Communication\VideoArchive;

/** A short reversible action and explicitly requested, bounded original history. */
final class VideoArchivePanel extends Workspace
{
    use VideoInterfaceText;
    public function render(array $item,array $row,array $page):string
    {
        if(!VideoArchive::installed($this->db))return '';
        $archived=(bool)$row['archived'];$h='<div class="social-video-archive-actions">';
        $catalog=new \Kulturhus\Native\Language\Catalog($this->db,$this->access);$messages=$catalog->messages();
        if($this->can('social','edit')&&\Kulturhus\Native\Communication\VideoRemoval::available($this->db,$this->access->club(),$row['source_id']))$h.=VideoArchiveForm::render($item,$row,$page,$this->csrf,$messages,null,true,true,$catalog->locale());
        $query=['view'=>'social','section'=>'compose','item'=>$item['id'],'video_page'=>$page['page'],'video_archive'=>$archived?'1':'0','video_archive_source'=>$row['source_id']];
        $selected=($_GET['video_archive_source']??null)===$row['source_id'];
        $h.='<details class="social-video-archive-history"'.($selected?' open':'').'><summary>'.Html::e($this->videoText('Archivverlauf')).'</summary>';
        if(!$selected)$h.='<a class="btn btn-outline theme-secondary" data-nav href="'.Html::e('/?'.http_build_query($query).'#social-videos').'">'.Html::e($this->videoText('Verlauf öffnen')).'</a>';
        else{
            $history=(new VideoArchive($this->db,$this->access))->history($row['source_id'],$_GET['video_archive_history']??1);
            foreach($history['rows']as$entry)$h.='<p>'.Html::e($this->videoText((int)$entry['archived']===1?'Archiviert':'Zurückgeholt')).' · '.Html::e($entry['actor_name']).'<br>'.$this->videoTime($entry['changed_at']).'</p>';
            if(!$history['total'])$h.='<p>'.Html::e($this->videoText('Noch keine Änderungen.')).'</p>';
            if($history['pages']>1){$h.='<nav aria-label="'.Html::e($this->videoText('Videoarchiv-Verlaufsseiten')).'"><span>'.$history['page'].'/'.$history['pages'].'</span>';foreach([-1=>'Vorheriger Verlauf',1=>'Weiterer Verlauf']as$step=>$label){$next=$history['page']+$step;if($next>0&&$next<=$history['pages'])$h.='<a class="btn btn-outline theme-secondary" data-nav href="'.Html::e('/?'.http_build_query($query+['video_archive_history'=>$next]).'#social-videos').'">'.Html::e($this->videoText($label)).'</a>';}$h.='</nav>';}
        }
        return $h.'</details></div>';
    }
}
