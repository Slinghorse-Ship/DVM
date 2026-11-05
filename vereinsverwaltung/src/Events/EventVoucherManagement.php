<?php declare(strict_types=1);
namespace Kulturhus\Verein;

use DomainException;
use PDO;

/** Native general management reads retain explicit board/helper boundaries. */
trait EventVoucherManagement
{
    public function sourcesPage(array $query=[]):array
    {
        return $this->readContext(function()use($query):array{
            [$search,$page]=$this->pageInput($query);$actor=$this->manager();
            $rows=$this->q('SELECT id,club_id,title,starts_at,ends_at,location,activity_state FROM appointments WHERE club_id=?'.$this->currentLock(),[$actor['club_id']])->fetchAll();
            if($this->website)$rows=array_merge($rows,$this->website->choiceIndex($actor['club_id'],true,true));
            $events=$this->q('SELECT id,source_id,enabled FROM event_voucher_events WHERE club_id=?'.$this->currentLock(),[$actor['club_id']])->fetchAll();$existing=[];
            foreach($events as$event)$existing[$event['source_id']]=$event;
            $needle=mb_strtolower($search);$out=[];
            foreach($rows as$row){
                if($search!==''&&!str_contains(mb_strtolower($row['title'].' '.($row['location']??'').' '.$row['starts_at'].' '.$row['id']),$needle))continue;
                $row['cancelled']=!empty($row['cancelled'])||($row['activity_state']??'')==='cancelled';$row['voucher_event']=$existing[$row['id']]['id']??'';$row['enabled']=isset($existing[$row['id']])?(bool)$existing[$row['id']]['enabled']:null;$out[]=$row;
            }
            usort($out,static fn(array $left,array $right):int=>[$right['starts_at'],$right['id']]<=>[$left['starts_at'],$left['id']]);
            return$this->window($out,$search,$page);
        });
    }

    public function membersPage(array $query=[]):array
    {
        return $this->readContext(function()use($query):array{
            [$search,$page]=$this->pageInput($query);$rows=$this->members();$needle=mb_strtolower($search);
            if($search!=='')$rows=array_values(array_filter($rows,static fn(array $row):bool=>str_contains(mb_strtolower($row['id'].' '.$row['name'].' '.$row['email']),$needle)));
            return$this->window($rows,$search,$page);
        });
    }

    public function helpersPage(array $query=[]):array
    {
        return $this->readContext(function()use($query):array{
            [$search,$page]=$this->pageInput($query);$rows=$this->helperChoices();$needle=mb_strtolower($search);
            if($search!=='')$rows=array_values(array_filter($rows,static fn(array $row):bool=>str_contains(mb_strtolower($row['id'].' '.$row['name']),$needle)));
            return$this->window($rows,$search,$page);
        });
    }

    public function selectedPeople(array $ids,bool $helpers=false):array
    {
        return $this->readContext(function()use($ids,$helpers):array{
            $choices=array_column($helpers?$this->helperChoices():$this->members(),null,'id');$rows=[];
            foreach($ids as$id){
                if(!is_string($id)||$id===''||mb_strlen($id)>128||preg_match('//u',$id)!==1||preg_match('/[\x00-\x1f\x7f]/',$id))throw new DomainException('Gültige Personenauswahl verwenden.',422);
                $rows[]=['id'=>$id,'name'=>$choices[$id]['name']??$id,'available'=>isset($choices[$id])];
            }
            return$rows;
        });
    }

    public function managementPrintSnapshot(string $event,string $person):array
    {
        return $this->readContext(function()use($event,$person):array{
            if(!$this->canRedeem($event))throw new DomainException('Keine Berechtigung für die Gutscheinverwaltung.',403);
            return$this->printSnapshot($event,$person);
        });
    }

    public function managementPrintStatus(string $event,string $person):array
    {
        return $this->readContext(function()use($event,$person):array{
            if(!$this->canRedeem($event))throw new DomainException('Keine Berechtigung für die Gutscheinverwaltung.',403);
            $scope=$this->readScope($event,false);$actor=$scope['actor'];
            $contact=$this->q('SELECT c.id,c.name FROM contacts c WHERE c.club_id=? AND c.id=? AND EXISTS(SELECT 1 FROM event_vouchers v WHERE v.club_id=c.club_id AND v.contact_id=c.id AND v.event_id=?'.$this->currentLock().')'.$this->currentLock(),[$actor['club_id'],$person,$event])->fetch();
            if(!$contact)throw new DomainException('Keine Berechtigung für diese persönlichen Gutscheine.',403);
            $display=$this->sourceForDisplay($scope['event']);$count=$this->currentPrintCount($scope,$person);
            $notice=!$display['available']?$display['notice']:($count>200?'Höchstens 200 Gutscheine pro Druckdatei.':($count===0?'Keine offenen Gutscheine für dieses Mitglied.':''));
            if($notice==='')$this->printSnapshot($event,$person);
            return ['person'=>$contact,'count'=>$count,'available'=>$notice==='','notice'=>$notice];
        });
    }

    public function moveTarget(string $event,string $move):array
    {
        return $this->readContext(function()use($event,$move):array{
            $scope=$this->readScope($event,false);if(!$scope['all_people'])throw new DomainException('Keine Einlöseberechtigung für diese Veranstaltung.',403);
            $row=$this->q('SELECT m.*,v.label,v.unit,v.state AS voucher_state,c.name,EXISTS(SELECT 1 FROM event_voucher_moves r WHERE r.club_id=m.club_id AND r.reversal_of=m.id'.$this->currentLock().') reversed FROM event_voucher_moves m JOIN event_vouchers v ON v.club_id=m.club_id AND v.id=m.voucher_id JOIN contacts c ON c.club_id=v.club_id AND c.id=v.contact_id WHERE m.club_id=? AND v.event_id=? AND m.id=?'.$this->currentLock(),[$scope['actor']['club_id'],$event,$move])->fetch();
            if(!$row)throw new DomainException('Ursprüngliche Einlösung nicht verfügbar.',403);
            return$row;
        });
    }

    public function formTarget(string $event,string $voucher,string $ticket=''):array
    {
        return $this->readContext(function()use($event,$voucher,$ticket):array{
            $scope=$this->readScope($event,false);if(!$scope['all_people'])throw new DomainException('Keine Einlöseberechtigung für diese Veranstaltung.',403);
            $row=$this->q('SELECT v.*,c.name FROM event_vouchers v JOIN contacts c ON c.club_id=v.club_id AND c.id=v.contact_id WHERE v.club_id=? AND v.event_id=? AND v.id=?'.$this->currentLock(),[$scope['actor']['club_id'],$event,$voucher])->fetch();
            if(!$row)throw new DomainException('Gutschein nicht verfügbar.',403);
            $row['ticket_id']='';$row['display_state']=$row['state']==='revoked'?'revoked':((int)$row['remaining']===0?'redeemed':'active');
            if($row['unit']==='piece'){
                $piece=$this->q('SELECT id,code,state,unit_no FROM event_voucher_tickets WHERE club_id=? AND voucher_id=? AND id=?'.$this->currentLock(),[$row['club_id'],$voucher,$ticket])->fetch();
                if(!$piece)throw new DomainException('Ursprünglichen Einzelgutschein auswählen.',422);
                $row['ticket_id']=$piece['id'];$row['code']=$piece['code'];$row['ticket_number']=(int)$piece['unit_no'];$row['ticket_total']=(int)$row['granted'];$row['remaining']=$piece['state']==='active'?1:0;$row['display_state']=$piece['state'];
            }elseif($ticket!=='')throw new DomainException('Passenden Wertgutschein auswählen.',422);
            return$row;
        });
    }

    public function currentFormVersion(string $event,string $voucher=''):array
    {
        return $this->readContext(function()use($event,$voucher):array{
            $scope=$this->readScope($event,false);if(!$scope['all_people'])throw new DomainException('Keine Einlöseberechtigung für diese Veranstaltung.',403);
            if($voucher==='')return ['version'=>(string)$scope['event']['version'],'enabled'=>(bool)$scope['event']['enabled']];
            $row=$this->q('SELECT id,version,state,remaining,unit,label FROM event_vouchers WHERE club_id=? AND event_id=? AND id=?'.$this->currentLock(),[$scope['actor']['club_id'],$event,$voucher])->fetch();
            if(!$row)throw new DomainException('Gutschein nicht verfügbar.',403);
            return$row;
        });
    }

    public function managementWorkspace(array $query):array
    {
        return $this->readContext(function()use($query):array{
            $access=$this->workspaceAccess();if(!$access['manage']&&!$access['helpers'])throw new DomainException('Keine Berechtigung für die Gutscheinverwaltung.',403);
            $task=$query['voucher_task']??'cards';if(!is_string($task)||!in_array($task,['cards','grant','configure','receipts','mail','print'],true))throw new DomainException('Gültigen Gutscheinbereich auswählen.',422);
            if(in_array($task,['grant','configure','mail'],true)&&!$access['manage'])throw new DomainException('Keine Berechtigung für die Gutscheinverwaltung.',403);
            $event=$query['voucher_event']??'';if(!is_string($event)||($event!==''&&!preg_match('/^[a-f0-9]{32}$/D',$event)))throw new DomainException('Veranstaltung nicht verfügbar.',403);
            $result=['personal'=>false,'access'=>$access,'task'=>$task,'event'=>null,'events'=>null,'sources'=>null,'cards'=>null,'history'=>null,'members'=>null,'helpers'=>null,'receipts'=>null,'receipt'=>null,'mail_originals'=>null,'mail_originals_available'=>false,'errors'=>[],'http_status'=>0];
            if(array_key_exists('voucher_receipt',$query)){
                if(!is_string($query['voucher_receipt']))throw new DomainException('Gutscheinbestätigung nicht verfügbar.',404);
                $result['receipt']=$this->confirmation($query['voucher_receipt']);$result['task']=$task='receipts';
            }
            $readPage=function(string $section,callable $read)use(&$result,$query):void{
                try{$result[$section]=$read(['query'=>$query['voucher_'.$section.'_query']??'','page'=>$query['voucher_'.$section.'_page']??1]);}
                catch(DomainException $failure){$result['errors'][$section]=$failure->getMessage();$result['http_status']=max($result['http_status'],$failure->getCode()===503?503:422);}
            };
            if($task==='receipts'){
                $readPage('receipts',fn(array $input):array=>$this->confirmationsPage($input));
                if(($result['receipt']['payload'][0]??null)==='mail-all'){
                    $result['mail_originals_available']=is_array($result['receipt']['context']['mail']['deliveries']??null);
                    $readPage('mail_originals',fn(array $input):array=>$this->confirmationMailPage($result['receipt']['key'],$input));
                }
                return$result;
            }
            if($event===''){
                $readPage('events',fn(array $input):array=>$this->eventsPage($input));
                if($access['manage'])$readPage('sources',fn(array $input):array=>$this->sourcesPage($input));
                return$result;
            }
            $scope=$this->readScope($event,false);$result['event']=$this->sourceForDisplay($scope['event']);
            if($task==='print'){$person=$query['print_person']??null;if(!is_string($person))throw new DomainException('Keine Berechtigung für diese persönlichen Gutscheine.',403);$result['print']=$this->managementPrintStatus($event,$person);}
            if($task==='cards'){
                $readPage('cards',fn(array $input):array=>$this->cardsPage($event,$input+['state'=>$query['voucher_card_state']??'all']));
                $readPage('history',fn(array $input):array=>$this->historyPage($event,$input));
            }elseif($task==='grant')$readPage('members',fn(array $input):array=>$this->membersPage($input));
            elseif($task==='configure'){
                $readPage('helpers',fn(array $input):array=>$this->helpersPage($input));
                $result['selected_helpers']=$this->helpers($event);
            }
            return$result;
        });
    }
}
