<?php declare(strict_types=1);
namespace Kulturhus\Verein\Finance\Banking\NativeTransfer;

use DomainException;
use Kulturhus\Verein\Settings\GeneralDraft;

/** Both payment choices share one idempotency key and the exact displayed source. */
final class PaymentForm
{
    private static function section(string $entry):string{return 'invoice-payment.'.hash('sha256',$entry);}
    public static function open(array &$session,array $actor,string $entry,array $reviews):array
    {
        $choices=[];foreach($reviews as$mode=>$review){
            if(!in_array($mode,['standard','instant'],true)||($review['input']['entry']??null)!==$entry||!in_array($review['input']['operation']??null,['pay','approve'],true))throw new DomainException('Zahlung neu öffnen.',422);
            $choices[$mode]=['input'=>$review['input'],'hash'=>$review['hash']];
        }
        if($choices===[])throw new DomainException('Zahlung neu öffnen.',422);
        $input=['entry'=>$entry,'choices'=>$choices];$section=self::section($entry);$slot=hash('sha256',$actor['club_id']."\0".$actor['id']."\0".$section);$stored=$session['general_settings_slots'][$slot]??null;
        if(is_string($stored)&&isset($session['general_settings_drafts'][$stored])){
            $old=GeneralDraft::get($session,$actor,$section,$stored);
            if($old['input']===$input&&$old['accepted']===null)return $old;
        }
        return GeneralDraft::open($session,$actor,$section,$input,true);
    }
    public static function get(array $session,array $actor,string $entry,mixed $id,mixed $mode):array
    {
        $draft=GeneralDraft::get($session,$actor,self::section($entry),$id);
        if(!is_string($mode)||!in_array($mode,['standard','instant'],true)||!isset($draft['input']['choices'][$mode]))throw new DomainException('Normale Überweisung oder Echtzeitüberweisung auswählen.',422);
        return ['draft'=>$draft,'choice'=>$draft['input']['choices'][$mode]];
    }
    public static function complete(array &$session,array $draft,string $key):void
    {
        $draft['accepted']=$key;$session['general_settings_drafts'][$draft['id']]=$draft;
    }
}
