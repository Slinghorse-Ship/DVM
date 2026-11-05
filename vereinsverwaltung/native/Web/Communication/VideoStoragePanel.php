<?php declare(strict_types=1);
namespace Kulturhus\Native\Web\Communication;
use DomainException;
use InvalidArgumentException;
use Kulturhus\Native\Web\{Workspace,Html};
use Kulturhus\Native\Communication\VideoStorageInventory;
use Kulturhus\Native\Language\{Catalog,VideoInventoryMessages};

final class VideoStoragePanel extends Workspace
{
    use VideoInterfaceText;
    public function page(string $id):string
    {
        $actor=$this->access->require('social','manage');$reader=$this->access->require('social','read');
        if($reader['id']!==$actor['id']||$reader['club_id']!==$actor['club_id'])throw new DomainException('Vereinszuordnung geändert. Videospeicher erneut öffnen.');
        try{
            if(($_GET['video_storage_window']??null)!=='1')throw new InvalidArgumentException('Videospeicher erneut öffnen.');
            $item=(new \Kulturhus\Native\Communication\Workspace($this->db,$this->access,'social',$this->mediaRoot))->get($id);
            $h='<section class="panel video-storage-window">'.$this->render($item).'</section>';
        }catch(DomainException|InvalidArgumentException $error){
            $messages=(new Catalog($this->db,$this->access))->messages();$t=static fn(string$s):string=>Html::e(VideoInventoryMessages::text($s,$messages));
            $h='<section class="panel video-storage-window"><h2>'.$t('Nicht zugeordnete Videodateien').'</h2><p role="alert">'.$t($error->getMessage()).'</p></section>';
        }
        foreach(['read','manage']as$cap){$current=$this->access->require('social',$cap);if($current['id']!==$actor['id']||$current['club_id']!==$actor['club_id'])throw new DomainException('Vereinszuordnung geändert. Videospeicher erneut öffnen.');}return$h;
    }

    public function render(array $item):string
    {
        if(!$this->can('social','manage'))return '';
        $catalog=new Catalog($this->db,$this->access);$messages=$catalog->messages();$english=explode('-',$catalog->locale(),2)[0]==='en';$t=static fn(string$s,array$v=[]):string=>Html::e(VideoInventoryMessages::text($s,$messages,$v));
        $check=$_GET['video_storage_check']??'';$after=$_GET['video_storage_after']??'';$source=$_GET['video_storage_source']??'';$window=$_GET['video_storage_window']??'';
        if(!is_string($check)||!in_array($check,['','1'],true)||!is_string($after)||!is_string($source)||!is_string($window)||!in_array($window,['','1'],true))throw new InvalidArgumentException('Videospeicher erneut öffnen.');
        foreach([$after,$source]as$id)if($id!==''&&!preg_match('/\A[a-f0-9]{32}\z/D',$id))throw new InvalidArgumentException('Videodatei erneut auswählen.');
        $own=$window==='1';$link=$own?' data-save-original data-navigation-native':' data-save-original data-navigation-native target="_blank" rel="noopener"';$open=$check==='1'||$own;
        $h='<details class="social-video-storage" id="social-video-storage"'.($open?' open':'').'><summary>'.$t('Nicht zugeordnete Videodateien').'</summary><p>'.$t($own?'Die Prüfung liest ältere Dateien ohne gespeicherte Videozuordnung. Dabei werden keine Dateien geändert.':'Die Dateiprüfung öffnet ein eigenes Fenster. Dein ungespeicherter Beitrag bleibt erhalten.').'</p><a class="btn btn-outline theme-secondary"'.$link.' href="'.Html::e($this->url($item['id'],$after,$source)).'">'.$t($check==='1'?'Erneut prüfen':'Speicher prüfen').'</a>';
        if($check!=='1')return $h.'</details>';
        $inventory=new VideoStorageInventory($this->db,$this->access,$this->mediaRoot);$list=$inventory->listing($after);$selected=null;$error='';
        if($source!==''){
            if(!in_array($source,array_column($list['rows'],'id'),true))$error='Die ausgewählte Datei ist auf dieser Seite nicht vorhanden. Ursprüngliche Auswahl erneut prüfen.';
            else try{$selected=$inventory->inspect($source);}catch(DomainException){$error='Datei nicht als vollständiges Video bestätigt. Erneut prüfen.';}
        }
        if(!$list['complete'])$h.='<p role="status">'.$t('Einige Dateien konnten nicht vollständig geprüft werden.').'</p>';
        if($error!=='')$h.='<p role="alert">'.$t($error).'</p><a class="btn btn-outline theme-secondary"'.$link.' href="'.Html::e($this->url($item['id'],$after,$source)).'">'.$t('Ursprüngliche Dateiauswahl erneut prüfen').'</a>';
        if(!$list['rows'])$h.='<p>'.$t('Keine nicht zugeordneten Videodateien gefunden.').'</p>';
        $number=static fn(float$n,int$d):string=>number_format($n,$d,$english?'.':',',$english?',':'.');
        $h.='<div class="video-storage-list">';
        foreach($list['rows']as$row){
            $h.='<article data-video-storage-source="'.$row['id'].'"><strong>'.$t('Videodatei {id}',['id'=>substr($row['id'],-8)]).'</strong><span>'.Html::e($number($row['bytes']/1048576,2)).' MiB · '.$this->videoTime($row['changed_at']).'</span>';
            if($selected!==null&&$selected['id']===$row['id']){
                $p=$selected['profile'];$h.='<p class="video-storage-proof" role="status">'.$t('Video bestätigt').' · '.Html::e($number($p['duration_ms']/1000,1)).' s · '.$p['width'].' × '.$p['height'].' · '.$t($p['audio']?'Mit Ton':'Ohne Ton').'</p>';
                if($this->can('social','edit')&&\Kulturhus\Native\Communication\VideoRecovery::installed($this->db)&&$item['kind']==='reel'&&in_array($item['status'],['draft','review'],true))$h.='<a class="btn btn-outline theme-secondary" data-save-original data-navigation-native target="_blank" rel="noopener" href="'.Html::e('/?'.http_build_query(VideoRecoveryPanel::query($item['id'],$row['id'])).'#social-video-recovery').'">'.$t('Wiederherstellen').'</a>';
            }elseif($row['inspectable'])$h.='<a class="btn btn-outline theme-secondary"'.$link.' href="'.Html::e($this->url($item['id'],$after,$row['id'])).'">'.$t('Datei prüfen').'</a>';
            else $h.='<p>'.$t('Unvollständige Datei oder mehr als 100 MiB.').'</p>';
            $h.='</article>';
        }
        $h.='</div><nav class="social-video-pager" aria-label="'.$t('Nicht zugeordnete Videoseiten').'">';
        if($after!=='')$h.='<a class="btn btn-outline theme-secondary"'.$link.' href="'.Html::e($this->url($item['id'],'')).'">'.$t('Erste Dateien').'</a>';
        if($list['has_more'])$h.='<a class="btn btn-outline theme-secondary"'.$link.' href="'.Html::e($this->url($item['id'],$list['next_after'])).'">'.$t('Weitere Dateien').'</a>';
        return $h.'</nav></details>';
    }
    private function url(string $item,string $after,string $source=''):string
    {
        $query=['view'=>'social','section'=>'compose','item'=>$item,'video_storage_window'=>'1','video_storage_check'=>'1','video_storage_after'=>$after];if($source!=='')$query['video_storage_source']=$source;return '/?'.http_build_query($query).'#social-video-storage';
    }
}
