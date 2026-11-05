<?php declare(strict_types=1);
namespace Kulturhus\Native\Web\Communication;
use Kulturhus\Native\Web\{Workspace as BaseWorkspace,Html,RichTextEditor};
use Kulturhus\Native\Communication\{Workspace,TemplateCatalogue,Content};
use Kulturhus\Native\Shared\RichText;

final class Templates extends BaseWorkspace
{
    use \Kulturhus\Native\Web\ContentInterfaceText;
    private function form(array $s,array $omit=[]): string
    {
        $h=str_replace('<form method="post">','<form method="post" data-contact-management>',$this->start('communication-template-manage'));
        foreach(['query','page','focus','new','current_version','history_page','revision','block_index']as$key)if(!in_array($key,$omit,true))$h.=Html::hidden('template_'.$key,$key==='new'?($s['new']?'1':'0'):($s[$key]??0));return$h;
    }
    public function manager(string $module,?array $state=null): string
    {
        $w=new Workspace($this->db,$this->access,$module,$this->mediaRoot);
        if($module==='social')return $this->social($w);
        $this->access->require($module,'read');$manage=$this->can($module,'manage');$edit=$this->can($module,'edit');$e=Html::e(...);
        $s=TemplateCatalogue::page($this->db,$this->access,$module,$state??TemplateFormState::restore(['template_query'=>$_GET['q']??'','template_page'=>$_GET['page']??1,'template_focus'=>$_GET['template_focus']??'','template_new'=>$_GET['template_new']??'','template_revision'=>$_GET['template_revision']??0,'template_history_page'=>$_GET['template_history_page']??1]));
        $h=('<section class="panel communication-templates" aria-label="' . Html::e($this->contentText('Vorlagen')) . '"><h2>' . Html::e($this->contentText('Vorlagen')) . '</h2>');
        if($s['focus']!==''||$s['new']){
            if($s['new']&&!$manage)throw new \DomainException('Vorlagenverwaltung benötigt die Verwaltungsberechtigung.');
            $row=$s['focus']!==''?$w->getTemplate($s['focus']):['id'=>'','version'=>0,'name'=>'','title'=>'','body'=>'','body_html'=>'','blocks'=>[],'builtin'=>false];
            if(!$s['current_version'])$s['current_version']=$row['version'];
            $in=$s['profile']??($row+['blocks_json'=>json_encode($row['blocks'],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),'body_html'=>$row['body_html']??TemplateFormState::plainHtml($row['body']??'')]);
            $h.='<section class="template-profile"><h3>'.$e($s['new']?$this->contentText('Neue Vorlage'):$row['name']).'</h3><p class="template-version">'.($row['builtin']?Html::e($this->contentText('Standardvorlage')):Html::e($this->contentText('Fassung')).' '.$s['current_version']).'</p>';
            if($s['revision']>0){
                $original=TemplateCatalogue::revision($this->db,$this->access,$module,$row['id'],$s['revision']);$h.=('<section class="template-original"><h4>' . Html::e($this->contentText('Fassung')) . ' ').$s['revision'].'</h4><p>'.$e($original['name']).('</p><details open><summary>' . Html::e($this->contentText('Original ansehen')) . '</summary>').$this->preview($module,$original).'</details></section>';
                if($manage&&!$row['builtin'])$h.=$this->form($s).('<button class="btn btn-solid theme-primary" name="template_action" value="restore">' . Html::e($this->contentText('Als neue Fassung übernehmen')) . '</button></form>');
                $h.=$this->form($s).('<button class="btn btn-outline theme-secondary" name="template_browse" value="current">' . Html::e($this->contentText('Aktuellen Stand öffnen')) . '</button></form>');
            }elseif($manage&&!$row['builtin']){
                $h.=$this->form($s).Html::hidden('id',$in['id']).Html::hidden('version',$in['version']).Html::input('name',$this->contentText('Vorlagenname'),$in['name'],'text','maxlength="200" required').Html::input('title',$this->contentText('Titel / Betreff'),$in['title'],'text','maxlength="300" required').RichTextEditor::render('body_html',$in['body_html'],$this->contentText('Text'),interfaceText:$this->contentText(...)).('<noscript><label>' . Html::e($this->contentText('Text')) . '<textarea class="form-control" name="body" rows="5" maxlength="100000">').$e(RichText::plain($in['body_html'])).'</textarea></label></noscript>'.$this->blocks($in,$s).Html::hidden('template_form_complete','1').('<button class="btn btn-solid theme-primary" name="template_action" value="save">' . Html::e($this->contentText('Vorlage speichern')) . '</button></form>');
                if(isset($s['profile'])&&$row['id']!=='')$h.=$this->form($s).('<button class="btn btn-outline theme-secondary" name="template_browse" value="current">' . Html::e($this->contentText('Aktuellen Stand öffnen')) . '</button></form>');
            }else $h.=('<details open><summary>' . Html::e($this->contentText('Inhalt ansehen')) . '</summary>').$this->preview($module,$row).'</details>';
            if($row['id']!==''&&$s['revision']===0){
                if($edit)$h.=str_replace('<form method="post">','<form method="post" data-contact-management>',$this->start('communication-from-template')).Html::hidden('template_id',$row['id']).Html::hidden('template_version',$s['current_version']).Html::hidden('template_focus',$row['id']).Html::hidden('template_current_version',$s['current_version']).('<button class="btn btn-solid theme-primary">' . Html::e($this->contentText('Neuen Entwurf erstellen')) . '</button></form>');
                if($manage)$h.=$this->form($s).('<button class="btn btn-outline theme-secondary" name="template_action" value="copy">' . Html::e($this->contentText('Als neue Vorlage kopieren')) . '</button></form>');
            }
            if(!$row['builtin']&&$row['id']!==''){
                $history=TemplateCatalogue::history($this->db,$this->access,$module,$row['id'],$s['history_page']);$s['history_page']=$history['page'];$h.='<details class="template-history"'.($s['revision']>0?' open':'').('><summary>' . Html::e($this->contentText('Verlauf ·')) . ' ').$history['total'].'</summary>'.$this->form($s).('<p>' . Html::e($this->contentText('Seite')) . ' ').$history['page'].' '.Html::e($this->contentText('von')).' '.$history['pages'].'</p>';
                foreach($history['rows']as$r)$h.='<p><button class="btn btn-outline theme-secondary" name="template_browse" value="revision:'.$r['version'].('">' . Html::e($this->contentText('Fassung')) . ' ').$r['version'].' · '.$this->displayTime($r['created_at']).'</button></p>';
                $h.=$this->paging($history,'history-').'</form></details>';
            }
            $h.=$this->form($s).('<button class="btn btn-outline theme-secondary" name="template_browse" value="close">' . Html::e($this->contentText('Schließen')) . '</button></form></section>');
        }
        $h.='<details class="template-list"'.($s['focus']===''&&!$s['new']?' open':'').('><summary>' . Html::e($this->contentText('Vorlagen ·')) . ' ').$s['total'].'</summary>'.$this->form($s,['query']).'<div class="template-toolbar">'.Html::input('template_query',$this->contentText('Vorlagenname'),$s['query'],'search','maxlength="200"').('<button class="btn btn-solid theme-primary" name="template_browse" value="search">' . Html::e($this->contentText('Suchen')) . '</button>');
        if($manage)$h.=('<button class="btn btn-outline theme-primary" name="template_browse" value="new">' . Html::e($this->contentText('Vorlage anlegen')) . '</button>');
        $h.=('</div><p role="status">' . Html::e($this->contentText('Seite')) . ' ').$s['page'].' '.Html::e($this->contentText('von')).' '.$s['pages'].'</p>';
        foreach($s['rows']as$r)$h.='<article class="template-card"><div><strong>'.$e($r['name']).'</strong><span>'.($r['builtin']?Html::e($this->contentText('Standardvorlage')):Html::e($this->contentText('Fassung')).' '.$r['version']).'</span></div><button class="btn btn-outline theme-secondary" name="template_browse" value="'.$r['id'].'">'.Html::e($this->contentText($manage&&!$r['builtin']?$this->contentText('Bearbeiten'):$this->contentText('Ansehen'))).'</button></article>';
        if(!$s['rows'])$h.=('<p>' . Html::e($this->contentText('Keine passenden Vorlagen.')) . '</p>');
        return$h.$this->paging($s).'</form></details></section>';
    }
    /** Read-only, bounded previews. Selecting a template does not create media or a draft. */
    private function social(Workspace $w):string
    {
        $this->access->require('social','read');$e=Html::e(...);$preview=$_GET['template_preview']??'';
        if(!is_string($preview)||($preview!==''&&!preg_match('/\A(?:[a-f0-9]{32}|social-(?:post|story|reel|carousel))\z/D',$preview)))throw new \DomainException('Vorlagenauswahl prüfen.');
        $page=TemplateCatalogue::page($this->db,$this->access,'social',['query'=>$_GET['template_query']??'','page'=>$_GET['template_page']??1,'reels_available'=>(new \Kulturhus\Native\Communication\Videos($this->db,$this->access,$this->mediaRoot))->installed()]);
        $url=static fn(array $change):string=>'?'.http_build_query(array_replace(['view'=>'social','section'=>'templates','template_query'=>$page['query'],'template_page'=>$page['page']],$change),'','&',PHP_QUERY_RFC3986);
        $h='<section class="communication-templates"><h2>'.$e($this->contentText('Vorlagen')).'</h2>';
        if($preview!==''){$selected=$w->getTemplate($preview);$h.='<section class="panel" data-social-template-preview><h3>'.$e($selected['name']).'</h3>'.$this->socialPreview($selected,540).Content::preview(Content::document('social',$selected));if($this->can('social','edit'))$h.=$this->start('communication-from-template').Html::hidden('template_id',$selected['id']).Html::hidden('template_version',$selected['version']).'<button class="btn btn-solid theme-primary">'.$e($this->contentText('Vorlage verwenden')).'</button></form>';$h.='<p><a class="btn btn-outline theme-secondary" href="'.$e($url([])).'">'.$e($this->contentText('Schließen')).'</a></p></section>';}
        $h.='<form method="get">'.Html::hidden('view','social').Html::hidden('section','templates').Html::input('template_query',$this->contentText('Vorlagenname'),$page['query'],'search','maxlength="200"').'<button class="btn btn-outline theme-secondary">'.$e($this->contentText('Suchen')).'</button></form><div class="draft-cards">';
        foreach($page['rows']as$meta){$row=$w->getTemplate($meta['id']);$h.='<article class="panel"><h3><span data-original-content>'.$e($row['name']).'</span></h3><a href="'.$e($url(['template_preview'=>$row['id']])).'" aria-label="'.$e($this->contentText('Ansehen').': '.$row['name']).'">'.$this->socialPreview($row,270).'</a><p><a class="btn btn-outline theme-secondary" href="'.$e($url(['template_preview'=>$row['id']])).'">'.$e($this->contentText('Ansehen')).'</a></p>';if($this->can('social','edit'))$h.=$this->start('communication-from-template').Html::hidden('template_id',$row['id']).Html::hidden('template_version',$row['version']).'<button class="btn btn-solid theme-primary">'.$e($this->contentText('Vorlage verwenden')).'</button></form>';$h.='</article>';}
        $h.='</div><p role="status">'.$e($this->contentText('Seite')).' '.$page['page'].' '.$e($this->contentText('von')).' '.$page['pages'].'</p>';
        if($page['page']>1)$h.='<a class="btn btn-outline theme-secondary" href="'.$e($url(['template_page'=>$page['page']-1])).'">'.$e($this->contentText('Zurück')).'</a>';
        if($page['page']<$page['pages'])$h.='<a class="btn btn-outline theme-secondary" href="'.$e($url(['template_page'=>$page['page']+1])).'">'.$e($this->contentText('Weiter')).'</a>';
        if(!$page['rows'])$h.='<p>'.$e($this->contentText('Keine passenden Vorlagen.')).'</p>';return$h.'</section>';
    }
    private function socialPreview(array $row,int $width):string
    {
        $e=Html::e(...);if(!extension_loaded('gd'))return'<p>'.$e($row['title']).'</p>';
        $identity=\Kulturhus\Native\Shared\Identity::forClub($this->db,$this->access->club());$destination=$row['destination_url']??'';if($destination==='')$destination=$identity['origin'];if($destination==='')$destination='https://example.invalid/';
        $slide=$row['kind']==='story'?($row['story'][0]??null):($row['kind']==='carousel'?($row['carousel'][0]??null):null);$slide??=['title'=>$row['title'],'text'=>$row['summary']??$row['body']??'','media_ref'=>''];
        $pictureId=$slide['media_ref']??'';if($pictureId==='')foreach($row['blocks']??[]as$block)if($block['type']==='image'){$pictureId=$block['media_ref'];break;}
        $picture=null;$logo=null;$imageMissing=false;
        if($this->can('website','read')&&($pictureId!==''||($identity['logo_id']??'')!=='')){
            $media=new \Kulturhus\Native\Cms\Media($this->db,$this->access,$this->mediaRoot);
            try{if($pictureId!=='')$picture=$media->variant($pictureId,'large');if(($identity['logo_id']??'')!=='')$logo=$media->variant($identity['logo_id'],'thumb');}catch(\RuntimeException|\InvalidArgumentException|\DomainException){$imageMissing=true;}
        }elseif($pictureId!=='')$imageMissing=true;
        try{
            $height=in_array($row['kind'],['story','reel'],true)?1920:1350;$canvas=\Kulturhus\Native\Communication\ImageDesign::canvas($slide,$height,$picture,$logo,$identity,$destination,$row['image_design']??[]);
            $thumb=imagecreatetruecolor($width,(int)round($height*$width/1080));imagecopyresampled($thumb,$canvas,0,0,0,0,$width,imagesy($thumb),1080,$height);ob_start();imagepng($thumb);$bytes=ob_get_clean();unset($thumb,$canvas);
            $h='<img src="data:image/png;base64,'.base64_encode($bytes).'" width="'.$width.'" height="'.(int)round($height*$width/1080).'" style="max-width:100%;height:auto" alt="'.$e($this->contentText('Vorschau').': '.$row['name']).'">';
            if($imageMissing)$h.='<p>'.$e($this->contentText('Das gespeicherte Foto ist für diese Vorschau nicht verfügbar.')).'</p>';if($row['kind']==='reel')$h.='<p>'.$e($this->contentText('Standbild-Vorschau für das animierte Video')).'</p>';return$h;
        }catch(\DomainException){return'<div class="panel"><strong>'.$e($slide['title']).'</strong><p>'.$e($slide['text']).'</p><p>'.$e($this->contentText('Die Bildvorschau benötigt ein passendes Foto und ausreichend kurzen Text.')).'</p></div>';}
    }
    private function paging(array $p,string $prefix=''): string
    {
        $h='<div class="template-toolbar">';if($p['page']>1)$h.='<button class="btn btn-outline theme-secondary" name="template_browse" value="'.$prefix.('previous">' . Html::e($this->contentText('Zurück')) . '</button>');if($p['page']<$p['pages'])$h.='<button class="btn btn-outline theme-secondary" name="template_browse" value="'.$prefix.('next">' . Html::e($this->contentText('Weiter')) . '</button>');return$h.'</div>';
    }
    private function preview(string $module,array $row): string { return Content::preview(Content::document($module,$row)); }
    private function blocks(array $in,array $s): string
    {
        $blocks=json_decode($in['blocks_json'],true,64,JSON_THROW_ON_ERROR);$i=max(0,min($s['block_index'],max(0,count($blocks)-1)));$b=$blocks[$i]??null;
        $h=Html::hidden('blocks_json',$in['blocks_json']).'<details class="template-blocks"'.(isset($s['profile'])?' open':'').('><summary>' . Html::e($this->contentText('Bausteine ·')) . ' ').count($blocks).'</summary>';
        if($b){$h.='<fieldset><legend>'.TemplateFormState::TYPES[$b['type']].' · '.($i+1).' / '.count($blocks).'</legend>';
            $fields=match($b['type']){'richtext'=>['html'=>$this->contentText('Text')],'text','heading'=>['text'=>$this->contentText('Text')],'image'=>['media_ref'=>'Bild','alt'=>$this->contentText('Alternativtext')],'button'=>['text'=>$this->contentText('Text'),'url'=>'Link'],'divider'=>[],'event'=>['title'=>$this->contentText('Titel'),'date'=>$this->contentText('Datum'),'venue'=>$this->contentText('Ort'),'text'=>$this->contentText('Text'),'media_ref'=>$this->contentText('Veranstaltungsfoto'),'url'=>$this->contentText('Link zur Veranstaltung')]};
            foreach($fields as$key=>$label){$value=$b[$key]??'';if($key==='text')$h.='<label>'.Html::e($this->contentText($label)).'<textarea class="form-control" name="block_'.$key.'" rows="3">'.Html::e($value).'</textarea></label>';
                elseif($key==='html')$h.=RichTextEditor::render('block_html',$value,$this->contentText($label),['max'=>10000],interfaceText:$this->contentText(...)).'<noscript><label>'.Html::e($this->contentText($label)).'<textarea class="form-control" name="block_plain" rows="5" maxlength="10000">'.Html::e(RichText::plain($value)).'</textarea></label></noscript>';
                elseif($key==='media_ref'){
                    $choices=[''=>$this->contentText('Bild auswählen')];if($this->can('website','read'))foreach((new \Kulturhus\Native\Cms\Media($this->db,$this->access,$this->mediaRoot))->list()as$m)$choices[$m['id']]=$m['alt'];
                    if($value!==''&&!isset($choices[$value]))$choices[$value]=$this->contentText('Gespeichertes Bild');$h.=Html::select('block_media_ref',$this->contentText($label),$choices,$value);
                }else$h.=Html::input('block_'.$key,$this->contentText($label),$value);
            }
            $h.='<div class="template-toolbar">';foreach(['previous'=>$this->contentText('Vorheriger Baustein'),'next'=>$this->contentText('Nächster Baustein'),'up'=>$this->contentText('Nach vorne'),'down'=>$this->contentText('Nach hinten'),'remove'=>$this->contentText('Baustein entfernen')]as$action=>$label){if(in_array($action,['previous','up'],true)&&$i===0)continue;if(in_array($action,['next','down'],true)&&$i===count($blocks)-1)continue;$h.='<button class="btn btn-outline theme-secondary" name="template_browse" value="block:'.$action.'" formnovalidate>'.$label.'</button>';}$h.='</div></fieldset>';
        }
        if(count($blocks)<100){$h.=('<details><summary>' . Html::e($this->contentText('Baustein hinzufügen')) . '</summary><div class="template-toolbar">');foreach(TemplateFormState::TYPES as$type=>$label)$h.='<button class="btn btn-outline theme-secondary" name="template_browse" value="add:'.$type.'" formnovalidate>'.$label.'</button>';$h.='</div></details>';}
        return$h.'</details>';
    }
}
