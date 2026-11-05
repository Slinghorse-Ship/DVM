<?php declare(strict_types=1);
require __DIR__.'/../src/bootstrap.php';
use Kulturhus\Verein\{Database,Documents,Settings,DocumentProtection};
$tests=0;
function check(bool $ok,string $label):void{global $tests;++$tests;if(!$ok)throw new RuntimeException($label);echo "PASS $label\n";}
function denied(callable $fn,string $label):void{try{$fn();}catch(DomainException){check(true,$label);return;}throw new RuntimeException($label);}
$db=\Kulturhus\Verein\Tests\MySqlFixture::connect(':memory:');Database::migrate($db);
$db->exec("INSERT INTO clubs VALUES('a','Test'); INSERT INTO actors(id,club_id,role) VALUES('manager','a','members'),('settings','a','members'),('finance','a','treasurer'); INSERT INTO actor_capabilities VALUES('manager','local_finance')");
$admin=new Settings($db,'manager');$role=$admin->saveRole('',0,['name'=>'Nur Einstellungen','members_access'=>'none','finance_access'=>'none','settings_access'=>true,'documents_access'=>'none','inventory_access'=>'none','loans_access'=>'none']);$admin->assignRole('settings',$role,0);
$full=new Documents($db,'manager');$limited=new Documents($db,'settings');$finance=new Documents($db,'finance');
$empty=['values'=>array_fill_keys(['VEREINS_IBAN','VEREINS_BIC','KONTOINHABER','GLAEUBIGER_ID','PAYPAL_LINK'],'')];
$bank=$empty;$bank['values']['VEREINS_IBAN']='DE89370400440532013000';
$meta=['title'=>'Testvorschau','kind'=>'preview','scope'=>'settings'];$key=fn()=>bin2hex(random_bytes(16));
$id=$full->generated('Test.pdf','%PDF-1.4',$meta,$key(),$bank);
check($full->document($id)['scopes']===['finance','settings'],'finance source rights retained in archive');
denied(fn()=>$limited->file($id),'settings-only role cannot download finance preview');
denied(fn()=>$finance->file($id),'finance-only role cannot download settings preview');
check($limited->listing()===[],'protected preview hidden from listing');
// Simulate metadata saved by the earlier version: old snapshots must also be protected.
$db->prepare('UPDATE documents SET scopes=? WHERE id=?')->execute(['["settings"]',$id]);
denied(fn()=>$limited->file($id),'legacy preview metadata cannot bypass bank-data protection');
check($full->document($id)['scopes']===['finance','settings'],'legacy effective scopes reconstructed');
$without=$limited->generated('Leer.pdf','%PDF-1.4',$meta,$key(),$empty);
check($limited->file($without)['bytes']==='%PDF-1.4','bank-free preview remains available to settings role');
denied(fn()=>$limited->generated('Bank.pdf','%PDF-1.4',$meta,$key(),$bank),'generation requires rights for snapshot data');
denied(fn()=>$limited->generated('Unklar.pdf','%PDF-1.4',$meta,$key(),[]),'unknown preview provenance fails closed');
check(count($limited->listing())===1,'rejected archive writes leave no documents');
foreach(['VEREINS_BIC','KONTOINHABER','GLAEUBIGER_ID','PAYPAL_LINK'] as $field){$one=$empty;$one['values'][$field]='TEST';check(DocumentProtection::previewScopes($one)===['finance','settings'],$field.' protected');}
check(\Kulturhus\Verein\Tests\MySqlFixture::foreignKeyErrors($db)===[],'foreign keys preserved');
echo "$tests document protection checks passed\n";
