<?php declare(strict_types=1);
namespace Kulturhus\Native\Web\Communication;
use Kulturhus\Native\Web\{Workspace,Html};
use Kulturhus\Native\Communication\VideoRemoval;
use Kulturhus\Native\Language\{Catalog,VideoFileMessages};
final class VideoRemovalPanel extends Workspace
{
    use VideoInterfaceText;
    public function render(array $item,array $row,array $page,array $state):string
    {
        if(!VideoRemoval::installed($this->db)||!$row['archived'])return'';
        $catalog=new Catalog($this->db,$this->access);$messages=$catalog->messages();$t=static fn(string$s):string=>Html::e(VideoFileMessages::text($s,$messages));$source=$row['source_id'];$selected=($_GET['video_lifecycle_source']??null)===$source;
        $manage=$this->can('social','manage');$visible=$manage?$state:array_intersect_key($state,array_flip(['version','state','actor_name','changed_at']));
        $h='<details class="social-video-removal"'.($selected?' open':'').'><summary>'.$t('Video-Papierkorb').'</summary>'.VideoRemovalForm::current($visible,$messages,$catalog->locale());
        if($manage){
            $kinds=match($state['state']){'active'=>['remove'],'removed'=>$state['purge_eligible']?['restore','purge']:['restore'],'removing','restoring','purging'=>['resume'],default=>[]};
            foreach($kinds as$kind){if($kind==='purge')$h.='<details class="social-video-purge"><summary>'.$t('Endgültig löschen').'</summary>';$h.=VideoRemovalForm::render($item,$row,$state,$this->csrf,$messages,$catalog->locale(),VideoRemovalForm::initial($item,$row,$state,$page['page'],$kind));if($kind==='purge')$h.='</details>';}
        }
        $query=['view'=>'social','section'=>'compose','item'=>$item['id'],'video_page'=>$page['page'],'video_archive'=>'1','video_lifecycle_source'=>$source];
        $h.='<details class="social-video-lifecycle-history"'.($selected?' open':'').'><summary>'.$t('Dateiverlauf').'</summary>';
        if(!$selected)$h.='<a class="btn btn-outline theme-secondary" data-nav href="'.Html::e('/?'.http_build_query($query).'#social-videos').'">'.$t('Dateiverlauf öffnen').'</a>';
        else{
            $history=(new VideoRemoval($this->db,$this->access,$this->mediaRoot))->history($source,$_GET['video_lifecycle_page']??1);
            foreach($history['rows']as$entry)$h.='<p>'.$t(VideoRemovalForm::STATES[$entry['state']]).' · '.Html::e($entry['actor_name']).'<br>'.$this->videoTime($entry['changed_at']).'</p>';
            if(!$history['total'])$h.='<p>'.$t('Noch keine Änderungen.').'</p>';
            if($history['pages']>1){$h.='<nav aria-label="'.$t('Video-Dateiverlauf').'">';foreach([-1=>'Vorheriger Verlauf',1=>'Weiterer Verlauf']as$step=>$label){$next=$history['page']+$step;if($next>=1&&$next<=$history['pages'])$h.='<a class="btn btn-outline theme-secondary" data-nav href="'.Html::e('/?'.http_build_query($query+['video_lifecycle_page'=>$next]).'#social-videos').'">'.$t($label).'</a>';}$h.='</nav>';}
        }
        return$h.'</details></details>';
    }
}
