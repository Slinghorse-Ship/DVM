<?php declare(strict_types=1);
ini_set('display_errors','0');ini_set('zend.exception_ignore_args','1');umask(0077);
if(PHP_SAPI!=='cli'){http_response_code(403);exit;}
require dirname(__DIR__,2).'/vendor/autoload.php';require dirname(__DIR__).'/native/bootstrap.php';require dirname(__DIR__).'/src/bootstrap.php';
use Kulturhus\Native\Demo\{Configuration,Deployment,Install,Operator,Maintenance};
try{
    $operation=$argv[1]??'';$path=$argv[2]??'';
    if(!in_array($operation,['configure','install','mount','enable','disable','status','operator-reset'],true)||count($argv)!==($operation==='mount'?4:3))throw new DomainException('Aufruf: manager-demo.php configure|install|enable|disable|status|operator-reset /privat/demo/demo.json; mount zusätzlich mit öffentlichem Webroot.');
    $input=static function(bool $optional=false):array{if(stream_isatty(STDIN)){if($optional)return [];throw new DomainException('Private JSON-Eingabe über STDIN erforderlich.');}$bytes=stream_get_contents(STDIN,1048577);if($optional&&trim($bytes)==='')return [];if(strlen($bytes)>1048576)throw new DomainException('Private Demo-Eingabe zu groß.');$data=json_decode($bytes,true,32,JSON_THROW_ON_ERROR);if(!is_array($data))throw new DomainException('Private JSON-Eingabe erforderlich.');return $data;};
    if($operation==='configure')$result=Deployment::configure($path,$input());
    elseif($operation==='install'){$credentials=$input(true);$maintenance=$credentials?new Maintenance(Configuration::read($path),$credentials):null;$result=Install::run($path,null,$maintenance);}
    elseif($operation==='mount')$result=Deployment::mount($path,$argv[3]);
    else{
        $configuration=Configuration::read($path);$operator=new Operator($configuration);
        if($operation==='status')$result=$operator->status();
        elseif($operation==='operator-reset'){$data=$input();if(!is_string($data['password']??null))throw new DomainException('Neues Betreiberpasswort über private Eingabe erforderlich.');$operator->reset($data['password']);$result=['operator_reset'=>true];}
        else{if($operation==='enable')Install::assertInstance(Configuration::connect($configuration),$configuration);$result=$operator->enableFromCli($operation==='enable');}
    }
    echo json_encode($result,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n";
}catch(Throwable $failure){fwrite(STDERR,$failure instanceof DomainException?$failure->getMessage()."\n":'Demo-Aktion nicht bestätigt: '.get_class($failure).'; private Details werden nicht ausgegeben.'."\n");exit(1);}
