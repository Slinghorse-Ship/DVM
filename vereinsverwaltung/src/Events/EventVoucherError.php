<?php declare(strict_types=1);
namespace Kulturhus\Verein;

/** Translate owned sentences while exact human labels and exact amounts remain separate. */
final class EventVoucherError extends \DomainException
{
    public ?int $balance=null;
    public string $unit='';
    public function __construct(public string $source,public array $arguments=[],int $status=422)
    {
        $replace=[];foreach($arguments as$key=>$value)$replace['{'.$key.'}']=(string)$value;
        parent::__construct(strtr($source,$replace),$status);
    }
    public static function balance(int $amount,string $unit):self
    {
        $failure=new self('Es sind nur noch {amount} übrig.',['amount'=>EventVouchers::display($amount,$unit)],409);$failure->balance=$amount;$failure->unit=$unit;return$failure;
    }
    public function translated(callable $text,string $locale):string
    {
        $arguments=$this->arguments;
        if($this->balance!==null){
            require_once dirname(__DIR__).'/Documents/PersonalDocumentText.php';
            $arguments['amount']=$this->unit==='eur'?\Kulturhus\Verein\Documents\PersonalDocumentText::money($this->balance,$locale):strtr($text('{count} Stück'),['{count}'=>(string)$this->balance]);
        }
        $replace=[];foreach($arguments as$key=>$value)$replace['{'.$key.'}']=(string)$value;
        return strtr($text($this->source),$replace);
    }
}
