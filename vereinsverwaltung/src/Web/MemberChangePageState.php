<?php declare(strict_types=1);
namespace Kulturhus\Verein\Web;
use DomainException;
use Kulturhus\Verein\MemberChangeRequests;

/** An own rejected proposal or decision never acquires current stored metadata. */
final class MemberChangePageState
{
 public static function read(MemberChangeRequests $service,array $query,#[\SensitiveParameter] array $post,int $status):array
 {
  $review=($query['view']??null)==='members';
  if(!($review?$service->canReview():$service->canSubmit()))throw new DomainException($review?'Keine Berechtigung zur Prüfung der Mitgliederänderungen.':'Keine Berechtigung für eigene Mitgliederänderungen.',403);
  $source=MemberChangeForm::query($query);$out=['source'=>$source,'review'=>$review,'posted'=>$post!==[],'blocked'=>false,'selected'=>null,'lookup'=>false,'editing'=>false,'values'=>null,'operation'=>null,'rows'=>[],'pending'=>[]];
  if($post!==[]){
   $command=MemberChangeForm::command($query,$post);$out['blocked']=in_array($status,[403,404,409,500,503],true);
   if($command['action']==='member-change-submit'){$out['editing']=true;$out['values']=$command['data']+['contact_id'=>$post['contact_id'],'contact_version'=>$post['contact_version'],'request_key'=>$post['request_key']];}
   else$out['operation']=$command;
   return$out;
  }
  if(isset($source['submitted_request'])){$out['lookup']=true;$out['selected']=$service->submissionResult($source['submitted_request']);return$out;}
  if($source['change']!==''){$out['selected']=$service->get($source['change'],$review);return$out;}
  $rows=$service->listing($review,$source['state'],$source['q']);$pages=max(1,(int)ceil(count($rows)/12));$page=(int)$source['page'];
  if($page>$pages)throw new DomainException('Die ursprüngliche Antragsseite ist nicht mehr verfügbar. Eine neue Seite bewusst wählen.',422);
  $out+=['page'=>$page,'pages'=>$pages,'total'=>count($rows)];$out['rows']=array_slice($rows,($page-1)*12,12);
  if(!$review){
   $out['pending']=$service->listing(false,'pending');
   if(isset($source['compose'])&&$out['pending']!==[])throw new DomainException('Es gibt bereits einen offenen Änderungsantrag. Ziehe ihn zuerst zurück, wenn du andere Angaben einreichen möchtest.',409);
   if($out['pending']===[]&&!array_intersect(['q','state','page'],array_keys($query))){
    $contact=$service->ownContact();$out['editing']=true;$out['values']=array_intersect_key($contact,MemberChangeRequests::FIELDS)+['contact_id'=>$contact['id'],'contact_version'=>(string)$contact['version'],'request_key'=>bin2hex(random_bytes(16))];
   }
  }
  return$out;
 }
 public static function status(DomainException $error):int
 {
  if(in_array($error->getCode(),[403,404,409,422,503],true))return$error->getCode();
  if(in_array($error->getMessage(),['Keine Berechtigung zur Prüfung der Mitgliederänderungen.','Keine Berechtigung für eigene Mitgliederänderungen.','Für die Prüfung sind ein aktuelles Vorstandsamt oder Superuser-Zugang sowie Mitglieder-Schreibrecht erforderlich.','Diesem Zugang ist kein eigenes Mitgliederprofil zugeordnet.','Eigene Datenänderungen sind für aktuelle Mitglieder verfügbar.','Änderungsantrag nicht verfügbar.','Mitgliederprofil nicht verfügbar.','Zugang nicht aktiv.'],true))return 403;
  return in_array($error->getMessage(),['Diese Anfrage wurde bereits mit anderen Angaben gespeichert.','Dein Profil wurde inzwischen geändert. Bitte die aktuellen Angaben neu öffnen und vergleichen.','Es gibt bereits einen offenen Änderungsantrag. Ziehe ihn zuerst zurück, wenn du andere Angaben einreichen möchtest.','Der Antrag wurde bereits bearbeitet. Bitte den aktuellen Stand öffnen.','Dieser Antrag wurde bereits bearbeitet. Bitte den aktuellen Stand öffnen.','Der Antrag wurde inzwischen geändert. Bitte neu öffnen.','Das Profil wurde inzwischen geändert. Vergleiche die Angaben und lehne den alten Antrag mit einer Begründung ab; das Mitglied kann neu einreichen.','Das Profil hat keine aktuelle Mitgliedschaft mehr. Bitte den Antrag nicht übernehmen.'],true)?409:422;
 }
}
