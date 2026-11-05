<?php declare(strict_types=1);
namespace Kulturhus\Verein { function date(string $format,?int $time=null):string{return \gmdate($format,$time??\strtotime($GLOBALS['partialReviewToday']??'2028-01-15 UTC'));} }
namespace Kulturhus\Verein\Tests {
require_once __DIR__.'/../../src/bootstrap.php';
require_once __DIR__.'/../../src/Finance/Assets/AssetPartialDisposalReview.php';
require_once __DIR__.'/../mysql-bootstrap.php';
use Kulturhus\Verein\{AssetBooks,AssetPartialDisposal,BalanceLedger,BalanceSources,Database,Documents,Finance,SettingsSchema,DocumentCatalog};

/** Synthetic originals only; no production DSN or data. Acquisition starts unposted. */
function partialReviewSetup():array
{
    $key=static fn()=>bin2hex(random_bytes(16));
    $db=MySqlFixture::connect();Database::migrate($db);
    $db->exec("INSERT INTO clubs VALUES('a','Synthetic partial disposal'),('b','Synthetic separate club');
        INSERT INTO actors(id,club_id,role,is_superuser) VALUES('admin','a','treasurer',1),('other','b','treasurer',1),('member','a','member',0),('editor','a','editor',0),('writer','a','treasurer',0),('reader','a','treasurer',0),('financeonly','a','treasurer',0);
        INSERT INTO settings_roles(club_id,id,name,description,members_access,finance_access,settings_access,version,inventory_access) VALUES
        ('a','writer','Synthetic asset writer','','none','write',0,1,'write'),('a','reader','Synthetic finance reader','','none','read',0,1,'write');
        INSERT INTO settings_role_assignments VALUES('writer','a','writer',1),('reader','a','reader',1)");
    $tax=SettingsSchema::defaults('tax');$tax['accounting_basis']='balance';$tax['input_tax_eligibility']='no';$tax['depreciation_enabled']=true;
    foreach(['a','b'] as $club)$db->prepare('INSERT INTO club_settings(club_id,section,payload,version,updated_at) VALUES(?,?,?,1,?)')->execute([$club,'tax',json_encode($tax),gmdate('c')]);
    putenv('VEREIN_BALANCE_WORKSPACE=1');
    $ledger=new BalanceLedger($db,'admin');$sources=new BalanceSources($db,'admin');$books=new AssetBooks($db,'admin');$docs=new Documents($db,'admin');$finance=new Finance($db,'admin');
    $ledger->create(2025);$ledger->setup(2025,1,$ledger->report(2025)['accounts'],[],'equity','Synthetic opening',true);
    $png=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=');
    $invoice=$finance->createEntry(['kind'=>'expense','description'=>'Synthetic equipment invoice','amount'=>'120','document_date'=>'2025-01-15','due_date'=>'2025-01-15','category'=>'general',
        'positions'=>[['description'=>'Synthetic components','amount'=>'120','tax_rate'=>'0','sphere'=>'4','account'=>'06300','booking_key'=>'','input_tax'=>'none']]],$key());
    $purchaseDoc=$docs->upload('purchase.png',$png,['title'=>'Synthetic component invoice','kind'=>'invoice','scope'=>'finance','links'=>['entry:'.$invoice]],$key());
    $db->exec("INSERT INTO inventory_items(club_id,id,code,name,kind) VALUES('a','partial','PART-1','Synthetic divisible equipment','reusable')");
    DocumentCatalog::register($db,'a','item','partial');
    $books->save('partial',0,['basis'=>'120','residual'=>'0','acquired_on'=>'2025-01-15','months'=>'24','sphere'=>'4','account'=>'06300','reason'=>'Synthetic invoice and shared useful life']);
    $db->prepare('INSERT INTO invoice_position_items VALUES(?,?,?,?,?,?,?,?)')->execute(['a',$invoice,1,'partial',1,'Synthetic components','admin',gmdate('c')]);
    $disposalDoc=$docs->upload('disposal.png',$png,['title'=>'Synthetic signed component disposal','kind'=>'invoice','scope'=>'finance','links'=>['item:partial']],$key());
    $plan=$books->get('partial')['data'];
    $ref=function(string $id)use($docs):array{$d=$docs->document($id);return ['document_id'=>$id,'revision'=>(int)$d['revision'],'sha256'=>$d['sha256']];};
    $proof=['schema'=>1,'kind'=>'partial_scrap','date'=>'2025-06-15','reason'=>'Synthetic damaged component without proceeds or compensation']+$ref($disposalDoc)+[
        'plan_sha256'=>AssetPartialDisposal::hash($plan),
        'allocation'=>['basis'=>'documented_component_cost','cost_cents'=>4000,'residual_cents'=>0]+$ref($purchaseDoc)+[
            'reason'=>'Synthetic separately documented component cost; same use and useful life','same_use_and_life'=>true,'confirmed'=>true],
        'no_proceeds'=>true,'complete'=>false];
    return compact('db','tax','ledger','sources','books','docs','finance','png','invoice','purchaseDoc','disposalDoc','plan','ref','proof');
}
}
