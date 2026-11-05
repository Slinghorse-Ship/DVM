<?php declare(strict_types=1);
use Kulturhus\Verein\Web\{MemberImportSession,MemberExcelExportSession};
if(!$registry->canWrite())throw new DomainException('Mitglieder-Schreibrecht erforderlich.',403);
if(($_POST['action']??null)!==$action)throw new DomainException('Importaktion vollständig prüfen.',422);
if(in_array($action,MemberExcelExportSession::ACTIONS,true)){
    if($action==='member-excel-bank-download'){
        $bytes=MemberExcelExportSession::download($db,(string)$_SESSION['actor'],$_GET,$_POST,$_SESSION);header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');header('Content-Disposition: attachment; filename="DVM-Mitglieder-mit-Bankdaten.xlsx"');header('Cache-Control: no-store, private');header('Content-Length: '.strlen($bytes));echo$bytes;exit;
    }
    if($action==='member-excel-bank-cancel'){MemberExcelExportSession::close($db,(string)$_SESSION['actor'],$_GET,$_POST,$_SESSION);redirectTo(['view'=>'members','import'=>'1']);}
    MemberExcelExportSession::confirm($db,(string)$_SESSION['actor'],$_GET,$_POST,$_SESSION);redirectTo(['view'=>'members','import'=>'1','bank_export_id'=>$_POST['bank_export_id']]);
}
MemberImportSession::source($_GET,$_POST);
if($action==='member-excel-export'){
    if(!is_string($_POST['member_scope']??null)||!is_string($_POST['member_search']??null))throw new DomainException('Mitgliederauswahl für Excel vollständig prüfen.',422);
    if(array_key_exists('member_include_bank',$_POST)){if($_POST['member_include_bank']!=='1')throw new DomainException('Bankexportauswahl vollständig prüfen.',422);$id=MemberExcelExportSession::create($db,(string)$_SESSION['actor'],$_POST['member_scope'],$_POST['member_search'],$_SESSION);redirectTo(['view'=>'members','import'=>'1','bank_export_id'=>$id]);}
    $bytes=$registry->exportMemberWorkbook($_POST['member_scope'],$_POST['member_search']);header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');header('Content-Disposition: attachment; filename="DVM-Mitglieder.xlsx"');header('Content-Length: '.strlen($bytes));echo$bytes;exit;
}
if($action==='member-import-upload'){
    $file=$_FILES['member_file']??null;if(!is_array($file)||($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK||!is_string($file['tmp_name']??null)||!is_string($file['name']??null)||!\Kulturhus\Verein\LiveUploads::file($file['tmp_name']))throw new DomainException('Bitte XLSX oder CSV bis 5 MB auswählen.',422);
    $book=\Kulturhus\Verein\MemberImportReader::read($file['name'],\Kulturhus\Verein\LiveUploads::bytes($file['tmp_name'],5242880));$id=MemberImportSession::create($db,(string)$_SESSION['actor'],$book,basename($file['name']),$_SESSION);redirectTo(['view'=>'members','import'=>'1','import_id'=>$id]);
}
if($action==='member-import-preview'){MemberImportSession::preview($db,(string)$_SESSION['actor'],$_POST,$_SESSION);redirectTo(['view'=>'members','import'=>'1','import_id'=>$_POST['import_id'],'preview'=>'1']);}
if($action==='member-import-discard'){MemberImportSession::discard($db,(string)$_SESSION['actor'],$_POST['import_id']??null,$_SESSION);redirectTo(['view'=>'members','import'=>'1']);}
if(in_array($action,['member-import-bank-begin','member-import-bank-confirm'],true)){MemberImportSession::bankConfirm($db,(string)$_SESSION['actor'],$_POST,$_SESSION);redirectTo(['view'=>'members','import'=>'1','import_id'=>$_POST['import_id'],'preview'=>'1']);}
if($action==='member-import-apply'){$receipt=MemberImportSession::apply($db,(string)$_SESSION['actor'],$_POST,$_SESSION);redirectTo(['view'=>'members','import'=>'1','import_result'=>$receipt['id']]);}
throw new DomainException('Importaktion vollständig prüfen.',422);
