<?php declare(strict_types=1);
namespace Kulturhus\Native\Cache;
use Kulturhus\Native\Language\CacheMessages;
use Kulturhus\Native\Web\Html;

final class Controller
{
    /** Shared HTTP contract; all write routes require the original session CSRF. */
    public static function request(Administration $admin,string $method,array $query,array $post,string $csrf):array {
        if(!in_array($method,['GET','HEAD','POST'],true))throw new \DomainException('Diese Anfrage ist nicht erlaubt.',405);
        if($method==='POST'){
            if($csrf===''||!is_string($post['csrf']??null)||!hash_equals($csrf,$post['csrf']))throw new \DomainException('Formular abgelaufen. Bitte neu laden.',403);
            return ['removed'=>$admin->action(Administration::scalar($post,'action'),$post)];
        }
        $page=Administration::scalar($query,'cache_page','1');if(!preg_match('/^[1-9][0-9]{0,5}$/D',$page))throw new \DomainException('Cacheseite prüfen.');
        return $admin->listing((int)$page,Administration::optional($query,'namespace'));
    }
    public static function serve(Administration $admin,string $csrf,bool $workspace,string $view,string $tab,array $messages=[],string $locale='de'):void {
        header('Cache-Control: no-store');$json=str_contains($_SERVER['HTTP_ACCEPT']??'','application/json')||($_GET['cache_json']??'')==='1';$method=$_SERVER['REQUEST_METHOD']??'GET';
        $text=static fn(string $source):string=>CacheMessages::text($source,$messages);
        $originalAllowed=false;
        try{
            if(!$workspace||$view!=='settings'||$tab!=='cache')throw new \DomainException('Keine Berechtigung für die Cacheverwaltung.',403);
            $originalAllowed=$method==='POST'&&$csrf!==''&&is_string($_POST['csrf']??null)&&hash_equals($csrf,$_POST['csrf']);
            $result=self::request($admin,$method,$_GET,$_POST,$csrf);
            if($method==='POST'&&!$json){$_SESSION['notice']=$text('Cache aktualisiert.');header('Location: /vereinsverwaltung/?view=settings&tab=cache',true,303);return;}
            if($json){header('Content-Type: application/json; charset=utf-8');if($method!=='HEAD')echo json_encode($result,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);}
        }catch(\Throwable $error){
            $code=in_array($error->getCode(),[403,405],true)?$error->getCode():($error instanceof \DomainException||$error instanceof \InvalidArgumentException?422:503);
            $accessDenied=false;try{$admin->requireAccess();}catch(\Throwable){$code=403;$originalAllowed=false;$accessDenied=true;}
            $message=$accessDenied?'Keine Berechtigung für die Cacheverwaltung.':($error instanceof \DomainException||$error instanceof \InvalidArgumentException?$error->getMessage():'Cache nicht verfügbar. Bitte erneut versuchen.');
            http_response_code($code);if($code===405)header('Allow: GET, HEAD, POST');
            if($json){header('Content-Type: application/json; charset=utf-8');if($method!=='HEAD')echo json_encode(['error'=>$text($message)],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);return;}
            header('Content-Type: text/html; charset=utf-8');if($method==='HEAD')return;
            $original='';if($originalAllowed){$original='<section><h2>'.Html::e($text('Ursprüngliche Angaben')).'</h2><dl>';foreach(['action'=>'Aktion','namespace'=>'Bereich','entry'=>'Eintrag','tag'=>'Markierung']as$key=>$label)if(array_key_exists($key,$_POST)){$raw=$_POST[$key];$value=is_string($raw)?$raw:json_encode($raw,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);if($key==='action'&&is_string($raw)){$actionLabel=match($raw){'cache-clear'=>'Cache leeren','cache-cleanup'=>'Abgelaufene entfernen','cache-invalidate-tag'=>'Markierte Einträge entfernen','cache-delete'=>'Entfernen',default=>null};if($actionLabel!==null)$value=$text($actionLabel);}$original.='<dt>'.Html::e($text($label)).'</dt><dd><pre>'.Html::e($value).'</pre></dd>';}$original.='</dl><p>'.Html::e($text('Ihre ursprüngliche Anfrage bleibt erhalten. Öffnen Sie den aktuellen Cache bewusst, bevor Sie eine neue Aktion bestätigen.')).'</p></section>';}
            echo '<!doctype html><html lang="'.Html::e($locale).'"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'.Html::e($text('Cache')).'</title><link rel="stylesheet" href="/assets/bootstrap-6.0.0-alpha.1.min.css"><link rel="stylesheet" href="/vereinsverwaltung/cache-interface.css"><main class="container py-4" data-cache-error><h1>'.Html::e($text('Cache')).'</h1><p role="alert">'.Html::e($text($message)).'</p>'.$original.'<a class="btn btn-outline theme-secondary px-3 py-3" href="/vereinsverwaltung/?view=settings&amp;tab=cache">'.Html::e($text('Cache erneut öffnen')).'</a></main></html>';
        }
    }
}
