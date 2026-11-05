<?php declare(strict_types=1);
namespace Kulturhus\Native\Communication\Mail;

use PDO;
use DomainException;
use Kulturhus\Native\Shared\{Access,Authority,Db,SecretBox};
use Kulturhus\Native\Communication\Replies;
use Kulturhus\Verein\MailPolicy;

/** Each received original is a single durable social command, never an editable draft. */
final class Dispatcher
{
    private Replies $replies;
    private bool $realTransport;
    public function __construct(private PDO $db,private SecretBox $box,?Replies $replies=null)
    {
        $this->realTransport=$replies===null;$this->replies=$replies??new Replies($db,$box);
    }
    private function authority(Access $caller,string $binding):Access
    {
        $caller->require('social','manage');
        $config=(new Settings($this->db,$this->box))->configuration($caller)['data'];
        if(!$config['enabled']||!$config['receive_enabled']||!$config['auto_send']||!hash_equals($binding,$config['binding']))throw new DomainException('Direkter Mailantwortversand ausgeschaltet oder Zuordnung geändert.');
        $access=Authority::resolve($this->db,['created_by'=>$config['actor'],'authority'=>$config['authority'],'club_id'=>$caller->club()]);
        foreach([['website','manage'],['social','manage'],['social','edit'],['social','publish']]as[$module,$capability])$access->require($module,$capability);
        if($access->club()!==$caller->club())throw new DomainException('Mailantwort gehört zu einem anderen Verein.');
        return $access;
    }
    /** Shared send gate also applies to direct calls outside this worker. */
    public function assertReply(Access $caller,array $reply):void
    {
        $bridge=new Bridge($this->db,$this->box);$mail=$bridge->answer($caller,$reply['reply_slot']);$thread=$bridge->get($caller,$mail['thread_id']);
        $owner=$this->authority($caller,$thread['binding']);$config=(new Settings($this->db,$this->box))->configuration($caller)['data'];
        if(($mail['payload']['send_actor']??null)!==$owner->user()['id']||($mail['payload']['send_authority']??null)!==$config['authority']||($mail['delivery']['reply_id']??null)!==$reply['id']||($mail['delivery']['state']??null)!=='ready')throw new DomainException('Mailantwortauftrag oder ursprüngliche Berechtigung geändert.');
    }
    public function run(Access $caller):array
    {
        if($this->db->inTransaction())throw new DomainException('Direkter Mailversand benötigt eine eigene Transaktion.');
        $caller->require('social','manage');$club=$caller->club();$lock=Settings::lock($club);
        $counts=['sent'=>0,'queued'=>0,'blocked'=>0,'uncertain'=>0,'rejected'=>0];
        if($this->realTransport&&(getenv('KULTURHUS_DISABLE_WEBSITE')==='1'||!MailPolicy::allowed((string)getenv('APP_URL'))))return $counts;
        if((int)Db::run($this->db,'SELECT GET_LOCK(?,0)',[$lock])->fetchColumn()!==1)return $counts;
        try{
            $ids=Db::run($this->db,"SELECT mail_id FROM native_social_mail_dispatch WHERE club_id=? AND state IN ('pending','ready') ORDER BY created_at,mail_id LIMIT 25",[$club])->fetchAll(PDO::FETCH_COLUMN);
            foreach($ids as$id){
                try{
                    $command=Db::atomic($this->db,function()use($caller,$club,$id):array{
                        $job=Db::run($this->db,'SELECT * FROM native_social_mail_dispatch WHERE club_id=? AND mail_id=? FOR UPDATE',[$club,$id])->fetch();
                        $bridge=new Bridge($this->db,$this->box);$mail=$bridge->answer($caller,$id);$thread=$bridge->get($caller,$mail['thread_id']);$access=$this->authority($caller,$thread['binding']);
                        $config=(new Settings($this->db,$this->box))->configuration($caller)['data'];
                        if(($mail['payload']['send_actor']??null)!==$access->user()['id']||($mail['payload']['send_authority']??null)!==$config['authority'])throw new DomainException('Ursprünglicher Mailverantwortlicher geändert.');
                        if($job['reply_id']!==null)return ['access'=>$access,'reply'=>$this->replies->get($access,$job['reply_id'])];
                        $source=$this->replies->source($access,$thread['attention_id']);
                        if(!hash_equals($thread['source_fingerprint'],$source['fingerprint'])||!hash_equals($thread['connection_id'],$this->replies->connection($access,$source['id'])))throw new DomainException('Original oder Anbieterbindung geändert.');
                        $text=ReplyText::extract($mail['payload']['reply_text']??$mail['payload']['text']);
                        $reply=$this->replies->queueMail($access,$source['id'],(int)$source['version'],$text,$id);
                        Db::run($this->db,"UPDATE native_social_mail_dispatch SET state='ready',reply_id=?,updated_at=UTC_TIMESTAMP(6) WHERE club_id=? AND mail_id=?",[$reply['id'],$club,$id]);
                        return ['access'=>$access,'reply'=>$reply];
                    });
                    $reply=$command['reply'];
                    if($reply['status']==='queued')$reply=$this->replies->send($command['access'],$reply['id'],(int)$reply['version'],true);
                    $status=match($reply['status']){'sent'=>'sent','sending','uncertain'=>'uncertain','rejected'=>'rejected',default=>'blocked'};
                    Db::run($this->db,'UPDATE native_social_mail_dispatch SET state=?,reason=?,updated_at=UTC_TIMESTAMP(6) WHERE club_id=? AND mail_id=?',[$status,$reply['reason'],$club,$id]);$counts[$status]++;
                }catch(\Throwable){
                    // Replies owns its committed network intent. A failed local
                    // acknowledgement cannot turn it into another provider call.
                    $job=Db::run($this->db,'SELECT reply_id FROM native_social_mail_dispatch WHERE club_id=? AND mail_id=?',[$club,$id])->fetch();
                    $status='blocked';
                    if($job['reply_id']!==null){$state=Db::run($this->db,'SELECT status FROM native_social_replies WHERE club_id=? AND id=?',[$club,$job['reply_id']])->fetchColumn();$status=match($state){'sent'=>'sent','sending','uncertain'=>'uncertain','rejected'=>'rejected',default=>'blocked'};}
                    Db::run($this->db,'UPDATE native_social_mail_dispatch SET state=?,reason=?,updated_at=UTC_TIMESTAMP(6) WHERE club_id=? AND mail_id=?',[$status,$status==='sent'?null:'mail_authority_source_or_provider',$club,$id]);$counts[$status]++;
                }
            }
            $counts['queued']=(int)Db::run($this->db,"SELECT COUNT(*) FROM native_social_mail_dispatch WHERE club_id=? AND state IN ('pending','ready')",[$club])->fetchColumn();
            return $counts;
        }finally{Db::run($this->db,'SELECT RELEASE_LOCK(?)',[$lock]);}
    }
}
