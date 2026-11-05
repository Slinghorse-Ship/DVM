<?php declare(strict_types=1);
/** Two genuine owned entry points in a fresh isolated database, same original photos. */
function ownGalleryOriginalsBrowser(PDO$db,string$root,array$account,array$data):array
{
 $origins=[];foreach(['root','manager']as$app){$socket=stream_socket_server('tcp://127.0.0.1:0');$port=(int)substr(strrchr(stream_socket_get_name($socket,false),':'),1);fclose($socket);$origins[$app]='http://127.0.0.1:'.$port;}
 $input=['root'=>$root,'origins'=>$origins,'account'=>$account,'data'=>$data];$file=$root.'/gallery-browser.json';file_put_contents($file,json_encode($input,JSON_THROW_ON_ERROR));chmod($file,0600);putenv('DVM_GALLERY_ORIGINALS_FIXTURE='.$file);$processes=[];$logs=[];
 foreach($origins as$app=>$origin){$log=fopen($root.'/gallery-'.$app.'.log','wb');$port=parse_url($origin,PHP_URL_PORT);$process=proc_open([...\Kulturhus\Native\TestSupport::phpCommand(),'-S','127.0.0.1:'.$port,'-t',$root.'/web',__DIR__.'/gallery-originals-browser-router.php'],[0=>['pipe','r'],1=>$log,2=>$log],$pipes);if(!is_resource($process))throw new RuntimeException('Own gallery server unavailable');fclose($pipes[0]);for($i=0;$i<100;$i++){$probe=@stream_socket_client('tcp://127.0.0.1:'.$port,$errno,$error,.1);if($probe){fclose($probe);break;}usleep(50000);}$processes[]=$process;$logs[]=$log;}
 return['processes'=>$processes,'logs'=>$logs,'input'=>$input];
}
