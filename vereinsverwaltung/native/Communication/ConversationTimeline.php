<?php declare(strict_types=1);
namespace Kulturhus\Native\Communication;
use DomainException;
use Kulturhus\Native\Shared\Access;

/** Page native originals and unsynchronized mail answers together; decrypt only selected mails. */
final class ConversationTimeline
{
    public function __construct(private Replies $replies){}
    private function references(Access $access,array $originals,?array $source,?string $connection):array
    {
        $access->require('social','manage');
        if(count($originals)>5000)throw new DomainException('Nachrichtenverlaufgrenze erreicht.');
        $refs=[];$remote=[];
        foreach($originals as$index=>$message){$remote[$message['id']]=true;$refs[]=['id'=>$message['id'],'date'=>$message['created_at'],'index'=>$index];}
        if($source&&$connection)foreach($this->replies->mailReferences($access,$source,$connection)as$mail){
            if($mail['remote_id']&&isset($remote[$mail['remote_id']]))continue;
            $refs[]=['id'=>'mail:'.$mail['mail_id'],'date'=>$mail['created_at'],'mail'=>$mail['mail_id']];
        }
        if($source&&$connection)foreach($this->replies->humanReferences($access,$source,$connection)as$human){
            if($human['remote_id']&&isset($remote[$human['remote_id']]))continue;
            $refs[]=['id'=>'human:'.$human['id'],'date'=>$human['created_at'],'human'=>$human['id']];
        }
        if(count($refs)>10000)throw new DomainException('Nachrichtenverlaufgrenze erreicht.');
        usort($refs,static fn($a,$b)=>[$a['date'],$a['id']]<=>[$b['date'],$b['id']]);
        return $refs;
    }
    /** Open a search result on its actual current page, including after newer arrivals. */
    public function pageContaining(Access $access,array $originals,?array $source,?string $connection,string $id):int
    {
        $refs=$this->references($access,$originals,$source,$connection);$total=count($refs);
        foreach($refs as$index=>$ref)if($ref['id']===$id)return (int)ceil(($total-$index)/40);
        throw new DomainException('Suchtreffer nicht mehr im gewählten Gespräch.');
    }
    public function page(Access $access,array $originals,?array $source,?string $connection,mixed $page=1):array
    {
        $access->require('social','manage');
        $page=filter_var($page,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>250]]);
        if($page===false||count($originals)>5000)throw new DomainException('Nachrichtenseite ungültig.');
        $refs=$this->references($access,$originals,$source,$connection);
        $total=count($refs);$pages=max(1,(int)ceil($total/40));if($page>$pages)throw new DomainException('Nachrichtenseite nicht verfügbar.');
        $offset=max(0,$total-$page*40);$length=min(40,max(0,$total-($page-1)*40));$messages=[];
        foreach(array_slice($refs,$offset,$length)as$ref){
            if(isset($ref['index'])){$messages[]=$originals[$ref['index']];continue;}
            if(isset($ref['human'])){$answer=$this->replies->humanAt($access,$source,$connection,$ref['human']);$messages[]=['id'=>$ref['id'],'text'=>$answer['payload']['text'],'incoming'=>false,'from_name'=>'Ihr','created_at'=>$ref['date'],'human_status'=>$answer['status'],'local_reply_id'=>$answer['id']];continue;}
            $answer=$this->replies->mailAt($access,$source,$connection,$ref['mail']);
            $messages[]=['id'=>$ref['id'],'text'=>$answer['payload']['text'],'incoming'=>false,'from_name'=>'Ihr','created_at'=>$answer['created_at'],'mail_status'=>$answer['status'],'local_reply_id'=>$answer['id']];
        }
        return ['messages'=>$messages,'page'=>$page,'pages'=>$pages,'total'=>$total,'older'=>$offset>0,'newer'=>$page>1];
    }
}
