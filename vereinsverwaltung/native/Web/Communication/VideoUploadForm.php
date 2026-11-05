<?php declare(strict_types=1);
namespace Kulturhus\Native\Web\Communication;
use DomainException;
use PDO;
use Kulturhus\Native\Web\Html;
use Kulturhus\Native\Shared\Access;
use Kulturhus\Native\Language\{Catalog,VideoStudioMessages};

/** A real ready-MP4 upload also works in a separate HTML window without losing the parent draft. */
final class VideoUploadForm
{
    public static function restore(array $post):array
    {
        $out=[];foreach(['id'=>32,'version'=>10,'identity_version'=>10,'video_request_key'=>32,'original_name'=>1020]as$key=>$max){$value=$post[$key]??'';if(!is_string($value)||strlen($value)>$max||preg_match('//u',$value)!==1||preg_match('/[\x00-\x1F\x7F]/',$value))throw new DomainException('Ursprüngliches Videoformular öffnen.');$out[$key]=$value;}
        if(!preg_match('/\A[a-f0-9]{32}\z/D',$out['id'])||!preg_match('/\A[1-9][0-9]{0,9}\z/D',$out['version'])||!preg_match('/\A(?:0|[1-9][0-9]{0,9})\z/D',$out['identity_version'])||!preg_match('/\A[a-f0-9]{32}\z/D',$out['video_request_key'])||mb_strlen($out['original_name'])>255)throw new DomainException('Ursprüngliches Videoformular öffnen.');
        if((int)$out['version']>4294967295||(int)$out['identity_version']>4294967295)throw new DomainException('Ursprüngliches Videoformular öffnen.');return$out;
    }
    public static function interfaceJson(callable $text):string
    {
        $out=[];foreach(VideoStudioMessages::ENGLISH as$source=>$translation)$out[$source]=$text($source);return Html::e(json_encode($out,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));
    }
    public static function correction(array $post,array $files,string $error):array
    {
        if(is_array($files['video_file']??null)&&is_string($files['video_file']['name']??null)&&$files['video_file']['name']!=='')$post['original_name']=basename(str_replace('\\','/',$files['video_file']['name']));
        $state=self::restore($post);$state['error']=$error;return$state;
    }
    public static function render(array $item,array $identity,string $csrf,callable $text,?array $state=null,bool $separate=true):string
    {
        $state??=['id'=>$item['id'],'version'=>(string)$item['version'],'identity_version'=>(string)$identity['version'],'video_request_key'=>bin2hex(random_bytes(16)),'original_name'=>''];$t=static fn(string$s):string=>Html::e($text($s));
        $readOnly=!empty($state['read_only']);$h='<section class="video-upload" data-video-upload data-video-read-only="'.($readOnly?'1':'0').'" data-video-interface="'.self::interfaceJson($text).'"><h3>'.$t('Fertige MP4 direkt speichern').'</h3>';
        if(($state['error']??'')!=='')$h.='<p role="alert">'.Html::e($state['error']).'</p>';
        $result=$state['result']??null;$postUrl='/?'.http_build_query(['view'=>'social','section'=>'compose','item'=>$item['id']]);
        if($result&&$result['found']){
            $h.='<p role="status" data-video-upload-status>'.$t('Video gespeichert. Beitrag und Bearbeitung bleiben erhalten.').'</p><p>'.Html::e($result['name']).'</p><video controls playsinline preload="metadata" class="video-saved-player" src="'.Html::e($result['original_url']).'" aria-label="'.$t('Gespeicherte Videofassung öffnen').'"></video><p><a class="btn btn-outline theme-secondary" data-save-original target="_blank" rel="noopener" href="'.Html::e($result['original_url']).'">'.$t('Gespeicherte Videofassung öffnen').'</a></p><p>'.$t('Dieses Fenster kann geschlossen werden. Der ursprüngliche Beitrag bleibt geöffnet.').'</p><a class="btn btn-outline theme-secondary" data-save-original target="_blank" rel="noopener" href="'.Html::e($postUrl).'">'.$t('Beitrag mit aktueller Videoauswahl öffnen').'</a>';
        }else{
            $h.='<p>'.$t($readOnly?'Du kannst den gespeicherten Vorgang prüfen. Für eine neue Videofassung fehlt die Bearbeitungsberechtigung.':($separate?'Ohne JavaScript öffnet die Speicherung ein eigenes Fenster. Ungespeicherte Beitragstexte und Bearbeitungen bleiben im ursprünglichen Fenster.':'Datei erneut auswählen. Ursprüngliche Fassung und Vorgangskennung bleiben erhalten.')).'</p><p>'.$t('MP4 mit H.264, 1080 × 1920 Pixeln, 30 Bildern/s und 3–90 Sekunden. Ton optional als AAC-Stereo mit 48 kHz.').'</p>';
            if($state['original_name']!=='')$h.='<p>'.Html::e(VideoStudioMessages::text('Ursprüngliche Datei: {name}',[VideoStudioMessages::key('Ursprüngliche Datei: {name}')=>$text('Ursprüngliche Datei: {name}')],['name'=>$state['original_name']])).'</p>';
            if(is_array($result))$h.='<p role="status">'.$t('Kein gespeichertes Video gefunden. Ursprünglichen Upload prüfen oder unverändert erneut versuchen.').'</p>';
            $h.='<form method="post" enctype="multipart/form-data" target="'.($separate?'_blank':'_self').'" data-native-video-form>'.Html::hidden('csrf',$csrf).Html::hidden('operation','social-video-save').Html::hidden('video_window','1').Html::hidden('id',$state['id']).Html::hidden('version',$state['version']).Html::hidden('identity_version',$state['identity_version']).Html::hidden('video_request_key',$state['video_request_key']).Html::hidden('original_name',$state['original_name']);
            $h.='<label'.($readOnly?' hidden':'').'>'.$t('Fertige MP4 auswählen (bis 100 MiB)').'<input class="form-control" type="file" name="video_file" accept="video/mp4" data-native-video-file required'.($readOnly?' disabled':'').'></label><div class="actions"><button type="submit" class="btn btn-solid theme-primary" data-video-upload-save'.($readOnly?' hidden disabled':'').'>'.$t('MP4 als private Videofassung speichern').'</button><button type="submit" class="btn btn-outline theme-secondary" name="video_review" value="1" formnovalidate formenctype="application/x-www-form-urlencoded" data-video-upload-review>'.$t('Gespeichertes Video prüfen').'</button><button type="button" class="btn btn-outline theme-secondary" data-video-upload-retry hidden>'.$t('Ursprüngliche Videofassung erneut speichern').'</button></div></form>';
            $h.='<p role="status" aria-live="polite" tabindex="-1" data-video-upload-status></p><video controls playsinline preload="metadata" class="video-saved-player" data-video-saved-player hidden aria-label="'.$t('Gespeicherte Videofassung öffnen').'"></video><p><a class="btn btn-outline theme-secondary" data-video-upload-original data-save-original target="_blank" rel="noopener" hidden>'.$t('Gespeicherte Videofassung öffnen').'</a></p><a class="btn btn-outline theme-secondary" data-video-upload-open-post data-save-original target="_blank" rel="noopener" href="'.Html::e($postUrl).'"'.((($state['error']??'')!==''||is_array($result))?'':' hidden').'>'.$t('Beitrag mit aktueller Videoauswahl öffnen').'</a>';
        }
        return$h.'</section>';
    }
    public static function page(PDO $db,Access $access,string $mediaRoot,string $csrf,array $state):string
    {
        $actor=$access->require('social','read');$item=(new \Kulturhus\Native\Communication\Workspace($db,$access,'social',$mediaRoot))->get($state['id']);$identity=\Kulturhus\Native\Shared\Identity::forClub($db,$actor['club_id']);$messages=(new Catalog($db,$access))->messages();try{$access->require('social','edit');$state['read_only']=false;}catch(DomainException){$state['read_only']=true;}$html='<section class="panel">'.self::render($item,$identity,$csrf,static fn(string$s):string=>VideoStudioMessages::text($s,$messages),$state,false).'</section>';$current=$access->require('social','read');if($current['id']!==$actor['id']||$current['club_id']!==$actor['club_id'])throw new DomainException('Speicherzugang geändert.');return$html;
    }
}
