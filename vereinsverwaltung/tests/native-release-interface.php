<?php declare(strict_types=1);
ini_set('zend.exception_ignore_args','1');
require dirname(__DIR__).'/native/bootstrap.php';require dirname(__DIR__).'/src/bootstrap.php';
require __DIR__.'/support/editorial-browser-fixture.php';require __DIR__.'/support/original-database-hash.php';
use Kulturhus\Native\{TestSupport,Installer\SchemaRegistry,Language\Catalog,Language\ManagerMessages,Shared\Db,Integration\ManagerBridge};
$cfg=TestSupport::config();$db=TestSupport::db();
if(($cfg['owner']??'')!=='isolated-native-ci'||($cfg['label']??'')!=='native-release-interface'||$db->query('SHOW TABLES')->fetchAll())throw new RuntimeException('Fresh owned release interface fixture required.');
SchemaRegistry::install($db);$f=ownEditorialBrowserFixture($db);$registry=new Kulturhus\Verein\Registry($db,$f['accounts']['admin']['actor']);
Db::run($db,"INSERT INTO actors(id,club_id,role,is_superuser) VALUES(?,?,'member',1)",['editorial-admin-de',$f['club']]);
$bridge=new ManagerBridge($db,'editorial-admin-de','native',static fn()=>null);$bridge->access();$auth=Kulturhus\Native\Auth\Runtime::service($db);$invite=$auth->createInvitation($f['admin'],$bridge->nativeActorId(),'workflow-admin-de@example.invalid');$auth->acceptInvitation($invite['token'],'Own isolated workflow password 2026!');$f['accounts']['admin_de']=['role'=>'admin_de','actor'=>'editorial-admin-de','token'=>$auth->passwordLogin('workflow-admin-de@example.invalid','Own isolated workflow password 2026!','own-workflow-admin-de')['session_token']];
foreach($f['accounts']as$role=>$account){$contact=$registry->saveContact(null,0,'person','Own retained release '.$role,'release-'.$role.'@example.invalid','Own exact original address '.$role);Db::run($db,'UPDATE actors SET contact_id=? WHERE id=?',[$contact,$account['actor']]);$access=(new ManagerBridge($db,$account['actor'],'native',static fn()=>null))->access();(new Catalog($db,$access))->select($role==='admin_de'?'de':'en');}
$languageSettings=new Kulturhus\Native\Language\Settings($db,$f['admin']);$languageSettings->save(['source'=>'de','languages'=>[['code'=>'de','label'=>'Deutsch','enabled'=>true,'fallback'=>null,'messages'=>[]],['code'=>'en','label'=>'English','enabled'=>false,'fallback'=>'de','messages'=>[Kulturhus\Native\Language\ManagerMessages::key('Seiten')=>'Pages <b data-own-ui-translation>literal</b> & entries',Kulturhus\Native\Language\ContentMessages::key('Termine und Veranstaltungsarchiv')=>'Events <b data-own-ui-translation>literal</b> & archive']]]],0);
Kulturhus\Verein\Database::ensureContributionRates($db);
$pages=new Kulturhus\Native\Cms\Pages($db,$f['admin']);$ownPage=$pages->save(['title'=>'Own retained Titel Suchen ä🙂','slug'=>'own-release-source','body'=>'0 Own retained original Text <literal> & ä🙂','template'=>'standard','nav_visible'=>true]);$f['data']=['original_page'=>$ownPage['id'],'original_title'=>$ownPage['title']];
$de=ManagerMessages::messages('de');$en=ManagerMessages::messages('en');$normalize=static fn(string$s):string=>trim(preg_replace('/\s+/u',' ',$s));$labels=[];
foreach($de as$key=>$source)if(is_string($source)&&is_string($en[$key]??null)&&$source!==$en[$key]&&!str_contains($source,'{'))$labels[$normalize($source)]=$en[$key];
foreach(['Filter','Organisation']as$sharedEnglish)unset($labels[$sharedEnglish]);
$f['data']['labels']=$labels;
$tables=array_values(array_diff($db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN),['native_auth_sessions']));$written=['khn_press_items','khn_press_revisions','native_communication_save_requests'];foreach($written as$table)if((int)$db->query('SELECT COUNT(*) FROM `'.$table.'`')->fetchColumn()!==0)throw new RuntimeException('No earlier press write may be excluded.');$protected=array_values(array_diff($tables,$written));$before=ownOriginalDatabaseHash($db,$protected);$keys=[];foreach(['auth','private']as$name)$keys[$name]=hash_file('sha256',$f['root'].'/'.$name.'.key');
$result=['suite'=>'native-release-interface','routes'=>[],'untranslated'=>[],'external_actions'=>0];$server=null;
try{
 $server=ownEditorialBrowserStart($f,$f['data']);
 if(getenv('DVM_RELEASE_INTERFACE_BROWSER_ONLY')!=='1')foreach(['admin','member','editor']as$role){
  $jar=$f['root'].'/release-'.$role.'.cookies';file_put_contents($jar,'');chmod($jar,0600);$curl=curl_init();curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_TIMEOUT=>60,CURLOPT_COOKIEFILE=>$jar,CURLOPT_COOKIEJAR=>$jar]);
  $request=static function(string$path)use($curl,$f):array{curl_setopt($curl,CURLOPT_URL,$f['origin'].$path);$body=curl_exec($curl);if(!is_string($body))throw new RuntimeException('Own release HTTP failed.');return[(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE),$body];};
  try{
   [$status]=$request('/own-test-enter?role='.$role);if($status!==303)throw new RuntimeException('Owned release session unavailable.');
   [$status,$html]=$request('/vereinsverwaltung/?view=dashboard');if($status!==200)throw new RuntimeException('Owned release dashboard unavailable.');
   $dom=new DOMDocument();@$dom->loadHTML('<?xml encoding="UTF-8">'.$html);$xp=new DOMXPath($dom);$routes=[];
   foreach($xp->query('//*[@id="navigation"]//a[@href]')as$link){$href=html_entity_decode($link->getAttribute('href'),ENT_QUOTES|ENT_HTML5,'UTF-8');if(str_starts_with($href,'?'))$href='/vereinsverwaltung/'.$href;if(str_starts_with($href,'/vereinsverwaltung/?'))$routes[$href]=true;}
   if(count($routes)<($role==='admin'?90:10))throw new RuntimeException('Complete current release menu required.');
   foreach(array_keys($routes)as$route){
    [$status,$html]=$request($route);if($status!==200||preg_match('/Fatal error:|SQLSTATE\[|Stack trace:/',$html))throw new RuntimeException('Actual release route failed: '.$role.' '.$route.' HTTP '.$status.' '.mb_substr(strip_tags($html),0,700));
    $dom=new DOMDocument();@$dom->loadHTML('<?xml encoding="UTF-8">'.$html);$xp=new DOMXPath($dom);if($xp->query('/html[@lang="en"]')->length!==1)throw new RuntimeException('Personal English language missing.');
    if($xp->query('//main')->length!==1||$xp->query('//main//*[self::h1 or self::h2 or self::button or self::label]')->length<1)throw new RuntimeException('Complete main interface required.');
    if($xp->query('//*[@data-own-ui-translation]')->length!==0)throw new RuntimeException('Personal interface text became HTML markup.');
    $row=['role'=>$role,'route'=>$route,'headings'=>[]];foreach($xp->query('//h1')as$heading)$row['headings'][]=$normalize($heading->textContent);
    foreach($xp->query('//main//*[self::h1 or self::h2 or self::h3 or self::button or self::summary or self::th or self::legend or self::option or self::a or self::label]')as$node){
     $source=$normalize($node->textContent);if($node->nodeName==='label'){$source='';foreach($node->childNodes as$child)if($child->nodeType===XML_TEXT_NODE)$source.=$child->textContent;$source=$normalize($source);}
     if($source==='Tag'&&str_contains($route,'tab=cache'))continue;$originalValue=false;foreach($xp->query('descendant-or-self::*[@data-original-content]',$node)as$originalNode)if($normalize($originalNode->textContent)===$source)$originalValue=true;if($originalValue)continue;
     if(isset($labels[$source]))$result['untranslated'][]=['role'=>$role,'route'=>$route,'element'=>$node->nodeName,'source'=>$source,'expected'=>$labels[$source],'href'=>$node->getAttribute('href'),'markup'=>$dom->saveHTML($node)];
    }
    foreach($xp->query('//main//*[@aria-label or @placeholder or @title]')as$node)foreach(['aria-label','placeholder','title']as$attribute){$source=$normalize($node->getAttribute($attribute));if(isset($labels[$source]))$result['untranslated'][]=['role'=>$role,'route'=>$route,'element'=>$node->nodeName,'attribute'=>$attribute,'source'=>$source,'expected'=>$labels[$source]];}
    $result['routes'][]=$row;
   }
   echo json_encode(['release_menu_role'=>$role,'routes'=>count($routes),'untranslated'=>count($result['untranslated'])],JSON_THROW_ON_ERROR).PHP_EOL;
  }finally{curl_close($curl);}
 }
 if($result['untranslated'])throw new RuntimeException('Untranslated registered interface labels remain.');
 if(getenv('DVM_RELEASE_INTERFACE_BROWSER')==='1'){
  $process=proc_open(['node',__DIR__.'/release-interface-browser.mjs'],[0=>['pipe','r'],1=>STDOUT,2=>STDERR],$pipes);if(!is_resource($process))throw new RuntimeException('Owned release browser unavailable.');fwrite($pipes[0],json_encode($server['fixture'],JSON_THROW_ON_ERROR));fclose($pipes[0]);if(proc_close($process)!==0)throw new RuntimeException('Actual release interface browser failed.');
  $result['browser']=json_decode(file_get_contents($f['root'].'/release-interface-results.json'),true,32,JSON_THROW_ON_ERROR);
 }
}finally{
 if($server){proc_terminate($server['process']);proc_close($server['process']);fclose($server['log']);}
 $evidence=rtrim(getenv('VEREIN_TEST_RESULTS')?:sys_get_temp_dir(),DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'dvm-release-interface-'.gmdate('Ymd-His').'-'.getmypid();if(!mkdir($evidence,0700,true))throw new RuntimeException('Owned release evidence unavailable.');file_put_contents($evidence.'/interface-report.json',json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR).PHP_EOL);foreach(glob($f['root'].'/release-interface-*')as$file)if(is_file($file))copy($file,$evidence.'/'.basename($file));copy($f['root'].'/http.log',$evidence.'/http.log');echo json_encode(['release_interface_evidence'=>$evidence],JSON_THROW_ON_ERROR).PHP_EOL;
 if(ownOriginalDatabaseHash($db,$protected)!==$before)throw new RuntimeException('Release reads changed an original business or preference byte.');foreach($keys as$name=>$hash)if(hash_file('sha256',$f['root'].'/'.$name.'.key')!==$hash)throw new RuntimeException('Original release key changed.');
 $expectedWrites=$result['browser']['saved_drafts']??0;foreach($written as$table)if((int)$db->query('SELECT COUNT(*) FROM `'.$table.'`')->fetchColumn()!==$expectedWrites)throw new RuntimeException('Only one intended original press version and replay receipt may be added.');
 if($expectedWrites){$saved=$db->query('SELECT payload,status,version FROM khn_press_items')->fetch(PDO::FETCH_ASSOC);$payload=json_decode($saved['payload'],true,64,JSON_THROW_ON_ERROR);if($saved['status']!=='draft'||(int)$saved['version']!==1||$payload['title']!=='Titel Suchen <literal> & ä🙂'||$payload['body']!=='0 Original Text Suchen ä🙂')throw new RuntimeException('Actual recovered press original changed human input.');}
}
echo json_encode(['suite'=>'native-release-interface','routes'=>count($result['routes']),'untranslated'=>count($result['untranslated']),'original_tables'=>count($tables),'untouched_tables'=>count($protected),'new_drafts'=>$result['browser']['saved_drafts']??0,'external_actions'=>0],JSON_THROW_ON_ERROR)."\n";
if($result['untranslated'])throw new RuntimeException('Untranslated registered interface labels remain.');
