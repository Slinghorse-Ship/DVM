<?php declare(strict_types=1);
namespace Kulturhus\Verein;

use DomainException;
use PDO;

/** Invoice VAT stays original evidence; a private recipient cannot establish club input VAT. */
final class InvoiceRecipientTax
{
    public const MESSAGE='Bei einer privat vorgestreckten Rechnung an ein Mitglied, eine andere Person oder einen noch ungeklärten Empfänger ist kein Vorsteuerabzug möglich. Prüfe jede Position: Kein Vorsteuerabzug wählen und den BU-Schlüssel leeren. Steuersatz und Bruttobetrag laut Original bleiben erhalten.';

    public static function assertPositions(string $recipient,array $rows):void
    {
        if($recipient==='club')return;
        if(!isset(ExpenseReimbursements::RECIPIENTS[$recipient]))throw new DomainException('Rechnungsempfänger prüfen.',422);
        foreach($rows as$index=>$row){
            if(!is_array($row)||(($row['input_tax']??'unknown')==='deductible'&&((int)($row['tax_rate']??0)>0||(int)($row['tax_cents']??0)>0))||($row['booking_key']??'')!=='')
                throw new DomainException('Position '.($index+1).': '.self::MESSAGE,422);
        }
    }

    /** Missing legacy treatment gets the known legal restriction, never the club's deductible default. */
    public static function prepareInvoice(array $data):array
    {
        if(($data['payment_source']??'none')!=='private_advance'||($data['invoice_recipient']??'club')==='club'||($data['use_invoice_positions']??'')!=='1')return$data;
        $rows=$data['invoice_positions']??null;
        if(!is_array($rows)||!array_is_list($rows))throw new DomainException('Positionsangaben prüfen.',422);
        foreach($rows as&$row)if(is_array($row)&&!array_key_exists('input_tax',$row))$row['input_tax']='none';
        unset($row);self::assertPositions((string)($data['invoice_recipient']??''),$rows);$data['invoice_positions']=$rows;
        return$data;
    }

    /** Called inside the original entry/claim transaction, before a new recipient decision. */
    public static function assertEntry(PDO $db,string $actor,string $entry,string $recipient):void
    {
        if($recipient==='club')return;
        if(!$db->inTransaction())throw new DomainException('Vorsteuer und Rechnungsempfänger gemeinsam prüfen.',422);
        $who=Permissions::finance($db,$actor);
        $q=$db->prepare('SELECT input_tax,booking_key,tax_rate,tax_cents FROM accounting_positions WHERE club_id=? AND entry_id=? ORDER BY ordinal FOR SHARE NOWAIT');$q->execute([$who['club_id'],$entry]);
        self::assertPositions($recipient,$q->fetchAll(PDO::FETCH_ASSOC));
    }

    /** A later accounting edit cannot bypass the already recorded invoice recipient. */
    public static function assertExisting(PDO $db,string $actor,string $type,string $entry,array $rows):void
    {
        if($type!=='entry')return;
        if(!$db->inTransaction())throw new DomainException('Vorsteuer und Rechnungsempfänger gemeinsam prüfen.',422);
        $who=Permissions::finance($db,$actor);$q=$db->prepare('SELECT invoice_recipient FROM finance_reimbursement_claims WHERE club_id=? AND entry_id=? FOR SHARE NOWAIT');$q->execute([$who['club_id'],$entry]);$recipient=$q->fetchColumn();
        if($recipient!==false)self::assertPositions((string)$recipient,$rows);
    }
}
