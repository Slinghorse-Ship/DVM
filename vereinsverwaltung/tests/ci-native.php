<?php declare(strict_types=1);
/** Explicit synthetic runner; never reads existing fixture or production configuration. */
if (PHP_SAPI !== 'cli' || (getenv('GITHUB_ACTIONS') !== 'true' && getenv('KH_NATIVE_CI') !== '1')) {
    fwrite(STDERR, "Nur ausdrücklich aktivierte synthetische CI-Laufzeit.\n");
    exit(1);
}
ini_set('zend.exception_ignore_args', '1');
// The explicit entry guard also authorises synthetic subprocesses in Actions.
// Nested crash tests deliberately require this flag rather than trusting CI alone.
putenv('KH_NATIVE_CI=1');
require dirname(__DIR__).'/native/bootstrap.php';

$suites = [
    'production-runtime', 'native-shared', 'native-cms', 'native-website-documents', 'native-image-orientation', 'native-photo-import', 'native-media-metadata', 'native-media-usage', 'native-media-policy', 'native-media-archive', 'native-media-edit', 'native-image-usage', 'native-image-attribution', 'native-image-history', 'native-media-quota', 'native-cms-model', 'native-cms-richtext-fields', 'native-field-conditions', 'native-field-groups', 'native-page-versions', 'native-album-versions', 'native-page-copies', 'native-tree-copies', 'native-page-hierarchy', 'native-page-trash', 'native-page-batches', 'native-cms-queries', 'native-query-budget', 'native-reference-search', 'native-page-media-picker', 'native-page-photo-upload', 'native-page-photo-status', 'native-upload-outcomes', 'native-richtext', 'native-richtext-adapters', 'native-path-history', 'native-auth', 'native-email-factor', 'native-session-duration', 'native-login-names', 'native-member-bank', 'native-member-bank-interface', 'native-member-accounts', 'native-membership', 'native-member-cards', 'native-membership-family', 'native-account-mail', 'native-account-identity',
    'native-website-design', 'native-home-layout', 'native-content-sections', 'native-section-crop', 'native-communications', 'native-editor-upload', 'native-album-upload', 'native-album-catalogue', 'native-article-catalogue', 'native-gallery', 'native-public-albums', 'native-content-workflow', 'native-news-channels', 'native-news-review', 'native-news-policy', 'native-news-reading', 'native-delivery', 'native-original-jobs', 'native-reminders',
    'native-events', 'native-event-workflows', 'native-facebook-events', 'native-event-worker', 'native-ticket-payments', 'native-tickets', 'native-ticket-voucher-pos', 'native-ticket-corrections', 'native-ticket-mail', 'native-ticket-refunds', 'native-eventbrite-images', 'native-event-catalogue', 'native-event-publications', 'native-chat', 'native-chat-workflows', 'native-navigation',
    'native-publication', 'native-public-router', 'native-public-aliases', 'native-indexing', 'native-feeds', 'native-feed-http', 'native-feed-images', 'native-feed-stream', 'native-public-search', 'native-social', 'native-social-hub', 'native-social-library', 'native-social-month', 'native-editorial-hub', 'native-social-accounts', 'native-social-event-sources', 'native-social-attention', 'native-social-personal-activity', 'native-social-replies', 'native-social-personal-replies',
    'native-personal-publication', 'native-personal-planning', 'native-meta-worker', 'native-social-message-replies', 'native-conversation-mail', 'native-mail-direct', 'native-inbox-catalogue', 'native-inbox-history', 'native-conversation-search', 'native-attention-catalogue', 'native-social-originals', 'native-pos', 'native-refund-recovery', 'native-printed-brand', 'native-hse-measures', 'shared-hse-documents', 'native-installer', 'native-webspace-installer', 'native-installer-integrations', 'native-recovery', 'native-story-handoff',
    'native-image-design', 'native-carousels', 'native-meta-carousels', 'native-carousel-ui', 'native-meta-webhooks', 'native-canvas-editor', 'native-photo-clones', 'native-production-editorial', 'native-contact-status', 'native-website-workspace', 'native-editorial-lists', 'native-press-recipients', 'native-manager-settings', 'native-manager-mail', 'native-digests', 'native-confirmation-renewals', 'native-newsletter-forms', 'native-account-panels', 'native-account-sessions', 'native-contact-links', 'native-media-references', 'native-media-removal-guard', 'native-media-quarantine-store', 'native-media-removal', 'native-media-removal-ui', 'native-media-retention', 'native-media-purge', 'native-media-purge-ui', 'native-media-storage-inventory', 'native-media-storage-ui', 'native-document-quota', 'native-document-quota-ui', 'native-photo-soft-clones', 'native-photo-clone-painter', 'native-photo-healing', 'native-videos', 'native-video-approval', 'native-meta-reels', 'native-http-gates', 'native-video-metadata', 'native-video-quota', 'native-video-archive', 'native-video-removal', 'native-video-storage-inventory', 'native-video-recovery', 'native-video-recovery-ui', 'native-video-reuse', 'native-reel-studio', 'native-reel-audio', 'native-media-storage-recovery', 'native-media-recovery-catalogue', 'native-prepared-queries'
];
$suites[] = 'native-stadtlandfunk-organizations';
$suites[] = 'native-public-demo';
$suites[] = 'native-articles';
$suites[] = 'native-newsletter-contacts';
$suites[] = 'native-press-delivery';
$suites[] = 'native-package-upgrade';
$suites[] = 'native-restore-resume';
$suites[] = 'native-hse-inspections';
$suites[] = 'native-newsletter-deliveries';
$suites[] = 'native-press-contacts-management';
$suites[] = 'native-communication-templates';
$suites[] = 'native-edition-catalogue';
$suites[] = 'native-press-event-sources';
$suites[] = 'native-press-photo-attachments';
$suites[] = 'native-press-upload-rights';
$suites[] = 'native-press-save-requests';
$suites[] = 'native-social-save-requests';
$suites[] = 'native-finance-business-day';
$suites[] = 'native-image-studio-interface';
$suites[] = 'native-canvas-save-requests';
$suites[] = 'native-social-photo-upload';
$suites[] = 'native-social-planning-interface';
$suites[] = 'native-event-preparations';
$suites[] = 'native-story-automation';
$suites[] = 'native-video-save-requests';
$suites[] = 'native-video-metadata-save-requests';
$suites[] = 'native-video-policy-save-requests';
$suites[] = 'native-video-archive-save-requests';
$suites[] = 'native-video-removal-requests';
$suites[] = 'native-video-recovery-requests';
$suites[] = 'native-video-reuse-requests';
$suites[] = 'native-press-compose';
$suites[] = 'native-contact-choices';
$suites[] = 'native-calendar-people';
$suites[] = 'native-calendar-upcoming';
$suites[] = 'native-calendar-recipients';
$suites[] = 'native-large-camera-originals';
$suites[] = 'native-email-address-change';
$suites[] = 'native-chat-catalogue';
$suites[] = 'native-chat-push';
$suites[] = 'native-chat-files';
$suites[] = 'native-chat-poll-forms';
$suites[] = 'native-inventory-movements';
$suites[] = 'native-sepa-order-catalogue';
$suites[] = 'native-sepa-candidate-picker';
$suites[] = 'native-goods-catalogue';
$suites[] = 'native-finance-catalogue';
$suites[] = 'native-inventory-financial-sources';
$suites[] = 'native-inventory-item-catalogue';
$suites[] = 'native-inventory-loan-catalogue';
$suites[] = 'native-inventory-assets-catalogue';
$suites[] = 'native-inventory-where-catalogue';
$suites[] = 'native-inventory-loan-draft';
$suites[] = 'native-inventory-loan-stock-picker';
$suites[] = 'native-newsletter-recovery';
$suites[] = 'native-mail-preflight';
$suites[] = 'native-account-preflight';
$suites[] = 'native-ticket-preflight';
$suites[] = 'native-account-acknowledgement';
$suites[] = 'native-membership-preflight';
$suites[] = 'native-application-mail-preflight';
$suites[] = 'native-invitation-preflight';
$suites[] = 'native-application-notification-preflight';
$suites[] = 'native-club-mail-preflight';
$suites[] = 'native-workflow-mail-preflight';
$suites[] = 'native-event-voucher-mail-preflight';
$suites[] = 'native-newsletter-compose';
$suites[] = 'native-newsletter-save-requests';
$suites[] = 'native-newsletter-pdf-attachments';
$suites[] = 'native-outgoing-mail-preflight';
$suites[] = 'native-social-comment-state';
$suites[] = 'native-comment-moderation';
$suites[] = 'native-catalog-pricing';
$suites[] = 'native-catalog-billing';
$suites[] = 'native-catalog-automation';
$suites[] = 'native-assignment-prices';
$suites[] = 'native-role-administration';
$suites[] = 'native-application-catalogue';
$suites[] = 'native-board-administration';
$suites[] = 'native-invoice-catalogue';
$suites[] = 'native-invoice-intake-review';
$suites[] = 'native-invoice-intake-catalogue';
$suites[] = 'native-documents-detail';
$suites[] = 'native-document-library';
$suites[] = 'native-gallery-originals';
$suites[] = 'native-social-human-sends';
$suites[] = 'native-social-check-plan-requests';
$suites[] = 'native-event-draft-requests';
$suites[] = 'native-website-manifest';
$suites[] = 'native-admission-choices';
$suites[] = 'native-editorial-reading';
$suites[] = 'native-editorial-policy';
$suites[] = 'native-editorial-channels';
$suites[] = 'native-standard-pages';
$suites[] = 'native-website-layouts';
$suites[] = 'native-event-programme';
$suites[] = 'native-worker-history';
$suites[] = 'native-system';
$suites[] = 'native-system-interface';
$suites[] = 'native-account-administration';
$suites[] = 'native-post-corrections';
$suites[] = 'native-manager-controls';
$suites[] = 'native-eric-runtime';
$suites[] = 'native-service-status';
$suites[] = 'native-service-status-interface';
$suites[] = 'native-public-status-language';
$suites[] = 'native-service-status-providers';
$suites[] = 'native-cache';
$suites[] = 'native-cache-interface';
$suites[] = 'native-extensions';
$suites[] = 'native-extensions-interface';
$suites[] = 'native-extension-hooks';
$suites[] = 'native-extension-hosts';
$suites[] = 'native-content-lists';
$suites[] = 'native-tree-copy-jobs';
$suites[] = 'native-tree-copy-removal';
$suites[] = 'native-page-purge';
$suites[] = 'native-languages';
$suites[] = 'native-language-renderers';
$suites[] = 'native-typed-fields';
$suites[] = 'native-template-contexts';
$suites[] = 'native-template-policy';
$suites[] = 'native-public-search-languages';
$suites[] = 'native-frontend-edits';
$suites[] = 'native-public-asset-channels';
$suites[] = 'native-field-policy';
$suites[] = 'native-api-tokens';
$suites[] = 'native-api-interface';
$suites[] = 'native-api';
$suites[] = 'native-api-role-transactions';
$suites[] = 'native-api-recovery';
$suites[] = 'native-api-http';
$suites[] = 'native-routing';
$suites[] = 'native-file-versions';
$suites[] = 'native-api-files';
$suites[] = 'native-cms-transfer-package';
$suites[] = 'native-cms-transfer';
$suites[] = 'native-comments';
$suites[] = 'native-comment-fields';
$suites[] = 'native-comments-ui';
$suites[] = 'native-fieldtypes';
$suites[] = 'native-model-catalogue';
$suites[] = 'native-template-renderers';
$suites[] = 'native-api-composition';
$suites[] = 'native-auth-retention';
$suites[] = 'native-runtime-boundaries';
$suites[] = 'native-appearance';
$suites[] = 'native-camera-detection';
$suites[] = 'camera-document-uploads';
$suites[] = 'native-camera-document-uploads';
$suites[] = 'native-selector-grammar';
$suites[] = 'native-selector-relations';
$suites[] = 'native-selector-window';
$suites[] = 'native-balance-period-production';
$suites[] = 'ebilanz-environments';
$suites[] = 'native-balance-environments';
$suites[] = 'native-balance-environments-apps';
$suites[] = 'native-session-authority';
$suites[] = 'native-login-aliases';
$suites[] = 'native-restore-accounts-pure';
$suites[] = 'native-restore-accounts';
$suites[] = 'native-newsletter-privacy';
$suites[] = 'native-navigation-form-state';
$suites[] = 'native-catalogue-policies';
$suites[] = 'native-raster-photo-fallback';
$suites[] = 'native-k-browser';
$suites[] = 'native-letter-outbox-repair';
$suites[] = 'native-manager-interface';
$suites[] = 'native-account-interface';
$suites[] = 'native-fints-directory';
$suites[] = 'native-report-source-links';
$suites[] = 'native-integration-registrations';
$suites[] = 'native-cloud-setup';
$suites[] = 'native-general-mailbox';
$suites[] = 'native-member-reference-date';
$suites[] = 'native-inventory-loan-windows';
$suites[] = 'native-appointment-series';
$suites[] = 'native-free-table-formulas';
$suites[] = 'native-balance-day-allocation';
$suites[] = 'native-free-list-interface';
$suites[] = 'native-shared-controls';
$suites[] = 'native-calendar-interface';
$suites[] = 'native-gema-interface';
$suites[] = 'native-document-interface';
$suites[] = 'native-document-sharing';
$suites[] = 'native-document-library-interface';
$suites[] = 'native-document-upload-interface';
$suites[] = 'native-photo-editor-interface';
$suites[] = 'native-media-library-interface';
$suites[] = 'native-gallery-interface';
$suites[] = 'native-hse-inspection-interface';
$suites[] = 'native-workflow-interface';
$suites[] = 'native-workflow-creations';
$suites[] = 'native-workflow-folders';
$suites[] = 'native-workflow-template-editor';
$suites[] = 'native-workflow-document-editor';
$suites[] = 'native-workflow-signature-interface';
$suites[] = 'native-document-checks-interface';
$suites[] = 'native-personal-documents-interface';
$suites[] = 'native-volunteer-interface';
$suites[] = 'native-voucher-interface';
$suites[] = 'native-voucher-management-interface';
$suites[] = 'native-voucher-mail-interface';
$suites[] = 'native-dashboard-interface';
$suites[] = 'native-roles-interface';
$suites[] = 'native-general-settings-interface';
$suites[] = 'native-numbering-interface';
$suites[] = 'native-tariff-interface';
$suites[] = 'native-assignment-interface';
$suites[] = 'native-personal-price-interface';
$suites[] = 'native-catalog-billing-interface';
$suites[] = 'native-catalog-automation-interface';
$suites[] = 'native-finance-preferences-interface';
$suites[] = 'native-finance-bank-interface';
$suites[] = 'native-finance-tax-interface';
$suites[] = 'native-mail-settings-interface';
$suites[] = 'native-paper-settings-interface';
$suites[] = 'native-text-settings-interface';
$suites[] = 'native-outgoing-mail-interface';
$suites[] = 'native-outgoing-invoice-interface';
$suites[] = 'native-outgoing-payment-interface';
$suites[] = 'native-outgoing-correction-interface';
$suites[] = 'native-contribution-run-interface';
$suites[] = 'native-contribution-delivery-interface';
$suites[] = 'native-sepa-interface';
$suites[] = 'native-sepa-return-funding';
$suites[] = 'native-sepa-remaining-collection';
$suites[] = 'native-sepa-return-corrections';
$suites[] = 'native-transfer-interface';
$suites[] = 'native-internal-movements';
$suites[] = 'native-bank-catalogue';
$suites[] = 'native-bank-ledger-interface';
$suites[] = 'native-bank-matching-interface';
$suites[] = 'native-cashbook-interface';
$suites[] = 'native-manual-finance-interface';
$suites[] = 'native-finance-help-interface';
$suites[] = 'native-invoice-review-interface';
$suites[] = 'native-invoice-review-recovery';
$suites[] = 'native-finance-receipt-frame';
$suites[] = 'native-position-edit-interface';
$suites[] = 'native-invoice-original-interface';
$suites[] = 'native-electronic-invoice-interface';
$suites[] = 'native-outgoing-electronic-interface';
$suites[] = 'native-xinvoice-interface';
$suites[] = 'native-bank-receipt-flow';
$suites[] = 'native-allocation-corrections';
$suites[] = 'native-bank-import-review';
$suites[] = 'native-bank-import-corrections';
$suites[] = 'native-bank-existing-money';
$suites[] = 'native-bank-payment-groups';
$suites[] = 'native-bank-payment-shares';
$suites[] = 'native-bank-fee-evidence';
$suites[] = 'native-payment-group-reversals';
$suites[] = 'native-payment-returns';
$suites[] = 'native-return-order-links';
$suites[] = 'native-partial-payment-returns';
$suites[] = 'native-transfer-settlements';
$suites[] = 'native-bank-import-recovery';
$suites[] = 'native-payment-reversals';
$suites[] = 'native-finance-process';
$suites[] = 'native-excess-returns';
$suites[] = 'native-credit-refund-transfers';
$suites[] = 'native-excess-return-postings';
$suites[] = 'native-donation-cover-interface';
$suites[] = 'native-invoice-reminder-interface';
$suites[] = 'native-debit-notice-interface';
$suites[] = 'native-invoice-address-layout';
$suites[] = 'native-donation-form-interface';
$suites[] = 'native-donor-contact-interface';
$suites[] = 'native-donor-contact-concurrency';
$suites[] = 'native-goods-donation-interface';
$suites[] = 'native-finance-report-interface';
$suites[] = 'native-finance-export-interface';
$suites[] = 'native-navigation-read-history';
$suites[] = 'native-asset-book-source';
$suites[] = 'native-invoice-inbox-source';
$suites[] = 'native-invoice-mail-source';
$suites[] = 'native-invoice-overview';
$suites[] = 'native-invoice-archive-interface';
$suites[] = 'native-task-help';
$suites[] = 'native-invoice-assignment';
$suites[] = 'native-invoice-guidance';
$suites[] = 'native-invoice-payment-reports';
$suites[] = 'native-invoice-draft-read';
$suites[] = 'native-invoice-save-recovery';
$suites[] = 'native-invoice-entry';
$suites[] = 'native-invoice-choices';
$suites[] = 'native-invoice-integrity';
$suites[] = 'native-document-source-choices';
$suites[] = 'native-document-source-overview';
$suites[] = 'native-member-archive-interface';
$suites[] = 'native-member-sepa-settings';
$suites[] = 'native-application-overview-interface';
$suites[] = 'native-application-review-interface';
$suites[] = 'native-manual-application-interface';
$suites[] = 'native-application-setup-interface';
$suites[] = 'native-document-sharing-choices';
$suites[] = 'native-member-excel';
$suites[] = 'native-member-import-details';
$suites[] = 'native-occasions-interface';
$suites[] = 'native-tax-catalogue-interface';
$suites[] = 'native-minutes-interface';
$suites[] = 'native-member-changes-interface';
$suites[] = 'native-activity-reports-interface';
$suites[] = 'native-hosting-jobs';
$suites[] = 'native-payment-tags-interface';
$suites[] = 'native-bank-account-interface';
$suites[] = 'native-fints-interface';
$suites[] = 'native-fints-dialog-input';
$suites[] = 'native-fints-schedule-interface';
$suites[] = 'native-receipt-documents-interface';
$suites[] = 'native-contribution-invoice-source';
$suites[] = 'native-member-catalogue-interface';
$suites[] = 'native-member-profile-original';
$suites[] = 'native-profile-create-replay';
$suites[] = 'native-inventory-location-interface';
$suites[] = 'native-inventory-catalogue-interface';
$suites[] = 'native-inventory-item-interface';
$suites[] = 'native-inventory-move-interface';
$suites[] = 'native-inventory-piece-interface';
$suites[] = 'native-inventory-piece-correction-interface';
$suites[] = 'native-inventory-individual-interface';
$suites[] = 'native-inventory-loan-create-interface';
$suites[] = 'native-inventory-loan-action-interface';
$suites[] = 'native-inventory-file-interface';
$suites[] = 'native-inventory-history-interface';
$suites[] = 'native-inventory-lifecycle-interface';
$suites[] = 'native-inventory-invoice-files-interface';
$suites[] = 'native-inventory-accounting-interface';
$suites[] = 'native-cost-center-subdivisions';
$suites[] = 'native-inventory-recognition-interface';
$suites[] = 'native-inventory-links-interface';
$suites[] = 'native-reimbursement-interface';
$suites[] = 'native-reimbursement-originals';
$suites[] = 'native-reimbursement-corrections';
$suites[] = 'native-reimbursement-bank-source';
$suites[] = 'native-reimbursement-returns';
$suites[] = 'native-annual-tax-interface';
$suites[] = 'native-tax-auto-package-interface';
$suites[] = 'native-tax-auto-draft-interface';
$suites[] = 'native-tax-filing-interface';
$suites[] = 'native-balance-filing-interface';
$suites[] = 'native-balance-correction-interface';
$suites[] = 'native-balance-period-correction-interface';
$suites[] = 'native-balance-segment-interface';
$suites[] = 'native-balance-clearing-interface';
$suites[] = 'native-balance-tax-reconciliation-interface';
$suites[] = 'native-balance-period-area-interface';
$suites[] = 'native-balance-period-tax-interface';
$suites[] = 'native-finance-original-recovery';
$suites[] = 'native-balance-period-journal-interface';
$suites[] = 'native-balance-period-source-interface';
$suites[] = 'native-balance-own-movement-interface';
$suites[] = 'native-balance-source-decision-interface';
$suites[] = 'native-balance-annual-source-interface';
$suites[] = 'native-balance-annual-catalogue-interface';
$suites[] = 'native-balance-annual-journal-interface';
$suites[] = 'native-balance-annual-closing-interface';
$suites[] = 'native-balance-annual-setup-interface';
$suites[] = 'native-balance-annual-mapping-interface';
$suites[] = 'native-balance-annual-screen-interface';
$suites[] = 'native-balance-period-screen-interface';
$suites[] = 'native-financial-reserve-calendar';
$suites[] = 'native-financial-reserve-year';
$suites[] = 'native-financial-reserve-review';
$suites[] = 'native-goods-correction-interface';
$suites[] = 'native-donation-collection-interface';
$suites[] = 'native-mailings-interface';
$suites[] = 'native-release-interface';
$suites[] = 'native-mime-headers';
$selected = array_slice($argv, 1);
if ($selected && array_diff($selected, $suites)) {
    fwrite(STDERR, "Unbekannte Testsuite.\n");
    exit(1);
}
$suites = $selected ?: $suites;
$root = dirname(__DIR__, 2);
$directory = $root.'/.local/native-ci-'.bin2hex(random_bytes(12));
$acceptanceTemporary = getenv('DVM_LOCAL_ACCEPTANCE_TMP');
if (is_string($acceptanceTemporary) && $acceptanceTemporary !== '') {
    $base = realpath($acceptanceTemporary);
    if ($base === false || !is_dir($base) || !is_writable($base) || is_link($acceptanceTemporary)
        || (fileperms($base) & 0077) !== 0) {
        fwrite(STDERR, "Eigenes privates temporäres Abnahmeverzeichnis erforderlich.\n");
        exit(1);
    }
    $directory = $base.'/native-ci-'.bin2hex(random_bytes(12));
}
$failed = [];
$databases = [];
$temporaryDirectories = [];
$ownedDirectory = false;
$infrastructureFailed = false;
try {
    $password = getenv('KH_NATIVE_CI_PASSWORD');
    if (!is_string($password) || $password === '') throw new RuntimeException('CI password missing');
    $server = new PDO('mysql:host=127.0.0.1;port=13371;charset=utf8mb4', 'root', $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false
    ]);
    $server->exec('SET SESSION sql_log_bin=0');
    if (!mkdir($directory, 0700, true)) throw new RuntimeException('CI directory unavailable');
    $ownedDirectory = true;
    // Even test code cannot accidentally inherit a configured live transport.
    foreach (['SMTP_DSN', 'META_ACCESS_TOKEN', 'EVENTBRITE_TOKEN'] as $variable) putenv($variable);
    foreach (['KULTURHUS_DISABLE_MAIL', 'KULTURHUS_DISABLE_WEBSITE', 'VEREIN_ERIC_DISABLE_TRANSPORT'] as $variable) putenv($variable.'=1');
    putenv('VEREIN_MODE=synthetic');
    foreach ($suites as $suite) {
        if ($suite==='native-package-upgrade') {
            require_once __DIR__.'/support/upgrade-prior-package.php';
            $priorDirectory = sys_get_temp_dir().'/dvm-upgrade-prior-'.bin2hex(random_bytes(12));
            if (!mkdir($priorDirectory,0700)) throw new RuntimeException('Private temporary package directory unavailable');
            $temporaryDirectories[] = $priorDirectory;
            putenv('DVM_UPGRADE_PRIOR_SOURCE='.ownUpgradePriorPackage($root,$priorDirectory));
        }
        $database = 'kh_native_'.bin2hex(random_bytes(12));
        $server->exec('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_bin');
        $databases[] = $database;
        $configuration = $directory.'/'.$suite.'.json';
        $handle = fopen($configuration, 'x');
        if (!$handle || !chmod($configuration, 0600)) throw new RuntimeException('Private CI configuration unavailable');
        fwrite($handle, json_encode([
            'host' => '127.0.0.1', 'port' => 13371, 'database' => $database,
            'username' => 'root', 'password' => $password, 'label' => $suite === 'native-publication' ? 'publication' : $suite,
            'owner' => 'isolated-native-ci'
        ], JSON_THROW_ON_ERROR));
        fclose($handle);
        putenv('KH_NATIVE_TEST_CONFIG='.$configuration);
        echo "\nRunning ".$suite." in a fresh synthetic database\n";
        $command = $suite === 'ebilanz-environments'
            ? ['python3', __DIR__.'/ebilanz-environments-test.py']
            : [PHP_BINARY, __DIR__.'/'.$suite.'.php'];
        $environment = null;
        // This suite proves exact camera cleanup. Other concurrently running suites
        // must never enter its before/after directory inventory.
        if ($suite === 'native-press-upload-rights') {
            $temporary = sys_get_temp_dir().'/dvm-ci-'.bin2hex(random_bytes(8));
            if (!mkdir($temporary, 0700)) throw new RuntimeException('Private camera test directory unavailable');
            $temporaryDirectories[] = $temporary;
            $environment = getenv();
            $environment['TMPDIR'] = $temporary;
            $environment['DVM_TEST_TEMP_DIRECTORY'] = $temporary;
            $command = [PHP_BINARY, '-d', 'sys_temp_dir='.$temporary, __DIR__.'/'.$suite.'.php'];
        }
        $process = proc_open($command, [0 => STDIN, 1 => ['pipe','w'], 2 => ['pipe','w']], $pipes, $root, $environment);
        if (!is_resource($process)) throw new RuntimeException('CI subprocess unavailable');
        // Forward child pipes through this process so redirected logs retain
        // one file position. Direct PHP stream inheritance overwrote earlier
        // suite output when stdout was a regular file.
        foreach ($pipes as $pipe) stream_set_blocking($pipe, false);
        while ($pipes) {
            $ready = array_values($pipes); $write = null; $except = null;
            if (stream_select($ready, $write, $except, 2) === false) throw new RuntimeException('CI output unavailable');
            foreach ($pipes as $descriptor => $pipe) {
                if (in_array($pipe, $ready, true)) {
                    $chunk = stream_get_contents($pipe);
                    if ($chunk !== false && $chunk !== '') fwrite($descriptor === 1 ? STDOUT : STDERR, $chunk);
                }
                if (feof($pipe)) { fclose($pipe); unset($pipes[$descriptor]); }
            }
        }
        $suiteExit = proc_close($process);
        if ($suiteExit !== 0) $failed[] = $suite;
        unlink($configuration);
        // Completed suites have no shared state. Release only this runner's freshly
        // created schema now, rather than retaining thousands of table definitions
        // until the whole run ends and evicting unrelated prepared metadata.
        $server->exec('DROP DATABASE `'.$database.'`');
        array_pop($databases);
        echo json_encode(['completed_suite'=>$suite,'exit_code'=>$suiteExit,'cleanup_complete'=>true], JSON_THROW_ON_ERROR)."\n";
    }
    echo json_encode(['suites' => count($suites), 'failed' => $failed, 'live_transport' => false], JSON_THROW_ON_ERROR)."\n";
} catch (Throwable) {
    fwrite(STDERR, "Synthetische CI-Einrichtung fehlgeschlagen; lokale MySQL-Laufzeit und private Konfiguration prüfen.\n");
    $infrastructureFailed = true;
} finally {
    foreach ($databases as $database) {
        try { $server->exec('DROP DATABASE `'.$database.'`'); }
        catch (Throwable) { $infrastructureFailed = true; fwrite(STDERR,"Eigene synthetische Testdatenbank konnte nicht bereinigt werden.\n"); }
    }
    foreach (array_merge($ownedDirectory ? [$directory] : [], $temporaryDirectories) as $cleanupDirectory) {
        if (is_link($cleanupDirectory)) { unlink($cleanupDirectory); continue; }
        if (!is_dir($cleanupDirectory)) continue;
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($cleanupDirectory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            if ($file->isDir() && !$file->isLink()) rmdir($file->getPathname());
            else unlink($file->getPathname());
        }
        rmdir($cleanupDirectory);
    }
}
exit($infrastructureFailed || $failed ? 1 : 0);
