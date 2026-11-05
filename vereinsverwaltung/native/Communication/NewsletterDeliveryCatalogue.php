<?php declare(strict_types=1);
namespace Kulturhus\Native\Communication;

use PDO;
use DomainException;
use Kulturhus\Native\Shared\{Access,Db,SecretBox};

/** Read-only, complete delivery history. At most one private message is decrypted at a time. */
final class NewsletterDeliveryCatalogue
{
    public const KINDS=['all'=>'Alle Mailarten','campaign'=>'Newsletterausgabe','test'=>'Testausgabe','confirm'=>'Anmeldebestätigung','reminder'=>'Bestätigungserinnerung','renewal'=>'Erneute Bestätigung'];
    public const STATES=PressDeliveryCatalogue::STATES;
    public const PAGE_SIZE=6;
    public const REASONS=['consent_withdrawn'=>'Abmeldung','authority_or_consent_changed'=>'Berechtigung oder Einwilligung geändert','transport_result_unknown'=>'Versandergebnis offen','newsletter_interrupted_review'=>'Unterbrochener Versand · Ergebnis prüfen','digest_period_paused'=>'Periode pausiert','digest_send_window_not_started'=>'Versandfenster beginnt später','digest_send_window_expired'=>'Versandfenster abgelaufen']+\Kulturhus\Native\Delivery\DeliveryNotStarted::LABELS;
    public static function state(array $query): array
    {
        $state=$query['newsletter_state']??'all';$kind=$query['newsletter_kind']??'all';
        if(!is_string($state)||!isset(self::STATES[$state])||!is_string($kind)||!isset(self::KINDS[$kind]))throw new DomainException('Zustellfilter prüfen.');
        $page=SubscriberSelection::number($query['newsletter_page']??1);$item=$query['newsletter_item']??'';$focus=$query['newsletter_job']??'';
        foreach([$item,$focus]as$id){if(!is_string($id))throw new DomainException('Zustellung auswählen.');if($id!=='')Content::id($id);}
        return ['state'=>$state,'kind'=>$kind,'page'=>$page,'item'=>$item,'focus'=>$focus];
    }
    public static function page(PDO $db,Access $access,SecretBox $box,array $query=[]): array
    {
        $actor=$access->require('newsletter','read');$club=$actor['club_id'];$state=self::state($query);
        $join=' FROM native_delivery_jobs j LEFT JOIN native_delivery_sources s ON s.job_id=j.id AND s.club_id=j.club_id AND s.module=\'newsletter\'';
        $where="j.club_id=? AND j.kind IN ('campaign','test','confirm','reminder','renewal')";$args=[$club];
        if($state['item']!==''){$where.=' AND s.item_id=?';$args[]=$state['item'];}
        if($state['kind']!=='all'){$where.=' AND j.kind=?';$args[]=$state['kind'];}
        $counts=[];foreach(Db::run($db,'SELECT j.status,COUNT(*) total'.$join.' WHERE '.$where.' GROUP BY j.status',$args)->fetchAll()as$r)$counts[$r['status']]=(int)$r['total'];
        if($state['state']!=='all'){$where.=' AND j.status=?';$args[]=$state['state'];}
        $columns='j.id,j.kind,j.status,j.attempts,j.due_at,j.started_at,j.completed_at,j.external_id,j.reason,s.item_id,s.item_version';
        $focus=null;
        if($state['focus']!==''){
            $focus=Db::run($db,'SELECT '.$columns.$join.' WHERE '.$where.' AND j.id=?',[...$args,$state['focus']])->fetch();
            if(!$focus)throw new DomainException('Zustellung in diesem Verlauf nicht verfügbar.');
            $ahead=(int)Db::run($db,'SELECT COUNT(*)'.$join.' WHERE '.$where.' AND (j.due_at>? OR (j.due_at=? AND j.id>?))',[...$args,$focus['due_at'],$focus['due_at'],$focus['id']])->fetchColumn();$state['page']=1+intdiv($ahead,self::PAGE_SIZE);
        }
        $total=(int)Db::run($db,'SELECT COUNT(*)'.$join.' WHERE '.$where,$args)->fetchColumn();$pages=max(1,(int)ceil($total/self::PAGE_SIZE));$state['page']=min($state['page'],$pages);
        $rows=Db::run($db,'SELECT '.$columns.$join.' WHERE '.$where.' ORDER BY j.due_at DESC,j.id DESC LIMIT 6 OFFSET '.(($state['page']-1)*6),$args)->fetchAll(PDO::FETCH_ASSOC);
        $proofs=[];
        foreach($rows as&$row){$payload=self::payload($db,$access,$box,$row,$proofs);$row+=self::metadata($payload);unset($payload);}unset($row);
        $selected=null;
        if($focus){$payload=self::payload($db,$access,$box,$focus,$proofs);$selected=$focus+self::metadata($payload);$selected['message']=self::message($payload);$reference=array_intersect_key($payload,['newsletter_original'=>true,'approval'=>true]);unset($payload);$selected['original']=self::original($db,$club,$focus,$reference);}
        foreach($proofs as$id=>$hash){$current=Db::run($db,'SELECT SHA2(ciphertext,256) FROM native_delivery_jobs WHERE club_id=? AND id=?',[$club,$id])->fetchColumn();if(!is_string($current)||!hash_equals($hash,$current))throw new DomainException('Zustellung inzwischen geändert. Verlauf neu öffnen.');}
        $access->require('newsletter','read');
        return ['rows'=>$rows,'selected'=>$selected,'total'=>$total,'pages'=>$pages,'counts'=>$counts,...$state];
    }
    private static function payload(PDO $db,Access $access,SecretBox $box,array $row,array &$proofs): array
    {
        $a=$access->require('newsletter','read');
        // Destroy the statement and its client buffer before opening this one ciphertext.
        $cipher=Db::run($db,'SELECT ciphertext FROM native_delivery_jobs WHERE club_id=? AND id=? AND kind=?',[$a['club_id'],$row['id'],$row['kind']])->fetchColumn();
        if(!is_string($cipher))throw new DomainException('Zustellung nicht mehr verfügbar.');
        $hash=hash('sha256',$cipher);if(isset($proofs[$row['id']])&&!hash_equals($proofs[$row['id']],$hash))throw new DomainException('Zustellung inzwischen geändert. Verlauf neu öffnen.');$proofs[$row['id']]=$hash;
        return $box->open('job:'.$a['club_id'].':'.$row['id'],$cipher);
    }
    private static function metadata(array $payload): array
    {
        $removed=$payload===[];$email=$removed?'':Content::email($payload['to']??'');
        return ['email'=>$email,'subject'=>Content::text($payload['subject']??'',320),'removed'=>$removed,'prepared'=>is_string($payload['text']??null)||is_string($payload['html']??null)];
    }
    private static function message(array $payload): string
    {
        $text=$payload['text']??null;
        if(!is_string($text)){$html=$payload['html']??'';$text=is_string($html)?\Kulturhus\Native\Shared\RichText::plain($html):'';}
        // Reading delivery history never gives another person a working consent/unsubscribe token.
        return preg_replace('~https?://[^\s<>"\']+/newsletter/(?:bestaetigen|abmelden|ausgabe)/\?token=[a-f0-9]{64}~i','[Persönlicher Newsletterlink]',$text)??'';
    }
    private static function original(PDO $db,string $club,array $row,array $payload): ?array
    {
        if($payload===[])return null;
        $reference=$payload['newsletter_original']??null;
        if($reference!==null){
            if(!is_array($reference)||!is_string($reference['item']??null)||!is_int($reference['version']??null)||$reference['version']<1||!is_string($reference['view_hash']??null)||!preg_match('/\A[a-f0-9]{64}\z/D',$reference['view_hash']))throw new DomainException('Ursprüngliche Newsletterfassung prüfen.');
            Content::id($reference['item']);
            $source=Db::run($db,'SELECT item_id,item_version,snapshot_json FROM native_campaign_views WHERE club_id=? AND token_hash=?',[$club,$reference['view_hash']])->fetch();
            if(!$source||$source['item_id']!==$reference['item']||(int)$source['item_version']!==$reference['version'])throw new DomainException('Ursprüngliche Newsletterfassung nicht verfügbar.');
            if(!is_string($reference['snapshot_sha256']??null)||!preg_match('/\A[a-f0-9]{64}\z/D',$reference['snapshot_sha256'])||!hash_equals($reference['snapshot_sha256'],hash('sha256',$source['snapshot_json'])))throw new DomainException('Gespeicherte Newsletterfassung stimmt nicht mit dem ursprünglichen Auftrag überein.');
            if($row['item_id']!==null&&($row['item_id']!==$source['item_id']||(int)$row['item_version']!==(int)$source['item_version']))throw new DomainException('Newsletterbezug der Zustellung prüfen.');
            $snapshot=json_decode($source['snapshot_json'],true,64,JSON_THROW_ON_ERROR);
            return ['id'=>$source['item_id'],'version'=>(int)$source['item_version'],'title'=>Content::text($snapshot['title']??'',300,true),'sha256'=>hash('sha256',$source['snapshot_json'])];
        }
        if($row['item_id']!==null){
            $approval=$payload['approval']??[];
            if(($approval['item']??null)!==$row['item_id']||($approval['version']??null)!==(int)$row['item_version'])throw new DomainException('Originalbezug des Newsletterauftrags prüfen.');
            $json=Db::run($db,'SELECT payload FROM khn_newsletter_revisions WHERE club_id=? AND item_id=? AND version=?',[$club,$row['item_id'],$row['item_version']])->fetchColumn();
            if(!is_string($json))throw new DomainException('Gespeicherte Newsletterfassung nicht verfügbar.');$snapshot=json_decode($json,true,64,JSON_THROW_ON_ERROR);
            return ['id'=>$row['item_id'],'version'=>(int)$row['item_version'],'title'=>Content::text($snapshot['title']??'',300,true),'sha256'=>hash('sha256',$json)];
        }
        return null;
    }
}
