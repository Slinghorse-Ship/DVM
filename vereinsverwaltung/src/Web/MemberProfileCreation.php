<?php declare(strict_types=1);
namespace Kulturhus\Verein\Web;
use Kulturhus\Native\Api\{Context,Factory,Operations,Problem};
use Kulturhus\Verein\Registry;

/** Session-owned durable creation results use the existing operation ledger. */
final class MemberProfileCreation
{
    private const OPERATION='manager.profile.create';
    public function __construct(private \PDO $db,private Context $context){}
    public static function session(\PDO $db,#[\SensitiveParameter]string $token):self
    {
        try{return new self($db,Factory::session($db,$token));}catch(Problem $failure){throw new \DomainException('Aktuelle Mitgliederbearbeitung für diesen Anlagevorgang erforderlich.',403,$failure);}
    }
    private function allowed():void
    {
        try{$this->context->active();if(!(new Registry($this->db,$this->context->actor()))->canWrite())throw new \DomainException('Aktuelle Mitgliederbearbeitung für diesen Anlagevorgang erforderlich.',403);}catch(Problem $failure){throw new \DomainException('Aktuelle Mitgliederbearbeitung für diesen Anlagevorgang erforderlich.',403,$failure);}
    }
    public static function key(mixed $key):string
    {
        if(!is_string($key)||!preg_match('/\A[a-f0-9]{32}\z/D',$key))throw new \DomainException('Ursprüngliche Anlagekennung vollständig prüfen.',422);return $key;
    }
    public function create(array $body,\Closure $operation):array
    {
        $this->allowed();MemberProfileForm::target($body,['view'=>'members','new'=>'1']);$key=self::key($body['create_request']??null);$club=$this->context->club();$actor=$this->context->actor();
        try{$result=(new Operations($this->db,$this->context,Factory::operationKey()))->run($key,'POST','/manager/members/new',['club'=>$club,'actor'=>$actor,'input'=>MemberProfileForm::original($body)],function()use($operation,$club,$actor):array{$this->allowed();$id=$operation();$this->allowed();return ['operation'=>self::OPERATION,'club'=>$club,'actor'=>$actor,'contact'=>$id];},fn()=>$this->allowed());}
        catch(Problem $failure){throw new \DomainException($failure->status===409?'Dieser Anlagevorgang ist bereits mit anderen Angaben gespeichert. Vorhandenes Profil prüfen.':'Anlagevorgang konnte nicht bestätigt werden. Aktuellen Speicherstand prüfen.',$failure->status===409?409:($failure->status===401?403:$failure->status),$failure);}
        if(($result['operation']??null)!==self::OPERATION||($result['club']??null)!==$club||($result['actor']??null)!==$actor||!is_string($result['contact']??null))throw new \DomainException('Anlagevorgang konnte nicht bestätigt werden. Aktuellen Speicherstand prüfen.',409);
        (new Registry($this->db,$actor))->contact($result['contact']);return ['contact'=>$result['contact'],'replayed'=>(bool)($result['replayed']??false)];
    }
    public function saved(mixed $key):?string
    {
        $this->allowed();$key=self::key($key);$query=$this->db->prepare('SELECT result_json FROM native_api_operations WHERE account_id=? AND key_hash=?');$query->execute([$this->context->accountId,hash('sha256',$key)]);$row=$query->fetch(\PDO::FETCH_ASSOC);if(!$row)return null;if(!is_string($row['result_json']))throw new \DomainException('Anlagekennung gehört zu einem anderen Vorgang.',409);
        $result=json_decode($row['result_json'],true,128,JSON_THROW_ON_ERROR);if(($result['operation']??null)!==self::OPERATION||($result['club']??null)!==$this->context->club()||($result['actor']??null)!==$this->context->actor()||!is_string($result['contact']??null))throw new \DomainException('Anlagekennung gehört zu einem anderen Vorgang.',409);
        (new Registry($this->db,$this->context->actor()))->contact($result['contact']);return $result['contact'];
    }
}
