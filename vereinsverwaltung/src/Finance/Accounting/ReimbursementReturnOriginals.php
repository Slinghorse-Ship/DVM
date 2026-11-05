<?php declare(strict_types=1);
namespace Kulturhus\Verein;

use DomainException;
use PDO;

/** Validate private claim supplements without changing earlier financial first formats. */
final class ReimbursementReturnOriginals
{
    private static function failure():never{throw new DomainException('Ursprünglichen Erstattungsrückzahlungsnachweis prüfen lassen.',503);}
    private static function source(mixed $value):array
    {
        if(!is_array($value)||array_keys($value)!==['entry','claim_id','entry_version','claim_version']||!is_string($value['entry'])||$value['entry']===''||!is_string($value['claim_id'])||$value['claim_id']===''||!is_int($value['entry_version'])||$value['entry_version']<1||!is_int($value['claim_version'])||$value['claim_version']<1)self::failure();return$value;
    }
    private static function claims(array $source,mixed $before,mixed $after,bool $void=false):void
    {
        if(!is_array($before)||!is_array($after)||($before['id']??null)!==$source['claim_id']||($before['entry_id']??null)!==$source['entry']||($before['version']??null)!==$source['claim_version']||!is_int($before['amount_cents']??null)||$before['amount_cents']<1||!in_array($before['state']??null,['open','approved','paid'],true))self::failure();
        $state=$void?($after['state']??null):'open';$pointer=$after['paid_transaction_id']??null;
        if(!in_array($state,$void?['open','paid']:['open'],true)||($state==='paid'?(!is_string($pointer)||$pointer===''):$pointer!==null))self::failure();
        $expected=array_replace($before,['state'=>$state,'version'=>$source['claim_version']+1,'approved_by'=>null,'approved_at'=>null,'paid_transaction_id'=>$pointer]);
        if($after!==$expected)self::failure();
    }
    public static function verify(PDO $db,array $first,bool $current=false):void
    {
        $r=$first['request'];$v=$first['review'];$s=$first['result'];$has=array_key_exists('reimbursement',$r)||array_key_exists('reimbursement',$v)||array_key_exists('reimbursement',$s);
        if(!$has){if($first['void']!==null&&(isset($first['void']['request']['reimbursement'])||isset($first['void']['review']['reimbursement'])||isset($first['void']['result']['reimbursement'])))self::failure();return;}
        if(isset($r['native_debit'])||isset($v['native_debit'])||isset($s['native_debit']))self::failure();
        $source=self::source($r['reimbursement']??null);if(($v['reimbursement']['source']??null)!==$source||($s['reimbursement']['source']??null)!==$source)self::failure();self::claims($source,$v['reimbursement']['claim']??null,$s['reimbursement']['claim']??null);
        foreach($first['items']as$item)if($item['entry_id']!==$source['entry'])self::failure();
        foreach($v['selected']as$item)if(($item['entry']['id']??null)!==$source['entry']||($item['entry']['version']??null)!==$source['entry_version']||($item['entry']['amount_cents']??null)!==$v['reimbursement']['claim']['amount_cents'])self::failure();
        $lock=$current?' FOR SHARE NOWAIT':'';$q=$db->prepare('SELECT id,entry_id FROM finance_reimbursement_claims WHERE club_id=? AND id=?'.$lock);$q->execute([$first['club_id'],$source['claim_id']]);$claim=$q->fetch();if(!$claim||$claim['id']!==$source['claim_id']||$claim['entry_id']!==$source['entry'])self::failure();
        if($first['void']!==null){$void=$first['void'];$vs=self::source($void['request']['reimbursement']??null);if($vs['entry']!==$source['entry']||$vs['claim_id']!==$source['claim_id']||($void['review']['reimbursement']['source']??null)!==$vs||($void['result']['reimbursement']['source']??null)!==$vs)self::failure();$before=$void['review']['reimbursement']['claim']??null;$after=$void['result']['reimbursement']['claim']??null;self::claims($vs,$before,$after,true);foreach(['contact_id','amount_cents','invoice_recipient']as$field)if(($before[$field]??null)!==($v['reimbursement']['claim'][$field]??null))self::failure();
            if(($void['review']['entries'][$source['entry']]['version']??null)!==$vs['entry_version'])self::failure();
            try{$restored=PaymentFundingRanges::cents($void['review']['restored_ranges'][$source['entry']],(int)$before['amount_cents']);}catch(\Throwable){self::failure();}if($after['state']!==($restored===$before['amount_cents']?'paid':'open'))self::failure();
            if($after['state']==='paid'){$q=$db->prepare('SELECT id FROM finance_allocations WHERE club_id=? AND entry_id=? AND transaction_id=? LIMIT 1'.$lock);$q->execute([$first['club_id'],$source['entry'],$after['paid_transaction_id']]);if($q->fetchColumn()===false)self::failure();}
        }
    }
}
