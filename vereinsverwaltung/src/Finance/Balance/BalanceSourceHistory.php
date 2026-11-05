<?php declare(strict_types=1);
namespace Kulturhus\Verein;

use DomainException;
use PDO;
require_once dirname(__DIR__, 2).'/Finance/Assets/OwnIntangibleStorage.php';
require_once __DIR__.'/BalanceSourceDecisionSchema.php';

/** Reads retained proposals and checks their journal/reversal chain. No history is rewritten. */
final class BalanceSourceHistory
{
    public function __construct(private PDO $db,private string $actorId,private bool $lock=false){}
    public static function json(mixed $value):string{return json_encode(OwnIntangibleStorage::normal($value),JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);}
    public static function proposalHash(array $p):string
    {
        return hash('sha256',self::json([$p['type'],$p['id'],$p['year'],$p['options'],$p['snapshot'],$p['accounts'],$p['vouchers'],$p['issues']]));
    }
    public static function digest(string $club,string $type,string $id,int $revision,array $payload):string
    {
        return hash('sha256',self::json([$club,$type,$id,$revision,$payload]));
    }
    private function rows(string $sql,array $args):array
    {
        if($this->lock&&!$this->db->inTransaction())throw new DomainException('Verlaufsprüfung benötigt einen Speichervorgang.');
        return DocumentCatalog::query($this->db,$sql.($this->lock?' FOR UPDATE':''),$args)->fetchAll();
    }
    /** Empty means an older binding without retained proposals; never invent that history. */
    public function checked(array $binding):array
    {
        $a=Permissions::actor($this->db,$this->actorId);
        if(!in_array($a['finance_access'],['read','write'],true)||$a['club_id']!==$binding['club_id'])throw new DomainException('Finanzberechtigung erforderlich.');
        $club=$a['club_id'];$type=$binding['source_type'];$id=$binding['source_id'];
        $rows=$this->rows('SELECT * FROM balance_source_history WHERE club_id=? AND source_type=? AND source_id=? ORDER BY revision LIMIT 1001',[$club,$type,$id]);
        if(!$rows&&$binding['history_sha256']!==null)self::damaged();
        if(count($rows)>1000)throw new DomainException('Zu viele Korrekturen für eine gemeinsame Prüfung.');
        $previous=null;
        foreach($rows as $i=>&$row){
            $r=json_decode($row['payload'],true,512,JSON_THROW_ON_ERROR);
            if(!is_array($r)||!in_array(array_keys($r),[['proposal','entry_ids','reversal_ids','previous_sha256','reason','actor','at'],['proposal','entry_ids','reversal_ids','previous_sha256','reason','actor','at','decision']],true)||!is_array($r['proposal'])
                ||!is_array($r['entry_ids'])||!array_is_list($r['entry_ids'])||!is_array($r['reversal_ids'])||!array_is_list($r['reversal_ids'])
                ||!is_string($r['reason'])||!is_string($r['actor'])||!is_string($r['at']))self::damaged();
            if(array_key_exists('decision',$r))BalanceSourceDecisionSchema::context($r['decision']);
            foreach(array_merge($r['entry_ids'],$r['reversal_ids']) as $entryId)if(!is_string($entryId)||!preg_match('/^[a-f0-9]{32}$/D',$entryId))self::damaged();
            if(count(array_unique($r['entry_ids']))!==count($r['entry_ids'])||count(array_unique($r['reversal_ids']))!==count($r['reversal_ids']))self::damaged();
            $p=$r['proposal'];
            if(array_keys($p)!==['type','id','year','options','snapshot','accounts','vouchers','evidence','issues','hash']
                ||!is_array($p['options'])||!is_array($p['snapshot'])||!is_array($p['accounts'])||!is_array($p['vouchers'])||!array_is_list($p['vouchers'])
                ||!is_array($p['issues'])||!is_string($p['hash'])||!is_string($p['evidence']))self::damaged();
            $chart=BalanceCalculation::chart($p['accounts']);
            foreach($p['vouchers'] as $v){
                if(!is_array($v)||array_keys($v)!==['date','description','lines']||!is_string($v['date'])||!is_string($v['description'])||!is_array($v['lines']))self::damaged();
                BalanceCalculation::lines($v['lines'],$chart);
            }
            if((int)$row['revision']!==$i+1||!hash_equals($row['sha256'],self::digest($club,$type,$id,$i+1,$r))
                ||$r['previous_sha256']!==($previous['sha256']??null)||$p['type']!==$type||$p['id']!==$id||$p['year']!==(int)$binding['tax_year']
                ||$p['issues']||!hash_equals($p['hash'],self::proposalHash($p))||count($r['entry_ids'])!==count($p['vouchers'])||(!$r['entry_ids']&&!AssetDisposal::nonPosting($p)))self::damaged();
            foreach($r['entry_ids'] as $index=>$entryId){
                $e=$this->rows('SELECT * FROM balance_entries WHERE club_id=? AND tax_year=? AND id=?',[$club,$p['year'],$entryId])[0]??null;
                $v=$p['vouchers'][$index];
                if(!$e||$e['reversal_of']!==null||$e['booked_on']!==$v['date']||$e['description']!==$v['description']||$e['evidence']!==$p['evidence']
                    ||json_decode($e['posting_lines'],true,64,JSON_THROW_ON_ERROR)!==$v['lines'])self::damaged();
            }
            if(!$previous){if($r['reversal_ids']!==[])self::damaged();}
            else{
                $old=$previous['payload'];
                if(count($r['reversal_ids'])!==count($old['entry_ids']))self::damaged();
                foreach($old['entry_ids'] as $index=>$oldId){
                    $reversals=$this->rows('SELECT * FROM balance_entries WHERE club_id=? AND tax_year=? AND reversal_of=?',[$club,$p['year'],$oldId]);
                    $e=$reversals[0]??null;$v=$old['proposal']['vouchers'][$index];$lines=$v['lines'];
                    foreach($lines as &$l)[$l['debit_cents'],$l['credit_cents']]=[$l['credit_cents'],$l['debit_cents']];unset($l);
                    if(count($reversals)!==1||$e['id']!==$r['reversal_ids'][$index]||$e['booked_on']!==$v['date']||$e['description']!=='Korrekturstorno: '.$oldId||$e['evidence']!==$r['reason']
                        ||json_decode($e['posting_lines'],true,64,JSON_THROW_ON_ERROR)!==$lines)self::damaged();
                    if($this->rows('SELECT id FROM balance_entries WHERE club_id=? AND tax_year=? AND reversal_of=?',[$club,$p['year'],$e['id']]))self::damaged();
                }
            }
            $row['payload']=$r;$previous=$row;
        }unset($row);
        if($previous){
            $r=$previous['payload'];$p=$r['proposal'];
            if($binding['history_sha256']!==$previous['sha256']||$binding['entry_ids']!==$r['entry_ids']||$binding['source_hash']!==$p['hash']||$binding['snapshot']!==$p['snapshot']||$binding['options']!==$p['options'])self::damaged();
            foreach($r['entry_ids'] as $entryId)if($this->rows('SELECT id FROM balance_entries WHERE club_id=? AND tax_year=? AND reversal_of=?',[$club,$p['year'],$entryId]))self::damaged();
        }
        return $rows;
    }
    private static function damaged():never{throw new DomainException('Belegverlauf und Journal stimmen nicht überein. Keine automatische Korrektur möglich.');}
}
