<?php declare(strict_types=1);
namespace Kulturhus\Verein\Events\Calendar;

use Kulturhus\Verein\Inventory;

/** Planning dates from the canonical loan service, without calendar copies. */
final class LoanSource
{
    public function __construct(private Inventory $inventory) {}

    public function available(): bool
    {
        return $this->inventory->canReadLoans();
    }

    public function rows(string $from, string $to): array
    {
        if (!$this->available()) {
            return [];
        }
        $rows = [];
        $zone=new \DateTimeZone('Europe/Berlin');$utc=new \DateTimeZone('UTC');
        $fromUtc=(new \DateTimeImmutable($from,$zone))->setTimezone($utc)->format('Y-m-d H:i:s');
        $toUtc=(new \DateTimeImmutable($to,$zone))->setTimezone($utc)->format('Y-m-d H:i:s');
        foreach ($this->inventory->loans("open") as $loan) {
            if(!empty($loan['window_start'])){
                if($loan['window_start']>=$toUtc||$loan['window_end']<=$fromUtc)continue;
                $entry=Entry::dated('loan:'.$loan['id'].':window','Ausleihe: '.Inventory::number((int)$loan['number']).' · '.$loan['purpose'],$loan['window_start'],'loans',['view'=>'inventory','tab'=>'loans','loan'=>$loan['id']],false,Inventory::loanStateLabel($loan));
                $entry['ends_at']=(new \DateTimeImmutable($loan['window_end'],new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');$entry['end_known']=true;$rows[]=$entry;continue;
            }
            $dates = [];
            if ($loan["state"] === "reserved") {
                $dates[$loan["starts_on"]][] = "Abholung";
            }
            $dates[$loan["due_on"]][] = "Rückgabe";
            foreach ($dates as $date => $labels) {
                if ($date < $from || $date >= $to) {
                    continue;
                }
                $rows[] = Entry::dated(
                    "loan:" . $loan["id"] . ":" . $date,
                    implode(" / ", $labels) .
                        ": " .
                        Inventory::number((int) $loan["number"]) .
                        " · " .
                        $loan["purpose"],
                    $date,
                    "loans",
                    [
                        "view" => "inventory",
                        "tab" => "loans",
                        "loan" => $loan["id"],
                    ],
                    true,
                    Inventory::STATES[$loan["state"]],
                );
            }
        }
        return $rows;
    }
}
