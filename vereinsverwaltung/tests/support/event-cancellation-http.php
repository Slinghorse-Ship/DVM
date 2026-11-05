<?php declare(strict_types=1);

/** Actual anonymous native-router image requests in the current owned event fixture. */
function ownEventCancellationHttp(string $root,string $mediaRoot,array $requests):array
{
    $cfg=\Kulturhus\Native\TestSupport::config();
    if(getenv('KH_NATIVE_CI')!=='1'||($cfg['owner']??null)!=='isolated-native-ci'||($cfg['label']??null)!=='native-event-catalogue'||realpath($root)!==$root||is_link($root))throw new RuntimeException('Exact owned event HTTP fixture required.');
    $source=dirname(__DIR__,3);$web=$root.'/http-web';if(!is_dir($web)&&!mkdir($web,0700))throw new RuntimeException('Own event web root unavailable.');
    $socket=stream_socket_server('tcp://127.0.0.1:0');$port=(int)substr(strrchr(stream_socket_get_name($socket,false),':'),1);fclose($socket);$origin='http://127.0.0.1:'.$port;
    $router=$root.'/event-http-router.php';$code='<?php declare(strict_types=1);require '.var_export($source.'/vereinsverwaltung/native/bootstrap.php',true).';require '.var_export($source.'/vereinsverwaltung/src/bootstrap.php',true).';$cfg=\\Kulturhus\\Native\\TestSupport::config();if(PHP_SAPI!=="cli-server"||getenv("KH_NATIVE_CI")!=="1"||($cfg["owner"]??null)!=="isolated-native-ci"||($cfg["label"]??null)!=="native-event-catalogue"||($_SERVER["REMOTE_ADDR"]??null)!=="127.0.0.1"||($_SERVER["HTTP_HOST"]??null)!=='.var_export('127.0.0.1:'.$port,true).'){http_response_code(403);exit;} putenv("KH_NATIVE_LOCAL_DEMO=synthetic");putenv("KH_NATIVE_REQUIRE_LOGIN=1");putenv("KH_NATIVE_DEMO_PORT='.$port.'");require '.var_export($source.'/vereinsverwaltung/native/Web/router.php',true).';';
    file_put_contents($router,$code);chmod($router,0600);$log=fopen($root.'/event-http.log','ab');$process=null;
    try{
        $process=proc_open([...\Kulturhus\Native\TestSupport::phpCommand(),'-S','127.0.0.1:'.$port,'-t',$web,$router],[0=>['pipe','r'],1=>$log,2=>$log],$pipes);if(!is_resource($process))throw new RuntimeException('Own event HTTP unavailable.');fclose($pipes[0]);
        $ready=false;for($n=0;$n<100;$n++){if(!proc_get_status($process)['running'])break;$probe=@stream_socket_client('tcp://127.0.0.1:'.$port,$errno,$error,.1);if($probe){fclose($probe);$ready=true;break;}usleep(50000);}if(!$ready)throw new RuntimeException('Own event HTTP not ready.');
        $result=[];foreach($requests as[$method,$path]){
            if(!in_array($method,['GET','HEAD'],true)||!preg_match('~^/media/[a-f0-9]{32}/(?:original|large|medium|thumb)$~D',$path))throw new RuntimeException('Owned image read required.');
            $headers=[];$h=curl_init($origin.$path);curl_setopt_array($h,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_TIMEOUT=>20,CURLOPT_NOBODY=>$method==='HEAD',CURLOPT_HEADERFUNCTION=>static function($h,$line)use(&$headers){if(str_contains($line,':')){[$k,$v]=explode(':',$line,2);$headers[strtolower(trim($k))]=trim($v);}return strlen($line);}]);$body=curl_exec($h);$status=curl_getinfo($h,CURLINFO_RESPONSE_CODE);curl_close($h);if(!is_string($body))throw new RuntimeException('Owned event HTTP request failed.');$result[]=['status'=>$status,'body'=>$body,'headers'=>$headers];
        }
        echo json_encode(['probe'=>'event-cancellation-http','responses'=>array_map(static fn($r)=>['status'=>$r['status'],'mime'=>$r['headers']['content-type']??null,'bytes'=>strlen($r['body']),'sha256'=>hash('sha256',$r['body'])],$result)],JSON_THROW_ON_ERROR).PHP_EOL;
        return $result;
    }finally{
        if(is_resource($process)){proc_terminate($process);proc_close($process);}if(is_resource($log))fclose($log);
        $proof=getenv('DVM_EVENT_CANCELLATION_PROOF_DIR');if(is_string($proof)&&$proof!==''){
            if(realpath($proof)!==$proof||!is_dir($proof)||is_link($proof)||(fileperms($proof)&0077))throw new RuntimeException('Explicit private event HTTP proof directory required.');
            $target=$proof.'/event-http-'.bin2hex(random_bytes(12)).'.log';if(!copy($root.'/event-http.log',$target))throw new RuntimeException('Own event HTTP proof unavailable.');chmod($target,0600);
        }
    }
}
