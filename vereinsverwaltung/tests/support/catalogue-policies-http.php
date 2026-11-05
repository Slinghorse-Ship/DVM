<?php declare(strict_types=1);
/** Real GET/POST/CSRF adapters, using only the caller's fresh isolated database. */
function ownCataloguePolicyHttp(PDO $db,array $fixture,array $modes,array $data,array &$servers,array &$allowedJobs,array &$allowedMedia):void
{
    $root=$fixture['root'];$config=\Kulturhus\Native\TestSupport::config();$inputs=[];
    $public=realpath(dirname(__DIR__,2).'/public');$publicAssets=[];
    foreach([...array_keys(Kulturhus\Verein\ManagerPageAssets::manifest()),'dvm-icon.svg','push-manifest.webmanifest','navigation-no-script.css'] as $name){$asset=realpath($public.'/'.$name);if($asset&&is_file($asset)&&str_starts_with($asset,$public.'/'))$publicAssets[$name]=hash_file('sha256',$asset);}
    $http=static function(string $origin,string $jar,string $path,?array $post=null,bool $json=false,?string $requestOrigin=null,string $fetchSite='',array $uploadFiles=[]):array{
        $headers=[];$curl=curl_init($origin.$path);$requestHeaders=['Origin: '.($requestOrigin??$origin)];if($fetchSite!=='')$requestHeaders[]='Sec-Fetch-Site: '.$fetchSite;if($json)$requestHeaders[]='Accept: application/json';
        curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>20,CURLOPT_COOKIEFILE=>$jar,CURLOPT_COOKIEJAR=>$jar,CURLOPT_HTTPHEADER=>$requestHeaders,CURLOPT_HEADERFUNCTION=>static function($h,string $line)use(&$headers):int{$headers[]=trim($line);return strlen($line);}]);
        if($post!==null)curl_setopt_array($curl,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$uploadFiles?array_replace($post,$uploadFiles):http_build_query($post)]);
        $body=curl_exec($curl);$status=curl_getinfo($curl,CURLINFO_RESPONSE_CODE);curl_close($curl);if(!is_string($body))throw new RuntimeException('Own catalogue HTTP unavailable.');
        if($post!==null)echo json_encode(['event'=>'actual-catalogue-http-post','path'=>$path,'action'=>$post['action']??null,'operation'=>$post['operation']??null,'status'=>$status,'multipart'=>(bool)$uploadFiles],JSON_THROW_ON_ERROR)."\n";
        return [$status,$body,$headers];
    };
    foreach($modes as $mode=>$actors){
        $socket=stream_socket_server('tcp://127.0.0.1:0');if(!$socket)throw new RuntimeException('Own catalogue port unavailable.');$port=(int)substr(strrchr(stream_socket_get_name($socket,false),':'),1);fclose($socket);$origin='http://127.0.0.1:'.$port;
        $f=['root'=>$root,'native_runtime'=>dirname(getenv('KH_NATIVE_TEST_CONFIG')).'/runtime-'.$config['database'],'origin'=>$origin,'mode'=>$mode,'club'=>$fixture['club'],'public_actor'=>$fixture['public_actor'],'accounts'=>$actors['accounts'],'public_assets'=>$publicAssets];
        $file=$root.'/catalogue-'.$mode.'.json';file_put_contents($file,json_encode($f,JSON_THROW_ON_ERROR));chmod($file,0600);
        $environment=getenv();$environment['DVM_CATALOGUE_POLICY_FIXTURE']=$file;$log=fopen($root.'/catalogue-'.$mode.'.log','ab');
        $process=proc_open([...\Kulturhus\Native\TestSupport::phpCommand(),'-S','127.0.0.1:'.$port,'-t',$root.'/web',__DIR__.'/catalogue-policies-router.php'],[0=>['pipe','r'],1=>$log,2=>$log],$pipes,dirname(__DIR__,3),$environment);
        if(!is_resource($process))throw new RuntimeException('Own catalogue server unavailable.');fclose($pipes[0]);$servers[]=['process'=>$process,'log'=>$log];
        for($attempt=0;$attempt<100;$attempt++){$ready=@stream_socket_client('tcp://127.0.0.1:'.$port,$errno,$error,.1);if($ready){fclose($ready);break;}usleep(50000);}
        $gallery=$mode==='native'?'/?view=media':'/vereinsverwaltung/?view=website&tab=gallery';$news=$mode==='native'?'/?view=news':'/vereinsverwaltung/?view=website&tab=news';
        $d=$data[$mode];$policyTables=array_values(array_unique(['khcms_pages','khcms_revisions','khcms_album_order','khcms_album_order_revisions','khcms_state','khcms_template_policy_state','khcms_publication_schedule',...array_filter($db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN),static fn($table)=>str_starts_with($table,'khcms_media'))]));
        foreach(['reader','publisher'] as $role){
            $jar=$root.'/catalogue-'.$mode.'-'.$role.'.cookies';[$entry]=$http($origin,$jar,'/own-test-enter?role='.$role);cpCheck($entry===303,$mode.' actual '.$role.' native session enters its own private controller');
            [$status,$html]=$http($origin,$jar,$gallery);cpCheck($status===200&&substr_count($html,'data-album-row=')===25,$mode.' actual '.$role.' gallery first window contains25 permitted albums');
            foreach([0,6,26] as $i)cpCheck(!str_contains($html,$d['albums'][$i]['title'])&&!str_contains($html,'data-album-row="'.$d['albums'][$i]['id'].'"'),$mode.' actual '.$role.' gallery hides original protected metadata '.$i);
            cpCheck(!str_contains($html,$d['cover']),$mode.' actual '.$role.' list never emits protected cover identity');
            [$status,$last]=$http($origin,$jar,$gallery.'&album_list_page=2');cpCheck($status===200&&substr_count($last,'data-album-row=')===1,$mode.' actual '.$role.' second album page contains exact final permitted original');
            [$status,$search]=$http($origin,$jar,$gallery.'&q='.rawurlencode($d['albums'][0]['title']));cpCheck($status===200&&!str_contains($search,'data-album-row='),$mode.' actual protected album search has no results or failure');
            [$status,$articles]=$http($origin,$jar,$news.'&status=all');cpCheck($status===200&&substr_count($articles,'data-news-row=')===6,$mode.' actual '.$role.' article page stays complete despite hidden own-club articles');
            [$status,$articlesLast]=$http($origin,$jar,$news.'&status=all&page=3');cpCheck($status===200&&substr_count($articlesLast,'data-news-row=')===1,$mode.' actual '.$role.' final article page is exact');
            cpCheck(cpAlbumActionLabel($html,$d['albums'][3]['id'])==='Ansehen'&&cpAlbumActionLabel($html,$d['albums'][10]['id'])===($role==='reader'?'Ansehen':'Bearbeiten'),$mode.' actual '.$role.' row labels follow current object edit rights');
            $itemKey=$mode==='native'?'item':'native_item';
            [$newsStatus,$newsProtectedHtml]=$http($origin,$jar,$news.'&'.$itemKey.'='.$d['news'][3]['id']);cpCheck($newsStatus===200,$mode.' actual '.$role.' reads protected news through genuine controller');
            foreach(['article-save','article-publish','article-unpublish','article-archive','article-unarchive','article-restore','article-review','article-plan','page-place'] as $operation)cpCheck(!cpForm($newsProtectedHtml,$operation),$mode.' actual '.$role.' protected news hides forbidden mutation '.$operation);
            if($role==='reader'){cpCheck(!cpForm($html,'album-order'),$mode.' reader is offered no order mutation form');continue;}
            cpCheck(!cpForm($html,'album-order',$d['albums'][3]['id'])&&!cpForm($html,'album-order',$d['albums'][2]['id'],'down')&&!cpForm($html,'album-order',$d['albums'][7]['id'],'up'),$mode.' actual publisher sees no unusable selected or neighbor movement controls');
            $form=cpForm($html,'album-order',$d['albums'][15]['id'],'down');cpCheck(isset($form['csrf'],$form['order_version'])&&strlen($form['csrf'])===64,$mode.' real permitted adjacent movement carries actual CSRF and original orderCAS');
            $original=ownOriginalDatabaseHash($db,$policyTables);
            foreach([['http://other.example.invalid','same-origin'],['null','cross-site']] as [$badOrigin,$site]){
                [$guardStatus]=$http($origin,$jar,$gallery,$form,false,$badOrigin,$site);cpCheck($guardStatus===403&&ownOriginalDatabaseHash($db,$policyTables)===$original,$mode.' own loopback adapter rejects foreign or null-cross-site origin '.$badOrigin.'/'.$site);
            }
            [$guardStatus]=$http($origin,$jar,$gallery,array_replace($form,['csrf'=>'forged']),false,'null','same-origin');cpCheck($guardStatus>=400&&$guardStatus<500&&ownOriginalDatabaseHash($db,$policyTables)===$original,$mode.' portable null-same-origin adapter still reaches genuine CSRF protection');
            [$status]=$http($origin,$jar,$gallery,array_replace($form,['csrf'=>'forged']));cpCheck($status>=400&&$status<500&&ownOriginalDatabaseHash($db,$policyTables)===$original,$mode.' actual forgedCSRF cannot change any original row');
            [$status]=$http($origin,$jar,$gallery,array_replace($form,['id'=>$d['albums'][3]['id'],'version'=>$d['albums'][3]['version']]));cpCheck($status>=400&&$status<500&&ownOriginalDatabaseHash($db,$policyTables)===$original,$mode.' actual forged protected target rejected with every original byte intact');
            [$status]=$http($origin,$jar,$gallery,array_replace($form,['order_version'=>0]),true);cpCheck($status===409&&ownOriginalDatabaseHash($db,$policyTables)===$original,$mode.' actual JSON stale orderCAS returns409 without original writes');
            $order=new \Kulturhus\Native\Cms\AlbumOrder($db,$actors['admin']);$beforeIds=$order->state()['ids'];$expected=$beforeIds;$position=array_search($d['albums'][15]['id'],$expected,true);[$expected[$position],$expected[$position+1]]=[$expected[$position+1],$expected[$position]];
            [$status]=$http($origin,$jar,$gallery,$form);cpCheck(in_array($status,[200,303],true)&&$order->state()['ids']===$expected,$mode.' actual HTML permitted orderPOST swaps only its true neighbor and preserves every hidden ID');
            [$status,$detail]=$http($origin,$jar,$gallery.'&'.($mode==='native'?'item':'native_item').'='.$d['albums'][3]['id']);cpCheck($status===200,$mode.' actual protected published album remains readable');
            foreach(['album-save','album-publish','album-unpublish','album-plan','album-cancel-plan','album-restore','page-place'] as $operation)cpCheck(!cpForm($detail,$operation),$mode.' actual original album hides forbidden mutation '.$operation);
            $newsOriginal=ownOriginalDatabaseHash($db,$policyTables);$protected=$d['news'][3];$dispatcher=array_intersect_key($form,['action'=>true]);
            cpCheck($mode==='native'?$dispatcher===[]:($dispatcher['action']??null)==='native-module',$mode.' hand-built negative news posts retain the genuine offered dispatcher');
            foreach([
                ['operation'=>'article-save','id'=>$protected['id'],'version'=>$protected['version'],'csrf'=>$form['csrf'],'title'=>'Own forbidden article change','slug'=>basename($protected['slug']),'news_date'=>$protected['news_date'],'excerpt'=>'Own forbidden excerpt','body_html'=>'<p>Own forbidden body</p>'],
                ['operation'=>'article-review','id'=>$protected['id'],'version'=>$protected['version'],'csrf'=>$form['csrf'],'review_status'=>'approved'],
                ['operation'=>'article-archive','id'=>$protected['id'],'version'=>$protected['version'],'csrf'=>$form['csrf']],
            ] as $forged){[$newsStatus]=$http($origin,$jar,$news.'&'.$itemKey.'='.$protected['id'],array_replace($dispatcher,$forged));cpCheck($newsStatus>=400&&$newsStatus<500&&ownOriginalDatabaseHash($db,$policyTables)===$newsOriginal,$mode.' actual forged protected '.$forged['operation'].' changes no original byte (HTTP '.$newsStatus.')');}
            $mediaBefore=cpMediaStorageState($actors['media']);
            [$newsStatus]=$http($origin,$jar,$news.'&'.$itemKey.'='.$protected['id'],array_replace($dispatcher,['csrf'=>$form['csrf'],'operation'=>'article-upload','id'=>$protected['id'],'version'=>$protected['version'],'alt'=>'Own forbidden media upload']),false,null,'',['image'=>new CURLFile($root.'/original.png','image/png','own-forged.png')]);
            cpCheck($newsStatus>=400&&$newsStatus<500&&ownOriginalDatabaseHash($db,$policyTables)===$newsOriginal&&cpMediaStorageState($actors['media'])===$mediaBefore,$mode.' genuine forged multipart on edit-manage source creates no media/quarantine/quota row or file');
            $allowed=$d['news'][4];[$newsStatus,$editable]=$http($origin,$jar,$news.'&'.$itemKey.'='.$allowed['id']);$saveForm=cpForm($editable,'article-save',$allowed['id']);
            cpCheck($newsStatus===200&&isset($saveForm['csrf'],$saveForm['version']),(string)$mode.' actual permitted editor retains genuine article save form');
            $permittedPost=array_replace($saveForm,['body_html'=>'<p>Own authorized human article correction.</p>']);[$newsStatus]=$http($origin,$jar,$news.'&'.$itemKey.'='.$allowed['id'],$permittedPost);$saved=(new Kulturhus\Native\Cms\Pages($db,$actors['admin']))->get($allowed['id']);
            cpCheck(in_array($newsStatus,[200,303],true)&&$saved['version']===$allowed['version']+1&&$saved['title']===$allowed['title']&&$saved['body_html']==='<p>Own authorized human article correction.</p>'&&$saved['published_revision']===null,$mode.' actual authorized article save updates only chosen private original version');
            [$newsStatus,$editable]=$http($origin,$jar,$news.'&'.$itemKey.'='.$allowed['id']);$reviewForm=cpForm($editable,'article-review',$allowed['id']);cpCheck(isset($reviewForm['csrf'],$reviewForm['review_status'])&&$reviewForm['version']===(string)$saved['version'],$mode.' permitted editor is offered fresh original-bound review transition');
            [$newsStatus]=$http($origin,$jar,$news.'&'.$itemKey.'='.$allowed['id'],$reviewForm);$reviewed=(new Kulturhus\Native\Cms\Pages($db,$actors['admin']))->get($allowed['id']);
            cpCheck(in_array($newsStatus,[200,303],true)&&$reviewed['version']===$saved['version']+1&&$reviewed['news_review']===$reviewForm['review_status']&&$reviewed['body_html']===$saved['body_html'],$mode.' actual authorized editorial transition succeeds without changing human text');
            $uploadPost=array_replace($dispatcher,['csrf'=>$saveForm['csrf'],'operation'=>'article-upload','id'=>$allowed['id'],'version'=>$reviewed['version'],'alt'=>'Own original upload <&> ä🙂']);$uploadFiles=['image'=>new CURLFile($root.'/original.png','image/png','own-real.png')];
            $currentHash=ownOriginalDatabaseHash($db,$policyTables);$mediaBefore=cpMediaStorageState($actors['media']);[$newsStatus]=$http($origin,$jar,$news.'&'.$itemKey.'='.$allowed['id'],array_replace($uploadPost,['version'=>$allowed['version']]),false,null,'',$uploadFiles);
            cpCheck($newsStatus===409&&ownOriginalDatabaseHash($db,$policyTables)===$currentHash&&cpMediaStorageState($actors['media'])===$mediaBefore,$mode.' actual stale multipart CAS leaves no image/quarantine/quota row or orphan file');
            [$newsStatus]=$http($origin,$jar,$news.'&'.$itemKey.'='.$allowed['id'],$uploadPost,false,null,'',$uploadFiles);$uploaded=(new Kulturhus\Native\Cms\Pages($db,$actors['admin']))->get($allowed['id']);$newImages=array_values(array_diff($uploaded['media_ids'],$reviewed['media_ids']));
            cpCheck(in_array($newsStatus,[200,303],true)&&$uploaded['version']===$reviewed['version']+1&&count($newImages)===1&&$uploaded['body_html']===$reviewed['body_html'],$mode.' actual permitted multipart atomically attaches exactly one new image and original human text');
            $newImage=$newImages[0];$allowedMedia[]=$newImage;$originalVariant=(new Kulturhus\Native\Cms\Media($db,$actors['admin'],$actors['media']))->variant($newImage,'original');
            cpCheck($originalVariant['alt']===$uploadPost['alt']&&$originalVariant['sha256']===hash_file('sha256',$originalVariant['path']),$mode.' accepted original variant and human alternative text are actually retained');
            cpCheck($uploaded['published_revision']===null&&(new Kulturhus\Native\Cms\Media($db,$actors['admin'],$actors['media']))->publicVariant($newImage,'original')===null,$mode.' private article upload does not release bytes or publish the draft');
            ownCatalogueMultipartRace($db,$actors,$origin,$jar,$news.'&'.$itemKey.'='.$allowed['id'],array_replace($uploadPost,['version'=>$uploaded['version']]),$root.'/original.png',$policyTables,$mode);


        }
        $inputs[$mode]=['origin'=>$origin,'gallery'=>$gallery,'news'=>$news,'data'=>$d];
    }
    if(getenv('DVM_CATALOGUE_POLICY_BROWSER')==='1'){
        $process=proc_open(['node',dirname(__DIR__).'/catalogue-policies-browser.mjs'],[0=>['pipe','r'],1=>STDOUT,2=>STDERR],$pipes);
        if(!is_resource($process))throw new RuntimeException('Own catalogue browser unavailable.');fwrite($pipes[0],json_encode($inputs,JSON_THROW_ON_ERROR));fclose($pipes[0]);if(proc_close($process)!==0){foreach(['native','integrated'] as $app){$log=$root.'/catalogue-'.$app.'.log';echo json_encode(['event'=>'own-catalogue-server-failure-log','app'=>$app,'tail'=>is_file($log)?substr(file_get_contents($log),-12000):null],JSON_THROW_ON_ERROR)."\n";}throw new RuntimeException('Actual catalogue policies browser failed.');}
    }
}

/** Hold the real club mutex until a genuine HTTP multipart reaches its staged files. */
function ownCatalogueMultipartRace(PDO $db,array $actors,string $origin,string $jar,string $path,array $post,string $source,array $tables,string $mode):void
{
    if($db->inTransaction())throw new RuntimeException('Own race needs its own transaction.');
    $club=$actors['admin']->club();$directory=$actors['media'].'/'.hash('sha256',$club);
    $oldDirectories=glob($directory.'/*',GLOB_ONLYDIR)?:[];$storage=cpMediaStorageState($actors['media']);
    $curl=curl_init($origin.$path);$multi=curl_multi_init();
    curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>20,CURLOPT_COOKIEFILE=>$jar,CURLOPT_HTTPHEADER=>['Origin: '.$origin],CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>array_replace($post,['image'=>new CURLFile($source,'image/png','own-race.png')])]);
    curl_multi_add_handle($multi,$curl);$running=0;
    try{
        $db->beginTransaction();
        Kulturhus\Native\Shared\Db::run($db,'SELECT generation FROM khcms_state WHERE club_id=? FOR UPDATE',[$club])->fetchColumn();
        $deadline=microtime(true)+10;$staged=false;
        do{
            if(curl_multi_exec($multi,$running)!==CURLM_OK)throw new RuntimeException('Own multipart transport could not advance.');
            if(array_diff(glob($directory.'/*',GLOB_ONLYDIR)?:[],$oldDirectories)){$staged=true;break;}
            if(!$running)throw new RuntimeException('Own multipart ended before staging: HTTP '.curl_getinfo($curl,CURLINFO_RESPONSE_CODE));
            curl_multi_select($multi,.02);
        }while(microtime(true)<$deadline);
        if(!$staged)throw new RuntimeException('Own real multipart never reached its staged files.');
        cpCheck(true,$mode.' genuine multipart reached pre-save files while original club mutex is held');
        $pages=new Kulturhus\Native\Cms\Pages($db,$actors['admin']);$current=$pages->get($post['id']);
        $concurrent=$pages->save(array_replace($current,['excerpt'=>'Own deliberate simultaneous policy change.','page_policy'=>['edit'=>'manage']]),$current['version']);
        $expected=ownOriginalDatabaseHash($db,$tables);$db->commit();
        $deadline=microtime(true)+10;
        do{if(curl_multi_exec($multi,$running)!==CURLM_OK)throw new RuntimeException('Own multipart transport failed after release.');if(!$running)break;curl_multi_select($multi,.02);}while(microtime(true)<$deadline);
        if($running)throw new RuntimeException('Own real multipart did not finish after release.');
        $status=curl_getinfo($curl,CURLINFO_RESPONSE_CODE);
        cpCheck($status===409,$mode.' actual in-flight upload meets deliberate concurrent CAS and object-policy change (HTTP '.$status.')');
        cpCheck(ownOriginalDatabaseHash($db,$tables)===$expected&&cpMediaStorageState($actors['media'])===$storage,$mode.' failed callback rolls back every new media/quarantine/quota row and derivative directory while retaining exact concurrent originals');
        $retained=$pages->get($post['id']);cpCheck($retained===$concurrent&&$retained['body_html']===$current['body_html'],$mode.' upload rollback preserves the complete newer original version, ACL and human text');
    }finally{
        if($db->inTransaction())$db->rollBack();
        curl_multi_remove_handle($multi,$curl);curl_close($curl);curl_multi_close($multi);
    }
}
