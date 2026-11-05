<?php declare(strict_types=1);
/** Actual issued source producers, on the existing isolated MySQL regression server. */
require __DIR__.'/donation-receipts.php';
require_once __DIR__.'/../src/Finance/Donations/Cover/CoverSource.php';
require_once __DIR__.'/../src/Finance/Donations/Cover/CoverContext.php';
require_once __DIR__.'/../src/Finance/Donations/Cover/CoverInput.php';
require_once __DIR__.'/../src/Finance/Donations/Cover/CoverText.php';
require_once __DIR__.'/../src/Finance/Donations/Cover/CoverRequests.php';

use Kulturhus\Verein\Finance\Donations\Cover\CoverSource;
use Kulturhus\Verein\Finance\Donations\Cover\CoverContext;
use Kulturhus\Verein\Finance\Donations\Cover\{CoverInput, CoverText};
use Kulturhus\Verein\Finance\Donations\Cover\CoverRequests;
use Kulturhus\Verein\{DonationCollections, Registry, SettingsSchema};

$coverStart = $checks;
$replacementFile = $docs->file($replacement, 1);
$replacementSnapshot = json_decode($replacementFile['snapshot'], true, 64, JSON_THROW_ON_ERROR);
$goodsFile = $docs->file($goodsCertificate, 1);
$goodsSnapshot = json_decode($goodsFile['snapshot'], true, 64, JSON_THROW_ON_ERROR);
$coverDenied = static function (callable $work, int $code, string $label): void {
    try { $work(); } catch (DomainException $failure) {
        check($failure->getCode() === $code, $label);
        return;
    }
    throw new RuntimeException('Not denied: '.$label);
};
$coverRows = static function () use ($db): array {
    $out = [];
    $tables = $db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    sort($tables);
    foreach ($tables as $table) {
        if (!preg_match('/\A[a-z0-9_]+\z/D', $table)) throw new RuntimeException('Owned table name required.');
        $rows = array_map('serialize', $db->query('SELECT * FROM `'.$table.'`')->fetchAll(PDO::FETCH_NUM));
        sort($rows);
        $out[$table] = $rows;
    }
    return $out;
};

$beforeCoverReads = $coverRows();
$moneySource = CoverSource::read($db, 'reader', $replacementSnapshot['receipt_number']);
check($moneySource['template'] === 'donation' && $moneySource['amount_cents'] === 12550
    && $moneySource['donor'] === $replacementSnapshot['donation_form']['donor']
    && $moneySource['file']['sha256'] === $replacementFile['sha256'],
    'Finance-only reader obtains exact original issued money-certificate donor amount and PDF without general or member rights');
$goodsSource = CoverSource::read($db, 'reader', $goodsSnapshot['receipt_number']);
check($goodsSource['template'] === 'donation' && $goodsSource['amount_cents'] === 8005
    && $goodsSource['donor'] === $goodsSnapshot['donation_form']['donor']
    && $goodsSource['file']['sha256'] === $goodsFile['sha256'],
    'Actual goods-certificate cover source retains its genuine original donor and exact valuation');
$coverDenied(fn() => CoverSource::read($db, 'reader', $snap['receipt_number']), 409, 'Cancelled original cannot become a new cover source despite its retained exact PDF');
$coverDenied(fn() => CoverSource::read($db, 'foreign', $replacementSnapshot['receipt_number']), 404, 'Known foreign-club certificate never substitutes an own original');
$coverDenied(fn() => CoverSource::read($db, 'member', $replacementSnapshot['receipt_number']), 403, 'Certificate cover source still requires current financial authority');
check($beforeCoverReads === $coverRows(), 'Every entire earlier business row survives all original cover reads and refusals');
$db->beginTransaction();
CoverSource::read($db, 'reader', $replacementSnapshot['receipt_number']);
check($db->inTransaction() && $beforeCoverReads === $coverRows(), 'Actual source reader preserves the caller transaction and every whole earlier row');
$db->rollBack();

$coverRole = $admin->saveRole('', 0, ['name'=>'Own cover source producer', 'members_access'=>'read', 'finance_access'=>'write', 'settings_access'=>false, 'inventory_access'=>'none', 'loans_access'=>'none', 'documents_access'=>'none']);
$admin->assignRole('writer', $coverRole, 0);
$coverDonor = (new Registry($db, 'manager'))->saveContact(null, 0, 'person', 'Own collective cover donor', 'cover@example.invalid', "Own test street 7\n12345 Own test city");
$coverPayments = [];
foreach (['25,01', '40,02'] as $index=>$amount) {
    $coverEntry = $finance->createEntry(array_replace($moneyData, ['amount'=>$amount]), $key());
    $finance->assignDonationContact('entry', $coverEntry, 1, $coverDonor);
    $finance->recordPayment($coverEntry, 2, $paidAccount, '2026-08-0'.($index+1));
    $coverPayments[] = $finance->allocations($coverEntry)[0]['id'];
}
$coverCollections = new DonationCollections($db, 'writer');
$coverInput = ['contact_id'=>$coverDonor, 'from'=>'2026-01-01', 'to'=>'2026-08-31', 'payment_ids'=>$coverPayments, 'waiver_ids'=>[], 'reviewed'=>'1'];
$coverCollectionPreview = $coverCollections->build($coverInput);
$coverCollectionDocument = $coverCollections->issue($coverInput, $key(), hash('sha256', json_encode($coverCollectionPreview, JSON_THROW_ON_ERROR)));
$coverCollectionFile = $docs->file($coverCollectionDocument, 1);
$coverCollectionSnapshot = json_decode($coverCollectionFile['snapshot'], true, 64, JSON_THROW_ON_ERROR);
$beforeCollectionRead = $coverRows();
$collectionSource = CoverSource::read($db, 'reader', $coverCollectionSnapshot['receipt_number']);
check($collectionSource['template'] === 'collective' && $collectionSource['amount_cents'] === 6503
    && $collectionSource['donor'] === $coverCollectionSnapshot['collection']['donor']
    && count($collectionSource['sources']) === 2 && $collectionSource['file']['sha256'] === $coverCollectionFile['sha256'],
    'Actual canonical collective certificate selects the collective cover template and its exact two original payments');
check($beforeCollectionRead === $coverRows(), 'Complete original source PDF snapshots claims and actual money records remain unchanged by collective cover reading');

$coverTemplates = SettingsSchema::templates('texts_donations');
$coverTemplates['donation'] = array_replace($coverTemplates['donation'], ['subject'=>'Eigener Dank {{ZAHLBETRAG}}', 'intro'=>'Eigene Einleitung 0 🙂', 'message'=>'{{BRIEFANREDE}}\n{{VEREINSNAME}} dankt für {{ZAHLBETRAG}}.', 'closing'=>'Eigener ganzer Schluss 0']);
$coverTemplates['collective'] = array_replace($coverTemplates['collective'], ['subject'=>'Eigener Sammeldank {{ZAHLBETRAG}}']);
$coverTemplates['donation']['message'] = "{{BRIEFANREDE}}\n{{VEREINSNAME}} dankt für {{ZAHLBETRAG}}.";
$admin->save('texts_donations', $coverTemplates, 0);
$beforeContextReads = $coverRows();
$moneyContext = CoverContext::read($db, 'reader', $replacementSnapshot['receipt_number'], '2026-10-22');
check(!(new \Kulturhus\Verein\Settings($db, 'reader'))->canManage()
    && $moneyContext['template']['subject'] === 'Eigener Dank {{ZAHLBETRAG}}'
    && $moneyContext['template']['intro'] === 'Eigene Einleitung 0 🙂'
    && $moneyContext['template']['closing'] === 'Eigener ganzer Schluss 0'
    && $moneyContext['values']['ZAHLBETRAG'] === '125,50 €'
    && $moneyContext['paper']['sender_name'] === $club['name'],
    'Actual approved single-cover template and real amount and sender are consumed with Finance-only rights rather than sample document values');
$collectionContext = CoverContext::read($db, 'reader', $coverCollectionSnapshot['receipt_number'], '2026-10-22');
check($collectionContext['template']['subject'] === 'Eigener Sammeldank {{ZAHLBETRAG}}'
    && $collectionContext['values']['ZAHLBETRAG'] === '65,03 €'
    && !array_key_exists('VORNAME', $collectionContext['values'])
    && !array_key_exists('RECHNUNGSNUMMER', $collectionContext['values']),
    'Actual collective-cover context uses its exact original sum without invented personal names or invoice number');
$coverDenied(fn() => CoverContext::read($db, 'reader', $replacementSnapshot['receipt_number'], "2026-10-22\0"), 422, 'Whole invalid cover date is rejected without a truncated replacement');
check($beforeContextReads === $coverRows(), 'Real template and letterhead context reads preserve every whole original business row');
(new Registry($db, 'manager'))->saveContact($coverDonor, 1, 'person', 'Own later changed donor', 'later@example.invalid', "Later test street 9\n23456 Later test city");
$beforeLaterContext = $coverRows();
$laterContext = CoverContext::read($db, 'reader', $coverCollectionSnapshot['receipt_number'], '2026-10-22');
check($laterContext['certificate']['donor'] === $coverCollectionSnapshot['collection']['donor']
    && $laterContext['values']['BRIEFANREDE'] === 'Guten Tag Own collective cover donor,'
    && $docs->file($coverCollectionDocument, 1)['sha256'] === $coverCollectionFile['sha256']
    && $beforeLaterContext === $coverRows(),
    'Actual later donor rename never rewrites the certified donor or original PDF used by a new cover');

$pdfText = static function (string $bytes): string {
    $process = proc_open(['/usr/bin/pdftotext', '-layout', '-', '-'], [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('Owned PDF text extraction unavailable.');
    fwrite($pipes[0], $bytes);
    fclose($pipes[0]);
    $text = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    if (proc_close($process) !== 0 || $error !== '') throw new RuntimeException('Owned cover PDF extraction failed.');
    return preg_replace('/\s+/u', ' ', $text);
};
$beforeActualCoverPdfs = $coverRows();
$wholeCover = CoverInput::initial($moneyContext);
$wholeCover['reason'] = "\n0\r\nOwn complete cover reason 🙂";
$wholeCover['text']['message'] = "\n0 <b>whole literal cover text</b> 🙂\r\n".$wholeCover['text']['message'];
check(CoverInput::normalize($wholeCover) === $wholeCover, 'Complete entered cover fields retain leading LF literal zero Unicode markup and original CRLF');
$actualCoverDocument = CoverText::document($moneyContext, $wholeCover);
$actualCoverPdf = \Kulturhus\Verein\DocumentPreview::pdf($actualCoverDocument);
$actualCoverText = $pdfText($actualCoverPdf);
check(str_starts_with($actualCoverPdf, '%PDF-') && str_contains($actualCoverText, '125,50')
    && str_contains($actualCoverText, 'Eigene Einleitung 0') && str_contains($actualCoverText, 'Eigener ganzer Schluss 0')
    && str_contains($actualCoverText, '<b>whole literal cover text</b>')
    && str_contains($actualCoverText, 'Alex Muster'),
    'Actual rendered single cover PDF contains the genuine amount and original donor with whole intro closing and escaped literal markup');
$actualCollectiveInput = CoverInput::initial($laterContext);
$actualCollectiveInput['reason'] = 'Own actual collective cover PDF review';
$actualCollectivePdf = \Kulturhus\Verein\DocumentPreview::pdf(CoverText::document($laterContext, $actualCollectiveInput));
$actualCollectiveText = $pdfText($actualCollectivePdf);
check(str_contains($actualCollectiveText, '65,03') && str_contains($actualCollectiveText, 'Own collective cover donor')
    && !str_contains($actualCollectiveText, 'Own later changed donor') && !str_contains($actualCollectiveText, '120,00'),
    'Actual rendered collective cover PDF uses its certified total and original address rather than later donor data or sample amount');
$missingCoverValue = $wholeCover;
$missingCoverValue['text']['message'] = '{{VORNAME}}';
$coverDenied(fn() => CoverText::document($moneyContext, $missingCoverValue), 422, 'Missing original personal first name cannot be silently invented for a real cover');
$overlongCover = $wholeCover;
$overlongCover['text']['message'] = "\n0".str_repeat('🙂', 6001);
check(CoverInput::capture($overlongCover) === $overlongCover, 'Whole overlong Unicode cover input remains available without clipping');
$coverDenied(fn() => CoverInput::normalize($overlongCover), 422, 'Whole overlong real cover text is explicitly rejected');
check($beforeActualCoverPdfs === $coverRows()
    && $docs->file($replacement, 1)['sha256'] === $replacementFile['sha256']
    && $docs->file($goodsCertificate, 1)['sha256'] === $goodsFile['sha256']
    && $docs->file($coverCollectionDocument, 1)['sha256'] === $coverCollectionFile['sha256'],
    'Actual cover PDF production and all invalid previews preserve every earlier business row and all three complete issued original PDFs');

$coverDocumentData = ['title'=>'Own actual donation cover', 'kind'=>'record', 'scope'=>'finance', 'document_date'=>'2026-10-22', 'links'=>[], 'notes'=>'Own cover of '.$replacementSnapshot['receipt_number']];
$coverDocumentSnapshot = ['kind'=>'donation-cover', 'source_document'=>$replacement, 'receipt_id'=>$replacementSnapshot['receipt_number'], 'rendered'=>$actualCoverDocument];
$writerDocuments = new \Kulturhus\Verein\Documents($db, 'writer');
$readerDocuments = new \Kulturhus\Verein\Documents($db, 'reader');
$coverDenied(fn() => $readerDocuments->generatedForFinancialOriginal($replacement, 'own-cover.pdf', $actualCoverPdf, $coverDocumentData, $key(), $coverDocumentSnapshot), 403, 'Actual financial source read never grants canonical cover creation without finance writing');
$beforeCoverStore = $coverRows();
$db->beginTransaction();
$rolledCover = $writerDocuments->generatedForFinancialOriginal($replacement, 'own-cover.pdf', $actualCoverPdf, $coverDocumentData, $key(), $coverDocumentSnapshot);
check($db->inTransaction() && $writerDocuments->file($rolledCover, 1)['bytes'] === $actualCoverPdf,
    'Actual cover canonical storage uses the exact reviewed PDF within the caller transaction without general settings');
$db->rollBack();
check($beforeCoverStore === $coverRows(), 'Actual caller rollback removes the entire derived cover document file request and audit and retains every older row');
try {
    $writerDocuments->generatedForFinancialOriginal($replacement, 'own-cover.pdf', $actualCoverPdf, $coverDocumentData, $key(), $coverDocumentSnapshot, null, static function (string $document): void { throw new RuntimeException('Own failure after canonical cover audit'); });
    throw new LogicException('Owned after-store failure not reached.');
} catch (RuntimeException $failure) {
    if ($failure->getMessage() !== 'Own failure after canonical cover audit') throw $failure;
    check($beforeCoverStore === $coverRows(), 'Actual failure after canonical cover and audit rolls back all new output rows without altering its original');
}

$coverManagerRole = $admin->saveRole('', 0, ['name'=>'Own inherited original protection producer', 'members_access'=>'write', 'finance_access'=>'write', 'settings_access'=>true, 'inventory_access'=>'none', 'loans_access'=>'none', 'documents_access'=>'write']);
$admin->assignRole('manager', $coverManagerRole, 0);
$managerDocuments = new \Kulturhus\Verein\Documents($db, 'manager');
$protectedSource = $managerDocuments->upload('own-financial-source.pdf', $actualCoverPdf, ['title'=>'Own finance and member source', 'kind'=>'receipt', 'document_date'=>'2026-10-22', 'links'=>['entry:'.$entry, 'contact:'.$coverDonor]], $key());
$protectedOriginal = $managerDocuments->document($protectedSource);
check(in_array('finance', $protectedOriginal['scopes'], true) && in_array('members', $protectedOriginal['scopes'], true), 'Actual source producer establishes both independent financial and member restrictions');
$protectedCover = $writerDocuments->generatedForFinancialOriginal($protectedSource, 'own-inherited-cover.pdf', $actualCoverPdf, $coverDocumentData, $key(), array_replace($coverDocumentSnapshot, ['source_document'=>$protectedSource]));
$protectedOutput = $writerDocuments->document($protectedCover);
check($protectedOutput['scopes'] === $protectedOriginal['scopes'] && $protectedOutput['folder_id'] === $protectedOriginal['folder_id'], 'Actual derived financial PDF retains every source restriction and the actual source folder');
denied(fn() => $readerDocuments->file($protectedCover, 1), 'Finance-only reader cannot open a derived original that additionally needs member access');
$openFinanceFolder = $managerDocuments->saveFolder('', 0, 'Own later open financial folder', '', 'finance');
$managerDocuments->update($protectedCover, 1, array_replace($coverDocumentData, ['folder'=>$openFinanceFolder, 'reason'=>'Own deliberate later relocation']));
$movedOutput = $managerDocuments->document($protectedCover);
check($movedOutput['scopes'] === $protectedOriginal['scopes'] && $movedOutput['folder_id'] === $openFinanceFolder
    && $managerDocuments->file($protectedCover, 1)['bytes'] === $actualCoverPdf,
    'Actual later move to a less restricted folder never strips inherited source rights or changes original derived PDF bytes');
denied(fn() => $readerDocuments->file($protectedCover, 1), 'Current inherited member protection still applies after the actual derived-file move');

if (getenv('VEREIN_TEST_MYSQL') !== 'isolated-regression-only' || getenv('VEREIN_TEST_MYSQL_PORT') !== '13372') throw new RuntimeException('Owned regression ledger required.');
\Kulturhus\Verein\Settings\GeneralRequestSchema::install($db);
$nativeCover = new CoverRequests($db, 'writer');
$nativeCoverInput = $nativeCover->initial($replacementSnapshot['receipt_number'], '2026-10-22');
$nativeCoverInput['reason'] = "\n0 Own entire private cover reason 🙂";
$beforeNativeReview = $coverRows();
$nativeCoverReview = $nativeCover->prepare($nativeCoverInput);
check($beforeNativeReview === $coverRows(), 'Actual native cover review creates no document payment private first or source change');
$coverDenied(fn() => $nativeCover->accept($nativeCoverInput, $key(), $nativeCoverReview['hash'], false), 422, 'Actual native cover requires its own separate conscious consent');
$nativeCoverKey = $key();
$db->beginTransaction();
$rolledNativeCover = $nativeCover->accept($nativeCoverInput, $nativeCoverKey, $nativeCoverReview['hash'], true);
check($db->inTransaction() && $rolledNativeCover['input'] === $nativeCoverInput
    && hash('sha256', $nativeCover->pdf($nativeCoverKey)) === $rolledNativeCover['result']['sha256'],
    'Actual canonical cover and private whole-input first are available atomically inside the caller transaction');
$db->rollBack();
check($beforeNativeReview === $coverRows(), 'Actual caller rollback removes canonical cover and personal first together while retaining every complete older row');
$db->exec("CREATE TRIGGER own_cover_first_failure BEFORE INSERT ON native_settings_requests FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Own failure after cover PDF and audits'; END");
try {
    $nativeCover->accept($nativeCoverInput, $nativeCoverKey, $nativeCoverReview['hash'], true);
    throw new RuntimeException('Owned native first failure not raised.');
} catch (PDOException) {
    check($beforeNativeReview === $coverRows(), 'Actual SQL failure after canonical PDF and audits rolls back the whole native cover decision');
} finally { $db->exec('DROP TRIGGER own_cover_first_failure'); }
$nativeFirst = $nativeCover->accept($nativeCoverInput, $nativeCoverKey, $nativeCoverReview['hash'], true);
$nativeFirstPdf = $nativeCover->pdf($nativeCoverKey);
check($nativeFirst['input'] === $nativeCoverInput && $nativeFirst['review']['source']['certificate']['file']['sha256'] === $replacementFile['sha256']
    && hash('sha256', $nativeFirstPdf) === $nativeFirst['result']['sha256']
    && $nativeCover->receipt($nativeCoverKey)['original_json'] === $nativeFirst['original_json'],
    'Actual committed cover binds its complete original input source certificate PDF and byte-exact generated PDF and personal JSON');
$beforeNativeReplay = $coverRows();
check($nativeCover->accept($nativeCoverInput, $nativeCoverKey, $nativeCoverReview['hash'], true) === $nativeFirst
    && $beforeNativeReplay === $coverRows(), 'Known native cover key reads its exact first without another output document request or audit');
$changedNativeCover = $nativeCoverInput;
$changedNativeCover['reason'] .= ' changed';
$coverDenied(fn() => $nativeCover->accept($changedNativeCover, $nativeCoverKey, $nativeCoverReview['hash'], true), 409, 'An accepted native cover key cannot become another whole personal intention');
$coverDenied(fn() => (new CoverRequests($db, 'reader'))->receipt($nativeCoverKey), 404, 'Another financial reader cannot inherit the known personal cover JSON');
$coverDenied(fn() => (new CoverRequests($db, 'foreign'))->receipt($nativeCoverKey), 404, 'Another club cannot inherit the known personal cover original');
$coverTemplates['donation']['subject'] = 'Own later approved cover template';
$admin->save('texts_donations', $coverTemplates, 1);
$beforeLaterSourceReplay = $coverRows();
check($nativeCover->accept($nativeCoverInput, $nativeCoverKey, $nativeCoverReview['hash'], true)['original_json'] === $nativeFirst['original_json']
    && $nativeCover->pdf($nativeCoverKey) === $nativeFirstPdf && $beforeLaterSourceReplay === $coverRows(),
    'Actual later template change never rewrites the accepted cover first or its original generated PDF');
$coverDenied(fn() => $nativeCover->accept($nativeCoverInput, $key(), $nativeCoverReview['hash'], true), 409, 'New cover acceptance cannot reuse a review predating the actual approved template change');
$admin->assignRole('writer', $role, 1);
$beforeReadOnlyReplay = $coverRows();
check($nativeCover->receipt($nativeCoverKey)['original_json'] === $nativeFirst['original_json']
    && $nativeCover->accept($nativeCoverInput, $nativeCoverKey, $nativeCoverReview['hash'], true)['original_json'] === $nativeFirst['original_json']
    && $nativeCover->pdf($nativeCoverKey) === $nativeFirstPdf && $beforeReadOnlyReplay === $coverRows(),
    'Actual later Finance-read-only author still retrieves and replays the exact first cover without new writing');
$coverDenied(fn() => $nativeCover->prepare($nativeCoverInput), 403, 'Read-only original access never grants a new native cover review or creation');

final class OwnCoverLostCommitPDO extends PDO
{
    public string $targetKey = '';
    public bool $armed = false;
    public function commit(): bool
    {
        $lose = false;
        if ($this->armed) {
            $query = $this->prepare('SELECT 1 FROM native_settings_requests WHERE request_key=?');
            $query->execute([$this->targetKey]);
            $lose = (bool)$query->fetchColumn();
        }
        $done = parent::commit();
        if ($lose) { $this->armed = false; throw new RuntimeException('Own committed cover acknowledgement lost.'); }
        return $done;
    }
}
$admin->assignRole('writer', $coverRole, 2);
$lostCoverInput = $nativeCover->initial($replacementSnapshot['receipt_number'], '2026-10-22');
$lostCoverInput['reason'] = "Own actual lost acknowledgement\n0";
$lostCoverReview = $nativeCover->prepare($lostCoverInput);
$lostCoverKey = $key();
$beforeLostCover = $coverRows();
$coverDsn = \Kulturhus\Verein\Tests\MySqlFixture::dsn($db);
$lostCoverDb = new OwnCoverLostCommitPDO($coverDsn, 'root', 'isolated-regression-only', [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES=>false]);
$lostCoverDb->targetKey = $lostCoverKey;
$lostCoverDb->armed = true;
$lostCoverRequests = new CoverRequests($lostCoverDb, 'writer');
$lostCoverFailure = false;
try { $lostCoverRequests->accept($lostCoverInput, $lostCoverKey, $lostCoverReview['hash'], true); }
catch (RuntimeException $failure) { if ($failure->getMessage() !== 'Own committed cover acknowledgement lost.') throw $failure; $lostCoverFailure = true; }
$lostCoverFirst = $nativeCover->receipt($lostCoverKey);
$afterLostCover = $coverRows();
$expectedCoverDelta = ['documents'=>1, 'document_files'=>1, 'document_requests'=>1, 'document_history'=>1, 'native_settings_requests'=>1, 'audit_events'=>2];
$lostCoverExact = true;
foreach ($beforeLostCover as $table=>$oldRows) {
    if (array_diff($oldRows, $afterLostCover[$table]) || count($afterLostCover[$table]) !== count($oldRows)+($expectedCoverDelta[$table] ?? 0)) $lostCoverExact = false;
}
if (!$lostCoverExact || !$lostCoverFailure || $lostCoverDb->inTransaction()) {
    $deltas = [];
    foreach ($beforeLostCover as $table=>$oldRows) if ($oldRows !== $afterLostCover[$table]) $deltas[$table] = ['added'=>count($afterLostCover[$table])-count($oldRows), 'all_old_rows_exact'=>array_diff($oldRows, $afterLostCover[$table]) === []];
    echo json_encode(['owned_commit_diagnostic'=>true, 'fault_observed'=>$lostCoverFailure, 'transaction_open'=>$lostCoverDb->inTransaction(), 'deltas'=>$deltas], JSON_THROW_ON_ERROR)."\n";
}
check($lostCoverFailure && !$lostCoverDb->inTransaction() && $lostCoverExact
    && $lostCoverRequests->accept($lostCoverInput, $lostCoverKey, $lostCoverReview['hash'], true) === $lostCoverFirst
    && $lostCoverRequests->pdf($lostCoverKey) === $nativeCover->pdf($lostCoverKey) && $afterLostCover === $coverRows(),
    'Actual lost COMMIT acknowledgement after the persisted personal first retains exactly one canonical cover and byte-exact replay with all older rows unchanged');
$coverLockDb = \Kulturhus\Verein\Tests\MySqlFixture::existing($coverDsn);
$coverLockDb->beginTransaction();
$coverLockDb->query("SELECT id FROM clubs WHERE id='a' FOR UPDATE NOWAIT")->fetchColumn();
try {
    $coverDenied(fn() => $nativeCover->accept($lostCoverInput, $key(), $lostCoverReview['hash'], true), 503, 'Actual independent club writer causes immediate retryable refusal without an unconfirmed cover');
    check($afterLostCover === $coverRows(), 'Independent-writer refusal retains every whole earlier cover original source and money row');
} finally { $coverLockDb->rollBack(); }

$beforeCoverHistory = $coverRows();
$historyCoverFirsts = [];
for ($historyIndex = 0; $historyIndex < 26; ++$historyIndex) {
    $historyInput = $nativeCover->initial($replacementSnapshot['receipt_number'], '2026-10-22');
    $historyInput['reason'] = 'Own complete cover history 0 '.$historyIndex;
    $historyReview = $nativeCover->prepare($historyInput);
    $historyCoverFirsts[] = $nativeCover->accept($historyInput, $key(), $historyReview['hash'], true);
}
$coverHistoryOne = $nativeCover->history($replacementSnapshot['receipt_number'], ['query'=>'Own complete cover history 0', 'page'=>'1']);
$coverHistoryTwo = $nativeCover->history($replacementSnapshot['receipt_number'], ['query'=>'Own complete cover history 0', 'page'=>'2']);
check($coverHistoryOne['total'] === 26 && count($coverHistoryOne['rows']) === 25 && count($coverHistoryTwo['rows']) === 1
    && $coverHistoryTwo['rows'][0]['request_key'] === $historyCoverFirsts[0]['key'],
    'Twenty-six actual generated cover decisions have exact private twenty-five-plus-one history with their earliest original reachable');
$afterCoverHistory = $coverRows();
$historyDelta = ['documents'=>26, 'document_files'=>26, 'document_requests'=>26, 'document_history'=>26, 'native_settings_requests'=>26, 'audit_events'=>52];
$wholeEarlierCovers = true;
foreach ($beforeCoverHistory as $table=>$oldRows) {
    if (array_diff($oldRows, $afterCoverHistory[$table]) || count($afterCoverHistory[$table]) !== count($oldRows)+($historyDelta[$table] ?? 0)) $wholeEarlierCovers = false;
}
check($wholeEarlierCovers && $nativeCover->receipt($nativeCoverKey)['original_json'] === $nativeFirst['original_json']
    && $nativeCover->pdf($nativeCoverKey) === $nativeFirstPdf,
    'Real full cover history adds only its deliberate document and first rows while preserving every whole earlier row and money record');
$absentCoverPage = $nativeCover->history($replacementSnapshot['receipt_number'], ['query'=>'Own complete cover history 0', 'page'=>'999999']);
$invalidCoverQuery = "\n0 🙂".str_repeat('z', 201);
$invalidCoverPage = $nativeCover->history($replacementSnapshot['receipt_number'], ['query'=>$invalidCoverQuery, 'page'=>'0']);
check($absentCoverPage['error'] !== '' && $absentCoverPage['rows'] === [] && $absentCoverPage['page_input'] === '999999'
    && $invalidCoverPage['error'] !== '' && $invalidCoverPage['rows'] === [] && $invalidCoverPage['query'] === $invalidCoverQuery
    && $invalidCoverPage['page_input'] === '0' && $afterCoverHistory === $coverRows(),
    'Whole invalid or unavailable cover history retains every control and substitutes neither another page nor another personal original');
echo json_encode(['suite'=>'donation-cover-source', 'checks'=>$checks-$coverStart, 'original_source_pdfs'=>3, 'external_actions'=>0], JSON_THROW_ON_ERROR)."\n";
