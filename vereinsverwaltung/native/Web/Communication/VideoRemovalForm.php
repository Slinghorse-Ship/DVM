<?php declare(strict_types=1);
namespace Kulturhus\Native\Web\Communication;
use DomainException;
use PDO;
use Kulturhus\Native\Web\Html;
use Kulturhus\Native\Shared\{Access,Db};
use Kulturhus\Native\Language\{Catalog,VideoFileMessages};

/** One original file phase survives its own progressive-enhancement result window. */
final class VideoRemovalForm
{
    public const STATES=['active'=>'Zurückgeholt','removing'=>'Entfernen begonnen','removed'=>'Im Papierkorb','restoring'=>'Zurückholen begonnen','purging'=>'Löschen begonnen','purged'=>'Datei gelöscht'];
    private const ACTIONS=[
        'remove'=>['social-video-remove','Datei entfernen','Datei in den Papierkorb verschieben.','Datei entfernen vorbereiten'],
        'restore'=>['social-video-restore','Datei zurückholen','Ursprüngliche Datei zurückholen.','Datei zurückholen vorbereiten'],
        'purge'=>['social-video-purge','Datei endgültig löschen','Datei endgültig löschen. Dies kann nicht rückgängig gemacht werden.','Endgültiges Löschen vorbereiten'],
        'resume'=>['social-video-resume','Vorgang fortsetzen','','Fortsetzung vorbereiten'],
    ];
    public static function action(string $kind):string{if(!isset(self::ACTIONS[$kind]))throw new DomainException('Videozustand prüfen.');return self::ACTIONS[$kind][0];}
    public static function continuation(string $state,bool $prepare=false):string{return match($state){'removing'=>$prepare?'Fortsetzung des Entfernens vorbereiten':'Entfernen fortsetzen','restoring'=>$prepare?'Fortsetzung des Zurückholens vorbereiten':'Zurückholen fortsetzen','purging'=>$prepare?'Fortsetzung des endgültigen Löschens vorbereiten':'Endgültiges Löschen fortsetzen',default=>$prepare?'Fortsetzung vorbereiten':'Vorgang fortsetzen'};}
    public static function operation(mixed $op):bool{return is_string($op)&&in_array($op,array_column(self::ACTIONS,0),true);}
    public static function kind(string $op):string{$key=array_search($op,array_column(self::ACTIONS,0),true);if($key===false)throw new DomainException('Ursprünglichen Videodateivorgang erneut öffnen.');return array_keys(self::ACTIONS)[$key];}
    public static function restore(array $post):array
    {
        $s=['kind'=>self::kind(is_string($post['operation']??null)?$post['operation']:'')];
        foreach(['id','source_id','removal_operation']as$key){$s[$key]=$post[$key]??null;if(!is_string($s[$key])||!preg_match('/\A[a-f0-9]{32}\z/D',$s[$key]))throw new DomainException('Ursprünglichen Videodateivorgang erneut öffnen.');}
        foreach(['version','removal_version','video_page']as$key){$s[$key]=$post[$key]??null;if(!is_string($s[$key])||!preg_match('/\A(?:0|[1-9][0-9]{0,9})\z/D',$s[$key])||(int)$s[$key]>4294967295||($key==='version'&&(int)$s[$key]<1)||($key==='video_page'&&((int)$s[$key]<1||(int)$s[$key]>1000000)))throw new DomainException('Ursprünglichen Videodateivorgang erneut öffnen.');}
        if(isset($post['confirm_video'])&&$post['confirm_video']!=='1')throw new DomainException('Dateiänderung ausdrücklich bestätigen.');$s['confirm_video']=isset($post['confirm_video']);return$s;
    }
    public static function initial(array $item,array $row,array $current,int $page,string $kind):array
    {
        if(!isset(self::ACTIONS[$kind]))throw new DomainException('Videozustand prüfen.');return['id'=>$item['id'],'source_id'=>$row['source_id'],'version'=>(string)$item['version'],'removal_version'=>(string)$current['version'],'removal_operation'=>$kind==='remove'?bin2hex(random_bytes(16)):$current['operation'],'video_page'=>(string)$page,'kind'=>$kind,'confirm_video'=>false];
    }
    private static function time(string $utc,string $locale):string
    {
        if($utc==='')return'';$date=(new \DateTimeImmutable($utc,new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone('Europe/Berlin'));return'<time datetime="'.Html::e(str_replace(' ','T',$utc).'Z').'">'.Html::e($date->format(explode('-',$locale,2)[0]==='en'?'Y-m-d H:i':'d.m.Y H:i').' Europe/Berlin').'</time>';
    }
    public static function current(array $state,array $messages,string $locale):string
    {
        $t=static fn(string$s):string=>Html::e(VideoFileMessages::text($s,$messages));$h='<section class="video-file-current" data-file-current><h3>'.$t('Aktueller Dateizustand').'</h3><p>'.$t($state['version']===0&&$state['state']==='active'?'Datei verfügbar':self::STATES[$state['state']]).'</p>';
        if(($state['actor_name']??'')!=='')$h.='<p>'.Html::e($state['actor_name']).' · '.self::time($state['changed_at'],$locale).'</p>';
        if(($state['retain_until']??'')!==''&&$state['state']==='removed')$h.='<p>'.$t('Aufbewahrung bis').' '.self::time($state['retain_until'],$locale).'</p>';return$h.'</section>';
    }
    public static function render(array $item,array $row,array $current,string $csrf,array $messages,string $locale,array $state,bool $separate=true,bool $canManage=true):string
    {
        $t=static fn(string$s):string=>Html::e(VideoFileMessages::text($s,$messages));$kind=$state['kind'];$a=self::ACTIONS[$kind];$result=$state['result']??null;$found=($result['found']??false)===true;$complete=$found&&$result['complete'];$pending=$found&&!$complete;
        $h='<section class="video-file-form" data-video-file-operation data-file-current-snapshot="'.Html::e(json_encode($current,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)).'" data-file-can-manage="'.($canManage?'1':'0').'" data-file-interface="'.Html::e(json_encode(VideoFileMessages::browser($messages),JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)).'">';
        if(!$separate)$h.='<h2>'.$t('Video-Papierkorb').'</h2><p>'.Html::e($row['original_name']).'</p>';
        if(!empty($state['error']))$h.='<p role="alert">'.Html::e($state['error']).'</p>';
        if(!empty($state['prepared']))$h.='<p role="status">'.$t('Dateivorgang vorbereitet. Noch keine Datei geändert.').'</p>';
        if($found){$h.='<section class="video-file-original" data-file-result="'.Html::e(json_encode($result,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)).'"><h3>'.$t('Ursprünglicher Dateivorgang').'</h3><p role="status">'.$t($complete?'Vorgang abgeschlossen.':'Vorgang begonnen, noch nicht abgeschlossen.').'</p><p>'.$t(self::STATES[$result['state']]).' · '.Html::e($result['actor_name']).'</p><p>'.self::time($result['changed_at'],$locale).'</p></section>';if($complete&&$result['version']!==$current['version'])$h.='<p>'.$t('Der ursprüngliche Abschluss ist ein früheres Ergebnis. Der aktuelle Dateizustand wird getrennt angezeigt.').'</p>';}
        if(!$separate)$h.=self::current($current,$messages,$locale);
        $h.='<p>'.$t(!$canManage?'Du kannst deinen ursprünglichen Dateivorgang prüfen. Für Änderungen fehlt die Verwaltungsberechtigung.':($separate?'Ohne JavaScript öffnen Dateivorgang und Prüfung ein eigenes Fenster. Der ungespeicherte Beitrag bleibt im ursprünglichen Fenster.':'Deine ursprüngliche Dateiauswahl und Bestätigung bleiben erhalten.')).'</p><p>'.$t('Die Änderung betrifft alle Fassungen derselben Quelldatei.').'</p>';
        $h.='<form method="post" target="'.($separate?'_blank':'_self').'" data-video-file-form'.(!empty($state['prepared'])?' data-unsaved-changes':'').'>'.Html::hidden('csrf',$csrf).Html::hidden('operation',$a[0]).Html::hidden('file_window','1');foreach(['id','source_id','version','removal_version','removal_operation','video_page']as$key)$h.=Html::hidden($key,$state[$key]);
        $h.='<label class="check" data-file-confirm-wrap'.($kind==='resume'||$found?' hidden':'').'><input class="check" type="checkbox" name="confirm_video" value="1"'.($state['confirm_video']?' checked':'').(!$canManage?' disabled':'').($kind!=='resume'&&!$found?' required':'').'><span data-file-confirm-label>'.$t($a[2]).'</span></label><div class="actions"><button class="btn btn-outline '.($kind==='purge'?'theme-danger':'theme-secondary').'" type="submit" data-file-save'.(!$canManage||$complete?' hidden disabled':'').'>'.$t($pending?self::continuation($result['state']):($kind==='resume'?self::continuation($current['state']):$a[1])).'</button><button class="btn btn-outline theme-secondary" type="submit" name="file_review" value="1" formnovalidate data-file-review>'.$t('Vorgang prüfen').'</button><button class="btn btn-outline theme-secondary" type="button" data-file-retry hidden>'.$t('Ursprünglichen Dateivorgang erneut versuchen').'</button></div>';
        $h.='<div class="actions" data-file-prepare-actions';$canPrepare=$canManage&&($complete||$pending||!empty($state['prepared'])||$current['version']>(int)$state['removal_version']);$h.=(!$canPrepare?' hidden':'').'>';
        $allowed=match($current['state']){'active'=>['remove'],'removed'=>!empty($current['purge_eligible'])?['restore','purge']:['restore'],'removing','restoring','purging'=>['resume'],default=>[]};
        foreach($allowed as$next)$h.='<button class="btn btn-outline '.($next==='purge'?'theme-danger':'theme-secondary').'" type="submit" name="file_prepare" value="'.$next.'" formnovalidate data-file-prepare>'.$t($next==='resume'?self::continuation($current['state'],true):self::ACTIONS[$next][3]).'</button>';
        $h.='</div></form><p role="status" aria-live="polite" tabindex="-1" data-file-status>'.(is_array($result)&&!$found?$t('Für diese ursprüngliche Aktion wurde kein gespeicherter Vorgang gefunden.'):'').'</p>';
        $h.='<p data-file-continuation-note'.(!$pending&&(isset($state['result'])||empty($state['error']))?' hidden':'').'>'.$t('Ein begonnener Dateivorgang wird durch das Schließen nicht abgebrochen. Den ursprünglichen Vorgang prüfen.').'</p>';
        return$h.'<a class="btn btn-outline theme-secondary" data-save-original data-file-open-current target="_blank" rel="noopener" href="'.Html::e('/?'.http_build_query(['view'=>'social','section'=>'compose','item'=>$item['id'],'video_page'=>$state['video_page'],'video_archive'=>'1','video_lifecycle_source'=>$state['source_id']]).'#social-videos').'">'.$t('Beitrag mit aktuellem Dateizustand öffnen').'</a></section>';
    }
    public static function correction(PDO $db,Access $access,string $mediaRoot,array $post,string $error):array
    {
        $state=self::restore($post);$state['error']=$error;
        try{$original=(new \Kulturhus\Native\Communication\VideoRemovalRequests($db,$access,$mediaRoot))->review($state['id'],$state['source_id'],$state['kind'],(int)$state['removal_version'],$state['removal_operation']);$state['result']=$original?['found'=>true]+$original:['found'=>false];}catch(\DomainException|\Kulturhus\Native\Cms\Conflict){/* Original source and current reader remain authoritative. */}
        return$state;
    }
    public static function page(PDO $db,Access $access,string $mediaRoot,string $csrf,array $state):string
    {
        $actor=$access->require('social','read');$item=(new \Kulturhus\Native\Communication\Workspace($db,$access,'social',$mediaRoot))->get($state['id']);$row=Db::run($db,'SELECT v.*,s.original_name FROM native_social_videos v JOIN native_social_video_sources s ON s.club_id=v.club_id AND s.id=v.source_id WHERE v.club_id=? AND v.item_id=? AND v.source_id=? ORDER BY v.id LIMIT 1',[$actor['club_id'],$state['id'],$state['source_id']])->fetch();if(!$row)throw new DomainException('Eigene Videoquelle erneut öffnen.');
        $current=(new \Kulturhus\Native\Communication\VideoRemovalRequests($db,$access,$mediaRoot))->status($state['id'],$state['source_id']);$manage=true;try{$access->require('social','manage');}catch(DomainException){$manage=false;}if(!$manage)$current=array_intersect_key($current,array_flip(['version','state','actor_name','changed_at']));$catalog=new Catalog($db,$access);$h='<section class="panel">'.self::render($item,$row,$current,$csrf,$catalog->messages(),$catalog->locale(),$state,false,$manage).'</section>';
        $latest=$access->require('social','read');if($latest['id']!==$actor['id']||$latest['club_id']!==$actor['club_id'])throw new DomainException('Speicherzugang geändert.');return$h;
    }
}
