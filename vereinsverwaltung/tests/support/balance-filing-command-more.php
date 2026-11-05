<?php declare(strict_types=1);
use Kulturhus\Verein\{BalanceFilingCommands,TaxFilings,BalanceTaxFiling,Documents};
$offline=$local+['library'=>__FILE__,'catalog'=>$catalogFile,'scratch'=>$root,'test_fixture_only'=>true,'balance_test_enabled'=>true,'balance_production_enabled'=>true,'production_enabled'=>true,'production_versions'=>['Bilanz_6_9']];
$offline['profiles']=['Bilanz_6_9'=>['family'=>'balance','test_only'=>false,'transport_enabled'=>true]];$writeConfig($offline);
$validationInput=['action'=>'tax-filing-validate','context'=>$command->workspace(2025,$id)['context'],'values'=>[]];
$db->exec("CREATE TRIGGER own_balance_validate_abort BEFORE INSERT ON balance_filing_commands FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Own actual validation receipt SQL abort'");
$before=ownOriginalDatabaseHash($db,$allTables);$caught=false;
try{$command->run($validationInput,bin2hex(random_bytes(16)));}catch(PDOException $e){$caught=(int)($e->errorInfo[1]??0)===1644;}
finally{$db->exec('DROP TRIGGER own_balance_validate_abort');}
$prove($caught&&ownOriginalDatabaseHash($db,$allTables)===$before,'actual validation followed by receipt SQL failure rolls back complete XML, validation and audit together');
$validated=$command->run($validationInput,bin2hex(random_bytes(16)));
$prove($validated['answer']['valid']===true&&$validated['result']['state']==='validated'&&$validated['result']['form_data']['balance_preparation']['native_runtime']['kind']==='synthetic-offline','only explicitly injected offline policy exercises validation and records its distinct proof');
$approveInput=['action'=>'tax-filing-balance-approve','context'=>$command->workspace(2025,$id)['context'],'values'=>['reviewed'=>'1']];
$bad=$approveInput;$bad['values']['reviewed']='0';$refuse(fn()=>$command->run($bad,bin2hex(random_bytes(16))),'board approval without its express review');
$financeCommand=new BalanceFilingCommands($db,'env-finance',$policy);
$nonBoard=['action'=>'tax-filing-balance-approve','context'=>$financeCommand->workspace(2025,$id)['context'],'values'=>['reviewed'=>'1']];
$refuse(fn()=>$financeCommand->run($nonBoard,bin2hex(random_bytes(16))),'current finance writer without board authority cannot approve');
$approved=$command->run($approveInput,bin2hex(random_bytes(16)));$readyTax=new TaxFilings($db,'env-board',$policy);
$prove($readyTax->ready($id,$approved['result']['version'],'SEND-PRODUCTION',true,false)['id']===$id,'exact local board approval readies only its bound original revision with the separate explicit production instruction');
$db->exec("UPDATE actors SET is_superuser=0 WHERE id='env-board'");
$refuse(fn()=>$readyTax->ready($id,$approved['result']['version'],'SEND-PRODUCTION',true,false),'actual withdrawal of original board authority invalidates transmission readiness');
$prove($command->run($approveInput,$approved['key'])===$approved,'historical accepted approval reads its exact first without granting board authority again');
$db->exec("UPDATE actors SET is_superuser=1 WHERE id='env-board'");
$adjust=['action'=>'tax-filing-balance-adjust','context'=>$command->workspace(2025,$id)['context'],'values'=>['adjust_period'=>'current_adjustments','adjust_reason'=>'0 Own original adjustment Übernahme <&>','adjust_debit'=>'expense','adjust_credit'=>'cash','adjust_amount'=>'1,23']];
$adjusted=$command->run($adjust,bin2hex(random_bytes(16)));
$prove($adjusted['result']['state']==='draft'&&$adjusted['result']['xml_data']===null&&!isset($adjusted['result']['form_data']['balance_preparation']['approval'])&&!$adjusted['result']['form_data']['balance_preparation']['review']['complete'],'actual local adjustment invalidates current XML, validation, overall review and board approval together');
$prove($command->receipt($approved['key'])===$approved&&$command->receipt($validated['key'])===$validated,'later actual adjustment leaves all first validation XML and board approval originals exact');
$revision=['action'=>'tax-filing-balance-revision','context'=>$command->workspace(2025,$id)['context'],'values'=>['revision_status'=>'E','revision_reason'=>'0 Own original first filing check Übernahme','revision_legal_basis'=>'','revision_source'=>'','revision_confirmed'=>'1']];
$revised=$command->run($revision,bin2hex(random_bytes(16)));
$prove($revised['result']['form_data']['balance_preparation']['filing_revision']['reason']===$revision['values']['revision_reason'],'actual submission-type review preserves every original zero and Unicode reason');
// A selection is bound to the PDF revision that existed when the form was opened.
$pdf=static function(string $text):string{
 $objects=['<< /Type /Catalog /Pages 2 0 R >>','<< /Type /Pages /Kids [3 0 R] /Count 1 >>','<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 5 0 R >> >> /Contents 4 0 R >>'];
 $stream='BT /F1 18 Tf 72 720 Td ('.$text.') Tj ET';$objects[]='<< /Length '.strlen($stream)." >>\nstream\n".$stream."\nendstream";$objects[]='<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';
 $bytes="%PDF-1.4\n";$offset=[];foreach($objects as $i=>$object){$offset[]=strlen($bytes);$bytes.=($i+1)." 0 obj\n".$object."\nendobj\n";}$xref=strlen($bytes);$bytes.="xref\n0 6\n0000000000 65535 f \n";foreach($offset as $value)$bytes.=sprintf("%010d 00000 n \n",$value);return $bytes."trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n".$xref."\n%%EOF\n";
};
$documents=new Documents($db,'env-board');
$doc=$documents->upload('Own evidence.pdf',$pdf('Own original evidence'),['title'=>'Own financial original source','kind'=>'other','scope'=>'finance','document_date'=>'2025-01-10'],bin2hex(random_bytes(16)));
$docBefore=$documents->document($doc);$stale=$command->workspace(2025,$id);
$documents->newVersion($doc,$docBefore['version'],'Own replaced evidence.pdf',$pdf('Own actual later evidence'),'Own actual later PDF revision');
$staleInput=['action'=>'tax-filing-balance-revision','context'=>$stale['context'],'values'=>$revision['values']];
$refuse(fn()=>$command->run($staleInput,bin2hex(random_bytes(16))),'actual later finance PDF revision cannot silently replace an opened source',409);
$prove($command->receipt($key)===$first&&$command->run($input,$key)===$first,'first accepted creation remains readable after actual later evidence and tax changes');
$runtimeBytes=file_get_contents($runtimeFile);unlink($runtimeFile);
try{$prove($command->run($validationInput,$validated['key'])===$validated&&$command->receipt($approved['key'])===$approved,'known accepted XML and approval originals are read before a missing current runtime configuration');}
finally{file_put_contents($runtimeFile,$runtimeBytes);chmod($runtimeFile,0600);}
$prove((int)$db->query('SELECT COUNT(*) FROM tax_filing_attempts')->fetchColumn()===0&&$policy->transmissions===0,'all local validation, approval, adjustment and revision cases leave actual transport unstarted');
