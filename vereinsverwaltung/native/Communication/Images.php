<?php declare(strict_types=1);
namespace Kulturhus\Native\Communication;
use PDO;
use Kulturhus\Native\Shared\{Access,Db,Identity,Qr};
use Kulturhus\Native\Cms\{Media,UploadTransaction};
/** Private, immutable rendered images. Public access requires an expiring publication capability. */
final class Images
{
    private string $root;
    public function __construct(private PDO $db,private Access $access,private string $mediaRoot)
    {
        $this->root=dirname($mediaRoot).'/communication-images';
        if(is_link($this->root)||(!is_dir($this->root)&&!mkdir($this->root,0700,true)))throw new \RuntimeException('Privater Bildspeicher fehlt.');
    }
    public static function fingerprint(array $item):string
    {$copy=array_intersect_key($item,array_flip(['title','summary','body','body_html','kind','blocks','story','channels','destination_url','image_design','story_link_requested','video_design']));if(($item['kind']??null)==='carousel')$copy['carousel']=Carousel::normalize($item['carousel']??[]);return hash('sha256',json_encode($copy,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));}
    public function render(string $itemId):array
    {
        $this->access->require('social','edit');if($this->db->inTransaction())throw new \LogicException('Bildfassungen benötigen einen eigenen Speichervorgang.');$item=(new Workspace($this->db,$this->access,'social'))->get($itemId);if($item['kind']==='reel')throw new \DomainException('Für Reels eine originale Videofassung speichern.');$club=$this->access->club();$identity=Identity::forClub($this->db,$club);
        if($identity['name']===''||$identity['origin']==='')throw new \DomainException('Vereinsname und Domain zuerst einrichten.');
        if($item['kind']==='story'&&($series=(new StorySeries($this->db,$this->access,$this->mediaRoot))->render($item))!==null)return $series;
        if(!extension_loaded('gd')||!function_exists('imagettftext'))throw new \DomainException('PHP/GD mit FreeType erforderlich.');
        $font=dirname(__DIR__,2).'/resources/fonts/liberation-serif/LiberationSerif-Regular.ttf';if(!is_file($font))throw new \DomainException('Lokale Schrift fehlt.');
        $media=new Media($this->db,$this->access,$this->mediaRoot);$logo=$identity['logo_id']!==''?$media->variant($identity['logo_id'],'thumb'):null;
        if($item['kind']==='carousel')Carousel::count($item);
        $slides=$item['kind']==='carousel'?$item['carousel']:($item['kind']==='story'?($item['story']?:[['title'=>$item['title'],'text'=>$item['summary'],'media_ref'=>'']]):[['title'=>$item['title'],'text'=>$item['summary'],'media_ref'=>'']]);
        $default='';foreach($item['blocks'] as $b)if($b['type']==='image'){$default=$b['media_ref'];break;}
        $result=[];
        foreach($slides as $number=>$slide){
            $pictureId=($slide['media_ref']?:$default);$picture=$pictureId!==''?$media->variant($pictureId,'large'):null;$height=$item['kind']==='story'?1920:1350;
            $destination=($item['destination_url']??'')?:$identity['origin'];
            $design=ImageDesign::options($item['image_design']??[]);
            $refs=$identity['logo_id']!==''?[$identity['logo_id']]:[];if($pictureId!==''&&$design['layout']!=='poster')$refs[]=$pictureId;
            $sources=ImageUsage::capture($this->db,$club,$refs,'social');
            $attribution=ImageAttribution::plan($sources,$height);
            $im=ImageDesign::canvas($slide,$height,$picture,$logo,$identity,$destination,$design,$attribution);
            $id=bin2hex(random_bytes(16));$file=$this->root.'/'.$id.'.png';$jpeg=$this->root.'/'.$id.'.jpg';$databaseOwned=false;try{if(!imagepng($im,$file,6))throw new \RuntimeException('Bild konnte nicht gespeichert werden.');chmod($file,0600);if(!imagejpeg($im,$jpeg,92))throw new \RuntimeException('JPEG-Ausgabe nicht erzeugt.');chmod($jpeg,0600);
                $databaseOwned=true;$this->store([$file,$jpeg],function()use($item,$id,$club,$number,$file,$jpeg,$identity,$destination,$height,$design,$sources,$attribution){$this->access->require('social','edit');$current=(new Workspace($this->db,$this->access,'social'))->get($item['id'],true);$currentIdentityVersion=(int)Db::run($this->db,'SELECT version FROM native_club_settings WHERE club_id=? FOR SHARE',[$club])->fetchColumn();if($current['version']!==$item['version']||$currentIdentityVersion!==$identity['version'])throw new \DomainException('Fassung oder Vereinsdaten inzwischen geändert.');if(ImageUsage::capture($this->db,$club,array_keys($sources['media']),'social')!==$sources)throw new \DomainException('Bildangaben geändert. Bilder erneut erzeugen.');Db::run($this->db,"INSERT INTO native_social_images(id,club_id,item_id,item_version,content_hash,slide,kind,sha256,metadata_json,created_at) VALUES(?,?,?,?,?,?,?,?,JSON_SET(CAST(? AS JSON),'$.generated_at',DATE_FORMAT(UTC_TIMESTAMP(6),'%Y-%m-%d %H:%i:%s.%f')),UTC_TIMESTAMP(6))",[$id,$club,$item['id'],$item['version'],self::fingerprint($item),$number,$item['kind'],hash_file('sha256',$file),json_encode(['renderer'=>'php-gd-local','sources'=>$sources,'attribution'=>$attribution,'design'=>$design,'width'=>1080,'height'=>$height,'identity_version'=>$identity['version'],'destination'=>$destination,'jpeg_sha256'=>hash_file('sha256',$jpeg)],JSON_THROW_ON_ERROR)]);});
                $result[]=$id;
            }catch(\Throwable $e){if(!$databaseOwned)self::cleanup([$file,$jpeg??'']);throw $e;}finally{unset($im);}
        }return $result;
    }
    /** Copy the first event design, changing only the optional reminder artwork. */
    public function renderStoryTemplate(array $item,array $first,array $master,string $slot,string $locale,bool $override=false):string
    {
        $this->access->require('social','edit');
        if($this->db->inTransaction()||$item['kind']!=='story'||$first['kind']!=='story')throw new \DomainException('Eigene aktuelle Story-Vorlage auswählen.');
        $master=Db::run($this->db,'SELECT * FROM native_social_images WHERE club_id=? AND item_id=? AND id=?',[$this->access->club(),$first['id'],$master['id']??''])->fetch();
        if(!$master)throw new \DomainException('Eigene aktuelle Story-Vorlage auswählen.');
        $identity=Identity::forClub($this->db,$this->access->club());$meta=json_decode($master['metadata_json'],true,32,JSON_THROW_ON_ERROR);
        if(($meta['identity_version']??null)!==$identity['version']||!hash_equals($master['content_hash'],self::fingerprint($first)))throw new \DomainException('Das erste Story-Bild mit den aktuellen Vereinsdaten speichern.');
        $this->file($master['id']);$sources=$meta['sources']??[];$attribution=$meta['attribution']??[];$document=null;$variantPath=null;$variantHash=null;
        if(($meta['renderer']??'')==='canvas2d-local'){
            $expected=$meta['story_series']['sha256'][$slot]??null;$path=$this->root.'/'.$master['id'].'-'.$slot.'.png';
            if(($meta['story_series']['format']??null)!==1||!is_string($expected)||!preg_match('/\A[a-f0-9]{64}\z/D',$expected)||is_link($path)||!is_file($path)||!hash_equals($expected,hash_file('sha256',$path)))throw new \DomainException('Das erste Story-Bild als Vorlage speichern.');
            $original=@imagecreatefrompng($path);if(!$original||imagesx($original)!==1080||imagesy($original)!==1920)throw new \DomainException('Story-Vorlage ist nicht lesbar.');
            $variantPath=$path;$variantHash=$expected;
            $image=imagecreatetruecolor(1080,1920);imagefill($image,0,0,imagecolorallocate($image,255,255,255));imagecopy($image,$original,0,0,0,0,1080,1920);unset($original);ImageAttribution::draw($image,$attribution);
            $document=StorySeries::document($meta['document'],$slot,$locale);
        }elseif(($meta['renderer']??'')==='php-gd-local'){
            $slide=$first['story'][0]??null;if(!is_array($slide))throw new \DomainException('Das erste Story-Bild als Vorlage speichern.');
            $text=preg_replace('/\A(HEUTE|MORGEN|NOCH [0-9]{1,3} TAGE|TERMIN MERKEN|TODAY|TOMORROW|[0-9]{1,3} DAYS TO GO|SAVE THE DATE)(?:\s*·\s*|\R|\z)/u','',$slide['text'],1);
            $label=$slot==='base'?'':(StorySeries::labels($locale)[$slot]??throw new \DomainException('Story-Tageshinweis prüfen.'));
            $slide['text']=($label!==''?$label."\n":'').$text;$pictureId=$slide['media_ref']??'';
            if($pictureId==='')foreach($first['blocks']as$block)if($block['type']==='image'){$pictureId=$block['media_ref'];break;}
            $media=new Media($this->db,$this->access,$this->mediaRoot);$picture=$pictureId!==''?$media->variant($pictureId,'large'):null;$logo=$identity['logo_id']!==''?$media->variant($identity['logo_id'],'thumb'):null;
            $design=ImageDesign::options($meta['design']??$first['image_design']??[]);
            $image=ImageDesign::canvas($slide,1920,$picture,$logo,$identity,($first['destination_url']??'')?:$identity['origin'],$design,$attribution);
            if($design['layout']==='classic')$document=ClassicCanvas::document($slide,'story',$identity);
        }else throw new \DomainException('Das erste Story-Bild als Vorlage speichern.');
        $id=bin2hex(random_bytes(16));$png=$this->root.'/'.$id.'.png';$jpeg=$this->root.'/'.$id.'.jpg';$owned=false;
        try{
            if(!imagepng($image,$png,6)||!imagejpeg($image,$jpeg,92)||!chmod($png,0600)||!chmod($jpeg,0600))throw new \RuntimeException('Story-Bild konnte nicht gespeichert werden.');
            $owned=true;$this->store([$png,$jpeg],function()use($item,$first,$master,$identity,$sources,$attribution,$document,$slot,$locale,$override,$variantPath,$variantHash,$id,$png,$jpeg):void{
                $this->access->require('social','edit');$workspace=new Workspace($this->db,$this->access,'social',$this->mediaRoot);$current=$workspace->get($item['id'],true);$currentFirst=$workspace->get($first['id'],true);
                $series=new StorySeries($this->db,$this->access,$this->mediaRoot);$context=$series->context($item['id']);$selected=$series->masterImage($currentFirst);
                $currentSlot=($context['config']['story_day_hint']??false)?($context['preparation']['slot']??null):'base';
                $expectedFirst=$override?$current['id']:($context['first']??null);
                if($current['version']!==$item['version']||$currentFirst['version']!==$first['version']||$expectedFirst!==$first['id']||($selected['id']??null)!==$master['id']||$currentSlot!==$slot||($context['config']['locale']??null)!==$locale||Identity::forClub($this->db,$this->access->club())['version']!==$identity['version'])throw new \DomainException('Story-Vorlage inzwischen geändert. Neu öffnen.');
                if(!StorySeries::same(ImageUsage::capture($this->db,$this->access->club(),array_keys($sources['media']??[]),'social'),$sources))throw new \DomainException('Bildangaben geändert. Gestaltung erneut prüfen.');
                $this->file($master['id']);
                if($variantPath!==null&&(is_link($variantPath)||!is_file($variantPath)||!hash_equals($variantHash,hash_file('sha256',$variantPath))))throw new \DomainException('Story-Vorlage inzwischen geändert. Neu öffnen.');
                $metadata=['renderer'=>'story-master-local','sources'=>$sources,'attribution'=>$attribution,'width'=>1080,'height'=>1920,'identity_version'=>$identity['version'],'destination'=>($current['destination_url']??'')?:$identity['origin'],'jpeg_sha256'=>hash_file('sha256',$jpeg),'story_hint'=>$context['config']['story_day_hint'],'story_slot'=>$context['preparation']['slot'],'story_reference'=>['item'=>$first['id'],'version'=>$first['version'],'image'=>$master['id'],'sha256'=>$master['sha256'],'slot'=>$slot,'override'=>$override]];
                if($document!==null)$metadata['document']=$document;
                Db::run($this->db,"INSERT INTO native_social_images(id,club_id,item_id,item_version,content_hash,slide,kind,sha256,metadata_json,created_at)VALUES(?,?,?,?,?,0,'story',?,JSON_SET(CAST(? AS JSON),'$.generated_at',DATE_FORMAT(UTC_TIMESTAMP(6),'%Y-%m-%d %H:%i:%s.%f')),UTC_TIMESTAMP(6))",[$id,$this->access->club(),$current['id'],$current['version'],self::fingerprint($current),hash_file('sha256',$png),json_encode($metadata,JSON_THROW_ON_ERROR)]);
            });return $id;
        }catch(\Throwable $error){if(!$owned)self::cleanup([$png,$jpeg]);throw $error;}finally{unset($image);}
    }
    public function history(string $item):array
    {$history=new ImageHistory($this->db,$this->access);$page=$history->page($item);return $history->originals($item,array_column($page['rows'],'id'));}
    public function saveCanvas(string $itemId,int $version,int $identityVersion,int $slide,array $document,string $source,?string $requestKey=null,array $seriesSources=[],?bool $storyHint=null):string
    {
        $seriesHashes=StorySeries::sources($seriesSources);
        if($requestKey!==null)return(new CanvasSaveRequests($this->db,$this->access,$this->mediaRoot))->save($itemId,$version,$identityVersion,$slide,$document,$source,$requestKey,fn(callable $receipt):string=>$this->storeCanvas($itemId,$version,$identityVersion,$slide,$document,$source,$receipt,$seriesSources,$storyHint),$seriesHashes===[]?[]:['files'=>$seriesHashes,'hint'=>$storyHint]);
        return$this->storeCanvas($itemId,$version,$identityVersion,$slide,$document,$source,null,$seriesSources,$storyHint);
    }
    private function storeCanvas(string $itemId,int $version,int $identityVersion,int $slide,array $document,string $source,?callable $receipt=null,array $seriesSources=[],?bool $storyHint=null):string
    {
        $this->access->require('social','edit');if($this->db->inTransaction())throw new \LogicException('Bildfassungen benötigen einen eigenen Speichervorgang.');$item=(new Workspace($this->db,$this->access,'social'))->get($itemId);$identity=Identity::forClub($this->db,$this->access->club());
        if($item['version']!==$version||$identity['version']!==$identityVersion)throw new \DomainException('Inhalt oder Vereinsdaten inzwischen geändert. Gestaltung neu öffnen.');
        if($identity['name']===''||$identity['origin']==='')throw new \DomainException('Vereinsname und Domain zuerst einrichten.');
        $slideCount=$item['kind']==='carousel'?Carousel::count($item):max(1,count($item['story']??[]));
        if($slide<0||$slide>=$slideCount||(!in_array($item['kind'],['story','carousel'],true)&&$slide!==0))throw new \DomainException('Unbekannte Bildfolie.');
        $doc=CanvasDocument::validate($document,$item['kind']);$media=new Media($this->db,$this->access,$this->mediaRoot);
        $seriesHashes=StorySeries::sources($seriesSources);
        if($seriesSources!==[]&&($item['kind']!=='story'||$slide!==0||(new StorySeries($this->db,$this->access,$this->mediaRoot))->context($itemId)===null))throw new \DomainException('Die Story-Vorlage in der Veranstaltungsfolge speichern.');
        foreach($doc['layers'] as $layer)if($layer['type']==='image')try{$media->variant($layer['media_ref'],'large');}catch(\RuntimeException|\InvalidArgumentException $e){throw new \DomainException('Ein eigenes Quellbild ist nicht verfügbar.',0,$e);}
        $refs=[];foreach($doc['layers'] as $layer)if($layer['type']==='image')$refs[]=$layer['media_ref'];$sources=ImageUsage::capture($this->db,$this->access->club(),$refs,'social');
        if(is_link($source)||!is_file($source)||filesize($source)>5*1024*1024)throw new \DomainException('Bilddatei überschreitet 5 MiB.');
        $info=@getimagesize($source);if(!$info||$info[0]!==$doc['width']||$info[1]!==$doc['height']||!in_array($info['mime'],['image/png','image/jpeg'],true))throw new \DomainException('Tatsächliche Bildbytes müssen zum Post-/Storyformat passen.');
        $image=$info['mime']==='image/png'?@imagecreatefrompng($source):@imagecreatefromjpeg($source);if(!$image)throw new \DomainException('Bild kann nicht dekodiert werden.');
        $attribution=ImageAttribution::plan($sources,$doc['height']);
        $flat=imagecreatetruecolor($doc['width'],$doc['height']);imagefill($flat,0,0,imagecolorallocate($flat,255,255,255));imagecopy($flat,$image,0,0,0,0,$doc['width'],$doc['height']);unset($image);ImageAttribution::draw($flat,$attribution);
        $id=bin2hex(random_bytes(16));$png=$this->root.'/'.$id.'.png';$jpeg=$this->root.'/'.$id.'.jpg';$databaseOwned=false;$seriesPaths=$seriesSources===[]?[]:StorySeries::paths($this->root,$id);$files=[$png,$jpeg,...array_values($seriesPaths)];$seriesMeta=null;
        try{
            if(!imagepng($flat,$png,6)||!imagejpeg($flat,$jpeg,92))throw new \RuntimeException('Bildexport konnte nicht gespeichert werden.');chmod($png,0600);chmod($jpeg,0600);
            if($seriesSources!==[])$seriesMeta=StorySeries::retain($seriesSources,$seriesHashes,$seriesPaths);
            $databaseOwned=true;$this->store($files,function()use($itemId,$version,$identityVersion,$doc,$slide,$png,$jpeg,$id,$media,$sources,$attribution,$receipt,$seriesSources,$seriesHashes,$seriesMeta,$storyHint){
                $this->access->require('social','edit');$current=(new Workspace($this->db,$this->access,'social'))->get($itemId,true);$identity=Identity::forClub($this->db,$this->access->club());$currentIdentityVersion=(int)Db::run($this->db,'SELECT version FROM native_club_settings WHERE club_id=? FOR SHARE',[$this->access->club()])->fetchColumn();
                if($current['version']!==$version||$currentIdentityVersion!==$identityVersion)throw new \DomainException('Inhalt oder Vereinsdaten inzwischen geändert.');
                foreach($doc['layers'] as $layer)if($layer['type']==='image')try{$media->variant($layer['media_ref'],'large');}catch(\RuntimeException|\InvalidArgumentException $e){throw new \DomainException('Ein eigenes Quellbild ist nicht verfügbar.',0,$e);}
                if(ImageUsage::capture($this->db,$this->access->club(),array_keys($sources['media']),'social')!==$sources)throw new \DomainException('Bildangaben geändert. Gestaltung erneut prüfen.');
                $seriesContext=$seriesSources===[]?null:(new StorySeries($this->db,$this->access,$this->mediaRoot))->context($itemId);
                if($seriesSources!==[]&&($seriesContext===null||($storyHint!==null&&$storyHint!==$seriesContext['config']['story_day_hint'])||StorySeries::sources($seriesSources)!==$seriesHashes))throw new \DomainException('Story-Vorlage inzwischen geändert. Bild erneut speichern.');
                $metadata=['sources'=>$sources,'attribution'=>$attribution,'renderer'=>'canvas2d-local','document'=>$doc,'width'=>$doc['width'],'height'=>$doc['height'],'identity_version'=>$identityVersion,'destination'=>($current['destination_url']??'')?:$identity['origin'],'jpeg_sha256'=>hash_file('sha256',$jpeg)];
                if($seriesMeta!==null){$metadata['story_series']=$seriesMeta;$metadata['story_hint']=$seriesContext['config']['story_day_hint'];$metadata['story_slot']=$seriesContext['preparation']['slot'];}
                Db::run($this->db,"INSERT INTO native_social_images(id,club_id,item_id,item_version,content_hash,slide,kind,sha256,metadata_json,created_at) VALUES(?,?,?,?,?,?,?,?,JSON_SET(CAST(? AS JSON),'$.generated_at',DATE_FORMAT(UTC_TIMESTAMP(6),'%Y-%m-%d %H:%i:%s.%f')),UTC_TIMESTAMP(6))",[$id,$this->access->club(),$itemId,$version,self::fingerprint($current),$slide,$current['kind'],hash_file('sha256',$png),json_encode($metadata,JSON_THROW_ON_ERROR)]);
                if($receipt!==null)$receipt($id);
            });return $id;
        }catch(\Throwable $e){if(!$databaseOwned)self::cleanup($files);throw $e;}finally{unset($flat);}
    }
    private function store(array $files,callable $operation):mixed
    {
        return UploadTransaction::run($this->db,function($tx)use($files,$operation):mixed{
            $tx->onRollback($this->db,static fn()=>self::cleanup($files));
            return $operation();
        });
    }
    private static function cleanup(array $files):void
    {
        foreach($files as$file)if($file!==''&&is_file($file)&&!is_link($file))@unlink($file);
    }
    public function file(string $id):array
    {$this->access->require('social','read');Content::id($id);$row=Db::run($this->db,'SELECT sha256 FROM native_social_images WHERE club_id=? AND id=?',[$this->access->club(),$id])->fetch();return self::checkedFile($this->root,$id,$row?:[]);}
    public function thumbnail(string $id):string
    {
        $club=$this->access->require('social','read')['club_id'];$file=$this->file($id);$info=@getimagesize($file['path']);
        if(!$info||$info[0]!==1080||!in_array($info[1],[1350,1920],true))throw new \DomainException('Bildvorschau nicht verfügbar.');
        $source=@imagecreatefrompng($file['path']);if(!$source)throw new \DomainException('Bildvorschau nicht verfügbar.');$height=(int)round($info[1]*320/1080);$preview=imagecreatetruecolor(320,$height);
        try{imagecopyresampled($preview,$source,0,0,0,0,320,$height,1080,$info[1]);ob_start();try{if(!imagepng($preview))throw new \DomainException('Bildvorschau nicht verfügbar.');$bytes=ob_get_contents();}finally{ob_end_clean();}}finally{unset($preview,$source);}
        if($this->access->require('social','read')['club_id']!==$club)throw new \DomainException('Vereinszugehörigkeit geändert.');return $bytes;
    }
    public function jpeg(string $id):array
    {
        $this->file($id);$row=Db::run($this->db,'SELECT metadata_json FROM native_social_images WHERE club_id=? AND id=?',[$this->access->club(),$id])->fetch();
        $metadata=json_decode($row['metadata_json'],true,32,JSON_THROW_ON_ERROR);$path=$this->root.'/'.$id.'.jpg';$hash=$metadata['jpeg_sha256']??'';
        if(is_link($this->root)||is_link($path)||!is_file($path)||!hash_equals($hash,hash_file('sha256',$path)))throw new \DomainException('Freigegebenes JPEG nicht verfügbar.');
        return ['path'=>$path,'mime'=>'image/jpeg','sha256'=>$hash];
    }
    public static function checkedFile(string $root,string $id,array $row):array
    {Content::id($id);$file=$root.'/'.$id.'.png';if(!$row||is_link($root)||is_link($file)||!is_file($file)||!hash_equals($row['sha256'],hash_file('sha256',$file)))throw new \DomainException('Bild nicht verfügbar oder verändert.');return ['path'=>$file,'mime'=>'image/png'];}
    public static function publicFile(PDO $db,string $club,string $token,string $mediaRoot):?array
    {if(!preg_match('/^[a-f0-9]{64}$/D',$token))return null;$row=Db::run($db,'SELECT i.id,i.sha256,i.metadata_json,i.kind,c.format FROM native_image_capabilities c JOIN native_social_images i ON i.id=c.image_id AND i.club_id=c.club_id WHERE c.club_id=? AND c.token_hash=? AND (c.expires_at IS NULL OR c.expires_at>UTC_TIMESTAMP())',[$club,hash('sha256',$token)])->fetch();if(!$row)return null;if($row['kind']==='carousel'&&!CarouselPublication::publicActive($db,$club,$token,$row))return null;try{ImageUsage::image($db,$club,$row,false);}catch(\DomainException|\InvalidArgumentException){return null;}$root=dirname($mediaRoot).'/communication-images';if($row['format']==='jpeg'){$meta=json_decode($row['metadata_json'],true,32,JSON_THROW_ON_ERROR);$path=$root.'/'.$row['id'].'.jpg';if(is_link($root)||is_link($path)||!is_file($path)||!hash_equals($meta['jpeg_sha256']??'',hash_file('sha256',$path)))throw new \DomainException('JPEG-Bild nicht verfügbar.');return ['path'=>$path,'mime'=>'image/jpeg'];}return self::checkedFile($root,$row['id'],$row);}
}
