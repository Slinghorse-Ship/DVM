<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use DomainException;
use PDO;
require_once __DIR__.'/BalanceSourceHistory.php';

/** Settle a carried calendar receivable/payable without recognising its invoice again. */
final class BalancePeriodInvoiceCarry
{
    public function __construct(private PDO $db,private string $actor){}
    public function original(string $entry,array $period,bool $lock):array
    {
        $period=BalancePeriod::check($period);$sources=new BalanceSources($this->db,$this->actor);$b=$sources->binding('invoice',$entry);
        if(!$b||sprintf('%04d-12-31',(int)$b['tax_year'])>=$period['start'])throw new DomainException('Zugeordnete Rechnung zuerst in diesen oder einen geprüften Vorzeitraum übernehmen.');
        $p=$sources->preview((int)$b['tax_year'],'invoice',$entry,$b['options'],$lock);
        if($p['issues']||!hash_equals($b['source_hash'],$p['hash'])||!(new BalanceSourceHistory($this->db,$this->actor,$lock))->checked($b))throw new DomainException('Ursprüngliche Kalenderrechnung oder ihr Buchungsverlauf wurde geändert.');
        $ledger=new BalancePeriodLedger($this->db,$this->actor);$r=$ledger->report(BalancePeriod::key($period),0,false);$ref=$r['previous'];$depth=0;
        if($r['issues'])throw new DomainException('Anfangsbestände aus dem geprüften Vorzeitraum zuerst abstimmen.');
        while(($ref['kind']??null)==='period'){
            if(++$depth>100)throw new DomainException('Vortragskette der Rechnung prüfen.');
            $prior=$ledger->report($ref['id'],0,false);
            if($prior['state']!=='closed'||$prior['issues']||$prior['closed_sha256']!==$ref['sha256'])throw new DomainException('Vorherigen Rechnungsstand zuerst freigeben.');
            $ref=$prior['previous'];
        }
        if(($ref['kind']??null)!=='calendar'||(int)$ref['id']<(int)$b['tax_year'])throw new DomainException('Rechnung ist nicht über einen bestätigten Kalenderabschluss vorgetragen.');
        $calendar=(new BalanceLedger($this->db,$this->actor))->report((int)$ref['id']);
        if($calendar['state']!=='closed'||$calendar['issues']||$calendar['closed_sha256']!==$ref['sha256'])throw new DomainException('Kalender-Vortrag der Rechnung ist nicht mehr freigegeben.');
        $account=$p['snapshot']['settlement_account']??null;
        if(!$account||!isset($r['accounts'][$account])||$r['accounts'][$account]!==$p['accounts'][$account])throw new DomainException('Vorgetragenes Rechnungs- oder Erstattungskonto fehlt.');
        return $p;
    }
}
