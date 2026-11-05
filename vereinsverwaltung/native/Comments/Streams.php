<?php declare(strict_types=1);
namespace Kulturhus\Native\Comments;

use DomainException;
use InvalidArgumentException;
use Kulturhus\Native\Cms\{Conflict,Pages,TemplatePolicy};
use Kulturhus\Native\Shared\{Access,Db,SecretBox};
use PDO;

/** Local human comments. Public access, private moderation and client receipts have separate DTOs. */
final class Streams
{
    private const STATES = ['approved','pending','rejected','spam','removed'];
    public function __construct(private PDO $db, private Access $access, private SecretBox $box) {}

    private function lock(): string
    {
        $club = $this->access->club();
        Db::run($this->db,'INSERT INTO khcms_state(club_id,generation) VALUES(?,0) ON DUPLICATE KEY UPDATE generation=generation',[$club]);
        Db::run($this->db,'SELECT generation FROM khcms_state WHERE club_id=? FOR UPDATE',[$club])->fetchColumn();
        return $club;
    }

    private function changed(string $club): void
    {
        Db::run($this->db,'UPDATE khcms_state SET generation=generation+1 WHERE club_id=?',[$club]);
    }

    public function configuration(string $page, string $stream = 'comments'): array
    {
        Values::id($page); Values::stream($stream);
        TemplatePolicy::assertCapability($this->db,$this->access,$page,'read');
        Bindings::assert($this->db,$this->access,$page,$stream);
        return $this->settings($page,$stream);
    }

    private function settings(string $page, string $stream): array
    {
        $row=Db::run($this->db,'SELECT version,enabled,moderation,anonymous,max_length FROM native_comment_settings WHERE club_id=? AND page_id=? AND stream=?',[$this->access->club(),$page,$stream])->fetch();
        if (!$row) return ['version'=>0, ...Values::settings([])];
        return ['version'=>(int)$row['version'],'enabled'=>(bool)$row['enabled'],'moderation'=>(bool)$row['moderation'],'anonymous'=>(bool)$row['anonymous'],'max_length'=>(int)$row['max_length']];
    }

    public function configure(string $page, string $stream, int $expected, array $input): array
    {
        Values::id($page); Values::stream($stream); $settings=Values::settings($input);
        return Db::atomic($this->db,function()use($page,$stream,$expected,$settings):array {
            $actor=$this->access->require('website','manage');$club=$this->lock();
            TemplatePolicy::assertCapability($this->db,$this->access,$page,'edit');
            Bindings::assert($this->db,$this->access,$page,$stream);
            $definition=(new \Kulturhus\Native\Cms\Pages($this->db,$this->access))->get($page)['field_definitions'][$stream]??null;
            if($definition!==null&&!\Kulturhus\Native\Cms\FieldPolicy::canEdit($this->db,$this->access,$definition))throw new DomainException('Dieses Kommentarfeld ist nicht zur Bearbeitung freigegeben.');
            $old=$this->settings($page,$stream);
            if ($expected!==$old['version']) throw new Conflict('Kommentareinstellungen wurden geändert. Neu laden.');
            if (!$expected && (int)Db::run($this->db,'SELECT COUNT(*) FROM native_comment_settings WHERE club_id=? AND page_id=?',[$club,$page])->fetchColumn()>=10) {
                throw new DomainException('Höchstens zehn Kommentarfelder pro Seite aktivieren.');
            }
            $version=$expected+1;
            Db::run($this->db,'INSERT INTO native_comment_settings(club_id,page_id,stream,version,enabled,moderation,anonymous,max_length,updated_by,updated_at) VALUES(?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE version=VALUES(version),enabled=VALUES(enabled),moderation=VALUES(moderation),anonymous=VALUES(anonymous),max_length=VALUES(max_length),updated_by=VALUES(updated_by),updated_at=VALUES(updated_at)',[$club,$page,$stream,$version,(int)$settings['enabled'],(int)$settings['moderation'],(int)$settings['anonymous'],$settings['max_length'],$actor['id']]);
            Db::run($this->db,'INSERT INTO native_comment_setting_history VALUES(?,?,?,?,?,?,UTC_TIMESTAMP(6))',[$club,$page,$stream,$version,json_encode($settings,JSON_THROW_ON_ERROR),$actor['id']]);
            $this->changed($club);
            return ['version'=>$version,...$settings];
        });
    }

    /** Re-read exactly one actual public original, with current template and channel restrictions. */
    public function original(string $page): array
    {
        Values::id($page);$actor=$this->access->require('website','read');
        [$policy,$args]=TemplatePolicy::readFilter($this->db,$this->access,'p',true);
        $slug=Db::run($this->db,'SELECT p.published_slug FROM khcms_pages p WHERE p.id=? AND ('.$policy.')',[$page,...$args])->fetchColumn();
        $original=is_string($slug)?(new Pages($this->db,$this->access))->publicPage($slug):null;
        if (!$original || $original['id']!==$page) throw new DomainException('Kommentare für diese Seite nicht verfügbar.');
        return $original;
    }

    /** Ancestors must remain approved too: moderation never leaks a hidden thread through its children. */
    private static function visible(): string
    {
        return "c.state='approved' AND (c.parent_id IS NULL OR EXISTS(SELECT 1 FROM native_comments p1 WHERE p1.id=c.parent_id AND p1.club_id=c.club_id AND p1.page_id=c.page_id AND p1.stream=c.stream AND p1.state='approved' AND (p1.parent_id IS NULL OR EXISTS(SELECT 1 FROM native_comments p2 WHERE p2.id=p1.parent_id AND p2.club_id=c.club_id AND p2.page_id=c.page_id AND p2.stream=c.stream AND p2.state='approved' AND (p2.parent_id IS NULL OR EXISTS(SELECT 1 FROM native_comments p3 WHERE p3.id=p2.parent_id AND p3.club_id=c.club_id AND p3.page_id=c.page_id AND p3.stream=c.stream AND p3.state='approved' AND p3.parent_id IS NULL))))))";
    }

    public function publicList(string $page, string $stream = 'comments', ?int $number = null): array
    {
        Values::stream($stream);$original=$this->original($page);Bindings::assert($this->db,$this->access,$page,$stream,true);$settings=$this->settings($page,$stream);
        if (!$settings['enabled']) throw new DomainException('Kommentare sind für diese Seite geschlossen.');
        if ($number!==null && ($number<1 || $number>50001)) throw new InvalidArgumentException('Kommentarseite auswählen.');
        $club=$this->access->club();$args=[$club,$page,$stream];$where='c.club_id=? AND c.page_id=? AND c.stream=? AND '.self::visible();
        $total=(int)Db::run($this->db,'SELECT COUNT(*) FROM native_comments c WHERE '.$where,$args)->fetchColumn();
        $last=max(1,(int)ceil($total/20));$number=min($number??$last,$last);
        $rows=Db::run($this->db,"SELECT c.id,c.parent_id,c.depth,c.author_name,c.body,c.format,c.created_at,reply.author_name AS reply_name,reply.body AS reply_body,reply.format AS reply_format FROM native_comments c LEFT JOIN native_comments reply ON reply.id=c.parent_id AND reply.club_id=c.club_id AND reply.page_id=c.page_id AND reply.stream=c.stream AND reply.state='approved' WHERE ".$where.' ORDER BY c.created_at,c.id LIMIT 20 OFFSET '.(($number-1)*20),$args)->fetchAll();
        $rows=array_map(static function(array $row):array {
            $safe=Values::publicRow($row);
            if ($row['parent_id']!==null && $row['reply_name']!==null) $safe['reply_to']=['id'=>$row['parent_id'],'author_name'=>$row['reply_name'],'body'=>mb_substr(Display::text($row['reply_body'],$row['reply_format']),0,200)];
            return $safe;
        },$rows);
        return ['rows'=>$rows,'total'=>$total,'page'=>$number,'last'=>$last,'settings'=>$settings,'stream_label'=>$original['field_definitions'][$stream]['label']??'Kommentare','original'=>array_intersect_key($original,array_flip(['id','title','slug','version']))];
    }

    public function publicParent(string $page, string $stream, string $id): ?array
    {
        Values::stream($stream);Values::id($id);$this->original($page);Bindings::assert($this->db,$this->access,$page,$stream,true);
        if (!$this->settings($page,$stream)['enabled']) return null;
        $row=Db::run($this->db,'SELECT c.id,c.author_name,c.body,c.format FROM native_comments c WHERE c.id=? AND c.club_id=? AND c.page_id=? AND c.stream=? AND '.self::visible(),[$id,$this->access->club(),$page,$stream])->fetch();
        if (!$row) return null;
        return ['id'=>$row['id'],'author_name'=>$row['author_name'],'body'=>mb_substr(Display::text($row['body'],$row['format']),0,200)];
    }

    /** $request/$client are server-issued opaque session values, never email, IP or an author-selected ID. */
    public function submit(string $page, string $stream, array $input, string $request, string $client, string $rateIdentity, ?Access $author = null): array
    {
        Values::id($page);Values::stream($stream);
        foreach ([$request,$client] as $token) if (!preg_match('/\A[a-f0-9]{64}\z/D',$token)) throw new InvalidArgumentException('Kommentarformular neu öffnen.');
        if ($rateIdentity==='' || strlen($rateIdentity)>256 || array_diff(array_keys($input),['name','email','body','format','parent'])) throw new InvalidArgumentException('Kommentarangaben prüfen.');
        return Db::atomic($this->db,function()use($page,$stream,$input,$request,$client,$rateIdentity,$author):array {
            $club=$this->lock();$original=$this->original($page);Bindings::assert($this->db,$this->access,$page,$stream,true);$settings=$this->settings($page,$stream);
            if (!$settings['enabled']) throw new DomainException('Kommentare sind für diese Seite geschlossen.');
            $identity='visitor';
            if ($author!==null) {$person=$author->user();if($person['club_id']!==$club)throw new DomainException('Kommentarkonto gehört nicht zu diesem Verein.');$identity=$person['id'];}
            elseif (!$settings['anonymous']) throw new DomainException('Zum Kommentieren anmelden.');
            $name=Values::text($input['name']??'',100);$body=Values::text($input['body']??'',$settings['max_length']);
            $email=Values::text($input['email']??'',254,false);
            if ($email!=='' && !filter_var($email,FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('Gültige E-Mail-Adresse angeben.');
            $format=$input['format']??'plain';if (!in_array($format,['plain','markdown'],true)) throw new InvalidArgumentException('Kommentarformat prüfen.');
            $parent=$input['parent']??null;if ($parent==='')$parent=null;if ($parent!==null)Values::id($parent);
            $requestHash=$this->box->fingerprint('comment-request:'.$page.':'.$stream.':'.$client.':'.$request);
            $authorKey=$this->box->fingerprint('comment-author:'.$club.':'.$client);
            $payloadHash=$this->box->fingerprint(json_encode([$page,$stream,$identity,$name,$email,$body,$format,$parent],JSON_THROW_ON_ERROR));
            $existing=Db::run($this->db,'SELECT id,version,state,payload_hash FROM native_comments WHERE club_id=? AND request_hash=?',[$club,$requestHash])->fetch();
            if ($existing) {
                if (!hash_equals($existing['payload_hash'],$payloadHash)) throw new Conflict('Dieses Formular gehört zu einem bereits gesendeten anderen Text. Neues Formular öffnen.');
                return ['id'=>$existing['id'],'version'=>(int)$existing['version'],'state'=>$existing['state'],'replayed'=>true];
            }
            \Kulturhus\Native\Shared\TextFormatter::render($body,$format);
            $rateKey=$this->box->fingerprint('comment-rate:'.$club.':'.$rateIdentity);
            if ((int)Db::run($this->db,'SELECT COUNT(*) FROM native_comments WHERE club_id=? AND rate_key=? AND created_at>=UTC_TIMESTAMP(6)-INTERVAL 1 HOUR',[$club,$rateKey])->fetchColumn()>=10) throw new DomainException('Bitte vor dem nächsten Kommentar etwas warten.');
            $depth=0;
            if ($parent!==null) {
                $row=Db::run($this->db,'SELECT c.depth FROM native_comments c WHERE c.club_id=? AND c.page_id=? AND c.stream=? AND c.id=? AND '.self::visible(),[$club,$page,$stream,$parent])->fetch();
                if (!$row || (int)$row['depth']>=3) throw new DomainException('Auf diesen Kommentar kann nicht geantwortet werden.');
                $depth=(int)$row['depth']+1;
            }
            $id=bin2hex(random_bytes(16));$state=$settings['moderation']?'pending':'approved';
            $cipher=$this->box->seal('website-comment:'.$club.':'.$id,['email'=>$email,'actor'=>$identity]);
            Db::run($this->db,'INSERT INTO native_comments(id,club_id,page_id,stream,parent_id,depth,version,state,author_name,author_cipher,author_key,body,format,original_revision,request_hash,payload_hash,rate_key,created_at,updated_at,updated_by) VALUES(?,?,?,?,?,?,1,?,?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6),?)',[$id,$club,$page,$stream,$parent,$depth,$state,$name,$cipher,$authorKey,$body,$format,$original['version'],$requestHash,$payloadHash,$rateKey,$identity]);
            $this->record($id,'submit',$identity);
            if ($state==='approved') $this->changed($club);
            return ['id'=>$id,'version'=>1,'state'=>$state,'replayed'=>false];
        });
    }

    public function ownReceipt(string $id, string $client): array
    {
        Values::id($id);if(!preg_match('/\A[a-f0-9]{64}\z/D',$client))throw new DomainException('Kommentar nicht verfügbar.');
        $row=Db::run($this->db,'SELECT id,page_id,stream,state,version,body,author_name FROM native_comments WHERE id=? AND club_id=? AND author_key=?',[$id,$this->access->club(),$this->box->fingerprint('comment-author:'.$this->access->club().':'.$client)])->fetch();
        if (!$row) throw new DomainException('Kommentar nicht verfügbar.');
        $this->original($row['page_id']);Bindings::assert($this->db,$this->access,$row['page_id'],$row['stream'],true);return $row;
    }

    private function record(string $id, string $action, string $actor): void
    {
        $row=Db::run($this->db,'SELECT * FROM native_comments WHERE club_id=? AND id=?',[$this->access->club(),$id])->fetch();
        Db::run($this->db,'INSERT INTO native_comment_history(club_id,comment_id,version,action,actor_id,snapshot_json,created_at) VALUES(?,?,?,?,?,?,UTC_TIMESTAMP(6))',[$row['club_id'],$id,$row['version'],$action,$actor,json_encode($row,JSON_THROW_ON_ERROR)]);
    }

    private static function moderatorRow(array $row): array
    {
        return array_intersect_key($row,array_flip(['id','page_id','stream','parent_id','depth','version','state','author_name','body','format','original_revision','created_at','updated_at','updated_by']));
    }

    public function get(string $id): array
    {
        Values::id($id);$actor=$this->access->require('website','publish');
        $row=Db::run($this->db,'SELECT * FROM native_comments WHERE id=? AND club_id=?',[$id,$actor['club_id']])->fetch();
        if (!$row) throw new DomainException('Kommentar nicht verfügbar.');
        TemplatePolicy::assertCapability($this->db,$this->access,$row['page_id'],'publish');
        Bindings::assert($this->db,$this->access,$row['page_id'],$row['stream']);
        return self::moderatorRow($row);
    }

    /** Metadata only; loading a chosen historical human text is a separate bounded request. */
    public function history(string $id, int $page = 1): array
    {
        $this->get($id);if($page<1||$page>100001)throw new InvalidArgumentException('Verlaufsseite auswählen.');
        $args=[$this->access->club(),$id];
        $total=(int)Db::run($this->db,'SELECT COUNT(*) FROM native_comment_history WHERE club_id=? AND comment_id=?',$args)->fetchColumn();
        $last=max(1,(int)ceil($total/10));$page=min($page,$last);
        $rows=Db::run($this->db,'SELECT version,action,actor_id,created_at FROM native_comment_history WHERE club_id=? AND comment_id=? ORDER BY version DESC LIMIT 10 OFFSET '.(($page-1)*10),$args)->fetchAll();
        return ['rows'=>$rows,'total'=>$total,'page'=>$page,'last'=>$last];
    }

    public function version(string $id, int $version): array
    {
        $this->get($id);if($version<1)throw new InvalidArgumentException('Kommentarversion auswählen.');
        $json=Db::run($this->db,'SELECT snapshot_json FROM native_comment_history WHERE club_id=? AND comment_id=? AND version=?',[$this->access->club(),$id,$version])->fetchColumn();
        if (!is_string($json)) throw new DomainException('Kommentarversion nicht verfügbar.');
        return self::moderatorRow(json_decode($json,true,32,JSON_THROW_ON_ERROR));
    }

    /** Private contact is an explicit current administration action, absent from all default DTOs. */
    public function contact(string $id): array
    {
        $this->get($id);$this->access->require('website','manage');$club=$this->access->club();
        $cipher=Db::run($this->db,'SELECT author_cipher FROM native_comments WHERE club_id=? AND id=?',[$club,$id])->fetchColumn();
        return $this->box->open('website-comment:'.$club.':'.$id,$cipher);
    }

    public function edit(string $id, int $expected, array $input): array
    {
        if (array_diff(array_keys($input),['name','body','format'])) throw new InvalidArgumentException('Kommentarkorrektur prüfen.');
        return Db::atomic($this->db,function()use($id,$expected,$input):array {
            $actor=$this->access->require('website','publish');$club=$this->lock();$old=$this->get($id);
            if ((int)$old['version']!==$expected) throw new Conflict('Kommentar wurde inzwischen bearbeitet. Neu laden.');
            $settings=$this->settings($old['page_id'],$old['stream']);
            $name=Values::text($input['name']??$old['author_name'],100);
            $body=Values::text($input['body']??$old['body'],max($settings['max_length'],mb_strlen($old['body'])));
            $format=$input['format']??$old['format'];if(!in_array($format,['plain','markdown'],true))throw new InvalidArgumentException('Kommentarformat prüfen.');
            if ($name===$old['author_name'] && $body===$old['body'] && $format===$old['format']) return $old;
            \Kulturhus\Native\Shared\TextFormatter::render($body,$format);
            Db::run($this->db,'UPDATE native_comments SET author_name=?,body=?,format=?,version=version+1,updated_by=?,updated_at=UTC_TIMESTAMP(6) WHERE id=? AND club_id=?',[$name,$body,$format,$actor['id'],$id,$club]);
            $this->record($id,'edit',$actor['id']);$this->changed($club);
            return $this->get($id);
        });
    }

    public function moderate(string $id, int $expected, string $state): array
    {
        Values::id($id);if(!in_array($state,self::STATES,true))throw new InvalidArgumentException('Kommentarentscheidung auswählen.');
        return Db::atomic($this->db,function()use($id,$expected,$state):array {
            $actor=$this->access->require('website','publish');$club=$this->lock();
            $row=Db::run($this->db,'SELECT page_id,stream,version,state FROM native_comments WHERE id=? AND club_id=? FOR UPDATE',[$id,$club])->fetch();
            if (!$row) throw new DomainException('Kommentar nicht verfügbar.');
            TemplatePolicy::assertCapability($this->db,$this->access,$row['page_id'],'publish');
        Bindings::assert($this->db,$this->access,$row['page_id'],$row['stream']);
            if ((int)$row['version']!==$expected) throw new Conflict('Kommentar wurde inzwischen bearbeitet. Neu laden.');
            if ($row['state']===$state) return ['id'=>$id,'version'=>$expected,'state'=>$state];
            Db::run($this->db,'UPDATE native_comments SET version=version+1,state=?,updated_by=?,updated_at=UTC_TIMESTAMP(6) WHERE id=? AND club_id=?',[$state,$actor['id'],$id,$club]);
            $this->record($id,$state,$actor['id']);$this->changed($club);
            return ['id'=>$id,'version'=>$expected+1,'state'=>$state];
        });
    }

    public function moderationList(string $state = 'pending', int $page = 1): array
    {
        $this->access->require('website','publish');if(!in_array($state,self::STATES,true)||$page<1||$page>50001)throw new InvalidArgumentException('Kommentarfilter prüfen.');
        [$policy,$args]=TemplatePolicy::readFilter($this->db,$this->access,'p',false,'publish');
        [$fieldGuard,$fieldArgs]=Bindings::filter($this->db,$this->access);
        $where=' FROM native_comments c JOIN khcms_pages p ON p.id=c.page_id AND p.club_id=c.club_id WHERE c.state=? AND ('.$policy.') AND ('.$fieldGuard.')';$args=[$state,...$args,...$fieldArgs];
        $total=(int)Db::run($this->db,'SELECT COUNT(*)'.$where,$args)->fetchColumn();$last=max(1,(int)ceil($total/20));$page=min($page,$last);
        $rows=Db::run($this->db,'SELECT c.id,c.page_id,c.stream,c.parent_id,c.version,c.state,c.author_name,c.body,c.format,c.created_at'.$where.' ORDER BY c.created_at DESC,c.id LIMIT 20 OFFSET '.(($page-1)*20),$args)->fetchAll();
        return ['rows'=>$rows,'total'=>$total,'page'=>$page,'last'=>$last];
    }
}
