<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use PDO;use DomainException;
/** Invoked only after the existing HTTP controller has validated the authenticated CSRF token. */
final class FinanceWorkflowActions
{
 public const ACTIONS=['finance-account-settings','finance-account-delete','outgoing-save','outgoing-review','outgoing-issue','outgoing-mail','outgoing-correct','outgoing-pdf','outgoing-xml','asset-book-save','asset-book-year','asset-partial-prepare','asset-partial-book','transfer-order-preview','transfer-order-prepare','transfer-order-confirm','transfer-order-approve','transfer-order-export','transfer-order-submitted','transfer-order-cancel'];
 public static function handle(PDO $db,string $actor,array $post):array{
  $action=$post['action']??'';if(!is_string($action)||!in_array($action,self::ACTIONS,true))throw new DomainException('Unbekannter Finanzvorgang.');$str=static function($key,$default='')use($post){$v=$post[$key]??$default;if(!is_string($v))throw new DomainException('Formularangabe prüfen.');return $v;};$version=filter_var($post['version']??0,FILTER_VALIDATE_INT);if($version===false||$version<0)throw new DomainException('Versionsangabe prüfen.');$finance=new Finance($db,$actor);if(in_array($action,['outgoing-pdf','outgoing-xml'],true)?!$finance->canRead():!$finance->canWrite())throw new DomainException('Finanzberechtigung erforderlich.');$service=new OutgoingInvoices($db,$actor);$id=$str('invoice');$invoiceContext=[];if(str_starts_with($action,'outgoing-')&&array_intersect(['invoices_q','inv_status','inv_kind','invoices_page'],array_keys($post)))$invoiceContext=OutgoingInvoiceCatalogue::controls($post);
  switch($action){
   case 'finance-account-settings':$id=$str('account');$finance->saveAccountSettings($id,$version,$str('active')==='1',$str('tax_account'),$str('fints_read')==='1',$str('fints_write')==='1',array_key_exists('name',$post)?$str('name'):null,array_key_exists('kind',$post)?$str('kind'):null,array_key_exists('opening',$post)?$str('opening'):null,array_key_exists('opened_on',$post)?$str('opened_on'):null);return ['route'=>['view'=>'finance','tab'=>'bank','account'=>$id,'edit_account'=>1,'_anchor'=>'account-settings']];
   case 'finance-account-delete':$finance->deleteAccount($str('account'),$str('confirm_name'),$version);return ['route'=>['view'=>'finance','tab'=>'bank']];
   case 'outgoing-save':$id=$service->save($id,$version,$post,$str('request_key'));break;
   case 'outgoing-review':$service->review($id,$version);break;
   case 'outgoing-issue':$service->issue($id,$version);break;
   case 'outgoing-mail':$service->queue($id,$version);break;
   case 'outgoing-correct':$id=$service->correct($id,$version,$str('correction_kind'),$str('amount'),$str('reason'),$str('correction_date'),$str('request_key'));break;
   case 'outgoing-xml':return ['bytes'=>$service->xml($id),'mime'=>'application/xml','filename'=>'xrechnung.xml'];
   case 'outgoing-pdf':$preview=$service->currentPreview($id);return ['bytes'=>$preview['bytes'],'mime'=>'application/pdf','filename'=>'rechnung.pdf'];
   case 'asset-book-save':$id=$str('item');(new AssetBooks($db,$actor))->save($id,$version,$post);return ['route'=>['view'=>'finance','tab'=>'book-values','item'=>$id]];
   case 'asset-book-year':$id=$str('item');$year=filter_var($post['year']??'',FILTER_VALIDATE_INT);if($year===false)throw new DomainException('Jahr prüfen.');(new AssetBooks($db,$actor))->recordYear($id,$version,$year,$post);return ['route'=>['view'=>'finance','tab'=>'book-values','item'=>$id,'year'=>(string)$year]];
   case 'asset-partial-prepare':
    require_once dirname(__DIR__, 2).'/Finance/Assets/AssetPartialDisposalRecords.php';$year=filter_var($post['year']??'',FILTER_VALIDATE_INT);if($year===false)throw new DomainException('Jahr prüfen.');
    $r=(new AssetPartialDisposalRecords($db,$actor))->prepare($str('item'),$version,$year,$post);
    return ['route'=>['view'=>'finance','tab'=>'book-values','item'=>$r['item_id'],'year'=>(string)$year,'partial_review'=>$r['id'],'_anchor'=>'partial-disposal']];
   case 'asset-partial-book':
    require_once dirname(__DIR__, 2).'/Finance/Assets/AssetPartialDisposalRecords.php';$records=new AssetPartialDisposalRecords($db,$actor);$r=$records->get($str('partial_review'));
    $records->book($r['id'],$str('partial_sha256'),$str('partial_book_confirmed')==='1');
    return ['route'=>['view'=>'finance','tab'=>'book-values','item'=>$r['item_id'],'year'=>(string)$r['year']]];
   case 'transfer-order-preview':
    $id=$str('entry');$service=new TransferOrders($db,$actor,MemberBankVault::fromEnvironment($db,$actor,ProductionRuntime::enabled()));$preview=$service->preview($id,$str('account'),$str('execution_date'),$str('purpose'),$str('transfer_mode','standard'));$_SESSION['transfer_preview']=['actor'=>$actor,'expires'=>time()+900,'entry'=>$id,'account'=>$str('account'),'execution_date'=>$preview['execution_date'],'transfer_mode'=>$preview['transfer_mode'],'purpose'=>$str('purpose'),'preview'=>$preview];return ['route'=>['view'=>'finance','tab'=>'receipts','entry'=>$id,'transfer_new'=>1,'transfer_preview'=>1,'_anchor'=>'invoice-transfer']];
   case 'transfer-order-prepare':
   $id=$str('entry');$service=new TransferOrders($db,$actor,MemberBankVault::fromEnvironment($db,$actor,ProductionRuntime::enabled()));$order=$service->prepare($id,$str('account'),$str('execution_date'),$str('purpose'),$str('preview_hash'),$str('request_key'),$str('transfer_mode','standard'));unset($_SESSION['transfer_preview']);return ['route'=>['view'=>'finance','tab'=>'receipts','entry'=>$id,'transfer_order'=>$order,'_anchor'=>'invoice-transfer']];
   case 'transfer-order-confirm':
    $id=$str('entry');$service=new TransferOrders($db,$actor,MemberBankVault::fromEnvironment($db,$actor,ProductionRuntime::enabled()));$order=$service->prepare($id,$str('account'),$str('execution_date'),$str('purpose'),$str('preview_hash'),$str('request_key'),$str('transfer_mode','standard'));$prepared=$service->get($order);if($prepared['state']==='prepared'&&$service->canManage())$service->approve($order,(int)$prepared['version']);unset($_SESSION['transfer_preview']);if($str('continue_to_bank')==='1'&&$service->get($order)['state']==='approved'&&$service->canSend())return ['route'=>['view'=>'finance','tab'=>'bank','account'=>$prepared['account_id'],'fints'=>1,'payment'=>'transfer','payment_order'=>$order,'_anchor'=>'fints-manual']];return ['route'=>['view'=>'finance','tab'=>'receipts','entry'=>$id,'transfer_order'=>$order,'_anchor'=>'invoice-transfer']];
   case 'transfer-order-approve':
    $order=$str('transfer_order');$service=new TransferOrders($db,$actor,MemberBankVault::fromEnvironment($db,$actor,ProductionRuntime::enabled()));$r=$service->get($order);$service->approve($order,$version);if($str('continue_to_bank')==='1')return ['route'=>['view'=>'finance','tab'=>'bank','account'=>$r['account_id'],'fints'=>1,'payment'=>'transfer','payment_order'=>$order,'_anchor'=>'fints-manual']];return ['route'=>['view'=>'finance','tab'=>'receipts','entry'=>$r['entry_id'],'transfer_order'=>$order,'_anchor'=>'invoice-transfer']];
   case 'transfer-order-export':
    $order=$str('transfer_order');$service=new TransferOrders($db,$actor,MemberBankVault::fromEnvironment($db,$actor,ProductionRuntime::enabled()));$r=$service->get($order);$xml=$service->export($order,$version);return ['bytes'=>$xml,'mime'=>'application/xml','filename'=>'sepa-ueberweisung-'.$r['entry_id'].'.xml'];
   case 'transfer-order-submitted':
    $order=$str('transfer_order');$service=new TransferOrders($db,$actor,MemberBankVault::fromEnvironment($db,$actor,ProductionRuntime::enabled()));$r=$service->get($order);$service->submitted($order,$version,$str('bank_reference'));return ['route'=>['view'=>'finance','tab'=>'receipts','entry'=>$r['entry_id'],'transfer_order'=>$order]];
   case 'transfer-order-cancel':
    $order=$str('transfer_order');$service=new TransferOrders($db,$actor,MemberBankVault::fromEnvironment($db,$actor,ProductionRuntime::enabled()));$r=$service->get($order);$service->cancel($order,$version);return ['route'=>['view'=>'finance','tab'=>'receipts','entry'=>$r['entry_id']]];
  }
  return ['route'=>['view'=>'finance','tab'=>'outgoing','invoice'=>$id]+$invoiceContext];
 }
}
