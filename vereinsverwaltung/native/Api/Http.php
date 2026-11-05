<?php declare(strict_types=1);
namespace Kulturhus\Native\Api;

/** Header-only bearer credentials or the actual native session, never a body/query login. */
final class Http
{
    public static function authenticate(\PDO $db,?string $authorization,?string $session):Context
    {
        if($authorization!==null){if(!preg_match('/^Bearer (khapi_v1_[a-f0-9]{64})$/D',$authorization,$match))throw Problem::unauthorized();return Factory::bearer($db,$match[1]);}
        if(!$session)throw Problem::unauthorized();return Factory::session($db,$session);
    }
    public static function json(string $raw):array
    {
        if(strlen($raw)>1048576)throw new Problem('body_too_large',413,'JSON-Anfrage auf höchstens 1 MiB begrenzen.');
        try{$object=json_decode($raw,false,64,JSON_THROW_ON_ERROR);if(!$object instanceof \stdClass||(property_exists($object,'data')&&!$object->data instanceof \stdClass))throw Problem::invalid();return json_decode($raw,true,64,JSON_THROW_ON_ERROR);}catch(\JsonException){throw Problem::invalid();}
    }
    private static function uploads():array
    {
        Input::keys($_FILES,['files','file']);if(count($_FILES)!==1)throw Problem::invalid();$group=$_FILES['files']??$_FILES['file'];if(!is_array($group))throw Problem::invalid();$result=[];
        if(!is_array($group['name']??null))$group=array_map(static fn(mixed $value):array=>[$value],$group);
        foreach($group['name'] as $index=>$name){$path=$group['tmp_name'][$index]??null;$error=$group['error'][$index]??null;
            if(!is_string($name)||!is_string($path)||$error!==UPLOAD_ERR_OK||!is_uploaded_file($path))throw new Problem('upload_rejected',422,'Vollständige hochgeladene Dateien auswählen.');$result[]=['name'=>$name,'path'=>$path];
        }
        if(!$result||count($result)>10)throw Problem::invalid();return $result;
    }
    public static function serve(\PDO $db,string $managerClub,string $origin,string $mediaRoot,string $documentRoot,?string $session=null,?string $csrf=null):void
    {
        $requestId=bin2hex(random_bytes(12));$method=$_SERVER['REQUEST_METHOD']??'GET';header('Content-Type: application/json; charset=utf-8');header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');header('X-Request-ID: '.$requestId);header('Content-Security-Policy: default-src \'none\'; frame-ancestors \'none\'');
        try{
            if(getenv('VEREIN_MODE')==='production'&&!in_array($_SERVER['HTTPS']??'',['on','1'],true))throw Problem::forbidden();
            if(isset($_SERVER['HTTP_ORIGIN'])&&$_SERVER['HTTP_ORIGIN']!==$origin)throw Problem::forbidden();
            $authorization=$_SERVER['HTTP_AUTHORIZATION']??null;if($authorization!==null&&!is_string($authorization))throw Problem::unauthorized();$context=self::authenticate($db,$authorization,$session);if($context->club()!==$managerClub)throw Problem::forbidden();
            if(!in_array($method,['GET','HEAD','POST','PUT','DELETE'],true))throw new Problem('method_not_allowed',405,'Diese HTTP-Methode gehört nicht zum API-Vertrag.');
            $write=!in_array($method,['GET','HEAD'],true);
            if(!$write&&(int)($_SERVER['CONTENT_LENGTH']??0)>0)throw Problem::invalid();
            if($context->mode==='session'&&$write&&(!$csrf||!is_string($_SERVER['HTTP_X_CSRF_TOKEN']??null)||!hash_equals($csrf,$_SERVER['HTTP_X_CSRF_TOKEN'])))throw new Problem('csrf_required',403,'Aktuellen X-CSRF-Token der nativen Sitzung mitsenden.');
            if($context->mode==='bearer')Factory::tokens($db)->touch(substr($authorization,7));
            $path=parse_url($_SERVER['REQUEST_URI']??'',PHP_URL_PATH);if(!is_string($path))throw Problem::missing();$engine=new Engine($db,$context,$mediaRoot,$documentRoot);$key=$_SERVER['HTTP_IDEMPOTENCY_KEY']??'';if(!is_string($key))throw Problem::invalid();
            if($method==='POST'&&($path==='/api/v1/files'||FilesController::uploadPath($path))&&str_starts_with(strtolower($_SERVER['CONTENT_TYPE']??''),'multipart/form-data')){
                Input::keys($_GET,[]);
                if($path==='/api/v1/files'){Input::keys($_POST,['kind','alt']);$kind=Input::text($_POST,'kind',10);$alt=Input::text($_POST,'alt',500,false);$result=$engine->upload(self::uploads(),$kind,$alt,$key);}
                else $result=(new FilesController($db,$context,$mediaRoot))->upload($path,self::uploads(),$_POST,$key);
            }else{
                $body=[];
                if($write){$contentType=strtolower(trim(explode(';',$_SERVER['CONTENT_TYPE']??'')[0]));if($contentType!=='application/json')throw new Problem('content_type_required',415,'Schreibanfragen benötigen application/json.');$raw=file_get_contents('php://input',false,null,0,1048577);if(!is_string($raw))throw Problem::invalid();$body=self::json($raw);}
                $result=$engine->dispatch($method,$path,$_GET,$body,$key);
            }
            if($result instanceof Download){$result->send($method==='HEAD');return;}
            http_response_code($result['status']);if(isset($result['replayed']))header('Idempotency-Replayed: true');
            if($method!=='HEAD')echo json_encode(['api_version'=>'1','data'=>$result['data'],'meta'=>$result['meta']+['request_id'=>$requestId]],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
        }catch(\Throwable $error){
            $problem=$error instanceof Problem?$error:match(true){$error instanceof \Kulturhus\Native\Cms\QuerySize=>new Problem('response_too_large',413,$error->getMessage()),$error instanceof \Kulturhus\Native\Cms\Conflict,$error instanceof \Kulturhus\Native\Auth\Conflict=>Problem::conflict(),$error instanceof \InvalidArgumentException=>Problem::invalid(),$error instanceof \DomainException=>Problem::forbidden(),default=>new Problem('operation_unconfirmed',503,'Ergebnis nicht bestätigt. Gespeicherten Stand mit derselben Anfragekennung prüfen.')};
            http_response_code($problem->status);if($problem->status===401)header('WWW-Authenticate: Bearer realm="native-api-v1"');if($problem->status===405)header('Allow: GET, HEAD, POST, PUT, DELETE');
            if($method!=='HEAD')echo json_encode(['api_version'=>'1','error'=>['code'=>$problem->key,'message'=>$problem->getMessage(),'request_id'=>$requestId]],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
        }
    }
}
