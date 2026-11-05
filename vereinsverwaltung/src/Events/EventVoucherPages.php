<?php declare(strict_types=1);
namespace Kulturhus\Verein;

use DomainException;
use PDO;

/** Shallow native pages keep each original piece once, with no repeated ticket arrays. */
trait EventVoucherPages
{
    public function workspaceAccess():array
    {
        return $this->readContext(function():array{
            $actor=$this->actor();$manage=$this->canManage();$helpers=$manage?[]:$this->currentHelpers($actor);
            $profile=$this->availableProfile($actor,false);
            if(!$manage&&!$helpers&&!$profile)throw new DomainException('Eigenes Mitgliederprofil für Gutscheine nicht verfügbar.');
            return ['actor'=>$actor,'manage'=>$manage,'helpers'=>$helpers,'profile_available'=>$profile];
        });
    }

    private function pageInput(array $query):array
    {
        $search=array_key_exists('query',$query)?$query['query']:'';
        $page=array_key_exists('page',$query)?$query['page']:1;
        if(!is_string($search)||preg_match('//u',$search)!==1||mb_strlen($search)>240||preg_match('/[\x00-\x1f\x7f]/',$search))throw new DomainException('Gutscheinsuche mit höchstens 240 Zeichen eingeben.',422);
        if((!is_int($page)&&!is_string($page))||!preg_match('/^[1-9][0-9]{0,8}$/D',(string)$page))throw new DomainException('Gültige Gutscheinseite auswählen.',422);
        return[$search,(int)$page];
    }

    private function window(array $rows,string $search,int $page):array
    {
        $total=count($rows);$pages=max(1,(int)ceil($total/25));
        if($page>$pages)throw new DomainException('Diese Gutscheinseite ist nicht verfügbar.',422);
        return ['rows'=>array_slice($rows,($page-1)*25,25),'total'=>$total,'page'=>$page,'pages'=>$pages,'per_page'=>25,'query'=>$search];
    }

    /** Source metadata may remain readable when issue is closed; availability stays explicit. */
    private function sourceForDisplay(array $event):array
    {
        $available=(bool)$event['enabled'];$notice=$available?'':'Die Gutscheinausgabe für diese Veranstaltung ist geschlossen.';
        try{
            $source=$this->currentSource($event);
            foreach(['title','starts_at','location']as$field)if(is_string($source[$field]??null))$event[$field]=$source[$field];
            try{$this->notCancelled($source);}catch(DomainException $failure){$available=false;$notice=$failure->getMessage();}
        }catch(DomainException $failure){if($failure->getCode()===503)throw$failure;$available=false;$notice=$failure->getMessage();}
        return ['event'=>$event,'available'=>$available,'notice'=>$notice];
    }

    private function readScope(string $event,bool $own):array
    {
        $actor=$this->actor();$row=$this->event($event);$redeem=$this->canRedeem($event);
        if($own||!$redeem){
            $this->availableProfile($actor);
            if(!$this->q('SELECT id FROM event_vouchers WHERE club_id=? AND event_id=? AND contact_id=?'.$this->currentLock(),[$actor['club_id'],$event,$actor['contact_id']])->fetchColumn())throw new DomainException('Veranstaltung nicht verfügbar.');
        }
        return ['actor'=>$actor,'event'=>$row,'all_people'=>!$own&&$redeem];
    }

    /** Current shallow card index: individual codes appear once, never inside every other card. */
    private function cardIndex(array $scope,bool $activeOnly=false):array
    {
        $voucherFilter=$activeOnly?" AND v.state='active' AND v.remaining>0":'';$ticketFilter=$activeOnly?" AND t.state='active'":'';
        $actor=$scope['actor'];$args=[$actor['club_id'],$scope['event']['id'],(int)$scope['all_people'],$actor['contact_id']];
        $vouchers=$this->q("SELECT v.*,c.name FROM event_vouchers v JOIN contacts c ON c.club_id=v.club_id AND c.id=v.contact_id WHERE v.club_id=? AND v.event_id=? AND (?=1 OR v.contact_id=?)".$voucherFilter." ORDER BY c.name,c.id,v.created_at,v.id".$this->currentLock(),$args)->fetchAll();
        $tickets=$this->q("SELECT t.id,t.voucher_id,t.unit_no,t.code,t.state FROM event_voucher_tickets t JOIN event_vouchers v ON v.club_id=t.club_id AND v.id=t.voucher_id WHERE v.club_id=? AND v.event_id=? AND (?=1 OR v.contact_id=?)".$voucherFilter.$ticketFilter." ORDER BY v.id,t.unit_no".$this->currentLock(),$args)->fetchAll();
        $byVoucher=[];foreach($tickets as$ticket)$byVoucher[$ticket['voucher_id']][]=$ticket;
        $cards=[];$errors=[];$invalidPeople=[];
        foreach($vouchers as$voucher){
            $voucher['parent_code']=$voucher['code'];$voucher['ticket_id']='';$voucher['consistent']=true;
            if($voucher['unit']!=='piece'){$voucher['display_state']=$voucher['state']==='revoked'?'revoked':((int)$voucher['remaining']===0?'redeemed':'active');$cards[]=$voucher;continue;}
            $pieces=$byVoucher[$voucher['id']]??[];$active=count(array_filter($pieces,static fn(array $piece):bool=>$piece['state']==='active'));
            $consistent=($activeOnly||count($pieces)===(int)$voucher['granted'])&&$active===(int)$voucher['remaining']&&($voucher['state']!=='revoked'||$active===0);
            if(!$consistent){$errors[$voucher['id']]='Gutscheinbestand bitte prüfen lassen.';$invalidPeople[$voucher['contact_id']]=true;}
            foreach($pieces as$piece){
                $card=$voucher;$card['ticket_id']=$piece['id'];$card['code']=$piece['code'];$card['state']=$piece['state'];
                $card['granted']=1;$card['remaining']=$piece['state']==='active'?1:0;$card['ticket_number']=(int)$piece['unit_no'];$card['ticket_total']=(int)$voucher['granted'];$card['consistent']=$consistent;$card['display_state']=$card['state'];$cards[]=$card;
            }
        }
        return ['cards'=>$cards,'errors'=>array_values($errors),'invalid_people'=>array_keys($invalidPeople)];
    }

    public function eventsPage(array $query=[],bool $own=false):array
    {
        return $this->readContext(function()use($query,$own):array{
            [$search,$page]=$this->pageInput($query);$actor=$this->actor();if($own)$this->availableProfile($actor);
            $events=$this->events();$rows=[];
            foreach($events as$event){
                if($own&&!$this->q('SELECT id FROM event_vouchers WHERE club_id=? AND event_id=? AND contact_id=?'.$this->currentLock(),[$actor['club_id'],$event['id'],$actor['contact_id']])->fetchColumn())continue;
                $display=$this->sourceForDisplay($event);
                $needle=mb_strtolower($search);
                if($search!==''&&!str_contains(mb_strtolower($display['event']['title'].' '.$display['event']['location'].' '.$display['event']['starts_at']),$needle))continue;
                $rows[]=$display;
            }
            usort($rows,static fn(array $a,array $b):int=>[$b['event']['starts_at'],$b['event']['id']]<=>[$a['event']['starts_at'],$a['event']['id']]);
            return $this->window($rows,$search,$page);
        });
    }

    public function cardsPage(string $event,array $query=[],bool $own=false):array
    {
        return $this->readContext(function()use($event,$query,$own):array{
            [$search,$page]=$this->pageInput($query);$scope=$this->readScope($event,$own);$display=$this->sourceForDisplay($scope['event']);
            $state=array_key_exists('state',$query)?$query['state']:'all';
            if(!is_string($state)||!in_array($state,['all','active','redeemed','revoked'],true))throw new DomainException('Gutscheinstand auswählen.',422);
            try{$code=EventVoucherQr::searchCode($search,$event);}catch(DomainException $failure){throw new DomainException($failure->getMessage(),422,$failure);}$index=$this->cardIndex($scope);$needle=mb_strtolower($search);
            $rows=array_values(array_filter($index['cards'],static function(array $card)use($state,$search,$code,$needle):bool{
                if($state!=='all'&&$card['display_state']!==$state)return false;
                if($search==='')return true;
                if($card['code']===$code||$card['parent_code']===$code)return true;
                return !str_starts_with(strtoupper(trim($search)),'KH:')&&str_contains(mb_strtolower($card['name'].' '.$card['label']),$needle);
            }));
            $print=[];foreach($index['invalid_people']as$person)$print[$person]=['count'=>0,'consistent'=>false];foreach($index['cards']as$card){$person=$card['contact_id'];$print[$person]??=['count'=>0,'consistent'=>true];$print[$person]['consistent']=$print[$person]['consistent']&&$card['consistent'];if($card['display_state']==='active'&&(int)$card['remaining']>0)$print[$person]['count']++;}
            return $this->window($rows,$search,$page)+$display+['errors'=>$index['errors'],'state'=>$state,'own'=>$own,'can_redeem'=>!$own&&$scope['all_people'],'print_people'=>$print];
        });
    }

    public function historyPage(string $event,array $query=[],bool $own=false):array
    {
        return $this->readContext(function()use($event,$query,$own):array{
            [$search,$page]=$this->pageInput($query);$scope=$this->readScope($event,$own);$actor=$scope['actor'];
            $rows=$this->q("SELECT m.*,v.label,v.unit,v.state AS voucher_state,c.name,EXISTS(SELECT 1 FROM event_voucher_moves r WHERE r.club_id=m.club_id AND r.reversal_of=m.id".$this->currentLock().") reversed FROM event_voucher_moves m JOIN event_vouchers v ON v.club_id=m.club_id AND v.id=m.voucher_id JOIN contacts c ON c.club_id=v.club_id AND c.id=v.contact_id WHERE v.club_id=? AND v.event_id=? AND (?=1 OR v.contact_id=?) ORDER BY m.created_at DESC,m.id DESC".$this->currentLock(),[$actor['club_id'],$event,(int)$scope['all_people'],$actor['contact_id']])->fetchAll();
            if($search!=='')$rows=array_values(array_filter($rows,static fn(array $row):bool=>str_contains(mb_strtolower($row['name'].' '.$row['label'].' '.$row['reason']),mb_strtolower($search))));
            return $this->window($rows,$search,$page);
        });
    }

    private function currentPrintCount(array $scope,string $person):int
    {
        $budget=$this->q("SELECT unit,remaining FROM event_vouchers WHERE club_id=? AND event_id=? AND contact_id=? AND state='active' AND remaining>0 ORDER BY id LIMIT 201".$this->currentLock(),[$scope['actor']['club_id'],$scope['event']['id'],$person])->fetchAll();
        $count=0;foreach($budget as$balance){$count+=$balance['unit']==='piece'?(int)$balance['remaining']:1;if($count>200)break;}
        return$count;
    }

    /** Bound the authorized active pieces before expanding any QR or producing a PDF. */
    public function printSnapshot(string $event,string $person,bool $own=false):array
    {
        return $this->readContext(function()use($event,$person,$own):array{
            $scope=$this->readScope($event,$own);$actor=$scope['actor'];
            if(($own||!$scope['all_people'])&&$person!==$actor['contact_id'])throw new DomainException('Keine Berechtigung für diese persönlichen Gutscheine.');
            $display=$this->sourceForDisplay($scope['event']);
            if(!$display['available'])throw new DomainException($display['notice'],409);
            $count=$this->currentPrintCount($scope,$person);
            // The event row is held current; all canonical writers lock that row before balances.
            if($count>200)throw new DomainException('Höchstens 200 Gutscheine pro Druckdatei.',422);
            if($count===0)throw new DomainException('Keine offenen Gutscheine für dieses Mitglied.',422);
            $scope['all_people']=false;$scope['actor']['contact_id']=$person;$index=$this->cardIndex($scope,true);
            if($index['errors'])throw new DomainException('Gutscheinbestand bitte prüfen lassen.');
            $cards=array_values(array_filter($index['cards'],static fn(array $card):bool=>$card['state']==='active'&&(int)$card['remaining']>0));
            if(count($cards)!==$count)throw new DomainException('Gutscheinbestand bitte prüfen lassen.');
            return ['event_id'=>$event,'title'=>$display['event']['title'],'date'=>$display['event']['starts_at'],'person'=>['name'=>$cards[0]['name']],'vouchers'=>$cards];
        });
    }

    public function personalWorkspace(array $query):array
    {
        return $this->readContext(function()use($query):array{
            $access=$this->workspaceAccess();$scope=$query['voucher_scope']??null;
            if(array_key_exists('voucher_scope',$query)&&(!is_string($scope)||!in_array($scope,['own','manage'],true)))throw new DomainException('Persönliche Ansicht oder Gutscheinverwaltung auswählen.',422);
            $canWork=$access['manage']||$access['helpers']!==[];
            if($scope==='manage'&&!$canWork)throw new DomainException('Keine Berechtigung für die Gutscheinverwaltung.');
            $personal=$scope==='own'||(!$canWork&&$scope===null);
            $result=['personal'=>$personal,'access'=>$access,'event'=>null,'events'=>null,'cards'=>null,'history'=>null,'errors'=>[],'http_status'=>0];
            if(!$personal)return$result;
            $this->availableProfile($access['actor']);
            if(array_key_exists('print_person',$query)&&(!is_string($query['print_person'])||$query['print_person']!==$access['actor']['contact_id']))throw new DomainException('Keine Berechtigung für diese persönlichen Gutscheine.');
            $event=array_key_exists('voucher_event',$query)?$query['voucher_event']:'';
            if(!is_string($event)||($event!==''&&!preg_match('/^[a-f0-9]{32}$/D',$event)))throw new DomainException('Veranstaltung nicht verfügbar.',403);
            if($event===''){
                try{$result['events']=$this->eventsPage(['query'=>array_key_exists('voucher_event_query',$query)?$query['voucher_event_query']:'','page'=>array_key_exists('voucher_event_page',$query)?$query['voucher_event_page']:1],true);}
                catch(DomainException $failure){$result['errors']['events']=$failure->getMessage();$result['http_status']=$failure->getCode()===503?503:422;}
                return$result;
            }
            $authorized=$this->readScope($event,true);$result['event']=$this->sourceForDisplay($authorized['event']);
            $count=$this->currentPrintCount($authorized,$access['actor']['contact_id']);
            $result['print']=['available'=>$result['event']['available']&&$count>0&&$count<=200,'notice'=>$count>200?'Höchstens 200 Gutscheine pro Druckdatei.':($count===0?'Keine offenen Gutscheine für dieses Mitglied.':'')];
            foreach(['cards','history']as$section){
                try{
                    $input=['query'=>array_key_exists('voucher_'.$section.'_query',$query)?$query['voucher_'.$section.'_query']:'','page'=>array_key_exists('voucher_'.$section.'_page',$query)?$query['voucher_'.$section.'_page']:1];
                    $result[$section]=$section==='cards'?$this->cardsPage($event,$input+['state'=>$query['voucher_card_state']??'all'],true):$this->historyPage($event,$input,true);
                }catch(DomainException $failure){$result['errors'][$section]=$failure->getMessage();$result['http_status']=max($result['http_status'],$failure->getCode()===503?503:422);}
            }
            if(($result['cards']['errors']??[])!==[])$result['print']=['available'=>false,'notice'=>'Gutscheinbestand bitte prüfen lassen.'];
            return$result;
        });
    }
}
