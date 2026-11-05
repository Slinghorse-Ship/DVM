<?php declare(strict_types=1);
namespace Kulturhus\Verein { function date(string $format,?int $time=null):string{return \gmdate($format,$time??\strtotime('2029-01-15 UTC'));} }
namespace Kulturhus\Verein\Tests {
require_once __DIR__.'/../../src/bootstrap.php';require_once __DIR__.'/../mysql-bootstrap.php';
require_once __DIR__.'/../../src/Finance/Balance/BalancePeriodLedger.php';
use Kulturhus\Verein\{AssetBooks,AssetValuationEvidence,BalanceLedger,BalanceSources,BalancePeriodLedger,BalancePeriodSources,Database,Documents,Finance,SettingsSchema,DocumentCatalog};
/** Model corrupted backup bytes only in the explicitly disposable synthetic schema. */
function costCorruptOriginal(\PDO $db,string $id,callable $assertion):void
{
    $name=$db->query('SELECT DATABASE()')->fetchColumn();
    if(getenv('VEREIN_MODE')!=='synthetic'||!is_string($name)||($name!=='k15753_migrate'&&!preg_match('/^kh_test_[a-f0-9]+$/D',$name)))throw new \RuntimeException('Synthetic schema required');
    $definition=$db->query('SHOW CREATE TRIGGER document_files_no_update')->fetch()['SQL Original Statement'];
    $q=$db->prepare('SELECT bytes FROM document_files WHERE club_id=? AND document_id=? AND revision=1');$q->execute(['a',$id]);$original=$q->fetchColumn();
    if(!is_string($original))throw new \RuntimeException('Synthetic original missing');
    $db->exec('DROP TRIGGER document_files_no_update');
    try{$update=$db->prepare('UPDATE document_files SET bytes=? WHERE club_id=? AND document_id=? AND revision=1');$update->execute(['corrupted synthetic backup','a',$id]);$assertion();}
    finally{$db->prepare('UPDATE document_files SET bytes=? WHERE club_id=? AND document_id=? AND revision=1')->execute([$original,'a',$id]);$db->exec($definition);}
    if($db->query('SHOW CREATE TRIGGER document_files_no_update')->fetch()['SQL Original Statement']!==$definition)throw new \RuntimeException('Original immutability trigger was not restored');
}
function costSetup(bool $period=false):array
{
    $key=static fn()=>bin2hex(random_bytes(16));$db=MySqlFixture::connect();Database::migrate($db);
    $db->exec("INSERT INTO clubs VALUES('a','Synthetic cost assets'),('b','Synthetic foreign club');
      INSERT INTO actors(id,club_id,role,is_superuser) VALUES('admin','a','treasurer',1),('other','b','treasurer',1),('member','a','member',0),('reader','a','treasurer',0);
      INSERT INTO settings_roles(club_id,id,name,description,members_access,finance_access,settings_access,version,inventory_access) VALUES('a','reader','Synthetic reader','','none','read',0,1,'read');
      INSERT INTO settings_role_assignments VALUES('reader','a','reader',1)");
    $tax=array_replace(SettingsSchema::defaults('tax'),['accounting_basis'=>'balance','depreciation_enabled'=>true,'input_tax_eligibility'=>'no']);
    foreach(['a','b'] as $club)$db->prepare('INSERT INTO club_settings(club_id,section,payload,version,updated_at) VALUES(?,?,?,1,?)')->execute([$club,'tax',json_encode($tax),gmdate('c')]);
    putenv('VEREIN_BALANCE_WORKSPACE=1');putenv('VEREIN_MODE=synthetic');
    $books=new AssetBooks($db,'admin');$docs=new Documents($db,'admin');$finance=new Finance($db,'admin');$evidence=new AssetValuationEvidence($db,'admin');
    $png=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=');
    $document=static fn(string $title)=>$docs->upload('proof.png',$png,['title'=>$title,'kind'=>'invoice','scope'=>'finance'],$key());
    $make=static function(string $item,string $code,string $kind,string $date='2025-02-15')use($db,$finance,$docs,$png,$key,$books):array{
      $invoice=$finance->createEntry(['kind'=>'expense','description'=>'Synthetic '.$item,'amount'=>'120','document_date'=>$date,'due_date'=>$date,'category'=>'general',
        'positions'=>[['description'=>'Synthetic cost asset','amount'=>'120','tax_rate'=>'0','sphere'=>'4','account'=>$code,'booking_key'=>'','input_tax'=>'none']]],$key());
      $purchase=$docs->upload('purchase.png',$png,['title'=>'Synthetic acquisition '.$item,'kind'=>'invoice','scope'=>'finance','links'=>['entry:'.$invoice]],$key());
      $db->prepare("INSERT INTO inventory_items(club_id,id,code,name,kind) VALUES('a',?,?,?,'reusable')")->execute([$item,'COST-'.$item,'Synthetic '.$item]);
      DocumentCatalog::register($db,'a','item',$item);
      $input=['method'=>'cost','basis'=>'120','acquired_on'=>$date,'sphere'=>'4','account'=>$code,'reason'=>'Synthetic original invoice, reviewed non-depreciable asset',
        'valuation_kind'=>$kind,'valuation_document_id'=>$purchase,'asset_reviewed'=>'1'];$books->save($item,0,$input);
      $db->prepare('INSERT INTO invoice_position_items VALUES(?,?,?,?,?,?,?,?)')->execute(['a',$invoice,1,$item,1,'Synthetic item','admin',gmdate('c')]);
      return compact('invoice','purchase','input');
    };
    if($period){
      putenv('VEREIN_BALANCE_PERIODS=1');$ledger=new BalancePeriodLedger($db,'admin');$sources=new BalancePeriodSources($db,'admin');
      $period=['start'=>'2025-02-15','end'=>'2025-12-15'];$pid=$ledger->create($period,'Synthetic partial-month cost period',true);$ledger->setup($pid,1,[],'Synthetic zero opening',true);
      $import=static function(string $type,string $id,array $options=[],?string $target=null)use($ledger,$sources,$pid):array{
        $target??=$pid;$report=$ledger->report($target);$p=$sources->preview($report['period'],$type,$id,$options);
        if($p['issues'])throw new \RuntimeException(implode(' ',$p['issues']));
        $ledger->importSource($target,$report['version'],$type,$id,$options,$p['hash'],true);return $ledger->report($target);
      };
    }else{
      $ledger=new BalanceLedger($db,'admin');$sources=new BalanceSources($db,'admin');$ledger->create(2025);$ledger->setup(2025,1,$ledger->report(2025)['accounts'],[],'equity','Synthetic zero opening',true);
      $import=static function(string $type,string $id,array $options=[],int $year=2025)use($ledger,$sources):array{
        $p=$sources->preview($year,$type,$id,$options);if($p['issues'])throw new \RuntimeException(implode(' ',$p['issues']));
        $ledger->importSource($year,$ledger->report($year)['version'],$type,$id,$options,$p['hash'],true);return $ledger->report($year);
      };
    }
    return get_defined_vars();
}
}
