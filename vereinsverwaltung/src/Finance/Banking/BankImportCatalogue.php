<?php declare(strict_types=1);
namespace Kulturhus\Verein;

use DomainException;
use PDO;

/** Bounded, provider-independent views of saved bank originals. Never fetches or books money. */
trait BankImportCatalogue
{
    private static function importPage(mixed $page):int
    {
        if((!is_int($page)&&!is_string($page))||!preg_match('/^[1-9][0-9]{0,6}$/D',(string)$page)||(int)$page>1000000)throw new DomainException('Bankseite als ganze Zahl zwischen 1 und 1000000 eingeben.',422);
        return (int)$page;
    }

    private static function importText(mixed $value,int $limit):string
    {
        if(!is_string($value)||!mb_check_encoding($value,'UTF-8')||mb_strlen($value,'UTF-8')>$limit||preg_match('/[\x00-\x1f\x7f]/',$value))throw new DomainException('Bankauswahl und Suchtext prüfen.',422);
        return trim($value);
    }

    private function importRead(callable $read):array
    {
        $own=!$this->db->inTransaction();
        if($own){$this->db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');$this->db->beginTransaction();}
        try{$club=$this->access()['club_id'];$result=$read();if($own)$this->db->commit();if($this->access()['club_id']!==$club)throw new DomainException('Vereinszugang geändert.',403);return $result;}
        catch(\Throwable $failure){if($own&&$this->db->inTransaction())$this->db->rollBack();throw $failure;}
    }

    private static function importBounds(int $total,int $page,int $size):array
    {
        $pages=max(1,(int)ceil($total/$size));$page=min($page,$pages);
        return ['total'=>$total,'page'=>$page,'pages'=>$pages,'size'=>$size,'first'=>$total?($page-1)*$size+1:0,'last'=>min($total,$page*$size)];
    }

    public function recordsPage(array $filter=[],mixed $page=1,int $size=20):array
    {
        $page=self::importPage($page);if(!in_array($size,[10,20,40],true))throw new DomainException('Bankseitengröße prüfen.',422);
        foreach(['account','mapping','transaction','state','q','from','to'] as $name)$filter[$name]=self::importText($filter[$name]??'',$name==='q'?150:128);
        if(!in_array($filter['state'],['','needs_review','review','conflict','pending','imported','ignored'],true))throw new DomainException('Status der Bankzeilen prüfen.',422);
        foreach(['from','to'] as $name)if($filter[$name]!=='')self::date($filter[$name]);
        if($filter['from']!==''&&$filter['to']!==''&&$filter['from']>$filter['to'])throw new DomainException('Von-Datum liegt nach Bis-Datum.',422);
        return $this->importRead(function()use($filter,$page,$size):array{
            $a=$this->access();$where=['r.club_id=?'];$args=[$a['club_id']];
            if($filter['account']!==''){(new Finance($this->db,$this->actor))->account($filter['account']);$where[]='m.account_id=?';$args[]=$filter['account'];}
            if($filter['mapping']!==''){$this->mapping($filter['mapping']);$where[]='r.mapping_id=?';$args[]=$filter['mapping'];}
            if($filter['transaction']!==''){(new Finance($this->db,$this->actor))->transactionDetail($filter['transaction']);$where[]='EXISTS(SELECT 1 FROM '.BankEvidence::table($this->db).' selected_proof WHERE selected_proof.club_id=r.club_id AND selected_proof.record_id=r.id AND selected_proof.transaction_id=?)';$args[]=$filter['transaction'];}
            foreach(['from'=>'>=','to'=>'<='] as $name=>$operator)if($filter[$name]!==''){$where[]='r.booked_on'.$operator.'?';$args[]=$filter[$name];}
            if($filter['q']!==''){
                $where[]='(INSTR(LOWER(r.description),LOWER(?))>0 OR INSTR(LOWER(r.counterparty),LOWER(?))>0 OR INSTR(LOWER(r.reference),LOWER(?))>0 OR INSTR(LOWER(a.name),LOWER(?))>0 OR r.id=? OR EXISTS(SELECT 1 FROM bank_import_observations o WHERE o.club_id=r.club_id AND o.record_id=r.id AND INSTR(LOWER(o.payload),LOWER(?))>0))';
                array_push($args,...array_fill(0,6,$filter['q']));
            }
            $joins=' FROM bank_import_records r JOIN bank_account_mappings m ON m.club_id=r.club_id AND m.id=r.mapping_id JOIN finance_accounts a ON a.club_id=m.club_id AND a.id=m.account_id LEFT JOIN bank_transaction_sources s ON s.club_id=r.club_id AND s.record_id=r.id WHERE '.implode(' AND ',$where);
            $counts=array_fill_keys(['review','conflict','pending','imported','ignored'],0);
            foreach($this->q('SELECT r.state,COUNT(*) AS n'.$joins.' GROUP BY r.state',$args) as $row)$counts[$row['state']]=(int)$row['n'];
            $counts['needs_review']=$counts['review']+$counts['conflict'];$total=$filter['state']===''?array_sum(array_intersect_key($counts,array_flip(['review','conflict','pending','imported','ignored']))):$counts[$filter['state']];
            $bounds=self::importBounds($total,$page,$size);$state=$filter['state'];
            if($state!==''){$joins.=$state==='needs_review'?" AND r.state IN('review','conflict')":' AND r.state=?';if($state!=='needs_review')$args[]=$state;}
            $rows=$this->q('SELECT r.*,m.provider,m.label AS bank_label,m.account_id,a.name AS account_name,s.transaction_id'.$joins.' ORDER BY r.last_seen DESC,r.id LIMIT '.$size.' OFFSET '.(($bounds['page']-1)*$size),$args)->fetchAll();
            return $bounds+['rows'=>$rows,'counts'=>$counts,'filter'=>$filter];
        });
    }

    public function candidatesPage(string $record,mixed $page=1,int $size=20,mixed $search='',string $exclude=''):array
    {
        $page=self::importPage($page);$search=self::importText($search,150);if(!in_array($size,[10,20,40],true))throw new DomainException('Zahlungsseitengröße prüfen.',422);
        return $this->importRead(function()use($record,$page,$size,$search,$exclude):array{
            $r=$this->record($record);$map=$this->mapping($r['mapping_id']);$where=['t.club_id=?','t.account_id=?','t.cents=?',BankCounterpayments::candidateSql($this->db),'NOT ('.BankEvidence::groupedSql($this->db).')'];$args=[$map['club_id'],$map['account_id'],$r['cents']];
            if($search!==''){$where[]='(INSTR(LOWER(t.description),LOWER(?))>0 OR INSTR(LOWER(t.counterparty),LOWER(?))>0 OR t.id=?)';array_push($args,$search,$search,$search);}
            if($exclude!==''){$where[]='t.id<>?';$args[]=$exclude;}
            $from=' FROM finance_transactions t WHERE '.implode(' AND ',$where);$total=(int)$this->q('SELECT COUNT(*)'.$from,$args)->fetchColumn();$bounds=self::importBounds($total,$page,$size);
            $rows=$this->q('SELECT t.*,EXISTS(SELECT 1 FROM '.BankEvidence::table($this->db).' s WHERE s.club_id=t.club_id AND s.transaction_id=t.id) AS bank_linked,EXISTS(SELECT 1 FROM finance_transactions bank_return WHERE bank_return.club_id=t.club_id AND bank_return.reversal_of=t.id) AS bank_returned'.$from.' ORDER BY t.booked_on DESC,t.id LIMIT '.$size.' OFFSET '.(($bounds['page']-1)*$size),$args)->fetchAll();
            return $bounds+['rows'=>$rows,'q'=>$search];
        });
    }

    public function reviewDetail(string $id,mixed $observationPage=1,mixed $decisionPage=1):array
    {
        $observationPage=self::importPage($observationPage);$decisionPage=self::importPage($decisionPage);
        return $this->importRead(function()use($id,$observationPage,$decisionPage):array{
            $r=$this->record($id);$map=$this->mapping($r['mapping_id']);$account=(new Finance($this->db,$this->actor))->account($map['account_id']);$args=[$r['club_id'],$id];
            $source=$this->q('SELECT * FROM bank_transaction_sources WHERE club_id=? AND record_id=?',$args)->fetch()?:null;$transaction=$source?(new Finance($this->db,$this->actor))->transactionDetail($source['transaction_id']):null;
            $observations=self::importBounds((int)$this->q('SELECT COUNT(*) FROM bank_import_observations WHERE club_id=? AND record_id=?',$args)->fetchColumn(),$observationPage,10);
            $observations['rows']=$this->q('SELECT o.*,o.sha256 AS payload_sha256,b.started_at,b.finished_at,b.state AS run_state FROM bank_import_observations o JOIN bank_import_runs b ON b.club_id=o.club_id AND b.id=o.run_id WHERE o.club_id=? AND o.record_id=? ORDER BY b.started_at DESC,b.id DESC,o.ordinal LIMIT 10 OFFSET '.(($observations['page']-1)*10),$args)->fetchAll();
            foreach($observations['rows'] as &$row){$row['verified']=hash_equals($row['payload_sha256'],hash('sha256',$row['payload']));$row['value']=null;if($row['verified'])try{$row['value']=self::normalise(json_decode($row['payload'],true,128,JSON_THROW_ON_ERROR));}catch(\Throwable){$row['verified']=false;}}unset($row);
            $decisions=self::importBounds((int)$this->q('SELECT COUNT(*) FROM bank_import_decisions WHERE club_id=? AND record_id=?',$args)->fetchColumn(),$decisionPage,20);
            $decisions['rows']=$this->q('SELECT * FROM bank_import_decisions WHERE club_id=? AND record_id=? ORDER BY created_at DESC,id LIMIT 20 OFFSET '.(($decisions['page']-1)*20),$args)->fetchAll();
            if($decisions['rows']&&BankEvidence::groupsAvailable($this->db)){
                $versions=array_column($decisions['rows'],'record_version');$links=$this->q('SELECT record_version,id FROM bank_payment_group_links WHERE club_id=? AND record_id=? AND record_version IN('.implode(',',array_fill(0,count($versions),'?')).')',[...$args,...$versions])->fetchAll(PDO::FETCH_KEY_PAIR);
                foreach($decisions['rows']as&$decision)if(isset($links[$decision['record_version']]))$decision['payment_group_id']=$links[$decision['record_version']];unset($decision);
            }
            return ['record'=>$r,'mapping'=>$map,'account'=>$account,'transaction'=>$transaction,'bank_source'=>$source,'payment_group'=>$this->paymentGroups()->active($id),'observations'=>$observations,'decisions'=>$decisions];
        });
    }

    public function observation(string $record,string $run,mixed $ordinal):array
    {
        if((!is_int($ordinal)&&!is_string($ordinal))||!preg_match('/^(?:0|[1-9][0-9]{0,3})$/D',(string)$ordinal))throw new DomainException('Ursprüngliche Bankzeile auswählen.',422);
        return $this->importRead(function()use($record,$run,$ordinal):array{
            $r=$this->record($record);$row=$this->q('SELECT o.*,o.sha256 AS payload_sha256 FROM bank_import_observations o WHERE club_id=? AND record_id=? AND run_id=? AND ordinal=?',[$r['club_id'],$record,$run,(int)$ordinal])->fetch();
            if(!$row)throw new DomainException('Ursprüngliche Bankzeile nicht verfügbar.',404);
            if(!hash_equals($row['payload_sha256'],hash('sha256',$row['payload'])))throw new DomainException('Die gespeicherten Bankoriginalbytes weichen von ihrer Prüfsumme ab.',409);
            return $row;
        });
    }
}
