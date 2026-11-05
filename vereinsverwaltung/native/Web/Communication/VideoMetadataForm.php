<?php declare(strict_types=1);
namespace Kulturhus\Native\Web\Communication;
use DomainException;
use PDO;
use Kulturhus\Native\Web\Html;
use Kulturhus\Native\Shared\{Access,Db,MediaDescription};
use Kulturhus\Native\Language\{Catalog,VideoMetadataMessages};

/** Only newly posted private text can be restored; stored proof is never loaded into a form. */
final class VideoMetadataForm
{
    private const FIELDS=['alt'=>500,'credit'=>500,'source_name'=>500,'source_url'=>2048,'license_name'=>200,'license_url'=>2048,'rights_state'=>40,'consent_state'=>40,'expires_on'=>10];
    private static function text(mixed $value,int $max,bool $multiline=false):string
    {
        if(!is_string($value)||preg_match('//u',$value)!==1||mb_strlen($value)>$max||preg_match($multiline?'/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/':'/[\x00-\x1f\x7f]/',$value))throw new DomainException('Ursprüngliches Videoangabenformular öffnen.');return$value;
    }
    public static function restore(array $post):array
    {
        $state=[];foreach(['id','source_id','metadata_request_key']as$key){$state[$key]=self::text($post[$key]??'',32);if(!preg_match('/\A[a-f0-9]{32}\z/D',$state[$key]))throw new DomainException('Ursprüngliches Videoangabenformular öffnen.');}
        foreach(['version','metadata_version']as$key){$state[$key]=self::text($post[$key]??'',10);if(!preg_match('/\A(?:0|[1-9][0-9]{0,9})\z/D',$state[$key])||(int)$state[$key]>4294967295||($key==='version'&&(int)$state[$key]<1))throw new DomainException('Ursprüngliches Videoangabenformular öffnen.');}
        if(!is_array($post['metadata']??null))throw new DomainException('Videoangaben erneut öffnen.');$state['metadata']=[];foreach(self::FIELDS as$key=>$max)$state['metadata'][$key]=self::text($post['metadata'][$key]??'',$max);
        $channels=$post['metadata']['channels']??[];if(!is_array($channels)||!array_is_list($channels)||count($channels)>4)throw new DomainException('Erlaubte Bereiche als Auswahl angeben.');$state['metadata']['channels']=array_map(static fn($value):string=>self::text($value,40),$channels);
        if(array_key_exists('replace_proof',$post)&&$post['replace_proof']!=='1')throw new DomainException('Privaten Nachweis als Text angeben.');$state['replace_proof']=isset($post['replace_proof']);$state['private_proof']=self::text($post['private_proof']??'',4000,true);return$state;
    }
    private static function options(array $options,string $selected,callable $text):array
    {
        $out=array_map($text,$options);if(!array_key_exists($selected,$out))$out[$selected]=strtr($text('Ungültige Auswahl: {value}'),['{value}'=>$selected]);return$out;
    }
    public static function values(array $metadata,callable $text):string
    {
        $h='<dl class="video-metadata-values">';foreach(['alt'=>'Beschreibung','credit'=>'Credit','source_name'=>'Quelle','source_url'=>'Quellenlink','license_name'=>'Lizenz','license_url'=>'Lizenzlink']as$key=>$label)if(($metadata[$key]??'')!=='')$h.='<dt>'.Html::e($text($label)).'</dt><dd>'.Html::e($metadata[$key]).'</dd>';
        foreach(['rights_state'=>['Nutzungsrechte',MediaDescription::RIGHTS],'consent_state'=>['Einwilligung',MediaDescription::CONSENT]]as$key=>[$label,$choices])$h.='<dt>'.Html::e($text($label)).'</dt><dd>'.Html::e($text($choices[$metadata[$key]]??'Ungeprüft')).'</dd>';
        if(($metadata['expires_on']??'')!=='')$h.='<dt>'.Html::e($text('Nutzung bis')).'</dt><dd>'.Html::e($metadata['expires_on']).'</dd>';
        $labels=[];foreach($metadata['channels']??[]as$channel)$labels[]=isset(MediaDescription::CHANNELS[$channel])?$text(MediaDescription::CHANNELS[$channel]):$channel;
        return$h.'<dt>'.Html::e($text('Erlaubte Bereiche')).'</dt><dd>'.Html::e(implode(', ',$labels)).'</dd></dl>';
    }
    public static function render(array $item,array $row,array $current,string $csrf,array $messages,?array $state=null,bool $separate=true,bool $canEdit=true,bool $canProof=true):string
    {
        $text=static fn(string$s):string=>VideoMetadataMessages::text($s,$messages);$t=static fn(string$s):string=>Html::e($text($s));
        if($state===null&&!$canEdit)return self::values($current,$text);
        $restored=$state!==null;$state??=['id'=>$item['id'],'source_id'=>$row['source_id'],'version'=>(string)$item['version'],'metadata_version'=>(string)$current['version'],'metadata_request_key'=>bin2hex(random_bytes(16)),'metadata'=>array_intersect_key($current,self::FIELDS)+['channels'=>$current['channels']],'replace_proof'=>false,'private_proof'=>''];
        $readOnly=!$canEdit;$confirmed=($state['result']['found']??false)===true;$metadata=$state['metadata'];$error=$state['error']??'';
        $h='<section class="video-metadata-form" data-video-metadata data-metadata-read-only="'.($readOnly?'1':'0').'" data-metadata-can-proof="'.($canProof?'1':'0').'" data-metadata-interface="'.Html::e(json_encode(VideoMetadataMessages::browser($messages),JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)).'">';
        if(!empty($state['prepared']))$h.='<p role="status">'.$t('Weitere Änderung vorbereitet. Deine Eingaben bleiben erhalten.').'</p>';
        if($error!=='')$h.='<p role="alert">'.Html::e($error).'</p>';
        if($confirmed){$result=$state['result'];$h.='<section class="video-metadata-saved" data-metadata-confirmed="'.Html::e(json_encode($result,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)).'"><h3>'.$t('Gespeicherte Videoangaben').'</h3><p role="status">'.$t('Videoangaben gespeichert. Der Beitrag bleibt geöffnet.').'</p>'.self::values($result['metadata'],$text).'</section><p>'.$t('Gespeicherter Nachweis gefunden. Deine weiteren Eingaben bleiben erhalten. Für eine neue Speicherung weitere Änderung vorbereiten.').'</p>';}
        if($confirmed&&$state['private_proof']!==''&&!$state['replace_proof'])$h.='<p role="status" data-metadata-proof-draft>'.$t('Videoangaben gespeichert. Der nicht ausgewählte Nachweistext bleibt als Entwurf erhalten.').'</p>';
        $h.='<p>'.$t($readOnly?'Du kannst die ursprüngliche Speicherung prüfen. Für Änderungen fehlt die Bearbeitungsberechtigung.':($separate?'Ohne JavaScript öffnen Speicherung und Prüfung ein eigenes Fenster. Der ungespeicherte Beitrag bleibt im ursprünglichen Fenster.':'Deine ursprünglichen Eingaben bleiben erhalten.')).'</p>';
        $h.='<form method="post" target="'.($separate?'_blank':'_self').'" data-video-metadata-form'.($restored&&empty($state['saved_now'])?' data-unsaved-changes':'').'>'.Html::hidden('csrf',$csrf).Html::hidden('operation','social-video-metadata-save').Html::hidden('metadata_window','1');
        foreach(['id','source_id','version','metadata_version','metadata_request_key']as$key)$h.=Html::hidden($key,$state[$key]);
        if($readOnly){foreach(self::FIELDS as$key=>$max)$h.=str_replace('<input ','<input data-metadata-readonly-copy ',Html::hidden('metadata['.$key.']',$metadata[$key]));foreach($metadata['channels']as$value)$h.=str_replace('<input ','<input data-metadata-readonly-copy ',Html::hidden('metadata[channels][]',$value));}
        $h.='<fieldset data-metadata-fields'.($readOnly?' disabled':'').'>';$required=$current['usage_policy']['require_details']?' required':'';
        foreach(['alt'=>'Beschreibung','credit'=>'Credit','source_name'=>'Quelle','license_name'=>'Lizenz']as$key=>$label)$h.=Html::input('metadata['.$key.']',$text($label),$metadata[$key],'text',($key==='alt'?'required':trim($required)).' maxlength="'.self::FIELDS[$key].'"');
        $h.='<details class="video-metadata-links"'.(!$separate?' open':'').'><summary>'.$t('Quellen- und Lizenzlinks').'</summary><div class="video-metadata-fields">';foreach(['source_url'=>'Quellenlink','license_url'=>'Lizenzlink']as$key=>$label)$h.=Html::input('metadata['.$key.']',$text($label),$metadata[$key],'url','maxlength="2048"');
        $h.='</div></details><details class="video-metadata-rights"'.((!$separate||$current['usage_policy']['check_rights']||$current['usage_policy']['require_details'])?' open':'').'><summary>'.$t('Rechte & Bereiche').'</summary><div class="video-metadata-fields">';
        $h.=Html::select('metadata[rights_state]',$text('Nutzungsrechte'),self::options(MediaDescription::RIGHTS,$metadata['rights_state'],$text),$metadata['rights_state']).Html::select('metadata[consent_state]',$text('Einwilligung'),self::options(MediaDescription::CONSENT,$metadata['consent_state'],$text),$metadata['consent_state']);
        $expiry=$metadata['expires_on'];$date=$expiry===''?false:\DateTimeImmutable::createFromFormat('!Y-m-d',$expiry,new \DateTimeZone('Europe/Berlin'));$h.=Html::input('metadata[expires_on]',$text('Nutzung bis'),$expiry,$expiry!==''&&(!$date||$date->format('Y-m-d')!==$expiry)?'text':'date','maxlength="10"');
        $h.='<fieldset><legend>'.$t('Erlaubte Bereiche').'</legend>';$channels=MediaDescription::CHANNELS;foreach($metadata['channels']as$value)if(!isset($channels[$value]))$channels[$value]=strtr($text('Ungültige Auswahl: {value}'),['{value}'=>$value]);
        foreach($channels as$key=>$label)$h.='<label class="check"><input class="check" type="checkbox" name="metadata[channels][]" value="'.Html::e($key).'"'.(in_array($key,$metadata['channels'],true)?' checked':'').'><span>'.Html::e(isset(MediaDescription::CHANNELS[$key])?$text($label):$label).'</span></label>';
        $h.='</fieldset></div></details>';
        if($canProof)$h.='<details class="video-metadata-proof"'.($state['replace_proof']||$state['private_proof']!==''?' open':'').'><summary>'.$t($current['has_proof']?'Privater Nachweis vorhanden':'Privater Nachweis').'</summary><p>'.$t('Gespeicherte private Nachweise werden hier nicht angezeigt.').'</p><label class="check"><input class="check" type="checkbox" name="replace_proof" value="1"'.($state['replace_proof']?' checked':'').'><span>'.$t('Nachweis ersetzen').'</span></label><label>'.$t('Neuer privater Nachweis').'<textarea class="form-control" name="private_proof" maxlength="4000" rows="3">'.Html::e($state['private_proof']).'</textarea></label></details>';
        $h.='</fieldset><div class="actions"><button class="btn btn-solid theme-primary" type="submit" data-metadata-save'.($readOnly||$confirmed?' hidden disabled':'').'>'.$t('Angaben speichern').'</button><button class="btn btn-outline theme-secondary" type="submit" name="metadata_review" value="1" formnovalidate data-metadata-review>'.$t('Gespeicherte Angaben prüfen').'</button><button class="btn btn-outline theme-secondary" type="button" data-metadata-retry hidden>'.$t('Ursprüngliche Angaben erneut speichern').'</button><button class="btn btn-outline theme-secondary" type="submit" name="metadata_next" value="1" formnovalidate data-metadata-next'.(!$confirmed||$readOnly?' hidden':'').'>'.$t('Weitere Änderung vorbereiten').'</button></div></form>';
        $h.='<p role="status" aria-live="polite" tabindex="-1" data-metadata-status>'.(is_array($state['result']??null)&&!$confirmed?$t('Keine gespeicherten Angaben für diesen Vorgang gefunden. Unverändert erneut speichern oder Eingaben bewusst korrigieren.'):'').'</p>';
        return$h.'<a class="btn btn-outline theme-secondary" data-save-original data-metadata-current target="_blank" rel="noopener" href="'.Html::e('/?'.http_build_query(['view'=>'social','section'=>'compose','item'=>$item['id'],'video_page'=>1]).'#social-videos').'">'.$t('Beitrag mit aktuellen Videoangaben öffnen').'</a></section>';
    }
    public static function page(PDO $db,Access $access,string $mediaRoot,string $csrf,array $state):string
    {
        $actor=$access->require('social','read');$item=(new \Kulturhus\Native\Communication\Workspace($db,$access,'social',$mediaRoot))->get($state['id']);$row=Db::run($db,'SELECT * FROM native_social_videos WHERE club_id=? AND item_id=? AND source_id=? ORDER BY id LIMIT 1',[$actor['club_id'],$state['id'],$state['source_id']])->fetch();if(!$row)throw new DomainException('Eigene Videoquelle erneut öffnen.');
        $metadata=(new \Kulturhus\Native\Communication\VideoMetadata($db,$access))->get($state['source_id']);$messages=(new Catalog($db,$access))->messages();$canEdit=true;$canProof=true;try{$access->require('social','edit');}catch(DomainException){$canEdit=false;}try{$access->require('social','manage');}catch(DomainException){$canProof=false;}
        $h='<section class="panel">'.self::render($item,$row,$metadata,$csrf,$messages,$state,false,$canEdit,$canProof).'</section>';$current=$access->require('social','read');if($current['id']!==$actor['id']||$current['club_id']!==$actor['club_id'])throw new DomainException('Speicherzugang geändert.');return$h;
    }
}
