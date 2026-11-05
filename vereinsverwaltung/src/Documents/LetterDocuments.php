<?php declare(strict_types=1);
namespace Kulturhus\Verein;

final class LetterDocuments
{
    public static function create(Documents $documents,Settings $settings,array $input,string $requestKey):string
    {
        if(!$documents->canScope('orga',true))throw new \DomainException('Schreibberechtigung für Dokumente erforderlich.');
        if(!preg_match('/^[a-f0-9]{32}$/D',$requestKey))throw new \DomainException('Bitte das Briefformular neu öffnen.');
        $letter=DocumentPreview::letter($settings,$input);
        return $documents->generated('kulturhus-brief.pdf',DocumentPreview::pdf($letter),[
            'title'=>$letter['title'],'kind'=>'other','scope'=>'orga','document_date'=>$input['date']
        ],$requestKey,$letter);
    }
}
