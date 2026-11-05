<?php declare(strict_types=1);
require __DIR__.'/../src/Finance/Balance/BalanceReadSnapshot.php';
use Kulturhus\Verein\BalanceReadSnapshot as R;
// No database emulation of business rules: this checks only PDO transaction
// ownership and the lifetime of the small, pure read-cache coordinator.
final class ReadSnapshotPdo extends PDO
{
    public bool $transaction=false;public int $begins=0;public int $commits=0;public int $rollbacks=0;
    public function __construct(){}
    public function getAttribute(int $attribute):mixed{return $attribute===PDO::ATTR_DRIVER_NAME?'synthetic-coordinator':null;}
    public function inTransaction():bool{return $this->transaction;}
    public function beginTransaction():bool{$this->begins++;return $this->transaction=true;}
    public function commit():bool{$this->commits++;$this->transaction=false;return true;}
    public function rollBack():bool{$this->rollbacks++;$this->transaction=false;return true;}
}
$n=0;function check(bool $ok):void{global $n;if(!$ok)throw new RuntimeException('Read snapshot check failed '.$n);$n++;}
$db=new ReadSnapshotPdo;$calls=0;$value=7;$read=static function()use(&$calls,&$value){$calls++;return ['value'=>$value];};
$get=static fn()=>R::remember($db,'model',['club','actor','period',true],$read);
$get();$get();check($calls===2&&$db->begins===0);
R::run($db,static function()use($db,$get,&$calls,$read){$a=$get();$a['value']=99;check($get()['value']===7);check($calls===3);R::run($db,static fn()=>$get());check($calls===3);R::remember($db,'model',['club','other','period',true],$read);check($calls===4);R::remember($db,'model',['club','actor','period',false],$read);check($calls===5);});
check(!$db->inTransaction()&&$db->begins===1&&$db->commits===1);
$value=8;check(R::run($db,$get)['value']===8&&$calls===6);
$db->beginTransaction();R::run($db,$get);check($db->inTransaction()&&$db->begins===3&&$db->commits===2);$db->rollBack();
try{R::run($db,static function()use($get){$get();throw new DomainException('Synthetic rejected read');});}catch(DomainException){}
check(!$db->inTransaction()&&$db->rollbacks===2);$before=$calls;R::run($db,$get);check($calls===$before+1);
try{R::run($db,static fn()=>R::remember($db,'cycle',[],static fn()=>R::remember($db,'cycle',[],static fn()=>null)));throw new RuntimeException('Cycle accepted');}catch(DomainException){check(true);}
$nullCalls=0;R::run($db,static function()use($db,&$nullCalls){$null=static function()use(&$nullCalls){$nullCalls++;return null;};check(R::remember($db,'nullable',[],$null)===null);check(R::remember($db,'nullable',[],$null)===null);});check($nullCalls===1);
echo "$n read snapshot lifetime checks passed; no SQL, network or application mutations\n";
