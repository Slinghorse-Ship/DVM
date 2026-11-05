<?php declare(strict_types=1);
namespace Kulturhus\Native\Web\Communication;
use DomainException;
use Kulturhus\Native\Web\Html;
use Kulturhus\Native\Shared\Access;
use PDO;

/** A separate HTML window preserves sibling drafts without JavaScript. No browser file is restored. */
final class PhotoUploadForm
{
    public static function restore(array $post):array
    {
        $out=[];foreach(['id'=>32,'version'=>10,'photo_request_key'=>32,'alt'=>8000]as$key=>$max){$value=$post[$key]??'';if(!is_string($value)||strlen($value)>$max||preg_match('//u',$value)!==1||preg_match('/[\x00-\x1F\x7F]/',$value))throw new DomainException('Ursprüngliches Fotoformular öffnen.');$out[$key]=$value;}
        if(!preg_match('/\A[a-f0-9]{32}\z/D',$out['id'])||!preg_match('/\A[1-9][0-9]{0,9}\z/D',$out['version'])||!preg_match('/\A[a-f0-9]{32}\z/D',$out['photo_request_key'])||mb_strlen($out['alt'])>2000)throw new DomainException('Ursprüngliches Fotoformular öffnen.');return$out;
    }
    public static function render(array $item,string $csrf,callable $text,?array $state=null,bool $separate=true):string
    {
        $state??=['id'=>$item['id'],'version'=>(string)$item['version'],'photo_request_key'=>bin2hex(random_bytes(16)),'alt'=>''];$t=static fn(string$s):string=>Html::e($text($s));
        $h='<section class="studio-photo-upload" data-photo-upload><h3>'.$t('Eigenes Foto hochladen').'</h3>';
        if(($state['error']??'')!=='')$h.='<p role="alert">'.Html::e($state['error']).'</p>';
        $result=$state['result']??null;
        if($result&&$result['found'])$h.='<p role="status" data-photo-status>'.$t('Foto gespeichert. Beitrag und Gestaltung bleiben unverändert.').'</p><p>'.Html::e($result['name']).'</p><a class="btn btn-outline theme-secondary" data-photo-original data-save-original target="_blank" rel="noopener" href="'.Html::e($result['original_url']).'">'.$t('Gespeichertes Foto öffnen').'</a><p>'.$t('Dieses Fenster kann geschlossen werden. Der ursprüngliche Beitrag bleibt geöffnet.').'</p><a class="btn btn-outline theme-secondary" data-save-original target="_blank" rel="noopener" href="'.Html::e('/?'.http_build_query(['view'=>'social','section'=>'compose','item'=>$item['id']])).'">'.$t('Beitrag mit aktuellem Bilderbestand öffnen').'</a>';
        else{
            $h.='<p>'.$t($separate?'Ohne JavaScript öffnet der Upload ein eigenes Fenster. Ungespeicherte Beitragstexte und Gestaltungen bleiben im ursprünglichen Fenster.':'Datei erneut auswählen. Bildbeschreibung und ursprüngliche Vorgangskennung bleiben erhalten.').'</p>';
            if(is_array($result))$h.='<p role="status">'.$t('Kein gespeichertes Foto gefunden. Datei bewusst erneut auswählen und speichern.').'</p>';
            $h.='<form method="post" enctype="multipart/form-data" target="'.($separate?'_blank':'_self').'" data-social-photo-form>'.Html::hidden('csrf',$csrf).Html::hidden('operation','social-photo-upload').Html::hidden('photo_window','1').Html::hidden('id',$state['id']).Html::hidden('version',$state['version']).Html::hidden('photo_request_key',$state['photo_request_key']);
            $h.='<label>'.$t('Foto auswählen (bis 256 MiB)').'<input class="form-control" type="file" name="photo" accept="'.\Kulturhus\Native\Cms\PhotoInput::accept().'" required></label>'.Html::input('alt',$text('Bildbeschreibung (optional)'),$state['alt'],'text','maxlength="500"');
            $h.='<div class="actions"><button type="submit" class="btn btn-solid theme-primary" data-photo-save>'.$t('Foto in Galerie speichern').'</button><button class="btn btn-outline theme-secondary" name="photo_review" value="1" formnovalidate formenctype="application/x-www-form-urlencoded" data-photo-review>'.$t('Gespeichertes Foto prüfen').'</button></div></form><p role="status" data-photo-status aria-live="polite"></p><a class="btn btn-outline theme-secondary" data-photo-original data-save-original target="_blank" rel="noopener" hidden>'.$t('Gespeichertes Foto öffnen').'</a>';
        }
        return$h.'</section>';
    }
    public static function page(PDO $db,Access $access,string $mediaRoot,string $csrf,array $state):string
    {
        $access->require('social','edit');$access->require('website','edit');$access->require('website','read');$item=(new \Kulturhus\Native\Communication\Workspace($db,$access,'social',$mediaRoot))->get($state['id']);
        $messages=(new \Kulturhus\Native\Language\Catalog($db,$access))->messages();return'<section class="panel">'.self::render($item,$csrf,static fn(string$s):string=>\Kulturhus\Native\Language\ImageStudioMessages::text($s,$messages),$state,false).'</section>';
    }
}
