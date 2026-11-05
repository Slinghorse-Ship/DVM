<?php declare(strict_types=1);
namespace Kulturhus\Native\Demo;

use Kulturhus\Native\Integration\{ManagerBridge,ManagerSession,ProductionSite};
use Kulturhus\Native\Auth\Runtime as AuthRuntime;

/** This context is activated only by the private mounted demo entry point. */
final class Runtime
{
    private static ?array $configuration=null;
    public static function active():bool{return self::$configuration!==null;}
    public static function locale():string{return session_status()===PHP_SESSION_ACTIVE&&($_SESSION['demo_locale']??'de')==='en'?'en':'de';}
    public static function root():string{return self::$configuration['private_root']??throw new \LogicException('Private Demo-Konfiguration fehlt.');}
    public static function instance():string{return self::$configuration['instance']??throw new \LogicException('Demo-Instanz fehlt.');}
    public static function club():string{return 'dvm-demo-'.self::instance();}
    public static function origin():string{return self::$configuration['origin']??throw new \LogicException('Demo-Origin fehlt.');}
    public static function mount():string{return '/demo';}

    /** A public exercise confirmation rechecks the same real, restricted role account. */
    public static function confirmationPassword(string $input,string $actor):string
    {
        if(!self::active()||$input!=='demo')return $input;
        $role=$_SESSION['demo_role']??null;
        if(!is_string($role)||!in_array($role,Configuration::ROLES,true)||$actor!=='demo-'.$role)throw new \DomainException('Aktuelle eingeschränkte Demorolle erforderlich.');
        Configuration::privateFile(self::root().'/roles.json');$roles=json_decode((string)file_get_contents(self::root().'/roles.json'),true,32,JSON_THROW_ON_ERROR);
        $password=$roles[$role]['password']??null;if(!is_string($password)||strlen($password)<16)throw new \DomainException('Privates Demokonto nicht vollständig.');return $password;
    }

    /** Clear inherited app bindings; platform proxy/CA and system variables stay intact. */
    public static function activate(#[\SensitiveParameter] array $configuration):void
    {
        if(self::active()){
            if(self::$configuration===Configuration::validate($configuration))return;
            throw new \LogicException('Demo-Kontext bereits für eine andere Instanz aktiv.');
        }
        self::$configuration=Configuration::validate($configuration);
        $names=array_unique([...array_keys(getenv()),...array_keys($_ENV)]);
        foreach($names as $name)if(preg_match('/\A(?:VEREIN_|KH_NATIVE_|KULTURHUS_|DVM_|SMTP_|MAIL_|MAILER_|BOARD_|PRESS_|NEWSLETTER_|META_|FACEBOOK_|INSTAGRAM_|EVENTBRITE_|SUMUP_|STRIPE_|PAYPAL_|ENABLEBANKING_|FINTS_|ELSTER_|ERIC_|APNS_|VAPID_|GOOGLE_WALLET_)/i',$name)||in_array(strtoupper($name),['APP_SECRET','SMTP_DSN','MAILER_DSN'],true)){
            putenv($name);unset($_ENV[$name],$_SERVER[$name]);
        }
        $root=self::root();$db=$configuration['database'];
        $environment=[
            'VEREIN_MODE'=>'production','VEREIN_AUTH'=>'native','KH_NATIVE_MANAGER'=>'1','KULTURHUS_LOCAL_DEMO'=>'0',
            'VEREIN_DATABASE'=>'mysql:host='.$db['host'].';port='.$db['port'].';dbname='.$db['name'].';charset=utf8mb4',
            'VEREIN_DATABASE_USER'=>$db['username'],'VEREIN_DATABASE_PASSWORD'=>$db['password'],
            'VEREIN_ALLOWED_HOSTS'=>parse_url(self::origin(),PHP_URL_HOST),'VEREIN_CLUB'=>self::club(),
            'VEREIN_PUBLIC_URL'=>self::origin().self::mount(),'VEREIN_PRIVATE_ROOT'=>$root,'VEREIN_SESSION_ROOT'=>$root,
            'KH_NATIVE_MANAGER_SESSION_ROOT'=>$root.'/sessions','KH_NATIVE_MANAGER_MEDIA_ROOT'=>$root.'/media',
            'KH_NATIVE_AUTH_KEY_FILE'=>$root.'/auth.key','KH_NATIVE_PRIVATE_KEY_FILE'=>$root.'/private.key',
            'VEREIN_MEMBER_BANK_KEY_PATH'=>$root.'/member-bank-keys/v1.key','VEREIN_MEMBER_BANK_KEY_VERSION'=>'1',
            'VEREIN_BANKING_ROOT'=>$root.'/banking','VEREIN_ERIC_DISABLE_TRANSPORT'=>'1',
            'KULTURHUS_DISABLE_MAIL'=>'1','KULTURHUS_DISABLE_WEBSITE'=>'1','KULTURHUS_DISABLE_PAYMENTS'=>'1','KULTURHUS_DISABLE_WALLET'=>'1',
        ];
        foreach($environment as $name=>$value)putenv($name.'='.$value);
    }

    public static function serve(string $configurationPath):void
    {
        ini_set('display_errors','0');ini_set('zend.exception_ignore_args','1');umask(0077);
        $configuration=Configuration::read($configurationPath);
        if(\Kulturhus\Native\Integration\CanonicalOrigin::redirect($configuration['origin']))return;
        if(($_SERVER['HTTP_HOST']??'')!==parse_url($configuration['origin'],PHP_URL_HOST)||!in_array($_SERVER['HTTPS']??'',['on','1'],true)){
            http_response_code(403);echo 'Konfigurierte HTTPS-Domain erforderlich.';return;
        }
        $original=$_SERVER['REQUEST_URI']??'';$path=parse_url($original,PHP_URL_PATH);
        if(!is_string($path)||!preg_match('~\A/demo(?:/|$)~D',$path)){http_response_code(404);return;}
        $method=$_SERVER['REQUEST_METHOD']??'GET';
        if(!in_array($method,['GET','HEAD','POST','OPTIONS'],true)){http_response_code(405);header('Allow: GET, HEAD, POST, OPTIONS');return;}
        if($method==='POST'&&isset($_SERVER['HTTP_ORIGIN'])&&$_SERVER['HTTP_ORIGIN']!==$configuration['origin']&&!($_SERVER['HTTP_ORIGIN']==='null'&&($_SERVER['HTTP_SEC_FETCH_SITE']??'')==='same-origin')){http_response_code(403);return;}
        header('X-Robots-Tag: noindex, nofollow');header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');header('Referrer-Policy: no-referrer');
        if($path==='/demo/operator'||str_starts_with($path,'/demo/operator/')){OperatorPage::serve($configuration);return;}
        $operator=new Operator($configuration);$status=$operator->status();
        if(!$status['enabled']){http_response_code(503);header('Retry-After: 60');if($method!=='HEAD')echo 'Die Demo ist derzeit ausgeschaltet. / The demo is currently disabled.';return;}
        $db=Configuration::connect($configuration);Install::assertInstance($db,$configuration);
        Configuration::privateFile($configuration['private_root'].'/installed.json');
        self::activate($configuration);ManagerSession::start();
        if(($_SESSION['demo_epoch']??null)!==$status['epoch']){
            if(is_string($_SESSION['auth_token']??null))AuthRuntime::service($db)->logout($_SESSION['auth_token']);
            $_SESSION=['csrf'=>bin2hex(random_bytes(32)),'demo_epoch'=>$status['epoch'],'demo_locale'=>self::locale()];session_regenerate_id(true);
        }
        $local=substr($path,strlen(self::mount()))?:'/';
        if($local==='/_adapter.js'){
            header('Content-Type: text/javascript; charset=utf-8');if($method!=='HEAD')echo Mount::adapter();return;
        }
        if($local==='/_demo.css'){
            header('Content-Type: text/css; charset=utf-8');if($method!=='HEAD')echo Mount::style();return;
        }
        if($local==='/language'){
            if($method!=='POST'){http_response_code(405);header('Allow: POST');return;}self::csrf($_POST['csrf']??null);
            if(!is_string($_POST['locale']??null)||!in_array($_POST['locale'],['de','en'],true))throw new \DomainException('Demosprache auswählen.');
            $_SESSION['demo_locale']=$_POST['locale'];header('Location: /demo/',true,303);return;
        }
        if(($local==='/'||$local==='/login'||$local==='/login/')&&$method!=='OPTIONS'){
            self::chooser($db,$method);return;
        }
        if($local==='/choose-role'){
            if($method!=='POST'){http_response_code(405);header('Allow: POST');return;}
            self::csrf($_POST['csrf']??null);
            $role=$_POST['role']??null;if(!is_string($role)||!in_array($role,Configuration::ROLES,true))throw new \DomainException('Demorolle auswählen.');
            Configuration::privateFile(self::root().'/roles.json');$roles=json_decode((string)file_get_contents(self::root().'/roles.json'),true,32,JSON_THROW_ON_ERROR);
            $record=$roles[$role]??null;if(!is_array($record))throw new \DomainException('Demokonto nicht eingerichtet.');
            $auth=AuthRuntime::service($db);$login=$auth->passwordLogin($record['email'],$record['password'],hash('sha256',($_SERVER['REMOTE_ADDR']??'').'|'.session_id()));
            if(($login['status']??'')!=='authenticated')throw new \DomainException('Demokonto nicht bestätigt.');
            $bridge=ManagerBridge::fromNativeSession($db,$auth,$login['session_token']);
            $query=$db->prepare('SELECT role,is_superuser,active FROM actors WHERE club_id=? AND id=?');$query->execute([self::club(),'demo-'.$role]);$actor=$query->fetch();
            if($bridge->managerClub()!==self::club()||$bridge->managerActor()!=='demo-'.$role||!$actor||$actor['role']!==$role||$actor['is_superuser']||!$actor['active'])throw new \DomainException('Eingeschränktes Demokonto erforderlich.');
            if(is_string($_SESSION['auth_token']??null))$auth->logout($_SESSION['auth_token']);
            $locale=self::locale();session_regenerate_id(true);
            $_SESSION=['csrf'=>bin2hex(random_bytes(32)),'demo_epoch'=>$status['epoch'],'demo_locale'=>$locale,'auth_token'=>$login['session_token'],'demo_role'=>$role];
            header('Location: /demo/vereinsverwaltung/',true,303);return;
        }
        if($local==='/leave-role'){
            if($method!=='POST'){http_response_code(405);header('Allow: POST');return;}self::csrf($_POST['csrf']??null);
            if(is_string($_SESSION['auth_token']??null))AuthRuntime::service($db)->logout($_SESSION['auth_token']);
            $locale=self::locale();$_SESSION=['csrf'=>bin2hex(random_bytes(32)),'demo_epoch'=>$status['epoch'],'demo_locale'=>$locale];session_regenerate_id(true);header('Location: /demo/',true,303);return;
        }
        // The public roles never manage credentials, technical installation or provider access.
        $account=rtrim($local,'/');$action=$_POST['action']??'';
        if(in_array($account,['/accounts','/invite','/reset','/forgot'],true)||str_starts_with($local,'/api/')||str_starts_with($local,'/setup/')||str_starts_with($local,'/upgrade/')||($account==='/account'&&(($_GET['section']??'')!=='language'||($method==='POST'&&($_POST['operation']??'')!=='select-language')))||isset($_GET['edit_fints'])||(is_string($action)&&preg_match('/\A(?:bank-access|enablebanking|native-api)/',$action))){
            http_response_code(403);if($method!=='HEAD')echo self::locale()==='en'?'Credential and provider administration is disabled in the demo.':'Zugangs- und Anbieterverwaltung sind in der Demo gesperrt.';return;
        }
        $publicAsset=in_array($method,['GET','HEAD'],true)&&preg_match('~\A/vereinsverwaltung/[A-Za-z0-9_./-]+\.(?:css|js|mjs|svg|png|jpe?g|gif|webp|ico|woff2?|ttf|otf|wasm|webmanifest)\z~D',$local);
        if(str_starts_with($local,'/vereinsverwaltung/')&&!isset($_SESSION['auth_token'])&&!$publicAsset){header('Location: /demo/',true,303);return;}
        if(in_array($_GET['view']??null,['social','newsletter'],true)&&!isset($_GET['plan_month'])&&in_array($_GET['planning_view']??'month',['month'],true)){
            $installation=json_decode((string)file_get_contents(self::root().'/installed.json'),true,32,JSON_THROW_ON_ERROR);
            if(is_string($installation['sample_month']??null)&&preg_match('/\A\d{4}-(?:0[1-9]|1[0-2])\z/D',$installation['sample_month']))$_GET['plan_month']=$installation['sample_month'];
        }
        $_SERVER['REQUEST_URI']=$local.(isset($_SERVER['QUERY_STRING'])&&$_SERVER['QUERY_STRING']!==''?'?'.$_SERVER['QUERY_STRING']:'');
        if(($_GET['dvm_demo_worker']??'')==='1')header('Service-Worker-Allowed: /demo/');
        try{Mount::dispatch(static fn()=>ProductionSite::serve($db));}finally{$_SERVER['REQUEST_URI']=$original;}
    }

    public static function csrf(mixed $token):void
    {
        if(!is_string($token)||!is_string($_SESSION['csrf']??null)||!hash_equals($_SESSION['csrf'],$token)){http_response_code(403);throw new \DomainException('Demoformular erneut öffnen.');}
    }

    private static function chooser(\PDO $db,string $method):void
    {
        if($method==='POST'){http_response_code(405);header('Allow: GET, HEAD');return;}
        header("Content-Security-Policy: default-src 'none'; style-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");header('Content-Type: text/html; charset=utf-8');
        if($method==='HEAD')return;$en=self::locale()==='en';$csrf=htmlspecialchars($_SESSION['csrf'],ENT_QUOTES,'UTF-8');
        echo '<!doctype html><html lang="'.($en?'en':'de').'"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>DVM Demo</title><link rel="stylesheet" href="/demo/vereinsverwaltung/style.css"><main class="content"><h1>DVM Demo</h1><p>'.($en?'Fictional shared sample data. External actions and device payments are disabled.':'Gemeinsame fiktive Beispieldaten. Externe Aktionen und Gerätezahlungen sind gesperrt.').'</p><form method="post" action="/demo/choose-role"><input type="hidden" name="csrf" value="'.$csrf.'">';
        foreach(['members'=>['Mitgliederverwaltung','Membership management'],'treasurer'=>['Finanzen','Finance'],'editor'=>['Redaktion','Editorial'],'member'=>['Mitglied','Member']] as $role=>$labels)echo '<p><button name="role" value="'.$role.'">'.$labels[$en?1:0].'</button></p>';
        echo '</form><form method="post" action="/demo/language"><input type="hidden" name="csrf" value="'.$csrf.'"><button name="locale" value="de">Deutsch</button> <button name="locale" value="en">English</button></form></main></html>';
    }
}
