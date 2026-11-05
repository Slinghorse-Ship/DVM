<?php declare(strict_types=1);
namespace Kulturhus\Native\Web\Communication;
use DomainException;
use PDO;
use Kulturhus\Native\Web\Html;
use Kulturhus\Native\Shared\{Access,Db};
use Kulturhus\Native\Language\{Catalog,VideoCatalogueMessages};

/** The original archive context survives a separate NoJS result or correction window. */
final class VideoArchiveForm
{
    public static function restore(array $post):array
    {
        $s=[];foreach(['id','source_id','archive_request_key']as$key){$s[$key]=$post[$key]??null;if(!is_string($s[$key])||!preg_match('/\A[a-f0-9]{32}\z/D',$s[$key]))throw new DomainException('Ursprüngliches Videoarchivformular öffnen.');}
        foreach(['version','archive_version','video_page']as$key){$s[$key]=$post[$key]??null;if(!is_string($s[$key])||!preg_match('/\A(?:0|[1-9][0-9]{0,9})\z/D',$s[$key])||(int)$s[$key]>4294967295||($key==='version'&&(int)$s[$key]<1)||($key==='video_page'&&((int)$s[$key]<1||(int)$s[$key]>1000000)))throw new DomainException('Ursprüngliches Videoarchivformular öffnen.');}
        if(!in_array($post['archived']??null,['0','1'],true))throw new DomainException('Videoarchiv erneut öffnen.');$s['archived']=$post['archived'];return$s;
    }
    public static function render(array $item,array $row,array $page,string $csrf,array $messages,?array $state=null,bool $separate=true,bool $canEdit=true,string $locale='de'):string
    {
        $t=static fn(string$s):string=>Html::e(VideoCatalogueMessages::text($s,$messages));$restored=$state!==null;$state??=['id'=>$item['id'],'source_id'=>$row['source_id'],'version'=>(string)$item['version'],'archive_version'=>(string)$row['archive_version'],'archived'=>$row['archived']?'0':'1','archive_request_key'=>bin2hex(random_bytes(16)),'video_page'=>(string)$page['page']];
        $confirmed=($state['result']['found']??false)===true;$h='<section class="video-archive-form" data-video-archive data-archive-read-only="'.($canEdit?'0':'1').'" data-archive-interface="'.Html::e(json_encode(VideoCatalogueMessages::browser($messages),JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)).'">';
        if(!$separate)$h.='<h2>'.$t('Videoarchiv').'</h2><p>'.Html::e($row['original_name']).'</p>';
        if(!empty($state['error']))$h.='<p role="alert">'.Html::e($state['error']).'</p>';
        if(!empty($state['prepared']))$h.='<p role="status">'.$t('Andere Archivwahl vorbereitet. Noch nicht gespeichert.').'</p>';
        if($confirmed){$result=$state['result'];$h.='<section class="video-archive-saved" data-archive-confirmed="'.Html::e(json_encode($result,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)).'"><h3>'.$t('Gespeicherte Archivwahl').'</h3><p>'.$t($result['archived']?'Archiviert':'Zurückgeholt').' · '.Html::e($result['actor_name']).'</p><p><time datetime="'.Html::e(str_replace(' ','T',$result['changed_at']).'Z').'">'.Html::e((new \DateTimeImmutable($result['changed_at'],new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone('Europe/Berlin'))->format(explode('-',$locale,2)[0]==='en'?'Y-m-d H:i':'d.m.Y H:i').' Europe/Berlin').'</time></p><p>'.$t('Archivversion').': '.Html::e($result['version']).'</p></section><p>'.$t('Die gespeicherte Archivwahl ist bestätigt. Eine andere Wahl muss bewusst vorbereitet und gespeichert werden.').'</p>';}
        $h.='<p>'.$t(!$canEdit?'Du kannst deine ursprüngliche Archivwahl prüfen. Für Änderungen fehlt die Bearbeitungsberechtigung.':($separate?'Ohne JavaScript öffnen Speicherung und Prüfung ein eigenes Fenster. Der ungespeicherte Beitrag bleibt im ursprünglichen Fenster.':'Die ursprüngliche Archivwahl bleibt erhalten.')).'</p>';
        $h.='<form method="post" target="'.($separate?'_blank':'_self').'" data-video-archive-form'.($restored&&empty($state['saved_now'])?' data-unsaved-changes':'').'>'.Html::hidden('csrf',$csrf).Html::hidden('operation','social-video-archive').Html::hidden('archive_window','1');foreach(['id','source_id','version','archive_version','archived','archive_request_key','video_page']as$key)$h.=Html::hidden($key,$state[$key]);
        $h.='<div class="actions"><button class="btn btn-outline theme-secondary" data-archive-save'.(!$canEdit||$confirmed?' hidden disabled':'').'>'.$t($state['archived']==='1'?'Video archivieren':'Video zurückholen').'</button><button class="btn btn-outline theme-secondary" name="archive_review" value="1" formnovalidate data-archive-review>'.$t('Gespeicherte Archivwahl prüfen').'</button><button class="btn btn-outline theme-secondary" type="button" data-archive-retry hidden>'.$t('Ursprüngliche Archivwahl erneut speichern').'</button><button class="btn btn-outline theme-secondary" name="archive_next" value="1" formnovalidate data-archive-next'.(!$confirmed||!$canEdit?' hidden':'').'>'.$t('Andere Archivwahl vorbereiten').'</button></div></form>';
        $h.='<p role="status" aria-live="polite" tabindex="-1" data-archive-status>'.(is_array($state['result']??null)&&!$confirmed?$t('Keine gespeicherte Archivwahl für diesen Vorgang gefunden. Unverändert erneut versuchen.'):'').'</p>';
        return$h.'<a class="btn btn-outline theme-secondary" data-save-original data-archive-current target="_blank" rel="noopener" href="'.Html::e('/?'.http_build_query(['view'=>'social','section'=>'compose','item'=>$item['id'],'video_page'=>$state['video_page'],'video_archive'=>$confirmed?($state['result']['archived']?'1':'0'):($state['archived']==='1'?'0':'1'),'video_archive_source'=>$state['source_id']]).'#social-videos').'">'.$t('Beitrag mit aktueller Videoauswahl öffnen').'</a></section>';
    }
    public static function page(PDO $db,Access $access,string $mediaRoot,string $csrf,array $state):string
    {
        $actor=$access->require('social','read');$item=(new \Kulturhus\Native\Communication\Workspace($db,$access,'social',$mediaRoot))->get($state['id']);$row=Db::run($db,'SELECT v.*,s.original_name FROM native_social_videos v JOIN native_social_video_sources s ON s.club_id=v.club_id AND s.id=v.source_id WHERE v.club_id=? AND v.item_id=? AND v.source_id=? ORDER BY v.id LIMIT 1',[$actor['club_id'],$state['id'],$state['source_id']])->fetch();if(!$row)throw new DomainException('Eigene Videoquelle erneut öffnen.');$canEdit=true;try{$access->require('social','edit');}catch(DomainException){$canEdit=false;}$catalog=new Catalog($db,$access);$messages=$catalog->messages();
        $h='<section class="panel">'.self::render($item,$row,['page'=>(int)$state['video_page']],$csrf,$messages,$state,false,$canEdit,$catalog->locale()).'</section>';$current=$access->require('social','read');if($current['id']!==$actor['id']||$current['club_id']!==$actor['club_id'])throw new DomainException('Speicherzugang geändert.');return$h;
    }
}
