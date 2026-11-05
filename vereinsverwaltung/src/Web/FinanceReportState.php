<?php declare(strict_types=1);
namespace Kulturhus\Verein\Web;
use DomainException;
use Kulturhus\Verein\FinanceReports;

/** Read the existing report model and retain rejected filters without inventing results. */
final class FinanceReportState
{
 public static function read(FinanceReports $reports,array $query,string $year):array
 {
  $reports->availableTags();
  $defaults=['report_type'=>'income','report_display'=>'table','from'=>$year.'-01-01','to'=>$year.'-12-31','resolution'=>'month','compare'=>'0','account'=>''];$values=[];$unsupported=[];
  foreach($defaults as$key=>$default){
   if(!array_key_exists($key,$query)){$values[$key]=$default;continue;}
   if(!is_string($query[$key])){$unsupported[$key]=$query[$key];$values[$key]='';continue;}
   $values[$key]=$query[$key];
  }
  $tags=array_key_exists('tags',$query)?$query['tags']:[];
  try{
   if($unsupported)throw new DomainException('Ursprüngliche Auswertungsfilter prüfen.',422);
   foreach($values as$value)if(!mb_check_encoding($value,'UTF-8')||str_contains($value,"\0"))throw new DomainException('Ursprüngliche Auswertungsfilter prüfen.',422);
   if(!in_array($values['report_type'],['income','journal'],true))throw new DomainException('Bitte eine verfügbare Auswertung auswählen.',422);
   if(!in_array($values['report_display'],['table','chart'],true))throw new DomainException('Darstellung der Auswertung ausdrücklich wählen.',422);
   if($tags!==''&&!is_array($tags))throw new DomainException('Ursprüngliche Zahlungstag-Auswahl prüfen.',422);
   $input=array_intersect_key($values,array_flip(['from','to','resolution','compare','account']))+['tags'=>$tags];
   $report=$reports->interfaceRead($values['report_type'],$input);
   return ['available'=>true,'http_status'=>200,'values'=>$values,'unsupported'=>[],'original_tags'=>$tags,'error'=>'','report'=>$report];
  }catch(DomainException$failure){
   return ['available'=>false,'http_status'=>in_array($failure->getCode(),[403,404,409,503],true)?$failure->getCode():422,'values'=>$values,'unsupported'=>$unsupported,'original_tags'=>$tags,'error'=>$failure->getMessage(),'report'=>null];
  }
 }
}
