<?php declare(strict_types=1);
namespace Kulturhus\Verein\Finance\Invoices;

use DomainException;
use Kulturhus\Verein\Settings\GeneralDraft;

/** Reuse the existing personal draft store, with one slot per issued invoice. */
final class OutgoingMailDraft
{
    public static function section(string $invoice):string
    {
        if(!preg_match('/\A[a-f0-9]{32}\z/D',$invoice))throw new DomainException('Ausgestellte eigene Rechnung auswählen.',422);
        return 'outgoing-mail.'.$invoice;
    }
    public static function open(array &$session,array $actor,array $input,bool $fresh=false):array
    {
        return GeneralDraft::open($session,$actor,self::section($input['invoice']),OutgoingMailInput::capture($input),$fresh);
    }
    public static function get(array $session,array $actor,string $invoice,mixed $id):array
    {
        try{return GeneralDraft::get($session,$actor,self::section($invoice),$id);}
        catch(DomainException $failure){throw new DomainException('Eigenen vollständigen Rechnungs-Mailentwurf öffnen.',404,$failure);}
    }
    public static function store(array &$session,array $draft):void{$session['general_settings_drafts'][$draft['id']]=$draft;}
    public static function complete(array $draft,string $key):array
    {
        $draft['pending']=false;$draft['accepted']=$key;$draft['review']=null;$draft['previous']=[];return $draft;
    }
    public static function replace(array $draft,array $input,bool $newKey=false):array{return GeneralDraft::replace($draft,OutgoingMailInput::capture($input),$newKey);}
    public static function discard(array &$session,array $draft):void{GeneralDraft::discard($session,$draft);}
}
