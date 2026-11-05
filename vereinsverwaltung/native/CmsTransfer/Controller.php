<?php declare(strict_types=1);
namespace Kulturhus\Native\CmsTransfer;

use DomainException;
use InvalidArgumentException;
use Kulturhus\Native\Cms\Conflict;
use Kulturhus\Native\Integration\Routes;
use Kulturhus\Native\Shared\Access;
use PDO;

/** Both actual managers delegate the same authenticated, CSRF-checked data-only workflow. */
final class Controller
{
    public static function serve(PDO $db,Access $access,string $mediaRoot,string $csrf,array $query,array $post,array $files,string $method):void
    {
        header('Cache-Control: private, no-store, max-age=0');header('Pragma: no-cache');header('X-Robots-Tag: noindex, nofollow');header('Referrer-Policy: same-origin');header('X-Content-Type-Options: nosniff');header('X-Frame-Options: SAMEORIGIN');header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; frame-ancestors 'self'; object-src 'none'; base-uri 'none'; form-action 'self'");header('Content-Type: text/html; charset=utf-8');
        if(!in_array($method,['GET','HEAD','POST'],true)){http_response_code(405);header('Allow: GET, HEAD, POST');return;}
        $job=null;$error='';$service=null;$catalog=null;$recent=null;$prefix='';$feedback=[];
        try{
            $access->require('website','manage');$access->require('website','read');$root=Factory::privateRoot($mediaRoot);$service=new Imports($db,$access,$mediaRoot,$root);$jobId=self::text($query,'job');
            if($method==='POST'){
                if(array_diff(array_keys($post),['csrf','operation','pages','languages','prefix','job','version','confirmed','secrets','slugs']))throw new InvalidArgumentException('Unbekannte Übertragungsangabe.');
                $token=self::text($post,'csrf');if($token===''||!hash_equals($csrf,$token))throw new DomainException('Formular abgelaufen. Seite neu öffnen und erneut versuchen.');
                $operation=self::text($post,'operation');
                if(in_array($operation,['export','export-model'],true)){
                    if($files!==[]||array_diff(array_keys($post),['csrf','operation','pages','languages']))throw new InvalidArgumentException('Exportauswahl prüfen.');$selection=[];$selected=$post['pages']??[];if(!is_array($selected)||count($selected)>100)throw new InvalidArgumentException('Höchstens 100 Seiten auswählen.');foreach($selected as$id=>$version)$selection[$id]=self::number($version);
                    if($operation==='export'&&$selection===[])throw new InvalidArgumentException('Mindestens eine Seite auswählen.');if(isset($post['languages'])&&$post['languages']!=='1')throw new InvalidArgumentException('Sprachentwurfauswahl prüfen.');if($operation==='export-model')$selection=[];
                    $exports=Package::directory($root.'/cms-transfers/exports');$path=$exports.'/export-'.bin2hex(random_bytes(16)).'.zip';
                    try{$file=(new Exporter($db,$access,$mediaRoot,$root))->export($selection,$path,($post['languages']??null)==='1');header('Content-Type: application/zip');header('Content-Length: '.$file['bytes']);header("Content-Disposition: attachment; filename=\"CMS-Inhalte.dvm.zip\"");header("Content-Security-Policy: sandbox; default-src 'none'");readfile($file['path']);return;}finally{if(is_file($path)&&!is_link($path))unlink($path);}
                }
                if($operation==='prepare'){
                    if(array_diff(array_keys($post),['csrf','operation','prefix','slugs'])||array_diff(array_keys($files),['package']))throw new InvalidArgumentException('Nur ein natives CMS-Paket auswählen.');$file=$files['package']??null;
                    if(!is_array($file)||!is_int($file['error']??null)||$file['error']!==UPLOAD_ERR_OK||!is_string($file['tmp_name']??null)||!is_uploaded_file($file['tmp_name']))throw new InvalidArgumentException('CMS-Paket wurde nicht vollständig hochgeladen. Datei erneut auswählen; Uploadgrenze des Webservers beachten.');
                    $prefix=self::text($post,'prefix');$options=$prefix!==''?['prefix'=>$prefix]:[];if(isset($post['slugs']))$options['slugs']=$post['slugs'];$job=$service->prepare($file['tmp_name'],$options);header('Location: '.Routes::endpoint('cms-transfer',['job'=>$job['id']]),true,303);return;
                }
                if($files!==[]||!in_array($operation,['apply','cancel','cleanup','revise'],true)||array_diff(array_keys($post),['csrf','operation','job','version','confirmed','secrets','prefix','slugs']))throw new InvalidArgumentException('Importaktion prüfen.');
                $id=self::text($post,'job');if($jobId!==$id)throw new DomainException('Formular gehört nicht zu diesem Importauftrag.');$version=self::number(self::text($post,'version'));$confirmed=($post['confirmed']??null)==='1';$secrets=$post['secrets']??[];if(!is_array($secrets))throw new InvalidArgumentException('Lokale Passwortangaben prüfen.');
                if($operation==='revise'){if($secrets!==[])throw new InvalidArgumentException('Erneute Prüfung benötigt keine Passwortangaben.');$options=array_intersect_key($post,array_flip(['prefix','slugs']));$feedback=$options;$service->revise($id,$version,$options);}
                elseif($operation==='apply')$service->apply($id,$version,$confirmed,$secrets);elseif($operation==='cancel'){if($secrets!==[])throw new InvalidArgumentException('Abbruch benötigt keine Passwortangaben.');$service->cancel($id,$version,$confirmed);}else{if($secrets!==[])throw new InvalidArgumentException('Bereinigung benötigt keine Passwortangaben.');$service->cleanup($id,$version,$confirmed);}
                header('Location: '.Routes::endpoint('cms-transfer',['job'=>$id]),true,303);return;
            }
            if($jobId!=='')$job=$service->get($jobId);
        }catch(Conflict $e){http_response_code(409);$error=$e->getMessage();}
        catch(InvalidArgumentException $e){http_response_code(422);$error=$e->getMessage();}
        catch(DomainException $e){http_response_code(403);$error=$e->getMessage();}
        if($service!==null){
            try{if($job===null&&self::text($query,'job')!=='')$job=$service->get(self::text($query,'job'));$catalog=(new Catalog($db,$access))->listing(self::text($query,'q'),self::page($query,'page'));$recent=$service->recent(self::page($query,'jobs_page'));}
            catch(InvalidArgumentException $e){http_response_code(422);$error=$e->getMessage();}
            catch(DomainException $e){http_response_code(403);$error=$e->getMessage();}
        }
        if($method==='HEAD')return;
        if($catalog===null||$recent===null){echo '<!doctype html><html lang="de"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>CMS-Übertragung</title><main><h1>CMS-Übertragung</h1><p role="alert">'.\Kulturhus\Native\Web\Html::e($error?:'Zugang nicht verfügbar.').'</p></main></html>';return;}
        try{$query['plan_page']=self::page($query,'plan_page');}catch(InvalidArgumentException $e){http_response_code(422);$error=$e->getMessage();$query['plan_page']=1;}
        echo(new View($csrf))->render($catalog,$recent,$job,$error,['prefix'=>$prefix,'feedback'=>$feedback]+$query);
    }
    private static function text(array $data,string $key):string
    {if(!is_string($data[$key]??''))throw new InvalidArgumentException('Übertragungsangabe prüfen.');return $data[$key]??'';}
    private static function number(mixed $value):int
    {if(!is_string($value)||!preg_match('/\A[1-9][0-9]{0,9}\z/D',$value)||(int)$value>4294967294)throw new InvalidArgumentException('Gültigen Versionsstand angeben.');return(int)$value;}
    private static function page(array $query,string $key):int
    {$text=self::text($query,$key);if($text==='')return 1;$page=self::number($text);if($page>1000000)throw new InvalidArgumentException('Gültige Ergebnisseite angeben.');return $page;}
}
