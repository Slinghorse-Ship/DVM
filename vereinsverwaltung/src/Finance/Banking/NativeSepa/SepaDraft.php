<?php declare(strict_types=1);
namespace Kulturhus\Verein\Finance\Banking\NativeSepa;
use Kulturhus\Verein\Settings\GeneralDraft;

final class SepaDraft
{
    public static function revision(array $draft):string{return hash('sha256',json_encode(['key'=>$draft['key'],'input'=>$draft['input'],'review'=>$draft['review']['hash']??null,'pending'=>$draft['pending'],'accepted'=>$draft['accepted']],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));}
    public static function section(string $order):string{return 'sepa-order.'.hash('sha256',$order);}
    public static function open(array &$session,array $actor,array $input,bool $fresh=false):array{return GeneralDraft::open($session,$actor,self::section($input['order']),SepaInput::capture($input),$fresh);}
    public static function get(array $session,array $actor,string $order,mixed $id):array{return GeneralDraft::get($session,$actor,self::section($order),$id);}
    public static function store(array &$session,array $draft):void{$session['general_settings_drafts'][$draft['id']]=$draft;}
    public static function replace(array $draft,array $input,bool $newKey=false):array{return GeneralDraft::replace($draft,SepaInput::capture($input),$newKey);}
    public static function complete(array $draft,string $key):array{$draft['pending']=false;$draft['accepted']=$key;$draft['review']=null;$draft['previous']=[];return $draft;}
    public static function discard(array &$session,array $draft):void{GeneralDraft::discard($session,$draft);}
}
