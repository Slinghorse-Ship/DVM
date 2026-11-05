<?php declare(strict_types=1);
ini_set('zend.exception_ignore_args','1');require dirname(__DIR__).'/native/bootstrap.php';require dirname(__DIR__).'/src/bootstrap.php';
use Kulturhus\Native\TestSupport;use Kulturhus\Verein\{Registry,Web\MemberProfileCreation};
$input=json_decode(stream_get_contents(STDIN),true,64,JSON_THROW_ON_ERROR);$db=TestSupport::db();$context=\Kulturhus\Native\Api\Factory::session($db,$input['token']);$registry=new Registry($db,$context->actor());$body=$input['body'];$result=(new MemberProfileCreation($db,$context))->create($body,static fn()=>$registry->saveContact(null,0,'person',$body['name'],$body['email'],$body['address'],['phone'=>$body['phone'],'notes'=>$body['notes']]));echo json_encode($result,JSON_THROW_ON_ERROR)."\n";
