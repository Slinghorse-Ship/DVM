<?php declare(strict_types=1);
namespace Kulturhus\Native\Integration;

use Kulturhus\Verein\ProductionRuntime;
use Kulturhus\Native\Auth\Runtime;
use Kulturhus\Native\Shared\Access;
use Kulturhus\Native\Web\{Account,Accounts};

/** Native HTTPS front controller. No fixture, schema installation or legacy bootstrap. */
final class ProductionSite
{
    public static function serve(\PDO $db): void
    {
        try { $gate=\Kulturhus\Native\Shared\OperationGate::shared((string)getenv('VEREIN_PRIVATE_ROOT')); }
        catch (\DomainException) { http_response_code(503); header('Retry-After: 60'); echo 'Kurze Wartung. Bitte später erneut versuchen.'; return; }
        // Included API controllers can exit; the owned lock also closes at request shutdown.
        register_shutdown_function(static fn()=>$gate->close());
        try { self::dispatch($db); } finally { $gate->close(); }
    }

    private static function dispatch(\PDO $db): void
    {
        require_once dirname(__DIR__,2).'/src/Infrastructure/Runtime/ProductionRuntime.php';
        if(ProductionRuntime::enabled()&&ProductionRuntime::nativeManager()&&CanonicalOrigin::redirect((string)getenv('VEREIN_PUBLIC_URL')))return;
        $host = $_SERVER['HTTP_HOST'] ?? '';
        if (!ProductionRuntime::enabled() || !ProductionRuntime::nativeManager()
            || !in_array($host, ProductionRuntime::allowedHosts(), true)
            || !in_array($_SERVER['HTTPS'] ?? '', ['on','1'], true)
            || !in_array(getenv('KULTURHUS_LOCAL_DEMO'), [false,'','0'], true)) {
            http_response_code(403); echo 'Konfigurierte HTTPS-Domain erforderlich.'; return;
        }
        $origin = 'https://'.$host;
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        header('X-Robots-Tag: noindex, nofollow');
        $publicJsonPreflight=$method==='OPTIONS'&&in_array(rtrim($path,'/'),['/feeds/events.json','/feeds/veranstaltungen'],true);
        $apiMethod=ApiRoutes::matches($path);
        if (!in_array($method, ['GET','HEAD','POST'], true)&&!$publicJsonPreflight&&!$apiMethod) {
            http_response_code(405); header('Allow: GET, HEAD, POST'); return;
        }
        if ($method === 'POST' && !$apiMethod && isset($_SERVER['HTTP_ORIGIN']) && $_SERVER['HTTP_ORIGIN'] !== $origin
            && !($_SERVER['HTTP_ORIGIN']==='null' && ($_SERVER['HTTP_SEC_FETCH_SITE']??'')==='same-origin')) {
            http_response_code(403); return;
        }
        header('X-Content-Type-Options: nosniff'); header('Referrer-Policy: no-referrer');
        header("Content-Security-Policy: default-src 'none'; style-src 'self'; style-src-attr 'unsafe-inline'; script-src 'self'; img-src 'self' data: blob:; connect-src 'self'; media-src 'self' blob:; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
        $root = dirname(__DIR__,3);
        $private = (string)getenv('VEREIN_PRIVATE_ROOT');
        $media = (string)getenv('KH_NATIVE_MANAGER_MEDIA_ROOT');
        $club = ProductionRuntime::club();
        $public = new Access($db, self::publicActor($club));
        if (PublicSite::serve($db, $public, $media, $private, $club, $origin, \Kulturhus\Native\Demo\Runtime::active()?$origin.'/demo':null)) return;
        $accountPath = rtrim($path,'/');
        if (\Kulturhus\Native\Web\LoginAliases::serve($method,$accountPath,$_GET)) return;
        if($accountPath==='/account'){header('Cache-Control: no-store');$query=['view'=>'settings','tab'=>'account','account_native'=>'1'];foreach(['section','session_page']as$key)if(is_string($_GET[$key]??null))$query[$key]=$_GET[$key];header('Location: /vereinsverwaltung/?'.http_build_query($query,'','&',PHP_QUERY_RFC3986),true,$method==='POST'?307:303);return;}
        if (in_array($accountPath, ['/login','/account','/invite','/reset','/forgot','/accounts'], true)) {
            ManagerSession::start(); $auth = Runtime::service($db); $state = []; $error = '';
            if (isset($_SESSION['auth_token'])) try { $auth->resolve($_SESSION['auth_token']); }
                catch (\DomainException) { unset($_SESSION['auth_token'],$_SESSION['actor']); }
            try {
                if ($accountPath === '/accounts') {
                    $bridge = ManagerBridge::fromNativeSession($db,$auth,is_string($_SESSION['auth_token']??null)?$_SESSION['auth_token']:'');
                    $page = new Accounts($db,$auth,$bridge->access(),$_SESSION['csrf']);
                    if ($method === 'POST') try { $state = $page->action($_POST); } catch (\Kulturhus\Native\Auth\AccountOperationUnconfirmed $failure) { http_response_code(503); $error=$failure->getMessage(); } catch (\DomainException $failure) { http_response_code(422); $error=$failure->getMessage(); }
                    $html = $page->render($state,$error,$_GET);
                } else {
                    $page = Account::forManager($db,$auth,$_SESSION['csrf'],hash('sha256',($_SERVER['REMOTE_ADDR']??'').'|'.($_SERVER['HTTP_USER_AGENT']??'')));
                    $page->withDemoLink(\Kulturhus\Native\Demo\Deployment::available($_SERVER['DOCUMENT_ROOT']??''));
                    $page->withSystemNavigation(\Kulturhus\Native\System\Navigation::resolver($db));
                    if ($method === 'POST') try {
                        $state = $page->action($_POST,$_SESSION);
                        if (isset($state['redirect'])) { header('Location: '.$state['redirect'],true,303); return; }
                    } catch (\Kulturhus\Native\Appearance\Conflict $e) { http_response_code(409); $error = $e->getMessage(); }
                    catch (\DomainException $e) { http_response_code(422); $error = $e->getMessage(); }
                    $html = $page->render($accountPath,$_GET,$_SESSION,$state,$error);
                }
                header('Cache-Control: no-store'); header('Content-Type: text/html; charset=utf-8');
                if ($method !== 'HEAD') echo $html;
            } catch (\DomainException) { http_response_code(403); echo 'Kontozugang nicht verfügbar.'; }
            return;
        }
        if ($path === '/assets/account.css') {
            header('Content-Type: text/css; charset=utf-8'); if ($method !== 'HEAD') readfile(__DIR__.'/../Web/assets/account.css'); return;
        }
        if (!str_starts_with($path,'/vereinsverwaltung/')) { http_response_code(404); return; }
        $relative = rawurldecode(substr($path,strlen('/vereinsverwaltung/')));
        if ($relative === '' || $relative === 'index.php') { require $root.'/vereinsverwaltung/public/index.php'; return; }
        if (Assets::serve($relative)) return;
        if (str_contains($relative,"\0") || str_contains($relative,'\\') || in_array('..',explode('/',$relative),true)) { http_response_code(404); return; }
        $publicRoot = realpath($root.'/vereinsverwaltung/public'); $file = realpath($publicRoot.'/'.$relative);
        $types = ['css'=>'text/css','js'=>'text/javascript','mjs'=>'text/javascript','svg'=>'image/svg+xml','png'=>'image/png','jpg'=>'image/jpeg','jpeg'=>'image/jpeg','gif'=>'image/gif','webp'=>'image/webp','ico'=>'image/x-icon','woff'=>'font/woff','woff2'=>'font/woff2','ttf'=>'font/ttf','webmanifest'=>'application/manifest+json'];
        $type = $types[strtolower(pathinfo($relative,PATHINFO_EXTENSION))] ?? null;
        if (!$file || !is_file($file) || !str_starts_with($file,$publicRoot.'/') || !$type) { http_response_code(404); return; }
        \Kulturhus\Native\Web\AssetResponse::send($file,$type);
    }

    public static function publicActor(string $club): string { return 'public-'.substr(ManagerBridge::clubKey($club),0,48); }
}
