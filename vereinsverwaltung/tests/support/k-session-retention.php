<?php declare(strict_types=1);

/** Real password/HTTP sessions; age only a file in this fixture's private directory. */
function ownKSessionRetention(array $app,callable $check):void
{
    $cfg=json_decode(file_get_contents($app['config']),true,32,JSON_THROW_ON_ERROR);
    if(($cfg['owner']??null)!=='isolated-k-browser'||($cfg['label']??null)!=='native-k-browser-standalone')throw new RuntimeException('Own native K session fixture required.');
    $clients=[];
    $request=static function($client,string $url,?array $post=null):array{
        curl_setopt_array($client,[CURLOPT_URL=>$url,CURLOPT_POST=>$post!==null,CURLOPT_CUSTOMREQUEST=>$post===null?'GET':'POST']);
        if($post!==null)curl_setopt($client,CURLOPT_POSTFIELDS,http_build_query($post));
        $body=curl_exec($client);if(!is_string($body))throw new RuntimeException('Own session HTTP unavailable.');
        return ['status'=>curl_getinfo($client,CURLINFO_RESPONSE_CODE),'body'=>$body];
    };
    try{
        foreach(['reader','admin']as$role){
            $client=curl_init();$clients[$role]=$client;curl_setopt_array($client,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_TIMEOUT=>30,CURLOPT_COOKIEFILE=>'',CURLOPT_HTTPHEADER=>['Origin: '.$app['origin']]]);
            $login=$request($client,$app['origin'].'/login');$dom=new DOMDocument();@$dom->loadHTML($login['body'],LIBXML_NONET);$xpath=new DOMXPath($dom);$forms=$xpath->query('//form[.//input[@name="email"] and .//input[@name="password"]]');
            if($login['status']!==200||$forms->length!==1)throw new RuntimeException('Actual native password form required.');$post=[];
            foreach($xpath->query('.//input[@type="hidden"]',$forms->item(0))as$input)$post[$input->getAttribute('name')]=$input->getAttribute('value');
            $post['email']=$app['credentials'][$role]['email'];$post['password']=$app['credentials'][$role]['password'];
            $signed=$request($client,$app['origin'].'/login',$post);$page=$request($client,$app['origin'].'/?view=overview');
            $check($signed['status']===303&&$page['status']===200&&!str_contains($page['body'],'name="password"'),'actual HTTP password and protected native overview authenticate own '.$role);
        }
        $cookie=null;$expected='KHNATIVE'.substr(hash('sha256',$cfg['database']),0,12);
        foreach(curl_getinfo($clients['reader'],CURLINFO_COOKIELIST)as$line){$parts=explode("\t",$line);if(count($parts)===7&&$parts[5]===$expected)$cookie=$parts[6];}
        if(!is_string($cookie)||!preg_match('/^[a-zA-Z0-9,-]{16,128}$/D',$cookie))throw new RuntimeException('Exact own native session cookie required.');
        $directory=realpath($app['runtime'].'/sessions');$file=$directory.'/sess_'.$cookie;
        if($directory!==$app['runtime'].'/sessions'||!is_file($file)||is_link($file))throw new RuntimeException('Own private native PHP session file required.');
        $content=hash_file('sha256',$file);$db=\Kulturhus\Native\TestSupport::db();$tables=$db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);$before=ownOriginalDatabaseHash($db,$tables);
        if(!touch($file,time()-1801))throw new RuntimeException('Own session age could not be set.');
        $admin=$request($clients['admin'],$app['origin'].'/?view=overview');clearstatcache(true,$file);$retained=is_file($file)&&!is_link($file)&&hash_file('sha256',$file)===$content;
        $reader=$request($clients['reader'],$app['origin'].'/?view=overview');$unchanged=hash_equals($before,ownOriginalDatabaseHash($db,$tables));
        $record=['php_file_older_than_default_gc'=>true,'forced_gc_admin_status'=>$admin['status'],'session_file_retained'=>$retained,'reader_status'=>$reader['status'],'all_original_database_bytes_exact'=>$unchanged,'external_actions'=>0];
        $proof=dirname($app['config']).'/results/native-session-retention.json';file_put_contents($proof,json_encode($record,JSON_THROW_ON_ERROR));chmod($proof,0600);echo json_encode(['native_session_retention'=>$record],JSON_THROW_ON_ERROR).PHP_EOL;
        $check($unchanged,'forced native session garbage collection changes no original account, grant, token or business row');
        $check($admin['status']===200&&$retained&&$reader['status']===200&&!str_contains($reader['body'],'name="password"'),'native PHP session retention preserves a still-valid personal login after thirty inactive minutes');
    }finally{foreach($clients as$client)curl_close($client);}
}
