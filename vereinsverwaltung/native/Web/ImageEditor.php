<?php declare(strict_types=1);
namespace Kulturhus\Native\Web;
/** Own accessible controls and same-origin assets. Stored design content stays literal. */
final class ImageEditor
{
    public static function render(array $item,array $identity,array $media,array $history,string $csrf,bool $upload,array $metadata=[],?callable $interfaceText=null,?array $series=null):string
    {
        $e=Html::e(...);$t=$interfaceText??static fn(string $source):string=>$source;
        $label=static fn(string $source):string=>$e($t($source));
        $format=static function(string $source,array $values)use($t,$e):string{$replace=[];foreach($values as$key=>$value)$replace['{'.$key.'}']=(string)$value;return $e(strtr($t($source),$replace));};
        $height=$item['kind']==='story'?1920:1350;$documents=[];
        foreach($history as$image){$meta=json_decode($image['metadata_json'],true,32,JSON_THROW_ON_ERROR);if(isset($meta['document'])&&!isset($documents[$image['slide']])&&$meta['height']===$height)$documents[$image['slide']]=$meta['document'];}
        if($series!==null&&isset($series['document'])){
            $human=false;foreach($history as$image){$meta=json_decode($image['metadata_json'],true,32,JSON_THROW_ON_ERROR);if((int)$image['slide']===0&&(($meta['renderer']??'')==='canvas2d-local'||($meta['story_reference']['override']??false)===true)){$human=true;break;}}
            if(!$human)$documents[0]=$series['document'];
        }
        $seed=['height'=>$height,'title'=>$item['title'],'summary'=>$item['summary']??'','slides'=>$item[$item['kind']==='carousel'?'carousel':'story']??[],'name'=>$identity['name'],'logo'=>$identity['logo_id'],'documents'=>$documents,'media'=>[],'qr'=>'/social-qr?'.http_build_query(['item'=>$item['id']]),'interface'=>\Kulturhus\Native\Language\ImageStudioMessages::dictionary($t)];
        $initial=[];$slides=$seed['slides']?:[['title'=>$seed['title'],'text'=>$seed['summary'],'media_ref'=>'']];$available=array_column($media,null,'id');$photo='';
        foreach($item['blocks']??[]as$block)if(in_array($block['type'],['image','event'],true)&&!empty($block['media_ref'])){$photo=$block['media_ref'];break;}
        foreach($slides as$index=>$slide){$slide['media_ref']=$slide['media_ref']?:$photo;if($slide['media_ref']!==''&&isset($available[$slide['media_ref']]))$initial[$index]=\Kulturhus\Native\Communication\ClassicCanvas::document($slide,$item['kind'],$identity);}
        $seed['initial_documents']=$initial;
        if($series!==null){
            $seed['story_series']=array_diff_key($series,['document'=>true]);
            foreach(['initial_documents','documents']as$group)if(isset($seed[$group][0])){
                $controls=array_values(array_filter($seed[$group][0]['layers'],static fn(array$l):bool=>in_array($l['id'],['reminder-background','reminder-label'],true)));
                if(count($controls)===2)$seed['story_series']['reminder_layers']=$controls;
                if(!array_filter($controls,static fn(array$l):bool=>$l['id']==='reminder-label')&&count($seed[$group][0]['layers'])<=28)$seed[$group][0]['layers']=[...$seed[$group][0]['layers'],...$seed['story_series']['reminder_layers']];
                foreach($seed[$group][0]['layers']as&$layer)if($layer['id']==='reminder-label'&&$layer['type']==='text')$layer['text']=$series['labels'][$series['slot']]??$series['labels']['initial'];
                unset($layer);
            }
        }
        foreach($media as$m){
            $source=$metadata[$m['id']]??[];$credit=['lines'=>[]];
            try{$credit['lines']=\Kulturhus\Native\Communication\ImageAttribution::lines($source);}catch(\DomainException $error){$credit['error']=$t($error->getMessage());}
            if($credit['lines'])$credit['url']='/social-credit?'.http_build_query(['media'=>$m['id'],'version'=>$source['version']]);
            $seed['media'][]=['id'=>$m['id'],'name'=>$m['alt'],'url'=>'/media-preview/'.$m['id'].'/large','thumb'=>'/media-preview/'.$m['id'].'/thumb','credit'=>$credit];
        }
        $seed['attribution']=['line_height'=>\Kulturhus\Native\Communication\ImageAttribution::LINE_HEIGHT,'max_lines'=>\Kulturhus\Native\Communication\ImageAttribution::MAX_LINES];
        $h='<section class="panel canvas-editor" id="social-image-editor" tabindex="-1" data-canvas-editor><header><div><span class="eyebrow">'.$label('Eigene Gestaltung').'</span><h2>'.$label('Bildstudio').'</h2></div><span class="badge">1080 × '.$height.'</span></header>';
        $h.='<p class="hint" data-canvas-fallback>'.$label($item['kind']==='story'?'Das Bildstudio benötigt JavaScript und eine verfügbare Zeichenfläche. Unter „Angaben zur Story“ kannst du die Bildvorlagen verwenden.':'Die freie Bildgestaltung benötigt JavaScript und eine verfügbare Zeichenfläche. Die Bildvorlagen im Beitragsformular kannst du weiterhin verwenden.').' <a href="#social-compose">'.$label($item['kind']==='story'?'Zu den Story-Angaben':'Zum Beitragsformular').'</a></p>';
        $h.='<textarea class="form-control" data-canvas-seed hidden>'.$e(json_encode($seed,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)).'</textarea>';
        $h.='<form method="post" enctype="multipart/form-data" data-canvas-form hidden>'.Html::hidden('csrf',$csrf).Html::hidden('operation','social-canvas-save').Html::hidden('id',$item['id']).Html::hidden('version',$item['version']).Html::hidden('identity_version',$identity['version']).Html::hidden('canvas_request_key',bin2hex(random_bytes(16))).'<input type="hidden" name="editor_document" data-canvas-document><input class="form-control" type="file" name="canvas_image" accept="image/png" data-canvas-file hidden>';
        $h.='<fieldset class="studio-fieldset" data-canvas-controls disabled><legend class="visually-hidden">'.$label('Gestaltungswerkzeuge').'</legend>';
        $slides=[];foreach(($item[$item['kind']==='carousel'?'carousel':'story']??[])?:[['title'=>'']]as$index=>$slide)$slides[(string)$index]=$t($item['kind']==='carousel'?'Bild':'Folie').' '.($index+1).(($slide['title']??'')!==''?' · '.$slide['title']:'');
        $h.='<div class="studio-toolbar">'.Html::select('slide',$t('Bildfolie'),$slides,'0');
        foreach(['undo'=>'Rückgängig','redo'=>'Wiederherstellen']as$action=>$text)$h.='<button type="button" data-studio="'.$action.'" class="btn btn-outline theme-secondary secondary" disabled>'.$label($text).'</button>';
        $h.='<details class="studio-export"><summary>'.$label('Bild herunterladen').'</summary><div class="studio-export-controls">';
        foreach(['width'=>['Breite in Pixel',1080],'height'=>['Höhe in Pixel',$height]]as$key=>[$text,$value])$h.='<label>'.$label($text).'<input class="form-control" type="number" data-export-'.$key.' value="'.$value.'" min="32" max="8192" step="1" required></label>';
        $h.='<label class="check"><input class="check" type="checkbox" data-export-aspect checked>'.$label('Seitenverhältnis erhalten').'</label><p class="hint">'.$format('Bis zu 16 Megapixel. Die gespeicherte Social-Fassung verwendet 1080 × {height} Pixel.',['height'=>$height]).'</p>';
        foreach(['png'=>'PNG','jpeg'=>'JPEG','webp'=>'WebP']as$imageFormat=>$name)$h.='<button type="button" data-studio="download-'.$imageFormat.'" class="btn btn-outline theme-secondary secondary">'.$name.'</button>';
        $h.='</div></details><button type="submit" class="btn btn-solid theme-primary">'.$label('Bildfassung speichern').'</button></div>';
        $h.='<div class="studio-grid"><div class="studio-stage"><div class="studio-frame"><canvas width="1080" height="'.$height.'" tabindex="-1" aria-disabled="true" aria-label="'.$label('Bildgestaltung. Ebene in der Ebenenliste wählen; mit Pfeiltasten verschieben.').'"></canvas></div>';
        $h.='<label class="check"'.($height!==1920?' hidden':'').'><input class="check" type="checkbox" data-safe-area checked> '.$label('Story-Sicherheitsbereich anzeigen').'</label><p class="hint">'.$label('Der Auswahlrahmen und Hilfslinien werden nicht exportiert.').'</p></div><aside class="studio-controls" aria-label="'.$label('Gestaltungswerkzeuge').'"><div class="studio-add">';
        foreach(['text'=>'Text','rect'=>'Fläche','ellipse'=>'Kreis','line'=>'Linie','arrow'=>'Pfeil','path'=>'Freihand zeichnen','frame'=>'Rahmen','redact'=>'Unkenntlich machen','qr'=>'QR-Code']as$action=>$text)$h.='<button type="button" data-studio="'.$action.'" class="btn btn-outline theme-secondary secondary">'.(in_array($action,['path','redact'],true)?'':'＋ ').$label($text).'</button>';
        if($identity['logo_id']!=='')$h.='<button type="button" data-studio="logo" class="btn btn-outline theme-secondary secondary">＋ '.$label('Vereinslogo').'</button>';
        $h.='</div><fieldset class="studio-photo-picker" data-studio-photo-picker data-dirty-ignore><legend>'.$label('Foto verwenden').'</legend><label class="check"><input type="radio" name="studio_photo_mode" data-studio-photo-mode value="replace" checked> '.$label('Vorlagenfoto ersetzen').'</label><label class="check"><input type="radio" name="studio_photo_mode" data-studio-photo-mode value="add"> '.$label('Zusätzliches Foto hinzufügen').'</label><details class="studio-photo-gallery" open><summary>'.$label('Fotos ansehen').'</summary><label>'.$label('Fotos durchsuchen').'<input type="search" class="form-control" data-studio-photo-search></label><div class="studio-photo-choices" data-studio-photo-choices>';
        foreach($media as$m)$h.='<button type="button" class="btn btn-outline theme-secondary studio-photo-choice" data-studio-photo-id="'.$e($m['id']).'"><img loading="lazy" decoding="async" src="/media-preview/'.$e($m['id']).'/thumb" alt=""><span>'.$e($m['alt']).'</span></button>';
        $h.='</div></details><label>'.$label('Eigenes Foto').'<select class="form-control" data-studio-photo><option value="">'.$label('Foto wählen …').'</option>';
        foreach($media as$m)$h.='<option value="'.$e($m['id']).'">'.$e($m['alt']).'</option>';
        $h.='</select></label></fieldset><label>'.$label('Hintergrundfarbe').'<input class="form-control" type="color" data-studio-background value="#163f33"></label><label>'.$label('Ebenen').'<select class="form-control" data-studio-layers size="5" aria-label="'.$label('Gestaltungsebene auswählen').'"></select></label><div class="studio-add">';
        foreach(['up'=>'Nach vorne','down'=>'Nach hinten','duplicate'=>'Duplizieren','remove'=>'Entfernen']as$action=>$text)$h.='<button type="button" data-studio="'.$action.'" class="btn btn-outline theme-secondary secondary">'.$label($text).'</button>';
        $h.='</div><div data-studio-properties></div></aside></div><p role="status" data-studio-status aria-live="polite">'.$label('Bildstudio wird geladen …').'</p></fieldset></form><div class="studio-canvas-recovery" data-canvas-recovery hidden><button type="button" class="btn btn-outline theme-secondary" data-canvas-review>'.$label('Gespeicherte Bildfassung prüfen').'</button><a class="btn btn-outline theme-secondary" data-canvas-original data-save-original target="_blank" rel="noopener" hidden>'.$label('Gespeicherte Bildfassung öffnen').'</a></div>';
        if($upload)$h.='<details data-photo-details><summary>'.$label('Neues eigenes Foto in die Galerie laden').'</summary>'.\Kulturhus\Native\Web\Communication\PhotoUploadForm::render($item,$csrf,$t).'</details>';
        return $h.'</section>';
    }
}
