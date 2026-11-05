<?php declare(strict_types=1);
namespace Kulturhus\Verein;

use PDO;
use DomainException;

/** Shared provenance for single payments and deliberately confirmed net groups. Never installs schema. */
final class BankEvidence
{
    public static function groupsAvailable(PDO $db):bool
    {
        foreach(['bank_payment_groups','bank_payment_group_items','bank_payment_group_links']as$table)if(!Database::tableExists($db,$table))return false;
        return true;
    }

    /** Same six columns as the historical single source; mutations continue to use their own table. */
    public static function table(PDO $db):string
    {
        if(!self::groupsAvailable($db))return 'bank_transaction_sources';
        return <<<'SQL'
(SELECT single_source.club_id,single_source.record_id,single_source.transaction_id,single_source.kind,single_source.linked_by,single_source.linked_at
 FROM bank_transaction_sources single_source
 UNION ALL
 SELECT binding.club_id,binding.record_id,line.transaction_id,'reconciled',binding.actor_id,binding.created_at
 FROM bank_payment_group_links binding
 JOIN bank_payment_groups payment_group ON payment_group.club_id=binding.club_id AND payment_group.id=binding.group_id
 JOIN bank_payment_group_items line ON line.club_id=payment_group.club_id AND line.group_id=payment_group.id
 JOIN bank_import_records bank_original ON bank_original.club_id=binding.club_id AND bank_original.id=binding.record_id
 JOIN bank_account_mappings bank_mapping ON bank_mapping.club_id=bank_original.club_id AND bank_mapping.id=bank_original.mapping_id
 WHERE binding.released_at IS NULL AND bank_mapping.account_id=payment_group.account_id
 AND bank_original.bank_status='BOOK' AND bank_original.currency='EUR' AND bank_original.cents=payment_group.total_cents
 AND SHA2(payment_group.original_json,256)=payment_group.original_sha256
 AND SHA2(binding.original_json,256)=binding.original_sha256
 AND JSON_UNQUOTE(JSON_EXTRACT(payment_group.original_json,'$.identity.id'))=payment_group.id
 AND JSON_UNQUOTE(JSON_EXTRACT(payment_group.original_json,'$.identity.club_id'))=payment_group.club_id
 AND JSON_UNQUOTE(JSON_EXTRACT(payment_group.original_json,'$.identity.account_id'))=payment_group.account_id
 AND JSON_UNQUOTE(JSON_EXTRACT(payment_group.original_json,'$.identity.record_id'))=payment_group.record_id
 AND JSON_UNQUOTE(JSON_EXTRACT(payment_group.original_json,'$.identity.created_by'))=payment_group.created_by
 AND JSON_UNQUOTE(JSON_EXTRACT(payment_group.original_json,'$.identity.created_at'))=payment_group.created_at
 AND CAST(JSON_UNQUOTE(JSON_EXTRACT(payment_group.original_json,'$.identity.total_cents')) AS SIGNED)=payment_group.total_cents
 AND JSON_UNQUOTE(JSON_EXTRACT(binding.original_json,'$.identity.id'))=binding.id
 AND JSON_UNQUOTE(JSON_EXTRACT(binding.original_json,'$.identity.club_id'))=binding.club_id
 AND JSON_UNQUOTE(JSON_EXTRACT(binding.original_json,'$.identity.record_id'))=binding.record_id
 AND JSON_UNQUOTE(JSON_EXTRACT(binding.original_json,'$.identity.group_id'))=binding.group_id
 AND JSON_UNQUOTE(JSON_EXTRACT(binding.original_json,'$.identity.actor_id'))=binding.actor_id
 AND JSON_UNQUOTE(JSON_EXTRACT(binding.original_json,'$.identity.reason'))=binding.reason
 AND JSON_UNQUOTE(JSON_EXTRACT(binding.original_json,'$.identity.operation'))=binding.operation
 AND JSON_UNQUOTE(JSON_EXTRACT(binding.original_json,'$.identity.created_at'))=binding.created_at
 AND CAST(JSON_UNQUOTE(JSON_EXTRACT(binding.original_json,'$.identity.record_version')) AS SIGNED)=binding.record_version
 AND (SELECT COUNT(*) FROM bank_payment_group_items checked_line WHERE checked_line.club_id=payment_group.club_id AND checked_line.group_id=payment_group.id) BETWEEN IF(CAST(JSON_UNQUOTE(JSON_EXTRACT(payment_group.original_json,'$.schema')) AS UNSIGNED)=2,1,2) AND 40
 AND (SELECT COUNT(*) FROM bank_payment_group_items checked_line WHERE checked_line.club_id=payment_group.club_id AND checked_line.group_id=payment_group.id)=JSON_LENGTH(JSON_EXTRACT(payment_group.original_json,'$.source.transactions'))
 AND (SELECT SUM(checked_line.cents) FROM bank_payment_group_items checked_line WHERE checked_line.club_id=payment_group.club_id AND checked_line.group_id=payment_group.id)=payment_group.total_cents
 AND NOT EXISTS(SELECT 1 FROM bank_payment_group_items checked_line JOIN finance_transactions checked_money ON checked_money.club_id=checked_line.club_id AND checked_money.id=checked_line.transaction_id
  WHERE checked_line.club_id=payment_group.club_id AND checked_line.group_id=payment_group.id
  AND (checked_money.account_id<>payment_group.account_id OR SIGN(checked_money.cents)<>SIGN(checked_line.cents) OR ABS(checked_line.cents)>ABS(checked_money.cents) OR checked_line.cents<>IF(CAST(JSON_UNQUOTE(JSON_EXTRACT(payment_group.original_json,'$.schema')) AS UNSIGNED)=2,COALESCE(CAST(JSON_UNQUOTE(JSON_EXTRACT(payment_group.original_json,CONCAT('$.source.portions.',JSON_QUOTE(checked_line.transaction_id)))) AS SIGNED),0),checked_money.cents)
   OR COALESCE(JSON_UNQUOTE(JSON_EXTRACT(payment_group.original_json,CONCAT('$.source.transactions.',JSON_QUOTE(checked_line.transaction_id),'.id'))),'')<>checked_line.transaction_id
   OR COALESCE(CAST(JSON_UNQUOTE(JSON_EXTRACT(payment_group.original_json,CONCAT('$.source.transactions.',JSON_QUOTE(checked_line.transaction_id),'.cents'))) AS SIGNED),0)<>checked_money.cents)))
SQL;
    }

    /** Fixed SQL aliases only, used to keep grouped components out of the single-payment chooser. */
    public static function groupedSql(PDO $db):string
    {
        if(!self::groupsAvailable($db))return '0';
        return 'EXISTS(SELECT 1 FROM bank_payment_group_items grouped_line JOIN bank_payment_group_links grouped_binding ON grouped_binding.club_id=grouped_line.club_id AND grouped_binding.group_id=grouped_line.group_id WHERE grouped_line.club_id=t.club_id AND grouped_line.transaction_id=t.id AND grouped_binding.released_at IS NULL)';
    }

    /** A damaged existing binding is still occupied until deliberately clarified. Fixed alias t. */
    public static function unprovedSql(PDO $db):string
    {
        return 'NOT EXISTS(SELECT 1 FROM bank_transaction_sources known_proof WHERE known_proof.club_id=t.club_id AND known_proof.transaction_id=t.id) AND NOT ('.self::groupedSql($db).')';
    }

    /** Catalogue prefilter only; every returned page is verified against physical originals. */
    public static function remainingSql(PDO $db):string
    {
        $group=self::groupsAvailable($db)?"COALESCE((SELECT SUM(ABS(portion.cents)) FROM bank_payment_group_items portion WHERE portion.club_id=t.club_id AND portion.transaction_id=t.id AND EXISTS(SELECT 1 FROM bank_payment_group_links active_portion WHERE active_portion.club_id=portion.club_id AND active_portion.group_id=portion.group_id AND active_portion.released_at IS NULL)),0)":'0';
        return 'CASE WHEN EXISTS(SELECT 1 FROM bank_transaction_sources classic WHERE classic.club_id=t.club_id AND classic.transaction_id=t.id) THEN 0 ELSE ABS(t.cents)-'.$group.' END';
    }

    /** Lock physical tables directly: a locking outer SELECT cannot refresh an old UNION snapshot. */
    public static function proofs(PDO $db,string $club,array $transactions,bool $current=false):array
    {
        if($current&&!$db->inTransaction())throw new DomainException('Aktuelle Banknachweise gemeinsam prüfen.');
        $transactions=array_values(array_unique($transactions));if(!$transactions)return [];if(count($transactions)>200)throw new DomainException('Banknachweise in begrenzten Seiten prüfen.');$marks=implode(',',array_fill(0,count($transactions),'?'));$lock=$current?' FOR SHARE NOWAIT':'';$args=[$club,...$transactions];$out=[];
        $q=$db->prepare('SELECT s.transaction_id,s.record_id,s.kind,s.linked_by,s.linked_at,r.version AS bank_version,r.state AS bank_state,r.content_hash AS bank_hash,r.booked_on,r.cents AS bank_cents,r.cents AS evidence_cents,r.currency,r.bank_status,m.account_id FROM bank_transaction_sources s JOIN bank_import_records r ON r.club_id=s.club_id AND r.id=s.record_id JOIN bank_account_mappings m ON m.club_id=r.club_id AND m.id=r.mapping_id WHERE s.club_id=? AND s.transaction_id IN('.$marks.') ORDER BY s.transaction_id,s.record_id'.$lock);$q->execute($args);foreach($q as$row){$row['group_id']='';$row['proof_schema']=0;$out[$row['transaction_id']][]=$row;}
        if(self::groupsAvailable($db)){
            $sql="SELECT line.transaction_id,line.cents AS evidence_cents,binding.club_id,binding.id AS binding_id,binding.record_id,binding.record_version AS binding_version,binding.group_id,binding.operation,binding.actor_id,binding.reason,binding.original_json AS binding_json,binding.original_sha256 AS binding_sha256,binding.created_at AS binding_created_at,payment_group.account_id AS group_account,payment_group.record_id AS group_record,payment_group.total_cents,payment_group.original_json AS group_json,payment_group.original_sha256 AS group_sha256,payment_group.created_by AS group_created_by,payment_group.created_at AS group_created_at,r.version AS bank_version,r.state AS bank_state,r.content_hash AS bank_hash,r.booked_on,r.cents AS bank_cents,r.currency,r.bank_status,m.account_id FROM bank_payment_group_items line JOIN bank_payment_groups payment_group ON payment_group.club_id=line.club_id AND payment_group.id=line.group_id JOIN bank_payment_group_links binding ON binding.club_id=line.club_id AND binding.group_id=line.group_id JOIN bank_import_records r ON r.club_id=binding.club_id AND r.id=binding.record_id JOIN bank_account_mappings m ON m.club_id=r.club_id AND m.id=r.mapping_id WHERE line.club_id=? AND line.transaction_id IN($marks) AND binding.released_at IS NULL ORDER BY line.transaction_id,binding.record_id".$lock;
            $q=$db->prepare($sql);$q->execute($args);$rows=$q->fetchAll();$verified=[];
            foreach($rows as$row){
                $header=['club_id'=>$club,'id'=>$row['group_id'],'account_id'=>$row['group_account'],'record_id'=>$row['group_record'],'total_cents'=>$row['total_cents'],'original_json'=>$row['group_json'],'original_sha256'=>$row['group_sha256'],'created_by'=>$row['group_created_by'],'created_at'=>$row['group_created_at']];
                $binding=['club_id'=>$club,'id'=>$row['binding_id'],'record_id'=>$row['record_id'],'record_version'=>$row['binding_version'],'group_id'=>$row['group_id'],'operation'=>$row['operation'],'actor_id'=>$row['actor_id'],'reason'=>$row['reason'],'original_json'=>$row['binding_json'],'original_sha256'=>$row['binding_sha256'],'created_at'=>$row['binding_created_at']];BankPaymentGroups::verifyOriginal($binding);
                if(!isset($verified[$header['id']])){$items=$db->prepare('SELECT item.*,money.account_id AS actual_account_id,money.cents AS actual_cents FROM bank_payment_group_items item JOIN finance_transactions money ON money.club_id=item.club_id AND money.id=item.transaction_id WHERE item.club_id=? AND item.group_id=? ORDER BY item.ordinal'.$lock);$items->execute([$club,$header['id']]);$checked=BankPaymentGroups::verifyGroup($header,$items->fetchAll());$verified[$header['id']]=(int)$checked['schema'];}
                if($row['account_id']!==$header['account_id']||(int)$row['bank_cents']!==(int)$header['total_cents']||$row['bank_status']!=='BOOK'||$row['currency']!=='EUR')throw new DomainException('Bankoriginal und gespeicherter Sammelnettobetrag stimmen nicht überein.',409);
                $proof=array_intersect_key($row,array_flip(['transaction_id','record_id','bank_version','bank_state','bank_hash','booked_on','bank_cents','evidence_cents','currency','bank_status','account_id']));$proof+=['group_id'=>$header['id'],'proof_schema'=>$verified[$header['id']],'kind'=>'reconciled','linked_by'=>$row['actor_id'],'linked_at'=>$row['binding_created_at']];$out[$row['transaction_id']][]=$proof;
            }
        }
        foreach($out as&$rows)usort($rows,static fn(array $a,array $b):int=>strcmp($a['record_id'],$b['record_id']));unset($rows);return $out;
    }
    /** Count a physical group once even when several bank references prove it. */
    public static function describe(array $money,array $proofs):array
    {
        $amount=abs((int)$money['cents']);$parts=[];$imported=false;
        foreach($proofs as$proof){
            $part=(int)$proof['evidence_cents'];if($proof['account_id']!==$money['account_id']||$proof['currency']!=='EUR'||$proof['bank_status']!=='BOOK'||!$part||($part>0)!==((int)$money['cents']>0)||abs($part)>$amount)throw new DomainException('Banknachweis und ursprüngliche Geldbewegung stimmen nicht überein.',409);
            $group=$proof['group_id']??'';$key=$group!==''?'group:'.$group:'single';if($group===''&&abs($part)!==$amount)throw new DomainException('Einzelbanknachweis belegt nicht die vollständige ursprüngliche Bewegung.',409);
            if(isset($parts[$key])&&$parts[$key]!==abs($part))throw new DomainException('Bankreferenzen derselben Zusammenfassung belegen unterschiedliche Teilbeträge.',409);$parts[$key]=abs($part);$imported=$imported||$proof['kind']==='imported';
        }
        $proved=array_sum($parts);if(!is_int($proved)||$proved>$amount)throw new DomainException('Die Banknachweise beanspruchen mehr als die ursprüngliche Geldbewegung.',409);
        $source=$money['recorded_source']??$money['source'];if($proved)$source=$proved===$amount?($imported?'bank':'bank_reconciled'):'bank_partial_reconciled';
        return ['source'=>$source,'bank_proved_cents'=>$proved,'bank_unproved_cents'=>$amount-$proved];
    }

}
