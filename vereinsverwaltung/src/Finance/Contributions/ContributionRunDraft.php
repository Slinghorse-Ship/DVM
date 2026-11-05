<?php declare(strict_types=1);
namespace Kulturhus\Verein\Finance\Contributions;
use Kulturhus\Verein\Settings\GeneralDraft;

final class ContributionRunDraft
{
    public static function open(array &$session,array $actor,array $input,bool $fresh=false):array{return GeneralDraft::open($session,$actor,ContributionRunRequests::SECTION,ContributionRunInput::capture($input),$fresh);}
    public static function get(array $session,array $actor,mixed $id):array{return GeneralDraft::get($session,$actor,ContributionRunRequests::SECTION,$id);}
    public static function replace(array $draft,array $input,bool $newKey=false):array{return GeneralDraft::replace($draft,ContributionRunInput::capture($input),$newKey);}
    public static function store(array &$session,array $draft):void{$session['general_settings_drafts'][$draft['id']]=$draft;}
    public static function discard(array &$session,array $draft):void{GeneralDraft::discard($session,$draft);}
    public static function complete(array $draft,string $key):array{$draft['pending']=false;$draft['accepted']=$key;$draft['review']=null;$draft['previous']=[];return $draft;}
}
