<?php declare(strict_types=1);
require dirname(__DIR__).'/native/bootstrap.php';
use Kulturhus\Native\CmsTransfer\Package;
use Kulturhus\Native\CmsTransfer\Content;

$checks=0;
function transferPackageCheck(bool $condition,string $label):void{global $checks;if(!$condition)throw new RuntimeException('FAIL '.$label);++$checks;}
function transferPackageReject(callable $run,string $label):void{try{$run();}catch(InvalidArgumentException $e){transferPackageCheck(true,$label);return;}throw new RuntimeException('FAIL expected '.$label);}
$root=sys_get_temp_dir().'/dvm-cms-package-'.bin2hex(random_bytes(12));mkdir($root,0700);
transferPackageCheck(\Kulturhus\Native\CmsTransfer\Factory::privateRoot($root.'/native/media')===$root.'/native','actual native-only bootstrap can resolve private CMS storage');
$content=['format'=>Package::FORMAT,'version'=>1,'fields'=>[],'templates'=>[],'pages'=>[],'assets'=>[],'languages'=>[]];
$text="Eigene Datei ä🙂\n";file_put_contents($root.'/source.txt',$text);$sha=hash('sha256',$text);
$withAsset=$content;$withAsset['assets']=[['id'=>str_repeat('a',32),'kind'=>'file','sha256'=>$sha,'bytes'=>strlen($text),'name'=>'Original.txt']];
$written=Package::create($root.'/valid.zip',$withAsset,[$sha=>$root.'/source.txt']);$read=Package::read($root.'/valid.zip',$root.'/private');
transferPackageCheck($read['content']===$withAsset&&file_get_contents($read['blobs'][$sha])===$text,'native data and actual bytes roundtrip');
transferPackageCheck($read['sha256']===$written['sha256']&&$read['bytes']===$written['bytes'],'package exact original fingerprint');
transferPackageCheck((fileperms($read['owned_root'])&0777)===0700&&(fileperms($read['blobs'][$sha])&0777)===0600,'private staging and files have private permissions');
transferPackageCheck(file_get_contents($root.'/source.txt')===$text,'export leaves source bytes unchanged');
$again=Package::read($root.'/valid.zip',$root.'/private');transferPackageCheck($again['owned_root']!==$read['owned_root'],'two package checks never share staging ownership');Package::cleanup($again);
$blob=$read['blobs'][$sha];Package::cleanup($read);transferPackageCheck(!file_exists($blob)&&!is_dir($read['owned_root'])&&is_file($root.'/source.txt'),'cleanup affects only own temporary bytes');
transferPackageReject(fn()=>Package::create($root.'/valid.zip',$content,[]),'export never replaces an existing file');
$bad=$content;$bad['users']=[['secret'=>'never']];transferPackageReject(fn()=>Package::create($root.'/users.zip',$bad,[]),'unknown top-level exports rejected');
$bad=$content;$bad['pages']=array_fill(0,101,[]);transferPackageReject(fn()=>Package::create($root.'/pages.zip',$bad,[]),'bounded page set');
$bad=$content;$bad['fields']=array_fill(0,200,[]);$bad['templates']=[[]];transferPackageReject(fn()=>Package::create($root.'/models.zip',$bad,[]),'combined model budget');
transferPackageReject(fn()=>Package::create($root.'/orphan.zip',$content,[$sha=>$root.'/source.txt']),'unreferenced blob never packaged');
transferPackageReject(fn()=>Package::create($root.'/hash.zip',$withAsset,[str_repeat('b',64)=>$root.'/source.txt']),'changed source hash refused');
symlink($root.'/source.txt',$root.'/source-link');transferPackageReject(fn()=>Package::create($root.'/link.zip',$withAsset,[$sha=>$root.'/source-link']),'symlinked original rejected');
symlink($root.'/valid.zip',$root.'/zip-link');transferPackageReject(fn()=>Package::read($root.'/zip-link',$root.'/private'),'symlinked archive rejected');
transferPackageReject(fn()=>Package::read('file://'.$root.'/valid.zip',$root.'/private'),'wrapper input rejected');
$zipBytes=file_get_contents($root.'/valid.zip');$eocd=strrpos($zipBytes,"PK\x05\x06");
foreach(['count'=>[10,pack('v',103)],'split'=>[4,pack('v',1)],'zip64'=>[12,pack('V',0xffffffff)],'comment'=>[20,pack('v',1)],'count-mismatch'=>[8,pack('vv',2,2)]]as$label=>[$relative,$replacement]){$hostile=substr_replace($zipBytes,$replacement,$eocd+$relative,strlen($replacement));file_put_contents($root.'/eocd-'.$label.'.zip',$hostile);transferPackageReject(fn()=>Package::read($root.'/eocd-'.$label.'.zip',$root.'/private'),'central directory preflight '.$label);}
file_put_contents($root.'/trailing.zip',$zipBytes.'trailing');transferPackageReject(fn()=>Package::read($root.'/trailing.zip',$root.'/private'),'archive has no bytes beyond its declared end');
$comment="Own ZIP comment PK\x05\x06 partial";file_put_contents($root.'/comment.zip',substr_replace($zipBytes,pack('v',strlen($comment)),$eocd+20,2).$comment);$commented=Package::read($root.'/comment.zip',$root.'/private');transferPackageCheck($commented['content']===$withAsset,'bounded ZIP comment including a signature is parsed at its exact declared end');Package::cleanup($commented);

/** Construct hostile archives independently of the implementation's package writer. */
function transferHostile(string $path,array $content,array $extra=[],?array $manifest=null,?callable $configure=null):void
{
    $json=json_encode($content,JSON_THROW_ON_ERROR);$manifest??=['format'=>Package::FORMAT,'version'=>1,'entries'=>['content.json'=>['sha256'=>hash('sha256',$json),'bytes'=>strlen($json)]]];
    $zip=new ZipArchive();if($zip->open($path,ZipArchive::CREATE|ZipArchive::EXCL)!==true)throw new RuntimeException('fixture zip');$zip->addFromString('manifest.json',json_encode($manifest,JSON_THROW_ON_ERROR));$zip->addFromString('content.json',$json);
    foreach($extra as$name=>$value)$zip->addFromString($name,$value);if($configure)$configure($zip);$zip->close();
}
foreach(['../outside','/absolute','C:\\outside','blobs/../../outside','other.php','folder/','blobs/'.str_repeat('a',63)]as$i=>$name){$path=$root.'/path-'.$i.'.zip';transferHostile($path,$content,[$name=>'untrusted']);transferPackageReject(fn()=>Package::read($path,$root.'/private'),'untrusted archive path '.$i);}
transferPackageCheck(!is_file($root.'/outside'),'archive path did not create outside file');
transferHostile($root.'/manifest-extra.zip',$content,[],['format'=>Package::FORMAT,'version'=>1,'entries'=>['content.json'=>['sha256'=>str_repeat('a',64),'bytes'=>1]],'sql'=>'DROP TABLE']);transferPackageReject(fn()=>Package::read($root.'/manifest-extra.zip',$root.'/private'),'unknown manifest key');
$json=json_encode($content,JSON_THROW_ON_ERROR);transferHostile($root.'/wrong-hash.zip',$content,[],['format'=>Package::FORMAT,'version'=>1,'entries'=>['content.json'=>['sha256'=>str_repeat('a',64),'bytes'=>strlen($json)]]]);transferPackageReject(fn()=>Package::read($root.'/wrong-hash.zip',$root.'/private'),'JSON checksum changed');
transferHostile($root.'/unlisted.zip',$content,['blobs/'.$sha=>$text]);transferPackageReject(fn()=>Package::read($root.'/unlisted.zip',$root.'/private'),'unlisted valid-looking blob');
$entries=['content.json'=>['sha256'=>hash('sha256',json_encode($withAsset,JSON_THROW_ON_ERROR)),'bytes'=>strlen(json_encode($withAsset,JSON_THROW_ON_ERROR))],'blobs/'.$sha=>['sha256'=>$sha,'bytes'=>strlen($text)]];
transferHostile($root.'/changed-blob.zip',$withAsset,['blobs/'.$sha=>str_repeat('z',strlen($text))],['format'=>Package::FORMAT,'version'=>1,'entries'=>$entries]);transferPackageReject(fn()=>Package::read($root.'/changed-blob.zip',$root.'/private'),'blob bytes changed with exact length');
transferHostile($root.'/symlink.zip',$content,[],null,static fn(ZipArchive $zip)=>$zip->setExternalAttributesName('content.json',ZipArchive::OPSYS_UNIX,0120777<<16));transferPackageReject(fn()=>Package::read($root.'/symlink.zip',$root.'/private'),'Unix symlink entry');
transferHostile($root.'/encrypted.zip',$content,[],null,static fn(ZipArchive $zip)=>$zip->setEncryptionName('content.json',ZipArchive::EM_AES_256,'synthetic-package-test'));transferPackageReject(fn()=>Package::read($root.'/encrypted.zip',$root.'/private'),'encrypted entries refused');
$large=$content;$large['pages']=[['body'=>str_repeat('z',2*1024*1024)]];transferHostile($root.'/bomb.zip',$large);transferPackageReject(fn()=>Package::read($root.'/bomb.zip',$root.'/private'),'excessive decompression ratio refused before extraction');
$bad=$content;$bad['format']='processwire';transferHostile($root.'/legacy.zip',$bad);transferPackageReject(fn()=>Package::read($root.'/legacy.zip',$root.'/private'),'legacy data rejected');
transferPackageCheck(count(iterator_to_array(new FilesystemIterator($root.'/private',FilesystemIterator::SKIP_DOTS)))===0,'all failed checks clean their own staging');
$shared=[];for($i=0;$i<20;$i++)$shared[]=['key'=>'leaf_'.$i,'label'=>'Eigenes Feld '.$i,'type'=>'text'];$leafKeys=array_column($shared,'key');for($i=0;$i<20;$i++)$shared[]=['key'=>'middle_'.$i,'label'=>'Eigener Datensatz '.$i,'type'=>'repeater','fields'=>$leafKeys];$middleKeys=array_map(static fn($i)=>'middle_'.$i,range(0,19));for($i=0;$i<20;$i++)$shared[]=['key'=>'wide_'.$i,'label'=>'Zu breiter Datensatz '.$i,'type'=>'repeater','fields'=>$middleKeys];transferPackageReject(fn()=>Content::assertFieldBudget($shared),'shared declaration graph is bounded before expanded arrays exist');
transferPackageReject(fn()=>Package::decodeJson('['.implode(',',array_fill(0,20001,'[]')).']'),'JSON container allocation is bounded before decoding');
$collisionKeys=[str_repeat('a',52).'000000007fe8',str_repeat('a',52).'00000000e56f'];$collisionMap=Content::modelMap(['fields'=>array_fill_keys($collisionKeys,[]),'templates'=>array_fill_keys($collisionKeys,[])],'import_probe');transferPackageCheck(count(array_unique($collisionMap['fields']))===2&&count(array_unique($collisionMap['templates']))===2,'actual short-hash collision keys map to distinct fields and templates');
$oldSection=str_repeat('d',32);$newSection=str_repeat('e',32);$translation=['slug'=>'source','fields'=>['body_html'=>'<a href="/source">Main</a>','custom_fields'=>['own_rich'=>'<a href="/source">Rich</a>','own_text'=>'<a href="/source">Plain</a>'],'home_layout'=>['hero_html'=>'<a href="/source">Hero</a>'],'content_sections'=>[$oldSection=>['body_html'=>'<a href="/source">Section</a>']]]];$mapped=Content::mappedLanguage($translation,['fields'=>['own_rich'=>'copy_rich','own_text'=>'copy_text']],['source'=>'copy/source'],[$oldSection=>$newSection],['own_rich'=>['type'=>'richtext'],'own_text'=>['type'=>'text']]);transferPackageCheck(str_contains($mapped['fields']['body_html'],'/copy/source')&&str_contains($mapped['fields']['custom_fields']['copy_rich'],'/copy/source')&&str_contains($mapped['fields']['home_layout']['hero_html'],'/copy/source')&&str_contains($mapped['fields']['content_sections'][$newSection]['body_html'],'/copy/source'),'all allowed translated HTML surfaces remap internal addresses');transferPackageCheck($mapped['fields']['custom_fields']['copy_text']===$translation['fields']['custom_fields']['own_text'],'translated ordinary text resembling HTML remains exact');
echo 'OK native CMS transfer package: '.$checks." conditions\n";
