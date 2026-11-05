<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use DomainException;
require_once __DIR__.'/AssetValuation.php';

/** Original gross book entries and separately confirmed fiscal positions.
 * A pair is an individually evidenced eligible arrangement, never an account-wide guess.
 * Initial bounded asset scope: segregated bank deposits, without an invented asset grid.
 */
final class OriginalPensionOffsets
{
    public const CODES=['13810','13830','30090','30770','71440','71450','73640','73650'];
    public const PAIRS=['13810'=>'30090','13830'=>'30770'];
    public const GROUPS=['13810'=>'other_assets','13830'=>'other_assets','30090'=>'provisions','30770'=>'provisions','71440'=>'revenue','71450'=>'revenue','73640'=>'expense','73650'=>'expense'];
    public const SURPLUS='bs.ass.SurplusFromOffsetting';
    public const PENSION='bs.eqLiab.accruals.pensions.direct';
    public const OTHER='bs.eqLiab.accruals.other.other';
    public const INTEREST='is.netIncome.regular.fin.netInterest.expenses.offsetting';
    public const FIELDS=['opening_cents','debit_cents','credit_cents','closing_cents','amount_cents'];
    public static function need(bool $ok):void{if(!$ok)throw new DomainException('Pensionsverrechnung: einzelne Vereinbarung, geschütztes Bankvermögen, getrennte Originalnachweise und unabhängig bestätigte steuerliche Bruttowerte prüfen.');}
    public static function active(array $rows):array
    {
        return array_filter($rows,static function($row):bool{
            if(!in_array($row['skr_code']??null,self::CODES,true))return false;
            foreach(self::FIELDS as $f)if(($row[$f]??null)!==0)return true;
            return false;
        });
    }
    public static function target(string $code):string
    {
        return match($code){'13810','13830'=>self::SURPLUS,'30090'=>self::PENSION,'30770'=>self::OTHER,'71440','71450','73640','73650'=>self::INTEREST,default=>throw new DomainException('Unbekanntes Verrechnungskonto.')};
    }
    public static function rule(array $r):void
    {
        $ref=$r['pension_offset_contract']??null;$code=is_string($ref)?substr($ref,0,5):'';
        self::need(isset(self::GROUPS[$code])&&$ref===$code.'@1'&&($r['group']??null)===self::GROUPS[$code]
            &&($r['condition']??null)==='detail_choice'&&($r['target']??null)===self::target($code)
            &&($r['allowed_targets']??null)===[self::target($code)]&&($r['book_only']??null)===true
            &&empty($r['disclosures'])&&!isset($r['commercial_contract']));
        if(isset($r['codes']))self::need($r['codes']===[$code]);
    }
    private static function row(array $rows,string $key):array
    {
        self::need(isset($rows[$key])&&is_array($rows[$key]));$a=$rows[$key];
        foreach(self::FIELDS as $f)self::need(is_int($a[$f]??null)&&abs($a[$f])<=BalanceCalculation::LIMIT);
        self::need($a['debit_cents']>=0&&$a['credit_cents']>=0&&$a['closing_cents']===$a['opening_cents']+$a['debit_cents']-$a['credit_cents']);
        self::need($a['amount_cents']===(in_array($a['group'],['provisions','revenue'],true)?-$a['closing_cents']:$a['closing_cents']));
        return $a;
    }
    /** Bind selectors to the original ledger; the form cannot supply replacement book amounts. */
    public static function bind(array $report,array $input,array $book,bool $fiscal=true):array
    {
        self::need(!isset($report['period'])&&!isset($report['club_scope'])&&($report['state']??null)==='closed'
            &&in_array($report['year']??null,[2025,2026],true)&&($report['previous_sha256']??null)===null);
        self::need(array_keys($input)===['schema','year','journal_sha256','pairs','tax_positions','book_document','tax_document','book_reviewed','tax_reviewed']
            &&$input['schema']===1&&$input['year']===$report['year']&&$input['journal_sha256']===$report['closed_sha256']
            &&$input['book_reviewed']===true&&$input['tax_reviewed']===$fiscal&&is_array($input['pairs'])
            &&array_is_list($input['pairs'])&&count($input['pairs'])>=1&&count($input['pairs'])<=20&&is_array($input['tax_positions']));
        AssetValuation::document($input['book_document']);
        if($fiscal)AssetValuation::document($input['tax_document']);else self::need($input['tax_document']===null&&$input['tax_positions']===[]);
        $rows=$book['accounts'];$active=self::active($rows);self::need((bool)$active);
        $covered=[];$facts=[];$offset=0;$original=[];
        foreach($input['pairs'] as $pair){
            self::need(is_array($pair)&&array_keys($pair)===['asset','provision','movements','obligation_kind','exclusive_bank_deposit','creditor_protected','sole_purpose','netting_reviewed']
                &&is_string($pair['asset'])&&is_string($pair['provision'])&&is_array($pair['movements'])&&array_is_list($pair['movements'])
                &&$pair['exclusive_bank_deposit']===true&&$pair['creditor_protected']===true&&$pair['sole_purpose']===true&&$pair['netting_reviewed']===true);
            $asset=self::row($rows,$pair['asset']);$provision=self::row($rows,$pair['provision']);
            self::need(isset(self::PAIRS[$asset['skr_code']??''])&&self::PAIRS[$asset['skr_code']]===$provision['skr_code']
                &&$pair['obligation_kind']===($asset['skr_code']==='13810'?'direct_pension':'comparable_long_term')
                &&$asset['group']==='other_assets'&&$provision['group']==='provisions'
                &&$asset['opening_cents']>=0&&$asset['closing_cents']>=0&&$provision['opening_cents']<=0&&$provision['closing_cents']<=0);
            $keys=[$pair['asset'],$pair['provision']];$expense=0;$income=0;
            foreach($pair['movements'] as $key){
                self::need(is_string($key));$row=self::row($rows,$key);$code=$row['skr_code'];
                self::need(in_array($code,['71440','71450','73640','73650'],true)&&$row['group']===self::GROUPS[$code]
                    &&$row['opening_cents']===0&&$row['amount_cents']>=0);
                if($row['group']==='revenue')$income+=$row['amount_cents'];else $expense+=$row['amount_cents'];
                $keys[]=$key;
            }
            foreach($keys as $key){self::need(!isset($covered[$key]));$covered[$key]=true;$original[$key]=self::row($rows,$key);}
            $net=$asset['amount_cents']-$provision['amount_cents'];$offset+=min($asset['amount_cents'],$provision['amount_cents']);
            foreach([self::SURPLUS=>max(0,$net),self::target($provision['skr_code'])=>max(0,-$net),self::INTEREST=>$expense-$income] as $target=>$amount)$facts[$target]=($facts[$target]??0)+$amount;
        }
        self::need(!array_diff_key($active,$covered));
        if($fiscal)self::need(!array_diff_key($input['tax_positions'],$covered)&&!array_diff_key($covered,$input['tax_positions']));
        $taxKeys=[];
        foreach($input['tax_positions'] as $key=>$position){
            self::need(is_array($position)&&array_keys($position)===['account','opening_cents','closing_cents']&&is_string($position['account'])
                &&is_int($position['opening_cents'])&&is_int($position['closing_cents'])&&abs($position['opening_cents'])<=BalanceCalculation::LIMIT&&abs($position['closing_cents'])<=BalanceCalculation::LIMIT);
            $target=self::row($rows,$position['account']);$source=$original[$key];
            self::need(!isset($taxKeys[$position['account']])&&!isset($covered[$position['account']])&&!in_array($target['skr_code'],self::CODES,true));$taxKeys[$position['account']]=true;
            // Every counterpart belongs exclusively to this original arrangement.
            foreach(self::FIELDS as $field)self::need($target[$field]===0);
            $group=$source['group']==='other_assets'?'cash':$source['group'];self::need($target['group']===$group);
            if($group==='cash')self::need($target['skr_code']==='18000'&&$position['opening_cents']>=0&&$position['closing_cents']>=0);
            elseif($group==='provisions')self::need($position['opening_cents']<=0&&$position['closing_cents']<=0);
            else self::need($position['opening_cents']===0&&($group==='expense'?$position['closing_cents']>=0:$position['closing_cents']<=0));
        }
        foreach($facts as $n)self::need(abs($n)<=BalanceCalculation::LIMIT);
        self::need($offset>=0&&$offset<=$book['asset_total_cents']);
        return ['schema'=>1,'fiscal'=>$fiscal,'source'=>$input,'original_accounts'=>$original,'book_facts'=>$facts,'book_offset_cents'=>$offset];
    }
    public static function fiscal(array $values):void
    {
        $active=self::active($values['book']['accounts']);if(!$active){self::need(!isset($values['pension_offsets']));return;}
        $p=$values['pension_offsets']??null;self::need(is_array($p)&&($p['fiscal']??null)===true&&!array_diff_key($active,$p['original_accounts']));
        foreach($p['original_accounts'] as $key=>$original){
            $actual=self::row($values['tax']['accounts'],$key);
            self::need($actual['opening_cents']===0&&$actual['closing_cents']===0&&$actual['amount_cents']===0);
            $position=$p['source']['tax_positions'][$key];$target=self::row($values['tax']['accounts'],$position['account']);
            self::need($target['opening_cents']===$position['opening_cents']&&$target['closing_cents']===$position['closing_cents']);
        }
    }
}
