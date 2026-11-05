<?php declare(strict_types=1);
namespace Kulturhus\Native\Demo;

/** A separate cookie and private session; never shares a public role or manager login. */
final class OperatorPage
{
    public static function serve(array $configuration):void
    {
        if(session_status()!==PHP_SESSION_NONE)throw new \DomainException('Getrennte Betreiber-Sitzung erforderlich.');
        $directory=$configuration['private_root'].'/operator-sessions';
        if(!is_dir($directory)||is_link($directory)||(fileperms($directory)&0077)!==0)throw new \DomainException('Privater Betreiber-Sitzungsspeicher fehlt.');
        session_module_name('files');session_name('DVM_DEMO_OPERATOR_'.substr($configuration['instance'],0,12));session_save_path($directory);
        session_start(['use_strict_mode'=>1,'use_only_cookies'=>1,'cookie_httponly'=>1,'cookie_secure'=>true,'cookie_samesite'=>'Strict','cookie_path'=>'/demo/operator','cookie_lifetime'=>900,'gc_maxlifetime'=>900]);
        $_SESSION['csrf']??=bin2hex(random_bytes(32));$operator=new Operator($configuration);$error='';$method=$_SERVER['REQUEST_METHOD']??'GET';
        if(!in_array($method,['GET','HEAD','POST'],true)){http_response_code(405);header('Allow: GET, HEAD, POST');return;}
        if($method==='POST')try{
            Runtime::csrf($_POST['csrf']??null);$operation=$_POST['operation']??'';
            if($operation==='login'){
                if(!is_string($_POST['password']??null))throw new \DomainException('Betreiberanmeldung nicht bestätigt.');
                $auth=$operator->login($_POST['password'],$_SERVER['REMOTE_ADDR']??'');session_regenerate_id(true);$_SESSION['operator']=$auth;
            }elseif($operation==='enable'||$operation==='disable')$operator->enable($operation==='enable',$_SESSION['operator']??[]);
            elseif($operation==='logout'){unset($_SESSION['operator']);session_regenerate_id(true);}
            else throw new \DomainException('Betreiberaktion nicht verfügbar.');
            $_SESSION['csrf']=bin2hex(random_bytes(32));header('Location: /demo/operator',true,303);return;
        }catch(\DomainException $failure){if(http_response_code()<400)http_response_code(422);$error=$failure->getMessage();}
        $authenticated=$operator->authorized($_SESSION['operator']??[]);if(!$authenticated)unset($_SESSION['operator']);
        header('Content-Type: text/html; charset=utf-8');header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
        if($method==='HEAD')return;$escape=static fn(string $value)=>htmlspecialchars($value,ENT_QUOTES,'UTF-8');$csrf=$escape($_SESSION['csrf']);
        echo '<!doctype html><html lang="de"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>DVM Demo · Betreiber / Operator</title><style>body{font:1rem/1.5 system-ui,sans-serif;max-width:42rem;margin:auto;padding:1rem;overflow-wrap:anywhere}input,button{font:inherit;min-height:44px;max-width:100%;box-sizing:border-box}input{display:block;width:100%;margin:.5rem 0}button{padding:.5rem 1rem;margin:.5rem 0}form{margin:1rem 0}</style><main><h1>Demo · Betreiber / Operator</h1>';
        if($error!=='')echo '<p role="alert">'.$escape($error).'</p>';
        if(!$authenticated){echo '<p>Getrennter Betreiberzugang / Separate operator sign-in</p><form method="post" action="/demo/operator"><input type="hidden" name="csrf" value="'.$csrf.'"><input type="hidden" name="operation" value="login"><label>Betreiberpasswort / Operator password<input name="password" type="password" autocomplete="current-password" required></label><button>Anmelden / Sign in</button></form>';}
        else{
            $status=$operator->status();echo '<p role="status">'.($status['enabled']?'Demo eingeschaltet / Demo enabled':'Demo ausgeschaltet / Demo disabled').'</p><p>Gemeinsame Beispieldaten bleiben beim Ausschalten erhalten. / Disabling preserves the shared sample data.</p>';
            foreach(['enable'=>'Einschalten / Enable','disable'=>'Ausschalten / Disable','logout'=>'Abmelden / Sign out'] as $operation=>$label)echo '<form method="post" action="/demo/operator"><input type="hidden" name="csrf" value="'.$csrf.'"><button name="operation" value="'.$operation.'">'.$label.'</button></form>';
        }
        echo '</main></html>';
    }
}
