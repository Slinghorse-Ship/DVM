<?php declare(strict_types=1);
namespace Kulturhus\Native\Api;
final class Scopes
{
    public const ALL=[
        'pages.read'=>['Seiten lesen','website','read'],'pages.write'=>['Seiten bearbeiten','website','edit'],'pages.publish'=>['Seiten veröffentlichen','website','publish'],
        'schema.read'=>['Felder und Vorlagen lesen','website','read'],'schema.write'=>['Felder und Vorlagen bearbeiten','website','manage'],
        'users.read'=>['Konten lesen','accounts','manage'],'users.write'=>['Konten verwalten','accounts','manage'],
        'roles.read'=>['Rollen lesen','settings','read'],'roles.write'=>['Rollen verwalten','settings','write'],
        'permissions.read'=>['Eigene Berechtigungen lesen','identity','read'],
        'files.read'=>['Dateien lesen','website','read'],'files.write'=>['Dateien hochladen und bearbeiten','website','edit'],
    ];
    public static function validate(mixed $scopes):array
    {
        if(!is_array($scopes)||!array_is_list($scopes)||!$scopes||count($scopes)>count(self::ALL))throw Problem::invalid();
        foreach($scopes as $scope)if(!is_string($scope)||!isset(self::ALL[$scope]))throw Problem::invalid();
        if(count(array_unique($scopes))!==count($scopes))throw Problem::invalid();sort($scopes,SORT_STRING);return $scopes;
    }
}
