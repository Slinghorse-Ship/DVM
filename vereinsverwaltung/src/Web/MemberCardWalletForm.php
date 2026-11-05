<?php declare(strict_types=1);
namespace Kulturhus\Verein\Web;
use DomainException;
use Kulturhus\Verein\MemberCardWallet;

/** Closed setup frame. Passwords and uploaded keys never become recovery fields. */
final class MemberCardWalletForm
{
    public const ACTIONS=['member-card-wallet-apple','member-card-wallet-google','member-card-wallet-probe','member-card-wallet-toggle','member-card-wallet-remove'];
    public static function frame(array $query,#[\SensitiveParameter]array $post=[]):void
    {
        if(($query['view']??null)!=='settings'||($query['tab']??null)!=='member-cards'||array_diff(array_keys($query),['view','tab'])!==[]||$post&&!in_array($post['action']??null,self::ACTIONS,true))throw new DomainException('Wallet-Einrichtung im ursprünglich geöffneten Bereich prüfen.',422);
    }
    public static function flag(mixed $raw):bool{try{return MemberCardForm::flag($raw);}catch(DomainException){throw new DomainException('Wallet-Angaben prüfen.',422);}}
    public static function value(#[\SensitiveParameter]mixed $raw,int $max=200):string
    {
        try{return MemberCardForm::text($raw,$max);}catch(DomainException){throw new DomainException('Wallet-Angaben prüfen.',422);}
    }
    private static function upload(array $files,string $name,int $max):string
    {
        $file=$files[$name]??null;if(!is_array($file)||($file['error']??null)!==UPLOAD_ERR_OK||!is_string($file['tmp_name']??null)||!is_uploaded_file($file['tmp_name']))throw new DomainException('Wallet-Datei erneut auswählen.',422);
        $size=filesize($file['tmp_name']);if(!is_int($size)||$size<1||$size>$max)throw new DomainException('Wallet-Datei prüfen.',422);$bytes=file_get_contents($file['tmp_name']);if(!is_string($bytes)||strlen($bytes)!==$size)throw new DomainException('Wallet-Datei prüfen.',422);return$bytes;
    }
    public static function act(MemberCardWallet $wallet,array $query,#[\SensitiveParameter]array $post,#[\SensitiveParameter]array $files):void
    {
        self::frame($query,$post);$action=$post['action'];$version=MemberCardForm::number($post['wallet_version']??null);
        if($action==='member-card-wallet-apple'){$wallet->saveApple($version,self::flag($post['enabled']??null),self::value($post['pass_type_id']??null),self::value($post['team_id']??null,10),self::upload($files,'apple_certificate',262144),self::value($post['apple_password']??null,1000));return;}
        if($action==='member-card-wallet-google'){$wallet->saveGoogle($version,self::flag($post['enabled']??null),self::value($post['issuer_id']??null,30),self::value($post['class_id']??null,150),self::upload($files,'google_account',131072),self::flag($post['publishing_confirmed']??null));return;}
        if($action==='member-card-wallet-probe'){$wallet->probeGoogle($version);return;}
        $provider=self::value($post['provider']??null,16);if(!in_array($provider,['apple','google'],true))throw new DomainException('Wallet-Angaben prüfen.',422);
        if($action==='member-card-wallet-toggle'){$wallet->toggle($provider,$version,self::flag($post['enabled']??null),$provider==='google'?self::flag($post['publishing_confirmed']??null):null);return;}
        if(!self::flag($post['confirm_remove']??null))throw new DomainException('Wallet-Angaben prüfen.',422);$wallet->remove($provider,$version);
    }
}
