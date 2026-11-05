<?php declare(strict_types=1);
namespace Kulturhus\Verein\Web;
use DomainException;
use Kulturhus\Verein\ActivityReportReview;
/** Original submitted text/metadata never acquires current stored versions or a fresh preview receipt. */
final class ActivityReportPageState
{
 public static function read(ActivityReportReview $service,array $query,#[\SensitiveParameter] array $post,int $status):array
 {
  if(!$service->canRead())throw new DomainException('Keine Berechtigung für diesen Tätigkeitsbericht.',403);
  $chair=$service->isChair();$prepare=$service->canPrepare();$rawAction=$post['action']??null;
  if(($rawAction==='activity-report-prepare'&&!$prepare)||(in_array($rawAction,['activity-report-edit','activity-report-regenerate','activity-report-approve'],true)&&!$chair)||($rawAction==='activity-report-preview'&&($post['with_signature']??null)==='1'&&!$chair))throw new DomainException('Für diese Berichtsaktion fehlt die aktuelle Berechtigung.',403);
  $source=ActivityReportForm::query($query);if($source['report_kind']!==$service->kind())throw new DomainException('Ursprüngliche Berichtsart prüfen.',422);
  $out=['source'=>$source,'kind'=>$service->kind(),'posted'=>$post!==[],'blocked'=>false,'chair'=>$service->chair(),'is_chair'=>$chair,'can_prepare'=>$prepare,'selected'=>null,'facts'=>null,'approved'=>false,'changed'=>false,'can_approve'=>false,'preview_token'=>null,'prepare_values'=>null,'edit_values'=>null,'operation'=>null,'lookup'=>false,'rows'=>[]];
  if($post!==[]){
   $command=ActivityReportForm::command($query,$post);$out['blocked']=in_array($status,[403,404,409,500,503],true);
   if($command['action']==='activity-report-prepare')$out['prepare_values']=['from_year'=>$post['from_year'],'to_year'=>$post['to_year'],'supplement'=>$command['supplement'],'prepare_token'=>$post['prepare_token']];
   elseif($command['action']==='activity-report-edit')$out['edit_values']=['report_id'=>$command['id'],'version'=>$post['version'],'source_hash'=>$command['source_hash'],'body'=>$command['body']];
   else$out['operation']=$command;
   return$out;
  }
  if(isset($source['prepared_from'])){
   $from=ActivityReportForm::year($source['prepared_from']);$to=ActivityReportForm::year($source['prepared_to']);if($from<2000||$to<$from||$to>$from+2||$to>(int)date('Y'))throw new DomainException('Berichtszeitraum prüfen.',422);
   $out['lookup']=true;$out['selected']=$service->forPeriod($from,$to);return$out;
  }
  if(isset($source['report_from'])){
   $from=ActivityReportForm::year($source['report_from']);$to=ActivityReportForm::year($source['report_to']);$existing=$service->forPeriod($from,$to);
   if($existing!==null){$source['report_id']=$existing['id'];$out['source']=$source;}
   elseif($prepare)$out['prepare_values']=['from_year'=>(string)$from,'to_year'=>(string)$to,'supplement'=>''];
  }
  if($source['report_id']!==''){
   if(isset($source['edit'])&&!$chair)throw new DomainException('Für diese Berichtsaktion fehlt die aktuelle Berechtigung.',403);
   $report=$service->get($source['report_id']);$facts=$service->source((int)$report['from_year'],(int)$report['to_year'],$report['supplement']);$approved=$service->approved($report);$changed=!hash_equals($facts['hash'],$report['source_hash']);
   $out['selected']=$report;$out['facts']=$facts;$out['approved']=$approved;$out['changed']=$changed;$out['can_approve']=$chair&&$report['state']==='review'&&!$changed&&$facts['complete']&&trim($out['chair']['name']??'')!==''&&trim($facts['club']['name']??'')!=='';
   if($chair&&isset($source['edit']))$out['edit_values']=['report_id'=>$report['id'],'version'=>(string)$report['version'],'source_hash'=>$facts['hash'],'body'=>$report['body']];
   if(!$approved&&!isset($source['edit']))$out['preview_token']=bin2hex(random_bytes(16));
   return$out;
  }
  $rows=$service->listing();$rows=array_values(array_filter($rows,static fn(array $row):bool=>(!isset($source['report_from'])||((int)$row['from_year']===(int)$source['report_from']&&(int)$row['to_year']===(int)$source['report_to']))&&($source['state']===''||$row['state']===$source['state'])&&($source['q']===''||mb_stripos($row['from_year'].' - '.$row['to_year'],$source['q'],0,'UTF-8')!==false)));$pages=max(1,(int)ceil(count($rows)/12));$page=(int)$source['page'];if($page>$pages)throw new DomainException('Die ursprüngliche Berichtsseite ist nicht mehr verfügbar. Eine neue Seite bewusst wählen.',422);
  $out+=['page'=>$page,'pages'=>$pages,'total'=>count($rows)];$out['rows']=array_slice($rows,($page-1)*12,12);
  if($prepare&&$out['prepare_values']===null&&!array_intersect(['q','state','page'],array_keys($query)))$out['prepare_values']=['from_year'=>(string)((int)date('Y')-1),'to_year'=>(string)((int)date('Y')-1),'supplement'=>''];
  return$out;
 }
 public static function status(DomainException $error):int
 {
  if(in_array($error->getCode(),[403,404,409,422,503],true))return$error->getCode();
  if(in_array($error->getMessage(),['Keine Berechtigung für diesen Tätigkeitsbericht.','Keine Berechtigung für den Tätigkeitsbericht.','Für diese Berichtsaktion fehlt die aktuelle Berechtigung.','Den zur Prüfung vorgelegten Bericht kann nur der 1. Vorstand ändern.','Nur der 1. Vorstand kann seine Unterschrift für diesen Bericht freigeben.','Zugang nicht aktiv.'],true))return 403;
  if($error->getMessage()==='Tätigkeitsbericht nicht verfügbar.')return 404;
  return in_array($error->getMessage(),['Der Bericht wurde inzwischen geändert. Bitte neu öffnen.','Die Tätigkeiten wurden inzwischen geändert. Bitte neu öffnen und prüfen.','Tätigkeiten inzwischen geändert. Bitte neu öffnen.','Die Tätigkeiten wurden seit der Erstellung geändert. Bericht prüfen und zuerst speichern.','Bericht, Unterschrift oder Vorstandsamt wurden geändert. Bitte die vollständige Vorschau erneut öffnen.','Das Vorstandsamt wurde geändert.','Bericht oder Quellen wurden inzwischen geändert. Erneut prüfen.','Der Bericht ist bereits freigegeben.','Der aktuelle Tätigkeitsbericht ist noch nicht freigegeben.'],true)?409:422;
 }
}
