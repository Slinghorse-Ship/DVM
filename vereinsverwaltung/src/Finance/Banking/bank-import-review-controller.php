<?php declare(strict_types=1);
// Local original review is independent of a configured or currently open bank connection.
$bankNoticeMessages=isset($nativeAccess)?(new \Kulturhus\Native\Language\Catalog($db,$nativeAccess))->messages():[];
$bankNotice=static fn(string $text):string=>\Kulturhus\Native\Language\FinanceBankMessages::text($text,$bankNoticeMessages);
$bankImportReviewed=false;$bankGroupReviewed=false;$bankGroupPrepared=null;$_GET['bank_import']='1';
unset($_GET['directory'],$_GET['new_account'],$_GET['edit_account'],$_GET['fints']);
try{
    if(!$canFinance||!$finance->canWrite())throw new DomainException('Finanz-Schreibrecht für die Bankübernahmeprüfung erforderlich.',403);
    $bankImportService=new \Kulturhus\Verein\BankImport($db,(string)$_SESSION['actor']);
    if(input($_GET,'import_original')!==''||input($_GET,'import_correction')!==''||input($_GET,'import_group_original')!==''){
        if(($_SERVER['REQUEST_METHOD']??'GET')!=='GET'&&($_SERVER['REQUEST_METHOD']??'GET')!=='HEAD')throw new DomainException('Bankoriginal nur lesend öffnen.',422);
        $correction=input($_GET,'import_correction')!=='';
        $groupOriginal=input($_GET,'import_group_original')!=='';
        $original=$groupOriginal?$bankImportService->groupOriginal(input($_GET,'import_record'),input($_GET,'import_group_original')):($correction?$bankImportService->correctionOriginal(input($_GET,'import_record'),input($_GET,'import_correction')):$bankImportService->observation(input($_GET,'import_record'),input($_GET,'import_original'),$_GET['import_ordinal']??''));
        header('Content-Type: application/json; charset=UTF-8');header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');
        header('Content-Disposition: attachment; filename="'.($groupOriginal?'bank-sammelnachweis-'.$original['id']:($correction?'bankkorrektur-'.$original['id']:'bankoriginal-'.substr(hash('sha256',$original['run_id'].':'.$original['ordinal']),0,20))).'.json"');
        if(($_SERVER['REQUEST_METHOD']??'GET')!=='HEAD')echo $correction||$groupOriginal?$original['original_json']:$original['payload'];exit;
    }
    if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
        if(!hash_equals($_SESSION['csrf'],input($_POST,'csrf')))throw new DomainException('Bankformular abgelaufen. Bitte neu öffnen.',403);
        if(input($_POST,'action')!=='bank-import-review')throw new DomainException('Bankübernahmeprüfung erneut öffnen.',422);
        $record=input($_POST,'record');if(input($_GET,'import_record')!==''&&input($_GET,'import_record')!==$record)throw new DomainException('Bankformular gehört zu einer anderen Bankzeile.',409);
        $_GET['import_record']=$record;$current=$bankImportService->record($record);
        $move=input($_POST,'review_move');if(input($_POST,'choose_transaction')!=='')$move='choose';
        if(input($_POST,'choose_group_transaction')!=='')$move='group-add';
        if(input($_POST,'remove_group_transaction')!=='')$move='group-remove';
        if(input($_POST,'choose_payment_group')!=='')$move='group-reuse';
        if(input($_POST,'choose_fee_entry')!=='')$move='fee-choose';
        $feeMode=input($_POST,'decision')==='fee'||input($_POST,'target_mode')==='fee';$shareMode=$feeMode||input($_POST,'decision')==='shares'||input($_POST,'target_mode')==='shares';$groupMode=$shareMode||input($_POST,'decision')==='group'||input($_POST,'target_mode')==='group';
        if($groupMode){
            $groupIds=$_POST['group_transactions']??[];if(!is_array($groupIds))throw new DomainException('Vorhandene eigene Teilbewegungen erneut auswählen.',422);$groupIds=\Kulturhus\Verein\BankPaymentGroups::ids($groupIds);
            if($move==='group-add'){if(!in_array(input($_POST,'choose_group_transaction'),$groupIds,true))$groupIds[]=input($_POST,'choose_group_transaction');$groupIds=\Kulturhus\Verein\BankPaymentGroups::ids($groupIds);$_POST['group_reuse']='';}
            if($move==='group-remove'){$groupIds=array_values(array_diff($groupIds,[input($_POST,'remove_group_transaction')]));$_POST['group_reuse']='';}
            if($move==='group-clear'){$groupIds=[];$_POST['group_reuse']='';}
            if($move==='group-reuse'){$saved=(new \Kulturhus\Verein\BankPaymentGroups($db,(string)$_SESSION['actor']))->group(input($_POST,'choose_payment_group'));$groupIds=array_column($saved['items'],'transaction_id');$_POST['group_reuse']=$saved['group']['id'];}
            $_POST['group_transactions']=$groupIds;
            $rawAmounts=$_POST['group_amounts']??[];if(!is_array($rawAmounts)||count($rawAmounts)>40)throw new DomainException('Bankteilbeträge erneut in ihrer ursprünglichen Auswahl prüfen.',422);$amounts=[];
            foreach($groupIds as$id){if(array_key_exists($id,$rawAmounts)){if(!is_string($rawAmounts[$id]))throw new DomainException('Jeden Bankteilbetrag als Eurobetrag prüfen.',422);$amounts[$id]=$rawAmounts[$id];}elseif($shareMode){$component=$finance->transactionDetail($id);$amounts[$id]=\Kulturhus\Verein\Finance::decimal($feeMode?(int)$component['bank_unproved_cents']:min((int)$component['bank_unproved_cents'],abs((int)$current['cents'])));}}
            if($shareMode&&$move==='group-reuse'){$amounts=[];foreach($saved['items']as$item)$amounts[$item['transaction_id']]=\Kulturhus\Verein\Finance::decimal(abs((int)$item['cents']));}$_POST['group_amounts']=$amounts;
            if($feeMode){$_POST['group_reuse']='';if($move==='fee-choose'){$_POST['fee_entry']=input($_POST,'choose_fee_entry');if(input($_POST,'fee_amount')==='')$_POST['fee_amount']=\Kulturhus\Verein\Finance::decimal((int)$finance->entry(input($_POST,'fee_entry'))['amount_cents']);}if($move==='fee-clear'){$_POST['fee_entry']='';$_POST['fee_amount']='';}if(in_array($move,['fee-choose','fee-clear'],true)){$_POST['group_hash']='';$_POST['group_confirm']='';}}
            if(in_array($move,['group-add','group-remove','group-clear','group-reuse','mode'],true)){$_POST['group_hash']='';$_POST['group_confirm']='';}
        }
        if(input($_POST,'correction_commit')==='1'){
            $kind=input($_POST,'correction_kind');
            if($feeMode&&$kind==='duplicate')$bankImportService->correctFeeDuplicate($record,(int)input($_POST,'record_version'),(int)input($_POST,'source_version'),$amounts,input($_POST,'fee_entry'),input($_POST,'fee_amount'),input($_POST,'correction_date'),input($_POST,'correction_reason'),input($_POST,'correction_key'),input($_POST,'group_hash'),input($_POST,'group_confirm')==='1');
            elseif($shareMode&&$kind==='duplicate')$bankImportService->correctSharesDuplicate($record,(int)input($_POST,'record_version'),(int)input($_POST,'source_version'),$amounts,input($_POST,'group_reuse'),input($_POST,'correction_date'),input($_POST,'correction_reason'),input($_POST,'correction_key'),input($_POST,'group_hash'),input($_POST,'group_confirm')==='1');
            elseif($groupMode&&$kind==='duplicate')$bankImportService->correctGroupDuplicate($record,(int)input($_POST,'record_version'),(int)input($_POST,'source_version'),$groupIds,input($_POST,'group_reuse'),input($_POST,'correction_date'),input($_POST,'correction_reason'),input($_POST,'correction_key'),input($_POST,'group_hash'),input($_POST,'group_confirm')==='1');
            else $bankImportService->correct($record,(int)input($_POST,'record_version'),$kind,$kind==='duplicate'?input($_POST,'transaction'):'',(int)input($_POST,'source_version'),$kind==='duplicate'?input($_POST,'correction_date'):'',input($_POST,'correction_reason'),input($_POST,'correction_key'),input($_POST,'correction_confirm')==='1');
            $_SESSION['notice']=$bankNotice('Bankkorrektur mit ursprünglichem Nachweis gespeichert. Den aktuellen Stand und Verlauf prüfen.');
            redirectTo(bankImportContext($_GET));
        }
        if(input($_POST,'review_commit')==='1'){
            if(input($_POST,'decision')==='new'&&input($_POST,'new_confirm')!=='1')throw new DomainException('Eine zusätzliche tatsächlich erfolgte Zahlung ausdrücklich bestätigen.',422);
            if(input($_POST,'decision')==='fee')$bankImportService->decideFee($record,(int)input($_POST,'record_version'),$amounts,input($_POST,'fee_entry'),input($_POST,'fee_amount'),input($_POST,'reason'),input($_POST,'group_hash'),input($_POST,'group_confirm')==='1');
            elseif(input($_POST,'decision')==='shares')$bankImportService->decideShares($record,(int)input($_POST,'record_version'),$amounts,input($_POST,'reason'),input($_POST,'group_reuse'),input($_POST,'group_hash'),input($_POST,'group_confirm')==='1');
            elseif(input($_POST,'decision')==='group')$bankImportService->decideGroup($record,(int)input($_POST,'record_version'),$groupIds,input($_POST,'reason'),input($_POST,'group_reuse'),input($_POST,'group_hash'),input($_POST,'group_confirm')==='1');
            else $bankImportService->decide($record,(int)input($_POST,'record_version'),input($_POST,'decision'),input($_POST,'transaction'),input($_POST,'reason'));
            $_SESSION['notice']=$bankNotice('Ursprüngliche Entscheidung zum Bankumsatz gespeichert. Den aktuellen Stand unten prüfen.');
            redirectTo(bankImportContext($_GET));
        }
        if(!in_array($move,['refresh','search','candidate-page','observations-page','decisions-page','corrections-page','clear','choose','mode','group-add','group-remove','group-clear','group-reuse','group-preview','group-search','group-page','group-reuse-page','group-history-page','fee-search','fee-page','fee-choose','fee-clear'],true)){
            $bankWorkflowError='Diese Bankangaben wurden noch nicht gespeichert. Auswahl und Original prüfen und danach gesondert bestätigen.';return;
        }
        if($move==='refresh'){$bankImportReviewed=true;$_POST['group_confirm']='';}
        if($move==='group-search')$_POST['group_page']='1';
        if($move==='fee-search')$_POST['fee_page']='1';
        if($move==='group-preview'){
            if(!$groupMode)throw new DomainException('Zuerst den Prüfweg für mehrere vorhandene Teilbewegungen auswählen.',422);
            $duplicate=input($_POST,'correction_kind')==='duplicate'?($bankImportService->reviewDetail($record)['transaction']['id']??''):'';if(input($_POST,'correction_kind')==='duplicate'&&$duplicate==='')throw new DomainException('Zuerst den gespeicherten ursprünglichen Sammelbankausgang prüfen.',409);
            $bankGroupPrepared=$feeMode?$bankImportService->feePreview($record,$amounts,input($_POST,'fee_entry'),input($_POST,'fee_amount'),input($_POST,$duplicate!==''?'correction_reason':'reason'),$duplicate):($shareMode?$bankImportService->sharePreview($record,$amounts,input($_POST,$duplicate!==''?'correction_reason':'reason'),input($_POST,'group_reuse'),$duplicate):$bankImportService->groupPreview($record,$groupIds,input($_POST,$duplicate!==''?'correction_reason':'reason'),input($_POST,'group_reuse'),$duplicate));$_POST['group_hash']=$bankGroupPrepared['hash'];$_POST['group_confirm']='';$bankGroupReviewed=true;
        }
        if($move==='search')$_POST['candidate_page']='1';
        if($move==='clear')$_POST['transaction']='';
        if($move==='choose')$_POST['transaction']=input($_POST,'choose_transaction');
    }
}catch(Throwable $failure){
    $bankWorkflowError=$failure instanceof DomainException?$failure->getMessage():'Bankübernahme nicht bestätigt. Den gespeicherten Prüfstand zuerst lesen.';
    http_response_code($failure instanceof DomainException&&in_array($failure->getCode(),[403,404,409,503],true)?$failure->getCode():($failure instanceof DomainException?422:503));
    $bankWorkflowError=$bankNotice($bankWorkflowError);
    if(input($_GET,'import_original')!==''||input($_GET,'import_correction')!==''||input($_GET,'import_group_original')!==''){header('Content-Type: text/plain; charset=UTF-8');header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');echo $bankWorkflowError;exit;}
}
