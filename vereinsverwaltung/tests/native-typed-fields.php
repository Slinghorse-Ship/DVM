<?php declare(strict_types=1);
require dirname(__DIR__).'/native/bootstrap.php';
require_once dirname(__DIR__).'/native/TestSupport.php';
use Kulturhus\Native\Cms\{Conflict,CustomFields,DataModel,FieldValues,Media,MediaMetadata,MediaReferences,PageCopies,Pages,ReferenceCatalog,Schema,WebsiteDocuments};
use Kulturhus\Native\Shared\{Access,Db};
use Kulturhus\Native\Web\{ContentModel};
use Kulturhus\Native\Web\Website\PublishedFields;
use Kulturhus\Native\TestSupport;
$checks=0;
function typedCheck(bool$ok,string$label):void{global$checks;if(!$ok)throw new RuntimeException('FAIL '.$label);$checks++;}
function typedReject(callable$run,string$class,string$label):void{try{$run();}catch(Throwable$error){typedCheck($error instanceof$class,$label.' ('.get_class($error).')');return;}throw new RuntimeException('FAIL expected '.$label);}
$db=TestSupport::db();$actors=TestSupport::seed($db);Schema::install($db);
$admin=new Access($db,$actors['admin']);$editor=new Access($db,$actors['editor']);$outside=new Access($db,$actors['outsider']);
$model=new DataModel($db,$admin);$pages=new Pages($db,$admin);$editorPages=new Pages($db,$editor);
$prefix='typed_'.bin2hex(random_bytes(4));$slugPrefix=str_replace('_','-',$prefix);$key=static fn($suffix)=>$prefix.'_'.$suffix;
$root=dirname(__DIR__,2).'/.local/native-typed-tests/'.$prefix;mkdir($root,0700,true);
$media=new Media($db,$admin,$root.'/media');$image=imagecreatetruecolor(12,8);imagepng($image,$root.'/image.png');unset($image);
$photo=$media->upload($root.'/image.png','public.png','Public image');$privatePhoto=$media->upload($root.'/image.png','private.png','Private image');
file_put_contents($root.'/file.pdf',"%PDF-1.7\n1 0 obj <<>> endobj\n%%EOF\n");
$documents=new WebsiteDocuments($db,$admin,$root.'/documents');$files=$documents->uploadMany([['path'=>$root.'/file.pdf','name'=>'public.pdf'],['path'=>$root.'/file.pdf','name'=>'private.pdf']]);
$target=$pages->save(['title'=>'Typed referenced page','slug'=>$slugPrefix.'-target']);$pages->publish($target['id'],1);
$foreign=(new Pages($db,$outside))->save(['title'=>'Foreign typed page','slug'=>$slugPrefix.'-target']);
$specs=[
    'name'=>['type'=>'text','required'=>true,'max'=>80],
    'tags'=>['type'=>'text','multiple'=>true,'max_items'=>4],
    'related'=>['type'=>'page-reference'],
    'photo'=>['type'=>'media-reference'],
    'file'=>['type'=>'file-reference'],
    'secret'=>['type'=>'password'],
    'time'=>['type'=>'datetime'],
    'pages'=>['type'=>'pagetable','max_items'=>4],
    'formatted'=>['type'=>'richtext'],
    'amount'=>['type'=>'decimal'],
];
foreach($specs as$suffix=>$spec)$model->saveField(['key'=>$key($suffix),'label'=>'Typed '.$suffix]+$spec);
$members=$model->saveField(['key'=>$key('members'),'label'=>'Typed members','type'=>'repeater','fields'=>array_map($key,['name','tags','related','photo','file','formatted','amount']),'max_items'=>5]);
$sessions=$model->saveField(['key'=>$key('sessions'),'label'=>'Typed sessions','type'=>'repeater','fields'=>[$key('name'),$key('members')],'max_items'=>5]);
$template=$model->saveTemplate(['key'=>$key('template'),'label'=>'Typed content','fields'=>array_map($key,['tags','members','sessions','pages','secret','time','file'])]);
typedCheck(isset($model->field($key('sessions'))['item_definitions'][$key('members')]['item_definitions'][$key('name')]),'three levels bind original typed definitions');
typedReject(fn()=>$model->saveField(['key'=>$key('members'),'label'=>'Cycle','type'=>'repeater','fields'=>[$key('sessions')]],1),InvalidArgumentException::class,'recursive cycle rejected atomically');
typedCheck($model->field($key('members'))['version']===1,'failed field change retains original definition');
$item=static function(string$name,array$extra=[])use($key):array{return ['version'=>0,'values'=>[$key('name')=>$name]+($extra['values']??[]),'editors'=>$extra['editors']??[],'public'=>$extra['public']??true];};
$values=[
    $key('tags')=>['eins','zwei','eins',''],
    $key('members')=>[
        $item('Public Alice',['values'=>[$key('related')=>$target['id'],$key('photo')=>$photo['id'],$key('file')=>$files[0]['id'],$key('formatted')=>'<p>Typed <strong>safe</strong><script>drop</script></p>',$key('amount')=>'12.5'],'editors'=>[$actors['admin']]]),
        $item('Secret Bob',['values'=>[$key('photo')=>$privatePhoto['id'],$key('file')=>$files[1]['id']],'public'=>false,'editors'=>[$actors['admin']]]),
    ],
    $key('sessions')=>[$item('Workshop',['values'=>[$key('members')=>[$item('Nested guest')]]])],
    $key('pages')=>[$target['id'],$target['id']],
    $key('secret')=>'own typed secret 2026',
    $key('time')=>'2026-10-16T19:30',
    $key('file')=>$files[0]['id'],
];
$page=$pages->save(['title'=>'Typed public original','slug'=>$slugPrefix.'-content','template'=>$template['key'],'custom_fields'=>$values]);
typedCheck($page['custom_fields'][$key('tags')]===['eins','zwei'],'multiple values normalize, deduplicate and preserve order');
typedCheck($page['custom_fields'][$key('pages')]===[$target['id']],'PageTable stores ordered own page references');
typedCheck(password_verify('own typed secret 2026',$page['custom_fields'][$key('secret')]),'password stored as one-way digest');
typedCheck(!str_contains(json_encode($page,JSON_THROW_ON_ERROR),'own typed secret 2026'),'plaintext absent from original snapshots');
typedReject(fn()=>$pages->save(['title'=>'Rejected digest injection','slug'=>$slugPrefix.'-digest','template'=>$template['key'],'custom_fields'=>[$key('secret')=>$page['custom_fields'][$key('secret')]]]),InvalidArgumentException::class,'new password input cannot inject a precomputed digest');
foreach($page['custom_fields'][$key('members')]as$member)typedCheck((bool)preg_match('/^[a-f0-9]{32}$/D',$member['id'])&&$member['version']===1,'server item identity and initial version');
typedCheck(count(FieldValues::references($page,'media'))===2&&FieldValues::references($page,'media',true)===[$photo['id']],'private and public reference projections differ');
typedCheck(FieldValues::references($page,'page')===[$target['id']],'recursive reference projection deduplicates PageTable and item references');
$remapped=FieldValues::remapPages($page,[$target['id']=>$foreign['id']]);
typedCheck($remapped['custom_fields'][$key('members')][0]['values'][$key('related')]===$foreign['id']&&$remapped['custom_fields'][$key('pages')]===[$foreign['id']],'copy remaps nested and PageTable references');
$page=$pages->publish($page['id'],1);
$public=$pages->publicPage($page['slug']);$originalJson=json_encode($public,JSON_THROW_ON_ERROR);
$rendered=(new PublishedFields($pages,$media))->render($public);
typedCheck(str_contains($rendered,'Public Alice')&&str_contains($rendered,'Nested guest')&&!str_contains($rendered,'Secret Bob'),'public renderer includes nested public items and excludes internal items');
typedCheck(!str_contains($rendered,$page['custom_fields'][$key('secret')])&&!str_contains($rendered,'Typed secret'),'public output excludes secret fields and digests');
typedCheck($media->publicVariant($photo['id'],'medium')!==null&&$media->publicVariant($privatePhoto['id'],'medium')===null,'internal item image never enters public media index');
typedCheck($documents->publicFile($files[0]['id'])!==null&&$documents->publicFile($files[1]['id'])===null,'only public original PDF fields authorize download');
$publicDto=FieldValues::publicPayload($public);$publicDtoJson=json_encode($publicDto,JSON_THROW_ON_ERROR);
typedCheck(!str_contains($publicDtoJson,'Secret Bob')&&!str_contains($publicDtoJson,$page['custom_fields'][$key('secret')])&&!str_contains($publicDtoJson,'editors'),'public DTO removes private values, secrets and editorial item permissions');
typedCheck($pages->publicSearch('Public Alice')['total']===1&&$pages->publicSearch('Secret Bob')['total']===0,'public search sees only published visible repeatable text');
$catalog=(new ReferenceCatalog($db,$admin))->listing('file','public.pdf',1,$files[0]['id']);
typedCheck($catalog['total']===1&&$catalog['rows'][0]['id']===$files[0]['id'],'PDF reference picker uses real bounded metadata search');
$queries=[
    ['field'=>'custom.'.$key('tags'),'op'=>'eq','value'=>'eins'],
    ['field'=>'custom.'.$key('members').'.'.$key('name'),'op'=>'contains','value'=>'Alice'],
    ['field'=>'custom.'.$key('sessions').'.'.$key('members').'.'.$key('name'),'op'=>'eq','value'=>'Nested guest'],
    ['field'=>'custom.'.$key('pages'),'op'=>'in','value'=>[$target['id']]],
];
foreach($queries as$filter)typedCheck($pages->count([$filter])===1,'actual typed MySQL collection selector '.$filter['field']);
typedReject(fn()=>$pages->search([['field'=>'custom.'.$key('secret'),'op'=>'eq','value'=>'secret']]),InvalidArgumentException::class,'password lookup is forbidden');
typedReject(fn()=>$pages->search([],25,0,[['field'=>'custom.'.$key('tags')]]),InvalidArgumentException::class,'ambiguous collection sorting is rejected');
$form=new ContentModel($db,$admin,'typed-csrf');$html=$form->fields($template['key'],$page['custom_fields']);
typedCheck(str_contains($html,'data-typed-collection')&&str_contains($html,'data-collection-up')&&str_contains($html,'[values]'),'native nested controls and keyboard reorder are rendered');
typedCheck(!str_contains($html,$page['custom_fields'][$key('secret')]),'password digest never enters form markup');
if(getenv('DVM_TYPED_FIELDS_BROWSER')==='1'){
    $browserPage=$pages->save(['title'=>'Own typed browser draft','slug'=>$slugPrefix.'-browser','template'=>$template['key'],'custom_fields'=>$values]);
    $fixtureFile=$root.'/browser-fixture.json';file_put_contents($fixtureFile,json_encode(['id'=>$browserPage['id']],JSON_THROW_ON_ERROR));chmod($fixtureFile,0600);
    $socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$errstr);if(!$socket)throw new RuntimeException('Own typed browser port unavailable.');$address=stream_socket_get_name($socket,false);fclose($socket);$port=(int)substr(strrchr($address,':'),1);
    putenv('KH_TYPED_BROWSER_FIXTURE='.$fixtureFile);
    $server=proc_open([...TestSupport::phpCommand(),'-S','127.0.0.1:'.$port,__DIR__.'/support/typed-fields-http.php'],[0=>['pipe','r'],1=>['file',$root.'/http.log','a'],2=>['file',$root.'/http.log','a']],$serverPipes);if(!is_resource($server))throw new RuntimeException('Own typed browser server unavailable.');fclose($serverPipes[0]);
    try{
        $ready=false;for($attempt=0;$attempt<100;$attempt++){$connection=@stream_socket_client('tcp://127.0.0.1:'.$port,$errno,$errstr,.1);if($connection){fclose($connection);$ready=true;break;}usleep(10000);}if(!$ready)throw new RuntimeException('Own typed browser server did not start.');
        $process=proc_open(['node',__DIR__.'/native-typed-fields-browser.mjs'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);if(!is_resource($process))throw new RuntimeException('Own typed browser unavailable.');fwrite($pipes[0],json_encode(['url'=>'http://127.0.0.1:'.$port,'prefix'=>$prefix],JSON_THROW_ON_ERROR));fclose($pipes[0]);$output=stream_get_contents($pipes[1]);$errors=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$status=proc_close($process);echo$output;if($status!==0)throw new RuntimeException('Typed browser failed: '.$errors);
        typedCheck($pages->get($browserPage['id'])['published_revision']===null,'actual browser edits never publish a draft');
        typedCheck(json_encode($pages->publicPage($page['slug']),JSON_THROW_ON_ERROR)===$originalJson,'all actual browser writes preserve public original');
    }finally{proc_terminate($server);proc_close($server);putenv('KH_TYPED_BROWSER_FIXTURE');}
}
$unchanged=$page;$unchanged['custom_fields'][$key('secret')]='';
$unchanged=$editorPages->save($unchanged,$page['version']);
typedCheck($unchanged['custom_fields'][$key('members')]===$page['custom_fields'][$key('members')],'editor can save unrelated changes while protected original items retain bytes');
typedCheck($unchanged['custom_fields'][$key('secret')]===$page['custom_fields'][$key('secret')],'empty edit control preserves original password digest');
$privateDto=FieldValues::privatePayload($unchanged,$editor);
typedCheck(!array_key_exists($key('secret'),$privateDto['custom_fields'])&&!array_key_exists('editors',$privateDto['custom_fields'][$key('members')][0])&&count($privateDto['custom_fields'][$key('members')])===2,'editorial DTO retains private original items and counters while hiding digests and manager-only ACLs');
$unchanged=$editorPages->save($privateDto,$unchanged['version']);
typedCheck($unchanged['custom_fields'][$key('members')]===$page['custom_fields'][$key('members')]&&$unchanged['custom_fields'][$key('secret')]===$page['custom_fields'][$key('secret')],'editorial API DTO round-trip preserves original ACLs and omitted secrets');
$secretPage=$pages->save(['title'=>'Own password lifecycle','slug'=>$slugPrefix.'-secret','template'=>$template['key'],'custom_fields'=>[$key('secret')=>'own replacement secret']]);
$secretPage['custom_fields'][$key('secret')]=['value'=>'','clear'=>'1'];$secretPage=$pages->save($secretPage,$secretPage['version']);
typedCheck($secretPage['custom_fields'][$key('secret')]===null,'explicit optional password removal works without exposing saved digest');
$bad=$unchanged;$bad['custom_fields'][$key('members')][0]['values'][$key('name')]='Unauthorized';
typedReject(fn()=>$editorPages->save($bad,$unchanged['version']),DomainException::class,'item ACL rejects forged value change');
$bad=$unchanged;array_shift($bad['custom_fields'][$key('members')]);
typedReject(fn()=>$editorPages->save($bad,$unchanged['version']),DomainException::class,'item ACL rejects omitted protected item');
$bad=$unchanged;$bad['custom_fields'][$key('members')]=array_reverse($bad['custom_fields'][$key('members')]);
typedReject(fn()=>$editorPages->save($bad,$unchanged['version']),DomainException::class,'item ACL rejects protected item reordering');
$bad=$unchanged;$bad['custom_fields'][$key('members')][0]['version']=0;
typedReject(fn()=>$pages->save($bad,$unchanged['version']),Conflict::class,'stale item version rejected even for manager');
$bad=$unchanged;$bad['custom_fields'][$key('sessions')][0]['values'][$key('members')][0]['values'][$key('related')]=$foreign['id'];
typedReject(fn()=>$pages->save($bad,$unchanged['version']),InvalidArgumentException::class,'nested foreign-club page reference rejected');
$changed=$unchanged;$changed['custom_fields'][$key('members')][0]['values'][$key('name')]='Changed Alice';
$changed=$pages->save($changed,$unchanged['version']);
typedCheck($changed['custom_fields'][$key('members')][0]['version']===2&&$changed['custom_fields'][$key('members')][1]['version']===1,'only changed item advances its original counter');
typedCheck(json_encode($pages->publicPage($page['slug']),JSON_THROW_ON_ERROR)===$originalJson,'draft changes preserve exact approved public original');
$nameDefinition=$model->field($key('name'));$model->saveField(['key'=>$key('name'),'label'=>'Changed numeric model','type'=>'integer'],1);
$restored=$pages->restore($page['id'],1,$changed['version']);
typedCheck($restored['field_definitions'][$key('members')]['item_definitions'][$key('name')]['type']==='text'&&$restored['custom_fields'][$key('members')][0]['values'][$key('name')]==='Public Alice','historical restore retains exact historical child definitions and original types');
typedCheck($restored['custom_fields'][$key('members')][0]['version']===3,'restored item advances current counter without overwriting history');
typedReject(fn()=>$pages->publish($page['id'],$restored['version']),Conflict::class,'historical model must be reviewed before new publication');
typedCheck((new MediaReferences($db,$admin))->inspect($privatePhoto['id'])['referenced'],'global removal guard sees retained private item references');
typedCheck($model->field($key('name'))['type']==='integer','historical page restore does not modify current model');
if(getenv('DVM_TYPED_FIELDS_APP_BROWSER')==='1'){require __DIR__.'/support/typed-fields-app-fixture.php';ownTypedFieldsAppBrowser();}
echo json_encode(['suite'=>'native-typed-fields','checks'=>$checks,'status'=>'passed','driver'=>'mysql'],JSON_THROW_ON_ERROR).PHP_EOL;
