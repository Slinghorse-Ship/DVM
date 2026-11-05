<?php declare(strict_types=1);
use Kulturhus\Verein\{BalanceSegmentCalculation as TransferS,BalanceCalculation as TransferC};
/** Synthetic evidence-backed purchase funded by another internal area. */
function internalTransferFixture(bool $taxOnly=false,bool $reverse=false):array
{
    $state=clubTaxFixture();$a=$state['allocation'];$r=$state['journal'];$alloc=$a['allocations'];$voucher=str_repeat('a',32);
    $alloc[$voucher.':1']=['parts'=>[$taxOnly?'food':'club'=>1003],'reason'=>'Synthetic payment from funding area.'];
    $transfers=[$voucher=>['account'=>'equity','parts'=>TransferS::transferParts($r,$a['units'],$alloc,$voucher),'reason'=>'Synthetic final allocation of funds; approved internal evidence.']];
    if($reverse){
        $entry=$r['entries'][5];$entry['id']=str_repeat('b',32);$entry['date']='2025-03-02';$entry['description']='Synthetic reversal';
        foreach($entry['lines'] as &$line)[$line['debit_cents'],$line['credit_cents']]=[$line['credit_cents'],$line['debit_cents']];unset($line);
        $r['entries'][]=$entry;$r['calculation']=TransferC::build(2025,$r['accounts'],$r['opening'],$r['entries']);
        for($i=0;$i<2;$i++)$alloc[$entry['id'].':'.$i]=$alloc[$voucher.':'.$i];
        $transfers[$entry['id']]=['account'=>'equity','parts'=>TransferS::transferParts($r,$a['units'],$alloc,$entry['id']),'reason'=>'Synthetic reversal of internal funding.'];
    }
    return clubTaxState($r,$a['units'],$alloc,2,[],$transfers);
}
