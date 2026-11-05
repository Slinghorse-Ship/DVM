<?php declare(strict_types=1);
// Real digest formatter, synthetic event objects only; no database or delivery.
namespace ProcessWire {
    class Pageimage {public string $url='/site/assets/files/1/synthetic-event.jpg';}
    class Pageimages {public function first():Pageimage{return new Pageimage();}}
}
namespace {
require __DIR__.'/../../processwire/site/src/NewsletterCampaignService.php';
$ref=new ReflectionClass(\Kulturhus\NewsletterCampaignService::class);$service=$ref->newInstanceWithoutConstructor();$digest=$ref->getMethod('digestBody');
$savedUrl=getenv('APP_URL');putenv('APP_URL=https://example.invalid');$checks=0;
function venueCheck(bool $value,string $label):void{global $checks;if(!$value)throw new RuntimeException($label);++$checks;}
try{
    for($month=1;$month<=12;$month++){
        $start=new DateTimeImmutable(sprintf('2027-%02d-01 10:00:00',$month),new DateTimeZone('Europe/Berlin'));
        $event=(object)['start_at'=>$start->getTimestamp(),'summary'=>'Synthetischer Veranstaltungstext.','venue_name'=>'Test-Gemeindezentrum','venue_address'=>'Testweg 12','url'=>'/veranstaltungen/test/','title'=>'Test-Veranstaltung','event_image'=>new \ProcessWire\Pageimages()];
        foreach([false,true] as $quarterly){
            $html=$digest->invoke($service,[$event],$start,$start->modify($quarterly?'+3 months':'+1 month'),$quarterly);
            venueCheck(!preg_match('/\b(?:im|ins) Kulturhus\b/u',$html),'association is not a venue: '.$month.' '.(int)$quarterly);
            venueCheck(str_contains($html,'<strong>Ort:</strong> Test-Gemeindezentrum · Testweg 12'),'actual event venue retained');
            venueCheck(str_contains($html,'Kulturhus Jaderberg e. V.')&&str_contains($html,'synthetic-event.jpg'),'association signature and event image retained');
        }
    }
    $event2=clone $event;$event2->venue_name='Test-Künstlerhaus';$event2->venue_address='Beispielstraße 4';
    $html=$digest->invoke($service,[$event,$event2],$start,$start->modify('+1 month'),false);
    venueCheck(substr_count($html,'<strong>Ort:</strong>')===2&&str_contains($html,'Test-Künstlerhaus · Beispielstraße 4'),'separate locations per event');
    $event->venue_name='';$event->venue_address='';$html=$digest->invoke($service,[$event],$start,$start->modify('+1 month'),false);
    venueCheck(!str_contains($html,'<strong>Ort:</strong>'),'missing venue never defaults to association');
    $autumn=$ref->getMethod('seasonalIntroduction')->invoke($service,new DateTimeImmutable('2027-10-01'));
    venueCheck($autumn==='Wenn die Tage wieder kürzer werden, wird es bei uns umso lebendiger. Wir freuen uns auf gemeinsame Abende mit euch.','recovered editorial autumn wording retained');
    $authored='<p>Ein bereits gespeicherter Text bleibt unverändert – auch im Kulturhus.</p>';
    venueCheck($service->editorBodyHtml($authored)===$authored,'no global replacement in saved editorial copy');
    echo "$checks newsletter association/venue checks passed; no database or transport\n";
}finally{putenv($savedUrl===false?'APP_URL':'APP_URL='.$savedUrl);}
}
