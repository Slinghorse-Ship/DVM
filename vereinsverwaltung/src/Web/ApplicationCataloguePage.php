<?php declare(strict_types=1);
namespace Kulturhus\Verein\Web;
use Kulturhus\Verein\{BoardAccess,MembershipApplications,MembershipApplicationCatalogue};
final class ApplicationCataloguePage
{
 public static function applies(array $query,array $post=[]):bool{return($query['view']??null)==='portal'&&($query['tab']??null)==='application'&&!isset($query['manual'])&&!isset($query['configure'])&&($post['action']??'')==='';}
 public static function prepare(\PDO $db,string $actor,array $query):array
 {
  $state=['query'=>$query['applications_q']??'','filter'=>$query['status']??'pending','number'=>$query['applications_page']??'1','focus'=>$query['application']??'','page'=>null,'selected'=>null,'error'=>'','status'=>200];
  try{
   $authority=BoardAccess::require($db,$actor);
   if(!is_string($state['focus'])||strlen($state['focus'])>100)throw new \DomainException('Antrag nicht verfügbar.',404);
   if($state['focus']!=='')$state['selected']=(new MembershipApplications($db,$authority['club_id'],$actor))->application($state['focus']);
   else$state['page']=(new MembershipApplicationCatalogue($db,$actor))->page($query);
  }catch(\DomainException $failure){$state['error']=$failure->getMessage();$state['status']=$failure->getMessage()==='Dieser Bereich ist nur für den aktuellen Vorstand und Superuser freigegeben.'?403:($failure->getMessage()==='Antrag nicht verfügbar.'?404:(in_array($failure->getCode(),[403,404,409,422,503],true)?$failure->getCode():422));}
  return$state;
 }
}
