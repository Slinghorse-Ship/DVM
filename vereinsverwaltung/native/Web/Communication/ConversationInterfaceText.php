<?php declare(strict_types=1);
namespace Kulturhus\Native\Web\Communication;
use Kulturhus\Native\Language\{Catalog,ConversationMessages};
trait ConversationInterfaceText
{
    private ?array $conversationInterfaceMessages=null;
    private ?bool $conversationEnglish=null;
    protected function conversationMessages():array
    {return$this->conversationInterfaceMessages??=(new Catalog($this->db,$this->access))->messages();}
    protected function conversationText(string $source,array $values=[]):string
    {return ConversationMessages::text($source,$this->conversationMessages(),$values);}
    protected function conversationDate(string $utc):string
    {
        if($utc==='')return'';
        $english=$this->conversationEnglish??=explode('-',(new Catalog($this->db,$this->access))->locale(),2)[0]==='en';
        return(new \DateTimeImmutable($utc,new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone('Europe/Berlin'))->format($english?'Y-m-d H:i':'d.m.Y H:i').' Europe/Berlin';
    }
}
