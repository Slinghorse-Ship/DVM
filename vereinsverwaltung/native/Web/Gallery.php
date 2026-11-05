<?php declare(strict_types=1);
namespace Kulturhus\Native\Web;
use PDO;
use DomainException;
use InvalidArgumentException;
use Kulturhus\Native\Cms\{Pages,Media,MediaUploads,Conflict,AlbumOrder,AlbumVersions,AlbumCatalogue,TemplatePolicy};
use Kulturhus\Native\Shared\Access;
/** Album workflow composed from versioned CMS pages and the isolated media store. */
final class Gallery
{
    use \Kulturhus\Native\Web\ContentInterfaceText;
    private Pages $pages;private Media $media;private MediaUploads $uploads;private AlbumOrder $order;private PublicationPlan $planning;
    private readonly Website\PageChildrenPanel $children;
    private readonly Website\PageHierarchyPanel $hierarchy;
    private readonly Website\PageVersionsPanel $versions;
    private readonly AlbumVersions $albumVersions;
    private readonly Website\PageMediaPicker $picker;
    private readonly AlbumCatalogue $catalogue;
    private readonly \Kulturhus\Native\Cms\PublishedAlbums $publishedAlbums;
    private int $publicPageNumber=1;
    public function __construct(private PDO $db,private Access $access,string $root,private string $csrf=''){$this->publishedAlbums=new \Kulturhus\Native\Cms\PublishedAlbums($db,$access);$this->catalogue=new AlbumCatalogue($db,$access);$this->picker=new Website\PageMediaPicker($db,$access);$this->albumVersions=new AlbumVersions($db,$access);$this->versions=new Website\PageVersionsPanel($db,$access,$csrf);$this->pages=new Pages($db,$access);$this->media=new Media($db,$access,$root);$this->uploads=new MediaUploads($db,$access,$root);$this->order=new AlbumOrder($db,$access);$this->planning=new PublicationPlan($db,$access,$csrf);$this->children=new Website\PageChildrenPanel($db,$access);$this->hierarchy=new Website\PageHierarchyPanel($db,$access,$csrf);}
    private function submitted(string $operation,string $id,?string $image=null):bool
    {
        return ($_SERVER['REQUEST_METHOD']??'')==='POST'&&http_response_code()>=400
            &&($_POST['operation']??null)===$operation&&($_POST['id']??'')===$id
            &&($image===null||($_POST['image_id']??null)===$image)
            &&$this->csrf!==''&&is_string($_POST['csrf']??null)&&hash_equals($this->csrf,$_POST['csrf']);
    }
    private function form(string $op,string $id='',int $version=0,?string $image=null):string
    {
        if($this->submitted($op,$id,$image)&&is_string($_POST['version']??null))$version=$_POST['version'];
        return '<form method="post"'.($op==='album-upload'?' enctype="multipart/form-data" data-album-upload-form data-album-photo-status-url="/album-photo-status"':'').'>'.Html::hidden('csrf',$this->csrf).Html::hidden('operation',$op).Html::hidden('id',$id).Html::hidden('version',$version);
    }
    private function can(string $cap,?string $id=null):bool{try{$this->access->require('website',$cap);if($id!==null)TemplatePolicy::assertCapability($this->db,$this->access,$id,$cap);return true;}catch(DomainException){return false;}}
    private function album(string $id):array{$p=$this->pages->get($id);if(($p['template_definition']['renderer']??$p['template'])!=='gallery'||$p['slug']==='galerie')throw new DomainException('Album nicht verfügbar.');return $p;}
    public function albums():array{return $this->order->sort(array_values(array_filter($this->pages->list(),static fn($p)=>($p['template_definition']['renderer']??$p['template'])==='gallery'&&$p['slug']!=='galerie'&&!$p['archived'])));}
    public function action(array $post,array $files):string|array
    {
        $op=$post['operation']??'';
        if(!is_string($op)||!in_array($op,['album-save','album-publish','album-unpublish','album-order','album-plan','album-cancel-plan','album-restore','album-upload','album-attach','album-image','album-remove','album-move'],true))throw new DomainException('Unbekannte Albumaktion.');
        $this->access->require('website',in_array($op,['album-publish','album-unpublish','album-order','album-plan','album-cancel-plan'],true)?'publish':'edit');
        if($op==='album-upload'&&(!is_string($post['id']??null)||!preg_match('/\A[a-f0-9]{32}\z/D',$post['id'])||(!is_int($post['version']??null)&&!is_string($post['version']??null))||!preg_match('/\A[1-9][0-9]*\z/D',(string)$post['version'])||filter_var($post['version'],FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>4294967294]])===false))throw new InvalidArgumentException('Albumkennung und gespeicherten Versionsstand prüfen.');
        if($op==='album-restore'&&!is_string($post['id']??null))throw new InvalidArgumentException('Gültige Albumkennung angeben.');
        $id=$post['id']??'';
        if(!is_string($id)||($id!==''&&!preg_match('/\A[a-f0-9]{32}\z/D',$id))||($id===''&&$op!=='album-save'))throw new InvalidArgumentException('Gültige Albumkennung angeben.');
        $rawVersion=$post['version']??0;
        if((!is_int($rawVersion)&&!is_string($rawVersion))||!preg_match('/\A(?:0|[1-9][0-9]{0,9})\z/D',(string)$rawVersion)||($version=filter_var($rawVersion,FILTER_VALIDATE_INT))===false||$version>4294967294||($version===0&&($op!=='album-save'||$id!=='')))throw new InvalidArgumentException('Gespeicherte Albumversion prüfen.');
        if($op==='album-restore'){
            foreach(['revision','version'] as $key)if((!is_int($post[$key]??null)&&!is_string($post[$key]??null))||!preg_match('/\A[1-9][0-9]*\z/D',(string)$post[$key]))throw new InvalidArgumentException('Gültige gespeicherte Albumversionen angeben.');
            $revision=filter_var($post['revision']??null,FILTER_VALIDATE_INT);$expected=filter_var($post['version']??null,FILTER_VALIDATE_INT);
            if(!is_string($post['id']??null)||$revision===null||$revision===false||$expected===null||$expected===false)throw new InvalidArgumentException('Albumkennung und gültige gespeicherte Versionen angeben.');
            return $this->albumVersions->restore($id,$revision,$expected)['id'];
        }
        if($op==='album-save'){
            foreach(['title','slug','excerpt','body','body_html','body_plain']as$field)if(array_key_exists($field,$post)&&!is_string($post[$field]))throw new InvalidArgumentException('Albumangaben als gültigen Text angeben.');
            $p=$id?$this->album($id):['template'=>'gallery','nav_visible'=>false,'media_ids'=>[],'gallery_items'=>[]];$originalHtml=Html::content($p);
            $p['title']=(string)($post['title']??'');$slug=(string)($post['slug']??'');$p['slug']='galerie/'.preg_replace('~^galerie/~','',$slug);if($id===''&&($post['auto_address']??null)==='1')$p['auto_address']='1';$p['excerpt']=(string)($post['excerpt']??'');$p['body']=(string)($post['body']??'');
            if(array_key_exists('body_html',$post)){$p['body_html']=(string)$post['body_html'];$p['body']='';}else unset($p['body_html']);
            if(array_key_exists('body_plain',$post)){
                $plain=str_replace(["\r\n","\r"],"\n",$post['body_plain']);
                if($plain===\Kulturhus\Native\Shared\RichText::plain($originalHtml)){$p['body_html']=$originalHtml;$p['body']=\Kulturhus\Native\Shared\RichText::plain($originalHtml);}
                else{$p['body_html']='';$p['body']=$plain;}
            }
            $order=$post['nav_order']??($p['nav_order']??0);if((!is_int($order)&&!is_string($order))||filter_var($order,FILTER_VALIDATE_INT)===false)throw new InvalidArgumentException('Ungültige Navigationsreihenfolge.');$p['nav_order']=$order;return $this->pages->save($p,$version)['id'];
        }
        $p=$this->album($id);if((int)$p['version']!==$version)throw new Conflict('Album wurde inzwischen geändert. Bitte neu laden.');
        if(in_array($op,['album-plan','album-cancel-plan'],true)){$this->planning->action($post,$p,$op==='album-cancel-plan');return $id;}
        if($op==='album-order'){
            $orderVersion=filter_var($post['order_version']??null,FILTER_VALIDATE_INT);$direction=$post['direction']??'';
            if($orderVersion===false||$orderVersion===null||$orderVersion<0||!is_string($direction))throw new InvalidArgumentException('Gültigen Stand der Albumreihenfolge angeben.');
            $list=$this->catalogue->listing($_GET);
            $this->order->move($id,$direction,$orderVersion);
            return ['view'=>'media','item'=>'','notice'=>'Albumreihenfolge gespeichert.','q'=>$list['q'],'status'=>$list['status'],'album_list_page'=>(string)$list['page']];
        }
        if($op==='album-publish'){$this->pages->publish($id,$version);return $id;}
        if($op==='album-unpublish'){$this->pages->unpublish($id,$version);return $id;}
        $items=$p['gallery_items']??array_map(static fn($mid)=>['id'=>$mid,'caption'=>'','tags'=>[]],$p['media_ids']);
        if($op==='album-upload'){
            if(count($p['media_ids'])>=100)throw new DomainException('Ein Album kann höchstens 100 Bilder enthalten.');
            $caption=$post['caption']??'';$uploadId=$post['upload_id']??null;
            if(!is_string($caption)||($uploadId!==null&&!is_string($uploadId)))throw new InvalidArgumentException('Bildbeschreibung und Upload-Kennung prüfen.');
            $caption=trim($caption);if($caption==='')$caption=$p['title'].' – neues Bild';
            $file=$files['image']??null;
            if(is_array($file)&&is_array($file['error']??null)){
                if($uploadId!==null)throw new InvalidArgumentException('Upload-Kennung nur für ein einzelnes Bild verwenden.');
                $batch=Website\PagePhotoUploads::fromFiles($files,'image');
                if(!$batch)throw new InvalidArgumentException('Mindestens ein Bild auswählen.');
                if(count($p['media_ids'])+count($batch)>100)throw new DomainException('Ein Album kann höchstens 100 Bilder enthalten.');
                $this->uploads->uploadMany($batch,function(array $assets)use($p,$items,$caption,$version):void{
                    $data=$p;$data['media_ids']=[...$p['media_ids'],...array_column($assets,'id')];
                    $data['gallery_items']=[...$items,...array_map(static fn($asset)=>['id'=>$asset['id'],'caption'=>$caption,'tags'=>[]],$assets)];
                    $this->pages->save($data,$version);
                },null,$caption);
                return $id;
            }
            if(!is_array($file)||($file['error']??null)!==UPLOAD_ERR_OK||!is_string($file['tmp_name']??null)||!is_string($file['name']??null)||!is_uploaded_file($file['tmp_name']))throw new DomainException('Bild konnte nicht hochgeladen werden.');
            $asset=$this->media->upload($file['tmp_name'],$file['name'],$caption,null,function(array $asset)use($p,$items,$caption,$version):void{
                $data=$p;$data['media_ids'][]=$asset['id'];$data['gallery_items']=[...$items,['id'=>$asset['id'],'caption'=>$caption,'tags'=>[]]];
                $this->pages->save($data,$version);
            },$uploadId);
            if($uploadId!==null)return ['view'=>'media','item'=>$id,'notice'=>'Bild im Album gespeichert.','image'=>['id'=>$asset['id'],'alt'=>$asset['alt'],'name'=>$asset['original_name'],'url'=>'/media-preview/'.$asset['id'].'/thumb','edit_url'=>'/?view=media&section=library&item='.$asset['id'],'album'=>['id'=>$id,'version'=>$version+1]]];
            return $id;
        }elseif($op==='album-attach'){
            $selection=$post['images']??[];
            if(!is_array($selection)||!array_is_list($selection)||count($selection)>100)throw new InvalidArgumentException('Bildauswahl prüfen.');
            foreach($selection as$selected)if(!is_string($selected)||!preg_match('/\A[a-f0-9]{32}\z/D',$selected))throw new InvalidArgumentException('Bildauswahl prüfen.');
            foreach($selection as $mid){$m=$this->media->variant((string)$mid,'thumb');if(!in_array($mid,$p['media_ids'],true)){$p['media_ids'][]=$mid;$items[]=['id'=>$mid,'caption'=>$m['alt'],'tags'=>[]];}}
        }elseif(in_array($op,['album-image','album-remove','album-move'],true)){
            $mid=$post['image_id']??'';
            if(!is_string($mid)||!preg_match('/\A[a-f0-9]{32}\z/D',$mid)||($op==='album-image'&&(!is_string($post['caption']??'')||!is_string($post['tags']??''))))throw new InvalidArgumentException('Bildkennung und Beschriftung prüfen.');
            $index=array_search($mid,array_column($items,'id'),true);if($index===false)throw new DomainException('Bild gehört nicht zu diesem Album.');
            if($op==='album-move'&&!in_array($post['direction']??null,['up','down'],true))throw new InvalidArgumentException('Bildbewegung prüfen.');
            if($op==='album-remove'){array_splice($items,$index,1);}
            elseif($op==='album-move'){$to=$index+(($post['direction']??'')==='up'?-1:1);if($to>=0&&$to<count($items))[$items[$to],$items[$index]]=[$items[$index],$items[$to]];}
            else{$items[$index]['caption']=(string)($post['caption']??'');$items[$index]['tags']=array_values(array_filter(array_map('trim',explode(',',(string)($post['tags']??''))),static fn($tag)=>$tag!==''));}
            $p['media_ids']=array_column($items,'id');
        }
        else throw new DomainException('Unbekannte Albumaktion.');
        $p['gallery_items']=$items;$this->pages->save($p,$version);return $id;
    }
    public function manager(string $id='',?array $filters=null):string
    {
        $filters??=$_GET;$e=Html::e(...);$edit=$this->can('edit');
        if($id===''||$id==='new'){
            $h=MediaLibrary::tabs(false,false,$this->can('manage')&&\Kulturhus\Native\Cms\MediaAvailability::installed($this->db),false,$this->can('manage'),text:$this->contentText(...)).('<div class="gallery-manager"><div class="gallery-heading"><p class="intro">'.Html::e($this->contentText('Vereinsmomente sammeln, zu Alben ordnen und auf der Website zeigen.')).'</p>').($edit?('<a class="btn btn-solid theme-primary button" data-nav href="/?view=media&amp;item=new">'.Html::e($this->contentText('+ Neues Album')).'</a>'):'').'</div>';
            if($id==='new'&&$edit)return $h.'<div class="panel">'.$this->albumForm(null).'</div></div>';
            if($id==='new')throw new DomainException('Keine Berechtigung zum Anlegen von Alben.');
            $listing=$this->catalogue->listing($filters);$albums=$listing['items'];$query=$listing['q'];$status=$listing['status'];$pager=$this->listPager($listing);$movements=$this->order->movements(array_column($albums,'id'));
            if(is_string($_POST['id']??null)&&$this->submitted('album-order',$_POST['id'])&&is_string($_POST['order_version']??null)&&(string)$_POST['order_version']!==(string)$listing['order_version'])$h.='<p role="alert">'.Html::e($this->contentText('Albumreihenfolge wurde inzwischen geändert. Bitte neu laden.')).' <a data-nav href="/?view=media">'.Html::e($this->contentText('Aktuellen Stand öffnen')).'</a></p>';
            $h.='<form method="get" action="/" class="content-list-filters">'.Html::hidden('view','media').Html::input('q',$this->contentText('Alben und Bildbeschreibungen suchen'),$query,'search','maxlength="120"').Html::select('status',$this->contentText('Status'),$this->contentOptions(['all'=>'Alle Alben','draft'=>'Entwürfe','published'=>'Veröffentlicht']),$status).('<button class="btn btn-solid theme-primary">'.Html::e($this->contentText('Suchen')).'</button><a data-nav href="/?view=media">'.Html::e($this->contentText('Zurücksetzen')).'</a></form><p class="content-list-count">').Html::e($this->contentFormat('{count} Alben',['count'=>$listing['total']])).'</p>'.$pager.('<div class="content-table-wrap"><table class="table content-management-table"><thead><tr><th scope="col">'.Html::e($this->contentText('Album')).'</th><th scope="col">'.Html::e($this->contentText('Bilder')).'</th><th scope="col">'.Html::e($this->contentText('Status')).'</th><th scope="col">'.Html::e($this->contentText('Reihenfolge')).'</th><th scope="col">'.Html::e($this->contentText('Aktionen')).'</th></tr></thead><tbody>');
            foreach($albums as $a){$rank=$a['rank'];$mid=$a['cover_id']??'';
                $h.='<tr data-album-row="'.$e($a['id']).'"><td><a class="album-table-title" data-nav href="/?view=media&amp;item='.$e($a['id']).'">'.($mid?'<img src="/media-preview/'.$e($mid).'/thumb" alt="" loading="lazy">':'').'<span>'.$e($a['title']).('</span></a></td><td data-label="'.Html::e($this->contentText('Bilder')).'">').$a['image_count'].('</td><td data-label="'.Html::e($this->contentText('Status')).'"><span class="badge">').Html::e($this->contentText($a['published_revision']?($a['has_unpublished_changes']?'Veröffentlicht · neuer Entwurf':'Veröffentlicht'):'Entwurf')).'</span>'.$this->planning->summary($a).('</td><td data-label="'.Html::e($this->contentText('Reihenfolge')).'"><div class="content-row-actions"><span>').($rank+1).'</span>';
                foreach(['up'=>'↑','down'=>'↓']as $direction=>$label)if($movements[$a['id']][$direction]??false)$h.=$this->form('album-order',$a['id'],$a['version']).Html::hidden('direction',$direction).Html::hidden('order_version',$this->submitted('album-order',$a['id'])&&is_string($_POST['order_version']??null)?$_POST['order_version']:$listing['order_version']).'<button class="btn btn-outline theme-secondary secondary" aria-label="'.Html::e($this->contentFormat($direction==='up'?'{title} nach oben':'{title} nach unten',['title'=>$a['title']])).'"'.'>'.Html::e($this->contentText($label)).'</button></form>';
                $h.=('</div></td><td data-label="'.Html::e($this->contentText('Aktionen')).'"><div class="content-row-actions"><a data-nav href="/?view=media&amp;item=').$e($a['id']).'">'.Html::e($this->contentText($this->can('edit',$a['id'])?'Bearbeiten':'Ansehen')).'</a>'.($a['published_slug']?'<a href="/site/'.$e($a['published_slug']).('" target="_blank" rel="noopener">'.Html::e($this->contentText('Website ↗')).'</a>'):'').'</div></td></tr>';
            }
            if(!$albums)$h.=('<tr><td colspan="5">'.Html::e($this->contentText('Keine Alben für diese Auswahl.')).'</td></tr>');
            return $h.'</tbody></table></div>'.$pager.('<p class="help">'.Html::e($this->contentText('Die Pfeile verschieben das Album in der vollständigen Reihenfolge und wirken sofort auf veröffentlichte Alben. Inhalte und unveröffentlichte Änderungen bleiben unverändert.')).'</p></div>');
        }
        $a=$this->album($id);$edit=$this->can('edit',$id);$page=$filters['album_page']??'1';
        if((!is_string($page)&&!is_int($page))||!preg_match('/\A[1-9][0-9]*\z/D',(string)$page)||($page=filter_var($page,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>1000000000]]))===false)throw new InvalidArgumentException('Gültige Bilderseite angeben.');
        $last=max(1,(int)ceil(count($a['media_ids'])/6));$page=min($page,$last);$offset=($page-1)*6;
        $h=MediaLibrary::tabs(false,false,$this->can('manage')&&\Kulturhus\Native\Cms\MediaAvailability::installed($this->db),false,$this->can('manage'),text:$this->contentText(...)).('<a class="back-link" data-nav href="/?view=media">'.Html::e($this->contentText('← Alle Alben')).'</a><div class="gallery-heading"><div><h2 class="album-title">').$e($a['title']).'</h2><p><span data-album-photo-count>'.count($a['media_ids']).'</span> '.Html::e($this->contentText('Bilder')).' · '.Html::e($this->contentText(($a['published_revision']??null)?'Veröffentlicht':'Entwurf')).(($a['has_unpublished_changes']??false)?' · '.Html::e($this->contentText('Änderungen gespeichert, noch nicht veröffentlicht')):'').'<span data-album-draft-status></span></p></div><div class="actions">';
        if($this->can('publish',$id))$h.=$this->form('album-publish',$id,$a['version']).('<button class="btn btn-solid theme-primary">'.Html::e($this->contentText('Album veröffentlichen')).'</button></form>');
        if($a['published_slug']??'')$h.='<a class="btn btn-outline theme-secondary button secondary" target="_blank" rel="noopener" href="/site/'.$e($a['published_slug']).('">'.Html::e($this->contentText('Website-Vorschau ↗')).'</a>');
        $h.='</div></div><div data-album="'.$e($id).'" data-album-version="'.$e($a['version']).'" data-album-count="'.count($a['media_ids']).'">';
        if(is_string($_POST['operation']??null)&&($this->submitted($_POST['operation'],$id))&&is_string($_POST['version']??null)&&(string)$_POST['version']!==(string)$a['version'])$h.='<p role="alert">'.Html::e($this->contentText('Album wurde inzwischen geändert. Bitte neu laden.')).' <a data-nav href="'.Html::e('/?'.http_build_query(['view'=>'media','item'=>$id])).'">'.Html::e($this->contentText('Aktuellen Stand öffnen')).'</a></p>';

        if($edit)$h.=('<details class="panel"'.($this->submitted('album-save',$id)?' open':'').'><summary>'.Html::e($this->contentText('Albumangaben bearbeiten')).'</summary>').$this->albumForm($a).'</details><div class="album-drop panel">'.$this->form('album-upload',$id,$a['version']).('<label class="upload-area" data-album-drop><span class="upload-icon" aria-hidden="true">＋</span><strong>'.Html::e($this->contentText('Bilder hier hineinziehen oder auswählen')).'</strong><span>'.Html::e($this->contentText('Mehrere Fotos · jeweils bis 256 MiB')).'</span><input class="form-control" data-album-upload data-dirty-ignore type="file" name="image[]" multiple accept="').\Kulturhus\Native\Cms\PhotoInput::accept().('"></label><p data-upload-progress role="status" aria-live="polite"></p><div data-library-upload-actions><button class="btn btn-outline theme-secondary" type="button" data-library-upload-cancel hidden>'.Html::e($this->contentText('Upload abbrechen')).'</button><button class="btn btn-outline theme-secondary" type="button" data-library-upload-check hidden>'.Html::e($this->contentText('Uploads prüfen')).'</button><button class="btn btn-outline theme-secondary" type="button" data-library-upload-forget hidden>'.Html::e($this->contentText('Prüfung beenden')).'</button><a class="btn btn-outline theme-secondary" data-nav data-album-upload-reload hidden href="/?view=media&amp;item=').$e($id).('">'.Html::e($this->contentText('Album neu laden')).'</a></div><div data-library-upload-results hidden></div><noscript><p>'.Html::e($this->contentText('Bis zu zehn Kameraoriginale und insgesamt 512 MiB.')).'</p><button class="btn btn-solid theme-primary">'.Html::e($this->contentText('Bilder hochladen')).'</button></noscript></form></div>');
        $pager=$this->imagePager($a,$page,$last);$h.='<section id="album-image-list" tabindex="-1" data-gallery-id="'.$e($a['id']).'" data-gallery-version="'.$e($a['version']).'" data-gallery-total="'.count($a['gallery_items']??[]).'" data-gallery-page="'.$page.'" data-gallery-pages="'.$last.('" data-gallery-kind="private"><h3>'.Html::e($this->contentText('Albumfotos')).'</h3>').$pager.'<div class="album-images">';foreach(array_slice($a['gallery_items']??[],$offset,6) as $index=>$m){$n=$offset+$index;$preview=$this->media->variant($m['id'],'medium');$caption=$m['caption']!==''?$m['caption']:$preview['alt'];$metadata=(new \Kulturhus\Native\Cms\MediaMetadata($this->db,$this->access))->get($m['id']);$shownCaption=$this->submitted('album-image',$id,$m['id'])&&is_string($_POST['caption']??null)?$_POST['caption']:$caption;$shownTags=$this->submitted('album-image',$id,$m['id'])&&is_string($_POST['tags']??null)?$_POST['tags']:implode(', ',$m['tags']);$h.='<article class="album-image" data-album-image="'.$e($m['id']).'"><button type="button" class="btn btn-solid theme-primary photo-open" data-lightbox data-group="album" data-gallery-index="'.($n+1).'" data-full="/media-preview/'.$e($m['id']).'/large" data-caption="'.$e($caption).'" data-alt="'.$e($preview['alt']).'" aria-label="'.Html::e($this->contentFormat('{caption} groß anzeigen',['caption'=>$caption])).'"><img src="/media-preview/'.$e($m['id']).'/medium" alt="'.$e($preview['alt']).'" loading="lazy" width="'.$e($preview['width']).'" height="'.$e($preview['height']).'"></button><noscript><a class="btn btn-outline theme-secondary" data-native-media href="/media-preview/'.$e($m['id']).('/large">'.Html::e($this->contentText('Großes Bild öffnen')).'</a></noscript><div class="photo-caption"><p>').$e($caption).MediaCredit::render($metadata,interfaceText:$this->contentText(...)).'</p>';
            if($edit){$h.=('<details'.($this->submitted('album-image',$id,$m['id'])?' open':'').'><summary>'.Html::e($this->contentText('Bildangaben & Reihenfolge')).'</summary>').$this->form('album-image',$id,$a['version'],$m['id']).Html::hidden('image_id',$m['id']).Html::input('caption',$this->contentText('Bildbeschreibung'),$shownCaption,'text','required maxlength="500"').Html::input('tags',$this->contentText('Schlagwörter (mit Komma trennen)'),$shownTags).('<button class="btn btn-outline theme-secondary secondary">'.Html::e($this->contentText('Bildangaben speichern')).'</button></form><div class="actions">');foreach(['up'=>'← Nach vorn','down'=>'Nach hinten →'] as $direction=>$label)$h.=$this->form('album-move',$id,$a['version'],$m['id']).Html::hidden('image_id',$m['id']).Html::hidden('direction',$direction).'<button class="btn btn-outline theme-secondary secondary"'.(($direction==='up'&&$n===0)||($direction==='down'&&$n===count($a['media_ids'])-1)?' disabled':'').'>'.Html::e($this->contentText($label)).'</button></form>';$h.=$this->form('album-remove',$id,$a['version'],$m['id']).Html::hidden('image_id',$m['id']).('<button class="btn btn-outline theme-secondary secondary">'.Html::e($this->contentText('Aus Album entfernen')).'</button></form></div><small>'.Html::e($this->contentText('Das Bild bleibt in der Mediathek verfügbar.')).'</small></details>');}
            $h.='</div></article>';}
        $h.='</div>'.$pager.'</section>';
        if($edit)$h.=('<details class="panel"><summary>'.Html::e($this->contentText('Bilder aus der Mediathek hinzufügen')).'</summary>').$this->form('album-attach',$id,$a['version']).$this->picker->render($this->picker->rejectedAlbumSelection($a,$_POST,$this->csrf),false,$a['media_ids']).('<button class="btn btn-solid theme-primary">'.Html::e($this->contentText('Ausgewählte Bilder hinzufügen')).'</button></form></details>');
        if($this->can('publish',$id)&&($a['published_revision']??null))$h.=('<details class="panel"><summary>'.Html::e($this->contentText('Veröffentlichung verwalten')).'</summary><p>'.Html::e($this->contentText('Die gespeicherte Entwurfsfassung wird erst nach Veröffentlichung auf der Website sichtbar.')).'</p>').$this->form('album-unpublish',$id,$a['version']).('<button class="btn btn-outline theme-secondary secondary">'.Html::e($this->contentText('Album offline nehmen')).'</button></form></details>');
        if($this->can('edit'))$h.=\Kulturhus\Native\Web\Website\PageCopyPanel::render($a,$this->csrf,(new \Kulturhus\Native\Cms\Pages($this->db,$this->access))->copyBases($a['id']),interfaceText:$this->contentText(...));
        $h.=(new \Kulturhus\Native\Web\Website\PageFieldtypesPanel($this->db,$this->access,$this->csrf))->render($a);
        if($edit)$h.=$this->hierarchy->render($a);
        $h.=$this->children->render($a);
        return $h.$this->planning->render($a,'album').'<section class="panel">'.$this->versions->render($a,'media',$edit?'album-restore':'').'</section></div>';
    }
    private function imagePager(array $album,int $page,int $last): string
    {
        $href=static fn(int $target):string=>'/?'.http_build_query(['view'=>'media','item'=>$album['id'],'album_page'=>$target]).'#album-image-list';
        $html=('<nav class="album-image-pager" aria-label="'.Html::e($this->contentText('Albumfotos')).'">');
        if($page>1)$html.='<a class="btn btn-outline theme-secondary" data-nav href="'.Html::e($href($page-1)).('">'.Html::e($this->contentText('Zurück')).'</a>');
        $html.='<form method="get" action="/#album-image-list">'.Html::hidden('view','media').Html::hidden('item',$album['id']).('<label>'.Html::e($this->contentText('Bilderseite')).'<select class="form-control" name="album_page" aria-label="'.Html::e($this->contentText('Bilderseite')).'" data-no-select-search>');
        for($i=1;$i<=$last;$i++)$html.='<option value="'.$i.'"'.($i===$page?' selected':'').'>'.Html::e($this->contentFormat('{page} von {pages}',['page'=>$i,'pages'=>$last])).'</option>';
        $html.=('</select></label><button class="btn btn-outline theme-secondary">'.Html::e($this->contentText('Anzeigen')).'</button></form>');
        if($page<$last)$html.='<a class="btn btn-outline theme-secondary" data-nav href="'.Html::e($href($page+1)).('">'.Html::e($this->contentText('Weiter')).'</a>');
        return $html.'</nav>';
    }
    private function listPager(array $listing): string
    {
        $href=static fn(int $page):string=>'/?'.http_build_query(['view'=>'media','q'=>$listing['q'],'status'=>$listing['status'],'album_list_page'=>$page]);
        $html=('<nav class="album-list-pager" aria-label="'.Html::e($this->contentText('Alben')).'">');
        if($listing['page']>1)$html.='<a class="btn btn-outline theme-secondary" data-nav href="'.Html::e($href($listing['page']-1)).('">'.Html::e($this->contentText('Zurück')).'</a>');
        $html.='<form method="get" action="/">'.Html::hidden('view','media').Html::hidden('q',$listing['q']).Html::hidden('status',$listing['status']).('<label>'.Html::e($this->contentText('Albenseite')).'<select class="form-control" name="album_list_page" aria-label="'.Html::e($this->contentText('Albenseite')).'" data-no-select-search>');
        for($i=1;$i<=$listing['last'];$i++)$html.='<option value="'.$i.'"'.($i===$listing['page']?' selected':'').'>'.Html::e($this->contentFormat('{page} von {pages}',['page'=>$i,'pages'=>$listing['last']])).'</option>';
        $html.=('</select></label><button class="btn btn-outline theme-secondary">'.Html::e($this->contentText('Anzeigen')).'</button></form>');
        if($listing['page']<$listing['last'])$html.='<a class="btn btn-outline theme-secondary" data-nav href="'.Html::e($href($listing['page']+1)).('">'.Html::e($this->contentText('Weiter')).'</a>');
        return $html.'</nav>';
    }
    private function albumForm(?array $a):string
    {
        $e=Html::e(...);$a??=['id'=>'','version'=>0,'title'=>'','slug'=>'','excerpt'=>'','body'=>'','nav_order'=>0];
        if($this->submitted('album-save',$a['id'])){foreach(['title','slug','excerpt','body','body_html','body_plain','nav_order']as$field)if(is_string($_POST[$field]??null))$a[$field]=$_POST[$field];if(array_key_exists('body_html',$_POST))$a['body']='';}

        return ('<h2>'.Html::e($this->contentText('Albumangaben')).'</h2>').str_replace('<form method="post">','<form method="post"'.($a['id']===''?' data-address-suggestion':'').'>',$this->form('album-save',$a['id'],$a['version'])).($a['id']===''?Html::hidden('auto_address','1'):'').'<div class="fields">'.Html::input('title',$this->contentText('Albumtitel'),$a['title'],'text','required').'</div><details><summary>Adresse des Albums</summary><p>Neue Alben erhalten eine passende Adresse aus dem Titel. Eine bestehende Adresse bleibt bei Titeländerungen erhalten.</p>'.Html::input('slug',$this->contentText('Adresse unter /galerie/'),preg_replace('~^galerie/~','',$a['slug']),'text',($a['id']!==''?'required ':'').'pattern="[a-z0-9]+(?:-[a-z0-9]+)*" data-address-target').'</details>'.Html::input('excerpt',$this->contentText('Kurzbeschreibung'),$a['excerpt']).RichTextEditor::render('body_html',Html::content($a),$this->contentText('Beschreibung'),interfaceText:$this->contentText(...)).'<noscript><label>'.Html::e($this->contentText('Beschreibung bearbeiten')).'<textarea class="form-control" name="body_plain" data-richtext-fallback-for="body_html" rows="6" maxlength="20000">'.Html::e($a['body_plain']??\Kulturhus\Native\Shared\RichText::plain(Html::content($a))).'</textarea></label><p>'.Html::e($this->contentText('Deine Textänderung wird ohne Formatierung gespeichert. Unveränderter Text behält die bisherige Formatierung.')).'</p></noscript>'.Html::input('nav_order',$this->contentText('Position in der Albumübersicht'),$a['nav_order'],'number').('<button class="btn btn-solid theme-primary">'.Html::e($this->contentText('Albumangaben speichern')).'</button></form>');
    }
    public function publicPageNumber(): int {return $this->publicPageNumber;}
    public function publicContent(?array $album=null,int $pageNumber=1,?PublishedLanguage $language=null,?\Closure $pageLink=null):string
    {
        if($pageNumber<1||$pageNumber>83334)throw new InvalidArgumentException('Ungültige Seitennummer.');
        $e=Html::e(...);$this->publicPageNumber=1;
        $listPath=$language?->path('galerie')??'galerie';
        if($album!==null&&$language!==null){$album=$language->page($album);if($album===null)return '<p>Dieses Album ist nicht veröffentlicht.</p>';}
        if($album){$last=max(1,(int)ceil(count($album['gallery_items']??[])/6));$this->publicPageNumber=min($pageNumber,$last);$pager=$this->publicPager(PublishedLanguage::pagePath($album),$this->publicPageNumber,$last,'Bilderseite',$pageLink);return '<p><a href="/site/'.$e($listPath).'">← Alle Alben</a></p><section id="gallery-items" data-public-album="'.$e($album['id']).'" data-gallery-id="'.$e($album['id']).'" data-gallery-version="'.$e($album['version']).'" data-gallery-total="'.count($album['gallery_items']??[]).'" data-gallery-page="'.$this->publicPageNumber.'" data-gallery-pages="'.$last.'" data-gallery-kind="public" aria-label="'.$e($album['title']).'">'.$pager.$this->publicPhotos($album,($this->publicPageNumber-1)*6,6).$pager.'</section>';}
        $listing=$this->publishedAlbums->listing($pageNumber);$this->publicPageNumber=$listing['page'];$pager=$this->publicPager($listPath,$listing['page'],$listing['last'],'Albenseite',$pageLink);
        if(!$listing['total'])return '<p>Noch keine Alben veröffentlicht.</p>';
        $h='<section id="gallery-items">'.$pager;
        // Resolve and release only one current publication at a time, never all album bodies.
        foreach($listing['items']as$key){$a=$this->pages->publicPage($key['slug']);if(!$a||$a['id']!==$key['id'])continue;if($language!==null){$a=$language->page($a);if($a===null)continue;}$h.='<section class="public-album" data-public-album="'.$e($a['id']).'" data-gallery-id="'.$e($a['id']).'" data-gallery-version="'.$e($a['version']).'" data-gallery-total="'.count($a['gallery_items']??[]).'" data-gallery-page="1" data-gallery-pages="'.max(1,(int)ceil(count($a['gallery_items']??[])/6)).'" data-gallery-kind="public" data-gallery-url="/site/'.$e(PublishedLanguage::pagePath($a)).'" aria-label="'.$e($a['title']).'"><div class="album-section-heading"><div><h2><a href="/site/'.$e(PublishedLanguage::pagePath($a)).'">'.$e($a['title']).'</a></h2><p>'.$e($a['excerpt']).'</p></div><a class="public-album-open" href="/site/'.$e(PublishedLanguage::pagePath($a)).'">Alle '.count($a['gallery_items']??[]).' Bilder →</a></div>'.$this->publicPhotos($a,0,3).'</section>';}
        return $h.$pager.'</section>';
    }
    private function publicPhotos(array $album,int $offset,int $limit): string
    {
        $e=Html::e(...);$h='<div class="photo-masonry">';
        foreach(array_slice($album['gallery_items']??[],$offset,$limit)as$i=>$m){$v=$this->media->publicVariant($m['id'],'medium',$album);$thumb=$this->media->publicVariant($m['id'],'thumb',$album);if(!$v||!$thumb)continue;$caption=$m['caption']!==''?$m['caption']:$v['alt'];$alt=($album['media_metadata'][$m['id']]['version']??0)>0?$v['alt']:$caption;$h.='<figure class="public-photo" data-public-photo="'.$e($m['id']).'"><button class="btn btn-solid theme-primary photo-open" type="button" data-lightbox data-group="'.$e($album['id']).'" data-gallery-index="'.($offset+$i+1).'" data-full="/media/'.$e($m['id']).'/large" data-caption="'.$e($caption).'" data-alt="'.$e($alt).'" aria-label="'.$e($caption).' groß anzeigen"><img src="/media/'.$e($m['id']).'/medium" srcset="/media/'.$e($m['id']).'/thumb '.$e($thumb['width']).'w, /media/'.$e($m['id']).'/medium '.$e($v['width']).'w" sizes="(max-width: 600px) 92vw, (max-width: 1000px) 46vw, 30vw" alt="'.$e($alt).'" width="'.$e($v['width']).'" height="'.$e($v['height']).'" loading="lazy" decoding="async"></button><noscript><a href="/media/'.$e($m['id']).'/large">Großes Bild öffnen</a></noscript><figcaption>'.$e($caption).MediaCredit::render($v).'</figcaption></figure>';}
        return $h.'</div>';
    }
    private function publicPager(string $slug,int $page,int $last,string $label,?\Closure $pageLink=null): string
    {
        if($last===1)return '';$target='/site/'.$slug;$href=static fn($n)=>($pageLink?$pageLink($n):$target.'?page='.$n).'#gallery-items';if($pageLink)$target=explode('?',$pageLink(1),2)[0];$h='<nav class="public-gallery-pager" aria-label="'.$label.'">';
        if($page>1)$h.='<a class="btn btn-outline theme-secondary" href="'.Html::e($href($page-1)).'">Zurück</a>';
        $h.='<form data-public-gallery-page method="get" action="'.Html::e($target).'#gallery-items"><label>'.$label.'<select class="form-control" name="page" aria-label="'.$label.'">';for($i=1;$i<=$last;$i++)$h.='<option value="'.$i.'"'.($i===$page?' selected':'').'>'.$i.' von '.$last.'</option>';$h.='</select></label><button class="btn btn-outline theme-secondary">Anzeigen</button></form>';
        if($page<$last)$h.='<a class="btn btn-outline theme-secondary" href="'.Html::e($href($page+1)).'">Weiter</a>';return $h.'</nav>';
    }
    public static function lightbox(?callable $interfaceText=null):string{$interfaceText??=static fn(string $source):string=>$source;return ('<dialog class="gallery-lightbox" id="gallery-lightbox" aria-label="'.Html::e($interfaceText('Große Bildansicht')).'"><button type="button" class="btn btn-solid theme-primary lightbox-close" data-lightbox-close aria-label="'.Html::e($interfaceText('Bildansicht schließen')).'">'.Html::e($interfaceText('Schließen ×')).'</button><button type="button" class="btn btn-solid theme-primary lightbox-prev" data-lightbox-step="-1" aria-label="'.Html::e($interfaceText('Vorheriges Bild')).'">‹</button><figure><img alt=""><figcaption></figcaption></figure><button type="button" class="btn btn-solid theme-primary lightbox-next" data-lightbox-step="1" aria-label="'.Html::e($interfaceText('Nächstes Bild')).'">›</button><div class="lightbox-footer"><p class="lightbox-counter" aria-live="polite"></p><p class="lightbox-status" role="status" hidden></p><a class="lightbox-reload" hidden>'.Html::e($interfaceText('Album neu öffnen')).'</a></div></dialog>');}
}
