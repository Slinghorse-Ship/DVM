<?php declare(strict_types=1);
namespace Kulturhus\Verein\Finance\Contributions;
use Kulturhus\Verein\Settings\GeneralDraft;

final class ContributionDeliveryDraft
{
    public static function section(string $job):string{return 'contribution-delivery.'.hash('sha256',$job);}
    public static function open(array &$session,array $actor,array $input,bool $fresh=false):array{return GeneralDraft::open($session,$actor,self::section($input['job']),ContributionDeliveryInput::capture($input),$fresh);}
    public static function get(array $session,array $actor,string $job,mixed $id):array{return GeneralDraft::get($session,$actor,self::section($job),$id);}
    public static function store(array &$session,array $draft):void{$session['general_settings_drafts'][$draft['id']]=$draft;}
    public static function replace(array $draft,array $input,bool $newKey=false):array{return GeneralDraft::replace($draft,ContributionDeliveryInput::capture($input),$newKey);}
    public static function complete(array $draft,string $key):array{$draft['pending']=false;$draft['accepted']=$key;$draft['review']=null;$draft['previous']=[];return $draft;}
    public static function discard(array &$session,array $draft):void{GeneralDraft::discard($session,$draft);}
}
