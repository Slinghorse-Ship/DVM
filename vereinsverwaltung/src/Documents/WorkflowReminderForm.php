<?php declare(strict_types=1);
namespace Kulturhus\Verein\Documents;

/** A review remains the same personal, expiring recipient snapshot until explicit replacement. */
final class WorkflowReminderForm
{
    public static function pending(mixed $pending,string $actor,array $post,int $now):array
    {
        if(!is_array($pending)||!is_string($pending['actor']??null)||$pending['actor']!==$actor||!is_int($pending['expires']??null)||$pending['expires']<$now||!is_string($pending['hash']??null)||!preg_match('/\A[a-f0-9]{64}\z/D',$pending['hash'])||!is_string($pending['document']??null)||!is_int($pending['revision']??null)||!is_string($pending['key']??null)||!preg_match('/\A[a-f0-9]{32}\z/D',$pending['key'])||!is_string($post['wf_mail_hash']??null)||!hash_equals($pending['hash'],$post['wf_mail_hash']))throw new \DomainException('Erinnerung erneut prüfen und Versand bestätigen.');
        foreach(['wf_id','wf_version']as$key)if(isset($post[$key])&&!is_string($post[$key]))throw new \DomainException('Erinnerung erneut prüfen und Versand bestätigen.');
        if(isset($post['wf_mail_context'])&&(!is_string($post['wf_mail_context'])||!in_array($post['wf_mail_context'],['','1'],true)))throw new \DomainException('Erinnerungsformular nicht vollständig übertragen. Vorschau erneut öffnen.');
        $legacy=($post['wf_mail_context']??'')===''&&($post['wf_id']??'')===''&&in_array($post['wf_version']??'',['','0'],true);
        if(!$legacy&&(($post['wf_id']??'')!==$pending['document']||($post['wf_version']??'')!==(string)$pending['revision']))throw new \DomainException('Erinnerung gehört zu einer anderen geprüften Fassung. Vorschau erneut öffnen.');
        if(($post['wf_mail_context']??'')==='1'&&(($post['wf_id']??'')!==$pending['document']||($post['wf_version']??'')!==(string)$pending['revision']))throw new \DomainException('Erinnerungsformular nicht vollständig übertragen. Vorschau erneut öffnen.');
        return $pending;
    }
    public static function original(mixed $pending,string $actor,string $document,int $revision,int $now):?array
    {
        if(!is_array($pending)||($pending['actor']??null)!==$actor||!is_int($pending['expires']??null)||$pending['expires']<$now||($pending['document']??null)!==$document||($pending['revision']??null)!==$revision||!is_array($pending['preview']??null))return null;
        $preview=$pending['preview'];return is_string($pending['hash']??null)&&is_string($preview['hash']??null)&&hash_equals($pending['hash'],$preview['hash'])&&($preview['document_id']??null)===$document&&($preview['revision']??null)===$revision?$preview:null;
    }
}
