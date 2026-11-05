<?php declare(strict_types=1);
require dirname(__DIR__).'/native/bootstrap.php';
require dirname(__DIR__).'/src/bootstrap.php';
require __DIR__.'/support/original-database-hash.php';
require __DIR__.'/support/editorial-browser-fixture.php';
use Kulturhus\Native\TestSupport;
use Kulturhus\Native\Shared\{Access,Db};
use Kulturhus\Native\Cms\{AlbumCatalogue,AlbumOrder,ArticleCatalogue,Conflict,DataModel,Media,Pages,PublicationSchedule,TemplatePolicy};
use Kulturhus\Native\Web\{Gallery,PublicationPlan};

$cfg=TestSupport::config();
if(($cfg['label']??'')!=='native-catalogue-policies'||($cfg['owner']??'')!=='isolated-native-ci')throw new RuntimeException('Own catalogue policy suite required.');
$db=TestSupport::db();Kulturhus\Native\Installer\SchemaRegistry::install($db);TestSupport::seed($db);
$fixture=ownEditorialBrowserFixture($db);$root=$fixture['root'];
$nativeRoot=dirname(getenv('KH_NATIVE_TEST_CONFIG')).'/runtime-'.$cfg['database'];
foreach([$nativeRoot.'/sessions',$nativeRoot.'/media'] as $directory)if(!is_dir($directory))mkdir($directory,0700,true);
copy($root.'/auth.key',$nativeRoot.'/auth.key');chmod($nativeRoot.'/auth.key',0600);
Db::run($db,"INSERT INTO native_users(id,club_id,name) VALUES('catalogue-publisher','synthetic-a','Own catalogue publisher')");
foreach(['read','edit','publish'] as $cap)Db::run($db,"INSERT INTO native_grants(actor_id,module,capability) VALUES('catalogue-publisher','website',?)",[$cap]);
foreach(['read','manage'] as $cap)Db::run($db,"INSERT INTO native_grants(actor_id,module,capability) VALUES('admin','accounts',?)",[$cap]);
$auth=Kulturhus\Native\Auth\Runtime::service($db);$nativeAccounts=[];
foreach(['admin'=>'admin','publisher'=>'catalogue-publisher','reader'=>'reader'] as $role=>$actor){
    $email='own-catalogue-'.$role.'@example.invalid';$invite=$auth->createInvitation(new Access($db,'admin'),$actor,$email);
    $auth->acceptInvitation($invite['token'],'Own isolated catalogue password 2026!');
    $nativeAccounts[$role]=['actor'=>$actor,'token'=>$auth->passwordLogin($email,'Own isolated catalogue password 2026!','own-catalogue-'.$role)['session_token']];
}
$integratedPublisher=new Kulturhus\Native\Integration\ManagerBridge($db,$fixture['accounts']['editor']['actor'],'native',static fn()=>null);
$integratedReader=new Kulturhus\Native\Integration\ManagerBridge($db,$fixture['accounts']['reader']['actor'],'native',static fn()=>null);
$modes=[
    'native'=>['admin'=>new Access($db,'admin'),'publisher'=>new Access($db,'catalogue-publisher'),'reader'=>new Access($db,'reader'),'media'=>$nativeRoot.'/media','accounts'=>$nativeAccounts],
    'integrated'=>['admin'=>$fixture['admin'],'publisher'=>$integratedPublisher->access(),'reader'=>$integratedReader->access(),'media'=>$root.'/media','accounts'=>['admin'=>$fixture['accounts']['admin'],'publisher'=>$fixture['accounts']['editor'],'reader'=>$fixture['accounts']['reader']]],
];
$checks=0;
function cpCheck(bool $ok,string $label):void{global $checks;if(!$ok)throw new RuntimeException('FAIL '.$label);$checks++;echo 'PASS '.$label."\n";}
function cpReject(callable $fn,string $class,string $label):void{try{$fn();}catch(Throwable $error){cpCheck($error instanceof $class,$label.' ('.$error::class.')');return;}throw new RuntimeException('Accepted '.$label);}
function cpForm(string $html,string $operation,?string $id=null,?string $direction=null):array{
    $dom=new DOMDocument();@$dom->loadHTML($html,LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING);$xp=new DOMXPath($dom);
    foreach($xp->query('//form') as $form){$data=[];foreach($xp->query('.//input[@name]',$form) as $input)$data[$input->getAttribute('name')]=$input->getAttribute('value');
        if(($data['operation']??'')===$operation&&($id===null||($data['id']??'')===$id)&&($direction===null||($data['direction']??'')===$direction))return $data;}
    return [];
}
function cpAlbumActionLabel(string $html,string $id,string $kind='album'):string{
    $dom=new DOMDocument();@$dom->loadHTML($html,LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING);$xp=new DOMXPath($dom);
    return trim($xp->evaluate('string(//tr[@data-'.$kind.'-row="'.$id.'"]/td[last()]//a[1])'));
}
function cpMediaStorageState(string $root):array{
    $state=[];if(!is_dir($root))return $state;
    foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::SELF_FIRST) as $entry){$name=substr($entry->getPathname(),strlen($root)+1);$state[$name]=$entry->isLink()?['link'=>readlink($entry->getPathname())]:($entry->isDir()?['directory'=>true]:['bytes'=>$entry->getSize(),'sha256'=>hash_file('sha256',$entry->getPathname())]);}
    ksort($state,SORT_STRING);return $state;
}
$pixel=imagecreatetruecolor(13,11);imagefill($pixel,0,0,imagecolorallocate($pixel,11,97,123));imagepng($pixel,$root.'/original.png');imagedestroy($pixel);
$sourceHash=hash_file('sha256',$root.'/original.png');$mutablePages=[];$allowedJobs=[];$allowedMedia=[];$data=[];
foreach($modes as $mode=>$actors){
    $admin=$actors['admin'];$pages=new Pages($db,$admin);$model=new DataModel($db,$admin);$prefix='Own '.$mode.' policy';
    $privateAlbum=$model->saveTemplate(['key'=>'catalogue_private_album','label'=>'Own restricted album model','renderer'=>'gallery','fields'=>[],'policy'=>['read'=>'manage']]);
    $publishAlbum=$model->saveTemplate(['key'=>'catalogue_publish_album','label'=>'Own privileged publication album model','renderer'=>'gallery','fields'=>[],'policy'=>['publish'=>'manage']]);
    $parent=$pages->save(['slug'=>'catalogue-restricted-parent','title'=>$prefix.' private parent','page_policy'=>['read'=>'manage']]);
    $cover=(new Media($db,$admin,$actors['media']))->upload($root.'/original.png','own-secret-cover.png',$prefix.' secret cover');
    $albums=[];
    for($n=0;$n<29;$n++){
        $input=['template'=>'gallery','title'=>$prefix.' album '.sprintf('%02d',$n),'slug'=>'galerie/catalogue-'.$n,'nav_order'=>$n,'body'=>'Own original album body '.$n];
        if($n===0)$input+=['page_policy'=>['read'=>'manage'],'media_ids'=>[$cover['id']]];
        if($n===6)$input['template']=$privateAlbum['key'];
        if($n===8)$input['template']=$publishAlbum['key'];
        if($n===26)$input['parent_id']=$parent['id'];
        if($n===3)$input['page_policy']=['edit'=>'manage','publish'=>'manage'];
        $album=$pages->save($input);if($n===3)$album=$pages->publish($album['id'],$album['version']);$albums[]=$album;
    }
    $news=[];
    for($n=0;$n<16;$n++){
        $input=['template'=>'news','title'=>$prefix.' news '.sprintf('%02d',$n),'slug'=>'aktuelles/catalogue-'.$n,'news_date'=>'2098-01-'.sprintf('%02d',$n+1),'body_html'=>'<p>Own original news '.$n.'</p>'];
        if(in_array($n,[0,6],true))$input['page_policy']=['read'=>'manage'];
        if($n===14)$input['parent_id']=$parent['id'];
        if($n===3)$input['page_policy']=['edit'=>'manage','publish'=>'manage'];
        $article=$pages->save($input);if($n===3)$article=$pages->publish($article['id'],$article['version']);$news[]=$article;
    }
    $currentNews=$model->template('news');$liveCatalogue=new ArticleCatalogue($db,$actors['reader']);
    $originalPages=ownOriginalDatabaseHash($db,['khcms_pages','khcms_revisions']);
    $restricted=$model->saveTemplate(array_replace($currentNews,['policy'=>['read'=>'manage']]),$currentNews['version']);
    cpCheck($liveCatalogue->listing(['status'=>'all'])['total']===0,$mode.' existing article catalogue obeys newly restricted current template before count');
    $model->saveTemplate(array_replace($currentNews,['policy'=>[]]),$restricted['version']);
    cpCheck($liveCatalogue->listing(['status'=>'all'])['total']===13&&ownOriginalDatabaseHash($db,['khcms_pages','khcms_revisions'])===$originalPages,$mode.' current template regrant restores complete count without rewriting any original page');
    $data[$mode]=['albums'=>$albums,'news'=>$news,'cover'=>$cover['id'],'club'=>$admin->club(),'parent'=>$parent['id'],'private_album_template'=>$privateAlbum];
    $mutablePages[]=$albums[13]['id'];$mutablePages[]=$news[4]['id'];
}
$tables=$db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);$clubs=array_column($data,'club');
$filter=static function(string $table)use(&$allowedJobs,&$allowedMedia,$mutablePages,$clubs):array{
    if(in_array($table,['khcms_pages','khcms_revisions'],true))return [' WHERE '.($table==='khcms_pages'?'id':'page_id').' NOT IN ('.implode(',',array_fill(0,count($mutablePages),'?')).')',$mutablePages];
    if(in_array($table,['khcms_state','khcms_template_policy_state','khcms_album_order','khcms_album_order_revisions'],true))return [' WHERE club_id NOT IN (?,?)',$clubs];
    if($table==='khcms_media'&&$allowedMedia)return [' WHERE id NOT IN ('.implode(',',array_fill(0,count($allowedMedia),'?')).')',$allowedMedia];
    if($table==='khcms_publication_schedule'&&$allowedJobs)return [' WHERE id NOT IN ('.implode(',',array_fill(0,count($allowedJobs),'?')).')',$allowedJobs];
    return ['',[]];
};
$before=ownOriginalDatabaseHash($db,$tables,$filter);$servers=[];
$originalFiles=[$root.'/original.png'=>$sourceHash,$nativeRoot.'/auth.key'=>hash_file('sha256',$nativeRoot.'/auth.key')];foreach(['auth','private'] as $name)$originalFiles[$root.'/'.$name.'.key']=hash_file('sha256',$root.'/'.$name.'.key');
foreach($modes as $actors)foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($actors['media'],FilesystemIterator::SKIP_DOTS)) as $file)if($file->isFile())$originalFiles[$file->getPathname()]=hash_file('sha256',$file->getPathname());
try{
    foreach($modes as $mode=>$actors){
        $d=$data[$mode];$admin=$actors['admin'];$pub=$actors['publisher'];$reader=$actors['reader'];$pages=new Pages($db,$admin);
        $albumCatalogue=new AlbumCatalogue($db,$reader);$newsCatalogue=new ArticleCatalogue($db,$reader);$order=new AlbumOrder($db,$pub);
        $actor=$pub->user();
        if($mode==='native')Db::run($db,"UPDATE native_users SET active=0 WHERE id='catalogue-publisher'");
        else Db::run($db,'UPDATE actors SET active=0 WHERE id=?',[$fixture['accounts']['editor']['actor']]);
        cpReject(fn()=>$order->move($d['albums'][10]['id'],'down',0),DomainException::class,$mode.' current global role/session authority is rechecked by an existing order service');
        if($mode==='native')Db::run($db,"UPDATE native_users SET active=1 WHERE id='catalogue-publisher'");
        else Db::run($db,'UPDATE actors SET active=1 WHERE id=?',[$fixture['accounts']['editor']['actor']]);
        $visibleAlbums=array_values(array_filter($d['albums'],static fn($p,$i)=>!in_array($i,[0,6,26],true),ARRAY_FILTER_USE_BOTH));
        $expected=array_column($visibleAlbums,'id');$one=$albumCatalogue->listing();$two=$albumCatalogue->listing(['album_list_page'=>'2']);
        cpCheck($one['total']===26&&array_column($one['items'],'id')===array_slice($expected,0,25)&&array_column($two['items'],'id')===array_slice($expected,25),$mode.' album page/template/inherited rights precede count and25-slot window');
        foreach([0,6,26] as $i)cpCheck($albumCatalogue->listing(['q'=>$d['albums'][$i]['title']])['total']===0,$mode.' hidden album title cannot become a search/count oracle '.$i);
        cpReject(fn()=>(new PublicationSchedule($db,$reader))->forPage($d['albums'][0]['id']),DomainException::class,$mode.' direct schedule metadata obeys actual original read policy');
        cpCheck(!in_array($d['albums'][0]['id'],$albumCatalogue->order(),true),$mode.' normal public PHP catalogue order never returns hidden IDs');
        $newsAll=[];foreach([1,2,3] as $page){$slice=$newsCatalogue->listing(['status'=>'all','page'=>$page]);cpCheck($slice['total']===13&&count($slice['items'])===($page===3?1:6),$mode.' article complete allowed page '.$page);$newsAll=[...$newsAll,...array_column($slice['items'],'id')];}
        cpCheck(count(array_unique($newsAll))===13&&!array_intersect(array_column([$d['news'][0],$d['news'][6],$d['news'][14]],'id'),$newsAll),$mode.' hidden article never aborts a whole permitted page or enters counts');
        foreach([0,6,14] as $i)cpCheck($newsCatalogue->listing(['status'=>'all','q'=>$d['news'][$i]['title']])['total']===0,$mode.' hidden article title search stays empty '.$i);
        $rights=$order->movements(array_column($d['albums'],'id'));
        cpCheck(!$rights[$d['albums'][3]['id']]['up']&&!$rights[$d['albums'][2]['id']]['down']&&!$rights[$d['albums'][7]['id']]['up']&&$rights[$d['albums'][10]['id']]['down'],$mode.' form projection checks actual selected and adjacent object rights without skipping hidden neighbor');
        $protected=['khcms_state','khcms_album_order','khcms_album_order_revisions','khcms_pages','khcms_revisions','khcms_publication_schedule'];$initial=ownOriginalDatabaseHash($db,$protected);
        foreach([[3,'down'],[2,'down'],[7,'up'],[8,'down'],[7,'down']] as [$i,$direction])cpReject(fn()=>$order->move($d['albums'][$i]['id'],$direction,0),DomainException::class,$mode.' forged selected/neighbor/page/template/hidden-neighbor movement denied '.$i.'/'.$direction);
        cpCheck(ownOriginalDatabaseHash($db,$protected)===$initial,$mode.' all rejected moves preserve full order, generation and every page/revision');
        $oldIds=array_column($d['albums'],'id');$expectedMove=$oldIds;[$expectedMove[10],$expectedMove[11]]=[$expectedMove[11],$expectedMove[10]];
        $moved=$order->move($d['albums'][10]['id'],'down',0);cpCheck($moved['ids']===$expectedMove&&$moved['version']===1,$mode.' permitted move preserves every hidden original ID and all unrelated positions');
        $afterMove=ownOriginalDatabaseHash($db,$protected);cpReject(fn()=>$order->move($d['albums'][11]['id'],'down',0),Conflict::class,$mode.' stale order CAS refused');cpCheck(ownOriginalDatabaseHash($db,$protected)===$afterMove,$mode.' stale movement leaves all source rows exact');
        $schedule=new PublicationSchedule($db,$pub);$stalePlan=$schedule->plan($d['albums'][13]['id'],1,time()+87000);$allowedJobs[]=$stalePlan['id'];
        $stalePlanOriginal=Db::run($db,'SELECT * FROM khcms_publication_schedule WHERE id=?',[$stalePlan['id']])->fetch();
        $stale=$pages->save(array_replace($d['albums'][13],['page_policy'=>['publish'=>'manage']]),1);$denied=ownOriginalDatabaseHash($db,$protected);
        cpReject(fn()=>$order->move($d['albums'][12]['id'],'down',1),DomainException::class,$mode.' new object revoke applies to an existing service before lock-bound mutation');cpCheck(ownOriginalDatabaseHash($db,$protected)===$denied,$mode.' fresh policy denial preserves source order and its immutable revision');
        cpReject(fn()=>$schedule->cancel($stalePlan['id'],1),DomainException::class,$mode.' fresh page policy revokes cancellation through an already existing schedule service');
        cpCheck(Db::run($db,'SELECT * FROM khcms_publication_schedule WHERE id=?',[$stalePlan['id']])->fetch()===$stalePlanOriginal,$mode.' revoked cancellation preserves original approval snapshot/hash/time/actor');
        $blocked=ownOriginalDatabaseHash($db,$protected);
        cpReject(fn()=>$schedule->plan($d['albums'][3]['id'],$d['albums'][3]['version'],time()+86400),DomainException::class,$mode.' object publish rule blocks new schedule');
        cpCheck(ownOriginalDatabaseHash($db,$protected)===$blocked,$mode.' rejected plan preserves all original schedule columns and hashes');
        $plan=$schedule->plan($d['albums'][18]['id'],1,time()+86400);$allowedJobs[]=$plan['id'];
        $raw=Db::run($db,'SELECT * FROM khcms_publication_schedule WHERE id=?',[$plan['id']])->fetch();
        cpReject(fn()=>$schedule->cancel($plan['id'],0),Conflict::class,$mode.' stale schedule CAS refused');
        cpCheck(Db::run($db,'SELECT * FROM khcms_publication_schedule WHERE id=?',[$plan['id']])->fetch()===$raw,$mode.' stale cancellation preserves exact original plan');
        $cancelled=$schedule->cancel($plan['id'],1);$after=Db::run($db,'SELECT * FROM khcms_publication_schedule WHERE id=?',[$plan['id']])->fetch();
        cpCheck($cancelled['status']==='cancelled'&&$after['snapshot_json']===$raw['snapshot_json']&&$after['snapshot_hash']===$raw['snapshot_hash']&&$after['due_at']===$raw['due_at'],$mode.' deliberate cancellation preserves original payload/hash/time');
        $privatePlan=(new PublicationSchedule($db,$admin))->plan($d['albums'][3]['id'],$d['albums'][3]['version'],time()+90000);$privateRaw=Db::run($db,'SELECT * FROM khcms_publication_schedule WHERE id=?',[$privatePlan['id']])->fetch();$allowedJobs[]=$privatePlan['id'];
        cpReject(fn()=>$schedule->cancel($privatePlan['id'],1),DomainException::class,$mode.' object rule prevents cancelling another protected original plan');
        cpCheck(Db::run($db,'SELECT * FROM khcms_publication_schedule WHERE id=?',[$privatePlan['id']])->fetch()===$privateRaw,$mode.' forbidden cancellation preserves complete original job');
        $rollbackBefore=ownOriginalDatabaseHash($db,$protected);$db->beginTransaction();
        try{cpReject(fn()=>$schedule->plan($d['albums'][19]['id'],1,time()+94000),LogicException::class,$mode.' existing own-transaction planning contract rejects nested caller transaction');cpCheck($db->inTransaction(),$mode.' rejected planning does not commit or rollback caller transaction');}finally{if($db->inTransaction())$db->rollBack();}
        cpCheck(ownOriginalDatabaseHash($db,$protected)===$rollbackBefore,$mode.' rejected outer transaction leaves no half-plan or original mutation');
        $html=(new Gallery($db,$pub,$actors['media'],'own-catalogue-csrf'))->manager($d['albums'][3]['id']);
        foreach(['album-save','album-publish','album-unpublish','album-plan','album-cancel-plan','album-restore','page-place'] as $operation)cpCheck(!cpForm($html,$operation),$mode.' direct readable album omits forbidden mutation '.$operation);
        $listingHtml=(new Gallery($db,$pub,$actors['media'],'own-catalogue-csrf'))->manager();
        cpCheck(cpAlbumActionLabel($listingHtml,$d['albums'][3]['id'])==='Ansehen'&&cpAlbumActionLabel($listingHtml,$d['albums'][10]['id'])==='Bearbeiten',$mode.' actual list action label follows individual edit policy');
        $articleView=new Kulturhus\Native\Web\Articles($db,$pub,$actors['media'],'own-catalogue-csrf');$newsProtected=$d['news'][3];
        $newsHtml=$articleView->manager($newsProtected['id']);
        foreach(['article-save','article-publish','article-unpublish','article-archive','article-unarchive','article-restore','article-review','article-plan','page-place'] as $operation)cpCheck(!cpForm($newsHtml,$operation),$mode.' readable protected news omits forbidden mutation '.$operation);
        $adminHtml=(new Kulturhus\Native\Web\Articles($db,$admin,$actors['media'],'own-catalogue-csrf'))->manager($newsProtected['id']);
        foreach(['article-save','article-publish','article-unpublish','article-archive','article-restore','article-review','article-plan','page-place'] as $operation)cpCheck((bool)cpForm($adminHtml,$operation),$mode.' actually authorized administrator retains news mutation '.$operation);
        $reviewPanel=new Kulturhus\Native\Web\Website\NewsReviewPanel($db,$pub,'own-catalogue-csrf');
        cpCheck(!cpForm($reviewPanel->render($newsProtected,'page'),'page-review')&&(bool)cpForm($reviewPanel->render($d['news'][4],'page'),'page-review'),$mode.' shared general page review panel follows each actual object policy');
        $newsList=$articleView->manager('',['status'=>'all','page'=>2]);
        cpCheck(cpAlbumActionLabel($newsList,$newsProtected['id'],'news')==='Ansehen'&&cpAlbumActionLabel($newsList,$d['news'][4]['id'],'news')==='Bearbeiten'&&!cpForm($newsList,'article-archive',$newsProtected['id']),$mode.' news list offers only actual object edit/archive actions');
        $d['order_version']=1;$data[$mode]=$d;
    }
    require __DIR__.'/support/catalogue-policies-http.php';
    try{ownCataloguePolicyHttp($db,$fixture,$modes,$data,$servers,$allowedJobs,$allowedMedia);}
    catch(Throwable $error){
        cpCheck(ownOriginalDatabaseHash($db,$tables,$filter)===$before,'interrupted actual browser leaves complete protected original tables byte-exact');
        foreach($originalFiles as $file=>$hash)if(!is_file($file)||hash_file('sha256',$file)!==$hash)throw new RuntimeException('Interrupted browser changed original source/key/variant: '.$file,0,$error);
        cpCheck(true,'interrupted actual browser leaves original source/key/image-variant files byte-exact');throw $error;
    }
    cpCheck(ownOriginalDatabaseHash($db,$tables,$filter)===$before,'all earlier complete original tables and generated/invisible columns outside exact own allowed rows remain byte-identical');
    foreach($originalFiles as $file=>$hash)if(!is_file($file)||hash_file('sha256',$file)!==$hash)throw new RuntimeException('Original source/key/variant changed: '.$file);
    cpCheck(true,'all actual original source/key/image-variant files remain byte-exact');
    echo json_encode(['suite'=>'native-catalogue-policies','browser_diagnostic'=>getenv('DVM_CATALOGUE_POLICY_DIAGNOSTIC')?:null,'checks'=>$checks,'actual_apps'=>['native','integrated'],'original_tables'=>count($tables),'original_files'=>count($originalFiles),'original_bytes'=>'exact except explicitly selected own policy/order/schedule/article/media rows','permitted_page_ids'=>$mutablePages,'permitted_new_media_ids'=>$allowedMedia,'permitted_schedule_ids'=>$allowedJobs,'permitted_order_clubs'=>$clubs,'external_actions'=>0],JSON_THROW_ON_ERROR)."\n";
}finally{foreach($servers as $server){if(is_resource($server['process'])){proc_terminate($server['process']);proc_close($server['process']);}if(is_resource($server['log']))fclose($server['log']);}}
