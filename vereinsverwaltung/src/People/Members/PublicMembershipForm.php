<?php declare(strict_types=1);
namespace Kulturhus\Verein;
final class PublicMembershipForm
{
    public static function handle(MembershipApplications $intake,array $post,string $csrf,bool $submitted,bool $local=true): array
    {
        $settings=$intake->resolvedSettings();$state=['rates'=>$intake->rates(),'settings'=>$settings,'csrf'=>$csrf,'values'=>$post,'key'=>is_string($post['request_key']??null)?$post['request_key']:bin2hex(random_bytes(16)),'error'=>'','success'=>false,'local'=>$local];
        if(!$submitted)return $state;
        try{
            if(!is_string($post['csrf']??null)||!hash_equals($csrf,$post['csrf']))throw new \DomainException('Formular abgelaufen. Bitte neu öffnen.');
            if(($post['action']??'')==='confirm-application'){ $intake->confirmEmail(is_string($post['confirmation_token']??null)?$post['confirmation_token']:'');$state['success']=true;$state['confirmed']=true;return $state;}
            if(!is_string($post['contact_website']??'')||($post['contact_website']??'')!=='')throw new \DomainException('Das Formular konnte nicht angenommen werden. Bitte erneut öffnen.');
            $answers=[];foreach(array_merge(MembershipApplications::FIELDS,['membership_type'=>'Mitgliedschaft','family_members'=>'Familienangehörige','family_reference'=>'Bestehende Familie']) as $name=>$label){if(isset($post[$name])&&!is_string($post[$name]))throw new \DomainException('Antragsangaben prüfen.');$answers[$name]=$post[$name]??($name==='membership_type'?'single':'');}
            $intake->submit($answers,$state['key'],(int)($post['configuration_version']??-1),($post['consent']??'')==='1',false,true,($post['newsletter']??'')==='1');$state['success']=true;
        }catch(\DomainException $e){$state['error']=$e->getMessage();}catch(\Throwable){$state['error']='Der Antrag konnte nicht gespeichert werden. Bitte später erneut versuchen.';}
        if((int)($post['configuration_version']??-1)!==$settings['version'])unset($state['values']['consent']);
        return $state;
    }
}
