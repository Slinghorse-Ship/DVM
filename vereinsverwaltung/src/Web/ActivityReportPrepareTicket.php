<?php declare(strict_types=1);
namespace Kulturhus\Verein\Web;
use DomainException;
use PDO;
use Kulturhus\Verein\{ActivityReportReview,Permissions};
/** A form's original period identities and versions remain in its own authenticated session. */
final class ActivityReportPrepareTicket
{
 public static function issue(PDO $db,string $actor,string $kind,array &$session):string
 {
  ActivityReportForm::kind($kind);$reports=new ActivityReportReview($db,$actor,null,$kind);if(!$reports->canPrepare())throw new DomainException('Für diese Berichtsaktion fehlt die aktuelle Berechtigung.',403);$current=Permissions::actor($db,$actor);$periods=[];
  foreach($reports->listing()as$row)$periods[$row['from_year'].'-'.$row['to_year']]=['id'=>$row['id'],'version'=>(int)$row['version']];
  $records=is_array($session['activity_report_preparations']??null)?$session['activity_report_preparations']:[];$records=array_filter($records,static fn($row):bool=>is_array($row)&&is_int($row['expires']??null)&&$row['expires']>=time());$token=bin2hex(random_bytes(16));$records[$token]=['actor'=>$actor,'club_id'=>$current['club_id'],'kind'=>$kind,'periods'=>$periods,'expires'=>time()+1800];$session['activity_report_preparations']=array_slice($records,-8,null,true);return$token;
 }
 public static function expected(PDO $db,string $actor,string $kind,string $token,int $from,int $to,array $session):array
 {
  ActivityReportForm::kind($kind);ActivityReportForm::id($token);$reports=new ActivityReportReview($db,$actor,null,$kind);if(!$reports->canPrepare())throw new DomainException('Für diese Berichtsaktion fehlt die aktuelle Berechtigung.',403);$current=Permissions::actor($db,$actor);$records=is_array($session['activity_report_preparations']??null)?$session['activity_report_preparations']:[];$record=$records[$token]??null;
  if(!is_array($record)||($record['actor']??null)!==$actor||($record['club_id']??null)!==$current['club_id']||($record['kind']??null)!==$kind||!is_int($record['expires']??null)||$record['expires']<time()||!is_array($record['periods']??null))throw self::unavailable();
  $period=$record['periods'][$from.'-'.$to]??null;if($period===null)return['club_id'=>$record['club_id'],'id'=>'','version'=>0];
  if(!is_array($period)||!is_string($period['id']??null)||!preg_match('/\A[a-f0-9]{32}\z/D',$period['id'])||!is_int($period['version']??null)||$period['version']<1||$period['version']>2147483646)throw self::unavailable();
  return['club_id'=>$record['club_id'],'id'=>$period['id'],'version'=>$period['version']];
 }
 private static function unavailable():DomainException{return new DomainException('Die ursprüngliche Berichtsvorbereitung ist nicht mehr verfügbar. Aktuellen Zeitraum getrennt prüfen und ursprüngliche Angaben behalten.',409);}
}
