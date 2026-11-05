<?php declare(strict_types=1);
namespace Kulturhus\Verein\Web;
use DomainException;
use Kulturhus\Verein\PaymentTags;

/** Original submitted metadata remains separate from current payment/catalogue versions. */
final class PaymentTagForm
{
 public static function scalar(array$body,string$key):string{if(!is_string($body[$key]??null))throw new DomainException('Ursprüngliche Zahlungstag-Angaben prüfen.',422);return$body[$key];}
 public static function version(array$body,string$key):int{$v=self::scalar($body,$key);if(!preg_match('/\A(?:0|[1-9][0-9]{0,8})\z/D',$v))throw new DomainException('Ursprüngliche Tagfassung prüfen.',422);return(int)$v;}
 public static function choices(array$body):array{
  $choices=$body['tag_choices']??[];if(!is_array($choices)||count($choices)>PaymentTags::MAX_SELECTION)throw new DomainException('Ursprüngliche Zahlungstag-Auswahl prüfen.',422);$out=[];
  foreach($choices as$id=>$version){if(!is_string($id)||!preg_match('/\A[a-f0-9]{32}\z/D',$id)||!is_string($version)||!preg_match('/\A[1-9][0-9]{0,8}\z/D',$version))throw new DomainException('Ursprüngliche Zahlungstag-Auswahl prüfen.',422);$out[$id]=(int)$version;}return$out;
 }
 public static function handle(PaymentTags$tags,array$query,array$body):?array{
  $id=self::scalar($body,'transaction_id');if(($query['view']??null)!=='finance'||($query['tab']??null)!=='bank'||($query['transaction']??null)!==$id)throw new DomainException('Ursprünglichen Kontoumsatz erneut öffnen.',409);
  $tags->state($id);
  if(($body['tag_move']??null)==='compare')return null;
  $action=self::scalar($body,'action');$key=self::scalar($body,'tag_key');$version=self::version($body,'tag_version');
  if($action==='payment-tags-assign')return$tags->assign($id,$version,self::choices($body),$key);
  if($action!=='payment-tags-save')throw new DomainException('Ursprüngliche Zahlungstag-Angaben prüfen.',422);
  $active=self::scalar($body,'tag_active');if(!in_array($active,['0','1'],true))throw new DomainException('Ursprüngliche Zahlungstag-Angaben prüfen.',422);
  $tag=self::scalar($body,'tag_id');return$tags->saveTag($tag===''?null:$tag,$version,self::scalar($body,'tag_name'),$active==='1',$key);
 }
}
