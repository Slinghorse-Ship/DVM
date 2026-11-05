<?php declare(strict_types=1);
namespace Kulturhus\Verein;

use DomainException;
use PDO;

/** Actual surplus returns settle an existing liability/receivable, with no invented profit or tax. */
final class ExcessReturnPostings
{
    public const ACCOUNTS=[
        'payable'=>['id'=>'src_excess_payables','name'=>'Rückzahlungsverbindlichkeiten aus Überzahlungen','group'=>'liabilities','category'=>'liabilities'],
        'receivable'=>['id'=>'src_excess_receivables','name'=>'Rückforderungen aus zu hohen Zahlungen','group'=>'receivables','category'=>'current'],
    ];
    private static function money(array $row):array
    {
        $out=[];foreach(['id','club_id','account_id','booked_on','counterparty','description','cents','reversal_of','request_key','fingerprint'] as $field)$out[$field]=$field==='cents'?(int)$row[$field]:$row[$field];return $out;
    }
    public static function movement(PDO $db,string $actor,string $transaction,bool $current=false):array
    {
        $f=new Finance($db,$actor);$t=$f->transactionDetail($transaction,$current);$rows=$f->excessReturnsForPosting($transaction,$current);$amount=0;$lines=[];$accounts=[];$snapshot=[];$parts=[];
        foreach($rows as $row){
            $source=$f->transactionDetail($row['source_id'],$current);$returned=$f->transactionDetail($row['returned_id'],$current);$review=$row['review'];
            if($source['reversal_of']||$source['reversed_on']||$returned['reversal_of']||$returned['reversed_on']||self::money($source)!==self::money($review['source']['transaction'])||self::money($returned)!==self::money($review['returned']))throw new DomainException('Ursprüngliche Überschusszahlung und tatsächliche Rückzahlung zuerst abstimmen.');
            $kind=(int)$source['cents']>0?'payable':'receivable';$account=self::ACCOUNTS[$kind];$cents=(int)$row['cents'];$amount+=$cents;$accounts[$account['id']]=['name'=>$account['name'],'group'=>$account['group'],'skr_code'=>''];
            $lines[]=['account'=>$account['id'],'debit_cents'=>(int)$t['cents']<0?$cents:0,'credit_cents'=>(int)$t['cents']>0?$cents:0,'sphere'=>''];
            $snapshot[]=['original'=>array_intersect_key($row,array_flip(['id','source_id','returned_id','cents','reason','created_by','created_at','request_json','request_sha256','review_json','review_sha256'])),'source'=>self::money($source),'returned'=>self::money($returned)];
            $parts[]=['id'=>$row['id'],'kind'=>$kind,'cents'=>$cents,'source_id'=>$source['id'],'returned_id'=>$returned['id'],'reason'=>$row['reason'],'receipt_ids'=>array_keys($review['source']['receipts'])];
        }
        return compact('amount','lines','accounts','snapshot','parts');
    }
}
