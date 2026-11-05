<?php declare(strict_types=1);
ini_set('zend.exception_ignore_args','1');
require dirname(__DIR__).'/native/bootstrap.php';
require dirname(__DIR__).'/src/bootstrap.php';
require __DIR__.'/support/original-database-hash.php';
use Kulturhus\Native\{TestSupport,Shared\Db};
use Kulturhus\Verein\{Registry,ContactArchive};

$db=TestSupport::db();
Kulturhus\Native\Installer\SchemaRegistry::install($db);
Db::run($db,"INSERT INTO clubs VALUES('own-catalog-a','Own dated memberships A'),('own-catalog-b','Own dated memberships B')");
foreach(['own-price-admin'=>'own-catalog-a','own-price-other'=>'own-catalog-b']as$id=>$club)Db::run($db,"INSERT INTO actors(id,club_id,role,is_superuser) VALUES(?,?,'members',1)",[$id,$club]);
$registry=new Registry($db,'own-price-admin');$foreign=new Registry($db,'own-price-other');$n=0;
$check=static function(bool$ok,string$label)use(&$n):void{if(!$ok)throw new RuntimeException('FAIL '.$label);++$n;echo 'PASS '.$label."\n";};
$deny=static function(callable$run,string$label)use($check):void{try{$run();}catch(DomainException){$check(true,$label);return;}throw new RuntimeException('Accepted '.$label);};
$person=static fn(string$name):string=>$registry->saveContact(null,0,'person',$name,'own@example.invalid','Own current permitted address');
$active=$person('Own A exact active');$activeMembership=$registry->addMembership($active,'DATE-A','aktiv','2020-03-01');
$passive=$person('Own B exact passive');$passiveMembership=$registry->addMembership($passive,'DATE-P','passiv','2020-01-01');
$registry->endMembership($activeMembership,1,'2022-02-28');$registry->endMembership($passiveMembership,1,'2022-12-31');
$old=$person('Own C old former');$oldMembership=$registry->addMembership($old,'DATE-OLD','passiv','2018-05-01');
// The pre-feature original deliberately has no retained type. Do not manufacture a migration/backfill.
Db::run($db,"UPDATE memberships SET status='ehemalig',ends_on='2023-12-31',version=2 WHERE club_id='own-catalog-a' AND id=?",[$oldMembership]);
$year=$person('Own D year only');$registry->addMembership($year,'DATE-YEAR','aktiv','2020-01-01',null,null,'year');
$future=$person('Own E future entry');$registry->addMembership($future,'DATE-FUTURE','passiv','2028-06-01');
$contact=$person('Own F contact only');
$registry->addMembership($active,'DATE-REJOIN','passiv','2024-06-01');
$foreignId=$foreign->saveContact(null,0,'person','Foreign private dated member','foreign@example.invalid','Foreign address');$foreign->addMembership($foreignId,'DATE-FOREIGN','aktiv','2010-01-01');
$archived=$person('Own protected archived profile');$archivedMembership=$registry->addMembership($archived,'DATE-ARCHIVE','passiv','2019-01-01');
$archive=new ContactArchive($db,'own-price-admin',str_repeat('synthetic-key-',4));$archive->archive($archived,1,'Own protected archived profile','Own deliberate archived original','2021-12-31');
$check(Db::run($db,"SELECT COUNT(*) FROM audit_events WHERE club_id='own-catalog-a' AND object_id=? AND action='membership.ended.passiv'",[$archivedMembership])->fetchColumn()==1,'archive captures its exact original membership type atomically');
$tables=$db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);$original=ownOriginalDatabaseHash($db,$tables);
$row=static function(string$id,string$day)use($registry):array{foreach($registry->contactPage(['as_of'=>$day],1,100)['rows']as$r)if($r['id']===$id)return$r;throw new RuntimeException('Missing own dated member');};
foreach([
    [$active,'2020-02-29','kontakt',null],[$active,'2020-03-01','aktiv','DATE-A'],
    [$active,'2022-02-28','aktiv','DATE-A'],[$active,'2022-03-01','ehemalig','DATE-A'],
    [$active,'2024-05-31','ehemalig','DATE-A'],[$active,'2024-06-01','passiv','DATE-REJOIN'],
    [$passive,'2021-07-01','passiv','DATE-P'],[$passive,'2022-12-31','passiv','DATE-P'],
    [$old,'2019-12-31','mitglied','DATE-OLD'],[$old,'2024-01-01','ehemalig','DATE-OLD'],
    [$year,'2020-01-01','ungewiss','DATE-YEAR'],[$year,'2020-12-30','ungewiss','DATE-YEAR'],
    [$year,'2020-12-31','aktiv','DATE-YEAR'],[$year,'2021-01-01','aktiv','DATE-YEAR'],
    [$future,'2028-05-31','kontakt',null],[$future,'2028-06-01','passiv','DATE-FUTURE'],
    [$contact,'2021-01-01','kontakt',null],
]as[$id,$day,$status,$number]){$r=$row($id,$day);$check($r['member_status']===$status&&$r['member_number']===$number,'actual period/type/number at '.$day.' '.$status.' '.$number);}
$current=$registry->contactPage(['as_of'=>'2021-07-01','list'=>'current']);
$check($current['total']===4,'current date list includes confirmed unknown type but excludes future and nonmembers');
$check($registry->contactPage(['as_of'=>'2020-06-01','list'=>'current'])['total']===3,'year-only uncertainty is excluded from confirmed active membership');
$check($registry->contactPage(['as_of'=>'2020-06-01','status'=>'ungewiss'])['total']===1,'year-only uncertainty has its own complete filter');
$check($registry->contactPage(['as_of'=>'2021-07-01','status'=>'passiv'])['total']===1,'known passive filter never invents the old lost type');
$check($registry->contactPage(['as_of'=>'2021-07-01','status'=>'mitglied'])['rows'][0]['id']===$old,'unknown historic type is independently reachable');
$check($registry->contactPage(['as_of'=>'2021-07-01','q'=>'DATE-A'])['rows'][0]['id']===$active,'search uses the number of the selected original period');
$check($registry->contactPage(['as_of'=>'2021-07-01','q'=>'DATE-REJOIN'])['total']===0,'future re-entry number cannot alter earlier search results');
$check($registry->contactPage(['as_of'=>'2021-07-01','q'=>'Foreign'])['total']===0,'date never widens current club authorization');
$check($registry->contactPage(['as_of'=>'2020-01-01','q'=>'archived'])['total']===0,'protected archived profiles are not exposed by a historical date');
foreach(['2021-02-29','2020-02-30','0000-00-00','1799-12-31','2100-01-01','2021-01-01 OR 1=1']as$bad)$deny(fn()=>$registry->contactPage(['as_of'=>$bad]),'invalid reference date rejected '.$bad);
$deny(fn()=>Registry::filters(['as_of'=>['2020-01-01']]),'nonscalar reference date rejected');
$check(ownOriginalDatabaseHash($db,$tables)===$original,'all reads and rejected dates retain every original business table and byte');
$list=$registry->saveList('Own fixed historical date',['as_of'=>'2021-07-01','list'=>'current']);
$saved=array_values(array_filter($registry->savedLists(),static fn($r)=>$r['id']===$list))[0];
$check(json_decode($saved['filters'],true)['as_of']==='2021-07-01','saved list retains a fixed date rather than moving it with today');
$csv=$registry->exportContacts([$active,$old],['name','member_number','member_status','as_of'],'csv','2021-07-01');
$check(str_contains($csv,'DATE-A')&&!str_contains($csv,'DATE-REJOIN')&&str_contains($csv,'Art unbekannt')&&substr_count($csv,'2021-07-01')===2,'CSV carries exact date number and honest unknown status');
$xlsx=$registry->exportContacts([$active],['member_number','member_status','as_of'],'xlsx','2021-07-01');
$file=tempnam(sys_get_temp_dir(),'dvm-dated-xlsx-');file_put_contents($file,$xlsx);$zip=new ZipArchive();$zip->open($file);$xml=$zip->getFromName('xl/worksheets/sheet1.xml');$zip->close();unlink($file);
$check(is_string($xml)&&str_contains($xml,'DATE-A')&&str_contains($xml,'2021-07-01'),'real XLSX contains selected period and date');
$deny(fn()=>$registry->exportContacts([$foreignId],['name'],'csv','2021-07-01'),'historical export rejects foreign original');
$deny(fn()=>$registry->exportContacts([$archived],['name'],'csv','2021-07-01'),'historical export keeps archive authorization');
$deny(fn()=>$registry->exportContacts([$active],['member_status'],'csv'),'status export requires an explicit reference date');
$deny(fn()=>$registry->endMembership($passiveMembership,1,'2022-12-31'),'stale termination cannot replace the recorded original type');
$db->exec("INSERT INTO actors(id,club_id,role,contact_id) VALUES('own-dated-self','own-catalog-a','member',".$db->quote($active).")");
$self=new Registry($db,'own-dated-self');$check($self->contactPage(['as_of'=>'2021-07-01'])['total']===1,'ordinary member sees only its own permitted historical profile');$deny(fn()=>$self->exportContacts([$active],['name'],'csv','2021-07-01'),'ordinary member cannot obtain staff export');
if(getenv('DVM_MEMBER_REFERENCE_BROWSER')==='1'){
    for($i=0;$i<30;$i++){$id=$person('Own paged '.str_pad((string)$i,2,'0',STR_PAD_LEFT));$registry->addMembership($id,'PAGE-'.$i,'aktiv','2010-01-01');}
    require __DIR__.'/support/catalog-pricing-browser-fixture.php';$browser=ownCatalogPricingBrowser($db,['active'=>$active,'old'=>$old]);
    $originals=ownOriginalDatabaseHash($db,['contacts','memberships','finance_transactions','finance_allocations','accounting_positions']);
    try{$process=proc_open(['node',__DIR__.'/member-reference-date-browser.mjs'],[0=>['pipe','r'],1=>STDOUT,2=>STDERR],$pipes);fwrite($pipes[0],json_encode($browser['input'],JSON_THROW_ON_ERROR));fclose($pipes[0]);if(proc_close($process)!==0)throw new RuntimeException('Actual membership reference-date browser failed');
        $check(ownOriginalDatabaseHash($db,['contacts','memberships','finance_transactions','finance_allocations','accounting_positions'])===$originals,'actual browser filtering saved lists and downloads preserve all business originals');
    }finally{proc_terminate($browser['process']);proc_close($browser['process']);fclose($browser['log']);}
}
echo json_encode(['suite'=>'native-member-reference-date','checks'=>$n,'external_actions'=>0])."\n";
