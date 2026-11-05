<?php declare(strict_types=1);
namespace Kulturhus\Banking;

use Fhp\Action\SendSEPARealtimeTransfer;
use Fhp\Model\SEPAAccount;
use Fhp\Protocol\{BPD,UPD,Message};
use Fhp\Segment\IPZ\HIIPZSv2;

/** Narrow compatibility wrapper around the pinned native action; vendor stays unchanged. */
final class InstantTransfer extends SendSEPARealtimeTransfer
{
    public bool $convertedToStandard = false;

    public function __construct(SEPAAccount $account,string $xml)
    {
        // Use the library's factory and supported serialization contract for its private fields.
        parent::__unserialize(parent::create($account,$xml,false)->__serialize());
    }

    public function __serialize(): array
    {
        return ['native'=>parent::__serialize(),'converted'=>$this->convertedToStandard];
    }

    public function __unserialize(array $state): void
    {
        parent::__unserialize($state['native']);
        $this->convertedToStandard=(bool)$state['converted'];
    }

    protected function createRequest(BPD $bpd,?UPD $upd)
    {
        $parameters=$bpd->getLatestSupportedParameters('HIIPZS');
        if(!$parameters instanceof HIIPZSv2)throw new \DomainException('Diese Bankverbindung bietet keine Echtzeitüberweisung ohne automatische Umwandlung an. Keine Zahlung gesendet.');
        if($parameters->parameter->getUnterstuetzteSEPADatenformate()===[]) {
            // php-fints 4.1.0 tests for null, but its trait returns [] for missing formats.
            // Fall back only to formats actually advertised by this bank, never invent one.
            $general=$bpd->requireLatestSupportedParameters('HISPAS')->getParameter()->getUnterstuetzteSEPADatenformate();
            $bpd=clone $bpd;$parameters=clone $parameters;$parameters->parameter=clone $parameters->parameter;
            $parameters->parameter->unterstuetzteSEPADatenformate=$general;
            $bpd->parameters['HIIPZS']=[2=>$parameters];
        }
        return parent::createRequest($bpd,$upd);
    }

    public function processResponse(Message $response)
    {
        parent::processResponse($response);
        // Never treat a bank-reported conversion as an error to retry: it may already be sent.
        if($response->findRueckmeldungen(3270)!==[])$this->convertedToStandard=true;
    }
}
