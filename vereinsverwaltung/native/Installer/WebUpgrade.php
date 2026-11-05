<?php declare(strict_types=1);
namespace Kulturhus\Native\Installer;

/** Standalone HTTPS updater stays executable while app/ is being replaced. */
final class WebUpgrade
{
    public static function serve(string $root,string $package): void
    {
        ini_set('display_errors','0'); ini_set('zend.exception_ignore_args','1');
        header('Cache-Control: no-store'); header('X-Content-Type-Options: nosniff'); header('Referrer-Policy: same-origin');
        header("Content-Security-Policy: default-src 'none'; style-src 'self'; script-src 'self'; connect-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
        $json=str_contains($_SERVER['HTTP_ACCEPT']??'','application/json');
        $csrf=''; $result=null; $error='';
        try {
            $method=$_SERVER['REQUEST_METHOD']??'GET'; $host=(string)($_SERVER['HTTP_HOST']??'');
            if (!in_array($_SERVER['HTTPS']??'',['on','1'],true)) { http_response_code(403); throw new \DomainException('Aktualisierung ausschließlich auf der eigenen HTTPS-Domain aufrufen.'); }
            UpgradeFiles::privateFile($root.'/private/environment.json');
            $environment=\PackageStore::json($root.'/private/environment.json');
            require_once $package.'/app/vereinsverwaltung/src/Infrastructure/Runtime/ProductionRuntime.php';
            if (!in_array($host,\Kulturhus\Verein\ProductionRuntime::allowedHosts($environment['VEREIN_ALLOWED_HOSTS']??''),true) || realpath($_SERVER['DOCUMENT_ROOT']??'')!==realpath($environment['VEREIN_WEB_ROOT']??$root.'/web')) { http_response_code(403); throw new \DomainException('Konfigurierte eigene Domain und eigener Webroot erforderlich.'); }
            if (!in_array($method,['GET','POST'],true)) { header('Allow: GET, POST'); http_response_code(405); throw new \DomainException('Formular über GET oder POST aufrufen.'); }
            if ($method==='POST' && isset($_SERVER['HTTP_ORIGIN']) && $_SERVER['HTTP_ORIGIN']!=='https://'.$host) { http_response_code(403); throw new \DomainException('Aktualisierung von der eigenen Domain aufrufen.'); }
            \PackageStore::directory($root.'/private/upgrade-sessions');
            session_name('DVM_UPGRADE'); session_save_path($root.'/private/upgrade-sessions');
            session_start(['use_strict_mode'=>1,'use_only_cookies'=>1,'cookie_secure'=>true,'cookie_httponly'=>true,'cookie_samesite'=>'Strict','cookie_path'=>'/upgrade/']);
            $_SESSION['csrf']??=bin2hex(random_bytes(32)); $csrf=$_SESSION['csrf'];
            if ($method==='POST') {
                if (!is_string($_POST['csrf']??null) || !hash_equals($csrf,$_POST['csrf'])) { http_response_code(403); throw new \DomainException('Formular abgelaufen. Aktualisierung neu öffnen.'); }
                $token=is_string($_POST['token']??null)&&$_POST['token']!==''?$_POST['token']:($_SESSION['upgrade_token']??'');
                $permit=UpgradePermit::authenticate($root,$package,$token);
                $_SESSION['upgrade_token']=$token; unset($token);
                $upgrade=new Upgrade($root,$package);
                $action=$_POST['action']??'';
                if ($action==='status') $result=$upgrade->status($permit);
                else {
                    require_once $package.'/app/vereinsverwaltung/src/Infrastructure/Database/Database.php';
                    try { $db=\Kulturhus\Verein\Database::connect($environment['VEREIN_DATABASE'],$environment['VEREIN_DATABASE_USER'],$environment['VEREIN_DATABASE_PASSWORD']); }
                    catch (\Throwable) { throw new \DomainException('Installierte private Datenbankverbindung im Hosting prüfen.'); }
                    $result=match($action) {
                        'begin'=>$upgrade->begin($db,$permit),
                        'step'=>$upgrade->step($db,$permit),
                        'rollback'=>($_POST['confirm']??'')==='1'?$upgrade->rollback($db,$permit):throw new \DomainException('Rückkehr zum bisherigen Stand bestätigen.'),
                        default=>throw new \DomainException('Upgradeaktion prüfen.'),
                    };
                }
                // Keep only in the private session until expiry so a lost final response can be checked.
            }
        } catch (\Throwable $e) {
            if ((http_response_code()?:200)<400) http_response_code(422);
            $error=$e instanceof \DomainException?$e->getMessage():'Aktualisierung unterbrochen. Paket und private Laufzeit im Hosting prüfen; anschließend fortsetzen oder zurückkehren.';
        }
        if ($json) { header('Content-Type: application/json; charset=utf-8'); echo json_encode($error!==''?['error'=>$error]:$result,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE); return; }
        header('Content-Type: text/html; charset=utf-8'); echo self::view($csrf,$result,$error);
    }

    public static function view(string $csrf,?array $result=null,string $error=''): string
    {
        $e=static fn(string $s):string=>htmlspecialchars($s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
        $active=$result && !$result['complete'] && $result['status']!=='ready'; $done=$result['complete']??false;
        return '<!doctype html><html lang="de"><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>DVM aktualisieren</title><link rel="stylesheet" href="/upgrade/bootstrap-6.0.0-alpha.1.min.css"><link rel="stylesheet" href="/upgrade/bootstrap-dvm.css"><link rel="stylesheet" href="/upgrade/upgrade.css"><script src="/upgrade/upgrade.js" defer></script><body><main><h1>DVM aktualisieren</h1><p id="progress" role="status" aria-live="polite">'.$e($result['label']??'Neues Paket bereit').'</p><p id="error" role="alert">'.$e($error).'</p><form method="post" id="upgrade-form"><input type="hidden" name="csrf" value="'.$e($csrf).'"><label for="upgrade-key">Privater Upgradeschlüssel</label><input id="upgrade-key" name="token" type="password" autocomplete="off" spellcheck="false" maxlength="64"><div class="actions"><button name="action" value="'.($active?'step':'begin').'" id="continue"'.($done?' disabled':'').'>'.($active?'Fortsetzen':'Aktualisieren').'</button><button name="action" value="status">Stand prüfen</button><button type="button" id="pause" hidden>Nach diesem Schritt anhalten</button></div><details><summary>Zum bisherigen Stand zurückkehren</summary><p>Während der Aktualisierung den Programm- und Datenbankstand vor dem Beginn wiederherstellen.</p><label class="confirmation"><input type="checkbox" name="confirm" value="1">Rückkehr bestätigen</label><button name="action" value="rollback"'.($done?' disabled':'').'>Bisherigen Stand wiederherstellen</button></details></form><p><a href="/login/">Anmelden</a></p></main></body></html>';
    }
}
