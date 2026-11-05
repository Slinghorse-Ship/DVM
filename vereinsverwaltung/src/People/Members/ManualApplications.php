<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use DomainException;
use PDO;
use Kulturhus\Verein\Infrastructure\Database\Transactions;

/** A paper original and its optional documented decision commit as one deliberate operation. */
final class ManualApplications
{
 public function __construct(private PDO $db,private string $actor){}
 private function lock(string $club,bool $write):void
 {
  $q=$this->db->prepare('SELECT id FROM clubs WHERE id=? FOR '.($write?'UPDATE':'SHARE').' NOWAIT');$q->execute([$club]);if(!$q->fetchColumn())throw new DomainException('Antrag nicht verfügbar.',404);
 }
 public function context():array
 {
  return $this->operation(function():array{$who=BoardAccess::require($this->db,$this->actor);$this->lock($who['club_id'],false);$who=BoardAccess::require($this->db,$this->actor);return(new MembershipApplications($this->db,$who['club_id'],$this->actor))->manualContext();});
 }
 private function operation(callable $work):array
 {
  try{return Transactions::run($this->db,$work);}
  catch(\PDOException $failure){if(in_array((int)($failure->errorInfo[1]??0),[1205,1213,3572],true))throw new DomainException('Antragserfassung wird gerade bearbeitet. Bitte erneut prüfen.',409,$failure);throw new DomainException('Antrag konnte nicht gespeichert werden. Deine Eingaben bleiben erhalten. Bitte erneut versuchen.',503,$failure);}
 }
 public function record(array $input,array $command):array
 {
  return $this->operation(function()use($input,$command):array{
   $who=BoardAccess::require($this->db,$this->actor);$this->lock($who['club_id'],true);$who=BoardAccess::require($this->db,$this->actor);$intake=new MembershipApplications($this->db,$who['club_id'],$this->actor);
   $key=$command['request_key']??null;$version=$command['version']??null;$fingerprint=$command['fingerprint']??null;
   if(!is_string($key)||!preg_match('/\A[a-f0-9]{32}\z/D',$key)||!is_int($version)||$version<0||!is_string($fingerprint)||!preg_match('/\A[a-f0-9]{64}\z/D',$fingerprint))throw new DomainException('Ursprüngliche Antragsbedingungen und Vorgangskennung prüfen.',422);
   $q=$this->db->prepare('SELECT id FROM membership_applications WHERE club_id=? AND request_key=? FOR SHARE NOWAIT');$q->execute([$who['club_id'],$key]);
   if($existing=$q->fetchColumn())return['id'=>$existing,'reused'=>true];
   $context=$intake->manualContext();if($context['version']!==$version||!hash_equals($context['fingerprint'],$fingerprint))throw new DomainException('Antragsbedingungen oder Beiträge wurden inzwischen geändert. Prüfe die aktuelle Fassung vor einer neuen Erfassung.',409);
   if(array_diff(array_keys($input),[...array_keys(MembershipApplications::FIELDS),'membership_type','family_members','family_reference']))throw new DomainException('Ursprüngliche Antragsangaben prüfen.',422);foreach($input as$value)if(!is_string($value)||preg_match('//u',$value)!==1)throw new DomainException('Ursprüngliche Antragsangaben prüfen.',422);
   foreach(['consent','external_decision','access_mail']as$field)if(!is_bool($command[$field]??null))throw new DomainException('Ursprüngliche Bestätigungen zur Antragserfassung prüfen.',422);
   foreach(['decision','review_note','number','start','existing_contact','decision_date','family_target']as$field)if(isset($command[$field])&&!is_string($command[$field]))throw new DomainException('Beschluss und ursprüngliche Antragsangaben prüfen.',422);
   $id=$intake->submit($input,$key,$version,$command['consent'],true,false,false,$command['access_mail']);
   if($command['external_decision']){$original=$intake->application($id);$intake->review($id,(int)$original['version'],$command['decision']??'',$command['review_note']??'',$command['number']??'',$command['start']??'',$command['existing_contact']??'',true,$command['decision_date']??'',$command['family_target']??'',MembershipApplications::fingerprint($original));}
   return['id'=>$id,'reused'=>false];
  });
 }
}
