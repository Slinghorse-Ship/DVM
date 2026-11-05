<?php declare(strict_types=1);
namespace Kulturhus\Verein;

use DomainException;
use PDO;

/** Gross bank movements retain their explicit settlement side in the DATEV payment batch. */
final class DatevExcessReturns
{
    public static function rows(PDO $db,string $actor,array $options):array
    {
        Permissions::finance($db,$actor);if(!Finance::excessReturnsAvailable($db))return [];
        $a=Permissions::finance($db,$actor);$codes=$options['excess_codes'];$q=$db->prepare('SELECT t.*,a.name AS account_name FROM finance_transactions t JOIN finance_accounts a ON a.club_id=t.club_id AND a.id=t.account_id WHERE t.club_id=? AND t.booked_on BETWEEN ? AND ? AND '.Finance::EXCESS_RETURN_ALLOCATED.'>0 ORDER BY t.booked_on,t.id LIMIT 10001');$q->execute([$a['club_id'],$options['from'],$options['to']]);$movements=$q->fetchAll();if(count($movements)>10000)throw new DomainException('Mehr als 10.000 Rückzahlungsbewegungen. DATEV-Zeitraum eingrenzen.');$rows=[];
        foreach($movements as $t){$proof=ExcessReturnPostings::movement($db,$actor,$t['id']);foreach($proof['parts'] as $part){
            $code=$codes[$part['kind']]??'';$bank=$options['bank_codes'][$t['account_id']]??'';$problems=[];
            if($bank==='')$problems[]='SKR42-Geldkonto fehlt';
            if($code==='')$problems[]='Überschussrückzahlung: Das mit der Buchhaltung abgestimmte Rückzahlungsverrechnungs-Konto fehlt.';
            elseif($code===$bank)$problems[]='Überschussrückzahlung: Geldkonto und Verrechnungskonto dürfen nicht identisch sein.';
            $amount=$part['cents'];$rows[]=['transaction_id'=>$t['id'],'entry_id'=>null,'payment_date'=>$t['booked_on'],'account_id'=>$t['account_id'],'account_name'=>$t['account_name'],'counterparty'=>$t['counterparty'],'receipt_number'=>'UE-'.$part['id'],'description'=>'Rückzahlungsbezug '.$part['id'],'amount_cents'=>$amount,'signed_cents'=>(int)$t['cents']>0?$amount:-$amount,'bank_code'=>$bank,'counter_code'=>$code,'booking_key'=>'','sphere'=>'','tax_rate'=>0,'catalog_year'=>(int)substr($t['booked_on'],0,4),'reversal_of'=>null,'excess_return_id'=>$part['id'],'excess_kind'=>$part['kind'],'excess_source_id'=>$part['source_id'],'excess_returned_id'=>$part['returned_id'],'export_text'=>'Rückzahlungsbezug '.$part['id'],'problems'=>$problems];
        }}
        return $rows;
    }
}
