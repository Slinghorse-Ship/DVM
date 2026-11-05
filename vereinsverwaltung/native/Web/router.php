<?php declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Kulturhus\Native\{TestSupport};
use Kulturhus\Native\Shared\{Access,HttpValidators};
use Kulturhus\Native\Web\{Manager,Site,Cache,Html,Gallery,Feeds,FeedResponse,PublicSearchView,PublicLayout,ChatView,ImageStudioAssets,PublicAssets,PathRedirect};
use Kulturhus\Native\Cms\{Pages,Media};
use Kulturhus\Native\Communication\Workspace;

// This entry point is deliberately unusable on a normal/public web server.
$port=(string)getenv('KH_NATIVE_DEMO_PORT');
$origin='http://127.0.0.1:'.$port;
if(PHP_SAPI!=='cli-server'||getenv('KH_NATIVE_LOCAL_DEMO')!=='synthetic'||!preg_match('/^\d{4,5}$/D',$port)||in_array((int)$port,[8091,8092],true)||($_SERVER['REMOTE_ADDR']??'')!=='127.0.0.1'||($_SERVER['HTTP_HOST']??'')!=='127.0.0.1:'.$port){http_response_code(403);exit('Nur lokale synthetische Demo.');}
header('X-Content-Type-Options: nosniff');header('Referrer-Policy: no-referrer');header('X-Frame-Options: SAMEORIGIN');
header("Content-Security-Policy: default-src 'none'; manifest-src 'self'; style-src 'self'; style-src-attr 'unsafe-inline'; script-src 'self'; worker-src 'self'; img-src 'self' data:; connect-src 'self'; media-src 'self' blob:; form-action 'self'; base-uri 'none'; frame-src 'self'; frame-ancestors 'self'");
header('X-Robots-Tag: noindex, nofollow');
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH)?:'/';if(rtrim($path,'/')==='/sitemap')$path='/sitemap.xml';$method=$_SERVER['REQUEST_METHOD']??'GET';
if(!in_array($method,['GET','POST','HEAD'],true)&&!\Kulturhus\Native\Integration\ApiRoutes::matches($path)&&!($method==='OPTIONS'&&in_array(rtrim($path,'/'),['/feeds/events.json','/feeds/veranstaltungen'],true))){http_response_code(405);header('Allow: GET, HEAD, POST');exit;}
if($method==='POST'&&!\Kulturhus\Native\Integration\ApiRoutes::matches($path)&&isset($_SERVER['HTTP_ORIGIN'])&&$_SERVER['HTTP_ORIGIN']!==$origin
    &&!($_SERVER['HTTP_ORIGIN']==='null'&&($_SERVER['HTTP_SEC_FETCH_SITE']??'')==='same-origin')){http_response_code(403);exit('Ungültiger Ursprung.');}
$sharedAssets=PublicAssets::sources()+['/assets/account.css'=>__DIR__.'/assets/account.css','/assets/file-library.css'=>dirname(__DIR__).'/Files/Web/library.css'];
if(isset($sharedAssets[$path])){
    if($method==='POST'){http_response_code(405);exit;}
    $type=PublicAssets::mime($sharedAssets[$path]);
    header('Content-Type: '.$type);header('Cache-Control: no-cache');if($method!=='HEAD')readfile($sharedAssets[$path]);exit;
}
if(str_starts_with($path,'/assets/')&&\Kulturhus\Native\Web\PhotoImportAssets::serve(substr($path,8)))exit;
if(str_starts_with($path,'/assets/')&&\Kulturhus\Native\Web\ReelStudioAssets::serve(substr($path,8)))exit;
$editorAsset = ImageStudioAssets::sources()[substr($path, 8)] ?? null;
if(str_starts_with($path, '/assets/') && ($editorAsset !== null || in_array($path,['/assets/compose-touch.css','/assets/social-library.css','/assets/social-calendar.css','/assets/editorial-hub.css','/assets/press-recipients.js','/assets/newsletter-save.js','/assets/press-recipients.css','/assets/articles.css','/assets/native.css','/assets/native.js','/assets/reference-picker.js','/assets/page-live.js','/assets/page-live.css','/assets/page-media-picker.js','/assets/page-media-picker.css','/assets/field-conditions.js','/assets/field-groups.js','/assets/typed-fields.js','/assets/field-groups.css','/assets/typed-fields.css','/assets/template-settings.css','/assets/page-versions.css','/assets/content-lists.css','/assets/content-sections.css','/assets/editor.css','/assets/editor.js','/assets/gallery.css','/assets/media-library.css','/assets/gallery.js','/assets/richtext.css','/assets/richtext.js','/assets/chat.css','/assets/chat.js','/assets/chat-worker.js','/assets/communication-contacts.css','/assets/publications.css','/assets/publications.js'],true))){
    if($method==='POST'){http_response_code(405);exit;}
    header('Content-Type: '.(str_ends_with($path,'.css')?'text/css':'text/javascript').'; charset=utf-8');header('Cache-Control: no-cache');readfile($editorAsset !== null ? __DIR__.'/assets/'.$editorAsset : __DIR__.$path);exit;
}
if (str_starts_with($path,'/vereinsverwaltung/') && $path!=='/vereinsverwaltung/') {
    if (\Kulturhus\Native\Integration\Assets::serve(substr($path,strlen('/vereinsverwaltung/')))) exit;
}
$json=str_contains($_SERVER['HTTP_ACCEPT']??'','application/json');
try {
    $db=TestSupport::db();$config=TestSupport::config();$runtime=dirname((string)getenv('KH_NATIVE_TEST_CONFIG')).'/runtime-'.$config['database'];
    if(!is_dir($runtime)&&!mkdir($runtime,0700,true))throw new RuntimeException('Laufzeitverzeichnis fehlt.');
    if(!is_dir($runtime.'/sessions'))mkdir($runtime.'/sessions',0700);
    session_save_path($runtime.'/sessions');session_name('KHNATIVE'.substr(hash('sha256',$config['database']),0,12));
    // PHP storage must outlive every supported personal session. Token expiry,
    // logout and revocation remain enforced by Auth, as in ManagerSession.
    ini_set('session.gc_maxlifetime',(string)\Kulturhus\Native\Auth\Sessions::MAX_SECONDS);
    session_start(['use_strict_mode'=>1,'cookie_httponly'=>1,'cookie_samesite'=>'Strict','cookie_path'=>'/']);
    if(str_starts_with($path,'/newsletter')){
        $key=getenv('KH_NATIVE_PRIVATE_KEY_FILE')?:dirname(__DIR__,3).'/.local/native-cms-integration/private.key';
        if(!is_file($key)){http_response_code(503);exit('Newsletter noch nicht eingerichtet.');}
        if(\Kulturhus\Native\Web\NewsletterEndpoints::serve($db,'synthetic-a',\Kulturhus\Native\Shared\SecretBox::fromFile($key),$runtime.'/media',$origin,new Access($db,'reader')))exit;
    }
    $_SESSION['native_actor']??='admin';$_SESSION['native_csrf']??=bin2hex(random_bytes(32));
    $secureAccounts=getenv('KH_NATIVE_REQUIRE_LOGIN')==='1';
    $auth=null;$signedIn=null;
    if(is_file($runtime.'/auth.key')){
        $auth=Kulturhus\Native\Auth\Runtime::service($db,$runtime.'/auth.key');
        if(!empty($_SESSION['auth_token']))try{$signedIn=$auth->resolve($_SESSION['auth_token']);}catch(DomainException){unset($_SESSION['auth_token']);}
    }
    $fixedManagerClub=getenv('KH_NATIVE_DEMO_MANAGER_CLUB')?:'synthetic-a';
    if(\Kulturhus\Native\Web\MemberCardPublicPage::serve($db,$fixedManagerClub))exit;
    if (\Kulturhus\Native\Api\NativeEntry::serve($db,$fixedManagerClub,$origin,$runtime.'/media',is_string($_SESSION['auth_token']??null)&&$signedIn?$_SESSION['auth_token']:null,$_SESSION['native_csrf'])) exit;
    if (\Kulturhus\Native\System\NativeEntry::serve($db,$fixedManagerClub,$runtime,is_string($_SESSION['auth_token']??null)&&$signedIn?$_SESSION['auth_token']:null,$_SESSION['native_csrf'])) exit;
    if(\Kulturhus\Native\Integration\CommentsRoutes::serve($db,new Access($db,'reader'),$runtime,$fixedManagerClub,$origin,$_SESSION['native_csrf']))exit;
    if(str_starts_with($path,'/extensions/')){
        require_once dirname(__DIR__,2).'/src/bootstrap.php';
        (new Access($db,'reader'))->require('website','read');
        $extensionResponse=(new \Kulturhus\Native\Extensions\Runtime($db,$fixedManagerClub))->url($method,$path);
        if(!$extensionResponse){http_response_code(404);header('Cache-Control: no-store');exit;}
        http_response_code($extensionResponse['status']);foreach($extensionResponse['headers']as$name=>$value)header($name.': '.$value);
        if($method!=='HEAD')echo $extensionResponse['body'];exit;
    }
    $accountPath=rtrim($path,'/');
    if (\Kulturhus\Native\Web\LoginAliases::serve($method,$accountPath,$_GET)) exit;
    if(in_array($accountPath,['/login','/account','/invite','/reset','/forgot'],true)){
        header('Cache-Control: no-store');header('Content-Type: text/html; charset=utf-8');
        if(!$auth){http_response_code(503);exit('Die Anmeldung ist nicht eingerichtet.');}
        $accountView=Kulturhus\Native\Web\Account::forNative($db,$auth,$_SESSION['native_csrf'],hash('sha256',$origin.'|'.($_SERVER['REMOTE_ADDR']??'')));
        $accountView->withSystemNavigation(\Kulturhus\Native\System\Navigation::resolver($db));
        $state=[];$error='';
        if($method==='POST')try{$state=$accountView->action($_POST,$_SESSION);}catch(\Kulturhus\Native\Appearance\Conflict $failure){http_response_code(409);$error=$failure->getMessage();}catch(DomainException $failure){http_response_code(422);$error=$failure->getMessage();}
        if(isset($state['redirect'])){header('Location: '.$state['redirect'],true,303);exit;}
        echo $accountView->render($accountPath,$_GET,$_SESSION,$state,$error);exit;
    }
    $publicAlias=\Kulturhus\Native\Web\PublicRoutes::routingAlias($path);
    if(str_starts_with($path,'/tickets/')){
        $key=getenv('KH_NATIVE_PRIVATE_KEY_FILE')?:dirname(__DIR__,3).'/.local/native-cms-integration/private.key';
        if(!is_file($key)){http_response_code(503);exit('Ticketservice nicht eingerichtet.');}
        if(\Kulturhus\Native\Web\Tickets\PublicRoutes::serve($db,'synthetic-a',\Kulturhus\Native\Shared\SecretBox::fromFile($key),$origin,new Access($db,'reader')))exit;
    }
    if($publicAlias!==null)$path='/site/'.$publicAlias;
    $publicRequest=\Kulturhus\Native\Web\WebsiteManifest::owns($path)||str_starts_with($path,'/site/')||str_starts_with($path,'/media/')||str_starts_with($path,'/website-document/')||str_starts_with($path,'/file/')||str_starts_with($path,'/publication-image/')||str_starts_with($path,'/publication-video/')||str_starts_with($path,'/meta-webhook/')||str_starts_with($path,'/feeds/')||in_array($path,['/robots.txt','/sitemap.xml','/search'],true);
    if(str_starts_with($path,'/meta-webhook/')){
        $key=getenv('KH_NATIVE_PRIVATE_KEY_FILE');if(!$key||!is_file($key)){http_response_code(503);exit;}
        if(\Kulturhus\Native\Web\MetaWebhookEndpoints::serve($db,'synthetic-a',\Kulturhus\Native\Shared\SecretBox::fromFile($key),$path))exit;
    }
    if($secureAccounts&&!$publicRequest&&!$signedIn){
        if($json){http_response_code(401);header('Content-Type: application/json');echo '{"error":"Bitte erneut anmelden."}';}
        else header('Location: /login',true,303);
        exit;
    }
    $access=$secureAccounts&&$signedIn?new Kulturhus\Native\Auth\SessionAccess($db,$auth,$_SESSION['auth_token']):new Access($db,$_SESSION['native_actor']);$mediaRoot=$runtime.'/media';
    if(\Kulturhus\Native\Integration\FileRoutes::serve($db,new Access($db,'reader'),$mediaRoot,$path,$method,true))exit;
    if(\Kulturhus\Native\Integration\FileRoutes::serve($db,$access,$mediaRoot,$path,$method,false))exit;
    if (rtrim($path,'/')==='/vereinsverwaltung') {
        header('Cache-Control: no-store');
        if (!$signedIn) { http_response_code(401); exit('Bitte erneut anmelden.'); }
        $endpoint=$_GET['native_endpoint']??null;
        if (is_string($endpoint) && in_array($endpoint,['cms-transfer','frontend-edit','frontend-preview','media-preview','website-document-preview','file-preview','reference-options'],true)) {
            $endpointAccess=new Kulturhus\Native\Auth\SessionAccess($db,$auth,$_SESSION['auth_token']);
            \Kulturhus\Native\Integration\Endpoints::serve($db,$endpointAccess,$mediaRoot,$_SESSION['native_csrf'],$_GET);
            exit;
        }
        $item=$_GET['native_item']??'';
        if (in_array($method,['GET','HEAD'],true) && ($_GET['view']??null)==='website' && ($_GET['tab']??null)==='technical' && $item==='') {
            header('Location: /?'.http_build_query(['view'=>'model',...\Kulturhus\Native\Cms\ModelCatalogue::state($_GET)]),true,303);exit;
        }
        if (in_array($method,['GET','HEAD'],true) && ($_GET['view']??null)==='website' && ($_GET['tab']??null)==='pages' && is_string($item) && ($item==='' || preg_match('/\A[a-f0-9]{32}\z/D',$item))) {
            header('Location: /?'.http_build_query(['view'=>'website',...($item!==''?['item'=>$item]:[])]),true,303);exit;
        }
        http_response_code(404);exit;
    }

    if($accountPath==='/accounts'){
        if(!$auth||!$signedIn){header('Location: /login',true,303);exit;}
        $accountAccess=new Kulturhus\Native\Auth\SessionAccess($db,$auth,$_SESSION['auth_token']);
        $view=new Kulturhus\Native\Web\Accounts($db,$auth,$accountAccess,$_SESSION['native_csrf']);$state=[];$error='';
        if($method==='POST')try{$state=$view->action($_POST);}catch(\Kulturhus\Native\Auth\AccountOperationUnconfirmed $failure){http_response_code(503);$error=$failure->getMessage();}catch(DomainException $failure){http_response_code(422);$error=$failure->getMessage();}
        header('Cache-Control: no-store');header('Content-Type: text/html; charset=utf-8');echo $view->render($state,$error,$_GET);exit;
    }
    if(preg_match('~^/social-video/([a-f0-9]{32})$~D',$path,$video)){
        $file=(new \Kulturhus\Native\Communication\Videos($db,$access,$mediaRoot))->file($video[1]);
        \Kulturhus\Native\Web\VideoResponse::serve($file,$method,$_SERVER['HTTP_RANGE']??null);exit;
    }
    if(preg_match('~^/social-image/([a-f0-9]{32})/thumb$~D',$path,$thumb)){
        if(!in_array($method,['GET','HEAD'],true)){http_response_code(405);header('Allow: GET, HEAD');exit;}
        $bytes=(new Kulturhus\Native\Communication\Images($db,$access,$mediaRoot))->thumbnail($thumb[1]);header('Content-Type: image/png');header('Cache-Control: no-store');header('Content-Length: '.strlen($bytes));if($method!=='HEAD')echo $bytes;exit;
    }
    if(str_starts_with($path,'/publication-video/')){
        if(!in_array($method,['GET','HEAD'],true)){http_response_code(405);exit;}
        $file=\Kulturhus\Native\Communication\VideoPublication::publicFile($db,'synthetic-a',substr($path,19),$mediaRoot);
        if(!$file){http_response_code(404);exit;}\Kulturhus\Native\Web\VideoResponse::serve($file,$method,$_SERVER['HTTP_RANGE']??null);exit;
    }
    if(str_starts_with($path,'/social-image/')||str_starts_with($path,'/publication-image/')){
        if($method==='POST'){http_response_code(405);exit;}
        $file=str_starts_with($path,'/social-image/')?(new Kulturhus\Native\Communication\Images($db,$access,$mediaRoot))->file(substr($path,14)):Kulturhus\Native\Communication\Images::publicFile($db,'synthetic-a',substr($path,19),$mediaRoot);
        if(!$file){http_response_code(404);exit;}header('Content-Type: '.$file['mime']);header('Cache-Control: no-store');if($method!=='HEAD')readfile($file['path']);exit;
    }
    if($path==='/social-credit'){
        if(!in_array($method,['GET','HEAD'],true)){http_response_code(405);header('Allow: GET, HEAD');exit;}
        $id=$_GET['media']??'';$version=$_GET['version']??'';if(!is_string($id)||!is_string($version)||!preg_match('/^(0|[1-9][0-9]{0,9})$/D',$version))throw new DomainException('Bildversion prüfen.');
        $bytes=Kulturhus\Native\Communication\ImageAttribution::preview($db,$access,$id,(int)$version);
        header('Content-Type: image/png');header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');header('Content-Length: '.strlen($bytes));if($method!=='HEAD')echo $bytes;exit;
    }
    if($path==='/social-qr'){
        if($method==='POST'){http_response_code(405);header('Allow: GET, HEAD');exit;}
        $id=$_GET['item']??'';if(!is_string($id))throw new DomainException('Inhaltskennung prüfen.');
        $item=(new Workspace($db,$access,'social'))->get($id);$identity=Kulturhus\Native\Shared\Identity::forClub($db,$access->club());
        $destination=($item['destination_url']??'')?:$identity['origin'];Kulturhus\Native\Communication\Content::url($destination);$bytes=Kulturhus\Native\Shared\Qr::png($destination);
        header('Content-Type: image/png');header('Cache-Control: no-store');header('Content-Length: '.strlen($bytes));if($method!=='HEAD')echo $bytes;exit;
    }
    if(str_starts_with($path,'/chat-file/')||str_starts_with($path,'/chat-image/')){
        if($method==='POST'){http_response_code(405);exit;}
        if(!preg_match('~^/(chat-file|chat-image)/([a-f0-9]{32})/([1-9][0-9]{0,17})$~D',$path,$match)){http_response_code(404);exit;}
        $ordinal=$_GET['attachment']??'1';if(!is_string($ordinal)||!preg_match('/\A(?:[1-9]|1[0-9]|20)\z/D',$ordinal))throw new DomainException('Eigenen Anhang auswählen.');
        $file=(new ChatView($db,$access,$runtime.'/chat-files'))->attachment($match[2],(int)$match[3],(int)$ordinal);
        if($match[1]==='chat-image'&&!in_array($file['mime'],['image/jpeg','image/png','image/webp'],true)){http_response_code(404);exit;}
        header('Content-Type: '.$file['mime']);header('Cache-Control: no-store');header("Content-Security-Policy: sandbox; default-src 'none'; frame-ancestors 'none'");
        if($match[1]==='chat-file')header("Content-Disposition: attachment; filename=\"Anhang\"; filename*=UTF-8''".rawurlencode($file['name']));
        header('Content-Length: '.filesize($file['path']));if($method!=='HEAD')readfile($file['path']);exit;
    }
    if($path==='/chat-status'){
        if($method==='POST'){http_response_code(405);exit;}
        header('Cache-Control: no-store');header('Content-Type: application/json');
        echo json_encode((new ChatView($db,$access,$runtime.'/chat-files'))->status((string)($_GET['item']??''),$_GET),JSON_THROW_ON_ERROR);exit;
    }
    if(\Kulturhus\Native\Web\WebsiteManifest::serve($db,new Access($db,'reader'),$path,$method))exit;
    if($path==='/robots.txt'){header('Content-Type: text/plain');echo "User-agent: *\nDisallow: /\n";exit;}
    if(str_starts_with($path,'/website-document/')||str_starts_with($path,'/website-document-preview/')){
        $file=null;
        if(preg_match('~^/(website-document|website-document-preview)/([a-f0-9]{32})$~D',$path,$match)){
            $docs=new \Kulturhus\Native\Cms\WebsiteDocuments($db,$match[1]==='website-document'?new Access($db,'reader'):$access,dirname($mediaRoot).'/website-documents');
            try{$file=$match[1]==='website-document'?$docs->publicFile($match[2]):$docs->file($match[2]);}catch(DomainException){}
        }
        \Kulturhus\Native\Web\DocumentResponse::send($file,$method);exit;
    }
    if(str_starts_with($path,'/media-storage/')){
        if(!in_array($method,['GET','HEAD'],true)){http_response_code(405);header('Allow: GET, HEAD');exit;}
        if(!preg_match('~^/media-storage/([a-f0-9]{32})/thumb$~D',$path,$match)){http_response_code(404);exit;}
        $file=(new \Kulturhus\Native\Cms\MediaStorageInventory($db,$access,$mediaRoot))->thumbnail($match[1]);header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');header("Content-Security-Policy: sandbox; default-src 'none'; frame-ancestors 'none'");header('Content-Type: '.$file['mime']);header('Content-Length: '.$file['bytes']);if($method!=='HEAD')readfile($file['path']);exit;
    }
    if(str_starts_with($path,'/media-removed/')){
        if(!in_array($method,['GET','HEAD'],true)){http_response_code(405);header('Allow: GET, HEAD');exit;}
        if(!preg_match('~^/media-removed/([a-f0-9]{32})/thumb$~D',$path,$match)){http_response_code(404);exit;}
        $file=(new \Kulturhus\Native\Cms\MediaRemoval($db,$access,$mediaRoot))->thumbnail($match[1]);header('Cache-Control: no-store');header('Content-Type: '.$file['mime']);header('Content-Length: '.$file['bytes']);if($method!=='HEAD')readfile($file['path']);exit;
    }
    if(str_starts_with($path,'/media/')||str_starts_with($path,'/media-preview/')){
        if(!preg_match('~^/(media|media-preview)/([a-f0-9]{32})/(original|large|medium|thumb)$~D',$path,$match)){http_response_code(404);exit;}
        $media=new Media($db,$match[1]==='media'?new Access($db,'reader'):$access,$mediaRoot);
        $file=$match[1]==='media'?$media->publicVariant($match[2],$match[3]):$media->variant($match[2],$match[3]);
        if(!$file){http_response_code(404);exit;}
        header('Content-Type: '.$file['mime']);header('Cache-Control: private, no-cache');header('Content-Length: '.filesize($file['path']));readfile($file['path']);exit;
    }
    if(str_starts_with($path,'/feeds/')||in_array(rtrim($path,'/'),['/search','/suche'],true)){
        $publicFeedAccess=new Access($db,'reader');$publicFeedAccess->require('website','read');
        $feeds=new Feeds(new Pages($db,$publicFeedAccess),$origin,'',new Media($db,$publicFeedAccess,$mediaRoot));
        if(str_starts_with($path,'/feeds/')){
            $feedRoute=match(rtrim($path,'/')){'/feeds/termine'=>'/feeds/events.ics','/feeds/veranstaltungen'=>'/feeds/events.json',default=>rtrim($path,'/')};
            $isJsonFeed=$feedRoute==='/feeds/events.json';if(FeedResponse::methodResponse($method,$isJsonFeed))exit;
            if($feedRoute==='/feeds/all.rss.xml'){FeedResponse::sendDocument($feeds->document('rss'),'application/rss+xml; charset=utf-8',$method);exit;}
            if(in_array($feedRoute,['/feeds/news','/feeds/news.rss.xml','/feeds/alle'],true)){FeedResponse::sendDocument($feeds->document('rss',$feedRoute==='/feeds/alle'?'mixed':'news'),'application/rss+xml; charset=utf-8',$method);exit;}
            if($feedRoute==='/feeds/events.ics'){
                $event=$_GET['event']??null;if($event!==null&&(!is_string($event)||!preg_match('/^[a-f0-9]{32}$/D',$event)))throw new InvalidArgumentException('Ungültiger Kalendertermin.');
                if($event!==null&&!(new Pages($db,$publicFeedAccess))->events()->publicId($event)){http_response_code(404);header('Cache-Control: no-store');exit('Veranstaltung nicht veröffentlicht.');}
                FeedResponse::sendDocument($feeds->document('ics','all',$event),'text/calendar; charset=utf-8',$method,false,$event===null?null:'termin-'.$event.'.ics');exit;
            }
            if($isJsonFeed){FeedResponse::sendDocument($feeds->document('json'),'application/json; charset=utf-8',$method,true);exit;}
            http_response_code(404);header('Cache-Control: no-store');exit('Feed nicht gefunden.');
        }
        if(in_array(rtrim($path,'/'),['/search','/suche'],true)){
            if(!in_array($method,['GET','HEAD'],true)){http_response_code(405);header('Allow: GET, HEAD');exit;}
            $query=$_GET['q']??'';if(!is_string($query))throw new InvalidArgumentException('Suchtext muss Text sein.');
            $locale=$_GET['lang']??null;if($locale!==null&&!is_string($locale))throw new InvalidArgumentException('Eine Website-Sprache auswählen.');
            $publicPages=new Pages($db,$publicFeedAccess);$results=$feeds->searchPage($query,$_GET['seite']??1,$locale);
            $identity=Kulturhus\Native\Shared\Identity::forClub($db,$publicFeedAccess->club());$identity['website_design']=Kulturhus\Native\Cms\WebsiteDesign::forClub($db,$publicFeedAccess->club())['template'];
            $body=PublicSearchView::layout($results,$db,$publicFeedAccess,$publicPages,$origin,'/?view=website',$identity,rtrim($path,'/'));
            header('Content-Type: text/html; charset=utf-8');header('Cache-Control: private, no-cache');$etag='"'.hash('sha256',$body).'"';header('ETag: '.$etag);
            if(HttpValidators::unchanged($method,$_SERVER['HTTP_IF_NONE_MATCH']??'',$etag)){http_response_code(304);exit;}
            header('Content-Length: '.strlen($body));if($method!=='HEAD')echo $body;exit;
        }
        http_response_code(404);exit;
    }
    if(str_starts_with($path,'/site/')||$path==='/sitemap.xml'){
        $publicAccess=new Access($db,'reader');
        if($path==='/site/mitglied-werden'){
            $pages=new Pages($db,$publicAccess);
            if(!$pages->publicPage('mitglied-werden')){http_response_code(404);exit('Seite nicht veröffentlicht.');}
            require_once dirname(__DIR__,2).'/src/bootstrap.php';
            $membershipIdentity=array_replace(Kulturhus\Native\Shared\Identity::forClub($db,$publicAccess->club()),['website_design'=>Kulturhus\Native\Cms\WebsiteDesign::forClub($db,$publicAccess->club())['template']]);
            $form=new Kulturhus\Native\Web\Membership(new Kulturhus\Verein\MembershipApplications($db,'synthetic-a'),$membershipIdentity);
            header('Cache-Control: no-store');header('Content-Type: text/html; charset=utf-8');
            echo Kulturhus\Native\Web\PublicLayout::render('Mitglied werden','Gemeinsam aktiv werden.',$form->render($_POST,$_SESSION['native_csrf'],$method==='POST',true),$pages->navigation(),'mitglied-werden',$origin,'/?view=website',$membershipIdentity,false,$pages->footerNavigation());exit;
        }
        $siteIdentity=Kulturhus\Native\Shared\Identity::forClub($db,$publicAccess->club());$siteIdentity['website_design']=Kulturhus\Native\Cms\WebsiteDesign::forClub($db,$publicAccess->club())['template'];
        $routes=new \Kulturhus\Native\Routing\Resolver($db,$publicAccess,new \Kulturhus\Native\Routing\Transport($origin,true,false));
        $site=new Site(new Pages($db,$publicAccess),new Media($db,$publicAccess,$mediaRoot),new Cache($runtime.'/cache'),$origin,'synthetic-a',new Gallery($db,$publicAccess,$mediaRoot),new Kulturhus\Native\Web\Articles($db,$publicAccess,$mediaRoot),'/?view=website',$siteIdentity,static fn()=>Kulturhus\Native\Cms\WebsiteDesign::forClub($db,$publicAccess->club())['template'],new \Kulturhus\Native\Language\PublicResolver($db,$publicAccess),$routes,commentLinks:static fn(array $page):string=>\Kulturhus\Native\Comments\PublicLink::page($db,$publicAccess,$page),templateRenderers:new \Kulturhus\Native\TemplateRenderers\Runtime($db,$publicAccess->club(),\Kulturhus\Native\TemplateRenderers\Factory::registry($db,$publicAccess->club())));
        if($path==='/sitemap.xml'){header('Content-Type: application/xml; charset=utf-8');echo $site->sitemap();exit;}
        try{$resolved=$routes->resolve((string)$_SERVER['REQUEST_URI']);}catch(InvalidArgumentException){http_response_code(400);header('Cache-Control: no-store');echo 'Ungültige öffentliche Anfrage.';exit;}
        if($resolved->kind==='redirect'){header('Cache-Control: no-store');header('Location: '.$resolved->location,true,308);exit;}
        if($resolved->kind!=='page'){http_response_code(404);echo 'Seite nicht veröffentlicht.';exit;}
        $routing=$resolved->context;
        $html=$site->page($routing->basePath,$routing->page,$routing->eventQuery($_GET),$routing);
        if($html===null){http_response_code(404);echo 'Seite nicht veröffentlicht.';exit;}
        header('Content-Type: text/html; charset=utf-8');header('Cache-Control: no-cache');$etag='"'.hash('sha256',$html).'"';header('ETag: '.$etag);
        if(HttpValidators::unchanged($method,$_SERVER['HTTP_IF_NONE_MATCH']??'',$etag)){http_response_code(304);exit;}
        echo $html;exit;
    }
    header('Cache-Control: no-store');
    if($path==='/page-photo-status'){
        if(!in_array($method,['GET','HEAD'],true)){http_response_code(405);header('Allow: GET, HEAD');exit;}
        $data=(new Kulturhus\Native\Web\Website\PagePhotoStatus($db,$access))->query($_GET);
        header('Content-Type: application/json; charset=utf-8');
        if($method!=='HEAD')echo json_encode($data,JSON_THROW_ON_ERROR);
        exit;
    }
    if($path==='/album-photo-status'){
        if(!in_array($method,['GET','HEAD'],true)){http_response_code(405);header('Allow: GET, HEAD');exit;}
        $data=(new Kulturhus\Native\Web\Website\AlbumPhotoStatus($db,$access))->query($_GET);
        header('Content-Type: application/json; charset=utf-8');
        if($method!=='HEAD')echo json_encode($data,JSON_THROW_ON_ERROR);
        exit;
    }
    if($path==='/reference-options'){
        if($method==='POST'){http_response_code(405);header('Allow: GET, HEAD');exit;}
        $data=(new Kulturhus\Native\Cms\ReferenceCatalog($db,$access))->query($_GET);
        header('Content-Type: application/json; charset=utf-8');
        if($method!=='HEAD')echo json_encode($data,JSON_THROW_ON_ERROR);
        exit;
    }
    if($path==='/model-fields'){
        header('Content-Type: application/json');echo json_encode(['html'=>(new Kulturhus\Native\Web\ContentModel($db,$access,$_SESSION['native_csrf']))->fields((string)($_GET['template']??'standard'))],JSON_THROW_ON_ERROR);exit;
    }
    if($path==='/design-preview'){
        if(!in_array($method,['GET','HEAD'],true)){http_response_code(405);header('Allow: GET, HEAD');exit;}
        $html=(new Kulturhus\Native\Web\Website\DesignPreview($db,$access,$mediaRoot))->render($_GET['template']??null);
        header('Cache-Control: no-store');header('X-Robots-Tag: noindex, nofollow');header('Content-Type: text/html; charset=utf-8');if($method!=='HEAD')echo $html;exit;
    }
    if($path==='/home-preview'){
        if(!in_array($method,['GET','HEAD'],true)){http_response_code(405);header('Allow: GET, HEAD');exit;}
        $id=$_GET['item']??'';if(!is_string($id))throw new \DomainException('Ungültige Anfrage.');
        $html=(new Kulturhus\Native\Web\Website\HomePreview($db,$access,$mediaRoot))->render($id);
        header('Cache-Control: no-store');header('X-Robots-Tag: noindex, nofollow');header('Content-Type: text/html; charset=utf-8');if($method!=='HEAD')echo $html;exit;
    }
    if($path==='/sections-preview'){
        if(!in_array($method,['GET','HEAD'],true)){http_response_code(405);header('Allow: GET, HEAD');exit;}
        $id=$_GET['item']??'';if(!is_string($id))throw new \DomainException('Ungültige Anfrage.');
        $html=(new Kulturhus\Native\Web\Website\ContentSectionsPreview($db,$access,$mediaRoot))->render($id);
        header('Cache-Control: no-store');header('X-Robots-Tag: noindex, nofollow');header('Content-Type: text/html; charset=utf-8');if($method!=='HEAD')echo $html;exit;
    }
    if($path==='/edition-document'){
        $item=Kulturhus\Native\Web\Communication\EditionPreview::item($db,$access,'newsletter',$_GET['item']??'',$_GET);
        $file=(new Kulturhus\Native\Communication\DocumentAttachments($db,$access,$mediaRoot))->file($item,$_GET['document']??'');header('Content-Type: application/pdf');header('Content-Length: '.$file['bytes']);header('X-Content-Type-Options: nosniff');header('X-Robots-Tag: noindex, nofollow');header("Content-Disposition: attachment; filename=\"Newsletter.pdf\"; filename*=UTF-8''".rawurlencode($file['name']));if(($_SERVER['REQUEST_METHOD']??'GET')!=='HEAD')readfile($file['path']);exit;
    }
    if($path==='/edition-image'){
        if(!in_array($method,['GET','HEAD'],true)){http_response_code(405);header('Allow: GET, HEAD');exit;}
        $file=Kulturhus\Native\Web\Communication\EditionPreview::image($db,$access,$mediaRoot,$_GET);header('Cache-Control: no-store');header('Content-Type: '.$file['mime']);header('Content-Length: '.$file['bytes']);header('X-Content-Type-Options: nosniff');header('X-Robots-Tag: noindex, nofollow');if(isset($file['attachment_name']))header("Content-Disposition: attachment; filename=\"Pressefoto\"; filename*=UTF-8''".rawurlencode($file['attachment_name']));if($method!=='GET')exit;readfile($file['path']);exit;
    }
    if($path==='/preview'){
        if(!in_array($method,['GET','HEAD'],true)){http_response_code(405);header('Allow: GET, HEAD');exit;}
        foreach(['module','item']as$key)if(!is_string($_GET[$key]??null))throw new \DomainException('Ungültige Anfrage.');
        $item=Kulturhus\Native\Web\Communication\EditionPreview::item($db,$access,$_GET['module'],$_GET['item'],$_GET);
        $image=static fn($id)=>'/edition-image?'.http_build_query(['module'=>$item['module'],'item'=>$item['id'],'revision'=>$item['version'],'media'=>$id]);
        if($item['module']==='social')$image=static function($id)use($db,$access,$mediaRoot){(new Media($db,$access,$mediaRoot))->variant($id,'medium');return '/media-preview/'.$id.'/medium';};
        $html=Kulturhus\Native\Web\Communication\EditionPreview::render($item,$image,['/assets/native.css','/assets/richtext.css','/assets/compose-touch.css'],Kulturhus\Native\Web\Communication\PressPhotoLinks::render($db,$access,$item).($item['module']==='newsletter'?Kulturhus\Native\Web\Communication\PdfAttachments::links($item):''));
        header('Cache-Control: no-store');header('X-Robots-Tag: noindex, nofollow');header('Content-Type: text/html; charset=utf-8');if($method!=='HEAD')echo$html;exit;
    }
    if($path!=='/'){http_response_code(404);exit('Nicht gefunden.');}
    $view=(string)($_GET['view']??'website');$id=(string)($_GET['item']??'');$notice=$method==='GET'&&is_string($_SESSION['native_flash']??null)?$_SESSION['native_flash']:'';unset($_SESSION['native_flash']);
    $manager=new Manager($db,$access,$mediaRoot,$_SESSION['native_csrf'],!$secureAccounts);
    if($method==='POST') {
        $result=$manager->action($view,$_POST,$_FILES);$view=$result['view'];$id=$result['item']??'';$notice=$result['notice'];
        if($json&&Kulturhus\Native\Web\Communication\PlanningFormState::operation($_POST['operation']??null)){header('Content-Type: application/json');header('Cache-Control: no-store');echo json_encode(['planning_saved'=>true,'url'=>Kulturhus\Native\Web\Communication\PlanningFormState::url($view,$result),'notice'=>$notice],JSON_THROW_ON_ERROR);exit;}
        if($json&&isset($result['inbox_sync_response'])){header('Content-Type: application/json');header('Cache-Control: no-store');echo json_encode($result['inbox_sync_response'],JSON_THROW_ON_ERROR);exit;}
        if($json&&isset($result['library_response'])){header('Content-Type: application/json');header('Cache-Control: no-store');echo json_encode($result['library_response'],JSON_THROW_ON_ERROR);exit;}
        if($json&&isset($result['event_draft_response'])){header('Content-Type: application/json');header('Cache-Control: no-store');echo json_encode($result['event_draft_response'],JSON_THROW_ON_ERROR);exit;}
        if($json&&isset($result['check_plan_response'])){header('Content-Type: application/json');header('Cache-Control: no-store');echo json_encode($result['check_plan_response'],JSON_THROW_ON_ERROR);exit;}
        if($json&&isset($result['video_reuse_result'])){header('Content-Type: application/json');header('Cache-Control: no-store');echo json_encode(['reuse_result'=>$result['video_reuse_result'],'prepared'=>$result['video_reuse_prepared'],'can_edit'=>$result['video_reuse_can_edit']],JSON_THROW_ON_ERROR);exit;}
        if($json&&isset($result['video_recovery_result'])){header('Content-Type: application/json');header('Cache-Control: no-store');echo json_encode(['recovery_result'=>$result['video_recovery_result'],'prepared'=>$result['video_recovery_prepared'],'can_manage'=>$result['video_recovery_can_manage'],'can_edit'=>$result['video_recovery_can_edit']],JSON_THROW_ON_ERROR);exit;}
        if($json&&isset($result['video_policy_result'])){header('Content-Type: application/json');header('Cache-Control: no-store');echo json_encode(['video_policy_result'=>$result['video_policy_result'],'prepared'=>$result['video_policy_prepared'],'can_manage'=>$result['video_policy_can_manage'],'usage'=>$result['video_policy_usage']],JSON_THROW_ON_ERROR);exit;}
        if($json&&isset($result['video_file_result'])){header('Content-Type: application/json');header('Cache-Control: no-store');echo json_encode(['video_file_result'=>$result['video_file_result'],'current'=>$result['video_file_current'],'prepared'=>$result['video_file_prepared'],'can_manage'=>$result['video_file_can_manage'],'usage'=>$result['video_file_usage']],JSON_THROW_ON_ERROR);exit;}
        if($json&&isset($result['video_archive_result'])){header('Content-Type: application/json');header('Cache-Control: no-store');echo json_encode(['video_archive_result'=>$result['video_archive_result'],'can_edit'=>$result['video_archive_can_edit'],'catalogue'=>$result['video_archive_catalogue']],JSON_THROW_ON_ERROR);exit;}
        if($json&&isset($result['video_metadata_result'])){header('Content-Type: application/json');header('Cache-Control: no-store');echo json_encode(['video_metadata_result'=>$result['video_metadata_result'],'prepared'=>$result['video_metadata_prepared'],'can_edit'=>$result['video_metadata_can_edit'],'can_proof'=>$result['video_metadata_can_proof']],JSON_THROW_ON_ERROR);exit;}
        if($json&&isset($result['video_result'])){header('Content-Type: application/json');header('Cache-Control: no-store');echo json_encode(['video_result'=>$result['video_result'],'usage'=>$result['video_usage'],'can_edit'=>$result['video_can_edit']],JSON_THROW_ON_ERROR);exit;}
        if($json&&isset($result['photo_result'])){header('Content-Type: application/json');header('Cache-Control: no-store');echo json_encode(['photo_result'=>$result['photo_result']],JSON_THROW_ON_ERROR);exit;}
        if($json&&isset($result['canvas_result'])){header('Content-Type: application/json');header('Cache-Control: no-store');echo json_encode(['canvas_result'=>$result['canvas_result']],JSON_THROW_ON_ERROR);exit;}
        if($view==='model')foreach(Kulturhus\Native\Cms\ModelCatalogue::state($result)as$key=>$value)$_GET[$key]=$value;
        if(($_POST['operation']??null)==='tree-copy-forget'&&$view==='website')unset($_GET['copy_job']);
        if($json&&isset($result['image'])){header('Content-Type: application/json');echo json_encode(['ok'=>true,'image'=>$result['image']],JSON_THROW_ON_ERROR);exit;}
        if($json&&isset($result['press_picker'])){header('Content-Type: application/json');echo json_encode(['picker'=>Kulturhus\Native\Web\Communication\PressRecipientPicker::render($db,$access,$result['press_picker'],true)],JSON_THROW_ON_ERROR);exit;}
        if(isset($result['section']))$_GET['section']=$result['section'];
        if(($_POST['operation']??null)==='media-storage-recover'||($result['view']==='media'&&($result['section']??null)==='library'))unset($_GET['recover_source'],$_GET['storage_recoveries'],$_GET['recoveries_after']);
        if(isset($result['batch']))$_GET['batch']=$result['batch'];
        foreach(['tickets','comment','comments_page','comments_stream','comments_state','comments_list_page','comments_history_page','copy_job','list_id']as$key)if(isset($result[$key]))$_GET[$key]=$result[$key];
        foreach(['tickets','comment','comments_page','comments_stream','comments_state','comments_list_page','comments_history_page','copy_job','list_id','webhook_page','webhook_focus','reply','attention','thread','queue_job','video_page','video_archive','video_recovery_window','video_recovery_check','video_recovery_source','video_recovery_after','video_use_window','video_use_original','video_use_source','video_use_version','video_use_identity','video_use_check','video_use_query','video_use_page','video_use_result','video_use_read'] as $key)if(isset($result[$key]))$_GET[$key]=$result[$key];
        if(isset($result['reply'])||isset($result['attention']))unset($_GET['chat_list']);
        if($view==='chat'){foreach(Kulturhus\Native\Chat\Catalogue::state($result)as$key=>$value)$_GET[$key]=(string)$value;if(isset($result['chat_before']))$_GET['chat_before']=(string)$result['chat_before'];elseif(($_POST['operation']??'')==='chat-send'&&!isset($_POST['chat_browse']))unset($_GET['chat_before']);}
        $updatedAccess=$secureAccounts?new Kulturhus\Native\Auth\SessionAccess($db,$auth,$_SESSION['auth_token']):new Access($db,$_SESSION['native_actor']);
        $manager=new Manager($db,$updatedAccess,$mediaRoot,$_SESSION['native_csrf'],!$secureAccounts);
        if(!$json&&!isset($result['compose_state'])){$_SESSION['native_flash']=$notice;$destination=['view'=>$view,'item'=>$id];if($view==='model')$destination+=Kulturhus\Native\Cms\ModelCatalogue::state($result);if($view==='news')$destination+=Kulturhus\Native\Cms\ArticleCatalogue::filters($result);foreach(['tickets','webhook_page','webhook_focus','section','comment','comments_page','comments_stream','comments_state','comments_list_page','comments_history_page','edition_history_open','batch','copy_job','list_id','reply','attention','thread','queue_job','queue_page','queue_provider','queue_state','newsletter_job','newsletter_page','newsletter_kind','newsletter_state','newsletter_item','planning_view','plan_month','plan_channel','plan_state','plan_step','album_page','album_list_page','video_page','video_archive','video_recovery_window','video_recovery_check','video_recovery_source','video_recovery_after','video_use_window','video_use_original','video_use_source','video_use_version','video_use_identity','video_use_check','video_use_query','video_use_page','video_use_result','video_use_read','q','status','chat_query','chat_filter','chat_page','chat_rooms','chat_before'] as $key)if(isset($result[$key]))$destination[$key]=$result[$key];header('Location: /?'.http_build_query($destination),true,303);exit;}
    }
    $before=$view==='chat'&&isset($_GET['chat_before'])?Kulturhus\Native\Web\ChatFormState::number($_GET['chat_before']):PHP_INT_MAX;
    if($json&&isset($result['save_lookup'])){header('Content-Type: application/json');echo json_encode(['save_lookup'=>$result['save_lookup']],JSON_THROW_ON_ERROR);exit;}
    $main=$manager->main($view,$id,$before,$result['compose_state']??null);$query=['view'=>$view,'item'=>$id];if(isset($_GET['section'])&&is_string($_GET['section']))$query['section']=$_GET['section'];if(isset($_GET['batch'])&&is_string($_GET['batch']))$query['batch']=$_GET['batch'];if($before<PHP_INT_MAX)$query['chat_before']=$before;
    if($view==='model')$query+=Kulturhus\Native\Cms\ModelCatalogue::state($_GET);
    if(isset($_GET['fieldtypes_page'])&&is_string($_GET['fieldtypes_page'])&&preg_match('/\A[1-9][0-9]{0,2}\z/D',$_GET['fieldtypes_page']))$query['fieldtypes_page']=$_GET['fieldtypes_page'];
    foreach(['files_page','file_history_page','file_group_page','file_storage','file_storage_after','recover_file','mine','tag','removed','kind','worker_state','worker_page','webhook_page','webhook_focus','tickets','ticket_offset','sidebar_page','children_page','versions_page','image_page','video_page','video_archive','video_archive_source','video_archive_history','video_lifecycle_source','video_lifecycle_page','video_storage_window','video_storage_check','video_storage_after','video_storage_source','video_recovery_window','video_recovery_check','video_recovery_source','video_recovery_after','video_recovery_read','video_use_window','video_use_original','video_use_source','video_use_version','video_use_identity','video_use_check','video_use_query','video_use_page','video_use_result','video_use_read','album_page','album_list_page','image_anchor','image_total','compare_from','compare_to','q','status','sort','page','year','period','source','archiv','seite','reply','attention','thread','message_page','focus_message','inbox_history','inbox_history_kind','inbox_history_page','inbox_connection','inbox_version','conversation_page','inbox_channel','review_page','review_channel','review_kind','original','original_page','chat_list','chat_query','chat_filter','chat_page','chat_rooms','state','press_item','press_state','press_page','press_job','queue_job','queue_page','queue_state','queue_provider','planning_view','plan_month','plan_channel','plan_state','plan_step','hub_query','hub_type','hub_page','newsletter_job','newsletter_item','newsletter_page','newsletter_kind','newsletter_state','photo_page','edition_query','edition_state','edition_page','edition_open','edition_history_page','edition_history_open','media_q','media_state','media_archive','media_archive_page','media_page','media_history_page','media_usage_page','media_usage_source','media_global_check','removed_page','storage_check','storage_after','recover_source','storage_recoveries','recoveries_after'] as $key)if(isset($_GET[$key])&&is_string($_GET[$key]))$query[$key]=$_GET[$key];
    if($view==='social'&&($_GET['section']??'library')==='library')foreach(['kind','channel']as$key)if(isset($_GET[$key])&&is_string($_GET[$key]))$query[$key]=$_GET[$key];
    if($view==='settings')foreach(['webhook_page','webhook_focus']as$key)if(isset($_GET[$key])&&is_string($_GET[$key]))$query[$key]=$_GET[$key];
    foreach(['comment','comments_page','comments_stream','comments_state','comments_list_page','comments_history_page','copy_job','list_id'] as $key)if(isset($_GET[$key])&&is_string($_GET[$key]))$query[$key]=$_GET[$key];
    if($view==='website'&&($_GET['section']??null)==='lister'){foreach(['list_apply','list_page','junction','filter_mode','selector_text','aggregate','direction'] as $key)if(isset($_GET[$key])&&is_string($_GET[$key])&&strlen($_GET[$key])<=16384)$query[$key]=$_GET[$key];foreach(['columns','rules'] as $key)if(isset($_GET[$key])&&is_array($_GET[$key])&&count($_GET[$key])<=20&&strlen(json_encode($_GET[$key],JSON_THROW_ON_ERROR))<=16384)$query[$key]=$_GET[$key];}
    if($json){header('Content-Type: application/json');echo json_encode(['html'=>$main,'url'=>'/?'.http_build_query($query),'notice'=>$notice],JSON_THROW_ON_ERROR);}
    else {header('Content-Type: text/html; charset=utf-8');echo $manager->shell($main,$notice);}
}catch(DomainException|InvalidArgumentException|JsonException|Kulturhus\Native\Cms\Conflict $error){
    http_response_code($error instanceof Kulturhus\Native\Cms\Conflict||$error instanceof Kulturhus\Native\Chat\Conflict?409:422);header('Cache-Control: no-store');
    if(in_array($view??null,['social','newsletter','press'],true)&&Kulturhus\Native\Web\Communication\PlanningFormState::operation($_POST['operation']??null)&&isset($db,$access,$mediaRoot,$_SESSION['native_csrf'])){
        try{$failure=Kulturhus\Native\Web\Communication\PlanningFormState::failure($db,$access,$view,$_POST,$error->getMessage(),false);
            if($json){header('Content-Type: application/json');echo json_encode(['error'=>$failure['error']],JSON_THROW_ON_ERROR);exit;}
            $_GET=$failure['route'];$correction=new Manager($db,$access,$mediaRoot,$_SESSION['native_csrf'],!$secureAccounts);header('Content-Type: text/html; charset=utf-8');echo$correction->shell($correction->main($view,'',PHP_INT_MAX,['planning_failure'=>$failure]));exit;
        }catch(Throwable){/* Current rights or language unavailable: preserve the original refusal. */}
    }
    if(($view??null)==='social'&&Kulturhus\Native\Web\Communication\VideoPolicyForm::operation($_POST['operation']??null)&&isset($db,$access,$mediaRoot,$_SESSION['native_csrf'])){
        try{$access->require('social','read');$message=Kulturhus\Native\Language\VideoPolicyMessages::text($error->getMessage(),(new Kulturhus\Native\Language\Catalog($db,$access))->messages());$canManage=true;try{$access->require('social','manage');}catch(DomainException){$canManage=false;}
            if($json){header('Content-Type: application/json');echo json_encode(['error'=>$message,'csrf'=>$_SESSION['native_csrf'],'can_manage'=>$canManage],JSON_THROW_ON_ERROR);exit;}
            $state=Kulturhus\Native\Web\Communication\VideoPolicyForm::restore($_POST);$state['error']=$message;$_GET['section']='compose';$correction=new Manager($db,$access,$mediaRoot,$_SESSION['native_csrf'],!$secureAccounts);header('Content-Type: text/html; charset=utf-8');echo$correction->shell($correction->main('social',$state['id'],PHP_INT_MAX,['video_policy_only'=>$state]));exit;
        }catch(Throwable){/* Current source binding, rights and original form remain authoritative. */}
    }
    if(($view??null)==='social'&&($_POST['operation']??null)==='social-video-reuse'&&isset($db,$access,$mediaRoot,$_SESSION['native_csrf'])){
        try{$access->require('social','read');$message=Kulturhus\Native\Language\VideoReuseMessages::text($error->getMessage(),(new Kulturhus\Native\Language\Catalog($db,$access))->messages());$edit=Kulturhus\Native\Web\Communication\VideoReuseForm::canEdit($db,$access,$_POST,$mediaRoot);
            if($json){header('Content-Type: application/json');header('Cache-Control: no-store');echo json_encode(['error'=>$message,'csrf'=>$_SESSION['native_csrf'],'can_edit'=>$edit],JSON_THROW_ON_ERROR);exit;}
            $state=Kulturhus\Native\Web\Communication\VideoReuseForm::correction($db,$access,$mediaRoot,$_POST,$message);$_GET['section']='compose';$correction=new Manager($db,$access,$mediaRoot,$_SESSION['native_csrf'],!$secureAccounts);header('Content-Type: text/html; charset=utf-8');echo$correction->shell($correction->main('social',$state['id'],PHP_INT_MAX,['video_reuse_only'=>$state]));exit;
        }catch(Throwable){/* Current private source rights remain authoritative. */}
    }    if(($view??null)==='social'&&($_POST['operation']??null)==='social-video-recover'&&isset($db,$access,$mediaRoot,$_SESSION['native_csrf'])){
        try{$access->require('social','read');$message=Kulturhus\Native\Language\VideoRecoveryMessages::text($error->getMessage(),(new Kulturhus\Native\Language\Catalog($db,$access))->messages());$manage=true;$edit=true;try{$access->require('social','manage');}catch(DomainException){$manage=false;}try{$access->require('social','edit');}catch(DomainException){$edit=false;}
            if($json){header('Content-Type: application/json');header('Cache-Control: no-store');echo json_encode(['error'=>$message,'csrf'=>$_SESSION['native_csrf'],'can_manage'=>$manage,'can_edit'=>$edit],JSON_THROW_ON_ERROR);exit;}
            $state=Kulturhus\Native\Web\Communication\VideoRecoveryForm::correction($db,$access,$mediaRoot,$_POST,$message);$_GET['section']='compose';$correction=new Manager($db,$access,$mediaRoot,$_SESSION['native_csrf'],!$secureAccounts);header('Content-Type: text/html; charset=utf-8');echo$correction->shell($correction->main('social',$state['id'],PHP_INT_MAX,['video_recovery_only'=>$state]));exit;
        }catch(Throwable){/* Current private source rights remain authoritative. */}
    }
    if(($view??null)==='social'&&Kulturhus\Native\Web\Communication\VideoRemovalForm::operation($_POST['operation']??null)&&isset($db,$access,$mediaRoot,$_SESSION['native_csrf'])){
        try{$access->require('social','read');$message=Kulturhus\Native\Language\VideoFileMessages::text($error->getMessage(),(new Kulturhus\Native\Language\Catalog($db,$access))->messages());$canManage=true;try{$access->require('social','manage');}catch(DomainException){$canManage=false;}
            if($json){header('Content-Type: application/json');echo json_encode(['error'=>$message,'csrf'=>$_SESSION['native_csrf'],'can_manage'=>$canManage],JSON_THROW_ON_ERROR);exit;}
            $state=Kulturhus\Native\Web\Communication\VideoRemovalForm::correction($db,$access,$mediaRoot,$_POST,$message);$_GET['section']='compose';$correction=new Manager($db,$access,$mediaRoot,$_SESSION['native_csrf'],!$secureAccounts);header('Content-Type: text/html; charset=utf-8');echo$correction->shell($correction->main('social',$state['id'],PHP_INT_MAX,['video_file_only'=>$state]));exit;
        }catch(Throwable){/* Current source binding, rights and original form remain authoritative. */}
    }
    if(($view??null)==='social'&&($_POST['operation']??null)==='social-video-archive'&&isset($db,$access,$mediaRoot,$_SESSION['native_csrf'])){
        try{$access->require('social','read');$message=Kulturhus\Native\Language\VideoCatalogueMessages::text($error->getMessage(),(new Kulturhus\Native\Language\Catalog($db,$access))->messages());$canEdit=true;try{$access->require('social','edit');}catch(DomainException){$canEdit=false;}
            if($json){header('Content-Type: application/json');echo json_encode(['error'=>$message,'csrf'=>$_SESSION['native_csrf'],'can_edit'=>$canEdit],JSON_THROW_ON_ERROR);exit;}
            $state=Kulturhus\Native\Web\Communication\VideoArchiveForm::restore($_POST);$state['error']=$message;$_GET['section']='compose';$correction=new Manager($db,$access,$mediaRoot,$_SESSION['native_csrf'],!$secureAccounts);header('Content-Type: text/html; charset=utf-8');echo$correction->shell($correction->main('social',$state['id'],PHP_INT_MAX,['video_archive_only'=>$state]));exit;
        }catch(Throwable){/* Current source binding, rights and original form remain authoritative. */}
    }
    if(($view??null)==='social'&&($_POST['operation']??null)==='social-video-metadata-save'&&isset($db,$access,$mediaRoot,$_SESSION['native_csrf'])){
        try{$access->require('social','read');$message=Kulturhus\Native\Language\VideoMetadataMessages::text($error->getMessage(),(new Kulturhus\Native\Language\Catalog($db,$access))->messages());$canEdit=true;$canProof=true;try{$access->require('social','edit');}catch(DomainException){$canEdit=false;}try{$access->require('social','manage');}catch(DomainException){$canProof=false;}
            if($json){header('Content-Type: application/json');echo json_encode(['error'=>$message,'csrf'=>$_SESSION['native_csrf'],'can_edit'=>$canEdit,'can_proof'=>$canProof],JSON_THROW_ON_ERROR);exit;}
            $state=Kulturhus\Native\Web\Communication\VideoMetadataForm::restore($_POST);$state['error']=$message;$_GET['section']='compose';$correction=new Manager($db,$access,$mediaRoot,$_SESSION['native_csrf'],!$secureAccounts);header('Content-Type: text/html; charset=utf-8');echo$correction->shell($correction->main('social',$state['id'],PHP_INT_MAX,['video_metadata_only'=>$state]));exit;
        }catch(Throwable){/* Current source binding, rights and original form remain authoritative. */}
    }
    if(($view??null)==='social'&&($_POST['operation']??null)==='social-video-save'&&isset($db,$access,$mediaRoot,$_SESSION['native_csrf'])){
        try{$access->require('social','read');$message=Kulturhus\Native\Language\VideoStudioMessages::text($error->getMessage(),(new Kulturhus\Native\Language\Catalog($db,$access))->messages());$canEdit=true;try{$access->require('social','edit');}catch(DomainException){$canEdit=false;}
            if($json){header('Content-Type: application/json');echo json_encode(['error'=>$message,'csrf'=>$_SESSION['native_csrf'],'can_edit'=>$canEdit],JSON_THROW_ON_ERROR);exit;}
            $state=Kulturhus\Native\Web\Communication\VideoUploadForm::correction($_POST,$_FILES,$message);$_GET['section']='compose';$correction=new Manager($db,$access,$mediaRoot,$_SESSION['native_csrf'],!$secureAccounts);header('Content-Type: text/html; charset=utf-8');echo$correction->shell($correction->main('social',$state['id'],PHP_INT_MAX,['video_only'=>$state]));exit;
        }catch(Throwable){/* Current access and form bindings remain authoritative. */}
    }
    $socialSaveError=$error->getMessage();
    if(isset($db,$access)&&Kulturhus\Native\Web\Communication\SocialLibrary::applies($view??'',$_POST,$_GET))try{$socialSaveError=Kulturhus\Native\Language\ConversationMessages::text(Kulturhus\Native\Language\ContentMessages::text($socialSaveError,(new Kulturhus\Native\Language\Catalog($db,$access))->contentMessages()),(new Kulturhus\Native\Language\Catalog($db,$access))->messages());}catch(Throwable){}
    if(isset($db,$access)&&Kulturhus\Native\Web\Communication\EventSources::applies($view??'',$_POST,$_GET))try{$socialSaveError=Kulturhus\Native\Language\ContentMessages::text($socialSaveError,(new Kulturhus\Native\Language\Catalog($db,$access))->contentMessages());}catch(Throwable){}
    if(isset($db,$access)&&Kulturhus\Native\Web\Communication\ConversationRequest::applies($view??'',$_POST,$_GET))try{$socialSaveError=Kulturhus\Native\Language\ConversationMessages::text($socialSaveError,(new Kulturhus\Native\Language\Catalog($db,$access))->messages());}catch(Throwable){/* Keep the original rejection when language access is unavailable. */}
    if(($view??null)==='social'&&($_POST['operation']??null)==='social-video-metadata-save'&&isset($db,$access))try{$socialSaveError=Kulturhus\Native\Language\VideoMetadataMessages::text($socialSaveError,(new Kulturhus\Native\Language\Catalog($db,$access))->messages());}catch(Throwable){}
    if(($view??null)==='social'&&($_POST['operation']??null)==='social-video-save'&&isset($db,$access))try{$socialSaveError=Kulturhus\Native\Language\VideoStudioMessages::text($socialSaveError,(new Kulturhus\Native\Language\Catalog($db,$access))->messages());}catch(Throwable){}
    if(in_array($view??null,['social','newsletter','press'],true)&&Kulturhus\Native\Web\Communication\PlanningFormState::operation($_POST['operation']??null)&&isset($db,$access))try{$socialSaveError=Kulturhus\Native\Language\EditorialHubMessages::text($socialSaveError,(new Kulturhus\Native\Language\Catalog($db,$access))->messages());}catch(Throwable){}
    if(($view??null)==='social'&&in_array($_POST['operation']??null,['social-canvas-save','social-canvas-review','social-photo-upload'],true)&&isset($db,$access)){try{$socialSaveError=Kulturhus\Native\Language\ImageStudioMessages::text($socialSaveError,(new Kulturhus\Native\Language\Catalog($db,$access))->messages());}catch(Throwable){/* Preserve the current rejection. */}}
    if(($view??null)==='social'&&($_POST['operation']??null)==='communication-save'&&isset($db,$access)){try{$socialSaveError=Kulturhus\Native\Language\ContentMessages::text($socialSaveError,(new Kulturhus\Native\Language\Catalog($db,$access))->contentMessages());}catch(Throwable){/* Preserve the original rejection when personal language is unavailable. */}}
    if($json){header('Content-Type: application/json');echo json_encode(['error'=>$socialSaveError],JSON_THROW_ON_ERROR);}
    elseif(($view??null)==='social'&&($_POST['operation']??null)==='social-photo-upload'&&is_string($_POST['csrf']??null)&&isset($db,$access,$mediaRoot,$_SESSION['native_csrf'])&&hash_equals($_SESSION['native_csrf'],$_POST['csrf'])){
        try{$state=Kulturhus\Native\Web\Communication\PhotoUploadForm::restore($_POST);$state['error']=$socialSaveError;$correction=new Manager($db,$access,$mediaRoot,$_SESSION['native_csrf'],!$secureAccounts);header('Content-Type: text/html; charset=utf-8');echo$correction->shell($correction->main('social',$state['id'],PHP_INT_MAX,['photo_only'=>$state]));}
        catch(Throwable){header('Content-Type: text/html; charset=utf-8');echo'<p role="alert">'.Html::e($socialSaveError).'</p>';}
    }
    elseif(($view??null)==='navigation'&&Kulturhus\Native\Web\NavigationFormState::submitted($_POST)&&is_string($_POST['csrf']??null)&&isset($db,$access,$mediaRoot,$_SESSION['native_csrf'])&&hash_equals($_SESSION['native_csrf'],$_POST['csrf'])){
        try{$state=(new Kulturhus\Native\Web\Navigation($db,$access,$_SESSION['native_csrf']))->correction($_POST,$error->getMessage(),$error instanceof Kulturhus\Native\Cms\Conflict);$correction=new Manager($db,$access,$mediaRoot,$_SESSION['native_csrf'],!$secureAccounts);header('Content-Type: text/html; charset=utf-8');echo$correction->shell($correction->main('navigation','',PHP_INT_MAX,['navigation'=>$state]));}
        catch(Throwable){header('Content-Type: text/html; charset=utf-8');echo'<p role="alert">'.Html::e($error->getMessage()).'</p>';}
    }
    elseif(($view??null)==='website'&&in_array($_POST['operation']??null,['website-comment-configure','website-comment-moderate','website-comment-edit'],true)&&is_string($_POST['csrf']??null)&&isset($db,$access,$mediaRoot,$_SESSION['native_csrf'])&&hash_equals($_SESSION['native_csrf'],$_POST['csrf'])){
        try{$correction=new Manager($db,$access,$mediaRoot,$_SESSION['native_csrf'],!$secureAccounts);header('Content-Type: text/html; charset=utf-8');echo$correction->shell($correction->main('website','',PHP_INT_MAX,['comment_correction'=>['post'=>$_POST,'error'=>$error->getMessage()]]));}
        catch(Throwable){header('Content-Type: text/html; charset=utf-8');echo'<p role="alert">'.Html::e($error->getMessage()).'</p><a href="/?view=website&amp;section=comments">Zu den Website-Kommentaren</a>';}
    }
    elseif(($view??null)==='chat'&&Kulturhus\Native\Web\ChatFormState::submitted($_POST)&&is_string($_POST['csrf']??null)&&isset($db,$access,$mediaRoot,$_SESSION['native_csrf'])&&hash_equals($_SESSION['native_csrf'],$_POST['csrf'])){
        try{$access->require('chat','edit');$state=Kulturhus\Native\Web\ChatFormState::restore($_POST);(new Kulturhus\Native\Chat\Workspace($db,$access))->get($state['id']);$state['error']=$error->getMessage();$correction=new Manager($db,$access,$mediaRoot,$_SESSION['native_csrf'],!$secureAccounts);header('Content-Type: text/html; charset=utf-8');echo$correction->shell($correction->main('chat',$state['id'],PHP_INT_MAX,['chat'=>$state]));}
        catch(Throwable){header('Content-Type: text/html; charset=utf-8');echo'<p role="alert">'.Html::e($error->getMessage()).'</p><a href="/?view=chat">Zu den Unterhaltungen</a>';}
    }
    elseif(($view??null)==='social'&&($_POST['operation']??null)==='social-mail-settings'&&is_string($_POST['csrf']??null)&&isset($db,$access,$mediaRoot,$_SESSION['native_csrf'])&&hash_equals($_SESSION['native_csrf'],$_POST['csrf'])){
        try{$access->require('website','manage');$access->require('social','manage');$_GET['section']='mail';$correction=new Manager($db,$access,$mediaRoot,$_SESSION['native_csrf'],!$secureAccounts);$mailError=Kulturhus\Native\Language\ConversationMessages::text($socialSaveError,(new Kulturhus\Native\Language\Catalog($db,$access))->messages());header('Content-Type: text/html; charset=utf-8');echo$correction->shell('<p role="alert">'.Html::e($mailError).'</p>'.$correction->main('social',''));}
        catch(Throwable){header('Content-Type: text/html; charset=utf-8');echo'<p role="alert">'.Html::e($mailError??'Vorgang nicht bestätigt. Ursprüngliche Nachricht prüfen.').'</p>';}
    }
    elseif(($view??null)==='social'&&Kulturhus\Native\Web\Communication\HumanFormState::submitted($_POST)&&is_string($_POST['csrf']??null)&&isset($db,$access,$mediaRoot,$_SESSION['native_csrf'])&&hash_equals($_SESSION['native_csrf'],$_POST['csrf'])){
        try{header('Content-Type: text/html; charset=utf-8');echo Kulturhus\Native\Web\Communication\HumanFormState::correction($db,$access,$mediaRoot,$_SESSION['native_csrf'],!$secureAccounts,$_POST,$error->getMessage());}
        catch(Throwable){header('Content-Type: text/html; charset=utf-8');echo '<p role="alert">'.Html::e($error->getMessage()).'</p><a href="/?view=social">Zu den Gesprächen</a>';}
    }
    elseif(($view??null)==='press'&&($_POST['operation']??null)==='press-event-draft'&&is_string($_POST['csrf']??null)&&isset($db,$access,$mediaRoot,$_SESSION['native_csrf'])&&hash_equals($_SESSION['native_csrf'],$_POST['csrf'])){
        try{$access->require('press','read');$_GET['section']='events';$correction=new Manager($db,$access,$mediaRoot,$_SESSION['native_csrf'],!$secureAccounts);header('Content-Type: text/html; charset=utf-8');echo $correction->shell('<p role="alert">'.Html::e($error->getMessage()).'</p>'.$correction->main('press',''));}
        catch(Throwable){header('Content-Type: text/html; charset=utf-8');echo '<p role="alert">'.Html::e($error->getMessage()).'</p><a href="/?view=press&amp;section=events">Veranstaltung erneut auswählen</a>';}
    }
    elseif(($view??null)==='press'&&($_POST['operation']??null)==='communication-schedule'&&is_string($_POST['id']??null)&&is_string($_POST['csrf']??null)&&isset($db,$access,$mediaRoot,$_SESSION['native_csrf'])&&hash_equals($_SESSION['native_csrf'],$_POST['csrf'])){
        try{$access->require('press','publish');$_GET['section']='compose';$correction=new Manager($db,$access,$mediaRoot,$_SESSION['native_csrf'],!$secureAccounts);header('Content-Type: text/html; charset=utf-8');echo $correction->shell('<p role="alert">'.Html::e($error->getMessage()).'</p>'.$correction->main('press',$_POST['id']));}
        catch(Throwable){header('Content-Type: text/html; charset=utf-8');echo '<p>'.Html::e($error->getMessage()).'</p><a href="/?view=press">Zur Presseredaktion</a>';}
    }
    elseif(in_array($view??null,['newsletter','press'],true)&&in_array($_POST['operation']??null,['communication-template-manage','communication-from-template'],true)&&is_string($_POST['csrf']??null)&&isset($db,$access,$mediaRoot,$_SESSION['native_csrf'])&&hash_equals($_SESSION['native_csrf'],$_POST['csrf'])){
        try{$access->require($view,'read');$state=['templates'=>Kulturhus\Native\Web\Communication\TemplateFormState::restore($_POST)];$_GET['section']='templates';$correction=new Manager($db,$access,$mediaRoot,$_SESSION['native_csrf'],!$secureAccounts);header('Content-Type: text/html; charset=utf-8');echo $correction->shell('<p role="alert">'.Html::e($error->getMessage()).'</p>'.$correction->main($view,'',PHP_INT_MAX,$state));}
        catch(Throwable){header('Content-Type: text/html; charset=utf-8');echo '<p>'.Html::e($error->getMessage()).'</p><a href="/?view='.Html::e($view).'&amp;section=templates">Zu den Vorlagen</a>';}
    }
    elseif(($view??null)==='press'&&in_array($_POST['operation']??null,['press-contacts','press-contact-profile'],true)&&is_string($_POST['csrf']??null)&&isset($db,$access,$mediaRoot,$_SESSION['native_csrf'])&&hash_equals($_SESSION['native_csrf'],$_POST['csrf'])){
        try{$access->require('press','read');$state=['contacts'=>Kulturhus\Native\Web\Communication\PressContactManager::restore($_POST)];$_GET['section']='recipients';$correction=new Manager($db,$access,$mediaRoot,$_SESSION['native_csrf'],!$secureAccounts);header('Content-Type: text/html; charset=utf-8');echo $correction->shell('<p role="alert">'.Html::e($error->getMessage()).'</p>'.$correction->main('press','',PHP_INT_MAX,$state));}
        catch(Throwable){header('Content-Type: text/html; charset=utf-8');echo '<p>'.Html::e($error->getMessage()).'</p><a href="/?view=press&amp;section=recipients">Zu den Pressekontakten</a>';}
    }
    elseif(($view??null)==='newsletter'&&in_array($_POST['operation']??null,['newsletter-contacts','newsletter-profile'],true)&&is_string($_POST['csrf']??null)&&isset($db,$access,$mediaRoot,$_SESSION['native_csrf'])&&hash_equals($_SESSION['native_csrf'],$_POST['csrf'])){
        try{$access->require('newsletter','manage');$state=['contacts'=>Kulturhus\Native\Web\Communication\NewsletterContacts::restore($_POST)];$_GET['section']='recipients';$correction=new Manager($db,$access,$mediaRoot,$_SESSION['native_csrf'],!$secureAccounts);header('Content-Type: text/html; charset=utf-8');echo $correction->shell('<p role="alert">'.Html::e($error->getMessage()).'</p>'.$correction->main('newsletter','',PHP_INT_MAX,$state));}
        catch(Throwable){header('Content-Type: text/html; charset=utf-8');echo '<p>'.Html::e($error->getMessage()).'</p><a href="/?view=newsletter&amp;section=recipients">Zu den Empfängern</a>';}
    }
    elseif(($view??null)==='newsletter'&&($_POST['operation']??null)==='communication-save'&&is_string($_POST['csrf']??null)&&isset($db,$access,$mediaRoot,$_SESSION['native_csrf'])&&hash_equals($_SESSION['native_csrf'],$_POST['csrf'])){
        try{$access->require('newsletter','edit');$state=Kulturhus\Native\Web\Communication\NewsletterComposerState::restore($_POST);$_GET['section']='compose';$correction=new Manager($db,$access,$mediaRoot,$_SESSION['native_csrf'],!$secureAccounts);header('Content-Type: text/html; charset=utf-8');echo $correction->shell('<p role="alert">'.Html::e($error->getMessage()).'</p>'.$correction->main('newsletter',$state['document']['id'],PHP_INT_MAX,$state));}
        catch(Throwable){header('Content-Type: text/html; charset=utf-8');echo '<p>'.Html::e($error->getMessage()).'</p><a href="/?view=newsletter">Zur Newsletterredaktion</a>';}
    }
    elseif(($view??null)==='press'&&($_POST['operation']??null)==='communication-save'&&!isset($_POST['press_browse'])&&is_string($_POST['csrf']??null)&&isset($db,$access,$mediaRoot,$_SESSION['native_csrf'])&&hash_equals($_SESSION['native_csrf'],$_POST['csrf'])){
        try{$access->require('press','edit');$state=Kulturhus\Native\Web\Communication\PressComposerState::restore($_POST);$_GET['section']='compose';$correction=new Manager($db,$access,$mediaRoot,$_SESSION['native_csrf'],!$secureAccounts);header('Content-Type: text/html; charset=utf-8');echo $correction->shell('<p role="alert">'.Html::e($error->getMessage()).'</p>'.$correction->main('press',$state['document']['id'],PHP_INT_MAX,$state));}
        catch(Throwable){header('Content-Type: text/html; charset=utf-8');echo '<p>'.Html::e($error->getMessage()).'</p><a href="/?view=press">Zur Presseredaktion</a>';}
    }
    elseif(($view??null)==='social'&&($_POST['operation']??null)==='communication-save'&&is_string($_POST['csrf']??null)&&isset($db,$access,$mediaRoot,$_SESSION['native_csrf'])&&hash_equals($_SESSION['native_csrf'],$_POST['csrf'])){
        try{$access->require('social','edit');$state=Kulturhus\Native\Web\Communication\SocialComposerState::restore($_POST);$_GET['section']='compose';$correction=new Manager($db,$access,$mediaRoot,$_SESSION['native_csrf'],!$secureAccounts);header('Content-Type: text/html; charset=utf-8');echo $correction->shell('<p role="alert">'.Html::e($socialSaveError).'</p>'.$correction->main('social',$state['document']['id'],PHP_INT_MAX,$state));}
        catch(Throwable){header('Content-Type: text/html; charset=utf-8');echo '<p>'.Html::e($error->getMessage()).'</p><a href="/?view=social">Zur Redaktion</a>';}
    }

    elseif(($_POST['operation']??null)==='social-video-recover'&&is_string($_POST['id']??null)&&is_string($_POST['source_id']??null)&&preg_match('/\A[a-f0-9]{32}\z/D',$_POST['source_id'])&&is_string($_POST['csrf']??null)&&isset($db,$access,$mediaRoot,$_SESSION['native_csrf'])&&hash_equals($_SESSION['native_csrf'],$_POST['csrf'])){
        try{$_GET['section']='compose';$_GET['video_recovery_check']='1';$_GET['video_recovery_source']=$_POST['source_id'];$_GET['video_recovery_after']=is_string($_POST['video_recovery_after']??null)?$_POST['video_recovery_after']:'';$correction=new Manager($db,$access,$mediaRoot,$_SESSION['native_csrf'],!$secureAccounts);header('Content-Type: text/html; charset=utf-8');echo $correction->shell('<p role="alert">'.Html::e($error->getMessage()).'</p>'.$correction->main('social',$_POST['id']));}
        catch(Throwable){header('Content-Type: text/html; charset=utf-8');echo '<p>'.Html::e($error->getMessage()).'</p><a href="/?view=social">Zur Redaktion</a>';}
    }
    elseif(in_array($_POST['operation']??null,['social-video-remove','social-video-restore','social-video-purge','social-video-resume','social-video-retention-save'],true)&&is_string($_POST['id']??null)&&isset($db,$access,$mediaRoot,$_SESSION['native_csrf'])){
        try{$_GET['section']='compose';$_GET['video_archive']='1';$_GET['video_page']=is_string($_POST['video_page']??null)?$_POST['video_page']:'1';if(is_string($_POST['source_id']??null))$_GET['video_lifecycle_source']=$_POST['source_id'];$correction=new Manager($db,$access,$mediaRoot,$_SESSION['native_csrf'],!$secureAccounts);header('Content-Type: text/html; charset=utf-8');echo $correction->shell('<p role="alert">'.Html::e($error->getMessage()).'</p>'.$correction->main('social',$_POST['id']));}
        catch(Throwable){header('Content-Type: text/html; charset=utf-8');echo '<p>'.Html::e($error->getMessage()).'</p><a href="/">Zur Redaktion</a>';}
    }
    elseif(in_array($_POST['operation']??null,\Kulturhus\Native\Files\Web\Library::OPERATIONS,true)&&is_string($_POST['csrf']??null)&&isset($db,$access,$mediaRoot,$_SESSION['native_csrf'])&&hash_equals($_SESSION['native_csrf'],$_POST['csrf'])){
        try{$_GET['section']='files';$correction=new Manager($db,$access,$mediaRoot,$_SESSION['native_csrf'],!$secureAccounts);header('Content-Type: text/html; charset=utf-8');echo $correction->shell('<p role="alert">'.Html::e($error->getMessage()).'</p>'.$correction->main('media',is_string($_POST['id']??null)?$_POST['id']:''));}
        catch(Throwable){header('Content-Type: text/html; charset=utf-8');echo '<p role="alert">'.Html::e($error->getMessage()).'</p><a href="/?view=media&amp;section=files">Zur Dateiablage</a>';}
    }
    elseif(($_POST['operation']??null)==='media-storage-recover'&&is_string($_POST['source_id']??null)&&preg_match('/\A[a-f0-9]{32}\z/D',$_POST['source_id'])&&isset($db,$access,$mediaRoot,$_SESSION['native_csrf'])){
        try{$_GET['section']='storage';$_GET['recover_source']=$_POST['source_id'];$correction=new Manager($db,$access,$mediaRoot,$_SESSION['native_csrf'],!$secureAccounts);header('Content-Type: text/html; charset=utf-8');echo $correction->shell('<p role="alert">'.Html::e($error->getMessage()).'</p>'.$correction->main('media',''));}
        catch(Throwable){header('Content-Type: text/html; charset=utf-8');echo '<p>'.Html::e($error->getMessage()).'</p><a href="/?view=media&amp;section=storage">Speicher prüfen</a>';}
    }
    elseif(($_POST['operation']??null)==='document-quota-save'&&isset($db,$access,$mediaRoot,$_SESSION['native_csrf'])){
        try{$_GET['section']='storage';$correction=new Manager($db,$access,$mediaRoot,$_SESSION['native_csrf'],!$secureAccounts);header('Content-Type: text/html; charset=utf-8');echo $correction->shell('<p role="alert">'.Html::e($error->getMessage()).'</p>'.$correction->main('media',''));}
        catch(Throwable){header('Content-Type: text/html; charset=utf-8');echo '<p>'.Html::e($error->getMessage()).'</p><a href="/?view=media&amp;section=storage">Speicher prüfen</a>';}
    }
    elseif(in_array($_POST['operation']??null,['meta-webhook-save','meta-webhook-resume'],true)&&is_string($_POST['csrf']??null)&&isset($db,$access,$mediaRoot,$_SESSION['native_csrf'])&&hash_equals($_SESSION['native_csrf'],$_POST['csrf'])){
        try{$access->require('website','manage');$access->require('social','manage');$state=['webhook'=>array_intersect_key($_POST,array_flip(['version','enabled']))];$correction=new Manager($db,$access,$mediaRoot,$_SESSION['native_csrf'],!$secureAccounts);header('Content-Type: text/html; charset=utf-8');echo $correction->shell('<p role="alert">'.Html::e($error->getMessage()).'</p>'.$correction->main('settings','',PHP_INT_MAX,$state));}
        catch(Throwable){header('Content-Type: text/html; charset=utf-8');echo '<p role="alert">'.Html::e($error->getMessage()).'</p><a href="/?view=settings">Zu den Verbindungen</a>';}
    }
    elseif(in_array($_POST['operation']??null,['website-design','news-policy','club-settings'],true)&&isset($db,$access,$mediaRoot,$_SESSION['native_csrf'])&&(($_POST['operation']??null)!=='club-settings'||(is_string($_POST['csrf']??null)&&hash_equals($_SESSION['native_csrf'],$_POST['csrf'])))){
        try{if(($_POST['operation']??null)==='club-settings')$access->require('website','manage');$correction=new Manager($db,$access,$mediaRoot,$_SESSION['native_csrf'],!$secureAccounts);header('Content-Type: text/html; charset=utf-8');echo $correction->shell('<p role="alert">'.Html::e($error->getMessage()).'</p>'.$correction->main('settings',''));}
        catch(Throwable){header('Content-Type: text/html; charset=utf-8');echo '<p>'.Html::e($error->getMessage()).'</p><a href="/?view=settings">Zur Vorlagenauswahl</a>';}
    }
    elseif(($_POST['operation']??'')==='stadtlandfunk-schedule'&&is_string($_POST['id']??null)&&isset($db,$access,$mediaRoot,$_SESSION['native_csrf'])){
        try{$correction=new Manager($db,$access,$mediaRoot,$_SESSION['native_csrf'],!$secureAccounts);$content=$correction->main('social',$_POST['id']);header('Content-Type: text/html; charset=utf-8');echo $correction->shell('<p role="alert">'.Html::e($error->getMessage()).'</p>'.$content);}
        catch(Throwable){header('Content-Type: text/html; charset=utf-8');echo '<p>'.Html::e($error->getMessage()).'</p><a href="/?view=social">Zur Redaktion</a>';}
    }
    elseif(in_array($_POST['operation']??null,['album-upload','album-attach'],true)&&is_string($_POST['id']??null)&&isset($db,$access,$mediaRoot,$_SESSION['native_csrf'])){
        try{$correction=new Manager($db,$access,$mediaRoot,$_SESSION['native_csrf'],!$secureAccounts);$content=$correction->main('media',$_POST['id']);header('Content-Type: text/html; charset=utf-8');echo $correction->shell('<p role="alert">'.Html::e($error->getMessage()).'</p>'.$content);}
        catch(Throwable){header('Content-Type: text/html; charset=utf-8');echo '<p>'.Html::e($error->getMessage()).'</p><a href="/?view=media">Zur Fotogalerie</a>';}
    }
    elseif(in_array($_POST['operation']??null,['media-remove','media-restore','media-removal-recover','media-retention-save','media-purge','media-purge-resume'],true)&&isset($db,$access,$mediaRoot,$_SESSION['native_csrf'])){
        try{$correction=new Manager($db,$access,$mediaRoot,$_SESSION['native_csrf'],!$secureAccounts);$_GET['section']=($_POST['operation']??'')==='media-remove'?'library':'removed';$imageId=is_string($_POST['id']??null)?$_POST['id']:'';header('Content-Type: text/html; charset=utf-8');echo $correction->shell('<p role="alert" id="media-result" tabindex="-1">'.Html::e($error->getMessage()).'</p>'.$correction->main('media',$imageId));}
        catch(Throwable){header('Content-Type: text/html; charset=utf-8');echo '<p role="alert">'.Html::e($error->getMessage()).'</p><a href="/?view=media&amp;section=library">Zur Mediathek</a>';}
    }
    elseif(in_array($_POST['operation']??null,['media-upload','media-metadata-save','media-quota-save','media-policy-save'],true)&&isset($db,$access,$mediaRoot,$_SESSION['native_csrf'])){
        try{$correction=new Manager($db,$access,$mediaRoot,$_SESSION['native_csrf'],!$secureAccounts);$_GET['section']='library';$imageId=is_string($_POST['id']??null)?$_POST['id']:'';$content=$correction->main('media',$imageId);header('Content-Type: text/html; charset=utf-8');echo $correction->shell('<p role="alert">'.Html::e($error->getMessage()).'</p>'.$content);}
        catch(Throwable){header('Content-Type: text/html; charset=utf-8');echo '<p>'.Html::e($error->getMessage()).'</p><a href="/?view=media&amp;section=library">Zur Mediathek</a>';}
    }
    elseif(in_array($_POST['operation']??'',['page-sections-save','page-save','page-fieldtypes-review'],true)&&is_string($_POST['id']??null)&&isset($db,$access,$mediaRoot,$_SESSION['native_csrf'])){
        try{$correction=new Manager($db,$access,$mediaRoot,$_SESSION['native_csrf'],!$secureAccounts);$failedView=in_array($view??'', ['website','news','events'],true)?$view:'website';$content=$correction->main($failedView,$_POST['id']);header('Content-Type: text/html; charset=utf-8');echo $correction->shell('<p role="alert">'.Html::e($error->getMessage()).'</p>'.$content);}
        catch(Throwable){header('Content-Type: text/html; charset=utf-8');echo '<p>'.Html::e($error->getMessage()).'</p><a href="/">Zur Redaktion</a>';}
    }
    else{header('Content-Type: text/html; charset=utf-8');echo '<p>'.Html::e($socialSaveError).'</p><a href="/">Zur Redaktion</a>';}
}catch(Throwable $error){
    // No DSN, SQL parameters, connection secrets or customer content in responses/logs.
    error_log('Native demo error: '.get_class($error).' at '.basename($error->getFile()).':'.$error->getLine());
    http_response_code(500);header('Cache-Control: no-store');
    if(($view??null)==='social'&&Kulturhus\Native\Web\Communication\VideoPolicyForm::operation($_POST['operation']??null)&&isset($db,$access,$mediaRoot,$_SESSION['native_csrf'])){
        try{$access->require('social','read');$message=Kulturhus\Native\Language\VideoPolicyMessages::text('Speicherung der Regel nicht bestätigt. Deine Eingaben bleiben erhalten. Gespeicherte Regel prüfen.',(new Kulturhus\Native\Language\Catalog($db,$access))->messages());$canManage=true;try{$access->require('social','manage');}catch(DomainException){$canManage=false;}
            if($json){header('Content-Type: application/json');echo json_encode(['error'=>$message,'csrf'=>$_SESSION['native_csrf'],'can_manage'=>$canManage],JSON_THROW_ON_ERROR);exit;}
            $state=Kulturhus\Native\Web\Communication\VideoPolicyForm::restore($_POST);$state['error']=$message;$_GET['section']='compose';$correction=new Manager($db,$access,$mediaRoot,$_SESSION['native_csrf'],!$secureAccounts);header('Content-Type: text/html; charset=utf-8');echo$correction->shell($correction->main('social',$state['id'],PHP_INT_MAX,['video_policy_only'=>$state]));exit;
        }catch(Throwable){/* Current source binding, rights and original form remain authoritative. */}
    }
    if(($view??null)==='social'&&($_POST['operation']??null)==='social-video-reuse'&&isset($db,$access,$mediaRoot,$_SESSION['native_csrf'])){
        try{$access->require('social','read');$message=Kulturhus\Native\Language\VideoReuseMessages::text('Videoübernahme nicht bestätigt. Ursprüngliche Auswahl und Beitragsfassung behalten.',(new Kulturhus\Native\Language\Catalog($db,$access))->messages());$edit=Kulturhus\Native\Web\Communication\VideoReuseForm::canEdit($db,$access,$_POST,$mediaRoot);
            if($json){header('Content-Type: application/json');header('Cache-Control: no-store');echo json_encode(['error'=>$message,'csrf'=>$_SESSION['native_csrf'],'can_edit'=>$edit],JSON_THROW_ON_ERROR);exit;}
            $state=Kulturhus\Native\Web\Communication\VideoReuseForm::correction($db,$access,$mediaRoot,$_POST,$message);$_GET['section']='compose';$correction=new Manager($db,$access,$mediaRoot,$_SESSION['native_csrf'],!$secureAccounts);header('Content-Type: text/html; charset=utf-8');echo$correction->shell($correction->main('social',$state['id'],PHP_INT_MAX,['video_reuse_only'=>$state]));exit;
        }catch(Throwable){/* Current private source rights remain authoritative. */}
    }    if(($view??null)==='social'&&($_POST['operation']??null)==='social-video-recover'&&isset($db,$access,$mediaRoot,$_SESSION['native_csrf'])){
        try{$access->require('social','read');$message=Kulturhus\Native\Language\VideoRecoveryMessages::text('Wiederherstellung nicht bestätigt. Ursprüngliche Dateiauswahl und gespeicherten Vorgang prüfen.',(new Kulturhus\Native\Language\Catalog($db,$access))->messages());$manage=true;$edit=true;try{$access->require('social','manage');}catch(DomainException){$manage=false;}try{$access->require('social','edit');}catch(DomainException){$edit=false;}
            if($json){header('Content-Type: application/json');header('Cache-Control: no-store');echo json_encode(['error'=>$message,'csrf'=>$_SESSION['native_csrf'],'can_manage'=>$manage,'can_edit'=>$edit],JSON_THROW_ON_ERROR);exit;}
            $state=Kulturhus\Native\Web\Communication\VideoRecoveryForm::correction($db,$access,$mediaRoot,$_POST,$message);$_GET['section']='compose';$correction=new Manager($db,$access,$mediaRoot,$_SESSION['native_csrf'],!$secureAccounts);header('Content-Type: text/html; charset=utf-8');echo$correction->shell($correction->main('social',$state['id'],PHP_INT_MAX,['video_recovery_only'=>$state]));exit;
        }catch(Throwable){/* Current private source rights remain authoritative. */}
    }
    if(($view??null)==='social'&&Kulturhus\Native\Web\Communication\VideoRemovalForm::operation($_POST['operation']??null)&&isset($db,$access,$mediaRoot,$_SESSION['native_csrf'])){
        try{$access->require('social','read');$message=Kulturhus\Native\Language\VideoFileMessages::text('Dateivorgang nicht bestätigt. Ursprünglichen Vorgang und Dateizustand prüfen.',(new Kulturhus\Native\Language\Catalog($db,$access))->messages());$canManage=true;try{$access->require('social','manage');}catch(DomainException){$canManage=false;}
            if($json){header('Content-Type: application/json');echo json_encode(['error'=>$message,'csrf'=>$_SESSION['native_csrf'],'can_manage'=>$canManage],JSON_THROW_ON_ERROR);exit;}
            $state=Kulturhus\Native\Web\Communication\VideoRemovalForm::correction($db,$access,$mediaRoot,$_POST,$message);$_GET['section']='compose';$correction=new Manager($db,$access,$mediaRoot,$_SESSION['native_csrf'],!$secureAccounts);header('Content-Type: text/html; charset=utf-8');echo$correction->shell($correction->main('social',$state['id'],PHP_INT_MAX,['video_file_only'=>$state]));exit;
        }catch(Throwable){/* Current source binding, rights and original form remain authoritative. */}
    }
    if(($view??null)==='social'&&($_POST['operation']??null)==='social-video-archive'&&isset($db,$access,$mediaRoot,$_SESSION['native_csrf'])){
        try{$access->require('social','read');$message=Kulturhus\Native\Language\VideoCatalogueMessages::text('Speicherung der Archivwahl nicht bestätigt. Ursprünglichen Vorgang behalten und gespeicherte Archivwahl prüfen.',(new Kulturhus\Native\Language\Catalog($db,$access))->messages());$canEdit=true;try{$access->require('social','edit');}catch(DomainException){$canEdit=false;}
            if($json){header('Content-Type: application/json');echo json_encode(['error'=>$message,'csrf'=>$_SESSION['native_csrf'],'can_edit'=>$canEdit],JSON_THROW_ON_ERROR);exit;}
            $state=Kulturhus\Native\Web\Communication\VideoArchiveForm::restore($_POST);$state['error']=$message;$_GET['section']='compose';$correction=new Manager($db,$access,$mediaRoot,$_SESSION['native_csrf'],!$secureAccounts);header('Content-Type: text/html; charset=utf-8');echo$correction->shell($correction->main('social',$state['id'],PHP_INT_MAX,['video_archive_only'=>$state]));exit;
        }catch(Throwable){/* Current source binding, rights and original form remain authoritative. */}
    }
    if(($view??null)==='social'&&($_POST['operation']??null)==='social-video-metadata-save'&&isset($db,$access,$mediaRoot,$_SESSION['native_csrf'])){
        try{$access->require('social','read');$message=Kulturhus\Native\Language\VideoMetadataMessages::text('Speicherung der Videoangaben nicht bestätigt. Deine Eingaben bleiben erhalten. Gespeicherte Angaben prüfen.',(new Kulturhus\Native\Language\Catalog($db,$access))->messages());$canEdit=true;$canProof=true;try{$access->require('social','edit');}catch(DomainException){$canEdit=false;}try{$access->require('social','manage');}catch(DomainException){$canProof=false;}
            if($json){header('Content-Type: application/json');echo json_encode(['error'=>$message,'csrf'=>$_SESSION['native_csrf'],'can_edit'=>$canEdit,'can_proof'=>$canProof],JSON_THROW_ON_ERROR);exit;}
            $state=Kulturhus\Native\Web\Communication\VideoMetadataForm::restore($_POST);$state['error']=$message;$_GET['section']='compose';$correction=new Manager($db,$access,$mediaRoot,$_SESSION['native_csrf'],!$secureAccounts);header('Content-Type: text/html; charset=utf-8');echo$correction->shell($correction->main('social',$state['id'],PHP_INT_MAX,['video_metadata_only'=>$state]));exit;
        }catch(Throwable){/* Current source binding, rights and original form remain authoritative. */}
    }
    if(($view??null)==='social'&&($_POST['operation']??null)==='social-video-save'&&isset($db,$access,$mediaRoot,$_SESSION['native_csrf'])){
        try{$access->require('social','read');$message=Kulturhus\Native\Language\VideoStudioMessages::text('Speicherung nicht bestätigt. Originaldatei und ursprünglichen Vorgang behalten. Gespeichertes Video prüfen.',(new Kulturhus\Native\Language\Catalog($db,$access))->messages());$canEdit=true;try{$access->require('social','edit');}catch(DomainException){$canEdit=false;}
            if($json){header('Content-Type: application/json');echo json_encode(['error'=>$message,'csrf'=>$_SESSION['native_csrf'],'can_edit'=>$canEdit],JSON_THROW_ON_ERROR);exit;}
            $state=Kulturhus\Native\Web\Communication\VideoUploadForm::correction($_POST,$_FILES,$message);$_GET['section']='compose';$correction=new Manager($db,$access,$mediaRoot,$_SESSION['native_csrf'],!$secureAccounts);header('Content-Type: text/html; charset=utf-8');echo$correction->shell($correction->main('social',$state['id'],PHP_INT_MAX,['video_only'=>$state]));exit;
        }catch(Throwable){/* Current access and form bindings remain authoritative. */}
    }

    if(in_array($view??null,['social','newsletter','press'],true)&&Kulturhus\Native\Web\Communication\PlanningFormState::operation($_POST['operation']??null)&&isset($db,$access,$mediaRoot,$_SESSION['native_csrf'])){
        try{$failure=Kulturhus\Native\Web\Communication\PlanningFormState::failure($db,$access,$view,$_POST,'',true);
            if($json){header('Content-Type: application/json');echo json_encode(['error'=>$failure['error']],JSON_THROW_ON_ERROR);exit;}
            $_GET=$failure['route'];$correction=new Manager($db,$access,$mediaRoot,$_SESSION['native_csrf'],!$secureAccounts);header('Content-Type: text/html; charset=utf-8');echo$correction->shell($correction->main($view,'',PHP_INT_MAX,['planning_failure'=>$failure]));exit;
        }catch(Throwable){/* Current rights or language unavailable: preserve the generic failure. */}
    }

    if(($view??null)==='social'&&($_POST['operation']??null)==='social-photo-upload'){
        $failure='Fotoupload nicht bestätigt. Datei, Beschreibung und ursprünglichen Vorgang behalten. Gespeichertes Foto prüfen.';
        try{if(isset($db,$access))$failure=Kulturhus\Native\Language\ImageStudioMessages::text($failure,(new Kulturhus\Native\Language\Catalog($db,$access))->messages());}catch(Throwable){}
        if($json){header('Content-Type: application/json');echo json_encode(['error'=>$failure],JSON_THROW_ON_ERROR);exit;}
        if(is_string($_POST['csrf']??null)&&isset($db,$access,$mediaRoot,$_SESSION['native_csrf'])&&hash_equals($_SESSION['native_csrf'],$_POST['csrf']))try{$state=Kulturhus\Native\Web\Communication\PhotoUploadForm::restore($_POST);$state['error']=$failure;$correction=new Manager($db,$access,$mediaRoot,$_SESSION['native_csrf'],!$secureAccounts);header('Content-Type: text/html; charset=utf-8');echo$correction->shell($correction->main('social',$state['id'],PHP_INT_MAX,['photo_only'=>$state]));exit;}catch(Throwable){}
        echo Html::e($failure);exit;
    }
    if($json&&($view??null)==='social'&&in_array($_POST['operation']??null,['social-canvas-save','social-canvas-review'],true)){header('Content-Type: application/json');$failure='Bildspeicherung nicht bestätigt. Gestaltung und ursprünglichen Vorgang behalten. Gespeicherte Bildfassung prüfen.';if(isset($db,$access))try{$failure=Kulturhus\Native\Language\ImageStudioMessages::text($failure,(new Kulturhus\Native\Language\Catalog($db,$access))->messages());}catch(Throwable){}echo json_encode(['error'=>$failure],JSON_THROW_ON_ERROR);exit;}
    if($json){$failure='Der Vorgang ist fehlgeschlagen. Bitte erneut versuchen.';if(($view??null)==='social'&&($_POST['operation']??null)==='communication-save'&&isset($db,$access)){try{$failure=Kulturhus\Native\Language\ContentMessages::text('Speicherung nicht bestätigt. Deine Angaben bleiben erhalten. Gespeicherten Stand prüfen oder unverändert erneut speichern.',(new Kulturhus\Native\Language\Catalog($db,$access))->contentMessages());}catch(Throwable){/* Do not expose the underlying storage failure. */}}if(isset($db,$access)&&Kulturhus\Native\Web\Communication\ConversationRequest::applies($view??'',$_POST,$_GET))try{$failure=Kulturhus\Native\Language\ConversationMessages::text($failure,(new Kulturhus\Native\Language\Catalog($db,$access))->messages());}catch(Throwable){}header('Content-Type: application/json');echo json_encode(['error'=>$failure],JSON_THROW_ON_ERROR);}
    elseif(($view??null)==='newsletter'&&($_POST['operation']??null)==='communication-save'&&is_string($_POST['csrf']??null)&&isset($db,$access,$mediaRoot,$_SESSION['native_csrf'])&&hash_equals($_SESSION['native_csrf'],$_POST['csrf'])){
        try{$access->require('newsletter','edit');$state=Kulturhus\Native\Web\Communication\NewsletterComposerState::restore($_POST);$_GET['section']='compose';$correction=new Manager($db,$access,$mediaRoot,$_SESSION['native_csrf'],!$secureAccounts);header('Content-Type: text/html; charset=utf-8');echo $correction->shell('<p role="alert">Speichern nicht bestätigt. Eingaben erhalten.</p>'.$correction->main('newsletter',$state['document']['id'],PHP_INT_MAX,$state));}
        catch(Throwable){header('Content-Type: text/html; charset=utf-8');echo '<p>Speichern nicht bestätigt.</p><a href="/?view=newsletter">Zur Newsletterredaktion</a>';}
    }
    elseif(($view??null)==='press'&&($_POST['operation']??null)==='communication-save'&&is_string($_POST['csrf']??null)&&isset($db,$access,$mediaRoot,$_SESSION['native_csrf'])&&hash_equals($_SESSION['native_csrf'],$_POST['csrf'])){
        try{$access->require('press','edit');$state=Kulturhus\Native\Web\Communication\PressComposerState::restore($_POST);$_GET['section']='compose';$correction=new Manager($db,$access,$mediaRoot,$_SESSION['native_csrf'],!$secureAccounts);header('Content-Type: text/html; charset=utf-8');echo $correction->shell('<p role="alert">Speichern nicht bestätigt. Eingaben erhalten.</p>'.$correction->main('press',$state['document']['id'],PHP_INT_MAX,$state));}
        catch(Throwable){header('Content-Type: text/html; charset=utf-8');echo '<p>Speichern nicht bestätigt.</p><a href="/?view=press">Zur Presseredaktion</a>';}
    }
    elseif(($view??null)==='social'&&($_POST['operation']??null)==='communication-save'&&is_string($_POST['csrf']??null)&&isset($db,$access,$mediaRoot,$_SESSION['native_csrf'])&&hash_equals($_SESSION['native_csrf'],$_POST['csrf'])){
        try{$access->require('social','edit');$state=Kulturhus\Native\Web\Communication\SocialComposerState::restore($_POST);$_GET['section']='compose';$correction=new Manager($db,$access,$mediaRoot,$_SESSION['native_csrf'],!$secureAccounts);$message=Kulturhus\Native\Language\ContentMessages::text('Speicherung nicht bestätigt. Deine Angaben bleiben erhalten. Gespeicherten Stand prüfen oder unverändert erneut speichern.',(new Kulturhus\Native\Language\Catalog($db,$access))->contentMessages());header('Content-Type: text/html; charset=utf-8');echo $correction->shell('<p role="alert">'.Html::e($message).'</p>'.$correction->main('social',$state['document']['id'],PHP_INT_MAX,$state));}
        catch(Throwable){header('Content-Type: text/html; charset=utf-8');echo '<p>Speichern nicht bestätigt.</p><a href="/?view=social">Zur Redaktion</a>';}
    }
    elseif(($view??null)==='social'&&($_POST['operation']??null)==='social-mail-settings'&&is_string($_POST['csrf']??null)&&isset($db,$access,$mediaRoot,$_SESSION['native_csrf'])&&hash_equals($_SESSION['native_csrf'],$_POST['csrf'])){
        try{$access->require('website','manage');$access->require('social','manage');$_GET['section']='mail';$correction=new Manager($db,$access,$mediaRoot,$_SESSION['native_csrf'],!$secureAccounts);$mailError=Kulturhus\Native\Language\ConversationMessages::text('Mailmodul nicht bestätigt. Ursprüngliche Einstellungen beibehalten und gespeicherten Stand getrennt prüfen.',(new Kulturhus\Native\Language\Catalog($db,$access))->messages());header('Content-Type: text/html; charset=utf-8');echo$correction->shell('<p role="alert">'.Html::e($mailError).'</p>'.$correction->main('social',''));}
        catch(Throwable){header('Content-Type: text/html; charset=utf-8');echo'<p role="alert">'.Html::e($mailError??'Vorgang nicht bestätigt. Ursprüngliche Nachricht prüfen.').'</p>';}
    }
    elseif(($view??null)==='social'&&Kulturhus\Native\Web\Communication\HumanFormState::submitted($_POST)&&is_string($_POST['csrf']??null)&&isset($db,$access,$mediaRoot,$_SESSION['native_csrf'])&&hash_equals($_SESSION['native_csrf'],$_POST['csrf'])){
        try{header('Content-Type: text/html; charset=utf-8');echo Kulturhus\Native\Web\Communication\HumanFormState::correction($db,$access,$mediaRoot,$_SESSION['native_csrf'],!$secureAccounts,$_POST,'Vorgang nicht bestätigt. Ursprüngliche Nachricht prüfen.');}
        catch(Throwable){echo 'Der Vorgang ist fehlgeschlagen. Bitte erneut versuchen.';}
    }
    else echo 'Der Vorgang ist fehlgeschlagen. Bitte erneut versuchen.';
}
