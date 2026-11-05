<?php declare(strict_types=1);
namespace Kulturhus\Native\Web;

use DomainException;
use Kulturhus\Native\Communication\Content;

/** Fixed editor controls use the caller's language; saved blocks and media labels stay literal. */
final class Editor
{
    public static function blocks(string $name,array $blocks,array $media=[],bool $upload=false,?callable $interfaceText=null,?array $events=null,array $brand=[]):string
    {
        return self::render($name,Content::blocks($blocks),'blocks',$media,$upload,$interfaceText,events:$events,brand:$brand);
    }

    public static function story(string $name,array $slides,array $media=[],bool $upload=false,?callable $interfaceText=null):string
    {
        $slides=\Kulturhus\Native\Web\Communication\SocialComposerState::slides(['story_slides'=>$slides]);
        $t=$interfaceText??static fn(string $source):string=>$source;
        $label=static fn(string $source):string=>Html::e($t($source));
        $fallback='<noscript><div data-editor-fallback>';
        foreach($slides?:[['title'=>'','text'=>'','media_ref'=>'']]as$index=>$slide){
            $fallback.='<fieldset><legend>'.$label('Folie').' '.($index+1).'</legend>'.Html::input('story_slides['.$index.'][title]',$t('Folientitel'),$slide['title'],'text','maxlength="200"').'<label>'.$label('Folientext').'<textarea class="form-control" name="story_slides['.$index.'][text]" rows="3" maxlength="1200">'.Html::e($slide['text']).'</textarea></label>';
            $choices=[''=>$t('Ohne Foto')];foreach($media as$image)$choices[$image['id']]=$image['alt'];
            $fallback.=Html::select('story_slides['.$index.'][media_ref]',$t('Foto'),$choices,$slide['media_ref']).'<div class="actions">';
            foreach(['up'=>'Nach vorne','down'=>'Nach hinten','remove'=>'Entfernen']as$action=>$text)$fallback.='<button class="btn btn-outline theme-secondary" name="story_browse" value="'.$action.':'.$index.'" formnovalidate>'.$label($text).'</button>';
            $fallback.='</div></fieldset>';
        }
        if(count($slides)<10)$fallback.='<button class="btn btn-outline theme-secondary" name="story_browse" value="add" formnovalidate>'.$label('Folie hinzufügen').'</button>';
        return self::render($name,$slides,'story',$media,$upload,$t,$fallback.'</div></noscript>');
    }

    public static function carousel(string $name,array $cards,array $media=[],bool $upload=false,?callable $interfaceText=null):string
    {
        if(count($cards)>10||!array_is_list($cards))throw new DomainException('Höchstens zehn Karussell-Bilder auswählen.');
        $cards=$cards?:array_fill(0,2,['title'=>'','text'=>'','media_ref'=>'']);
        $t=$interfaceText??static fn(string $source):string=>$source;
        $label=static fn(string $source):string=>Html::e($t($source));
        $fallback='<noscript><div data-editor-fallback>';
        foreach($cards as$index=>$card){
            $fallback.='<fieldset><legend>'.$label('Bild').' '.($index+1).'</legend>'.Html::input('carousel_cards['.$index.'][title]',$t('Titel'),$card['title']??'','text','maxlength="200"').'<label>'.$label('Text').'<textarea class="form-control" name="carousel_cards['.$index.'][text]" rows="3" maxlength="1200">'.Html::e($card['text']??'').'</textarea></label>';
            $choices=[''=>$t('Ohne Foto')];foreach($media as$image)$choices[$image['id']]=$image['alt'];
            $fallback.=Html::select('carousel_cards['.$index.'][media_ref]',$t('Foto'),$choices,$card['media_ref']??'').'<div class="actions">';
            foreach(['up'=>'Nach vorne','down'=>'Nach hinten','remove'=>'Entfernen']as$action=>$text)$fallback.='<button class="btn btn-outline theme-secondary" name="carousel_browse" value="'.$action.':'.$index.'" formnovalidate>'.$label($text).'</button>';
            $fallback.='</div></fieldset>';
        }
        if(count($cards)<10)$fallback.='<button class="btn btn-outline theme-secondary" name="carousel_browse" value="add" formnovalidate>'.$label('Bild hinzufügen').'</button>';
        return self::render($name,$cards,'carousel',$media,$upload,$t,$fallback.'</div></noscript>');
    }

    private static function render(string $name,array $items,string $mode,array $media,bool $upload,?callable $interfaceText,string $fallback='',?array $events=null,array $brand=[]):string
    {
        if(!preg_match('/^[a-zA-Z][a-zA-Z0-9_]*$/D',$name))throw new DomainException('Ungültiger Editorfeldname.');
        $t=$interfaceText??static fn(string $source):string=>$source;
        $label=static fn(string $source):string=>Html::e($t($source));
        $id='kh-editor-'.bin2hex(random_bytes(6));$story=in_array($mode,['story','carousel'],true);$carousel=$mode==='carousel';
        $heading=$carousel?'Karussell gestalten':($story?'Story gestalten':'Inhaltsbausteine');
        $json=json_encode($items,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
        $html='<section class="kh-editor" data-kh-editor="'.$mode.'"'.($brand!==[]?' data-editor-newsletter':'').' id="'.$id.'" aria-labelledby="'.$id.'-title"><h3 id="'.$id.'-title">'.$label($heading).'</h3><input type="hidden" data-editor-value name="'.Html::e($name).'" value="'.Html::e($json).'">';
        $html.='<div class="kh-editor-tools" role="group" aria-label="'.$label($carousel?'Bild hinzufügen':($story?'Folie hinzufügen':'Baustein hinzufügen')).'">';
        $types=$story?['slide'=>$carousel?'Bild hinzufügen':'Folie hinzufügen']:['text'=>'Text','heading'=>'Überschrift','image'=>'Bild','button'=>'Schaltfläche','divider'=>'Trennlinie','event'=>'Veranstaltung'];
        foreach($types as$type=>$title)$html.='<button type="button" class="btn btn-outline theme-secondary secondary" data-editor-add="'.$type.'" disabled>+ '.$label($title).'</button>';
        if($events!==null){$html.='<template data-editor-events>'.Html::e(json_encode(Content::blocks($events),JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)).'</template><button type="button" class="btn btn-outline theme-secondary" data-editor-upcoming'.(!$events?' disabled':'').'>'.$label('Nächste Veranstaltungen einfügen').'</button>';if(!$events)$html.='<p>'.$label('Derzeit keine kommenden veröffentlichten Veranstaltungen.').'</p>';}
        $html.='</div><div class="kh-editor-summary"><span data-editor-count>'.count($items).' / '.($story?'10':'100').' '.$label($carousel?'Bilder':($story?'Folien':'Bausteine')).'</span><button type="button" class="btn btn-outline theme-secondary secondary" data-editor-undo hidden>'.$label('Entfernen rückgängig machen').'</button></div>';
        if($upload)$html.='<div class="kh-editor-upload"><label>'.$label('Fotos hinzufügen').'<input class="form-control" type="file" accept="'.\Kulturhus\Native\Cms\PhotoInput::accept().'" multiple data-editor-upload></label><button type="button" class="btn btn-outline theme-secondary secondary" data-editor-upload-cancel hidden>'.$label('Abbrechen').'</button></div>';
        $html.='<p class="kh-editor-status" data-editor-status role="status" aria-live="polite"></p><nav class="kh-editor-selection" data-editor-navigation aria-label="'.$label($carousel?'Bilder':($story?'Folien':'Bausteine')).'" hidden><button type="button" class="btn btn-outline theme-secondary secondary" data-editor-step="-1" aria-label="'.$label($carousel?'Vorheriges Bild':($story?'Vorherige Folie':'Vorheriger Baustein')).'">←</button><label>'.$label($carousel?'Bild':($story?'Folie':'Baustein')).'<select class="form-control" data-editor-select></select></label><button type="button" class="btn btn-outline theme-secondary secondary" data-editor-step="1" aria-label="'.$label($carousel?'Nächstes Bild':($story?'Nächste Folie':'Nächster Baustein')).'">→</button></nav>'.($brand!==[]?self::newsletterFrame($brand,'<div class="kh-editor-items" data-editor-list aria-labelledby="'.$id.'-title"></div>'):'<div class="kh-editor-items" data-editor-list aria-labelledby="'.$id.'-title"></div>').'<template data-editor-media>';
        foreach($media as$image){$imageId=Content::id($image['id']);$html.='<button type="button" class="btn btn-outline theme-secondary secondary kh-media-choice" data-media-id="'.$imageId.'"><img loading="lazy" decoding="async" src="/media-preview/'.$imageId.'/thumb" alt="'.Html::e($image['alt']).'"><span>'.Html::e($image['alt']).'</span></button>';}
        $html.='</template>';
        if($fallback==='')$fallback='<noscript><p>'.$label('Für den Baustein-Editor bitte JavaScript aktivieren. Bereits gespeicherte Bausteine bleiben unverändert erhalten.').'</p></noscript>';
        return $html.$fallback.'</section>';
    }
    private static function newsletterFrame(array $brand,string $canvas):string
    {
        $e=Html::e(...);$hero=($brand['mail_hero_url']??'')!==''?'<img class="live-newsletter-hero" src="/assets/saxophone.jpg" alt="Saxophon" loading="eager">':'';
        // Same club identity and original newsletter artwork as the actual outgoing mail.
        if(($brand['logo_png']??'')!==''){$brand['logo_png']='';$brand['logo_url']='/site/assets/kulturhus-public/apple-touch-icon.png';}
        return '<div class="newsletter-live-tools"><strong>Live gestalten</strong><span>Texte und Bausteine direkt in der Mail bearbeiten.</span><button type="button" class="btn btn-outline theme-secondary" data-mail-size="desktop" aria-pressed="true">Computer</button><button type="button" class="btn btn-outline theme-secondary" data-mail-size="mobile" aria-pressed="false">Handy</button></div><div class="newsletter-live-workspace" data-newsletter-workspace><article class="newsletter-live-mail"><header class="newsletter-live-brand">'.\Kulturhus\Native\Shared\MailDesign::header($brand,false).'</header>'.$hero.'<div class="newsletter-live-heading" data-live-mail-title></div><div class="newsletter-live-content"><div data-live-mail-summary></div><div data-live-mail-intro></div>'.$canvas.'</div><footer class="newsletter-live-footer"><strong>'.$e($brand['name']).'</strong><p>'.$e($brand['mail_tagline']??'').'</p><small>Im Browser ansehen · Impressum · Newsletter abmelden</small></footer></article></div>';
    }

}
