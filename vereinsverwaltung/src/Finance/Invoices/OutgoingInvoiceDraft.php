<?php declare(strict_types=1);
namespace Kulturhus\Verein\Finance\Invoices;

use DomainException;
use Kulturhus\Verein\Settings\GeneralDraft;

/** Existing personal draft storage, including a separate slot for a new invoice. */
final class OutgoingInvoiceDraft
{
    public static function section(string $invoice): string
    {
        if ($invoice!==''&&!preg_match('/\A[a-f0-9]{32}\z/D',$invoice)) {
            throw new DomainException('Eigene ganze Rechnung auswählen.',422);
        }
        return 'outgoing-invoice.'.($invoice===''?'new':$invoice);
    }

    public static function open(array &$session,array $actor,array $input,bool $fresh=false): array
    {
        $input=OutgoingInvoiceInput::capture($input);$section=self::section($input['invoice']);
        $slot=hash('sha256',$actor['club_id']."\0".$actor['id']."\0".$section);$id=$session['general_settings_slots'][$slot]??null;
        if(!$fresh&&is_string($id)&&isset($session['general_settings_drafts'][$id])){
            $old=GeneralDraft::get($session,$actor,$section,$id);
            if($old['accepted']!==null)$fresh=true;
        }
        return GeneralDraft::open($session,$actor,$section,$input,$fresh);
    }

    public static function get(array $session,array $actor,string $invoice,mixed $id): array
    {
        try { return GeneralDraft::get($session,$actor,self::section($invoice),$id); }
        catch (DomainException $failure) { throw new DomainException('Eigenen ganzen Rechnungsentwurf öffnen.',404,$failure); }
    }

    public static function store(array &$session,array $draft): void
    {
        $session['general_settings_drafts'][$draft['id']]=$draft;
    }

    public static function replace(array $draft,array $input,bool $newKey=false): array
    {
        return GeneralDraft::replace($draft,OutgoingInvoiceInput::capture($input),$newKey);
    }

    public static function recoverCreation(array &$session,array $actor,array $draft,array $outcome): array
    {
        $input=$draft['input'];$input['invoice']=$outcome['invoice']['id'];$input['version']=(string)$outcome['original']['version'];
        $next=self::open($session,$actor,$input,true);
        $next['legacy_creation']=['request_key'=>$draft['legacy_request_key'],'input'=>$draft['input'],
            'invoice'=>$outcome['invoice']['id'],'created_at'=>$outcome['original']['happened_at']];
        $next['conflicted']=$outcome['invoice']['state']!=='draft'||(string)$outcome['invoice']['version']!==$input['version'];
        self::store($session,$next);self::discard($session,$draft);
        return $next;
    }

    public static function complete(array $draft,string $key): array
    {
        $draft['pending']=false;$draft['accepted']=$key;$draft['review']=null;$draft['previous']=[];
        return $draft;
    }

    public static function discard(array &$session,array $draft): void
    {
        GeneralDraft::discard($session,$draft);
    }
}
