<?php declare(strict_types=1);
namespace Kulturhus\Native\Communication;

use PDO;
use DomainException;
use Kulturhus\Native\Shared\{Access,Db,SecretBox};

/** Bounded conversation summaries; decrypt one original at a time, never all histories. */
final class InboxCatalogue
{
    public function __construct(private PDO $db,private SecretBox $box){}
    public static function query(array $input):array
    {
        $page=filter_var($input['conversation_page']??1,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>1000000]]);
        $channel=$input['inbox_channel']??'all';$term=$input['q']??'';
        if($page===false||!is_string($channel)||!in_array($channel,['all','facebook','instagram'],true)||!is_string($term)||strlen($term)>800||!mb_check_encoding($term,'UTF-8')||mb_strlen($term)>200||preg_match('/[\x00-\x1f\x7f]/',$term))throw new DomainException('Gesprächssuche prüfen.');
        return ['page'=>$page,'channel'=>$channel,'q'=>trim($term)];
    }
    private function summary(Access $access,array $row,string $club,string $term=''):?array
    {
        // Private helper only inside listing's transaction and locked current grant.
        $ciphertext=Db::run($this->db,'SELECT ciphertext FROM native_social_inbox WHERE club_id=? AND id=?',[$club,$row['id']])->fetchColumn();
        if(!is_string($ciphertext))throw new DomainException('Gespräch inzwischen geändert.');
        $data=$this->box->open('social-inbox:'.$club.':'.$row['id'],$ciphertext);
        $summary=$row+$this->preview($data);
        if($term===''||mb_stripos($summary['name'].' '.$summary['channel'].' Nachricht',$term)!==false)return $summary;
        $service=new Replies($this->db,$this->box);$context=null;
        $hasMail=(bool)Db::run($this->db,"SELECT 1 FROM native_social_mail_threads t JOIN native_social_mail_replies m ON m.club_id=t.club_id AND m.thread_id=t.id JOIN native_social_mail_dispatch d ON d.club_id=m.club_id AND d.mail_id=m.id WHERE t.club_id=? AND t.kind='message' AND t.channel=? AND t.parent_id=? AND t.connection_id=? LIMIT 1",[$club,$row['channel'],$data['conversation']??'',$data['connection_id']??''])->fetchColumn();
        $human=new HumanSends($this->db,$service);$hasHuman=$human->installed()&&(bool)Db::run($this->db,"SELECT 1 FROM native_social_human_sends h JOIN native_social_replies r ON r.club_id=h.club_id AND r.id=h.reply_id WHERE h.club_id=? AND h.kind='message' AND r.channel=? AND r.parent_id=? AND r.connection_id=? LIMIT 1",[$club,$row['channel'],$data['conversation']??'',$data['connection_id']??''])->fetchColumn();
        if(!$hasMail&&!$hasHuman)$context=[null,null];
        foreach(array_reverse($data['messages'])as$message){
            if(mb_stripos($message['from_name'].' '.$message['text'],$term)===false)continue;
            $context??=$this->mailContext($access,$row,$data,$service);
            return $this->hit($access,$summary,$data,$service,$context,$message['id'],$message['text'],$term);
        }
        $context??=$this->mailContext($access,$row,$data,$service);[$source,$connection]=$context;
        if(!$source||!$connection)return null;
        $match=$service->searchMail($access,$source,$connection,$term,array_column($data['messages'],'id'));
        $match??=$service->searchHuman($access,$source,$connection,$term,array_column($data['messages'],'id'));
        if($match)return $this->hit($access,$summary,$data,$service,$context,$match['id'],$match['text'],$term);
        return null;
    }
    /** Same read-only source/account context as the visible conversation. */
    private function mailContext(Access $access,array $row,array $data,Replies $service):array
    {
        $source=null;$connection=null;
        try{$source=$service->messageOriginal($access,$row['id']);if(empty($source['_archived']))$connection=$service->connection($access,$source['id']);}catch(DomainException){}
        $connection??=$source?$service->retainedConnection($access,$source):null;
        if(!$source||$source['channel']!==$row['channel']||$source['parent_id']!==($data['conversation']??null)||($connection!==null&&$connection!==($data['connection_id']??null)))return [null,null];
        return [$source,$connection];
    }
    private function hit(Access $access,array $summary,array $data,Replies $service,array $context,string $id,string $text,string $term):array
    {
        [$source,$connection]=$context;$position=mb_stripos($text,$term);$start=$position===false?0:max(0,$position-50);
        return array_replace($summary,['text'=>($start>0?'… ':'').mb_substr($text,$start,150),'focus_message'=>$id,'message_page'=>(new ConversationTimeline($service))->pageContaining($access,$data['messages'],$source,$connection,$id)]);
    }
    private function preview(array $data):array
    {
        $last=null;
        foreach($data['messages']as$message)if($message['incoming'])$last=$message;
        if(!$last)$last=$data['messages'][array_key_last($data['messages'])]??null;
        if(!$last)throw new DomainException('Gespräch ohne Originalnachricht.');
        return ['name'=>$last['from_name']?:'Gespräch','text'=>$last['text']];
    }
    public function listing(Access $access,array $query):array
    {
        $access->require('social','manage');$q=self::query(['conversation_page'=>$query['page']??1,'inbox_channel'=>$query['channel']??'all','q'=>$query['q']??'']);
        return Db::atomic($this->db,function()use($access,$q):array{
            $actor=$access->require('social','manage');$club=$actor['club_id'];$where="club_id=? AND channel IN ('facebook','instagram')";$args=[$club];
            if($q['channel']!=='all'){$where.=' AND channel=?';$args[]=$q['channel'];}
            $offset=($q['page']-1)*6;$rows=[];
            if($q['q']===''){
                $total=(int)Db::run($this->db,'SELECT COUNT(*) FROM native_social_inbox WHERE '.$where,$args)->fetchColumn();
                $metadata=Db::run($this->db,'SELECT id,channel,last_incoming FROM native_social_inbox WHERE '.$where.' ORDER BY last_incoming DESC,id DESC LIMIT 6 OFFSET '.$offset,$args)->fetchAll();
                foreach($metadata as$row)$rows[]=$this->summary($access,$row,$club);
            }else{
                // Encrypted names/text cannot be searched in SQL. Scan metadata
                // in fixed batches and retain only the requested six summaries.
                $total=0;$last=null;
                do{
                    $cursor=$last?' AND (last_incoming<? OR (last_incoming=? AND id<?))':'';
                    $metadata=Db::run($this->db,'SELECT id,channel,last_incoming FROM native_social_inbox WHERE '.$where.$cursor.' ORDER BY last_incoming DESC,id DESC LIMIT 100',$last?[...$args,$last['last_incoming'],$last['last_incoming'],$last['id']]:$args)->fetchAll();
                    foreach($metadata as$row){$summary=$this->summary($access,$row,$club,$q['q']);if($summary!==null){if($total>=$offset&&count($rows)<6)$rows[]=$summary;++$total;}$last=$row;}
                    $access->require('social','manage');
                }while(count($metadata)===100);
            }
            $pages=max(1,(int)ceil($total/6));if($q['page']>$pages)throw new DomainException('Gesprächsseite nicht verfügbar.');
            $access->require('social','manage');return ['rows'=>$rows,'total'=>$total,'pages'=>$pages]+$q;
        });
    }
    public function conversation(Access $access,string $id):array
    {
        $row=(new MessageReplyProvider($this->db,$this->box))->conversation($access,$id);
        return $row+['messages'=>$row['payload']['messages']]+$this->preview($row['payload']);
    }
}
