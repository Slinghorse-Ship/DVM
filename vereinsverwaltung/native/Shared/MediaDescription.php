<?php declare(strict_types=1);
namespace Kulturhus\Native\Shared;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
/** Shared unchanged source/license/consent value contract for photos and videos. */
final class MediaDescription
{
    public const CHANNELS = ['website'=>'Website', 'newsletter'=>'Newsletter', 'social'=>'Social Media', 'press'=>'Presse'];
    public const RIGHTS = ['unknown'=>'Ungeprüft', 'cleared'=>'Nutzung geklärt', 'withdrawn'=>'Nutzung gesperrt'];
    public const CONSENT = ['unknown'=>'Ungeprüft', 'not_required'=>'Nicht erforderlich', 'recorded'=>'Liegt vor', 'withdrawn'=>'Widerrufen'];
    public const PUBLIC_FIELDS=['alt','credit','source_name','source_url','license_name','license_url'];
    public static function normalize(array $input,string $kind='Bild'): array
    {
        $result = [];
        foreach (['alt'=>500, 'credit'=>500, 'source_name'=>500, 'license_name'=>200] as $key=>$max) $result[$key] = self::text($input[$key] ?? '', $max, $key==='alt',false,$kind);
        foreach (['source_url', 'license_url'] as $key) {
            $url = self::text($input[$key] ?? '', 2048,false,false,$kind);
            if ($url !== '' && (!filter_var($url, FILTER_VALIDATE_URL) || parse_url($url, PHP_URL_SCHEME)!=='https' || parse_url($url, PHP_URL_USER)!==null || parse_url($url, PHP_URL_PASS)!==null)) throw new InvalidArgumentException('Quellen- und Lizenzlinks als HTTPS-Adresse ohne Zugangsdaten angeben.');
            $result[$key] = $url;
        }
        foreach (['rights_state'=>self::RIGHTS, 'consent_state'=>self::CONSENT] as $key=>$choices) { $value = $input[$key] ?? 'unknown'; if (!is_string($value) || !isset($choices[$value])) throw new InvalidArgumentException($kind.'rechte und Einwilligung prüfen.'); $result[$key] = $value; }
        $expiry = self::text($input['expires_on'] ?? '', 10,false,false,$kind);
        $date = $expiry === '' ? false : DateTimeImmutable::createFromFormat('!Y-m-d', $expiry, new DateTimeZone('Europe/Berlin'));
        if ($expiry !== '' && (!$date || $date->format('Y-m-d') !== $expiry)) throw new InvalidArgumentException('Gültiges Ablaufdatum angeben.');
        $result['expires_on'] = $expiry;
        $channels = $input['channels'] ?? array_keys(self::CHANNELS);
        if (!is_array($channels) || !array_is_list($channels) || count($channels)>4) throw new InvalidArgumentException($kind.'kanäle als Liste angeben.');
        foreach ($channels as $channel) if (!is_string($channel) || !isset(self::CHANNELS[$channel])) throw new InvalidArgumentException($kind.'kanal prüfen.');
        $result['channels'] = array_values(array_intersect(array_keys(self::CHANNELS), $channels));
        return $result;
    }

    public static function text(mixed $value, int $max, bool $required = false, bool $multiline = false,string $kind='Bild'): string
    {
        if (!is_string($value) || !preg_match('//u', $value) || mb_strlen($value)>$max || preg_match($multiline ? '/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/' : '/[\x00-\x1f\x7f]/', $value)) throw new InvalidArgumentException($kind.'angaben als gültigen begrenzten Text angeben.');
        $value = trim($value);
        if ($required && $value==='') throw new InvalidArgumentException($kind==='Video'?'Beschreibung für das Video angeben.':'Beschreibenden Alternativtext angeben.');
        return $value;
    }
    public static function usable(array $metadata, string $channel): bool
    {
        if (!isset(self::CHANNELS[$channel])) throw new InvalidArgumentException('Bildkanal prüfen.');
        $policy=$metadata['usage_policy']??[];
        if(($policy['check_rights']??false)&&($metadata['rights_state']!=='cleared'||!in_array($metadata['consent_state'],['recorded','not_required'],true)))return false;
        if($policy['require_details']??false)foreach(['credit','source_name','license_name']as$key)if(trim($metadata[$key]??'')==='')return false;
        return $metadata['rights_state'] !== 'withdrawn' && $metadata['consent_state'] !== 'withdrawn' && in_array($channel, $metadata['channels'], true) && ($metadata['expires_on'] === '' || $metadata['expires_on'] >= (new DateTimeImmutable('now', new DateTimeZone('Europe/Berlin')))->format('Y-m-d'));
    }

}
