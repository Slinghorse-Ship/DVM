<?php declare(strict_types=1);
/** Current linked native purpose routing. Replaces removed ProcessWire transport doubles. */
require_once dirname(__DIR__,3).'/vendor/autoload.php';
require_once dirname(__DIR__,2).'/native/bootstrap.php';
require_once dirname(__DIR__,2).'/src/bootstrap.php';
function ownMailPurposeFixture():array
{
    $db=\Kulturhus\Verein\Tests\MySqlFixture::connect();\Kulturhus\Native\Installer\SchemaRegistry::install($db);
    $db->exec("INSERT INTO clubs VALUES('own-purpose-club','Own purpose association'),('other-purpose-club','Other purpose association');INSERT INTO actors(id,club_id,role,is_superuser) VALUES('own-purpose-admin','own-purpose-club','members',1),('own-purpose-reader','own-purpose-club','member',0),('other-purpose-admin','other-purpose-club','members',1)");
    $profile=\Kulturhus\Verein\SettingsSchema::defaults('club');$profile['name']='Own purpose association';(new \Kulturhus\Verein\Settings($db,'own-purpose-admin'))->save('club',$profile,0);
    $access=(new \Kulturhus\Native\Integration\ManagerBridge($db,'own-purpose-admin','native',static fn()=>null))->access();$other=(new \Kulturhus\Native\Integration\ManagerBridge($db,'other-purpose-admin','native',static fn()=>null))->access();$key=random_bytes(32);$box=new \Kulturhus\Native\Shared\SecretBox(random_bytes(32));$channels=new \Kulturhus\Verein\MailChannelSettings($db,'own-purpose-admin',$key);$mailbox=new \Kulturhus\Verein\InvoiceMailbox($db,'own-purpose-admin',$key);$mail=new \Kulturhus\Native\Delivery\MailAccount($db,$box,static fn():string=>$key);
    return compact('db','access','other','key','box','channels','mailbox','mail');
}
function ownMailPurposeConfigured(array $f):array
{
    $data=\Kulturhus\Verein\MailChannelSettings::defaults();
    foreach(['board','press','newsletter']as $channel)$data['channels'][$channel]=array_replace($data['channels'][$channel],['override'=>true,'host'=>'smtp.example.test','port'=>$channel==='press'?'587':'465','user'=>'own-'.$channel.'@example.invalid','from'=>'own-'.$channel.'@example.invalid','reply'=>'own-reply-'.$channel.'@example.invalid','name'=>$channel==='newsletter'?str_repeat('ä',75):'Own '.$channel.' displayed sender','password'=>'Own synthetic '.$channel.' private test secret']);
    $f['channels']->save($data,$f['channels']->state()['version']);return $data;
}
function ownMailPurposeCheck(bool $ok,string $label):void
{global $mailPurposeChecks;if(!$ok)throw new RuntimeException($label);$mailPurposeChecks++;echo 'PASS '.$label."\n";}
