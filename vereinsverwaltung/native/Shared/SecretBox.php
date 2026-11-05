<?php declare(strict_types=1);
namespace Kulturhus\Native\Shared;
final class SecretBox
{
    public function __construct(#[\SensitiveParameter] private string $key)
    {if(strlen($key)!==SODIUM_CRYPTO_SECRETBOX_KEYBYTES)throw new \DomainException('Privater Schlüssel nicht verfügbar.');}
    public static function fromFile(string $path):self
    {if(is_link($path)||!is_file($path)||(fileperms($path)&0077))throw new \DomainException('Privater Schlüssel benötigt Modus 0600.');return new self(file_get_contents($path));}
    public function seal(string $context,array $payload):string
    {$nonce=random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);return base64_encode($nonce.sodium_crypto_secretbox(json_encode([$context,$payload],JSON_THROW_ON_ERROR),$nonce,$this->key));}
    public function open(string $context,#[\SensitiveParameter] string $ciphertext):array
    {$bytes=base64_decode($ciphertext,true);if($bytes===false||strlen($bytes)<40)throw new \DomainException('Private Konfiguration beschädigt.');$plain=sodium_crypto_secretbox_open(substr($bytes,24),substr($bytes,0,24),$this->key);if($plain===false)throw new \DomainException('Private Konfiguration nicht authentifiziert.');$data=json_decode($plain,true,64,JSON_THROW_ON_ERROR);if(!is_array($data)||($data[0]??null)!==$context||!is_array($data[1]??null))throw new \DomainException('Private Konfiguration gehört zu einem anderen Kontext.');return $data[1];}
    public function fingerprint(string $value):string{return hash_hmac('sha256',$value,$this->key);}
}
