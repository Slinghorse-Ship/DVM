<?php declare(strict_types=1);
require __DIR__.'/../src/bootstrap.php';require __DIR__.'/../src/Web/View.php';
use Kulturhus\Verein\{Database,Finance,Registry,EventGema};
set_error_handler(static function(int $severity,string $message,string $file,int $line):never{throw new ErrorException($message,0,$severity,$file,$line);});
$db=\Kulturhus\Verein\Tests\MySqlFixture::connect(':memory:');Database::migrate($db);$db->exec("INSERT INTO clubs VALUES('a','Synthetic');INSERT INTO actors(id,club_id,role,is_superuser) VALUES('admin','a','members',1)");
$finance=new Finance($db,'admin');$registry=new Registry($db,'admin');$gema=new EventGema($db,'admin',$registry);
$checks=0;function check(bool $ok,string $message):void{global $checks;if(!$ok)throw new RuntimeException($message);++$checks;echo "PASS $message\n";}
function denied(callable $run,string $message):void{try{$run();}catch(DomainException){check(true,$message);return;}throw new RuntimeException($message);}
$event=$registry->saveAppointment(null,0,['title'=>'Musterkonzert','start'=>'2027-09-01T18:00','end'=>'2027-09-01T20:00','category'=>'verein'],[]);
$gema->checklist($event,0,'yes',false,'pending');$gema->checklist($event,1,'yes',true,'pending');
$_SESSION=['actor'=>'admin','csrf'=>'synthetic'];$_GET=['view'=>'finance','tab'=>'receipts','new_entry'=>'1','gema_event'=>$event];
$_POST=['action'=>'create-entry','gema_event'=>$event,'gema_version'=>'1','description'=>'Mein Entwurf','kind'=>'expense'];
function renderFinanceState():string{global $db,$finance,$registry;$canFinance=true;$view='finance';$tab=$_GET['tab'];$views=['finance'=>'Finanzen'];$financeEntry=null;ob_start();try{require __DIR__.'/../views/Finance/Accounting/finance.php';return ob_get_clean();}catch(Throwable $e){ob_end_clean();throw $e;}}
$html=renderFinanceState();check(str_contains($html,'name="gema_version" value="1"'),'conflict rerender retains original GEMA version');check(str_contains($html,'value="Mein Entwurf"'),'conflict retains financial draft');
$bill=['kind'=>'expense','description'=>'GEMA Test','amount'=>'100','document_date'=>'2026-09-01','due_date'=>'2026-09-10','category'=>'culture'];
denied(fn()=>$finance->createEntry($bill,bin2hex(random_bytes(16)),fn($id)=>$gema->linkInvoice($event,1,$id)),'repeated stale submission remains rejected');
check((int)$db->query('SELECT COUNT(*) FROM finance_entries')->fetchColumn()===0&&$gema->get($event)['status']==='registered','stale submission creates no invoice and preserves concurrent status');
$_POST=[];check(str_contains(renderFinanceState(),'name="gema_version" value="2"'),'fresh GET loads current version');
$_GET=['view'=>'finance','tab'=>'export'];$filters=['from'=>'2026-08-01','to'=>'2026-02-01','kind'=>'expense','status'=>'partial'];
foreach(['finance-export','finance-bundle'] as $action){$_POST=['action'=>$action,'export_form'=>'finance']+$filters;denied(fn()=>$finance->export($filters),'invalid range rejected '.$action);$html=renderFinanceState();foreach(['name="from" required value="2026-08-01"','name="to" required value="2026-02-01"','value="expense" selected','value="partial" selected'] as $needle)check(str_contains($html,$needle),'export error retains '.$needle.' '.$action);}
$_POST=['action'=>'datev-preview','from'=>'2025-01-01','to'=>'2025-12-31','kind'=>'expense'];$html=renderFinanceState();check(!str_contains($html,'name="from" required value="2025-01-01"'),'DATEV draft does not contaminate finance export filters');
echo "$checks finance form state checks passed\n";
