<?php declare(strict_types=1);
$cardPage=new \Kulturhus\Verein\Web\MemberCardPage((string)$_SESSION['csrf'],$memberCardMessages??[],($memberCardLocale??'de'));
if($memberCardContext!==null)echo$cardPage->render($memberCardContext,$memberCardPrivate,$memberCardHistory,$memberCardAvailability,$memberCardError,$memberCardMayConfigure);
elseif(($_SERVER['REQUEST_METHOD']??'GET')==='POST'&&$memberCardContact!==''&&in_array($_POST['action']??null,\Kulturhus\Verein\Web\MemberCardForm::ACTIONS,true)&&in_array(http_response_code(),[409,503],true))echo$cardPage->recovery($memberCardContact,$memberCardDraft,$memberCardError);
else echo'<div class="content member-card-page"><h1>'.e($memberCardText('Mitgliederausweis')).'</h1><p role="alert">'.e($memberCardText($memberCardError?:'Mitgliederausweis derzeit nicht verfügbar.')).'</p></div>';
