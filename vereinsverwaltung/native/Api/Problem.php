<?php declare(strict_types=1);
namespace Kulturhus\Native\Api;
final class Problem extends \RuntimeException
{
    public function __construct(public readonly string $key,public readonly int $status,string $message){parent::__construct($message);}
    public static function invalid():self{return new self('invalid_request',422,'Anfragedaten entsprechen nicht dem API-Vertrag.');}
    public static function unauthorized():self{return new self('authentication_required',401,'Aktive native Anmeldung oder gültiger API-Zugang erforderlich.');}
    public static function forbidden():self{return new self('permission_denied',403,'Freigabe und aktuelle Berechtigung für diese Aktion fehlen.');}
    public static function missing():self{return new self('not_found',404,'Der angeforderte eigene Datensatz ist nicht verfügbar.');}
    public static function conflict():self{return new self('version_conflict',409,'Der gespeicherte Stand wurde geändert. Aktuellen Datensatz erneut lesen.');}
}
