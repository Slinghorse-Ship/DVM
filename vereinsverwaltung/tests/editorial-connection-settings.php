<?php declare(strict_types=1);
require __DIR__.'/../src/bootstrap.php';
require_once __DIR__.'/../src/Communication/EditorialConnectionSettings.php';
use Kulturhus\Verein\EditorialConnectionSettings as Connection;
$n=0;
function check(bool $ok,string $label):void{global $n;if(!$ok)throw new RuntimeException($label);$n++;echo "PASS $label\n";}
function denied(callable $f,string $label):void{try{$f();}catch(DomainException){check(true,$label);return;}throw new RuntimeException($label);}
$original=['public_theme'=>'kultur','facebook_url'=>'https://example.invalid','facebook_auto_publish'=>true,'instagram_auto_publish'=>true,'eventbrite_organization_id'=>'123','press_email'=>'presse@example.invalid','instagram_post_template'=>'Original'];
$website=Connection::merge('website',$original,['public_theme'=>'sport']);
check($website['public_theme']==='sport'&&$website['facebook_auto_publish']==='1'&&$website['eventbrite_organization_id']==='123'&&$website['instagram_post_template']==='Original','design preserves channel settings and worker flags');
$social=Connection::merge('social',$original,['facebook_post_template'=>'Neue Vorlage','instagram_auto_publish'=>'1','facebook_page_access_token'=>'']);
check($social['facebook_auto_publish']==='0'&&$social['instagram_auto_publish']==='1','unchecked automation is explicitly disabled');
check($social['public_theme']==='kultur'&&$social['press_email']==='presse@example.invalid'&&$social['eventbrite_organization_id']==='123','social preserves website, press and event connections');
check($social['facebook_page_access_token']==='','empty secret stays empty for native retain-secret operation');
denied(fn()=>Connection::merge('website',$original,['facebook_auto_publish'=>'1']),'website cannot change social automation');
denied(fn()=>Connection::merge('social',$original,['public_theme'=>'sport']),'social cannot change website design');
denied(fn()=>Connection::merge('social',$original,['eventbrite_token'=>'fake']),'social cannot replace event connection');
denied(fn()=>Connection::merge('newsletter',$original,[]),'unknown connection scope rejected');
denied(fn()=>Connection::merge('website',$original,['public_theme'=>'invalid']),'unknown design rejected');
denied(fn()=>Connection::merge('social',$original,['instagram_auto_publish'=>'true']),'invalid automation flag rejected');
denied(fn()=>Connection::merge('social',$original,['instagram_username'=>[]]),'nested field rejected');
echo "$n scoped connection checks passed\n";
