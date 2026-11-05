<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use DomainException;

/** A taxonomy choice describes an asset; it does not approve its tax deduction. */
final class FinancialReserveXml
{
    public const CODES=['72500','72550'];
    public const PREFIX='is.netIncome.regular.fin.netParticipation.amortFinanc.';
    public const LABELS=['financialsExcept.participCorp.domesticCorp'=>'Beteiligungen an inländischen Kapitalgesellschaften','financialsExcept.participCorp.foreignCorp'=>'Beteiligungen an ausländischen Kapitalgesellschaften','financialsExcept.participCorp.other'=>'Beteiligungen an Kapitalgesellschaften · sonstige','other'=>'Sonstige Finanzabschreibungen · nur wenn nicht genauer zuordenbar','financialsExcept.loansSharehRelPart.domestic'=>'Ausleihungen an Gesellschafter oder nahestehende Personen · Inland','financialsExcept.loansSharehRelPart.foreign'=>'Ausleihungen an Gesellschafter oder nahestehende Personen · Ausland','financialsExcept.loansSharehRelPart.other'=>'Ausleihungen an Gesellschafter oder nahestehende Personen · sonstige'];
    public static function choices(array $account,array $targets):array
    {
        if(!in_array($account['skr_code']??'',self::CODES,true))return $targets;
        if(($account['group']??'')!=='expense')return [];
        $suffixes=['financialsExcept.participCorp.domesticCorp','financialsExcept.participCorp.foreignCorp','financialsExcept.participCorp.other','other'];
        if($account['skr_code']==='72550')$suffixes=[...$suffixes,'financialsExcept.loansSharehRelPart.domestic','financialsExcept.loansSharehRelPart.foreign','financialsExcept.loansSharehRelPart.other'];
        $choices=[];foreach($suffixes as $suffix)$choices[self::PREFIX.$suffix]=self::LABELS[$suffix];return $choices;
    }
    public static function assertExportable(array $accounts):void
    {
        foreach($accounts as $account)if(in_array($account['skr_code']??'',self::CODES,true)){
            if(!is_int($account['amount_cents']??null))throw new DomainException('Geprüfter Finanzanlagenbetrag fehlt.');
            if($account['amount_cents']!==0)throw new DomainException('72500/72550: XML-Detailposition allein bestätigt keine steuerliche Übertragung. Originalrücklage, Rechtsnachfolge, Anlagenbasis und tatsächliche Steuerbuchungen benötigen noch eine fachliche Abnahme.');
        }
    }
}
