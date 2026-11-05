<?php declare(strict_types=1);
namespace Kulturhus\Native\Web\Communication;
use DomainException;
use PDO;
use Kulturhus\Native\Web\Html;
use Kulturhus\Native\Shared\{Access,Db};
use Kulturhus\Native\Language\{Catalog,VideoRecoveryMessages};
/** The original source plan and the current editor revision stay separate. */
final class VideoRecoveryForm
{
    public static function restore(array $post):array
    {
        $state=[];foreach(['id','source_id','source_fingerprint','recovery_name','version','identity_version','video_recovery_after']as$key){$value=$post[$key]??($key==='video_recovery_after'?'':null);if(!is_string($value))throw new DomainException('Ursprüngliches Wiederherstellungsformular erneut öffnen.');$state[$key]=$value;}
        foreach(['id','source_id']as$key)\Kulturhus\Native\Communication\Content::id($state[$key]);if($state['video_recovery_after']!=='')\Kulturhus\Native\Communication\Content::id($state['video_recovery_after']);
        foreach(['version','identity_version']as$key)if(!preg_match($key==='version'?'/\A[1-9][0-9]{0,9}\z/D':'/\A(?:0|[1-9][0-9]{0,9})\z/D',$state[$key])||(int)$state[$key]>4294967295)throw new DomainException('Aktuellen Inhaltsstand öffnen.');
        if(!preg_match('/\A[a-f0-9]{64}\z/D',$state['source_fingerprint']))throw new DomainException('Videodatei erneut prüfen.');if(strlen($state['recovery_name'])>2048||preg_match('//u',$state['recovery_name'])!==1||preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/',$state['recovery_name']))throw new DomainException('Ursprüngliches Wiederherstellungsformular erneut öffnen.');return$state;
    }
    public static function initial(array $item,array $identity,array $plan,string $after):array
    {return['id'=>$item['id'],'source_id'=>$plan['original_id'],'source_fingerprint'=>$plan['fingerprint'],'recovery_name'=>$plan['original_name']??'Reel.mp4','version'=>(string)$item['version'],'identity_version'=>(string)$identity['version'],'video_recovery_after'=>$after];}
    public static function render(array $state,string $csrf,array $messages,string $locale,bool $separate=true,bool $canManage=true,bool $canEdit=true):string
    {
        $t=static fn(string$s,array$v=[]):string=>Html::e(VideoRecoveryMessages::text($s,$messages,$v));$result=$state['result']??null;$found=($result['found']??false)===true;$complete=$found&&$result['state']==='complete';$pending=$found&&!$complete;$nameLocked=$pending||$complete||($state['source_state']??null)==='pending';
        $h='<section class="video-recovery-form" data-video-recovery-state="'.Html::e($found?$result['state']:'new').'" data-video-recovery-request data-recovery-name-locked="'.($nameLocked?'1':'0').'" data-recovery-interface="'.Html::e(json_encode(VideoRecoveryMessages::browser($messages),JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)).'" data-recovery-can-manage="'.($canManage?'1':'0').'" data-recovery-can-edit="'.($canEdit?'1':'0').'"><h2>'.$t('Video wiederherstellen').'</h2>';
        if(!empty($state['error']))$h.='<p role="alert">'.Html::e($state['error']).'</p>';
        if(!empty($state['prepared']))$h.='<p role="status">'.$t('Fortsetzung zum aktuellen Beitragsstand vorbereitet. Die Vorbereitung ändert keine Datei.').'</p>';
        $h.='<p>'.$t($separate?'Ohne JavaScript öffnen Wiederherstellung und Prüfung ein eigenes Fenster. Der ungespeicherte Beitrag bleibt im ursprünglichen Fenster.':'Deine ursprüngliche Dateiauswahl und dein Videoname bleiben erhalten.').'</p>';
        if(!$canEdit&&!$complete)$h.='<p>'.$t('Für eine neue Kopie oder Fortsetzung werden ein bearbeitbarer Reel-Entwurf und aktuelle Bearbeitungsrechte benötigt.').'</p>';
        if($found){$h.='<section class="video-recovery-original" data-recovery-result="'.Html::e(json_encode($result,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)).'"><h3>'.$t('Ursprüngliche Wiederherstellung').'</h3><p role="status">'.$t($complete?'Videofassung gespeichert.':'Wiederherstellung begonnen, noch nicht abgeschlossen.').'</p><p>'.Html::e($result['original_name']).'</p><p>'.$t('Begonnen am').' '.self::time($result['created_at'],$locale).'</p>';
            if($complete)$h.='<p>'.$t('Gespeicherte Beitragsfassung {version}',['version'=>$result['item_version']]).' · '.self::time($result['completed_at'],$locale).'</p><p data-video-recovery-file-state="'.Html::e($result['file_state']).'">'.$t(self::fileText($result['file_state'],$result['archived'])).'</p>';$h.='</section>';
        }
        $h.='<form method="post" target="'.($separate?'_blank':'_self').'" data-video-recovery-form data-video-recovery-original-form'.(!empty($state['prepared'])?' data-unsaved-changes':'').'>'.Html::hidden('csrf',$csrf).Html::hidden('operation','social-video-recover').Html::hidden('recovery_window','1');foreach(['id','source_id','source_fingerprint','version','identity_version','video_recovery_after']as$key)$h.=Html::hidden($key,$state[$key]);
        $h.='<label>'.$t('Videoname').'<input class="form-control" type="text" name="recovery_name" maxlength="255" required value="'.Html::e($state['recovery_name']).'"'.($nameLocked||!$canEdit?' readonly':'').'></label><div class="actions"><button type="submit" class="btn btn-solid theme-primary" data-recovery-save'.(!$canManage||!$canEdit||$complete?' hidden disabled':'').'>'.$t($pending?'Wiederherstellung fortsetzen':'Private Videofassung speichern').'</button><button type="submit" name="recovery_review" value="1" formnovalidate class="btn btn-outline theme-secondary" data-recovery-review>'.$t('Ursprüngliche Wiederherstellung prüfen').'</button><button type="button" class="btn btn-outline theme-secondary" data-recovery-retry hidden>'.$t('Ursprüngliche Wiederherstellung erneut versuchen').'</button><button type="submit" name="recovery_prepare" value="1" formnovalidate class="btn btn-outline theme-secondary" data-recovery-prepare'.(!$canManage||!$canEdit||$complete?' hidden disabled':'').'>'.$t('Fortsetzung zum aktuellen Beitragsstand vorbereiten').'</button></div></form><p role="status" aria-live="polite" tabindex="-1" data-recovery-status>'.(is_array($result)&&!$found?$t('Keine gespeicherte Wiederherstellung für diese ursprüngliche Auswahl gefunden.'):'').'</p>';
        $query=['view'=>'social','section'=>'compose','item'=>$state['id'],'video_page'=>1];if($complete&&$result['archived']){$query['video_archive']='1';$query['video_lifecycle_source']=$result['target_id'];}
        $label=$complete?($result['archived']?'Archiv öffnen':'Gespeicherte Videos'):'Beitrag mit aktuellen Videofassungen öffnen';
        $h.='<p data-recovery-continuation-note'.($complete||!$pending&&empty($state['error'])?' hidden':'').'>'.$t('Eine begonnene Wiederherstellung wird durch das Schließen nicht abgebrochen. Ursprünglichen Vorgang prüfen.').'</p><a class="btn btn-outline theme-secondary" data-save-original data-recovery-open target="_blank" rel="noopener" href="'.Html::e('/?'.http_build_query($query).'#social-videos').'">'.$t($label).'</a>';

        $h.='<a class="btn btn-outline theme-secondary" data-save-original data-recovery-open-original target="_blank" rel="noopener" href="'.Html::e('/?'.http_build_query(VideoRecoveryPanel::query($state['id'],$state['source_id'],$state['video_recovery_after'])).'#social-video-recovery').'">'.$t('Ursprünglichen Wiederherstellungsvorgang öffnen').'</a>';
        return$h.'</section>';
    }
    public static function fileText(string $state,bool $archived):string
    {return match($state){'removed'=>'Videodatei im Papierkorb.','purged'=>'Videodatei endgültig gelöscht.','removing','restoring','purging'=>'Dateivorgang noch offen.',default=>$archived?'Videofassung im Archiv.':'Videodatei verfügbar.'};}
    public static function time(string $utc,string $locale):string
    {$date=new \DateTimeImmutable($utc,new \DateTimeZone('UTC'));return'<time datetime="'.Html::e(str_replace(' ','T',$utc).'Z').'">'.Html::e($date->setTimezone(new \DateTimeZone('Europe/Berlin'))->format(explode('-',$locale,2)[0]==='en'?'Y-m-d H:i':'d.m.Y H:i').' Europe/Berlin').'</time>';}
    public static function correction(PDO $db,Access $access,string $mediaRoot,array $post,string $error):array
    {
        $state=self::restore($post);$state['error']=$error;try{$state['result']=(new \Kulturhus\Native\Communication\VideoRecoveryRequests($db,$access,$mediaRoot))->review($state['id'],$state['source_id'],$state['source_fingerprint'],$state['recovery_name']);}catch(DomainException|\Kulturhus\Native\Cms\Conflict){}return$state;
    }
    public static function page(PDO $db,Access $access,string $mediaRoot,string $csrf,array $state):string
    {
        $actor=$access->require('social','manage');$parent=(new \Kulturhus\Native\Communication\Workspace($db,$access,'social',$mediaRoot))->get($state['id']);$canEdit=$parent['kind']==='reel'&&in_array($parent['status'],['draft','review'],true);try{$access->require('social','edit');}catch(DomainException){$canEdit=false;}$catalog=new Catalog($db,$access);$h='<section class="panel">'.self::render($state,$csrf,$catalog->messages(),$catalog->locale(),false,true,$canEdit).'</section>';$current=$access->require('social','manage');$reader=$access->require('social','read');if($current['id']!==$actor['id']||$current['club_id']!==$actor['club_id']||$reader['id']!==$actor['id']||$reader['club_id']!==$actor['club_id'])throw new DomainException('Speicherzugang geändert.');return$h;
    }
}
