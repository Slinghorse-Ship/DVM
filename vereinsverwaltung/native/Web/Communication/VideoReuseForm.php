<?php declare(strict_types=1);
namespace Kulturhus\Native\Web\Communication;
use DomainException;
use PDO;
use Kulturhus\Native\Shared\{Access,Db};
use Kulturhus\Native\Language\{Catalog,VideoReuseMessages};
use Kulturhus\Native\Web\Html;

/** Own source selection remains independent of unsaved editorial input. */
final class VideoReuseForm
{
    public static function restore(array $post):array
    {
        $state=[];foreach(['id','reuse_video_id','source_id','version','identity_version','video_use_query','video_use_page']as$key){$value=$post[$key]??null;if(!is_string($value))throw new DomainException('Ursprüngliches Videoauswahlformular öffnen.');$state[$key]=$value;}
        foreach(['id','reuse_video_id','source_id']as$key)\Kulturhus\Native\Communication\Content::id($state[$key]);
        foreach(['version','identity_version']as$key)if(!preg_match($key==='version'?'/\A[1-9][0-9]{0,9}\z/D':'/\A(?:0|[1-9][0-9]{0,9})\z/D',$state[$key])||(int)$state[$key]>4294967295)throw new DomainException('Aktuellen Inhaltsstand öffnen.');
        if(!preg_match('/\A[1-9][0-9]{0,6}\z/D',$state['video_use_page'])||(int)$state['video_use_page']>1000000)throw new DomainException('Videoauswahlseite prüfen.');
        if(strlen($state['video_use_query'])>2048||preg_match('//u',$state['video_use_query'])!==1||preg_match('/[\x00-\x1F\x7F]/',$state['video_use_query']))throw new DomainException('Videoauswahl erneut öffnen.');return$state;
    }
    public static function initial(array $item,array $row,int $identity,string $query,int $page):array
    {return['id'=>$item['id'],'reuse_video_id'=>$row['video_id'],'source_id'=>$row['source_id'],'version'=>(string)$item['version'],'identity_version'=>(string)$identity,'video_use_query'=>$query,'video_use_page'=>(string)$page,'original_name'=>$row['original_name']];}
    public static function render(array $state,string $csrf,array $messages,string $locale,bool $separate=true,bool $canEdit=true,bool $compact=false):string
    {
        $t=static fn(string$s,array$v=[]):string=>Html::e(VideoReuseMessages::text($s,$messages,$v));$result=$state['result']??null;$found=($result['found']??false)===true;
        $h='<section class="video-reuse-form" data-video-reuse-request data-reuse-interface="'.Html::e(json_encode(VideoReuseMessages::browser($messages),JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)).'" data-reuse-can-edit="'.($canEdit?'1':'0').'" data-video-reuse-state="'.($found?'saved':'new').'">'.($compact?'':'<h2>'.$t('Vorhandenes Video übernehmen').'</h2><p data-reuse-source-name data-original-content>'.Html::e($state['original_name']??'').'</p><p>'.$t('Verwendet dieselbe Videodatei. Andere Beiträge behalten ihre eigene Freigabe.').'</p>');
        if(!empty($state['error']))$h.='<p role="alert">'.Html::e($state['error']).'</p>';
        if(!empty($state['prepared']))$h.='<p role="status">'.$t('Übernahme zum aktuellen Beitragsstand vorbereitet. Noch keine Fassung übernommen.').'</p>';
        if(!$canEdit&&!$found)$h.='<p>'.$t('Für eine neue Übernahme werden ein bearbeitbarer Reel-Entwurf und aktuelle Bearbeitungsrechte benötigt.').'</p>';
        if($found)$h.='<section class="video-reuse-original" data-reuse-result="'.Html::e(json_encode($result,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)).'"><h3>'.$t('Ursprüngliche Videoübernahme').'</h3><p role="status">'.$t('Videofassung übernommen.').'</p><p data-original-content>'.Html::e($result['original_name']).'</p><p>'.$t('Gespeicherte Beitragsfassung {version}',['version'=>$result['item_version']]).' · '.VideoRecoveryForm::time($result['created_at'],$locale).'</p><p data-video-reuse-file-state="'.Html::e($result['file_state']).'">'.$t(VideoRecoveryForm::fileText($result['file_state'],$result['archived'])).'</p></section>';
        $h.='<p>'.$t($separate?'Ohne JavaScript öffnen Übernahme und Prüfung ein eigenes Fenster. Der ungespeicherte Beitrag bleibt im ursprünglichen Fenster.':'Deine ursprüngliche Videoauswahl und Beitragsfassung bleiben erhalten.').'</p><form method="post" target="'.($separate?'_blank':'_self').'" data-video-reuse-form'.(!empty($state['prepared'])?' data-unsaved-changes':'').'>'.Html::hidden('csrf',$csrf).Html::hidden('operation','social-video-reuse').Html::hidden('reuse_window','1');
        foreach(['id','reuse_video_id','source_id','version','identity_version','video_use_query','video_use_page']as$key)$h.=Html::hidden($key,$state[$key]);
        $h.='<div class="actions"><button type="submit" class="btn btn-solid theme-primary" data-reuse-save'.(!$canEdit||$found?' hidden disabled':'').'>'.$t('Diese Fassung nutzen').'</button><button type="submit" name="reuse_review" value="1" formnovalidate class="btn btn-outline theme-secondary" data-reuse-review>'.$t('Ursprüngliche Videoübernahme prüfen').'</button><button type="button" class="btn btn-outline theme-secondary" data-reuse-retry hidden>'.$t('Ursprüngliche Videoübernahme erneut versuchen').'</button><button type="submit" name="reuse_prepare" value="1" formnovalidate class="btn btn-outline theme-secondary" data-reuse-prepare'.(!$canEdit||$found?' hidden disabled':'').'>'.$t('Übernahme zum aktuellen Beitragsstand vorbereiten').'</button></div></form><p role="status" aria-live="polite" tabindex="-1" data-reuse-status>'.(is_array($result)&&!$found?$t('Übernahme derzeit nicht bestätigt. Ursprüngliche Auswahl behalten.'):'').'</p>';
        $query=['view'=>'social','section'=>'compose','item'=>$state['id'],'video_page'=>1];if($found&&$result['archived']){$query['video_archive']='1';$query['video_lifecycle_source']=$result['source_id'];}
        return$h.'<a class="btn btn-outline theme-secondary" data-save-original data-reuse-open'.($compact&&!$found?' hidden':'').' target="_blank" rel="noopener" href="'.Html::e('/?'.http_build_query($query).'#social-videos').'">'.$t($found?($result['archived']?'Archiv öffnen':'Gespeicherte Videos'):'Beitrag mit aktuellen Videofassungen öffnen').'</a><a class="btn btn-outline theme-secondary" data-save-original data-reuse-open-original target="_blank" rel="noopener" href="'.Html::e('/?'.http_build_query(VideoReusePanel::original($state)).'#social-video-reuse').'"'.($compact&&!$found?' hidden':'').'>'.$t('Ursprüngliche Videoübernahme öffnen').'</a></section>';
    }
    public static function canEdit(PDO $db,Access $access,array $post,string $mediaRoot):bool
    {try{$actor=$access->require('social','edit');$item=(new \Kulturhus\Native\Communication\Workspace($db,$access,'social',$mediaRoot))->get(is_string($post['id']??null)?$post['id']:'');foreach(['read','edit']as$cap){$now=$access->require('social',$cap);if($now['id']!==$actor['id']||$now['club_id']!==$actor['club_id'])return false;}return$item['kind']==='reel'&&in_array($item['status'],['draft','review'],true);}catch(\Throwable){return false;}}
    public static function correction(PDO $db,Access $access,string $mediaRoot,array $post,string $error):array
    {$state=self::restore($post);$state['error']=$error;try{$state['result']=(new \Kulturhus\Native\Communication\VideoReuseRequests($db,$access,$mediaRoot))->review($state['id'],$state['reuse_video_id'],$state['source_id'],(int)$state['version'],(int)$state['identity_version']);}catch(DomainException|\Kulturhus\Native\Cms\Conflict){}return$state;}
    public static function page(PDO $db,Access $access,string $mediaRoot,string $csrf,array $state):string
    {
        $actor=$access->require('social','edit');$reader=$access->require('social','read');if($reader['id']!==$actor['id']||$reader['club_id']!==$actor['club_id'])throw new DomainException('Person oder Vereinszuordnung geändert.');
        $parent=(new \Kulturhus\Native\Communication\Workspace($db,$access,'social',$mediaRoot))->get($state['id']);$source=Db::run($db,'SELECT s.original_name FROM native_social_videos v JOIN native_social_video_sources s ON s.club_id=v.club_id AND s.id=v.source_id WHERE v.club_id=? AND v.id=? AND v.source_id=?',[$actor['club_id'],$state['reuse_video_id'],$state['source_id']])->fetch();if(!$source)throw new DomainException('Eigene ursprüngliche Videofassung auswählen.');$state['original_name']=$source['original_name'];$catalog=new Catalog($db,$access);
        $h='<section class="panel">'.self::render($state,$csrf,$catalog->messages(),$catalog->locale(),false,$parent['kind']==='reel'&&in_array($parent['status'],['draft','review'],true)).'</section>';
        foreach(['edit','read']as$cap){$now=$access->require('social',$cap);if($now['id']!==$actor['id']||$now['club_id']!==$actor['club_id'])throw new DomainException('Person oder Vereinszuordnung geändert.');}return$h;
    }
}
