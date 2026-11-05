<?php declare(strict_types=1);
namespace Kulturhus\Verein\Documents;
use Kulturhus\Verein\DocumentWorkflow\{WorkflowException,RiskRegister};

/** Bind validation wording to known schema labels, without translating human field names. */
final class WorkflowFormError extends \DomainException
{
    private function __construct(private readonly string $source,private readonly array $arguments,private readonly array $owned=[])
    {
        parent::__construct(HseInterfaceText::format($source,$arguments,[]));
    }
    public function localized(array $messages):string
    {
        $arguments=$this->arguments;foreach($this->owned as$key)$arguments[$key]=HseInterfaceText::text($arguments[$key],$messages);return HseInterfaceText::format($this->source,$arguments,$messages);
    }
    public static function from(WorkflowException $failure,array $document):\DomainException
    {
        if($failure->isConflict()||$failure->isUnconfirmedCreation())return $failure;$message=$failure->getMessage();
        // Match human labels first, including names that resemble a built-in label.
        foreach($document['fields']as$field){
            $label=$field['label'];$type=$field['type'];
            if(in_array($type,['text','textarea'],true)){$limit=$type==='text'?2000:20000;if($message===$label.' muss reiner Text mit höchstens '.$limit.' Zeichen sein.')return new self('{label} muss reiner Text mit höchstens {count} Zeichen sein.',['label'=>$label,'count'=>$limit]);}
            $formats=['Das Feld „{label}“ benötigt ein gültiges Datum im Format JJJJ-MM-TT.','Das Feld „{label}“ benötigt eine endliche Zahl mit Dezimalpunkt.','Das Feld „{label}“ enthält keine gültige Auswahl.','Das Feld „{label}“ benötigt Ja oder Nein.','Das Pflichtfeld „{label}“ muss vor dem Einreichen ausgefüllt beziehungsweise bestätigt werden.'];
            foreach($formats as$source)if($message===strtr($source,['{label}'=>$label]))return new self($source,['label'=>$label]);
        }
        if(preg_match('/\ARegisterzeile ([1-9]|[12][0-9]|30): (.+)\z/D',$message,$match)){
            foreach(RiskRegister::TEXT_LIMITS as$column=>$limit){$label=RiskRegister::COLUMN_LABELS[$column];if($match[2]===$label.' muss reiner Text mit höchstens '.$limit.' Zeichen sein.')return new self('Registerzeile {row}: {column} muss reiner Text mit höchstens {count} Zeichen sein.',['row'=>$match[1],'column'=>$label,'count'=>$limit],['column']);if($match[2]===$label.' darf beim Einreichen nicht leer sein.')return new self('Registerzeile {row}: {column} darf beim Einreichen nicht leer sein.',['row'=>$match[1],'column'=>$label],['column']);}
            foreach(RiskRegister::DATE_LABELS as$label)if($match[2]===$label.' muss ein gültiges Datum im Format JJJJ-MM-TT sein.')return new self('Registerzeile {row}: {column} muss ein gültiges Datum im Format JJJJ-MM-TT sein.',['row'=>$match[1],'column'=>$label],['column']);
            foreach(['Ausgangsbewertung','Restbewertung']as$label)if($match[2]===$label.' muss vor dem Einreichen vollständig bewertet sein.')return new self('Registerzeile {row}: {column} muss vor dem Einreichen vollständig bewertet sein.',['row'=>$match[1],'column'=>$label],['column']);
        }
        return $failure;
    }
}
