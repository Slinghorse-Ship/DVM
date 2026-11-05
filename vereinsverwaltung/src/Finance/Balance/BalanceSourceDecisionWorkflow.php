<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use DomainException;

/** First whole annual source operation, including every actual dependent source. */
trait BalanceSourceDecisionWorkflow
{
    private ?array $sourceDecisionRequest=null;
    private array $sourceDecisionPending=[];
    private bool $sourceDecisionFlushing=false;
    private ?array $sourceDecisionContext=null;
    private ?array $sourceDecisionFirst=null;

    public function sourceDecisionStand(int $year):array
    {
        $actor=$this->access();$row=$this->row($actor,$year);if(!$row)throw new DomainException('Bilanzjahr nicht vorhanden.',404);
        return ['year'=>$year,'version'=>$row['version'],'sha256'=>$this->sourceDecisionStandHash($actor,$row)];
    }
    private function sourceDecisionStandHash(array $actor,array $row):string
    {
        $hash=hash_init('sha256');hash_update($hash,BalanceSourceHistory::json(['annual-source-stand-v1',$actor['club_id'],$row]));
        $rows=$this->q('SELECT * FROM balance_entries WHERE club_id=? AND tax_year=? ORDER BY booked_on,id LIMIT 10001'.($this->writing?' FOR UPDATE':''),[$actor['club_id'],(int)$row['tax_year']]);$count=0;
        try{while($entry=$rows->fetch()){if(++$count>10000)throw new DomainException('Mehr als 10.000 Bilanzbuchungen in diesem Jahr.');$entry['date']=$entry['booked_on'];$entry['lines']=json_decode($entry['posting_lines'],true,64,JSON_THROW_ON_ERROR);unset($entry['posting_lines']);hash_update($hash,"\0".BalanceSourceHistory::json($entry));}}finally{$rows->closeCursor();}
        return hash_final($hash);
    }
    public function sourceDecisionForAction(array $input):array
    {
        $input=BalanceSourceDecisionSchema::input($input);$actor=$this->access();$matches=$this->q("SELECT source_type,source_id,revision,sha256 FROM balance_source_history WHERE club_id=? AND JSON_UNQUOTE(JSON_EXTRACT(payload,'$.decision.format'))='annual-source-decision-v1' AND JSON_UNQUOTE(JSON_EXTRACT(payload,'$.decision.request_key'))=? LIMIT 2",[$actor['club_id'],$input['request_key']])->fetchAll();
        if(count($matches)>1)throw new DomainException('Ursprünglichen Belegvorgang prüfen lassen.',503);
        if($matches){$first=$this->sourceDecisionOriginal($matches[0]['source_type'],$matches[0]['source_id'],(int)$matches[0]['revision'],$matches[0]['sha256']);$checked=$input;$checked['reviewed']=true;if($first['context']['actor']!==$actor['id']||$first['context']['request']!==$checked)throw new DomainException('Ursprüngliche Angaben oder Person der Belegübernahme geändert.',409);return ['original'=>$first];}
        $row=$this->row($actor,$input['year']);if(!$row||$row['state']!=='open'||$row['version']!==$input['version']||!hash_equals($input['before_sha256'],$this->sourceDecisionStandHash($actor,$row)))throw new DomainException('Geprüften ursprünglichen Jahresstand erneut laden.',409);return ['original'=>null];
    }
    public function applySourceDecision(array $input):array
    {
        $input=BalanceSourceDecisionSchema::input($input);if(!$input['reviewed'])throw new DomainException('Beleg und vorgeschlagene Buchung zuerst prüfen.');
        return $this->transaction(function(array $actor)use($input):array{
            $matches=$this->q("SELECT source_type,source_id,revision,sha256 FROM balance_source_history WHERE club_id=? AND JSON_UNQUOTE(JSON_EXTRACT(payload,'$.decision.format'))='annual-source-decision-v1' AND JSON_UNQUOTE(JSON_EXTRACT(payload,'$.decision.request_key'))=? LIMIT 2",[$actor['club_id'],$input['request_key']])->fetchAll();
            if(count($matches)>1)throw new DomainException('Ursprünglichen Belegvorgang prüfen lassen.',503);
            if($matches){$first=$this->sourceDecisionOriginal($matches[0]['source_type'],$matches[0]['source_id'],(int)$matches[0]['revision'],$matches[0]['sha256']);if($first['context']['actor']!==$actor['id']||$first['context']['request']!==$input)throw new DomainException('Ursprüngliche Angaben oder Person der Belegübernahme geändert.',409);return $first;}
            $row=$this->editable($actor,$input['year'],$input['version'],'open');if(!hash_equals($input['before_sha256'],$this->sourceDecisionStandHash($actor,$row)))throw new DomainException('Geprüften ursprünglichen Jahresstand erneut laden.',409);
            $this->sourceDecisionRequest=$input;$this->sourceDecisionPending=[];$this->sourceDecisionFirst=null;
            try{
                if($input['operation']==='import')$this->importCheckedSource($actor,$input['year'],$input['version'],$input['source_type'],$input['source_id'],$input['options'],$input['expected_hash'],true);
                else $this->correctCheckedSource($actor,$input['year'],$input['version'],$input['source_type'],$input['source_id'],$input['options'],$input['expected_hash'],$input['reason'],true);
                $this->flushSourceDecisionHistory($actor,$input['year']);$first=$this->sourceDecisionFirst;if(!$first)throw new DomainException('Ursprünglichen Belegvorgang prüfen lassen.',503);
                return $this->sourceDecisionOriginal($first['type'],$first['id'],$first['revision'],$first['sha256']);
            }finally{$this->sourceDecisionRequest=null;$this->sourceDecisionPending=[];$this->sourceDecisionFlushing=false;$this->sourceDecisionContext=null;$this->sourceDecisionFirst=null;}
        });
    }
    /** Finance readers authenticate the full actual source/reversal chains. */
    public function sourceDecisionOriginal(string $type,string $id,int $revision,string $sha256,int $depth=0):array
    {
        if($depth<0||$depth>1)throw new DomainException('Ursprünglichen Belegvorgang prüfen lassen.',503);BalanceSourceDecisionSchema::identity($type,$id);if($revision<1||$revision>1000||!preg_match('/\A[a-f0-9]{64}\z/D',$sha256))throw new DomainException('Ursprünglichen Belegvorgang nicht gefunden.',404);
        $sources=new BalanceSources($this->db,$this->actorId);$binding=$sources->binding($type,$id);if(!$binding)throw new DomainException('Ursprünglichen Belegvorgang nicht gefunden.',404);
        $history=(new BalanceSourceHistory($this->db,$this->actorId,$this->writing))->checked($binding);$first=$history[$revision-1]??null;if(!$first||!hash_equals($first['sha256'],$sha256)||!isset($first['payload']['decision']))throw new DomainException('Ursprünglichen Belegvorgang nicht gefunden.',404);
        $context=BalanceSourceDecisionSchema::context($first['payload']['decision']);
        if($context['format']==='annual-source-reference-v1')return $this->sourceDecisionOriginal($context['primary_type'],$context['primary_id'],$context['primary_revision'],$context['primary_sha256'],$depth+1);
        $request=$context['request'];if($request['source_type']!==$type||$request['source_id']!==$id||$request['year']!==(int)$binding['tax_year']||$context['actor']!==$first['payload']['actor'])throw new DomainException('Ursprünglichen Belegvorgang prüfen lassen.',503);
        $items=[];
        foreach($context['targets']as$target){$current=$sources->binding($target['type'],$target['id']);if(!$current||(int)$current['tax_year']!==$request['year'])throw new DomainException('Ursprünglichen Belegvorgang prüfen lassen.',503);$chain=(new BalanceSourceHistory($this->db,$this->actorId,$this->writing))->checked($current);$row=$chain[$target['revision']-1]??null;if(!$row||$row['payload']['actor']!==$context['actor'])throw new DomainException('Ursprünglichen Belegvorgang prüfen lassen.',503);if($target['type']===$type&&$target['id']===$id){if(!hash_equals($row['sha256'],$first['sha256']))throw new DomainException('Ursprünglichen Belegvorgang prüfen lassen.',503);}else{$reference=BalanceSourceDecisionSchema::context($row['payload']['decision']??null);if($reference!==['format'=>'annual-source-reference-v1','request_key'=>$request['request_key'],'primary_type'=>$type,'primary_id'=>$id,'primary_revision'=>$revision,'primary_sha256'=>$first['sha256']])throw new DomainException('Ursprünglichen Belegvorgang prüfen lassen.',503);}$items[]=['type'=>$target['type'],'id'=>$target['id'],'revision'=>(int)$row['revision'],'sha256'=>$row['sha256'],'payload'=>$row['payload']];}
        return ['type'=>$type,'id'=>$id,'revision'=>$revision,'sha256'=>$first['sha256'],'payload'=>$first['payload'],'context'=>$context,'items'=>$items];
    }
    public function sourceDecisionHistory(int $year,mixed $page='1'):array
    {
        $actor=$this->access();BalanceCalculation::year($year);
        if(!is_string($page)||!preg_match('/\A[1-9][0-9]{0,6}\z/D',$page))throw new DomainException('Verlaufsseite der Belegvorgänge vollständig auswählen.',422);
        $where=" FROM balance_source_history h JOIN balance_sources b ON b.club_id=h.club_id AND b.source_type=h.source_type AND b.source_id=h.source_id WHERE h.club_id=? AND b.tax_year=? AND JSON_UNQUOTE(JSON_EXTRACT(h.payload,'$.decision.format'))='annual-source-decision-v1'";
        $total=(int)$this->q('SELECT COUNT(*)'.$where,[$actor['club_id'],$year])->fetchColumn();$pages=max(1,(int)ceil($total/20));if((int)$page>$pages)throw new DomainException('Verlaufsseite der Belegvorgänge vollständig auswählen.',422);$offset=((int)$page-1)*20;
        $rows=$this->q("SELECT h.source_type,h.source_id,h.revision,h.sha256".$where." ORDER BY CAST(JSON_UNQUOTE(JSON_EXTRACT(h.payload,'$.decision.result.version')) AS UNSIGNED) DESC,h.source_type,h.source_id,h.revision DESC LIMIT 20 OFFSET ".$offset,[$actor['club_id'],$year])->fetchAll();$items=[];
        foreach($rows as$row){$original=$this->sourceDecisionOriginal($row['source_type'],$row['source_id'],(int)$row['revision'],$row['sha256']);$items[]=['type'=>$original['type'],'id'=>$original['id'],'revision'=>$original['revision'],'sha256'=>$original['sha256'],'description'=>$original['context']['source_label']['description'],'reason'=>$original['context']['request']['reason'],'operation'=>$original['context']['request']['operation'],'at'=>$original['payload']['at']];}
        return ['total'=>$total,'page'=>(int)$page,'pages'=>$pages,'items'=>$items];
    }
    private function flushSourceDecisionHistory(array $actor,int $year):void
    {
        if($this->sourceDecisionRequest===null||!$this->sourceDecisionPending)return;$request=$this->sourceDecisionRequest;$pending=$this->sourceDecisionPending;$this->sourceDecisionPending=[];$primary=[];$others=[];
        foreach($pending as$item){if($item['proposal']['type']===$request['source_type']&&$item['proposal']['id']===$request['source_id'])$primary[]=$item;else$others[]=$item;}
        if(count($primary)!==1)throw new DomainException('Ursprünglichen Belegvorgang prüfen lassen.',503);$pending=[...$primary,...$others];$targets=[];
        foreach($pending as$item)$targets[]=['type'=>$item['proposal']['type'],'id'=>$item['proposal']['id'],'revision'=>$item['previous']?(int)$item['previous']['revision']+1:1,'label'=>$this->sourceDecisionLabel($actor,$item['proposal'])];
        $report=$this->report($year);$context=['format'=>'annual-source-decision-v1','request_key'=>$request['request_key'],'request'=>$request,'actor'=>$actor['id'],'targets'=>$targets,'result'=>['version'=>$report['version'],'annual_result_cents'=>$report['calculation']['annual_result_cents'],'asset_total_cents'=>$report['calculation']['asset_total_cents']],'source_label'=>$this->sourceDecisionLabel($actor,$primary[0]['proposal'])];BalanceSourceDecisionSchema::context($context);$this->sourceDecisionFlushing=true;
        try{foreach($pending as$index=>$item){if($index===0)$this->sourceDecisionContext=$context;else{$first=$this->sourceDecisionFirst;$this->sourceDecisionContext=['format'=>'annual-source-reference-v1','request_key'=>$request['request_key'],'primary_type'=>$first['type'],'primary_id'=>$first['id'],'primary_revision'=>$first['revision'],'primary_sha256'=>$first['sha256']];}$this->sourceHistory($actor,$item['proposal'],$item['ids'],$item['reversals'],$item['previous'],$item['reason']);if($index===0){$target=$targets[0];$sha=$this->q('SELECT sha256 FROM balance_source_history WHERE club_id=? AND source_type=? AND source_id=? AND revision=?',[$actor['club_id'],$target['type'],$target['id'],$target['revision']])->fetchColumn();if(!is_string($sha))throw new DomainException('Ursprünglichen Belegvorgang prüfen lassen.',503);$this->sourceDecisionFirst=$target+['sha256'=>$sha];}}}
        finally{$this->sourceDecisionFlushing=false;$this->sourceDecisionContext=null;}
    }
    private function sourceDecisionLabel(array $actor,array $proposal):array
    {
        $description=$proposal['snapshot']['entry']['description']??$proposal['evidence'];$counterparty='';$account='';
        if($proposal['type']==='depreciation'&&is_string($proposal['snapshot']['annual']['item_id']??null)){$name=$this->q('SELECT name FROM inventory_items WHERE club_id=? AND id=? FOR SHARE NOWAIT',[$actor['club_id'],$proposal['snapshot']['annual']['item_id']])->fetchColumn();if(is_string($name))$description=$name;}
        if($proposal['type']==='transaction'){$row=$this->q('SELECT t.description,t.counterparty,a.name account_name FROM finance_transactions t JOIN finance_accounts a ON a.club_id=t.club_id AND a.id=t.account_id WHERE t.club_id=? AND t.id=? FOR SHARE NOWAIT',[$actor['club_id'],$proposal['id']])->fetch();if(!$row)throw new DomainException('Ursprünglichen eigenen Kontoumsatz prüfen.');$description=$row['description'];$counterparty=$row['counterparty'];$account=$row['account_name'];}
        return ['description'=>$description,'counterparty'=>$counterparty,'account_name'=>$account];
    }
}
