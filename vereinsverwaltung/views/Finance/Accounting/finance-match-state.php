<?php declare(strict_types=1);
// Caller has already read this exact authorized entry or transaction.
$financeMatchMessages=isset($nativeAccess)?(new \Kulturhus\Native\Language\Catalog($db,$nativeAccess))->messages():[];
$financeMatchText=static fn(string $source,array $values=[]):string=>\Kulturhus\Native\Language\FinanceMatchingMessages::text($source,$financeMatchMessages,$values);
$financeMatchSavedPost=$_POST;
$financeMatchState=\Kulturhus\Verein\Web\FinanceMatchDraft::view($_SESSION,$db,(string)$_SESSION['actor'],$financeMatchRoute,$_POST,$_GET,$financeMatchReviewState??null);
$financeMatchBody=$financeMatchState['draft']['body']??(\Kulturhus\Verein\Web\FinanceMatchDraft::matchesRoute($_POST,$financeMatchRoute)?$_POST:[]);
$financeMatchNonce=$financeMatchState['draft']['id']??'';
if($financeMatchState['draft']!==null)$_POST=$financeMatchBody+['match_draft'=>$financeMatchNonce];
$financeMatchContext=$financeMatchRoute['kind']==='transaction'?bankTransactionContext($financeMatchRoute['id'],$_GET):receiptPaymentContext($financeMatchRoute['id'],$_GET);
if($financeMatchNonce!=='')$financeMatchContext['match_draft']=$financeMatchNonce;
$financeMatchFreshContext=$financeMatchContext;unset($financeMatchFreshContext['match_draft']);
