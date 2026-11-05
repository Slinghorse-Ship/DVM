<?php declare(strict_types=1);
namespace Kulturhus\Verein\Finance\Invoices;

use DomainException;
use Kulturhus\Verein\Settings\GeneralDraft;

final class OutgoingCorrectionDraft
{
    public static function section(string $invoice):string
    {
        if(!preg_match('/\A[a-f0-9]{32}\z/D',$invoice))throw new DomainException('Eigene ursprüngliche Rechnung auswählen.',422);
        return 'outgoing-correction.'.$invoice;
    }
    public static function open(array &$session,array $actor,array $input,bool $fresh=false):array
    {
        $input=OutgoingCorrectionInput::capture($input);return GeneralDraft::open($session,$actor,self::section($input['invoice']),$input,$fresh);
    }
    public static function get(array $session,array $actor,string $invoice,mixed $id):array
    {
        try{return GeneralDraft::get($session,$actor,self::section($invoice),$id);}catch(DomainException$failure){throw new DomainException('Eigenen ganzen Korrekturentwurf öffnen.',404,$failure);}
    }
    public static function replace(array $draft,array $input,bool $newKey=false):array
    {
        $next=GeneralDraft::replace($draft,OutgoingCorrectionInput::capture($input),$newKey);
        // An earlier canonical attempt can still commit; editing must not bypass its original key.
        if(isset($draft['legacy_request_key']))$next['key']=$draft['legacy_request_key'];return $next;
    }
    public static function store(array &$session,array $draft):void{$session['general_settings_drafts'][$draft['id']]=$draft;}
    public static function discard(array &$session,array $draft):void{GeneralDraft::discard($session,$draft);}
    public static function complete(array $draft,string $key):array{$draft['pending']=false;$draft['accepted']=$key;$draft['review']=null;$draft['previous']=[];return $draft;}
}
