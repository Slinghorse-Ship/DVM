<?php declare(strict_types=1);
require __DIR__.'/../src/Auth/Permissions.php';
use Kulturhus\Verein\Permissions;

// Exercise the real permission reader without any database allocation.
final class NavigationReadDb extends PDO
{
    public int $reads=0;
    public array $actors=[];
    public array $bindings=[];
    public function __construct(string $label='first')
    {
        foreach(['a','b'] as $id)$this->actors[$id]=['id'=>$id,'club_id'=>$label,'role'=>'members','members_access'=>'write','finance_access'=>'read','settings_access'=>1,'inventory_access'=>'read','loans_access'=>'read','documents_access'=>'read','press_access'=>'none','minutes_access'=>'read','is_superuser'=>0];
    }
    public function inTransaction():bool{return false;}
    public function prepare(string $query,array $options=[]):PDOStatement|false
    {
        $this->reads++;return new NavigationReadStatement($this,str_contains($query,'processwire_identities'));
    }
}
final class NavigationReadStatement extends PDOStatement
{
    private string $id='';
    public function __construct(private NavigationReadDb $db,private bool $binding){}
    public function execute(?array $params=null):bool{$this->id=(string)$params[0];return true;}
    public function fetch(int $mode=PDO::FETCH_DEFAULT,int $cursorOrientation=PDO::FETCH_ORI_NEXT,int $cursorOffset=0):mixed{return $this->db->actors[$this->id]??false;}
    public function fetchColumn(int $column=0):mixed{return $this->db->bindings[$this->id]??false;}
}
$n=0;function check(bool $ok,string $label):void{global $n;if(!$ok)throw new RuntimeException($label);$n++;echo "PASS $label\n";}
$db=new NavigationReadDb();Permissions::actor($db,'a');Permissions::actor($db,'a');check($db->reads===4,'service checks outside menu stay live');
Permissions::navigationRead(static function()use($db):array{
    $a=Permissions::actor($db,'a');$a['finance_access']='write';
    check(Permissions::actor($db,'a')['finance_access']==='read','returned menu rights cannot modify the snapshot');
    check($db->reads===6,'one actor snapshot per menu calculation');
    check(Permissions::navigationRead(static fn()=>Permissions::actor($db,'a'))['id']==='a'&&$db->reads===6,'nested menu reads reuse only the current snapshot');
    Permissions::actor($db,'b');check($db->reads===8,'different actors retain separate snapshots');
    $other=new NavigationReadDb('second');check(Permissions::actor($other,'a')['club_id']==='second'&&$other->reads===2,'different connections retain separate snapshots');
    $transient=new NavigationReadDb('transient');$weak=WeakReference::create($transient);Permissions::actor($transient,'a');unset($transient);check($weak->get()===null,'menu snapshot does not retain a discarded connection');
    return [];
});
$db->actors['a']['finance_access']='none';check(Permissions::actor($db,'a')['finance_access']==='none'&&$db->reads===10,'later service check observes changed rights immediately');
Permissions::navigationRead(static fn()=>Permissions::actor($db,'a'));check($db->reads===12,'next menu rebuild reads current database state');
try{Permissions::navigationRead(static function()use($db):array{Permissions::actor($db,'a');throw new DomainException('synthetic interruption');});}catch(DomainException){}
Permissions::actor($db,'a');check($db->reads===16,'exception releases the menu snapshot');
Permissions::navigationRead(static function()use($db):array{
    $before=$db->reads;for($i=0;$i<2;$i++)try{Permissions::actor($db,'missing');throw new RuntimeException('missing actor allowed');}catch(DomainException){}
    check($db->reads===$before+2,'denials are not cached as successful actor records');return [];
});
$db->bindings['a']=0;try{Permissions::actor($db,'a');throw new RuntimeException('revoked identity allowed');}catch(DomainException){check(true,'revoked identity is denied after menu generation');}
$db->bindings['a']=1;$db->actors['a']['role']='editor';$db->actors['a']['finance_access']='write';$db->actors['a']['members_access']='write';
$editor=Permissions::navigationRead(static fn()=>Permissions::actor($db,'a'));check($editor['finance_access']==='none'&&$editor['members_access']==='none'&&$editor['settings_access']===0,'editor boundary still overrides corrupt assigned rights');
echo "$n menu permission lifetime checks passed; no database or transport\n";
