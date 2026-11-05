<?php declare(strict_types=1);
require __DIR__.'/einvoice-inspection.php';
use Kulturhus\Verein\{EInvoiceInspection,EInvoiceValidator,Finance,FinanceBundle,Documents};
$fixture=(string)getenv('KULTURHUS_EMBEDDED_TEST_PDF');
if(!$fixture){echo "SKIP real PDF worker: KULTURHUS_EMBEDDED_TEST_PDF required\n";return;}
$bytes=file_get_contents($fixture);$pdfid=$documents->upload('synthetic-embedded.pdf',$bytes,['title'=>'Synthetic embedded','kind'=>'receipt','scope'=>'finance','document_date'=>'2026-09-07','links'=>[]],bin2hex(random_bytes(16)));
$shared=\Kulturhus\Verein\InvoiceRecognition::extract($bytes,'application/pdf');check($shared['method']==='xml'&&$shared['source']==='embedded_xml','default recognition gives embedded XML priority');check(!isset($shared['xml_base64']),'default recognition never returns raw embedded payload');
$r=EInvoiceInspection::document($documents,$pdfid);check($r['state']==='recognized'&&$r['source']==='embedded_xml','real sandbox worker feeds structured PDF inspection');check($r['sha256']===hash('sha256',$bytes)&&$r['revision']===1,'embedded result bound to PDF original');check(!isset($r['xml_base64']),'XML bytes never leaked into view seed');
denied(fn()=>EInvoiceInspection::document(new Documents($db,'member'),$pdfid),'PDF inspection respects document ACL');
if(EInvoiceValidator::configured()){
 $validator=new EInvoiceValidator($db,'finance');$v=$validator->validate($pdfid,1,hash('sha256',$bytes));check($v['state']==='valid'&&str_contains($v['scenario'],'CII'),'embedded CII passes real EN16931 KoSIT');
 $entry=(new Finance($db,'finance'))->createEntry(['kind'=>'expense','description'=>'Synthetic PDF validation export','amount'=>'1.00','due_date'=>'2026-09-30','document_date'=>'2026-09-07','category'=>'culture'],bin2hex(random_bytes(16)));
 $doc=$documents->document($pdfid);$documents->update($pdfid,(int)$doc['version'],['title'=>$doc['title'],'kind'=>'receipt','document_date'=>'2026-09-07','scope'=>'finance','links'=>['entry:'.$entry],'reason'=>'Synthetic export']);
 $bundle=(new FinanceBundle($db,'finance'))->build([]);$path=tempnam(sys_get_temp_dir(),'kh-embedded-');file_put_contents($path,$bundle['bytes']);$zip=new ZipArchive();$zip->open($path);try{check($zip->getFromName('prueforiginale/'.hash('sha256',$bytes).'.pdf')===$bytes,'validated original stays exact PDF in financial bundle');}finally{$zip->close();unlink($path);}
 $documents->newVersion($pdfid,(int)$documents->document($pdfid)['version'],'synthetic-embedded.pdf',$bytes."\n",'Synthetic replacement');check(!$validator->latest($pdfid)['current'],'replacement PDF invalidates current validation');
 denied(fn()=>$validator->validate($pdfid,1,hash('sha256',$bytes)),'old PDF version cannot be revalidated as current');
}
echo "$checks combined embedded invoice checks passed\n";
