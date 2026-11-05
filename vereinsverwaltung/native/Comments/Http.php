<?php declare(strict_types=1);
namespace Kulturhus\Native\Comments;

use DomainException;
use InvalidArgumentException;
use Kulturhus\Native\Cms\{Conflict,Pages};
use Kulturhus\Native\Integration\{ManagerBridge,ManagerSession};
use Kulturhus\Native\Shared\{Access,Identity,SecretBox};
use Kulturhus\Native\Web\{Html,PublicLayout};
use PDO;

/** Personal form contexts belong to this uncached endpoint, never to cached website HTML. */
final class Http
{
    public static function serve(PDO $db,Access $publicAccess,SecretBox $box,string $origin,?string $csrf=null):bool
    {
        $path=rtrim(parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH)?:'/','/');if($path!=='/_comments')return false;
        header('Cache-Control: private, no-store, max-age=0');header('Content-Type: text/html; charset=utf-8');header('X-Robots-Tag: noindex, nofollow');header('X-Content-Type-Options: nosniff');header('Referrer-Policy: no-referrer');
        $method=$_SERVER['REQUEST_METHOD']??'GET';
        if(!in_array($method,['GET','HEAD','POST'],true)){header('Allow: GET, HEAD, POST');self::error(405,'Diese Anfrage ist nicht erlaubt.',$method);return true;}
        if($method==='POST'&&isset($_SERVER['HTTP_ORIGIN'])&&!hash_equals($origin,$_SERVER['HTTP_ORIGIN'])&&!($_SERVER['HTTP_ORIGIN']==='null'&&($_SERVER['HTTP_SEC_FETCH_SITE']??'')==='same-origin')){self::error(403,'Formular neu öffnen.',$method);return true;}
        $nativeSession=$csrf!==null;
        if($csrf===null){ManagerSession::start();$csrf=$_SESSION['csrf']??null;}
        elseif(session_status()!==PHP_SESSION_ACTIVE){self::error(403,'Sitzung neu öffnen.',$method);return true;}
        if(!is_string($csrf)||!preg_match('/\A[a-f0-9]{64}\z/D',$csrf)){self::error(403,'Sitzung neu öffnen.',$method);return true;}
        $client=$_SESSION['comment_client']??null;if(!is_string($client)||!preg_match('/\A[a-f0-9]{64}\z/D',$client))$_SESSION['comment_client']=$client=bin2hex(random_bytes(32));
        $forms=$_SESSION['comment_forms']??[];if(!is_array($forms))$forms=[];
        foreach($forms as$id=>$context)if(!is_array($context)||($context['expires']??0)<time())unset($forms[$id]);
        $_SESSION['comment_forms']=$forms;
        $streams=new Streams($db,$publicAccess,$box);$author=self::author($db);$values=[];$error='';$status=200;$receipt=null;$reply=null;$page=null;$stream='comments';$number=null;$recover=false;
        try{
            if($method==='POST'){
                if(!is_string($_POST['comment_csrf']??null)||!hash_equals($csrf,$_POST['comment_csrf'])){self::error(403,'Formular abgelaufen. Bitte neu öffnen.',$method);return true;}
                $form=$_POST['comment_form']??null;
                if(!is_string($form)||!preg_match('/\A[a-f0-9]{64}\z/D',$form)||!isset($forms[$form])){self::error(409,'Kommentarformular abgelaufen. Seite neu öffnen.',$method);return true;}
                $context=$forms[$form];$page=$context['page'];$stream=$context['stream'];$reply=$context['parent'];
                if(array_diff(array_keys($_POST),['comment_csrf','comment_form','name','email','body','format']))throw new InvalidArgumentException('Kommentarangaben prüfen.');
                $values=array_intersect_key($_POST,array_flip(['name','email','body','format']));
                foreach($values as$value)if(!is_string($value))throw new InvalidArgumentException('Kommentarangaben als Text eingeben.');
                $recover=true;
                try{
                    $result=$streams->submit($page,$stream,$values+['parent'=>$reply],$context['request'],$client,self::rateIdentity($author),$author);
                    $_SESSION['comment_alias']=Values::text($values['name']??'',100);
                    header('Location: /_comments?'.http_build_query(['id'=>$page,'stream'=>$stream,'receipt'=>$result['id']],'','&',PHP_QUERY_RFC3986).'#comment-compose',true,303);return true;
                }catch(Conflict $failure){$error=$failure->getMessage();$status=409;}catch(DomainException|InvalidArgumentException $failure){$error=$failure->getMessage();$status=422;}
            }else{
                $page=Values::id($_GET['id']??null);$stream=Values::stream($_GET['stream']??'comments');
                if(isset($_GET['seite']))$number=self::number($_GET['seite'],50001);
                if(isset($_GET['reply']))$reply=Values::id($_GET['reply']);
                if(isset($_GET['receipt'])){$receipt=$streams->ownReceipt(Values::id($_GET['receipt']),$client);if($receipt['page_id']!==$page||$receipt['stream']!==$stream)throw new DomainException('Eigener Kommentar nicht verfügbar.');}
            }
            $list=$streams->publicList($page,$stream,$number);
            $parent=$reply!==null?$streams->publicParent($page,$stream,$reply):null;
            if($reply!==null&&$parent===null)throw new DomainException('Auf diesen Kommentar kann nicht geantwortet werden.');
            $form=bin2hex(random_bytes(32));$_SESSION['comment_forms'][$form]=['page'=>$page,'stream'=>$stream,'parent'=>$reply,'request'=>bin2hex(random_bytes(32)),'expires'=>time()+3600];
            while(count($_SESSION['comment_forms'])>20)array_shift($_SESSION['comment_forms']);
            $state=['list'=>$list,'stream'=>$stream,'reply'=>$parent,'receipt'=>$receipt,'error'=>$error,'values'=>$values,'name'=>$_SESSION['comment_alias']??($author?->user()['name']??''),'can_submit'=>$list['settings']['anonymous']||$author!==null];
            $content=PublicView::render($state,$csrf,$form);$pages=new Pages($db,$publicAccess);$identity=Identity::forClub($db,$publicAccess->club());
            $html=PublicLayout::render('Kommentare · '.$list['original']['title'],'Kommentare zur veröffentlichten Vereinsseite.',$content,$pages->navigation(),$list['original']['slug'],$origin,$nativeSession?'/?view=website':'/vereinsverwaltung/',$identity,true,$pages->footerNavigation());
            http_response_code($status);if($method!=='HEAD')echo$html;
        }catch(InvalidArgumentException $failure){self::error(400,$failure->getMessage(),$method);}catch(DomainException $failure){
            if($method==='POST'&&$recover){http_response_code($status===409?409:422);echo PublicView::recovery($values,$error!==''?$error:$failure->getMessage(),'/_comments?'.http_build_query(['id'=>$page,'stream'=>$stream],'','&',PHP_QUERY_RFC3986));}
            else self::error(404,$failure->getMessage(),$method);
        }
        return true;
    }

    /** Optional current real account; an old manager actor or a public read identity is never an author. */
    private static function author(PDO $db):?Access
    {
        $token=$_SESSION['auth_token']??null;if(!is_string($token)||$token==='')return null;
        try{$auth=\Kulturhus\Native\Auth\Runtime::service($db);return ManagerBridge::fromNativeSession($db,$auth,$token)->access();}catch(DomainException){return null;}
    }

    private static function rateIdentity(?Access $author):string
    {
        $address=$_SERVER['REMOTE_ADDR']??'';if(!is_string($address)||strlen($address)>64||!filter_var($address,FILTER_VALIDATE_IP))$address='unknown';
        return'ip:'.$address.($author!==null?':account:'.$author->user()['id']:'');
    }

    public static function number(mixed $number,int $maximum):int
    {
        if(!is_string($number)&&!is_int($number))throw new InvalidArgumentException('Eine Seite auswählen.');$text=(string)$number;
        if(!preg_match('/\A[1-9][0-9]{0,6}\z/D',$text)||(int)$text>$maximum)throw new InvalidArgumentException('Eine gültige Seite auswählen.');return(int)$text;
    }

    private static function error(int $status,string $message,string $method):void
    {
        http_response_code($status);if($method!=='HEAD')echo'<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Kommentare</title></head><body><main><h1>Kommentare</h1><p role="alert">'.Html::e($message).'</p><a href="/">Zur Website</a></main></body></html>';
    }
}
