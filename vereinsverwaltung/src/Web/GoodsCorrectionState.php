<?php declare(strict_types=1);
namespace Kulturhus\Verein\Web;
use DomainException;
use PDO;
use Kulturhus\Verein\Finance;
/** Source-specific correction controls preserve rejected facts and their original revision. */
final class GoodsCorrectionState
{
 public const ACTIONS=['goods-correction-review','goods-correction-compare','goods-correction-adopt','goods-correction-save'];
 public static function target(array $post,array $query):string
 {
  $id=$post['source_id']??null;
  if(!is_string($id)||$id===''||($query['view']??null)!=='donations'||($query['tab']??null)!=='goods'||($query['goods']??null)!==$id||($query['correct_goods']??null)!=='1')throw new DomainException('Ursprüngliche Sachspende mit ihrer Korrektur erneut öffnen.',409);
  return $id;
 }
 public static function form(array $source,array $positions,array $post,string $today):array
 {
  $submitted=in_array($post['action']??null,self::ACTIONS,true)||(array_key_exists('source_id',$post)&&array_key_exists('reason',$post));$input=$post;
  if(!$submitted){
   $input=array_intersect_key($source,array_flip(['donor','designation','description','received_on','valuation','origin']));$input['evidence_present']=(string)$source['evidence_present'];$input['request_key']=bin2hex(random_bytes(16));
   foreach(['position_description'=>'description','position_sphere'=>'sphere','position_account'=>'account_code','position_tax'=>'tax_rate','position_booking_key'=>'booking_key','position_input_tax'=>'input_tax']as$field=>$column)$input[$field]=array_map(static fn(array $row):string=>(string)$row[$column],$positions);
   $input['position_amount']=array_map(static fn(array $row):string=>intdiv((int)$row['gross_cents'],100).','.str_pad((string)((int)$row['gross_cents']%100),2,'0',STR_PAD_LEFT),$positions);
  }
  $input['action']='create-goods';$state=GoodsDonationState::form($input,$today);$state['submitted']=$submitted;
  foreach(['source_id'=>(string)$source['id'],'source_version'=>(string)$source['version'],'reason'=>'','review_hash'=>'','correction_compare_hash'=>'']as$field=>$initial){
   if(!$submitted){$state['values'][$field]=$initial;continue;}
   if(!array_key_exists($field,$post)){$state['values'][$field]='';continue;}
   if(!is_string($post[$field])){$state['unsupported'][$field]=$post[$field];$state['values'][$field]='';}else $state['values'][$field]=$post[$field];
  }
  return $state;
 }
 public static function fields(array $post):array
 {
  $id=$post['source_id']??null;$version=$post['source_version']??null;$reason=$post['reason']??null;
  if(!is_string($id)||$id===''||!is_string($version)||!preg_match('/\A[1-9][0-9]{0,9}\z/D',$version)||!is_string($reason))throw new DomainException('Ursprüngliche Sachspendenfassung und Änderungsgrund prüfen.',422);
  $asCreate=array_replace($post,['action'=>'create-goods']);GoodsDonationState::validate($asCreate);$state=GoodsDonationState::form($asCreate,'');$data=array_intersect_key($state['values'],array_flip(['donor','designation','description','received_on','valuation','origin','evidence_present']));$data['positions']=$state['positions'];
  return ['id'=>$id,'version'=>(int)$version,'data'=>$data,'reason'=>$reason,'key'=>$state['values']['request_key']];
 }
 public static function handle(PDO $db,string $actor,array &$post,array $query):array
 {
  $id=self::target($post,$query);if(!in_array($post['action']??null,self::ACTIONS,true))throw new DomainException('Angebotene Sachspendenkorrektur ausdrücklich wählen.',422);$finance=new Finance($db,$actor);if(!$finance->canWrite())throw new DomainException('Aktuelle Finanzschreibberechtigung erforderlich.',403);
  if(array_key_exists('manual_move',$post)){ManualFinanceForm::edit($db,$actor,$post);$post['review_hash']='';$post['correction_compare_hash']='';return ['edited'=>true];}
  $input=self::fields($post);$action=$post['action'];
  if($action==='goods-correction-save'){
   if(!is_string($post['review_hash']??null))throw new DomainException('Sachspendenkorrektur erneut prüfen.',422);
   return ['accepted'=>$finance->correctGoods($id,$input['version'],$input['data'],$input['reason'],$post['review_hash'],$input['key'])];
  }
  $compare=in_array($action,['goods-correction-compare','goods-correction-adopt'],true);$version=$compare?(int)$finance->currentPositionState('goods',$id)['source']['version']:$input['version'];
  $preview=$finance->reviewGoodsCorrection($id,$version,$input['data'],$input['reason']);$hash=hash('sha256',json_encode([$input,$preview],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));
  if($action==='goods-correction-adopt'){
   if(!is_string($post['correction_compare_hash']??null)||!hash_equals($hash,$post['correction_compare_hash']))throw new DomainException('Sachspende oder Korrektur seit der Prüfung geändert. Erneut vergleichen.',409);
   $post['source_version']=(string)$preview['source']['version'];$post['review_hash']=$preview['hash'];
  }elseif(!$compare)$post['review_hash']=$preview['hash'];else $post['review_hash']='';
  return ['preview'=>$preview,'comparison'=>$compare,'adopted'=>$action==='goods-correction-adopt','original_version'=>$input['version'],'hash'=>$hash];
 }
}
