<?php declare(strict_types=1);
namespace Kulturhus\Native\Extensions;
use Kulturhus\Native\Web\{Html,BootstrapAssets};
use Kulturhus\Native\Language\ExtensionMessages;
final class Controller
{
    private static function scalar(array $input,string $key,string $default=''):string
    {
        $value=$input[$key]??$default;if(!is_string($value))throw new \DomainException('Erweiterungsanfrage prüfen.');return$value;
    }
    public static function configuration(array $input,Definition $definition):array
    {
        if(array_diff(array_keys($input),array_keys($definition->fields)))throw new \DomainException('Unbekanntes Konfigurationsfeld.');$values=[];
        foreach($definition->fields as$key=>$field){
            if(!array_key_exists($key,$input)&&$field['type']!=='boolean')throw new \DomainException('Konfiguration prüfen.');
            $value=array_key_exists($key,$input)?$input[$key]:'0';
            if(!is_string($value)&&!is_int($value))throw new \DomainException('Konfiguration prüfen.');
            if($field['type']==='boolean'){
                if(!in_array($value,['0','1'],true))throw new \DomainException('Schalter prüfen.');$value=$value==='1';
            }elseif($field['type']==='integer'){
                if(!preg_match('/\A-?(?:0|[1-9][0-9]{0,9})\z/D',(string)$value))throw new \DomainException('Zahl prüfen.');$value=(int)$value;
            }
            $values[$key]=$value;
        }
        return$definition->configuration($values);
    }
    public static function serve(\PDO $db,string $actor,string $csrf,bool $workspace,string $view,string $tab,array $messages=[],string $locale='de'):void
    {
        header('Cache-Control: no-store');$json=str_contains($_SERVER['HTTP_ACCEPT']??'','application/json')||($_GET['extensions_json']??'')==='1';
        $method=$_SERVER['REQUEST_METHOD']??'GET';$code=422;$csrfPassed=false;$manager=null;
        $text=static fn(string $source):string=>ExtensionMessages::text($source,$messages);
        try{
            if(!$workspace||$view!=='settings'||$tab!=='extensions'){$code=403;throw new \DomainException('Erweiterungsverwaltung nicht freigegeben.');}
            if(!in_array($method,['GET','HEAD','POST'],true)){$code=405;header('Allow: GET, HEAD, POST');throw new \DomainException('Anfrage nicht erlaubt.');}
            if($method==='POST'){
                if($csrf===''||!is_string($_POST['csrf']??null)||!hash_equals($csrf,$_POST['csrf'])){$code=403;throw new \DomainException('Formular abgelaufen. Bitte neu laden.');}
                $csrfPassed=true;
            }
            $manager=Factory::manager($db,$actor);$manager->listing();
            if($method==='POST'){
                $id=self::scalar($_POST,'plugin');$action=self::scalar($_POST,'action');$revision=self::scalar($_POST,'revision');
                if(!str_starts_with($action,'extension-')||!preg_match('/\A(?:0|[1-9][0-9]{0,8})\z/D',$revision))throw new \DomainException('Erweiterungsaktion prüfen.');
                $action=substr($action,10);
                if($action==='uninstall'&&self::scalar($_POST,'confirm')!=='1')throw new \DomainException('Entfernen ausdrücklich bestätigen.');
                $configuration=[];
                if($action==='configure'){
                    if(!is_array($_POST['configuration']??null))throw new \DomainException('Konfiguration prüfen.');
                    $configuration=self::configuration($_POST['configuration'],Runtime::catalog()->get($id)->definition());
                }
                $manager->change($id,$action,(int)$revision,$configuration);
                if(!$json){$_SESSION['notice']=$text('Erweiterung gespeichert.');header('Location: /vereinsverwaltung/?view=settings&tab=extensions#extension-'.rawurlencode($id),true,303);return;}
            }
            if($json){header('Content-Type: application/json; charset=utf-8');if($method!=='HEAD')echo json_encode(['extensions'=>$manager->listing()],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);return;}
            if($method!=='HEAD')echo View::render($manager,$csrf,$_GET,null,$messages,$locale);
        }catch(\Throwable $failure){
            if($failure instanceof \PDOException||!($failure instanceof \DomainException))$code=503;
            elseif(in_array($failure->getCode(),[403,404,409,422,503],true))$code=$failure->getCode();
            $current=false;try{(new Access($db,$actor))->actor();$current=true;}catch(\PDOException){$code=503;}catch(\Throwable){$code=403;}
            $message=$code===503?'Erweiterung derzeit nicht sicher prüfbar. Öffnen Sie den gespeicherten Stand erneut.':($failure instanceof \DomainException?$failure->getMessage():'Erweiterungsverwaltung nicht verfügbar.');
            if(!$current&&$code===403)$message='Erweiterungsverwaltung nicht freigegeben.';
            http_response_code($code);if($code===405)header('Allow: GET, HEAD, POST');
            header('Content-Type: '.($json?'application/json':'text/html').'; charset=utf-8');if($method==='HEAD')return;
            if($json){echo json_encode(['error'=>$text($message)],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);return;}
            $head='<!doctype html><html lang="'.Html::e($locale).'"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'.Html::e($text('Erweiterungen')).'</title>'.BootstrapAssets::styles('/vereinsverwaltung/').'<body>';
            $alert='<p class="alert alert-danger" role="alert">'.Html::e($text($message)).'</p>';
            if($current&&$csrfPassed&&$manager!==null&&in_array($code,[409,422,503],true)&&($_POST['action']??null)==='extension-configure'){
                $draft=['plugin'=>$_POST['plugin']??null,'revision'=>$_POST['revision']??null,'configuration'=>$_POST['configuration']??null];
                try{$content=View::render($manager,$csrf,array_intersect_key($_GET,array_flip(['extension_page','extension_history_page','extension_reopen'])),$draft,$messages,$locale,$code===503);echo $head.'<main class="extension-error">'.$alert.$content.'</main></body></html>';return;}catch(\Throwable){/* A missing current source never supplies another writable configuration. */}
            }
            $original='';if($current&&$csrfPassed&&in_array($code,[409,422,503],true)){$raw=array_intersect_key($_POST,array_flip(['plugin','revision','configuration','confirm','action']));unset($raw['revision']);$original='<section class="extension-original"><h2>'.Html::e($text('Ursprüngliche Angaben')).'</h2><pre>'.Html::e(json_encode($raw,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE|JSON_PRETTY_PRINT)).'</pre></section>';}
            echo $head.'<link rel="stylesheet" href="/vereinsverwaltung/native-extensions.css"><main class="content native-extensions extension-error"><h1>'.Html::e($text('Erweiterungen')).'</h1>'.$alert.$original.'<a class="btn btn-outline theme-secondary" href="/vereinsverwaltung/?view=settings&amp;tab=extensions&amp;extension_reopen='.(($_GET['extension_reopen']??null)==='1'?'2':'1').'">'.Html::e($text('Aktuelle Erweiterungen öffnen')).'</a></main></body></html>';
        }
    }
}
