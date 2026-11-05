<?php declare(strict_types=1);
/** Generated schema83 CHECK recovery for review; no connection, DDL, or application side effects.
 * table => [constraint name => native MySQL 8.4 CHECK expression].
 * 177 source rules; consult native-check-manifest.json for provenance and baseline presence.
 * Add with ALTER TABLE ... ADD CONSTRAINT ... CHECK (...); never duplicate existing named checks.
 */
return array (
  'accounting_positions' => 
  array (
    'kh86_ck_1e47451351d008389776e6db' => '(`ordinal` BETWEEN 1 AND 50)',
    'kh86_ck_7b9a3c6cc133b2c06cf4f300' => '((`gross_cents` > 0) AND (`gross_cents` <= 100000000000))',
    'kh86_ck_edd73735adb78f21e5a38f86' => '(`tax_rate` IN (0, 7, 19))',
    'kh86_ck_72522888850e4cbecfa1da1b' => '((`tax_cents` >= 0) AND (`tax_cents` <= `gross_cents`))',
    'kh86_ck_7dac600d5d80e0bbec6b47e1' => '(CAST(`sphere` AS BINARY) IN (\'\', \'1\', \'2\', \'3\', \'4\', \'9\'))',
    'kh86_ck_a2a57981ad68b1ea94d931f3' => '(CAST(`input_tax` AS BINARY) IN (\'unknown\', \'none\', \'deductible\'))',
    'kh86_ck_7ac2cbe701c0de714eec6146' => '((CAST(`entry_id` AS BINARY) IS NULL) != (CAST(`goods_id` AS BINARY) IS NULL))',
    'kh86_ck_fe0d517d631e7e952b99edf3' => '((CAST(`goods_id` AS BINARY) IS NULL) OR (`tax_rate` = 0))',
    'kh86_ck_4bd8e4d25482402a39efc4c8' => '(((CAST(`account_code` AS BINARY) = \'\') AND (`catalog_year` IS NULL)) OR (((CAST(`sphere` AS BINARY) != \'\') AND (CHAR_LENGTH(SUBSTRING_INDEX(`account_code`, CHAR(0), 1)) = 5)) AND (`catalog_year` IN (2025, 2026))))',
  ),
  'actor_capabilities' => 
  array (
    'kh86_ck_2628362fba47532f76d1b22d' => '(CAST(`capability` AS BINARY) = \'local_finance\')',
  ),
  'actors' => 
  array (
    'kh86_ck_12b27af11ee0b3c5227890a2' => '(CAST(`role` AS BINARY) IN (\'members\', \'member\', \'editor\', \'treasurer\', \'admin\'))',
    'kh86_ck_f39c39a66730124000e59875' => '(`active` IN (0, 1))',
    'kh86_ck_4b0abd0a2d4a895178335c64' => '(`is_superuser` IN (0, 1))',
    'kh86_ck_86d9fa4afc33c2c5b5d84f47' => '(CAST(`press_access` AS BINARY) IN (\'none\', \'read\', \'write\'))',
    'kh86_ck_dae3ad2246011338cdaddbf1' => '(CAST(`minutes_access` AS BINARY) IN (\'none\', \'read\', \'write\'))',
  ),
  'admission_board' => 
  array (
    'kh86_ck_494691e6513a7ecd6cce9c20' => '(`enabled` IN (0, 1))',
  ),
  'admission_board_checks' => 
  array (
    'kh86_ck_cb947b46de2739317471c04f' => '(`board_version` > 0)',
    'kh86_ck_7ca787c8ea444863007e2155' => '(CAST(`office` AS BINARY) IN (\'1. Vorstand\', \'2. Vorstand\', \'Schriftwart\', \'Kassenwart\'))',
    'kh86_ck_216b69fed2e7573b78c08139' => '(CAST(`checked_at` AS BINARY) <> \'\')',
  ),
  'admission_votes' => 
  array (
    'kh86_ck_1fa132544d01c4dee9d57455' => '(CAST(`choice` AS BINARY) IN (\'yes\', \'no\'))',
  ),
  'application_notifications' => 
  array (
    'kh86_ck_3ce272480bb61c1ad650fc08' => '(CAST(`status` AS BINARY) IN (\'pending\', \'sending\', \'sent\', \'uncertain\', \'skipped\'))',
  ),
  'appointment_attendees' => 
  array (
    'kh86_ck_40a36aaafae37f4e40b99b99' => '(CAST(`response` AS BINARY) IN (\'offen\', \'ja\', \'nein\', \'vielleicht\'))',
  ),
  'appointments' => 
  array (
    'kh86_ck_4bbc113b9d35c762acc14038' => '(CAST(`category` AS BINARY) IN (\'verein\', \'vorstand\', \'kultur\'))',
    'kh86_ck_c5268c6e57a871d8dc10129b' => '(CAST(`activity_state` AS BINARY) IN (\'planned\', \'completed\', \'cancelled\'))',
    'kh86_ck_5308a485e5cf94a32a2a63a8' => '(CAST(`activity_kind` AS BINARY) IN (\'event\', \'assembly\', \'board\', \'other\'))',
    'kh86_ck_2a173656d86c968e9ef767c6' => '(CAST(`ends_at` AS BINARY) > CAST(`starts_at` AS BINARY))',
  ),
  'bank_account_mappings' => 
  array (
    'kh86_ck_3a637106a20c99866513e41a' => '(CAST(`provider` AS BINARY) IN (\'enable\', \'fints\', \'paypal\'))',
    'kh86_ck_a7729a304ab0bf8ae4f545ca' => '(CAST(`environment` AS BINARY) = \'PRODUCTION\')',
  ),
  'bank_import_records' => 
  array (
    'kh86_ck_ff17ebfd86891eb2297190d5' => '(CAST(`state` AS BINARY) IN (\'pending\', \'review\', \'imported\', \'ignored\', \'conflict\'))',
  ),
  'bank_import_runs' => 
  array (
    'kh86_ck_e0eb639aa49991f525b3056c' => '(CAST(`state` AS BINARY) IN (\'fetching\', \'complete\', \'failed\'))',
  ),
  'bank_transaction_sources' => 
  array (
    'kh86_ck_fb98b27b787d34ecf7a83cef' => '(CAST(`kind` AS BINARY) IN (\'imported\', \'reconciled\'))',
  ),
  'calendar_invitation_deliveries' => 
  array (
    'kh86_ck_5afa6a60ee34fa4b672af31a' => '(CAST(`status` AS BINARY) IN (\'pending\', \'sending\', \'sent\', \'uncertain\', \'skipped\'))',
  ),
  'cash_closures' => 
  array (
    'kh86_ck_b2b5499c7cca0a732c272513' => '(`balance_cents` >= 0)',
  ),
  'catalog_assignments' => 
  array (
    'kh86_ck_2df7e7618daad2efdebf7df0' => '((CAST(`ends_on` AS BINARY) IS NULL) OR (CAST(`ends_on` AS BINARY) >= CAST(`starts_on` AS BINARY)))',
  ),
  'club_chat_poll_options' => 
  array (
    'kh86_ck_2d05a3d2559da315bb649bd1' => '(`option_number` BETWEEN 1 AND 10)',
  ),
  'club_chat_poll_votes' => 
  array (
    'kh86_ck_97eb87f58ccc7457c90c8a38' => '(`version` >= 1)',
  ),
  'club_chat_polls' => 
  array (
    'kh86_ck_35c94e0084ae5d1cd14efacd' => '(`version` >= 1)',
  ),
  'club_settings' => 
  array (
    'kh86_ck_bce70356a9b05fbd6f520a19' => '(`version` > 0)',
  ),
  'contacts' => 
  array (
    'kh86_ck_9968cff634b0a156ad61276a' => '(CAST(`kind` AS BINARY) IN (\'person\', \'organisation\'))',
    'kh86_ck_bed18855ce9c4cbf409c6ebf' => '(`version` > 0)',
    'kh86_ck_d9b89078768a352cd0ab21fc' => '(`honorary` IN (0, 1))',
    'kh86_ck_8c2610ea542f914cbf03b08d' => '(CHAR_LENGTH(SUBSTRING_INDEX(`legacy_member_number`, CHAR(0), 1)) <= 80)',
  ),
  'contribution_deliveries' => 
  array (
    'kh86_ck_625c349b45dff7e102fba27c' => '(CAST(`type` AS BINARY) IN (\'invoice\', \'reminder\'))',
    'kh86_ck_ce6d49b53c1c87802de6c2cd' => '(`cycle` >= 0)',
    'kh86_ck_3a632f101d91b1bd3c84fe92' => '(CAST(`status` AS BINARY) IN (\'pending\', \'sending\', \'sent\', \'uncertain\', \'skipped\'))',
  ),
  'contribution_invoices' => 
  array (
    'kh86_ck_b5a06ae2fee8019f86fa7362' => '(CAST(`state` AS BINARY) IN (\'draft\', \'issued\'))',
  ),
  'document_folders' => 
  array (
    'kh86_ck_20e8dc9a63d5603f338d0e22' => '(CAST(`scope` AS BINARY) IN (\'orga\', \'finance\', \'members\', \'inventory\', \'settings\'))',
    'kh86_ck_365d1cc771e402a2ad4a1466' => '(`admission_only` IN (0, 1))',
  ),
  'documents' => 
  array (
    'kh86_ck_38f4986fb491fe6243bcfad2' => '(CAST(`origin` AS BINARY) IN (\'upload\', \'generated\', \'migrated\'))',
    'kh86_ck_7c2bd0b951312174201f573e' => '(CAST(`state` AS BINARY) IN (\'active\', \'archived\'))',
    'kh86_ck_ecc81ec879ea8e9960da1235' => '(`admission_only` IN (0, 1))',
  ),
  'donation_contacts' => 
  array (
    'kh86_ck_06183e2fd4374259ca960ddd' => '(CAST(`source_type` AS BINARY) IN (\'entry\', \'goods\'))',
  ),
  'donation_preparation_status' => 
  array (
    'kh86_ck_c31d70fed3fdccd8a52aac99' => '(CAST(`source_type` AS BINARY) IN (\'entry\', \'goods\'))',
  ),
  'donation_receipt_claims' => 
  array (
    'kh86_ck_2a9db5dfa51cd55c3b4eaae4' => '(CAST(`source_type` AS BINARY) IN (\'payment\', \'goods\'))',
  ),
  'donation_receipt_sources' => 
  array (
    'kh86_ck_69ee91a6554fd67545ed21ab' => '(CAST(`source_type` AS BINARY) IN (\'entry\', \'waiver\'))',
    'kh86_ck_3c68a86e94a4fe9ff137f087' => '(`amount_cents` > 0)',
  ),
  'donation_receipts' => 
  array (
    'kh86_ck_31daada8970eb693ddcdb697' => '(CAST(`kind` AS BINARY) IN (\'money\', \'goods\'))',
  ),
  'donation_waivers' => 
  array (
    'kh86_ck_3fb080527476a29a2eb8a9b1' => '(`amount_cents` > 0)',
    'kh86_ck_1fd15ac13e8e943668490f03' => '(`regular` IN (0, 1))',
  ),
  'einvoice_validations' => 
  array (
    'kh86_ck_ceadd14b5838ab2c4125e7ce' => '(CAST(`state` AS BINARY) IN (\'valid\', \'invalid\'))',
  ),
  'event_gema' => 
  array (
    'kh86_ck_68a6c2dcb2a85bdbbe212d09' => '(CAST(`status` AS BINARY) IN (\'check\', \'not_required\', \'register\', \'registered\', \'requested\', \'exempt\'))',
    'kh86_ck_babc09a2164eafdf9cf5c30c' => '(CAST(`setlist` AS BINARY) IN (\'check\', \'not_required\', \'pending\', \'submitted\'))',
  ),
  'finance_account_settings' => 
  array (
    'kh86_ck_791e8f0bb9b35938dfa89a1c' => '(`active` IN (0, 1))',
    'kh86_ck_7c32d01f9c13c0dab8764449' => '(`fints_read` IN (0, 1))',
    'kh86_ck_054b050278c2c9146163a1fd' => '(`fints_write` IN (0, 1))',
  ),
  'finance_accounts' => 
  array (
    'kh86_ck_19934c6bf96d12ae079dc6ac' => '(CAST(`kind` AS BINARY) IN (\'cash\', \'offline\'))',
    'kh86_ck_c9a0471dbdc5b1009fb684b5' => '(ABS(`opening_cents`) <= 100000000000)',
  ),
  'finance_allocations' => 
  array (
    'kh86_ck_7f44efedcb4041ca4d767340' => '((`cents` > 0) AND (`cents` <= 100000000000))',
    'kh86_ck_dfe757df4f803efb0d481aa0' => '(((CAST(`voided_at` AS BINARY) IS NULL) AND (CAST(`void_reason` AS BINARY) IS NULL)) OR ((CAST(`voided_at` AS BINARY) IS NOT NULL) AND (CAST(`void_reason` AS BINARY) IS NOT NULL)))',
  ),
  'finance_entries' => 
  array (
    'kh86_ck_cb7d48e697ecf2c75e2da07e' => '(CAST(`kind` AS BINARY) IN (\'income\', \'expense\', \'donation\', \'contribution\'))',
    'kh86_ck_f8672d2c26183c364ea42bd7' => '((`amount_cents` > 0) AND (`amount_cents` <= 100000000000))',
    'kh86_ck_008547b70291f5d22b0a6a23' => '(CAST(`status` AS BINARY) IN (\'open\', \'paid\', \'cancelled\'))',
    'kh86_ck_fea5889f985656d5113f4198' => '((((CAST(`status` AS BINARY) = \'paid\') AND (CAST(`paid_on` AS BINARY) IS NOT NULL)) AND (CAST(`account_id` AS BINARY) IS NOT NULL)) OR (((CAST(`status` AS BINARY) != \'paid\') AND (CAST(`paid_on` AS BINARY) IS NULL)) AND (CAST(`account_id` AS BINARY) IS NULL)))',
  ),
  'finance_event_assignments' => 
  array (
    'kh86_ck_8cda6acd1f80fd7a9b05d70e' => '(CAST(`purpose` AS BINARY) IN (\'appointment\', \'artist\', \'gema\'))',
  ),
  'finance_reimbursement_claims' => 
  array (
    'kh86_ck_bc214aacefe0a0be864a5fb1' => '((`amount_cents` > 0) AND (`amount_cents` <= 100000000000))',
    'kh86_ck_b5f5ac20cbfd3a4e9d5ac7f6' => '(CAST(`invoice_recipient` AS BINARY) IN (\'club\', \'member\', \'other\', \'unknown\'))',
    'kh86_ck_a7ea7acc55e8045644076172' => '(CAST(`state` AS BINARY) IN (\'open\', \'approved\', \'paid\', \'waived\', \'cancelled\'))',
  ),
  'finance_transactions' => 
  array (
    'kh86_ck_c574004502b16aadb0bbe8f0' => '((`cents` != 0) AND (ABS(`cents`) <= 100000000000))',
    'kh86_ck_58886e92c6cab1e68a3c03fc' => '(CAST(`source` AS BINARY) IN (\'manual\', \'legacy\', \'reversal\'))',
  ),
  'finance_transfer_orders' => 
  array (
    'kh86_ck_0e456694fece27968583605d' => '(CAST(`state` AS BINARY) IN (\'prepared\', \'approved\', \'exported\', \'submitted\', \'cancelled\'))',
  ),
  'fints_sync_runs' => 
  array (
    'kh86_ck_748937c44f0edadee8ffb09d' => '(CAST(`state` AS BINARY) IN (\'complete\', \'needs_setup\', \'needs_auth\', \'failed\', \'skipped\'))',
  ),
  'fints_sync_settings' => 
  array (
    'kh86_ck_94387ff6a9adbfd868857ca3' => '(`enabled` IN (0, 1))',
    'fints_sync_settings_chk_1' => '(`interval_minutes` = 5)',
  ),
  'free_table_access' => 
  array (
    'kh86_ck_3bad4035b2864bd49d498908' => '(CAST(`access` AS BINARY) IN (\'read\', \'write\'))',
  ),
  'free_table_rows' => 
  array (
    'kh86_ck_a105629f475f3a2e41e235fd' => '(`archived` IN (0, 1))',
  ),
  'free_tables' => 
  array (
    'kh86_ck_21b805939c6a77b9479b2211' => '(`archived` IN (0, 1))',
  ),
  'goods_donations' => 
  array (
    'kh86_ck_d8c5d061092727dfa67aafd5' => '((`value_cents` > 0) AND (`value_cents` <= 100000000000))',
    'kh86_ck_4bb5d05da897449b75c89def' => '(CAST(`origin` AS BINARY) IN (\'private\', \'business\', \'unknown\'))',
    'kh86_ck_d1944eef6e6894e714494ea2' => '(`evidence_present` IN (0, 1))',
  ),
  'inventory_accessories' => 
  array (
    'kh86_ck_ad818941fca99fccf3897e73' => '(CAST(`usage_mode` AS BINARY) IN (\'required\', \'optional\'))',
    'kh86_ck_403a555884af85b5b14f547e' => '(`quantity_per_unit` BETWEEN 1 AND 1000000)',
    'kh86_ck_71019ead995682445b11268d' => '(CAST(`parent_item` AS BINARY) <> CAST(`child_item` AS BINARY))',
  ),
  'inventory_acquisition_pools' => 
  array (
    'kh86_ck_22ddfe6e818399d740d32ce2' => '(`source_ordinal` > 0)',
    'kh86_ck_2d1920e4492f6e3559ca9d95' => '(`gross_cents` > 0)',
  ),
  'inventory_acquisition_sources' => 
  array (
    'kh86_ck_d1677084ca96673a5f4b5a4b' => '(`source_ordinal` > 0)',
    'kh86_ck_7e2de44a07d425d8cc871804' => '(`gross_cents` >= 0)',
    'kh86_ck_b9578f759329996626d0304b' => '((((CAST(`pool_id` AS BINARY) IS NULL) AND (`gross_cents` IS NOT NULL)) AND (`pool_quantity` IS NULL)) OR ((((CAST(`pool_id` AS BINARY) IS NOT NULL) AND (`gross_cents` IS NULL)) AND (`pool_quantity` IS NOT NULL)) AND (`pool_quantity` > 0)))',
  ),
  'inventory_items' => 
  array (
    'kh86_ck_134efd97dd76402725370046' => '(CAST(`kind` AS BINARY) IN (\'reusable\', \'consumable\'))',
    'kh86_ck_54144506540b3aacfe282d1c' => '(`minimum` >= 0)',
    'kh86_ck_a98fb237ea02494a3a2cec2e' => '(`active` IN (0, 1))',
    'kh86_ck_8f5663f7ebed7f86c7aab916' => '(CAST(`ownership` AS BINARY) IN (\'club\', \'third_party\'))',
    'kh86_ck_2a0dda40f7198e40b89556a0' => '((`unit_value_cents` >= 0) AND (`unit_value_cents` <= 100000000000))',
    'kh86_ck_e5ac8b10917bb3c345f8e0d6' => '(CAST(`tracking_mode` AS BINARY) IN (\'quantity\', \'individual\'))',
    'kh86_ck_6312127d77f0543c3b7fdb0a' => '(`content_quantity` BETWEEN 1 AND 1000000)',
    'kh86_ck_7e53821a797f97299e8f6a29' => '((CAST(`source_entry` AS BINARY) IS NULL) OR (CAST(`source_goods` AS BINARY) IS NULL))',
  ),
  'inventory_loan_lines' => 
  array (
    'kh86_ck_593cd80da14f2919407e1ade' => '(`quantity` > 0)',
    'kh86_ck_1dc8645c15d93e1ea0bf91e6' => '((`content_quantity` IS NULL) OR (`content_quantity` BETWEEN 1 AND 1000000))',
    'kh86_ck_7faf9d34bbd405ab709db99b' => '(((`returned` >= 0) AND (`lost` >= 0)) AND ((`returned` + `lost`) <= `quantity`))',
  ),
  'inventory_loans' => 
  array (
    'kh86_ck_e9f36a2a63f36670e2861f87' => '(CAST(`state` AS BINARY) IN (\'reserved\', \'issued\', \'returned\', \'cancelled\'))',
    'kh86_ck_060a419c61d4ae19dd1deaf7' => '(CAST(`due_on` AS BINARY) >= CAST(`starts_on` AS BINARY))',
  ),
  'inventory_locations' => 
  array (
    'kh86_ck_7d4f25f355127daadbc0f552' => '(`active` IN (0, 1))',
  ),
  'inventory_purchase_units' => 
  array (
    'kh86_ck_e697fdcc9df17c4738c2b707' => '((`unit_value_cents` IS NULL) OR (`unit_value_cents` >= 0))',
  ),
  'inventory_stock' => 
  array (
    'kh86_ck_c6b89186374ad1cbd25545f1' => '(((((`shelf` >= 0) AND (`reserved` >= 0)) AND (`reserved` <= `shelf`)) AND (`lent` >= 0)) AND (`blocked` >= 0))',
  ),
  'invoice_approvals' => 
  array (
    'kh86_ck_16414ef7f9db35a718a628db' => '(`office_version` > 0)',
    'kh86_ck_44e5a87c10955e4fce7d0f4e' => '(CAST(`decision` AS BINARY) IN (\'approved\', \'withdrawn\'))',
    'kh86_ck_003ace2a74ba4c84b8ddd716' => '(CHAR_LENGTH(SUBSTRING_INDEX(`snapshot_hash`, CHAR(0), 1)) = 64)',
  ),
  'invoice_mail_only' => 
  array (
    'kh86_ck_ce009b2e07e843f8653fe878' => '(CAST(`state` AS BINARY) IN (\'active\', \'archived\'))',
  ),
  'invoice_payment_reports' => 
  array (
    'kh86_ck_1b2e3fbd9d7cf6a27c1d8048' => '(CAST(`operation` AS BINARY) IN (\'reported\', \'withdrawn\'))',
  ),
  'manager_notifications' => 
  array (
    'kh86_ck_aea63f6ac6b0ad57bac43856' => '(CAST(`topic` AS BINARY) IN (\'invoices\', \'payments\', \'events\', \'documents\', \'contributions\', \'minutes\', \'meetings\'))',
  ),
  'manager_push_deliveries' => 
  array (
    'kh86_ck_8efaade66ab00ce530ccacb8' => '(CAST(`state` AS BINARY) IN (\'pending\', \'sending\', \'sent\', \'cancelled\', \'failed\', \'uncertain\'))',
  ),
  'manual_member_accounts' => 
  array (
    'kh86_ck_1aeef90f61b8ec9c62a3d41c' => '(CAST(`state` AS BINARY) IN (\'prepared\', \'ready\', \'needs_review\', \'needs_address\'))',
    'kh86_ck_d712c7f444c95e4fbe214c1f' => '(`send_mail` IN (0, 1))',
    'kh86_ck_929ff00633f032bbb0db3d54' => '(CAST(`mail_status` AS BINARY) IN (\'pending\', \'disabled\', \'sending\', \'sent\', \'uncertain\'))',
  ),
  'member_bank_config' => 
  array (
    'kh86_ck_f6420f3b630f3b707813bfc2' => '(`sepa_enabled` IN (0, 1))',
    'kh86_ck_3472eb17dd4daf6f21da86e8' => '(`bank_online_mandate_accepted` IN (0, 1))',
  ),
  'member_bank_grants' => 
  array (
    'kh86_ck_76ed61912ae17e8459700a78' => '(CAST(`action` AS BINARY) IN (\'save\', \'mandate\', \'revoke\', \'delete\', \'reveal\'))',
  ),
  'member_bank_records' => 
  array (
    'kh86_ck_4fd9d0cdc79023e9b2239fc1' => '(`version` > 0)',
    'kh86_ck_a9efd3594111d575ff733d0d' => '(CAST(`status` AS BINARY) IN (\'saved\', \'mandated\', \'revoked\', \'removed\'))',
  ),
  'member_change_requests' => 
  array (
    'kh86_ck_89d26196f0f4e8f9cc14ba1a' => '(CAST(`state` AS BINARY) IN (\'pending\', \'accepted\', \'rejected\', \'withdrawn\'))',
    'kh86_ck_b03f8c5d6b4a89847a3f9fc4' => '(`version` >= 1)',
  ),
  'membership_account_onboarding' => 
  array (
    'kh86_ck_8b5ad40a3a2c1e07b684768e' => '(`created_new` IN (0, 1))',
    'kh86_ck_67598f4a1dd70f3f893168fc' => '(`password_change_required` IN (0, 1))',
    'kh86_ck_0cd142cf74296b91cb84d75a' => '(CAST(`state` AS BINARY) IN (\'prepared\', \'ready\'))',
  ),
  'membership_applications' => 
  array (
    'kh86_ck_50c040689a9e2c86cbc08b16' => '(CAST(`status` AS BINARY) IN (\'pending\', \'accepted\', \'rejected\'))',
    'kh86_ck_ee441c29889e637dc8914a87' => '(`access_mail` IN (0, 1))',
  ),
  'membership_contribution_rates' => 
  array (
    'kh86_ck_d0ee8687f58bc3c4353e9b3c' => '(CAST(`membership_type` AS BINARY) IN (\'single\', \'family\'))',
  ),
  'memberships' => 
  array (
    'kh86_ck_7f2458c61a4d69361c1a8cdd' => '(CAST(`status` AS BINARY) IN (\'aktiv\', \'passiv\', \'ehemalig\'))',
    'kh86_ck_c2c4fa32f23a9cd9651573f5' => '(CAST(`starts_precision` AS BINARY) IN (\'day\', \'year\'))',
    'kh86_ck_b443aa9e5d19b021a2938ec0' => '((CAST(`ends_on` AS BINARY) IS NULL) OR (CAST(`ends_on` AS BINARY) >= CAST(`starts_on` AS BINARY)))',
    'kh86_ck_5db972cbac3deac6074cf80c' => '(((CAST(`status` AS BINARY) = \'ehemalig\') AND (CAST(`ends_on` AS BINARY) IS NOT NULL)) OR ((CAST(`status` AS BINARY) != \'ehemalig\') AND (CAST(`ends_on` AS BINARY) IS NULL)))',
  ),
  'minutes' => 
  array (
    'kh86_ck_dccd19d4124d654f400da883' => '(CAST(`source_kind` AS BINARY) IN (\'editor\', \'pdf\'))',
    'kh86_ck_37aeaf3a41b4b46d3ab3c457' => '(CAST(`state` AS BINARY) IN (\'draft\', \'published\', \'archived\'))',
    'kh86_ck_b83d6240ecf3f4939bbabcbd' => '(`version` > 0)',
  ),
  'minutes_files' => 
  array (
    'kh86_ck_156736f572acc0f008610611' => '(CAST(`mime` AS BINARY) = \'application/pdf\')',
  ),
  'number_counters' => 
  array (
    'kh86_ck_abd85c576ef348a30bc48b3e' => '(`next_number` > 0)',
  ),
  'outgoing_invoice_deliveries' => 
  array (
    'kh86_ck_a41e7e42667ae4de025041cf' => '(CAST(`state` AS BINARY) IN (\'pending\', \'sending\', \'sent\', \'uncertain\', \'blocked\', \'cancelled\'))',
  ),
  'outgoing_invoices' => 
  array (
    'kh86_ck_e78e552442487655d8a10a56' => '(CAST(`kind` AS BINARY) IN (\'invoice\', \'cancellation\', \'credit\'))',
    'kh86_ck_ec6810a9b7135287958f4fed' => '(CAST(`state` AS BINARY) IN (\'draft\', \'reviewed\', \'issued\', \'cancelled\'))',
  ),
  'processwire_identities' => 
  array (
    'kh86_ck_1259262f07d865f054e316ed' => '(`pw_user_id` > 0)',
    'kh86_ck_ed82a43971e397fd3a2697b4' => '(`enabled` IN (0, 1))',
    'kh86_ck_1834ce69e8cf122d59fea371' => '(`version` > 0)',
  ),
  'sepa_order_items' => 
  array (
    'kh86_ck_a3fc89beef392df36b720d76' => '(`cents` > 0)',
    'kh86_ck_dca6a5ea498c643e3e8a9ad0' => '(CAST(`state` AS BINARY) IN (\'active\', \'cancelled\', \'returned\'))',
  ),
  'sepa_orders' => 
  array (
    'kh86_ck_3841ce7bc5b2688b22fc3de8' => '(CAST(`state` AS BINARY) IN (\'prepared\', \'approved\', \'exported\', \'submitted\', \'cancelled\'))',
  ),
  'settings_catalog' => 
  array (
    'kh86_ck_43f6b5e562b5551ac39f3c59' => '(CAST(`kind` AS BINARY) IN (\'contribution\', \'donation\', \'service\'))',
    'kh86_ck_e0122a63a260ff116ddae813' => '(`version` > 0)',
    'kh86_ck_b09e74ea28cc52bf1a56e925' => '(`active` IN (0, 1))',
  ),
  'settings_roles' => 
  array (
    'kh86_ck_15bb05b808336e2b41425944' => '(CAST(`members_access` AS BINARY) IN (\'none\', \'read\', \'write\'))',
    'kh86_ck_a407b589a936a0319973351a' => '(CAST(`finance_access` AS BINARY) IN (\'none\', \'read\', \'write\'))',
    'kh86_ck_64df997ce824a3bd49bf1726' => '(`settings_access` IN (0, 1))',
    'kh86_ck_02e602781cfcf27e60f322c7' => '(CAST(`inventory_access` AS BINARY) IN (\'none\', \'read\', \'write\'))',
    'kh86_ck_1cd5454edca8b9fcd1b5ed3a' => '(CAST(`loans_access` AS BINARY) IN (\'none\', \'read\', \'write\'))',
    'kh86_ck_c274a96bd887a70b90157c32' => '(CAST(`documents_access` AS BINARY) IN (\'none\', \'read\', \'write\'))',
    'kh86_ck_6439b2779252cd75abd8358d' => '(CAST(`press_access` AS BINARY) IN (\'none\', \'read\', \'write\'))',
    'kh86_ck_1118993c019c2c539c325f9f' => '(CAST(`minutes_access` AS BINARY) IN (\'none\', \'read\', \'write\'))',
  ),
  'setup_acknowledgements' => 
  array (
    'kh86_ck_6890ca9153e383f51b20b0db' => '(CAST(`step` AS BINARY) = \'intro\')',
  ),
  'supplier_contact_requests' => 
  array (
    'kh86_ck_e9f71bee8bcd22334997657e' => '(CHAR_LENGTH(SUBSTRING_INDEX(`request_key`, CHAR(0), 1)) = 32)',
    'kh86_ck_28eef445a6439c5502ae30f1' => '(CHAR_LENGTH(SUBSTRING_INDEX(`request_hash`, CHAR(0), 1)) = 64)',
  ),
  'tax_account_settings' => 
  array (
    'kh86_ck_a36c8044657b0276e894f7b1' => '(`year` = 2026)',
    'kh86_ck_8802a0628f9fd5f1be70a7a6' => '((CHAR_LENGTH(SUBSTRING_INDEX(`code`, CHAR(0), 1)) = 5) AND (NOT REGEXP_LIKE(SUBSTRING_INDEX(`code`, CHAR(0), 1), \'[^0-9]\', \'c\')))',
    'kh86_ck_9cfeabfe525508cd03112f80' => '(`active` IN (0, 1))',
    'kh86_ck_6d86ab6d32be5c33bfc05678' => '(`custom` IN (0, 1))',
  ),
  'website_drafts' => 
  array (
    'kh86_ck_3c984138534ba842e6f12be0' => '(CAST(`kind` AS BINARY) IN (\'page\', \'news\'))',
    'kh86_ck_5a41a11a05f48ed05950850b' => '(CAST(`status` AS BINARY) IN (\'draft\', \'review\', \'archived\'))',
    'kh86_ck_37f20a8905b465777fb47256' => '(`version` > 0)',
    'kh86_ck_aace2bd9d697f850e4fc97a7' => '(((CAST(`kind` AS BINARY) = \'page\') AND (CAST(`page_key` AS BINARY) IS NOT NULL)) OR ((CAST(`kind` AS BINARY) = \'news\') AND (CAST(`page_key` AS BINARY) IS NULL)))',
  ),
  'website_event_publications' => 
  array (
    'kh86_ck_57fba4565f142d3b3582aa09' => '(`published_seen` IN (0, 1))',
  ),
);
