<?php declare(strict_types=1);
namespace Kulturhus\Native\Web\Communication;
use Kulturhus\Native\Web\{Workspace,Html};
use Kulturhus\Native\Communication\{Videos,Images,VideoMetadata};
use Kulturhus\Native\Shared\Identity;

/** Native private upload and deliberately opened, six-item video history. */
final class VideoHistoryPanel extends Workspace
{
    use VideoInterfaceText;
    public function render(array $item): string
    {
        $videos=new Videos($this->db,$this->access,$this->mediaRoot);
        if(!$videos->installed())return '';
        $actor=$this->access->require('social','read');
        $identity=Identity::forClub($this->db,$this->access->club());
        $page=$videos->page($item['id'],$_GET['video_page']??1,($_GET['video_archive']??null)==='1');
        $h='<section class="panel social-video-history" id="social-videos" tabindex="-1"><h2>'.Html::e($this->videoText('Videofassungen')).' <span class="badge">'.($page['active_total']+$page['archived_total']).'</span></h2>';
        $h.=(new VideoQuotaPanel($this->db,$this->access,$this->mediaRoot,$this->csrf))->render($item);
        $h.=(new VideoRetentionPanel($this->db,$this->access,$this->mediaRoot,$this->csrf))->render($item);
        if($this->can('social','edit')){
            $messages=(new \Kulturhus\Native\Language\Catalog($this->db,$this->access))->messages();$text=static fn(string$source):string=>\Kulturhus\Native\Language\VideoStudioMessages::text($source,$messages);
            $h.='<details class="social-video-upload"><summary>'.Html::e($text('Fertige MP4 direkt speichern')).'</summary>'.VideoUploadForm::render($item,$identity,$this->csrf,$text).'</details>';
        }
        $h.='<nav class="social-video-pager" aria-label="'.Html::e($this->videoText('Videoauswahl')).'">';
        foreach([false=>'Aktuell',true=>'Archiv']as$archived=>$label)$h.='<a class="btn btn-outline theme-secondary" data-nav'.((bool)$archived===$page['archived']?' aria-current="page"':'').' href="'.Html::e('/?'.http_build_query(['view'=>'social','section'=>'compose','item'=>$item['id'],'video_page'=>1,'video_archive'=>$archived?'1':'0']).'#social-videos').'">'.Html::e($this->videoText($label)).' · <span data-video-'.($archived?'archived':'active').'-count>'.($archived?$page['archived_total']:$page['active_total']).'</span></a>';
        $h.='</nav>';
        if(!$page['total'])return $this->finish($h.'<p>'.Html::e($this->videoText($page['archived']?'Keine archivierten Videos.':'Keine aktuellen Videos.')).'</p></section>',$actor);
        $h.='<details class="social-video-versions"'.(isset($_GET['video_page'])?' open':'').'><summary>'.Html::e($this->videoText('Gespeicherte Videos')).'</summary>'.$this->pager($item['id'],$page).'<div class="social-video-grid">';
        $metadataAvailable=VideoMetadata::installed($this->db);
        foreach($page['rows']as$row){
            $profile=json_decode($row['profile_json'],true,16,JSON_THROW_ON_ERROR);$url='/social-video/'.$row['id'];
            $life=(new \Kulturhus\Native\Communication\VideoRemoval($this->db,$this->access,$this->mediaRoot))->get($row['source_id']);
            $opened=($_GET['video_archive_source']??null)===$row['source_id']||($_GET['video_lifecycle_source']??null)===$row['source_id'];
            $h.='<article><details class="social-video-card-tools" data-video-source="'.Html::e($row['source_id']).'" data-video-file-version="'.Html::e($life['version']).'" name="video-actions-'.Html::e($item['id']).'"'.($opened?' open':'').'><summary><strong>'.Html::e($row['original_name']).'</strong><span class="video-row-action" aria-hidden="true"></span></summary>';
            if($life['state']==='active')$h.='<span class="social-video-profile">'.Html::e($this->mediaNumber($profile['duration_ms']/1000,1)).' s · '.Html::e($this->videoText($profile['audio']?'Mit Ton':'Ohne Ton')).'</span><details class="social-video-player" name="video-preview-'.Html::e($item['id']).'"><summary>'.Html::e($this->videoText('Video ansehen')).'</summary><video controls playsinline preload="none" src="'.$url.'" aria-label="'.Html::e($row['original_name']).'"></video></details><a class="btn btn-outline theme-secondary" href="'.$url.'" download>'.Html::e($this->videoText('Download')).'</a>';
            else $h.='<span class="badge">'.Html::e($this->videoText($life['state']==='purged'?'Datei gelöscht':(in_array($life['state'],['removed','restoring'],true)?'Datei im Papierkorb':'Dateivorgang läuft'))).'</span>';
            $metadata=$metadataAvailable?VideoMetadata::current($this->db,$this->access->club(),$row['source_id']):null;
            if($item['kind']==='reel'&&($item['approved_video']['id']??null)===$row['id']){
                $fresh=$metadata===null||($item['approved_video']['metadata_version']??0)===$metadata['version'];
                $h.='<span class="badge">'.Html::e($this->videoText($fresh?'Freigegeben':'Freigabe erneuern')).'</span>';
                if($life['state']==='active'&&!$page['archived']&&!$fresh&&$this->can('social','publish'))$h.=$this->start('social-video-renew').Html::hidden('id',$item['id']).Html::hidden('version',$item['version']).Html::hidden('video_id',$row['id']).'<button class="btn btn-outline theme-secondary">'.Html::e($this->videoText('Neue Freigabe vorbereiten')).'</button></form>';
            }
            elseif($life['state']==='active'&&!$page['archived']&&$item['kind']==='reel'&&in_array($item['status'],['draft','review'],true)&&$this->can('social','publish')&&(int)$row['item_version']===$item['version']&&(int)$row['identity_version']===$identity['version']&&hash_equals($row['content_hash'],Images::fingerprint($item)))$h.=$this->start('communication-approve').Html::hidden('id',$item['id']).Html::hidden('version',$item['version']).Html::hidden('video_id',$row['id']).'<button class="btn btn-solid theme-primary">'.Html::e($this->videoText('Diese Fassung freigeben')).'</button></form>';
            if($metadata!==null)$h.=(new VideoMetadataPanel($this->db,$this->access,$this->mediaRoot,$this->csrf))->render($item,$row,$metadata);
            if($this->can('social','edit')&&$row['id']===Videos::reuseIdentity($actor['club_id'],$item['id'],(int)$row['item_version'],(int)$row['identity_version'],$row['source_id'])){$reuseState=['id'=>$item['id'],'reuse_video_id'=>$row['id'],'source_id'=>$row['source_id'],'version'=>(string)$row['item_version'],'identity_version'=>(string)$row['identity_version'],'video_use_query'=>'','video_use_page'=>'1'];$reuseMessages=(new \Kulturhus\Native\Language\Catalog($this->db,$this->access))->messages();$h.='<a class="btn btn-outline theme-secondary" data-save-original data-navigation-native data-video-use-original target="_blank" rel="noopener" href="'.Html::e('/?'.http_build_query(VideoReusePanel::original($reuseState)).'#social-video-reuse').'">'.Html::e(\Kulturhus\Native\Language\VideoReuseMessages::text('Ursprüngliche Videoübernahme öffnen',$reuseMessages)).'</a>';}
            $h.=(new VideoArchivePanel($this->db,$this->access,$this->mediaRoot,$this->csrf))->render($item,$row,$page);
            $h.=(new VideoRemovalPanel($this->db,$this->access,$this->mediaRoot,$this->csrf))->render($item,$row,$page,$life);
            $h.='</details></article>';
        }
        return $this->finish($h.'</div>'.$this->pager($item['id'],$page).'</details></section>',$actor);
    }
    private function finish(string $html,array $actor):string
    {
        $current=$this->access->require('social','read');if($current['id']!==$actor['id']||$current['club_id']!==$actor['club_id'])throw new \DomainException('Speicherzugang geändert.');return$html;
    }
    private function pager(string $item,array $page): string
    {
        $h='<nav class="social-video-pager" aria-label="'.Html::e($this->videoText('Gespeicherte Videoseiten')).'"><span>'.Html::e($this->videoText('{count} Videos · {page}/{pages}',['count'=>$page['total'],'page'=>$page['page'],'pages'=>$page['pages']])).'</span>';
        foreach([-1=>'Vorherige Videos',1=>'Weitere Videos']as$direction=>$label){$next=$page['page']+$direction;if($next>=1&&$next<=$page['pages'])$h.='<a class="btn btn-outline theme-secondary" data-nav href="'.Html::e('/?'.http_build_query(['view'=>'social','section'=>'compose','item'=>$item,'video_page'=>$next,'video_archive'=>$page['archived']?'1':'0']).'#social-videos').'">'.Html::e($this->videoText($label)).'</a>';}
        return $h.'</nav>';
    }
}
