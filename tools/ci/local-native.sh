#!/bin/bash
# Full local contracts in fresh synthetic databases; never starts GitHub Actions.
set -euo pipefail
task_repo=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd -P)
cd -- "$task_repo"
task_mode=${1:---core}
if [[ $# -gt 0 ]]; then shift; fi
case "$task_mode" in --preflight|--core|--calendar|--components|--browsers|--package|--all) ;; *) echo 'Aufruf: bash tools/ci/local-native.sh --preflight|--core [Suite ...]|--calendar|--components|--browsers|--package|--all' >&2; exit 2 ;; esac
[[ "$task_mode" == --core || $# == 0 ]] || { echo 'Einzelne Suiten nur mit --core wählen.' >&2; exit 2; }
# A complete acceptance must not inherit a developer's shortened diagnostics.
if [[ "$task_mode" == --all || "$task_mode" == --browsers ]]; then
  unset DVM_TRANSFER_PAYMENT_ONLY
  unset DVM_HSE_EDITOR_CASES DVM_INVOICE_ENTRY_SAVE_RECHECK
  unset DVM_ACTIVITY_REPORTS_BROWSER_ONLY
  unset DVM_FINANCE_EXPORT_PROFILES
  unset DVM_APPEARANCE_FLOW DVM_MEMBER_PROFILE_ORIGINAL_CASE DVM_INVENTORY_ITEM_CASE
  unset DVM_BALANCE_TAX_RECONCILIATION_SEGMENT
  unset DVM_BALANCE_PERIOD_AREA_SEGMENT
  unset DVM_BALANCE_PERIOD_TAX_SEGMENT
  unset DVM_FINANCE_ORIGINAL_RECOVERY_SEGMENT DVM_BALANCE_PERIOD_JOURNAL_SEGMENT
  unset DVM_INVENTORY_LOAN_ACTION_CASE
  unset DVM_INVENTORY_FILE_CASE
  unset DVM_INVENTORY_HISTORY_CASE
  unset DVM_INVENTORY_LIFECYCLE_CASE
  unset DVM_INVENTORY_INVOICE_FILES_CASE
  unset DVM_INVENTORY_ACCOUNTING_CASE
  unset DVM_INVENTORY_RECOGNITION_CASE
  unset DVM_INVENTORY_LINKS_CASE
  unset DVM_INVENTORY_LOAN_CREATE_CASE
  unset DVM_INVENTORY_INDIVIDUAL_CASE
  unset DVM_INVENTORY_PIECE_CORRECTION_CASE
  unset DVM_INVENTORY_PIECE_CASE
  unset DVM_K_BROWSER_CASE DVM_MANAGER_CONTROLS_FAST
  unset DVM_MANAGER_CONTROLS_PROFILE_ONLY DVM_MANAGER_CONTROLS_TRANSITION_ONLY
  unset DVM_APPEARANCE_ACTIONS_ONLY DVM_API_APPS_ONLY DVM_API_UI_ONLY_RECOVERY DVM_SYSTEM_UI_ONLY_NATIVE
  unset DVM_NAVIGATION_HTTP_ONLY DVM_NAVIGATION_PROFILE DVM_RASTER_SKIP_PURE
  unset DVM_PUBLIC_DEMO_BROWSER_CASE
  unset DVM_CONVERSATION_PROFILE
  unset DVM_COMMENTS_ACTIONS_ONLY DVM_COMMENTS_FLOW DVM_CONTENT_LISTS_ACTIONS_ONLY
  unset DVM_VOUCHER_MANAGEMENT_CASE DVM_VOUCHER_MANAGEMENT_READONLY DVM_VOUCHER_MAIL_CASE DVM_VOUCHER_MAIL_READONLY DVM_DASHBOARD_CASE DVM_DASHBOARD_READONLY DVM_DASHBOARD_FINANCE_ONLY DVM_ROLES_CASE DVM_GENERAL_SETTINGS_CASE DVM_NUMBERING_CASE DVM_TARIFF_CASE DVM_ASSIGNMENT_CASE DVM_PERSONAL_PRICE_CASE DVM_CATALOG_BILLING_CASE DVM_CATALOG_AUTOMATION_CASE DVM_FINANCE_PREFERENCES_CASE DVM_FINANCE_BANK_CASE DVM_FINANCE_TAX_CASE DVM_MAIL_SETTINGS_CASE DVM_PAPER_SETTINGS_CASE DVM_TEXT_SETTINGS_CASE DVM_OUTGOING_MAIL_CASE DVM_OUTGOING_INVOICE_CASE DVM_OUTGOING_CORRECTION_CASE DVM_CONTRIBUTION_RUN_CASE DVM_CONTRIBUTION_DELIVERY_CASE DVM_SEPA_CASE DVM_SEPA_RETURN_FUNDING_CASE DVM_SEPA_REMAINING_CASE DVM_SEPA_CORRECTION_CASE DVM_TRANSFER_CASE DVM_DONATION_COVER_CASE DVM_DEBIT_CASE
  unset DVM_DONATION_FORM_CASE DVM_DONATION_COLLECTION_CASE
  unset DVM_XINVOICE_CASE DVM_OUTGOING_ELECTRONIC_CASE DVM_ELECTRONIC_INVOICE_CASE DVM_INVOICE_ORIGINAL_CASE DVM_POSITION_EDIT_CASE DVM_FINANCE_RECEIPT_CASE DVM_INVOICE_REVIEW_RECOVERY_CASE DVM_INVOICE_REVIEW_CASE DVM_FINANCE_HELP_CASE DVM_MANUAL_FINANCE_CASE DVM_CASHBOOK_CASE DVM_BANK_MATCHING_CASE DVM_BANK_LEDGER_CASE DVM_BANK_RECEIPT_CASE DVM_BANK_IMPORT_REVIEW_CASE DVM_BANK_IMPORT_CORRECTIONS_CASE DVM_BANK_IMPORT_RECOVERY_CASE DVM_PAYMENT_REVERSALS_CASE DVM_FINANCE_PROCESS_CASE DVM_EXCESS_RETURNS_CASE DVM_CREDIT_REFUND_CASE DVM_BANK_EXISTING_MONEY_CASE DVM_BANK_PAYMENT_GROUPS_CASE DVM_BANK_PAYMENT_SHARES_CASE DVM_BANK_FEE_CASE DVM_GROUP_REVERSALS_CASE DVM_PAYMENT_RETURNS_CASE DVM_RETURN_ORDER_CASE DVM_PARTIAL_RETURNS_CASE DVM_TRANSFER_SETTLEMENTS_CASE
  unset DVM_ACCOUNT_INTERFACE_ACTIONS_ONLY
  unset DVM_LANGUAGE_ACTIONS_ONLY DVM_PRESS_FLOW_ONLY
  unset DVM_ROUTING_ACTIONS_ONLY DVM_ROUTING_MATRIX_ONLY
  unset DVM_SOCIAL_LIBRARY_ONLY_INTEGRATED DVM_STATUS_BROWSER_ACTIONS_ONLY
  unset DVM_TEMPLATE_RENDERERS_ACTIONS_ONLY
  unset DVM_SOCIAL_SAVE_SMOKE
  unset DVM_IMAGE_STUDIO_SMOKE
  unset DVM_CANVAS_SAVE_SMOKE
  unset DVM_SOCIAL_PHOTO_SMOKE
  unset DVM_VIDEO_REUSE_REQUEST_SMOKE
  unset DVM_VIDEO_RECOVERY_SEGMENT
  unset DVM_VIDEO_RECOVERY_SMOKE
  unset DVM_VIDEO_FILE_SMOKE
  unset DVM_VIDEO_ARCHIVE_SAVE_SMOKE
  unset DVM_VIDEO_POLICY_SAVE_SMOKE
  unset DVM_VIDEO_METADATA_SAVE_SMOKE
  unset DVM_VIDEO_SAVE_SMOKE
  unset DVM_SOCIAL_PLANNING_SMOKE
  unset DVM_EXTENSIONS_UI_ONLY_LOST
fi
task_runtime=${DVM_LOCAL_RUNTIME:-/workspace/.setup}
if [[ -x "$task_runtime/bin/php" ]]; then
  export PATH="$task_runtime/bin:$PATH"
  export PHPRC="$task_runtime/php-conf/php.ini"
  export LD_LIBRARY_PATH="$task_runtime/runtime/usr/lib/x86_64-linux-gnu${LD_LIBRARY_PATH:+:$LD_LIBRARY_PATH}"
fi
export VEREIN_PYTHON="${VEREIN_PYTHON:-$task_runtime/invoice-runtime/python/run}"
export VEREIN_OCR_RUNTIME="${VEREIN_OCR_RUNTIME:-$task_runtime/invoice-private/runtime-config/ocr.json}"
export VEREIN_KOSIT_RUNTIME="${VEREIN_KOSIT_RUNTIME:-$task_runtime/invoice-private/runtime-config/kosit.json}"
export VEREIN_HYBRID_RUNTIME="${VEREIN_HYBRID_RUNTIME:-$task_runtime/invoice-private/runtime-config/hybrid.json}"
export PLAYWRIGHT_MODULE="${PLAYWRIGHT_MODULE:-$task_runtime/browser/node_modules/playwright/index.mjs}"
export PLAYWRIGHT_CHROMIUM_EXECUTABLE="${PLAYWRIGHT_CHROMIUM_EXECUTABLE:-/usr/bin/chromium}"
export KH_NATIVE_CI=1 KH_NATIVE_CI_PASSWORD=synthetic-ci-only
export KULTURHUS_DISABLE_MAIL=1 KULTURHUS_DISABLE_WEBSITE=1
export VEREIN_ERIC_DISABLE_TRANSPORT=1
if [[ "${GITHUB_ACTIONS:-}" != true ]]; then
  case "$task_mode" in
    --core) DVM_LOCAL_TEST_DATABASES=1 python3 tools/cloud/start-test-databases.py --native ;;
    --calendar) DVM_LOCAL_TEST_DATABASES=1 python3 tools/cloud/start-test-databases.py --calendar ;;
    --all|--browsers) DVM_LOCAL_TEST_DATABASES=1 python3 tools/cloud/start-test-databases.py --all ;;
  esac
fi
if [[ "$task_mode" == --preflight || "$task_mode" == --all ]]; then
  python3 distribution/build-source-manifest.py --check
  rg --files vereinsverwaltung/native vereinsverwaltung/src vereinsverwaltung/public vereinsverwaltung/views vereinsverwaltung/migrations -g '*.php' -0 | xargs -0 -n 1 php -l
  python3 tools/ci/test-start-mysql.py
  python3 tools/cloud/test-retire-legacy-preview.py
  python3 tools/cloud/test-start-test-databases.py
  python3 native-app/tools/interface-resources.py --check
  python3 native-app/tests/test-ios-ci.py
  python3 native-app/tests/test-mobile-release.py
fi
if [[ "$task_mode" == --core || "$task_mode" == --all ]]; then
  php vereinsverwaltung/tests/ci-native.php "$@"
fi
if [[ "$task_mode" == --calendar || "$task_mode" == --all ]]; then
  export VEREIN_TEST_MYSQL=isolated-regression-only
  export VEREIN_TEST_MYSQL_HOST=127.0.0.1 VEREIN_TEST_MYSQL_PORT=13372
  export VEREIN_TEST_RESULTS="${VEREIN_TEST_RESULTS:-$task_repo/.local/test-results}"
  php vereinsverwaltung/tests/run-mysql-suite.php calendar-occasions-publications.php calendar-workflows.php calendar-newsletter-periods.php calendar-loans.php shared-calendar-documents.php calendar-visible-range.php event-workflows.php calendar-invitations.php
fi
if [[ "$task_mode" == --components || "$task_mode" == --browsers || "$task_mode" == --all ]]; then
  node vereinsverwaltung/tests/image-export-browser.mjs
  node vereinsverwaltung/tests/photo-import-browser.mjs
  node vereinsverwaltung/tests/photo-editor-browser.mjs
  node vereinsverwaltung/tests/editor-list-browser.mjs
  node native-app/tests/native-client-browser.mjs
fi
if [[ "$task_mode" == --browsers || "$task_mode" == --all ]]; then
  python3 tools/ci/test-browser-registration.py
  for task_case in \
    DVM_PUBLIC_DEMO_BROWSER:native-public-demo \
    DVM_GENERAL_MAILBOX_BROWSER:native-general-mailbox \
    DVM_MAILINGS_INTERFACE_BROWSER:native-mailings-interface \
    DVM_RECEIPT_DOCUMENTS_BROWSER:native-receipt-documents-interface \
    DVM_CONTRIBUTION_INVOICE_SOURCE_BROWSER:native-contribution-invoice-source \
    DVM_INVENTORY_CATALOGUE_BROWSER:native-inventory-catalogue-interface \
    DVM_INVENTORY_MATERIAL_BROWSER:native-inventory-catalogue-interface \
    DVM_INVENTORY_LOCATION_BROWSER:native-inventory-location-interface \
    DVM_INVENTORY_MOVE_BROWSER:native-inventory-move-interface \
    DVM_OCCASIONS_BROWSER:native-occasions-interface \
    DVM_MEMBER_CATALOGUE_BROWSER:native-member-catalogue-interface \
    DVM_MEMBER_DETAILS_BROWSER:native-member-import-details \
    DVM_PROFILE_CREATE_REPLAY_BROWSER:native-profile-create-replay \
    DVM_MEMBER_PROFILE_ORIGINAL_BROWSER:native-member-profile-original \
    DVM_INVENTORY_ITEM_BROWSER:native-inventory-item-interface \
    DVM_INVENTORY_ITEM_SOURCE_BROWSER:native-inventory-item-interface \
    DVM_TAX_CATALOGUES_BROWSER:native-tax-catalogue-interface \
    DVM_MINUTES_BROWSER:native-minutes-interface \
    DVM_MEMBER_CHANGES_BROWSER:native-member-changes-interface \
    DVM_ACTIVITY_REPORTS_BROWSER:native-activity-reports-interface \
    DVM_RELEASE_INTERFACE_BROWSER:native-release-interface \
    DVM_GROUP_REVERSALS_BROWSER:native-payment-group-reversals \
    DVM_PAYMENT_RETURNS_BROWSER:native-payment-returns \
    DVM_RETURN_ORDER_LINKS_BROWSER:native-return-order-links \
    DVM_PARTIAL_RETURNS_BROWSER:native-partial-payment-returns \
    DVM_TRANSFER_SETTLEMENTS_BROWSER:native-transfer-settlements \
    DVM_BANK_FEE_BROWSER:native-bank-fee-evidence \
    DVM_BANK_PAYMENT_SHARES_BROWSER:native-bank-payment-shares \
    DVM_BANK_PAYMENT_GROUPS_BROWSER:native-bank-payment-groups \
    DVM_BANK_EXISTING_MONEY_BROWSER:native-bank-existing-money \
    DVM_EXCESS_RETURNS_BROWSER:native-excess-returns \
    DVM_CREDIT_REFUND_BROWSER:native-credit-refund-transfers \
    DVM_FINANCE_PROCESS_BROWSER:native-finance-process \
    DVM_PAYMENT_REVERSALS_BROWSER:native-payment-reversals \
    DVM_BANK_IMPORT_RECOVERY_BROWSER:native-bank-import-recovery \
    DVM_BANK_IMPORT_CORRECTIONS_BROWSER:native-bank-import-corrections \
    DVM_BANK_IMPORT_REVIEW_BROWSER:native-bank-import-review \
    DVM_BANK_RECEIPT_BROWSER:native-bank-receipt-flow \
    DVM_BANK_CATALOGUE_BROWSER:native-bank-catalogue \
    DVM_BANK_LEDGER_BROWSER:native-bank-ledger-interface \
    DVM_BANK_ACCOUNT_BROWSER:native-bank-account-interface \
    DVM_BANK_ACCOUNT_CREATE_BROWSER:native-bank-account-interface \
    DVM_BANK_OPENING_BROWSER:native-bank-account-interface \
    DVM_FINTS_INTERFACE_BROWSER:native-fints-interface \
    DVM_FINTS_SCHEDULE_BROWSER:native-fints-schedule-interface \
    DVM_BANK_MATCHING_BROWSER:native-bank-matching-interface \
    DVM_CASHBOOK_BROWSER:native-cashbook-interface \
    DVM_MANUAL_FINANCE_BROWSER:native-manual-finance-interface \
    DVM_FINANCE_HELP_BROWSER:native-finance-help-interface \
    DVM_INVOICE_REVIEW_BROWSER:native-invoice-review-interface \
    DVM_INVOICE_REVIEW_RECOVERY_BROWSER:native-invoice-review-recovery \
    DVM_FINANCE_RECEIPT_BROWSER:native-finance-receipt-frame \
    DVM_FINANCE_CATALOGUE_INTERFACE_BROWSER:native-finance-receipt-frame \
    DVM_POSITION_EDIT_BROWSER:native-position-edit-interface \
    DVM_INVOICE_ORIGINAL_BROWSER:native-invoice-original-interface \
    DVM_ELECTRONIC_INVOICE_BROWSER:native-electronic-invoice-interface \
    DVM_OUTGOING_ELECTRONIC_BROWSER:native-outgoing-electronic-interface \
    DVM_XINVOICE_BROWSER:native-xinvoice-interface \
    DVM_DONATION_FORM_BROWSER:native-donation-form-interface \
    DVM_DONOR_CONTACT_BROWSER:native-donor-contact-interface \
    DVM_GOODS_DONATION_BROWSER:native-goods-donation-interface \
    DVM_FINANCE_REPORT_BROWSER:native-finance-report-interface \
    DVM_FINANCE_EXPORT_BROWSER:native-finance-export-interface \
    DVM_NAVIGATION_READ_HISTORY_BROWSER:native-navigation-read-history \
    DVM_ASSET_BOOK_SOURCE_BROWSER:native-asset-book-source \
    DVM_INVOICE_INBOX_SOURCE_BROWSER:native-invoice-inbox-source \
    DVM_INVOICE_MAIL_SOURCE_BROWSER:native-invoice-mail-source \
    DVM_INVOICE_OVERVIEW_BROWSER:native-invoice-overview \
    DVM_INVOICE_ARCHIVE_BROWSER:native-invoice-archive-interface \
    DVM_TASK_HELP_BROWSER:native-task-help \
    DVM_INVOICE_ASSIGNMENT_BROWSER:native-invoice-assignment \
    DVM_INVOICE_GUIDANCE_BROWSER:native-invoice-guidance \
    DVM_INVOICE_PAYMENT_REPORTS_BROWSER:native-invoice-payment-reports \
    DVM_INVOICE_DRAFT_READ_BROWSER:native-invoice-draft-read \
    DVM_INVOICE_DRAFT_SAVE_BROWSER:native-invoice-draft-read \
    DVM_INVOICE_SAVE_RECOVERY_BROWSER:native-invoice-save-recovery \
    DVM_INVOICE_ENTRY_BROWSER:native-invoice-entry \
    DVM_INVOICE_CHOICES_BROWSER:native-invoice-choices \
    DVM_INVOICE_INTEGRITY_BROWSER:native-invoice-integrity \
    DVM_DOCUMENT_SOURCE_CHOICES_BROWSER:native-document-source-choices \
    DVM_DOCUMENT_SOURCE_OVERVIEW_BROWSER:native-document-source-overview \
    DVM_MEMBER_ARCHIVE_BROWSER:native-member-archive-interface \
    DVM_MEMBER_SEPA_SETTINGS_BROWSER:native-member-sepa-settings \
    DVM_APPLICATION_OVERVIEW_BROWSER:native-application-overview-interface \
    DVM_APPLICATION_REVIEW_BROWSER:native-application-review-interface \
    DVM_MANUAL_APPLICATION_BROWSER:native-manual-application-interface \
    DVM_APPLICATION_SETUP_BROWSER:native-application-setup-interface \
    DVM_DOCUMENT_SHARING_CHOICES_BROWSER:native-document-sharing-choices \
    DVM_MEMBER_EXCEL_BROWSER:native-member-excel \
    DVM_PAYMENT_TAG_BROWSER:native-payment-tags-interface \
    DVM_GOODS_CORRECTION_BROWSER:native-goods-correction-interface \
    DVM_DONATION_COLLECTION_BROWSER:native-donation-collection-interface \
    DVM_INTERNAL_MOVEMENTS_BROWSER:native-internal-movements \
    DVM_SERVICE_STATUS_BROWSER:native-service-status \
    DVM_STATUS_INTERFACE_BROWSER:native-service-status-interface \
    DVM_PUBLIC_STATUS_BROWSER:native-public-status-language \
    DVM_MANAGER_INTERFACE_BROWSER:native-manager-interface \
    DVM_ACCOUNT_INTERFACE_BROWSER:native-account-interface \
    DVM_FINTS_DIRECTORY_BROWSER:native-fints-directory \
    DVM_INTEGRATION_REGISTRATIONS_BROWSER:native-integration-registrations \
    DVM_APPEARANCE_BROWSER:native-appearance \
    DVM_CAMERA_DOCUMENTS_BROWSER:native-camera-document-uploads \
    DVM_RASTER_PHOTOS_BROWSER:native-raster-photo-fallback \
    DVM_K_LEGACY_BROWSER:native-k-browser \
    DVM_BALANCE_ENVIRONMENTS_BROWSER:native-balance-environments-apps \
    DVM_WEBSITE_WORKSPACE_BROWSER:native-website-workspace \
    DVM_NAVIGATION_BROWSER:native-navigation \
    DVM_CATALOGUE_POLICY_BROWSER:native-catalogue-policies \
    DVM_SYSTEM_BROWSER:native-system \
    DVM_FIELDTYPES_BROWSER:native-fieldtypes \
    DVM_TEMPLATE_RENDERERS_BROWSER:native-template-renderers \
    DVM_COMMENTS_BROWSER:native-comments-ui \
    DVM_CMS_TRANSFER_BROWSER:native-cms-transfer \
    DVM_CMS_TRANSFER_NATIVE_BROWSER:native-cms-transfer \
    DVM_ACCOUNT_ADMIN_BROWSER:native-account-administration \
    DVM_ROUTING_BROWSER:native-routing \
    DVM_FILE_VERSIONS_APP_BROWSER:native-file-versions \
    DVM_API_FILES_HTTP:native-api-files \
    DVM_CACHE_BROWSER:native-cache \
    DVM_CACHE_INTERFACE_BROWSER:native-cache-interface \
    DVM_EXTENSIONS_BROWSER:native-extensions \
    DVM_EXTENSIONS_INTERFACE_BROWSER:native-extensions-interface \
    DVM_API_INTERFACE_BROWSER:native-api-interface \
    DVM_SYSTEM_INTERFACE_BROWSER:native-system-interface \
    DVM_FIELD_POLICY_BROWSER:native-field-policy \
    DVM_CONTENT_LISTS_BROWSER:native-content-lists \
    DVM_CONTENT_LISTS_NATIVE_BROWSER:native-content-lists \
    DVM_PAGE_LIFECYCLE_BROWSER:native-tree-copy-jobs \
    DVM_LANGUAGES_BROWSER:native-languages \
    DVM_TYPED_FIELDS_BROWSER:native-typed-fields \
    DVM_TYPED_FIELDS_APP_BROWSER:native-typed-fields \
    DVM_TEMPLATE_POLICY_BROWSER:native-template-policy \
    DVM_FRONTEND_EDITS_BROWSER:native-frontend-edits \
    DVM_FRONTEND_EDITS_NATIVE_BROWSER:native-frontend-edits \
    DVM_PRESS_PHOTO_ATTACHMENTS_BROWSER:native-press-photo-attachments \
    DVM_PRESS_UPLOAD_RIGHTS_BROWSER:native-press-upload-rights \
    DVM_PRESS_SAVE_BROWSER:native-press-save-requests \
    DVM_SOCIAL_SAVE_BROWSER:native-social-save-requests \
    DVM_IMAGE_STUDIO_INTERFACE_BROWSER:native-image-studio-interface \
    DVM_CANVAS_SAVE_BROWSER:native-canvas-save-requests \
    DVM_STORY_AUTOMATION_BROWSER:native-story-automation \
    DVM_SOCIAL_PHOTO_BROWSER:native-social-photo-upload \
    DVM_SOCIAL_PLANNING_BROWSER:native-social-planning-interface \
    DVM_EVENT_PREPARATION_BROWSER:native-event-preparations \
    DVM_PRESS_COMPOSE_BROWSER:native-press-compose \
    DVM_CONTACT_CHOICES_BROWSER:native-contact-choices \
    DVM_CALENDAR_PEOPLE_BROWSER:native-calendar-people \
    DVM_CALENDAR_UPCOMING_BROWSER:native-calendar-upcoming \
    DVM_CALENDAR_RECIPIENTS_BROWSER:native-calendar-recipients \
    DVM_LARGE_CAMERA_BROWSER:native-large-camera-originals \
    DVM_WEBSITE_MANIFEST_BROWSER:native-website-manifest \
    DVM_PRESS_EVENT_SOURCES_BROWSER:native-press-event-sources \
    DVM_EDITION_CATALOGUE_BROWSER:native-edition-catalogue \
    DVM_NEWSLETTER_COMPOSE_BROWSER:native-newsletter-compose \
    DVM_NEWSLETTER_SAVE_BROWSER:native-newsletter-save-requests \
    DVM_NEWSLETTER_PDF_BROWSER:native-newsletter-pdf-attachments \
    DVM_ARTICLE_CATALOGUE_BROWSER:native-article-catalogue \
    DVM_COMMUNICATION_TEMPLATES_BROWSER:native-communication-templates \
    DVM_PRESS_CONTACTS_MANAGEMENT_BROWSER:native-press-contacts-management \
    DVM_NEWSLETTER_DELIVERIES_BROWSER:native-newsletter-deliveries \
    DVM_MAIL_PREFLIGHT_BROWSER:native-mail-preflight \
    DVM_HSE_INSPECTIONS_BROWSER:native-hse-inspections \
    DVM_MEMBERSHIP_BROWSER:native-membership-family \
    DVM_MEMBER_REFERENCE_BROWSER:native-member-reference-date \
    DVM_INVENTORY_LOAN_WINDOWS_BROWSER:native-inventory-loan-windows \
    DVM_APPOINTMENT_SERIES_BROWSER:native-appointment-series \
    DVM_FREE_TABLE_FORMULAS_BROWSER:native-free-table-formulas \
    DVM_BALANCE_DAY_BROWSER:native-balance-day-allocation \
    DVM_FREE_LIST_INTERFACE_BROWSER:native-free-list-interface \
    DVM_SHARED_CONTROLS_BROWSER:native-shared-controls \
    DVM_CALENDAR_INTERFACE_BROWSER:native-calendar-interface \
    DVM_GEMA_INTERFACE_BROWSER:native-gema-interface \
    DVM_DOCUMENT_INTERFACE_BROWSER:native-document-interface \
    DVM_DOCUMENT_SHARING_BROWSER:native-document-sharing \
    DVM_DOCUMENT_LIBRARY_INTERFACE_BROWSER:native-document-library-interface \
    DVM_DOCUMENT_UPLOAD_INTERFACE_BROWSER:native-document-upload-interface \
    DVM_PHOTO_EDITOR_INTERFACE_BROWSER:native-photo-editor-interface \
    DVM_MEDIA_LIBRARY_INTERFACE_BROWSER:native-media-library-interface \
    DVM_GALLERY_INTERFACE_BROWSER:native-gallery-interface \
    DVM_HSE_INSPECTION_INTERFACE_BROWSER:native-hse-inspection-interface \
    DVM_DOCUMENT_CHECKS_INTERFACE_BROWSER:native-document-checks-interface \
    DVM_PERSONAL_DOCUMENTS_INTERFACE_BROWSER:native-personal-documents-interface \
    DVM_VOLUNTEER_INTERFACE_BROWSER:native-volunteer-interface \
    DVM_VOUCHER_INTERFACE_BROWSER:native-voucher-interface \
    DVM_VOUCHER_MANAGEMENT_INTERFACE_BROWSER:native-voucher-management-interface \
    DVM_VOUCHER_MAIL_INTERFACE_BROWSER:native-voucher-mail-interface \
    DVM_DASHBOARD_INTERFACE_BROWSER:native-dashboard-interface \
    DVM_ROLES_INTERFACE_BROWSER:native-roles-interface \
    DVM_GENERAL_SETTINGS_BROWSER:native-general-settings-interface \
    DVM_NUMBERING_BROWSER:native-numbering-interface \
    DVM_TARIFF_BROWSER:native-tariff-interface \
    DVM_ASSIGNMENT_BROWSER:native-assignment-interface \
    DVM_PERSONAL_PRICE_BROWSER:native-personal-price-interface \
    DVM_CATALOG_BILLING_INTERFACE_BROWSER:native-catalog-billing-interface \
    DVM_CATALOG_AUTOMATION_INTERFACE_BROWSER:native-catalog-automation-interface \
    DVM_FINANCE_PREFERENCES_INTERFACE_BROWSER:native-finance-preferences-interface \
    DVM_FINANCE_BANK_INTERFACE_BROWSER:native-finance-bank-interface \
    DVM_FINANCE_TAX_INTERFACE_BROWSER:native-finance-tax-interface \
    DVM_MAIL_SETTINGS_INTERFACE_BROWSER:native-mail-settings-interface \
    DVM_PAPER_SETTINGS_INTERFACE_BROWSER:native-paper-settings-interface \
    DVM_TEXT_SETTINGS_INTERFACE_BROWSER:native-text-settings-interface \
    DVM_OUTGOING_MAIL_INTERFACE_BROWSER:native-outgoing-mail-interface \
    DVM_OUTGOING_INVOICE_INTERFACE_BROWSER:native-outgoing-invoice-interface \
    DVM_OUTGOING_PAYMENT_BROWSER:native-outgoing-payment-interface \
    DVM_OUTGOING_BANK_RETURN_BROWSER:native-outgoing-payment-interface \
    DVM_FINANCIAL_ORIGINAL_SOURCE_BROWSER:native-outgoing-payment-interface \
    DVM_OUTGOING_INVOICE_SOURCE_BROWSER:native-outgoing-payment-interface \
    DVM_OUTGOING_CORRECTION_INTERFACE_BROWSER:native-outgoing-correction-interface \
    DVM_CONTRIBUTION_RUN_INTERFACE_BROWSER:native-contribution-run-interface \
    DVM_CONTRIBUTION_DELIVERY_INTERFACE_BROWSER:native-contribution-delivery-interface \
    DVM_SEPA_INTERFACE_BROWSER:native-sepa-interface \
    DVM_SEPA_RETURN_FUNDING_BROWSER:native-sepa-return-funding \
    DVM_SEPA_REMAINING_BROWSER:native-sepa-remaining-collection \
    DVM_SEPA_CORRECTION_BROWSER:native-sepa-return-corrections \
    DVM_TRANSFER_INTERFACE_BROWSER:native-transfer-interface \
    DVM_DONATION_COVER_INTERFACE_BROWSER:native-donation-cover-interface \
    DVM_INVOICE_REMINDER_INTERFACE_BROWSER:native-invoice-reminder-interface \
    DVM_DEBIT_NOTICE_BROWSER:native-debit-notice-interface \
    DVM_WORKFLOW_INTERFACE_BROWSER:native-workflow-interface DVM_WORKFLOW_CREATIONS_BROWSER:native-workflow-creations DVM_WORKFLOW_FOLDERS_BROWSER:native-workflow-folders DVM_WORKFLOW_TEMPLATE_EDITOR_BROWSER:native-workflow-template-editor DVM_WORKFLOW_DOCUMENT_EDITOR_BROWSER:native-workflow-document-editor DVM_WORKFLOW_SIGNATURE_INTERFACE_BROWSER:native-workflow-signature-interface \
    DVM_NEWSLETTER_BROWSER:native-newsletter-forms \
    DVM_EMAIL_ADDRESS_BROWSER:native-email-address-change \
    DVM_CHAT_CATALOGUE_BROWSER:native-chat-catalogue \
    DVM_CHAT_PUSH_BROWSER:native-chat-push \
    DVM_CHAT_FILES_BROWSER:native-chat-files \
    DVM_CHAT_POLL_FORMS_BROWSER:native-chat-poll-forms \
    DVM_INVENTORY_PIECE_BROWSER:native-inventory-piece-interface \
    DVM_INVENTORY_PIECE_CORRECTION_BROWSER:native-inventory-piece-correction-interface \
    DVM_INVENTORY_INDIVIDUAL_BROWSER:native-inventory-individual-interface \
    DVM_INVENTORY_LOAN_CREATE_BROWSER:native-inventory-loan-create-interface \
    DVM_INVENTORY_LOAN_ACTION_BROWSER:native-inventory-loan-action-interface \
    DVM_INVENTORY_FILE_BROWSER:native-inventory-file-interface \
    DVM_INVENTORY_HISTORY_BROWSER:native-inventory-history-interface \
    DVM_INVENTORY_LIFECYCLE_BROWSER:native-inventory-lifecycle-interface \
    DVM_INVENTORY_INVOICE_FILES_BROWSER:native-inventory-invoice-files-interface \
    DVM_INVENTORY_ACCOUNTING_BROWSER:native-inventory-accounting-interface \
    DVM_INVENTORY_RECOGNITION_BROWSER:native-inventory-recognition-interface \
    DVM_INVENTORY_LINKS_BROWSER:native-inventory-links-interface \
    DVM_REIMBURSEMENT_BROWSER:native-reimbursement-interface \
    DVM_REIMBURSEMENT_CORRECTIONS_BROWSER:native-reimbursement-corrections \
    DVM_REIMBURSEMENT_BANK_SOURCE_BROWSER:native-reimbursement-bank-source \
    DVM_REIMBURSEMENT_RETURNS_BROWSER:native-reimbursement-returns \
    DVM_ANNUAL_TAX_BROWSER:native-annual-tax-interface \
    DVM_TAX_AUTO_PACKAGE_BROWSER:native-tax-auto-package-interface \
    DVM_TAX_AUTO_DRAFT_BROWSER:native-tax-auto-draft-interface \
    DVM_TAX_FILING_BROWSER:native-tax-filing-interface \
    DVM_BALANCE_FILING_BROWSER:native-balance-filing-interface \
    DVM_BALANCE_SPECIALIST_BROWSER:native-balance-filing-interface \
    DVM_BALANCE_RESERVE_BROWSER:native-balance-filing-interface \
    DVM_BALANCE_EQUALIZATION_BROWSER:native-balance-filing-interface \
    DVM_BALANCE_SPECIAL7G_BROWSER:native-balance-filing-interface \
    DVM_BALANCE_BASIS7G_BROWSER:native-balance-filing-interface \
    DVM_BALANCE_BASIS6B_BROWSER:native-balance-filing-interface \
    DVM_BALANCE_SPECIAL7B_BROWSER:native-balance-filing-interface \
    DVM_BALANCE_PENSION_BROWSER:native-balance-filing-interface \
    DVM_BALANCE_OWN_BROWSER:native-balance-filing-interface \
    DVM_BALANCE_POOL_BROWSER:native-balance-filing-interface \
    DVM_BALANCE_CORRECTION_BROWSER:native-balance-correction-interface \
    DVM_BALANCE_PERIOD_CORRECTION_BROWSER:native-balance-period-correction-interface \
    DVM_BALANCE_SEGMENT_BROWSER:native-balance-segment-interface \
    DVM_BALANCE_CLEARING_BROWSER:native-balance-clearing-interface \
    DVM_BALANCE_TAX_RECONCILIATION_BROWSER:native-balance-tax-reconciliation-interface \
    DVM_BALANCE_PERIOD_AREA_BROWSER:native-balance-period-area-interface \
    DVM_BALANCE_PERIOD_TAX_BROWSER:native-balance-period-tax-interface \
    DVM_FINANCE_ORIGINAL_RECOVERY_BROWSER:native-finance-original-recovery \
    DVM_BALANCE_PERIOD_JOURNAL_BROWSER:native-balance-period-journal-interface \
    DVM_BALANCE_PERIOD_SOURCE_BROWSER:native-balance-period-source-interface \
    DVM_BALANCE_OWN_MOVEMENT_BROWSER:native-balance-own-movement-interface \
    DVM_BALANCE_SOURCE_DECISION_BROWSER:native-balance-source-decision-interface \
    DVM_BALANCE_ANNUAL_SOURCE_BROWSER:native-balance-annual-source-interface \
    DVM_BALANCE_ANNUAL_CATALOGUE_BROWSER:native-balance-annual-catalogue-interface \
    DVM_BALANCE_ANNUAL_JOURNAL_BROWSER:native-balance-annual-journal-interface \
    DVM_BALANCE_ANNUAL_CLOSING_BROWSER:native-balance-annual-closing-interface \
    DVM_BALANCE_ANNUAL_SETUP_BROWSER:native-balance-annual-setup-interface \
    DVM_BALANCE_ANNUAL_MAPPING_BROWSER:native-balance-annual-mapping-interface \
    DVM_BALANCE_ANNUAL_SCREEN_BROWSER:native-balance-annual-screen-interface \
    DVM_BALANCE_PERIOD_SCREEN_BROWSER:native-balance-period-screen-interface \
    DVM_INVENTORY_MOVEMENTS_BROWSER:native-inventory-movements \
    DVM_SEPA_ORDER_CATALOGUE_BROWSER:native-sepa-order-catalogue \
    DVM_SEPA_CANDIDATE_PICKER_BROWSER:native-sepa-candidate-picker \
    DVM_GOODS_CATALOGUE_BROWSER:native-goods-catalogue \
    DVM_FINANCE_CATALOGUE_BROWSER:native-finance-catalogue \
    DVM_INVENTORY_FINANCIAL_SOURCES_BROWSER:native-inventory-financial-sources \
    DVM_INVENTORY_ITEM_CATALOGUE_BROWSER:native-inventory-item-catalogue \
    DVM_INVENTORY_LOAN_CATALOGUE_BROWSER:native-inventory-loan-catalogue \
    DVM_INVENTORY_ASSETS_CATALOGUE_BROWSER:native-inventory-assets-catalogue \
    DVM_INVENTORY_WHERE_CATALOGUE_BROWSER:native-inventory-where-catalogue \
    DVM_INVENTORY_LOAN_DRAFT_BROWSER:native-inventory-loan-draft \
    DVM_INVENTORY_LOAN_STOCK_PICKER_BROWSER:native-inventory-loan-stock-picker \
    DVM_SOCIAL_COMMENT_STATE_BROWSER:native-social-comment-state \
    DVM_COMMENT_MODERATION_BROWSER:native-comment-moderation \
    DVM_CATALOG_PRICING_BROWSER:native-catalog-pricing \
    DVM_CATALOG_BILLING_BROWSER:native-catalog-billing \
    DVM_CATALOG_AUTOMATION_BROWSER:native-catalog-automation \
    DVM_ASSIGNMENT_PRICES_BROWSER:native-assignment-prices \
    DVM_ROLE_ADMINISTRATION_BROWSER:native-role-administration \
    DVM_APPLICATION_CATALOGUE_BROWSER:native-application-catalogue \
    DVM_ADMISSION_CHOICES_BROWSER:native-admission-choices \
    DVM_EDITORIAL_READING_BROWSER:native-editorial-reading \
    DVM_EDITORIAL_POLICY_BROWSER:native-editorial-policy \
    DVM_EDITORIAL_CHANNELS_BROWSER:native-editorial-channels \
    DVM_STANDARD_PAGES_BROWSER:native-standard-pages \
    DVM_WEBSITE_LAYOUTS_BROWSER:native-website-layouts \
    DVM_PUBLIC_MENU_BROWSER:native-website-layouts \
    DVM_EVENT_PROGRAMME_BROWSER:native-event-programme \
    DVM_WORKER_HISTORY_BROWSER:native-worker-history \
    DVM_POST_CORRECTIONS_BROWSER:native-post-corrections \
    DVM_MANAGER_CONTROLS_BROWSER:native-manager-controls \
    DVM_BOARD_ADMINISTRATION_BROWSER:native-board-administration \
    DVM_INVOICE_CATALOGUE_BROWSER:native-invoice-catalogue \
    DVM_INVOICE_INTAKE_REVIEW_BROWSER:native-invoice-intake-review \
    DVM_INVOICE_INTAKE_CATALOGUE_BROWSER:native-invoice-intake-catalogue \
    DVM_DOCUMENTS_DETAIL_BROWSER:native-documents-detail \
    DVM_DOCUMENT_LIBRARY_BROWSER:native-document-library \
    DVM_GALLERY_ORIGINALS_BROWSER:native-gallery-originals \
    DVM_SOCIAL_HUMAN_SENDS_BROWSER:native-social-human-sends \
    DVM_SOCIAL_CHECK_PLAN_BROWSER:native-social-check-plan-requests \
    DVM_EVENT_SOURCE_BROWSER:native-event-draft-requests \
    DVM_META_WEBHOOK_BROWSER:native-meta-webhooks \
    DVM_ACCOUNT_BROWSER:native-account-panels \
    DVM_ACCOUNT_SESSIONS_BROWSER:native-account-panels \
    DVM_PUBLIC_ALIAS_BROWSER:native-public-aliases \
    DVM_CONTACT_LINK_BROWSER:native-contact-links \
    DVM_MEDIA_REFERENCE_BROWSER:native-media-references \
    DVM_MEDIA_REMOVAL_BROWSER:native-media-removal-ui \
    DVM_MEDIA_RETENTION_BROWSER:native-media-retention \
    DVM_MEDIA_PURGE_BROWSER:native-media-purge-ui \
    DVM_MEDIA_STORAGE_BROWSER:native-media-storage-ui \
    DVM_DOCUMENT_QUOTA_BROWSER:native-document-quota-ui \
    DVM_PHOTO_SOFT_CLONES_BROWSER:native-photo-soft-clones \
    DVM_PHOTO_CLONE_PAINTER_BROWSER:native-photo-clone-painter \
    DVM_PHOTO_HEALING_BROWSER:native-photo-healing \
    DVM_EVENT_WORKFLOW_BROWSER:native-facebook-events \
    DVM_TICKET_POS_BROWSER:native-ticket-voucher-pos \
    DVM_TICKET_REFUND_BROWSER:native-ticket-refunds \
    DVM_VIDEO_RECOVERY_REQUEST_BROWSER:native-video-recovery-requests \
    DVM_VIDEO_REUSE_REQUEST_BROWSER:native-video-reuse-requests \
    DVM_VIDEO_FILE_BROWSER:native-video-removal-requests \
    DVM_VIDEO_ARCHIVE_SAVE_BROWSER:native-video-archive-save-requests \
    DVM_VIDEO_POLICY_SAVE_BROWSER:native-video-policy-save-requests \
    DVM_VIDEO_METADATA_SAVE_BROWSER:native-video-metadata-save-requests \
    DVM_VIDEO_SAVE_BROWSER:native-video-save-requests \
    DVM_VIDEO_BROWSER:native-videos \
    DVM_VIDEO_APPROVAL_BROWSER:native-video-approval \
    DVM_VIDEO_METADATA_BROWSER:native-video-metadata \
    DVM_VIDEO_QUOTA_BROWSER:native-video-quota \
    DVM_PRESS_RECIPIENTS_BROWSER:native-press-recipients \
    DVM_PRESS_DELIVERY_BROWSER:native-press-delivery \
    DVM_UPGRADE_BROWSER:native-package-upgrade \
    DVM_NEWSLETTER_CONTACTS_BROWSER:native-newsletter-contacts \
    DVM_VIDEO_ARCHIVE_BROWSER:native-video-archive \
    DVM_VIDEO_REMOVAL_BROWSER:native-video-removal \
    DVM_VIDEO_STORAGE_BROWSER:native-video-storage-inventory \
    DVM_VIDEO_RECOVERY_BROWSER:native-video-recovery-ui \
    DVM_VIDEO_REUSE_BROWSER:native-video-reuse \
    DVM_CAROUSEL_BROWSER:native-carousel-ui \
    DVM_SOCIAL_MONTH_BROWSER:native-social-month \
    DVM_EDITORIAL_HUB_BROWSER:native-editorial-hub \
    DVM_SOCIAL_LIBRARY_BROWSER:native-social-library \
    DVM_REEL_STUDIO_BROWSER:native-reel-studio \
    DVM_REEL_AUDIO_BROWSER:native-reel-audio \
    DVM_MEDIA_RECOVERY_BROWSER:native-media-storage-recovery \
    DVM_RECOVERY_CATALOGUE_BROWSER:native-media-recovery-catalogue; do
    if [[ "$task_case" == DVM_RASTER_PHOTOS_BROWSER:native-raster-photo-fallback ]]; then
      env DVM_RASTER_PHOTOS_BROWSER=1 DVM_RASTER_WEBSPACE_HTTP=1 php vereinsverwaltung/tests/ci-native.php native-raster-photo-fallback
    elif [[ "$task_case" == DVM_K_LEGACY_BROWSER:native-k-browser ]]; then
      env -u DVM_K_BROWSER_CASE DVM_K_LEGACY_BROWSER=1 php vereinsverwaltung/tests/ci-native.php native-k-browser
    else
      env "${task_case%%:*}=1" php vereinsverwaltung/tests/ci-native.php "${task_case#*:}"
    fi
  done
  DVM_INSTALLER_BROWSER=1 DVM_PLAYWRIGHT_MODULE="$PLAYWRIGHT_MODULE" DVM_CHROMIUM="$PLAYWRIGHT_CHROMIUM_EXECUTABLE" php vereinsverwaltung/tests/ci-native.php native-webspace-installer
fi
if [[ "$task_mode" == --package || "$task_mode" == --all ]]; then
  python3 distribution/test-source-manifest.py
  python3 distribution/test-package-store.py
  python3 vereinsverwaltung/tests/materialize-eric-sdk-test.py
  python3 distribution/tests/runtime-tools.py
  python3 distribution/build-source-manifest.py --check
  php distribution/install.php --verify
fi
