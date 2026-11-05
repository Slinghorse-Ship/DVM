<?php declare(strict_types=1);
namespace Kulturhus\Native\Web\Communication;
use DomainException;
use InvalidArgumentException;
use Kulturhus\Native\Communication\{VideoRecovery,VideoRecoveryRequests};
use Kulturhus\Native\Shared\Identity;
use Kulturhus\Native\Language\{Catalog,VideoRecoveryMessages};
use Kulturhus\Native\Web\{Workspace,Html};
final class VideoRecoveryPanel extends Workspace
{
    use VideoInterfaceText;
    public static function query(string $item,string $source='',string $after=''):array
    {return['view'=>'social','section'=>'compose','item'=>$item,'video_recovery_window'=>'1','video_recovery_check'=>'1','video_recovery_source'=>$source,'video_recovery_after'=>$after];}
    private function url(string $item,string $source='',string $after=''):string
    {return'/?'.http_build_query(self::query($item,$source,$after)).'#social-video-recovery';}
    public function page(string $id):string
    {
        $actor=$this->access->require('social','manage');$reader=$this->access->require('social','read');if($reader['id']!==$actor['id']||$reader['club_id']!==$actor['club_id'])throw new DomainException('Speicherzugang geändert.');
        try{if(($_GET['video_recovery_window']??null)!=='1')throw new InvalidArgumentException('Video-Wiederherstellung erneut öffnen.');$item=(new \Kulturhus\Native\Communication\Workspace($this->db,$this->access,'social',$this->mediaRoot))->get($id);$h='<section class="panel video-recovery-window">'.$this->render($item).'</section>';}
        catch(DomainException|InvalidArgumentException $error){$messages=(new Catalog($this->db,$this->access))->messages();$h='<section class="panel"><h2>'.Html::e(VideoRecoveryMessages::text('Video-Wiederherstellungen',$messages)).'</h2><p role="alert">'.Html::e(VideoRecoveryMessages::text($error->getMessage(),$messages)).'</p></section>';}
        foreach(['read','manage']as$cap){$current=$this->access->require('social',$cap);if($current['id']!==$actor['id']||$current['club_id']!==$actor['club_id'])throw new DomainException('Speicherzugang geändert.');}return$h;
    }
    public function render(array $item):string
    {
        if(!$this->can('social','manage')||!VideoRecovery::installed($this->db))return'';
        $catalog=new Catalog($this->db,$this->access);$messages=$catalog->messages();$locale=$catalog->locale();$t=static fn(string$s):string=>Html::e(VideoRecoveryMessages::text($s,$messages));
        $check=$_GET['video_recovery_check']??'';$source=$_GET['video_recovery_source']??'';$after=$_GET['video_recovery_after']??'';$window=$_GET['video_recovery_window']??'';$read=$_GET['video_recovery_read']??'';
        foreach([$check,$window,$read]as$value)if(!is_string($value)||!in_array($value,['','0','1'],true))throw new InvalidArgumentException('Videovorgang erneut prüfen.');if($check==='0'||$window==='0'||!is_string($source)||!is_string($after))throw new InvalidArgumentException('Video-Wiederherstellung erneut öffnen.');
        foreach([$source,$after]as$id)if($id!==''&&!preg_match('/\A[a-f0-9]{32}\z/D',$id))throw new InvalidArgumentException('Ursprünglichen Videovorgang auswählen.');
        $posted=($_POST['operation']??null)==='social-video-recover'&&is_string($_POST['csrf']??null)&&hash_equals($this->csrf,$_POST['csrf']);if($posted)$check='1';$own=$window==='1';$link=' data-save-original data-navigation-native'.($own?'':' target="_blank" rel="noopener"');
        $h='<details class="social-video-recovery" id="social-video-recovery"'.($check==='1'||$own?' open':'').'><summary>'.$t('Video-Wiederherstellungen').'</summary><p>'.$t($own?'Wiederherstellungen zeigen den ursprünglichen Dateivorgang. Eine gespeicherte Fassung bleibt auch nach späterem Archivieren oder Löschen bestätigt.':'Die Wiederherstellungsprüfung öffnet ein eigenes Fenster. Dein ungespeicherter Beitrag bleibt erhalten.').'</p><a class="btn btn-outline theme-secondary"'.$link.' href="'.Html::e($this->url($item['id'],'',$after)).'">'.$t('Vorgänge prüfen').'</a>';
        if($check!=='1')return$h.'</details>';$service=new VideoRecovery($this->db,$this->access,$this->mediaRoot);
        if($source!==''){
            try{$plan=$service->prepare($source);$selected='<section class="video-recovery-selected">';
                if(isset($plan['item_id'])&&$plan['item_id']!==$item['id'])$selected.='<a class="btn btn-outline theme-secondary"'.$link.' href="'.Html::e($this->url($plan['item_id'],$source,$after)).'">'.$t('Ursprünglichen Beitrag öffnen').'</a>';
                else{
                    $state=$posted&&($_POST['source_id']??null)===$source?VideoRecoveryForm::restore($_POST):VideoRecoveryForm::initial($item,Identity::forClub($this->db,$this->access->club()),$plan,$after);
                    if($plan['state']!=='new')$state['result']=(new VideoRecoveryRequests($this->db,$this->access,$this->mediaRoot))->review($item['id'],$source,$state['source_fingerprint'],$state['recovery_name']);
                    $selected.=VideoRecoveryForm::render($state,$this->csrf,$messages,$locale,!$own,true,$this->can('social','edit')&&$item['kind']==='reel'&&in_array($item['status'],['draft','review'],true));
                }$h.=$selected.'</section>';
            }catch(DomainException|\Kulturhus\Native\Cms\Conflict){$h.='<p role="alert">'.$t('Videovorgang derzeit nicht fortsetzbar. Datei und Beitrag erneut prüfen.').'</p><a class="btn btn-outline theme-secondary"'.$link.' href="'.Html::e($this->url($item['id'],$source,$after)).'">'.$t('Vorgang erneut prüfen').'</a>';}
        }
        $list=$service->listing($after);$h.='<div class="video-recovery-list">';foreach($list['rows']as$row)$h.='<article data-video-recovery-original="'.$row['original_id'].'" data-video-recovery-row-state="'.$row['state'].'"><strong>'.Html::e($row['original_name']).'</strong><span>'.$t($row['state']==='complete'?'Gespeichert':'Offen').' · '.$this->videoTime($row['created_at']).' · '.Html::e(substr($row['original_id'],-8)).'</span><a class="btn btn-outline theme-secondary"'.$link.' href="'.Html::e($this->url($row['item_id'],$row['original_id'],$after)).'">'.$t($row['state']==='complete'?'Ergebnis öffnen':'Fortsetzung öffnen').'</a></article>';
        $h.='</div><nav class="social-video-pager" aria-label="'.$t('Video-Wiederherstellungsseiten').'">';if($after!=='')$h.='<a class="btn btn-outline theme-secondary"'.$link.' href="'.Html::e($this->url($item['id'])).'">'.$t('Erste Vorgänge').'</a>';if($list['has_more'])$h.='<a class="btn btn-outline theme-secondary"'.$link.' href="'.Html::e($this->url($item['id'],'',$list['next_after'])).'">'.$t('Weitere Vorgänge').'</a>';if(!$list['rows']&&$source==='')$h.='<p>'.$t('Keine Wiederherstellungen begonnen.').'</p>';return$h.'</nav></details>';
    }
}
