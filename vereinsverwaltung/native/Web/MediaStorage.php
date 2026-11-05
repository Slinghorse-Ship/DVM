<?php declare(strict_types=1);
namespace Kulturhus\Native\Web;

use InvalidArgumentException;
use Kulturhus\Native\Cms\{MediaStorageInventory,MediaStorageRecovery,MediaRecoveryInventory,MediaAvailability,DocumentQuota};

/** Bounded storage observation and explicitly chosen private photo recovery. */
final class MediaStorage extends Workspace
{
    public function action(array $post): array
    {
        $this->validatePost($post);
        if(($post['operation']??null)==='media-storage-recover'){
            foreach(['source_id','source_fingerprint','recovery_name','recovery_alt']as$key)if(!is_string($post[$key]??null))throw new InvalidArgumentException('Dateigruppe und eingegebene Bildangaben prüfen.');
            $result=(new MediaStorageRecovery($this->db,$this->access,$this->mediaRoot))->recover($post['source_id'],$post['source_fingerprint'],$post['recovery_name'],$post['recovery_alt']);$_POST=[];
            return ['view'=>'media','section'=>'library','item'=>$result['id'],'notice'=>$this->mediaText($result['created']?'Foto wiederhergestellt.':'Foto bereits wiederhergestellt.')];
        }
        if(($post['operation']??null)!=='document-quota-save'||!is_string($post['quota_version']??null)||!preg_match('/\A(?:0|[1-9][0-9]{0,9})\z/D',$post['quota_version']))throw new InvalidArgumentException('Gespeicherte Dokumentenspeichergrenze prüfen.');
        (new DocumentQuota($this->db,$this->access))->save($post['limit_mib']??null,(int)$post['quota_version']);$_POST=[];
        return ['view'=>'media','section'=>'storage','notice'=>$this->mediaText('Dokumentenspeichergrenze gespeichert.')];
    }
    public function manager(array $query=[]): string
    {
        $this->access->require('website','manage');
        $checked=$query['storage_check']??'';$after=$query['storage_after']??'';
        $recoveries=$query['storage_recoveries']??'';$recoveryAfter=$query['recoveries_after']??'';
        if(!is_string($recoveries)||!in_array($recoveries,['','1'],true)||!is_string($recoveryAfter)||($recoveryAfter!==''&&!preg_match('/\A[a-f0-9]{32}\z/D',$recoveryAfter)))throw new InvalidArgumentException('Fortsetzungen erneut öffnen.');
        $recovery=$query['recover_source']??'';
        if(!is_string($recovery)||($recovery!==''&&!preg_match('/\A[a-f0-9]{32}\z/D',$recovery)))throw new InvalidArgumentException('Dateigruppe erneut öffnen.');
        if(!is_string($checked)||!in_array($checked,['','1'],true)||!is_string($after)||($after!==''&&!preg_match('/\A[a-f0-9]{32}\z/D',$after)))throw new InvalidArgumentException('Speicherprüfung neu öffnen.');
        $html=MediaLibrary::tabs(false,false,MediaAvailability::installed($this->db),true,true,text:$this->mediaText(...)).'<div class="media-library media-storage" id="media-storage"><div class="media-library-heading"><h2 tabindex="-1">'.Html::e($this->mediaText('Speicher prüfen')).'</h2></div>';
        $html.='<nav class="media-library-pager media-storage-views" aria-label="'.Html::e($this->mediaText('Speicheransichten')).'"><a data-nav'.($recoveries===''?' aria-current="page"':'').' href="/?view=media&amp;section=storage&amp;storage_check=1">'.Html::e($this->mediaText('Nicht zugeordnet')).'</a><a data-nav'.($recoveries==='1'?' aria-current="page"':'').' href="'.Html::e($this->recoveriesUrl('')).'">'.Html::e($this->mediaText('Fortsetzen')).'</a></nav>';
        if($recovery!=='')return $html.$this->recovery($recovery,$recoveries==='1',$recoveryAfter).'</div>';
        if($recoveries==='1')return $html.$this->recoveries($recoveryAfter).'</div>';
        $html.='<form method="get" action="/" class="media-storage-check">'.Html::hidden('view','media').Html::hidden('section','storage').Html::hidden('storage_check','1').'<button class="btn btn-solid theme-primary">'.Html::e($this->mediaText($checked==='1'?'Erneut prüfen':'Speicher prüfen')).'</button></form>';
        $html.=$this->documentQuota();
        if($checked!=='1')return $html.'</div>';
        $list=(new MediaStorageInventory($this->db,$this->access,$this->mediaRoot))->listing($after);
        $html.='<section class="media-storage-results" aria-label="'.Html::e($this->mediaText('Nicht zugeordnete Dateien')).'"><h3>'.Html::e($this->mediaText('Nicht zugeordnete Dateien')).'</h3>';
        if(!$list['complete'])$html.='<p class="media-storage-warning" role="status">'.Html::e($this->mediaText('Einige Dateien konnten nicht vollständig geprüft werden.')).'</p>';
        $html.=$this->pager($list).'<div class="removed-media-list">';
        foreach($list['rows']as$row){
            $html.='<article class="panel removed-media-card media-storage-card" data-storage-group="'.Html::e($row['id']).'">';
            if($row['has_thumbnail'])$html.='<img src="/media-storage/'.Html::e($row['id']).'/thumb" alt="" loading="lazy" width="320" height="320">';
            $html.='<div class="removed-media-body"><h4>'.Html::e($row['readable']?$this->mediaFormat($row['file_count']===1?'{count} Datei':'{count} Dateien',['count'=>$row['file_count']]):$this->mediaText('Dateien prüfen')).'</h4>';
            if($row['readable']){
                $html.='<p>'.Html::e($this->mediaFormat('{bytes} · Geändert {time}',['bytes'=>$this->bytes($row['bytes']),'time'=>$this->mediaTime($row['changed_at'])])).'</p>';
                if($row['first_files']){$html.='<details class="media-storage-files"><summary>'.Html::e($this->mediaText('Dateinamen anzeigen')).'</summary><ul>';foreach($row['first_files']as$name)$html.='<li>'.Html::e($name).'</li>';$html.='</ul></details>';}
                if($row['file_count']===4&&$row['has_thumbnail']&&$this->can('website','edit')&&$this->can('website','read'))$html.='<a class="btn btn-outline theme-secondary" data-nav href="'.Html::e($this->recoveryUrl($row['id'])).'">'.Html::e($this->mediaText('Foto wiederherstellen')).'</a>';
            }else $html.='<p>'.Html::e($this->mediaText('Diese Dateigruppe konnte nicht vollständig geprüft werden.')).'</p>';
            $html.='</div></article>';
        }
        if(!$list['rows'])$html.='<p class="media-storage-empty">'.Html::e($this->mediaText('Keine älteren nicht zugeordneten Dateien gefunden.')).'</p>';
        return $html.'</div>'.$this->pager($list).'</section></div>';
    }
    private function recoveriesUrl(string $after):string{return '/?'.http_build_query(['view'=>'media','section'=>'storage','storage_recoveries'=>'1','recoveries_after'=>$after]);}
    private function recoveries(string $after):string
    {
        $list=(new MediaRecoveryInventory($this->db,$this->access,$this->mediaRoot))->listing($after);
        $html='<section class="media-storage-results" aria-label="'.Html::e($this->mediaText('Begonnene Wiederherstellungen')).'"><h3>'.Html::e($this->mediaText('Fortsetzungen')).'</h3>';
        if(!$list['complete'])$html.='<p role="status">'.Html::e($this->mediaText('Einige Vorgänge benötigen Prüfung.')).'</p>';
        $html.=$this->pager($list,true).'<div class="removed-media-list">';
        foreach($list['rows']as$row){
            $html.='<article class="panel removed-media-card media-storage-card" data-recovery-target="'.Html::e($row['target_id']).'"><img src="/media-storage/'.Html::e($row['source_id']).'/thumb" alt="" loading="lazy" width="320" height="320"><div class="removed-media-body"><h4>'.Html::e($this->mediaText('Foto')).'</h4><p>'.$row['width'].' × '.$row['height'].' · '.Html::e($this->bytes($row['bytes'])).'</p>';
            if($row['recovery_state']==='running')$html.='<p role="status">'.Html::e($this->mediaText('Foto wird wiederhergestellt.')).'</p><a class="btn btn-outline theme-secondary" data-nav href="'.Html::e($this->recoveriesUrl($after)).'">'.Html::e($this->mediaText('Erneut prüfen')).'</a>';
            else $html.='<a class="btn btn-solid theme-primary" data-nav href="'.Html::e($this->recoveryUrl($row['source_id'],true,$after)).'">'.Html::e($this->mediaText('Fortsetzen')).'</a>';
            $html.='</div></article>';
        }
        if(!$list['rows'])$html.='<p>'.Html::e($this->mediaText('Keine begonnenen Wiederherstellungen.')).'</p>';
        return $html.'</div>'.$this->pager($list,true).'</section>';
    }
    private function recoveryUrl(string $source,bool $recoveries=false,string $after=''):string{return '/?'.http_build_query(['view'=>'media','section'=>'storage','recover_source'=>$source]+($recoveries?['storage_recoveries'=>'1','recoveries_after'=>$after]:[])).'#media-recovery';}
    private function recovery(string $source,bool $recoveries=false,string $after=''):string
    {
        $this->access->require('website','edit');$this->access->require('website','read');
        $posted=($_POST['operation']??null)==='media-storage-recover'&&($_POST['source_id']??null)===$source;
        $prepared=$posted?null:(new MediaStorageRecovery($this->db,$this->access,$this->mediaRoot))->prepare($source);
        $back=$recoveries?$this->recoveriesUrl($after):'/?view=media&section=storage&storage_check=1';
        $html='<section class="panel media-storage-recovery" id="media-recovery"><h3 tabindex="-1">'.Html::e($this->mediaText('Foto wiederherstellen')).'</h3><a data-nav href="'.Html::e($back).'">'.Html::e($this->mediaText('Zur Speicherprüfung')).'</a>';
        if($prepared!==null&&$prepared['existing'])return $html.'<p>'.Html::e($this->mediaText('Dieses Foto wurde bereits wiederhergestellt.')).'</p><a class="btn btn-solid theme-primary" data-nav href="'.Html::e('/?'.http_build_query(['view'=>'media','section'=>'library','item'=>$prepared['target_id']])).'">'.Html::e($this->mediaText('Foto öffnen')).'</a></section>';
        $html.='<img src="/media-storage/'.Html::e($source).'/thumb" width="320" height="320" alt="" loading="lazy">';
        if($prepared!==null)$html.='<p>'.$prepared['width'].' × '.$prepared['height'].' · '.Html::e($this->bytes($prepared['bytes'])).'</p>';
        $state=$prepared['recovery_state']??($posted&&($_POST['recovery_resume']??null)==='1'?'pending':'');
        if($state!=='')$html.='<p role="status">'.Html::e($this->mediaText($state==='running'?'Foto wird wiederhergestellt.':'Wiederherstellung unterbrochen.')).'</p>';
        $fingerprint=$posted?(is_string($_POST['source_fingerprint']??null)?$_POST['source_fingerprint']:''):$prepared['fingerprint'];
        $name=$posted&&is_string($_POST['recovery_name']??null)?$_POST['recovery_name']:'';$alt=$posted&&is_string($_POST['recovery_alt']??null)?$_POST['recovery_alt']:'';
        return $html.'<form method="post" action="'.Html::e($this->recoveryUrl($source,$recoveries,$after)).'">'.Html::hidden('csrf',$this->csrf).Html::hidden('operation','media-storage-recover').Html::hidden('source_id',$source).Html::hidden('source_fingerprint',$fingerprint).Html::hidden('recovery_resume',$state==='pending'?'1':'0').Html::input('recovery_name',$this->mediaText('Name (optional)'),$name,'text','maxlength="240"').'<details><summary>'.Html::e($this->mediaText('Bildbeschreibung ergänzen')).'</summary>'.Html::input('recovery_alt',$this->mediaText('Alternativtext'),$alt,'text','maxlength="500"').'</details><p role="alert" tabindex="-1" data-media-removal-error hidden></p><a data-nav data-media-removal-check'.($posted||$state==='running'?'':' hidden').' href="'.Html::e($this->recoveryUrl($source,$recoveries,$after)).'">'.Html::e($this->mediaText('Dateigruppe erneut prüfen')).'</a><button class="btn btn-solid theme-primary"'.($state==='running'?' disabled':'').'>'.Html::e($this->mediaText($state==='pending'?'Fortsetzen':'Foto wiederherstellen')).'</button></form></section>';
    }
    private function documentQuota(): string
    {
        if(!DocumentQuota::installed($this->db))return '';
        $quota=(new DocumentQuota($this->db,$this->access))->get();$posted=($_POST['operation']??null)==='document-quota-save';
        $mib=$posted?(is_string($_POST['limit_mib']??null)?$_POST['limit_mib']:''):(string)($quota['limit_bytes']/1048576);
        $version=$posted?(is_string($_POST['quota_version']??null)?$_POST['quota_version']:'invalid'):(string)$quota['version'];
        $current='/?view=media&section=storage#document-quota';
        $html='<details class="panel document-quota" id="document-quota"'.($posted?' open':'').'><summary>'.Html::e($this->mediaText('PDF-Speicher')).'</summary><p>'.Html::e($this->mediaFormat($quota['document_count']===1?'{count} Dokument':'{count} Dokumente',['count'=>$quota['document_count']])).' · '.Html::e($this->bytes($quota['used_bytes'])).'</p><p>'.Html::e($quota['limit_bytes']===0?$this->mediaText('Ohne Speichergrenze'):$this->mediaFormat('{remaining} verfügbar von {limit}',['remaining'=>$this->bytes($quota['remaining_bytes']),'limit'=>$this->bytes($quota['limit_bytes'])])).'</p>';
        $html.='<form method="post" action="'.Html::e($current).'">'.Html::hidden('csrf',$this->csrf).Html::hidden('operation','document-quota-save').Html::hidden('quota_version',$version).Html::input('limit_mib',$this->mediaText('Speichergrenze in MiB (0 = ohne Grenze)'),$mib,'number','required min="0" max="102400" step="1"').'<p role="alert" tabindex="-1" data-media-removal-error hidden></p><a data-nav data-media-removal-check'.($posted?'':' hidden').' href="'.Html::e($current).'">'.Html::e($this->mediaText('Aktuelle Speichergrenze prüfen')).'</a><button class="btn btn-solid theme-primary">'.Html::e($this->mediaText('Speichergrenze speichern')).'</button></form></details>';
        return $html;
    }
    private function pager(array $list,bool $recoveries=false): string
    {
        if(!$list['has_more']&&$list['after']==='')return '';
        $html='<nav class="media-library-pager" aria-label="'.Html::e($this->mediaText($recoveries?'Fortsetzungen':'Dateigruppen')).'">';
        if($list['after']!=='')$html.='<a data-nav href="'.Html::e($recoveries?$this->recoveriesUrl(''):$this->url('')).'">'.Html::e($this->mediaText('Zum Anfang')).'</a>';
        if($list['has_more'])$html.='<a data-nav href="'.Html::e($recoveries?$this->recoveriesUrl($list['next_after']):$this->url($list['next_after'])).'">'.Html::e($this->mediaText($recoveries?'Nächste Fotos →':'Nächste Dateigruppen →')).'</a>';
        return $html.'</nav>';
    }
    private function url(string $after): string { return '/?'.http_build_query(['view'=>'media','section'=>'storage','storage_check'=>'1','storage_after'=>$after]); }
    private function bytes(int $bytes): string
    {
        if($bytes<1024)return $bytes.' B';if($bytes<1048576)return $this->mediaNumber($bytes/1024,1).' KiB';return $this->mediaNumber($bytes/1048576,1).' MiB';
    }
}
