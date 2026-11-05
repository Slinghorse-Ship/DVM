<?php declare(strict_types=1);
namespace Kulturhus\Native\Integration;
use PDO;
use Kulturhus\Native\Shared\Access;
use Kulturhus\Native\Web\{ChatView,ContentModel,Html};
use Kulturhus\Native\Cms\Media;
use Kulturhus\Native\Communication\Workspace;
final class Endpoints
{
    public static function serve(PDO $db,Access $access,string $mediaRoot,string $csrf,array $query): void
    {
        header('Cache-Control: no-store');
        if(($query['native_endpoint']??null)==='cms-transfer'){\Kulturhus\Native\CmsTransfer\Controller::serve($db,$access,$mediaRoot,$csrf,$query,$_POST,$_FILES,$_SERVER['REQUEST_METHOD']??'GET');return;}
        if (in_array($query['native_endpoint'] ?? null, ['frontend-edit', 'frontend-preview'], true)) {
            \Kulturhus\Native\Web\FrontendEditor\Controller::serve($db,$access,$mediaRoot,$csrf,$query,$_POST,$_SERVER['REQUEST_METHOD']??'GET');
            return;
        }
        if(!in_array($_SERVER['REQUEST_METHOD']??'GET',['GET','HEAD'],true)){http_response_code(405);header('Allow: GET, HEAD');return;}
        $text=static function(string $key)use($query):string{if(!is_string($query[$key]??''))throw new \DomainException('Ungültige Anfrage.');return $query[$key]??'';};
        try {
            $endpoint=$text('native_endpoint');
            if($endpoint==='design-preview'){
                $html=(new \Kulturhus\Native\Web\Website\DesignPreview($db,$access,$mediaRoot,Routes::url('settings')))->render($text('template'));
                header('Content-Type: text/html; charset=utf-8');header('X-Robots-Tag: noindex, nofollow');if(($_SERVER['REQUEST_METHOD']??'GET')!=='HEAD')echo $html;return;
            }
            if($endpoint==='sections-preview'){
                $mediaUrl=static fn($id)=>Routes::endpoint('media-preview',['media'=>$id,'variant'=>'medium']);
                $html=(new \Kulturhus\Native\Web\Website\ContentSectionsPreview($db,$access,$mediaRoot,Routes::url('website'),$mediaUrl))->render($text('item'));
                header('Content-Type: text/html; charset=utf-8');header('X-Robots-Tag: noindex, nofollow');if(($_SERVER['REQUEST_METHOD']??'GET')!=='HEAD')echo $html;return;
            }
            if($endpoint==='home-preview'){
                $mediaUrl=static fn($id)=>Routes::endpoint('media-preview',['media'=>$id,'variant'=>'medium']);
                $html=(new \Kulturhus\Native\Web\Website\HomePreview($db,$access,$mediaRoot,Routes::url('website'),$mediaUrl))->render($text('item'));
                header('Content-Type: text/html; charset=utf-8');header('X-Robots-Tag: noindex, nofollow');
                if(($_SERVER['REQUEST_METHOD']??'GET')!=='HEAD')echo $html;return;
            }
            if($endpoint==='reference-options'){
                $data=(new \Kulturhus\Native\Cms\ReferenceCatalog($db,$access))->query($query);
                header('Content-Type: application/json; charset=utf-8');
                if(($_SERVER['REQUEST_METHOD']??'GET')!=='HEAD')echo json_encode($data,JSON_THROW_ON_ERROR);
                return;
            }
            if(in_array($endpoint,['page-photo-status','album-photo-status'],true)){
                $data=$endpoint==='album-photo-status'?(new \Kulturhus\Native\Web\Website\AlbumPhotoStatus($db,$access))->query($query):(new \Kulturhus\Native\Web\Website\PagePhotoStatus($db,$access))->query($query);
                if(isset($data['image']))foreach(['url','edit_url']as$key)$data['image'][$key]=Routes::translate($data['image'][$key]);
                header('Content-Type: application/json; charset=utf-8');
                if(($_SERVER['REQUEST_METHOD']??'GET')!=='HEAD')echo json_encode($data,JSON_THROW_ON_ERROR);
                return;
            }
            if($endpoint==='social-credit'){
                $version=$text('version');if(!preg_match('/^(0|[1-9][0-9]{0,9})$/D',$version))throw new \DomainException('Bildversion prüfen.');
                $bytes=\Kulturhus\Native\Communication\ImageAttribution::preview($db,$access,$text('media'),(int)$version);
                header('Content-Type: image/png');header('X-Content-Type-Options: nosniff');header('Content-Length: '.strlen($bytes));if(($_SERVER['REQUEST_METHOD']??'GET')!=='HEAD')echo $bytes;return;
            }
            if($endpoint==='social-qr'){
                $item=(new Workspace($db,$access,'social'))->get($text('item'));$identity=\Kulturhus\Native\Shared\Identity::forClub($db,$access->club());$destination=($item['destination_url']??'')?:$identity['origin'];
                \Kulturhus\Native\Communication\Content::url($destination);$bytes=\Kulturhus\Native\Shared\Qr::png($destination);
                header('Content-Type: image/png');header('X-Content-Type-Options: nosniff');header('Content-Length: '.strlen($bytes));if(($_SERVER['REQUEST_METHOD']??'GET')!=='HEAD')echo $bytes;return;
            }
            if($endpoint==='social-remote-image'){
                $box=\Kulturhus\Native\Shared\SecretBox::fromFile(getenv('KH_NATIVE_PRIVATE_KEY_FILE')?:dirname(__DIR__,3).'/.local/native-cms-integration/private.key');
                $bytes=(new \Kulturhus\Native\Communication\SocialHub($db,$box))->thumbnail($access,$text('object'));
                header('Content-Type: image/png');header('X-Content-Type-Options: nosniff');header('Content-Length: '.strlen($bytes));if(($_SERVER['REQUEST_METHOD']??'GET')!=='HEAD')echo $bytes;return;
            }
            if($endpoint==='model-fields'){
                header('Content-Type: application/json; charset=utf-8');echo json_encode(['html'=>(new ContentModel($db,$access,$csrf))->fields($text('template')?:'standard')],JSON_THROW_ON_ERROR);return;
            }
            if($endpoint==='chat-status'){
                header('Content-Type: application/json; charset=utf-8');echo json_encode((new ChatView($db,$access,dirname($mediaRoot).'/chat-files'))->status($text('item'),$query),JSON_THROW_ON_ERROR);return;
            }
            if($endpoint==='chat-view'){
                $id=$text('item');$view=new EmbeddedManager(new \Kulturhus\Native\Web\Manager($db,$access,$mediaRoot,$csrf,false,true),$access);
                header('Content-Type: application/json; charset=utf-8');echo json_encode(['html'=>$view->main('chat',$id),'url'=>Routes::url('chat',$id)],JSON_THROW_ON_ERROR);return;
            }
            if($endpoint==='preview'){
                $item=\Kulturhus\Native\Web\Communication\EditionPreview::item($db,$access,$text('module'),$text('item'),$query);if(!array_key_exists('revision',$query))$item['_identity']=\Kulturhus\Native\Shared\Identity::forClub($db,$access->club());
                $image=static fn($id)=>Routes::endpoint('edition-image',['module'=>$item['module'],'item'=>$item['id'],'revision'=>$item['version'],'media'=>$id]);
                if($item['module']==='social')$image=static function($id)use($db,$access,$mediaRoot){(new Media($db,$access,$mediaRoot))->variant($id,'medium');return Routes::endpoint('media-preview',['media'=>$id,'variant'=>'medium']);};
                elseif(($item['_identity']['logo_id']??'')!==''&&!isset($item['_media_usage']['media'][$item['_identity']['logo_id']]))$item['_identity']['logo_id']='';
                $html=\Kulturhus\Native\Web\Communication\EditionPreview::render($item,$image,['/vereinsverwaltung/style.css','/vereinsverwaltung/native-richtext.css','/vereinsverwaltung/native-compose-touch.css'],\Kulturhus\Native\Web\Communication\PressPhotoLinks::render($db,$access,$item).($item['module']==='newsletter'?\Kulturhus\Native\Web\Communication\PdfAttachments::links($item):''));
                header('X-Robots-Tag: noindex, nofollow');header('Content-Type: text/html; charset=utf-8');if(($_SERVER['REQUEST_METHOD']??'GET')!=='HEAD')echo$html;return;
            }
            if($endpoint==='edition-document'){
                $item=\Kulturhus\Native\Web\Communication\EditionPreview::item($db,$access,'newsletter',$text('item'),$query);
                $file=(new \Kulturhus\Native\Communication\DocumentAttachments($db,$access,$mediaRoot))->file($item,$text('document'));
                header('Content-Type: application/pdf');header('Content-Length: '.$file['bytes']);header('X-Content-Type-Options: nosniff');header('X-Robots-Tag: noindex, nofollow');header("Content-Disposition: attachment; filename=\"Newsletter.pdf\"; filename*=UTF-8''".rawurlencode($file['name']));if(($_SERVER['REQUEST_METHOD']??'GET')!=='HEAD')readfile($file['path']);return;
            }
            if($endpoint==='edition-image'){
                $file=\Kulturhus\Native\Web\Communication\EditionPreview::image($db,$access,$mediaRoot,$query);header('Content-Type: '.$file['mime']);header('Content-Length: '.$file['bytes']);header('X-Content-Type-Options: nosniff');header('X-Robots-Tag: noindex, nofollow');if(isset($file['attachment_name']))header("Content-Disposition: attachment; filename=\"Pressefoto\"; filename*=UTF-8''".rawurlencode($file['attachment_name']));if(($_SERVER['REQUEST_METHOD']??'GET')!=='HEAD')readfile($file['path']);return;
            }
            if($endpoint==='website-document-preview'){
                $file=(new \Kulturhus\Native\Cms\WebsiteDocuments($db,$access,dirname($mediaRoot).'/website-documents'))->file($text('document'));
                \Kulturhus\Native\Web\DocumentResponse::send($file,$_SERVER['REQUEST_METHOD']??'GET');return;
            }
            if($endpoint==='file-preview'){
                $file=(new \Kulturhus\Native\Files\FileReferences($db,$access,dirname($mediaRoot).'/files',dirname($mediaRoot).'/website-documents',$mediaRoot))->file($text('revision'));
                FileRoutes::respond($file,$_SERVER['REQUEST_METHOD']??'GET');return;
            }
            if($endpoint==='social-video'){
                $file=(new \Kulturhus\Native\Communication\Videos($db,$access,$mediaRoot))->file($text('video'));
                \Kulturhus\Native\Web\VideoResponse::serve($file,$_SERVER['REQUEST_METHOD']??'GET',$_SERVER['HTTP_RANGE']??null);return;
            }
            if($endpoint==='social-image'&&!in_array($text('variant'),['','thumb'],true))throw new \DomainException('Bildvariante prüfen.');
            if($endpoint==='social-image'&&$text('variant')==='thumb'){
                $bytes=(new \Kulturhus\Native\Communication\Images($db,$access,$mediaRoot))->thumbnail($text('image'));header('Content-Type: image/png');header('X-Content-Type-Options: nosniff');header('Content-Length: '.strlen($bytes));if(($_SERVER['REQUEST_METHOD']??'GET')!=='HEAD')echo $bytes;return;
            }
            if($endpoint==='social-image'){
                $file=(new \Kulturhus\Native\Communication\Images($db,$access,$mediaRoot))->file($text('image'));
            }elseif($endpoint==='media-storage'){
                if($text('variant')!=='thumb')throw new \DomainException('Private Bildvariante prüfen.');$file=(new \Kulturhus\Native\Cms\MediaStorageInventory($db,$access,$mediaRoot))->thumbnail($text('media'));
            }elseif($endpoint==='media-removed'){
                if($text('variant')!=='thumb')throw new \DomainException('Private Bildvariante prüfen.');$file=(new \Kulturhus\Native\Cms\MediaRemoval($db,$access,$mediaRoot))->thumbnail($text('media'));
            }elseif($endpoint==='media-preview'){
                $file=(new Media($db,$access,$mediaRoot))->variant($text('media'),$text('variant'));
            } elseif(in_array($endpoint,['chat-file','chat-image'],true)){
                $seq=$text('seq');if(!preg_match('/^[1-9][0-9]{0,17}$/D',$seq))throw new \DomainException('Ungültiger Anhang.');
                $ordinal=array_key_exists('attachment',$query)?$text('attachment'):'1';if(!preg_match('/\A(?:[1-9]|1[0-9]|20)\z/D',$ordinal))throw new \DomainException('Eigenen Anhang auswählen.');
                $file=(new ChatView($db,$access,dirname($mediaRoot).'/chat-files'))->attachment($text('item'),(int)$seq,(int)$ordinal);
                if($endpoint==='chat-image'&&!in_array($file['mime'],['image/png','image/jpeg','image/webp'],true))throw new \DomainException('Bild nicht verfügbar.');
                if($endpoint==='chat-file')header("Content-Disposition: attachment; filename=Anhang; filename*=UTF-8''".rawurlencode($file['name']));
            } else {http_response_code(404);return;}
            header('Content-Type: '.$file['mime']);header('X-Content-Type-Options: nosniff');header("Content-Security-Policy: sandbox; default-src 'none'; frame-ancestors 'none'");
            header('Content-Length: '.filesize($file['path']));if(($_SERVER['REQUEST_METHOD']??'GET')!=='HEAD')readfile($file['path']);
        } catch(\DomainException|\InvalidArgumentException $error) {
            http_response_code(403);header('Content-Type: application/json; charset=utf-8');echo json_encode(['error'=>'Inhalt für diesen Zugang nicht verfügbar.'],JSON_THROW_ON_ERROR);
        }
    }
}
