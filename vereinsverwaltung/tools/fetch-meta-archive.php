<?php
// Run over SSH on the existing website. Only GET requests; credentials stay on the server.
if(PHP_SAPI!=='cli')exit;
try {
$root=$argv[1]??'';if(!is_file($root.'/index.php'))throw new RuntimeException('ProcessWire-Verzeichnis als Argument angeben.');
$wire=(static function($root){require $root.'/index.php';return $wire;})($root);
$module = $wire->modules->get('ProcessKulturhusDashboard');
[$pageId,$token] = (new ReflectionMethod($module,'facebookCredentials'))->invoke($module);
if (!preg_match('/^\d+$/D',$pageId)) throw new RuntimeException('Facebook-Seiten-ID fehlt.');
$query = http_build_query(['fields'=>'id,name,description,start_time,end_time,place,cover,is_canceled,event_times','limit'=>100]);
$next = 'https://graph.facebook.com/v23.0/'.$pageId.'/events?'.$query;
$events=[]; $seen=[]; $pageCount=0;
while ($next !== '') {
    $parts=parse_url($next);
    if (($parts['scheme']??'')!=='https'||($parts['host']??'')!=='graph.facebook.com'||isset($seen[$next])||$pageCount>=100) throw new RuntimeException('Ungültige oder unvollständige Meta-Seitenfolge.');
    $seen[$next]=true;
    parse_str($parts['query']??'', $params); unset($params['access_token']);
    $url='https://graph.facebook.com'.$parts['path'].'?'.http_build_query($params);
    $http=$wire->wire(new \ProcessWire\WireHttp());
    $http->setHeader('Authorization','Bearer '.$token); $http->setTimeout(30);
    $payload=json_decode((string)$http->get($url),true);
    if ($http->getHttpCode()!==200||!is_array($payload['data']??null)) throw new RuntimeException('Meta-Datenabruf fehlgeschlagen (HTTP '.(int)$http->getHttpCode().', Code '.(int)($payload['error']['code']??0).').');
    foreach($payload['data'] as $event)if(preg_match('/^\d+$/D',(string)($event['id']??'')))$events[$event['id']]=$event;
    $pageCount++; $next=(string)($payload['paging']['next']??'');
}
echo json_encode(['schema'=>1,'source'=>'Meta Graph API','complete'=>true,'fetched_at'=>gmdate('c'),'pages'=>$pageCount,'events'=>array_values($events)],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
}catch(Throwable $e){fwrite(STDERR,'Meta-Archivabruf nicht abgeschlossen. '.get_class($e)."\n");exit(1);}
