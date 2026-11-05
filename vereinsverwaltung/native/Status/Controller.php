<?php declare(strict_types=1);
namespace Kulturhus\Native\Status;

use Kulturhus\Native\Language\StatusMessages;
use Kulturhus\Native\Web\Html;

/** Authenticated status-only endpoint. No URL, account, actor or tenant can be posted. */
final class Controller
{
    private static function scalar(array $data,string $key,string $default=''):string
    {
        if(!is_string($data[$key]??$default))throw new \DomainException('Ungültige Statusanfrage.');
        return $data[$key]??$default;
    }
    private static function flag(array $data,string $key):bool
    {
        if(!array_key_exists($key,$data))return false;
        if($data[$key]!=='1')throw new \DomainException('Ungültiger Schalter.');
        return true;
    }
    public static function serve(\PDO $db,string $actor,string $csrf,bool $workspace,string $view,string $tab,array $messages=[],string $locale='de'):void
    {
        header('Cache-Control: no-store');
        $json=str_contains($_SERVER['HTTP_ACCEPT']??'','application/json')||($_GET['status_json']??'')==='1';
        $code=422;$csrfPassed=false;$monitor=null;$group='system';
        $text=static fn(string $source):string=>StatusMessages::text($source,$messages);
        $method=$_SERVER['REQUEST_METHOD']??'GET';
        try{
            if(!$workspace||$view!=='settings'||$tab!=='status'||!(new \Kulturhus\Verein\Settings($db,$actor))->canManage()){
                $code=403;throw new \DomainException('Keine Berechtigung für den Systemstatus.');
            }
            if(!in_array($method,['GET','HEAD','POST'],true)){
                $code=405;header('Allow: GET, HEAD, POST');throw new \DomainException('Diese Anfrage ist nicht erlaubt.');
            }
            if($method==='POST'){
                if($csrf===''||!is_string($_POST['csrf']??null)||!hash_equals($csrf,$_POST['csrf'])){
                    $code=403;throw new \DomainException('Formular abgelaufen. Bitte neu laden.');
                }
                $csrfPassed=true;
            }
            $monitor=Factory::environment($db,$actor)->monitor();
            $group=Catalog::group(self::scalar($method==='POST'?$_POST:$_GET,'group','system'));
            if($method==='POST'){
                $action=self::scalar($_POST,'action');
                if($action==='system-status-check')$result=['service'=>$monitor->check(self::scalar($_POST,'service'))];
                elseif($action==='system-status-save'){
                    $version=self::scalar($_POST,'version');
                    if(!preg_match('/\A(?:0|[1-9][0-9]{0,8})\z/D',$version))throw new \DomainException('Einstellungsstand prüfen.');
                    $monitor->save((int)$version,self::flag($_POST,'public_enabled'),self::flag($_POST,'automatic_enabled'),Catalog::ids($_POST['public_services']??[]),Catalog::ids($_POST['disabled_services']??[]));
                    $result=['saved'=>true];
                }else throw new \DomainException('Unbekannte Statusaktion.');
                if(!$json){
                    $_SESSION['notice']=$text(isset($result['saved'])?'Status-Einstellungen gespeichert.':'Prüfung abgeschlossen.');
                    header('Location: /vereinsverwaltung/?'.http_build_query(['view'=>'settings','tab'=>'status','group'=>$group]),true,303);return;
                }
            }else $result=['services'=>$monitor->snapshot($group)];
            if($json){
                header('Content-Type: application/json; charset=utf-8');
                if($method!=='HEAD')echo json_encode($result,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
            }
            return;
        }catch(\Throwable $error){
            if($error instanceof \PDOException||($error instanceof \DomainException&&(str_starts_with($error->getMessage(),'Privater Schlüssel')||str_starts_with($error->getMessage(),'Private Konfiguration'))))$code=503;
            elseif($error instanceof \DomainException&&in_array($error->getCode(),[403,404,409,422,503],true))$code=$error->getCode();
            try{if(!(new \Kulturhus\Verein\Settings($db,$actor))->canManage())$code=403;}catch(\Throwable){$code=$csrfPassed?503:403;}
            $message=$error instanceof \DomainException?$error->getMessage():'Statusprüfung nicht bestätigt. Bitte neu laden.';
            if($code===503)$message='Status-Einstellungen derzeit nicht sicher prüfbar. Bitte den gespeicherten Stand erneut öffnen.';
            http_response_code($code);
            if($json){
                header('Content-Type: application/json; charset=utf-8');
                if($method!=='HEAD')echo json_encode(['error'=>$text($message)],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
                return;
            }
            header('Content-Type: text/html; charset=utf-8');if($method==='HEAD')return;
            $content='';
            // A retained draft is shown only after current access and CSRF were
            // established. It never chooses a newer hidden settings version.
            if($csrfPassed&&$monitor!==null&&in_array($code,[409,422,503],true)&&($_POST['action']??null)==='system-status-save'){
                try{$content='<main class="status-public">'.View::render($monitor,$csrf,['group'=>$group,'status_reopen'=>$_GET['status_reopen']??null],$messages,$_POST,$message,$code===503,$locale).'</main>';}catch(\Throwable){/* Do not substitute another tenant or invent saved data. */}
            }
            if($content==='')$content='<main class="status-public"><h1>'.Html::e($text('Systemstatus')).'</h1><p role="alert">'.Html::e($text($message)).'</p><a class="btn btn-solid theme-primary" href="/vereinsverwaltung/?view=settings&amp;tab=status">'.Html::e($text('Neu laden')).'</a></main>';
            if($content!==''&&$csrfPassed&&$code===503&&($_POST['action']??null)==='system-status-save'&&!str_contains($content,'data-status-settings-form')){
                $original=array_intersect_key($_POST,array_flip(['version','group','public_enabled','automatic_enabled','public_services','disabled_services']));
                $content.='<section class="status-public"><h2>'.Html::e($text('Eingabe ansehen')).'</h2><pre>'.Html::e(json_encode($original,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE|JSON_PRETTY_PRINT)).'</pre></section>';
            }
            $content=str_replace(['href="system-status.css"','src="system-status.js"'],['href="/vereinsverwaltung/system-status.css"','src="/vereinsverwaltung/system-status.js"'],$content);
            echo '<!doctype html><html lang="'.Html::e($locale).'"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'.Html::e($text('Systemstatus')).'</title><link rel="stylesheet" href="/assets/bootstrap-6.0.0-alpha.1.min.css"><link rel="stylesheet" href="/assets/system-status.css"><body>'.$content.'</body></html>';
        }
    }
}
