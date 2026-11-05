<?php declare(strict_types=1);
require dirname(__DIR__).'/native/bootstrap.php';
require dirname(__DIR__,2).'/distribution/src/PackageStore.php';
require __DIR__.'/support/original-database-hash.php';

use Kulturhus\Native\Installer\{RestoreAccounts,UpgradeFiles};

// BEGIN PURE ORIGINAL ORACLE FIXTURE
/** PDO's row/metadata contract without opening a connection. */
final class RestoreOracleRows extends PDOStatement
{
    private int $index=0;
    public function __construct(private array $rows){}
    public function execute(?array $params=null):bool{return true;}
    public function fetchAll(int $mode=PDO::FETCH_DEFAULT,mixed ...$args):array{return$this->rows;}
    public function fetch(int $mode=PDO::FETCH_DEFAULT,int $orientation=PDO::FETCH_ORI_NEXT,int $offset=0):mixed{return$this->rows[$this->index++]??false;}
    public function closeCursor():bool{return true;}
}
final class RestoreOraclePdo extends PDO
{
    public bool $buffered=true;
    public array $columns=[];
    public array $primary=[];
    public array $rows=[['id'=>7,'original'=>"Exact\0\xff ä🙂"]];
    public function __construct(int $markerColumns=0)
    {
        $this->columns=[['TABLE_NAME'=>'own_original','COLUMN_NAME'=>'id','ORDINAL_POSITION'=>1,'COLUMN_TYPE'=>'int','IS_NULLABLE'=>'NO','COLUMN_DEFAULT'=>null,'EXTRA'=>'','GENERATION_EXPRESSION'=>'','CHARACTER_SET_NAME'=>null,'COLLATION_NAME'=>null],['TABLE_NAME'=>'own_original','COLUMN_NAME'=>'original','ORDINAL_POSITION'=>2,'COLUMN_TYPE'=>'varbinary(64)','IS_NULLABLE'=>'NO','COLUMN_DEFAULT'=>null,'EXTRA'=>'INVISIBLE','GENERATION_EXPRESSION'=>'','CHARACTER_SET_NAME'=>null,'COLLATION_NAME'=>null]];
        $this->primary=[['TABLE_NAME'=>'own_original','COLUMN_NAME'=>'id','ORDINAL_POSITION'=>1]];
        if($markerColumns){foreach(array_slice(['id','intent','security_review_at','credentials_json'],0,$markerColumns)as$i=>$field)$this->columns[]=['TABLE_NAME'=>'native_restore_operation','COLUMN_NAME'=>$field,'ORDINAL_POSITION'=>$i+1,'COLUMN_TYPE'=>'own fixed control column','IS_NULLABLE'=>'YES','COLUMN_DEFAULT'=>null,'EXTRA'=>'','GENERATION_EXPRESSION'=>'','CHARACTER_SET_NAME'=>null,'COLLATION_NAME'=>null];$this->primary[]=['TABLE_NAME'=>'native_restore_operation','COLUMN_NAME'=>'id','ORDINAL_POSITION'=>1];}
        usort($this->columns,static fn(array $a,array $b):int=>[$a['TABLE_NAME'],$a['ORDINAL_POSITION']]<=>[$b['TABLE_NAME'],$b['ORDINAL_POSITION']]);usort($this->primary,static fn(array $a,array $b):int=>[$a['TABLE_NAME'],$a['ORDINAL_POSITION']]<=>[$b['TABLE_NAME'],$b['ORDINAL_POSITION']]);
    }
    public function query(string $query,?int $fetchMode=null,mixed ...$fetchModeArgs):PDOStatement|false
    {if(str_contains($query,'information_schema.COLUMNS'))return new RestoreOracleRows($this->columns);if(str_contains($query,'KEY_COLUMN_USAGE'))return new RestoreOracleRows($this->primary);throw new RuntimeException('Unexpected pure metadata query.');}
    public function prepare(string $query,array $options=[]):PDOStatement|false
    {if(!str_contains($query,' FROM `own_original` ORDER BY `id`'))throw new RuntimeException('Unexpected pure original query.');return new RestoreOracleRows($this->rows);}
    public function getAttribute(int $attribute):mixed{return$this->buffered;}
    public function setAttribute(int $attribute,mixed $value):bool{$this->buffered=(bool)$value;return true;}
}
// END PURE ORIGINAL ORACLE FIXTURE

$service=new RestoreAccounts(dirname(__DIR__,2));$count=0;
$work=(getenv('KH_RESTORE_TEST_TMP')?:sys_get_temp_dir()).'/kh-restore-account-pure-'.bin2hex(random_bytes(8));
mkdir($work,0700);mkdir($work.'/private',0700);mkdir($work.'/web',0700);
$call=static function(string $name,mixed ...$args)use($service):mixed{return (new ReflectionMethod($service,$name))->invoke($service,...$args);};
$check=static function(bool $ok,string $label)use(&$count):void{if(!$ok)throw new RuntimeException($label);++$count;echo 'PASS '.$label."\n";};
$reject=static function(callable $operation,string $label)use($check):void{try{$operation();}catch(DomainException|RuntimeException){$check(true,$label);return;}throw new RuntimeException($label);};
$remove=static function(string $path)use(&$remove):void{if(is_link($path)||is_file($path)){unlink($path);return;}foreach(new FilesystemIterator($path)as$file)$remove($file->getPathname());rmdir($path);};
try{
    $input=['account_id'=>str_repeat('1',32),'original_alias'=>'Original.alias-ä','identity_reviewed'=>true,'account_version'=>7,'request_key'=>str_repeat('2',32)];
    $check($call('input',$input)===$input,'exact original alias and closed explicit identity input preserved');
    foreach(['identity_reviewed'=>false,'account_version'=>'7','original_alias'=>"Original\nAlias",'account_id'=>str_repeat('A',32),'request_key'=>str_repeat('2',31)]as$key=>$value)$reject(fn()=>$call('input',array_replace($input,[$key=>$value])),'reject malformed '.$key);
    $reject(fn()=>$call('input',$input+['codes'=>[]]),'reject additional capability input');
    $reject(fn()=>$call('input',array_diff_key($input,['identity_reviewed'=>1])),'identity confirmation cannot be omitted');
    $reject(fn()=>$call('input',array_replace($input,['original_alias'=>"\xff"])),'invalid UTF8 original alias refused');
    $codes=[];for($i=0;$i<10;$i++)$codes[]=implode('-',str_split(str_pad(dechex($i+1),20,'0',STR_PAD_LEFT),5));
    $hashes=$call('hashes',$codes);$check(count(array_unique($hashes))===10&&$hashes[0]===hash('sha256',str_replace('-','',$codes[0])),'ten recovery hashes match existing authentication representation');
    $reject(fn()=>$call('hashes',array_fill(0,10,$codes[0])),'duplicate codes refused');
    $reject(fn()=>$call('hashes',array_slice($codes,1)),'incomplete code set refused');
    $bad=$codes;$bad[0]=strtoupper('abcde-12345-12345-12345');$reject(fn()=>$call('hashes',$bad),'noncanonical code spelling refused');
    $permit=['root'=>$work,'intent'=>str_repeat('3',48),'database'=>'own-fixture','server'=>'own-server'];
    $receipt=['format'=>1,'intent'=>$permit['intent'],'account_id'=>$input['account_id'],'request_key'=>$input['request_key'],'version'=>7,'alias'=>$input['original_alias'],'source'=>str_repeat('4',64),'issued_at'=>1700000000,'state'=>'prepared','codes'=>$codes];
    $key=bin2hex(random_bytes(32));$context=$call('context',$permit,$input['account_id']);$box=$call('box',$key);
    $path=$work.'/private/receipt.json';$write=static function(array $r)use($path,$box,$context):void{UpgradeFiles::atomic($path,json_encode(['cipher'=>$box->seal($context,$r)],JSON_THROW_ON_ERROR));};
    $write($receipt);$check($call('receipt',$path,$permit,$input['account_id'],$key)===$receipt,'private encrypted receipt roundtrips exact original codes');
    $encrypted=(string)file_get_contents($path);$check(!str_contains($encrypted,$codes[0])&&!str_contains($encrypted,$input['original_alias']),'receipt reveals no plaintext alias or code');
    $reject(fn()=>$call('receipt',$path,$permit,$input['account_id'],bin2hex(random_bytes(32))),'another backup key cannot open handoff receipt');
    foreach(['root'=>$work.'-other','intent'=>str_repeat('5',48),'database'=>'foreign','server'=>'foreign']as$keyName=>$value)$reject(fn()=>$call('receipt',$path,array_replace($permit,[$keyName=>$value]),$input['account_id'],$key),'encrypted receipt bound to '.$keyName);
    $reject(fn()=>$call('receipt',$path,$permit,str_repeat('6',32),$key),'encrypted receipt bound to exact account');
    $write(array_replace($receipt,['codes'=>array_fill(0,10,$codes[0])]));$reject(fn()=>$call('receipt',$path,$permit,$input['account_id'],$key),'encrypted duplicate set refused');
    $write(array_replace($receipt,['state'=>'unknown']));$reject(fn()=>$call('receipt',$path,$permit,$input['account_id'],$key),'unknown encrypted receipt phase refused');
    UpgradeFiles::atomic($path,str_repeat('x',32769));$reject(fn()=>$call('receipt',$path,$permit,$input['account_id'],$key),'receipt bounded before decode');
    $write($receipt);$tampered=json_decode(file_get_contents($path),true,16,JSON_THROW_ON_ERROR);$tampered['cipher'][20]=$tampered['cipher'][20]==='a'?'b':'a';UpgradeFiles::atomic($path,json_encode($tampered,JSON_THROW_ON_ERROR));$reject(fn()=>$call('receipt',$path,$permit,$input['account_id'],$key),'modified encrypted receipt refused');
    $text=$call('text',$receipt);$output=$work.'/private/output.txt';
    $check(!$call('existingOutput',$output,$text),'absent output permits exact receipt recovery');
    UpgradeFiles::atomic($output,$text);$check($call('existingOutput',$output,$text)&&(fileperms($output)&0777)===0600,'exact output private0600 accepted');
    $reject(fn()=>$call('existingOutput',$output,$text.'changed'),'changed output never overwritten');
    chmod($output,0644);clearstatcache(true,$output);$reject(fn()=>$call('existingOutput',$output,$text),'publicly readable output refused');
    chmod($output,0600);symlink($output,$work.'/private/output-link');$reject(fn()=>$call('existingOutput',$work.'/private/output-link',$text),'symlink output refused');
    $paths=$call('paths',$work,$permit['intent'],$input['account_id'],null,false);$check(!file_exists($work.'/private/restore-account-codes')&&str_ends_with($paths['receipt'],'/'.$input['account_id'].'.json'),'inspection creates no handoff directories');
    $paths=$call('paths',$work,$permit['intent'],$input['account_id'],$input['request_key'],true);$check((fileperms(dirname($paths['output']))&0777)===0700&&str_starts_with($paths['output'],$work.'/private/restore-account-codes/'),'handoff output always beneath fixed private0700 tree');
    $call('webRoot',$work,$work.'/web');$check(true,'real sibling webroot accepted');
    foreach([$work,$work.'/private',$work.'/web/..',$work.'/missing','relative/web']as$i=>$web)$reject(fn()=>$call('webRoot',$work,$web),'unsafe actual webroot '.$i.' refused');
    symlink($work.'/web',$work.'/web-link');$reject(fn()=>$call('webRoot',$work,$work.'/web-link'),'final webroot symlink refused');
    symlink($work,$work.'/ancestor-link');$reject(fn()=>$call('webRoot',$work,$work.'/ancestor-link/web'),'ancestor webroot symlink refused');
    $row=['id'=>$input['account_id'],'version'=>7,'password_hash'=>'original','totp_secret'=>'originalcipher','totp_counter'=>5,'_binding'=>null];
    $check($call('stamp',$row,$key)===$call('stamp',array_replace($row,['totp_counter'=>9]),$key),'normal TOTP counter progress keeps exact handoff identity stamp');
    $check($call('stamp',$row,$key)!==$call('stamp',array_replace($row,['password_hash'=>'changed']),$key),'permanent credential change invalidates handoff identity stamp');
    // Golden values come from the unmodified HEAD47 helper with this exact fixture.
    $vectors=[0=>'ff2274b6d61c1dae45c519c250b2609606f56f2f454f36dc8b24463d25006859',2=>'d728f7db4fa27b4e8666d25e10b1267a58957e5aa8cea4d6b8193688bfb492a1',4=>'a7f9a1676fcad9611406ad8fad8c687ed2b0d971579007cf6edd4b1b3cf72828'];
    foreach($vectors as$markerColumns=>$expected){$fixture=new RestoreOraclePdo($markerColumns);$check(ownOriginalDatabaseHash($fixture,['own_original'])===$expected,'default original hash retains exact HEAD47 vector with '.$markerColumns.' control columns');$check(ownOriginalDatabaseHash($fixture,['own_original'],null,true)===$vectors[0],'explicit selected metadata retains exact original rows and schema with '.$markerColumns.' control columns');$check($fixture->buffered,'original hash restores PDO streaming mode '.$markerColumns);}
    $changed=new RestoreOraclePdo(4);$changed->rows[0]['original'].='changed';$check(ownOriginalDatabaseHash($changed,['own_original'],null,true)!==$vectors[0],'selected metadata oracle still detects changed original binary row');
    $changed=new RestoreOraclePdo(4);foreach($changed->columns as&$column)if($column['TABLE_NAME']==='own_original')$column['EXTRA'].='changed';unset($column);$check(ownOriginalDatabaseHash($changed,['own_original'],null,true)!==$vectors[0],'selected metadata oracle still detects changed original column metadata');
    $changed=new RestoreOraclePdo(4);foreach($changed->primary as&$primary)if($primary['TABLE_NAME']==='own_original')$primary['ORDINAL_POSITION']=2;unset($primary);$check(ownOriginalDatabaseHash($changed,['own_original'],null,true)!==$vectors[0],'selected metadata oracle still detects changed original primary key metadata');
    $changed=new RestoreOraclePdo(4);$reject(fn()=>ownOriginalDatabaseHash($changed,['missing_original'],null,true),'selected metadata cannot hide missing original table');
    $cli=static function(array $args,array $input,int $expected,string $label)use($check):void{
        $process=proc_open([PHP_BINARY,dirname(__DIR__,2).'/distribution/recover-native.php',...$args],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        if(!is_resource($process))throw new RuntimeException('Own private CLI unavailable.');
        fwrite($pipes[0],json_encode($input,JSON_THROW_ON_ERROR));fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($process);
        $check($exit===$expected&&($expected===0||$out==='')&&!str_contains($out.$err,'PRIVATE-CODE-SENTINEL'),$label);
    };
    $cli(['--help'],[],0,'actual private CLI declares restore account operations');
    foreach([
        ['--restore-account-codes'],
        ['--config-stdin','--restore-account-codes','--resume'],
        ['--config-stdin','--restore-account-codes','--release'],
        ['--config-stdin','--inspect-restore-account','--resume'],
    ]as$i=>$args)$cli($args,[],1,'actual CLI rejects illegal account operation '.$i.' before database access');
    $base=['root'=>$work,'dsn'=>'unused','username'=>'unused','password'=>'PRIVATE-CODE-SENTINEL','key_file'=>$work.'/absent.key','account_id'=>str_repeat('1',32)];
    $cli(['--config-stdin','--restore-account-codes'],$base,1,'actual CLI refuses incomplete handoff identity before database access');
    $cli(['--config-stdin','--inspect-restore-account'],$base+['unexpected'=>'PRIVATE-CODE-SENTINEL'],1,'actual CLI rejects extra inspect fields before database access');
    $cli(['--config-stdin','--inspect-restore-account'],$base,1,'actual CLI refuses absent private backup key before database access');
    echo 'Native restore account pure: '.$count." conditions passed.\n";
}finally{$remove($work);}
