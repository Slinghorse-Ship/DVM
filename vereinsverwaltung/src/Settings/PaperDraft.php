<?php declare(strict_types=1);
namespace Kulturhus\Verein\Settings;
use DomainException;
use Kulturhus\Native\Shared\SecretBox;

/** Pending logo pixels belong only to the authenticated private draft, never canonical assets. */
final class PaperDraft
{
    private SecretBox $box;
    public function __construct(#[\SensitiveParameter]string $key)
    {if(strlen($key)<32)throw new DomainException('Geschützter Serverschlüssel fehlt.',503);$this->box=new SecretBox(hash_hkdf('sha256',$key,32,'dvm-paper-private-logo-v1'));}
    private function context(array $draft):string
    {return 'dvm-paper-logo/1'."\0".$draft['club']."\0".$draft['actor']."\0".$draft['id'];}
    public function logo(array $draft):?string
    {
        if(($draft['input']['logo']??null)===null)return null;
        if($draft['accepted']!==null)throw new DomainException('Angenommenes Briefpapier über seine ursprüngliche Kennung lesen.',409);
        $data=$this->box->open($this->context($draft),$draft['logo_cipher']??'');$bytes=is_string($data['bytes']??null)?base64_decode($data['bytes'],true):false;
        if(!is_string($bytes))throw new DomainException('Private vorgemerkte Logodatei ist beschädigt.',503);PaperInput::bytes($draft['input'],$bytes);return$bytes;
    }
    public function upload(array $draft,array $upload):array
    {
        if($draft['pending']||$draft['accepted']!==null)throw new DomainException('Zuerst den ursprünglichen Briefpapierausgang lesen.',409);
        $draft['logo_previous']??=$draft['input']['data']['logo'];$next=$draft['input'];$next['logo']=$upload['meta'];$next['data']['logo']='';PaperInput::bytes($next,$upload['bytes']);
        $draft=GeneralDraft::replace($draft,$next);$draft['logo_cipher']=$this->box->seal($this->context($draft),['bytes'=>base64_encode($upload['bytes'])]);return$draft;
    }
    public static function forget(array $draft):array
    {
        if($draft['pending']||$draft['accepted']!==null)throw new DomainException('Zuerst den ursprünglichen Briefpapierausgang lesen.',409);
        $next=$draft['input'];$next['logo']=null;$next['data']['logo']=$draft['logo_previous']??$next['data']['logo'];$draft=GeneralDraft::replace($draft,$next,true);$draft['logo_cipher']='';unset($draft['logo_previous']);return$draft;
    }
    public static function accepted(array $draft,string $key):array
    {$draft['accepted']=$key;$draft['pending']=false;$draft['logo_cipher']='';$draft['previous']=[];return$draft;}
}
