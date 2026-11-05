<?php declare(strict_types=1);
if(isset($_GET['volunteer_file'])||isset($_GET['volunteer_preview'])){
 $volunteerMessages=isset($nativeAccess)?(new \Kulturhus\Native\Language\Catalog($db,$nativeAccess))->messages():[];
 $volunteerText=static fn(string $source):string=>\Kulturhus\Verein\Documents\HseInterfaceText::text($source,$volunteerMessages);
 header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');
 try{
  if(isset($_GET['volunteer_file'])){
   $asset=(new \Kulturhus\Verein\VolunteerForm($db,(string)$_SESSION['actor']))->file(input($_GET,'volunteer_file'));
   header('Content-Type: application/pdf');header('Content-Disposition: attachment; filename="ehrenamtsbestaetigung.pdf"');echo $asset['bytes'];
  }else{
   $draft=$_SESSION['volunteer_preview']??null;
   if(!is_array($draft)||($draft['actor']??'')!==$_SESSION['actor']||($draft['expires']??0)<time()||!is_string($draft['token']??null)||!hash_equals($draft['token'],input($_GET,'volunteer_preview')))throw new DomainException('Vorschau nicht mehr verfügbar. Bitte erneut erstellen.');
   $service=new \Kulturhus\Verein\VolunteerForm($db,(string)$_SESSION['actor'],$draft['kind']??'hygiene');
   if(!hash_equals($draft['hash'],\Kulturhus\Verein\VolunteerForm::digest($service->build($draft['contact'],$draft['input'],$draft['signed'])))||!is_string($draft['bytes']??null)||!is_string($draft['pdf_sha256']??null)||!hash_equals($draft['pdf_sha256'],hash('sha256',$draft['bytes'])))throw new DomainException('Vorschau nicht mehr verfügbar. Bitte erneut erstellen.');
   header('Content-Type: application/pdf');header('Content-Disposition: inline; filename="ehrenamtsbestaetigung-vorschau.pdf"');echo $draft['bytes'];
  }
 }catch(DomainException $failure){http_response_code($failure->getCode()===503?503:403);echo e($volunteerText($failure->getCode()===503?$failure->getMessage():(isset($_GET['volunteer_file'])?'Ehrenamtsbestätigung nicht verfügbar.':'Vorschau nicht mehr verfügbar. Bitte erneut erstellen.')));}
 catch(Throwable){http_response_code(500);echo e($volunteerText('Bestätigung konnte nicht geladen werden.'));}
 exit;
}
