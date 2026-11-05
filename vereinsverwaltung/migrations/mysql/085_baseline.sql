-- Native MySQL baseline 85. Structure only; guards are installed separately.
SET FOREIGN_KEY_CHECKS=0;
CREATE TABLE `accounting_positions` (
  `id` int NOT NULL AUTO_INCREMENT,
  `club_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `entry_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `goods_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ordinal` int NOT NULL,
  `description` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `gross_cents` bigint NOT NULL,
  `tax_rate` int NOT NULL,
  `tax_cents` bigint NOT NULL,
  `sphere` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `account_code` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT (_utf8mb4''),
  `account_name` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT (_utf8mb4''),
  `account_category` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT (_utf8mb4''),
  `catalog_year` int DEFAULT NULL,
  `booking_key` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT (_utf8mb4''),
  `booking_key_snapshot` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT (_utf8mb4''),
  `input_tax` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT (_utf8mb4'unknown'),
  PRIMARY KEY (`id`),
  UNIQUE KEY `club_id` (`club_id`,`entry_id`,`ordinal`),
  UNIQUE KEY `club_id_2` (`club_id`,`goods_id`,`ordinal`),
  CONSTRAINT `accounting_positions_ibfk_1` FOREIGN KEY (`club_id`, `entry_id`) REFERENCES `finance_entries` (`club_id`, `id`),
  CONSTRAINT `accounting_positions_ibfk_2` FOREIGN KEY (`club_id`, `goods_id`) REFERENCES `goods_donations` (`club_id`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `accounting_revisions` (
  `id` int NOT NULL AUTO_INCREMENT,
  `club_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `object_type` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `object_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `version` int NOT NULL,
  `previous_positions` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `next_positions` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `reason` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `actor_id` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `happened_at` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `club_id` (`club_id`,`object_type`,`object_id`,`version`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `actor_capabilities` (
  `actor_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `capability` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`actor_id`,`capability`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `actors` (
  `id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `club_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `role` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `contact_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `active` int NOT NULL DEFAULT '1',
  `is_superuser` int NOT NULL DEFAULT '0',
  `press_access` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT (_utf8mb4'none'),
  `minutes_access` mediumtext COLLATE utf8mb4_unicode_ci,
  PRIMARY KEY (`id`),
  KEY `club_id` (`club_id`,`contact_id`),
  CONSTRAINT `actors_ibfk_1` FOREIGN KEY (`club_id`, `contact_id`) REFERENCES `contacts` (`club_id`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `admission_board` (
  `club_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `actor_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `office` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `enabled` int NOT NULL,
  `version` int NOT NULL,
  PRIMARY KEY (`club_id`,`actor_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `admission_board_checks` (
  `club_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `actor_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `board_version` int NOT NULL,
  `office` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `checked_by` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `checked_at` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`club_id`,`actor_id`,`board_version`),
  CONSTRAINT `admission_board_checks_ibfk_1` FOREIGN KEY (`club_id`, `actor_id`) REFERENCES `admission_board` (`club_id`, `actor_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `admission_rounds` (
  `club_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `application_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `roster` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `roster_hash` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `opened_at` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `opened_by` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`club_id`,`id`),
  UNIQUE KEY `club_id` (`club_id`,`application_id`),
  CONSTRAINT `admission_rounds_ibfk_1` FOREIGN KEY (`club_id`, `application_id`) REFERENCES `membership_applications` (`club_id`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `admission_votes` (
  `club_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `round_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `actor_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `choice` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `reason` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `declaration` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `voted_at` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`club_id`,`round_id`,`actor_id`),
  CONSTRAINT `admission_votes_ibfk_1` FOREIGN KEY (`club_id`, `round_id`) REFERENCES `admission_rounds` (`club_id`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `anniversary_settings` (
  `club_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `rules` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `version` int NOT NULL DEFAULT '1',
  PRIMARY KEY (`club_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `application_confirmations` (
  `id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `club_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `request_key` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `payload` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `configuration_version` int NOT NULL,
  `consent` int NOT NULL,
  `newsletter` int NOT NULL DEFAULT '0',
  `token_hash` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `mail_token` mediumtext COLLATE utf8mb4_unicode_ci,
  `created_at` int NOT NULL,
  `expires_at` int NOT NULL,
  `confirmed_at` int DEFAULT NULL,
  `application_id` mediumtext COLLATE utf8mb4_unicode_ci,
  `mail_status` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT (_utf8mb4'pending'),
  `newsletter_status` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT (_utf8mb4'waiting'),
  PRIMARY KEY (`id`),
  UNIQUE KEY `token_hash` (`token_hash`),
  UNIQUE KEY `club_id` (`club_id`,`request_key`),
  KEY `application_confirmation_email` (`club_id`,`email`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `application_notifications` (
  `club_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `application_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `attempted_at` mediumtext COLLATE utf8mb4_unicode_ci,
  `sent_at` mediumtext COLLATE utf8mb4_unicode_ci,
  PRIMARY KEY (`club_id`,`application_id`,`email`),
  CONSTRAINT `application_notifications_ibfk_1` FOREIGN KEY (`club_id`, `application_id`) REFERENCES `membership_applications` (`club_id`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `application_settings` (
  `club_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `version` int NOT NULL,
  `configuration` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `updated_by` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `updated_at` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`club_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `appointment_attendees` (
  `club_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `appointment_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `contact_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `response` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT (_utf8mb4'offen'),
  PRIMARY KEY (`club_id`,`appointment_id`,`contact_id`),
  KEY `club_id` (`club_id`,`contact_id`),
  CONSTRAINT `appointment_attendees_ibfk_1` FOREIGN KEY (`club_id`, `appointment_id`) REFERENCES `appointments` (`club_id`, `id`) ON DELETE CASCADE,
  CONSTRAINT `appointment_attendees_ibfk_2` FOREIGN KEY (`club_id`, `contact_id`) REFERENCES `contacts` (`club_id`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `appointments` (
  `id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `club_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `title` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `starts_at` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `ends_at` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `location` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT (_utf8mb4''),
  `description` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT (_utf8mb4''),
  `category` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `version` int NOT NULL DEFAULT '1',
  `activity_state` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT (_utf8mb4'planned'),
  `activity_kind` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT (_utf8mb4'event'),
  `report_summary` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT (_utf8mb4''),
  `source_reference` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  PRIMARY KEY (`club_id`,`id`),
  KEY `appointments_start` (`club_id`,`starts_at`),
  KEY `appointment_source_reference` (`club_id`,`source_reference`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `asset_book_history` (
  `club_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `item_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `version` int NOT NULL,
  `actor_id` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `happened_at` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `reason` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `snapshot` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`club_id`,`item_id`,`version`),
  CONSTRAINT `asset_book_history_ibfk_1` FOREIGN KEY (`club_id`, `item_id`) REFERENCES `asset_book_plans` (`club_id`, `item_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `asset_book_plans` (
  `club_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `item_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `version` int NOT NULL DEFAULT '1',
  `payload` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_by` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `updated_at` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`club_id`,`item_id`),
  CONSTRAINT `asset_book_plans_ibfk_1` FOREIGN KEY (`club_id`, `item_id`) REFERENCES `inventory_items` (`club_id`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `asset_book_years` (
  `club_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `item_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `year` int NOT NULL,
  `plan_version` int NOT NULL,
  `opening_cents` bigint NOT NULL,
  `depreciation_cents` bigint NOT NULL,
  `closing_cents` bigint NOT NULL,
  `actor_id` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `recorded_at` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `snapshot` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`club_id`,`item_id`,`year`),
  CONSTRAINT `asset_book_years_ibfk_1` FOREIGN KEY (`club_id`, `item_id`) REFERENCES `asset_book_plans` (`club_id`, `item_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `assigned_numbers` (
  `club_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `namespace` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `value` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `object_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`club_id`,`namespace`,`value`),
  UNIQUE KEY `club_id` (`club_id`,`namespace`,`object_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `audit_events` (
  `sequence` int NOT NULL AUTO_INCREMENT,
  `club_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `actor_id` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `action` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `object_id` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `object_version` int NOT NULL,
  `happened_at` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`sequence`),
  KEY `audit_club` (`club_id`,`sequence`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `bank_account_mappings` (
  `club_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `provider` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `environment` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `identity_hash` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `account_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `label` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_by` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`club_id`,`id`),
  UNIQUE KEY `club_id` (`club_id`,`provider`,`identity_hash`),
  KEY `club_id_2` (`club_id`,`account_id`),
  CONSTRAINT `bank_account_mappings_ibfk_1` FOREIGN KEY (`club_id`, `account_id`) REFERENCES `finance_accounts` (`club_id`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `bank_import_decisions` (
  `club_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `record_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `actor_id` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `decision` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `reason` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `record_version` int NOT NULL,
  `created_at` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`club_id`,`id`),
  KEY `club_id` (`club_id`,`record_id`),
  CONSTRAINT `bank_import_decisions_ibfk_1` FOREIGN KEY (`club_id`, `record_id`) REFERENCES `bank_import_records` (`club_id`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `bank_import_observations` (
  `club_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `run_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `record_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `ordinal` int NOT NULL,
  `payload` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `sha256` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`club_id`,`run_id`,`ordinal`),
  KEY `club_id` (`club_id`,`record_id`),
  CONSTRAINT `bank_import_observations_ibfk_1` FOREIGN KEY (`club_id`, `run_id`) REFERENCES `bank_import_runs` (`club_id`, `id`),
  CONSTRAINT `bank_import_observations_ibfk_2` FOREIGN KEY (`club_id`, `record_id`) REFERENCES `bank_import_records` (`club_id`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `bank_import_records` (
  `club_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `mapping_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `identity` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `reference` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `bank_status` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `booked_on` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `currency` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `cents` bigint NOT NULL,
  `counterparty` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `content_hash` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `state` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `reason` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `first_seen` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `last_seen` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `version` int NOT NULL DEFAULT '1',
  PRIMARY KEY (`club_id`,`id`),
  UNIQUE KEY `club_id` (`club_id`,`mapping_id`,`identity`),
  KEY `bank_import_review` (`club_id`,`state`,`last_seen`),
  CONSTRAINT `bank_import_records_ibfk_1` FOREIGN KEY (`club_id`, `mapping_id`) REFERENCES `bank_account_mappings` (`club_id`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `bank_import_runs` (
  `club_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `mapping_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `actor_id` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `request_key` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `from_date` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `to_date` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `state` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `started_at` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `finished_at` mediumtext COLLATE utf8mb4_unicode_ci,
  `rows_count` int NOT NULL DEFAULT '0',
  `new_count` int NOT NULL DEFAULT '0',
  `review_count` int NOT NULL DEFAULT '0',
  `error_code` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT (_utf8mb4''),
  PRIMARY KEY (`club_id`,`id`),
  UNIQUE KEY `club_id` (`club_id`,`request_key`),
  KEY `club_id_2` (`club_id`,`mapping_id`),
  CONSTRAINT `bank_import_runs_ibfk_1` FOREIGN KEY (`club_id`, `mapping_id`) REFERENCES `bank_account_mappings` (`club_id`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `bank_transaction_sources` (
  `club_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `record_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `transaction_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `kind` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `linked_by` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `linked_at` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`club_id`,`record_id`),
  KEY `club_id` (`club_id`,`transaction_id`),
  CONSTRAINT `bank_transaction_sources_ibfk_1` FOREIGN KEY (`club_id`, `record_id`) REFERENCES `bank_import_records` (`club_id`, `id`),
  CONSTRAINT `bank_transaction_sources_ibfk_2` FOREIGN KEY (`club_id`, `transaction_id`) REFERENCES `finance_transactions` (`club_id`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `calendar_invitation_deliveries` (
  `club_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `invitation_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `event_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `content_hash` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT (_utf8mb4'pending'),
  `attempted_at` mediumtext COLLATE utf8mb4_unicode_ci,
  `sent_at` mediumtext COLLATE utf8mb4_unicode_ci,
  `body` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT (_utf8mb4''),
  PRIMARY KEY (`club_id`,`event_id`,`content_hash`,`email`),
  KEY `club_id` (`club_id`,`invitation_id`),
  CONSTRAINT `calendar_invitation_deliveries_ibfk_1` FOREIGN KEY (`club_id`, `invitation_id`) REFERENCES `calendar_invitations` (`club_id`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `calendar_invitation_targets` (
  `club_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `invitation_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `contact_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT (_utf8mb4''),
  `name` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `event_fingerprint` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT (_utf8mb4''),
  PRIMARY KEY (`club_id`,`invitation_id`,`contact_id`),
  KEY `club_id` (`club_id`,`contact_id`),
  CONSTRAINT `calendar_invitation_targets_ibfk_1` FOREIGN KEY (`club_id`, `invitation_id`) REFERENCES `calendar_invitations` (`club_id`, `id`),
  CONSTRAINT `calendar_invitation_targets_ibfk_2` FOREIGN KEY (`club_id`, `contact_id`) REFERENCES `contacts` (`club_id`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `calendar_invitations` (
  `club_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `request_key` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `actor_id` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `event_id` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `content_hash` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `preview_hash` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `subject` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `body` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`club_id`,`id`),
  UNIQUE KEY `club_id` (`club_id`,`request_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `calendar_prints` (
  `club_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `actor_id` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `event_id` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `request_key` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `snapshot` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `pdf` blob NOT NULL,
  `created_at` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `posted_at` mediumtext COLLATE utf8mb4_unicode_ci,
  PRIMARY KEY (`club_id`,`id`),
  UNIQUE KEY `club_id` (`club_id`,`request_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `cash_closures` (
  `id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `club_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `account_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `closed_through` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `balance_cents` bigint NOT NULL,
  PRIMARY KEY (`club_id`,`id`),
  UNIQUE KEY `club_id` (`club_id`,`account_id`,`closed_through`),
  CONSTRAINT `cash_closures_ibfk_1` FOREIGN KEY (`club_id`, `account_id`) REFERENCES `finance_accounts` (`club_id`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `catalog_assignments` (
  `club_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `catalog_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `contact_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `starts_on` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `ends_on` mediumtext COLLATE utf8mb4_unicode_ci,
  `version` int NOT NULL DEFAULT '1',
  PRIMARY KEY (`club_id`,`id`),
  KEY `club_id` (`club_id`,`catalog_id`),
  KEY `club_id_2` (`club_id`,`contact_id`),
  CONSTRAINT `catalog_assignments_ibfk_1` FOREIGN KEY (`club_id`, `catalog_id`) REFERENCES `settings_catalog` (`club_id`, `id`),
  CONSTRAINT `catalog_assignments_ibfk_2` FOREIGN KEY (`club_id`, `contact_id`) REFERENCES `contacts` (`club_id`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `club_chat_messages` (
  `seq` int NOT NULL AUTO_INCREMENT,
  `chat_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `actor_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `body` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `request_key` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `file_name` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `file_mime` mediumtext COLLATE utf8mb4_unicode_ci,
  `file_bytes` mediumblob,
  PRIMARY KEY (`seq`),
  UNIQUE KEY `request_key` (`request_key`),
  KEY `chat_id` (`chat_id`),
  KEY `actor_id` (`actor_id`),
  CONSTRAINT `club_chat_messages_ibfk_1` FOREIGN KEY (`chat_id`) REFERENCES `club_chats` (`id`),
  CONSTRAINT `club_chat_messages_ibfk_2` FOREIGN KEY (`actor_id`) REFERENCES `actors` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `club_chat_people` (
  `chat_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `actor_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `last_read` int NOT NULL DEFAULT '0',
  PRIMARY KEY (`chat_id`,`actor_id`),
  KEY `actor_id` (`actor_id`),
  CONSTRAINT `club_chat_people_ibfk_1` FOREIGN KEY (`chat_id`) REFERENCES `club_chats` (`id`),
  CONSTRAINT `club_chat_people_ibfk_2` FOREIGN KEY (`actor_id`) REFERENCES `actors` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `club_chat_poll_options` (
  `poll_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `option_number` int NOT NULL,
  `label` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`poll_id`,`option_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `club_chat_poll_votes` (
  `poll_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `actor_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `option_number` int NOT NULL,
  `version` int NOT NULL DEFAULT '1',
  `request_key` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `updated_at` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`poll_id`,`actor_id`),
  UNIQUE KEY `request_key` (`request_key`),
  KEY `poll_id` (`poll_id`,`option_number`),
  CONSTRAINT `club_chat_poll_votes_ibfk_1` FOREIGN KEY (`poll_id`, `option_number`) REFERENCES `club_chat_poll_options` (`poll_id`, `option_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `club_chat_polls` (
  `id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `chat_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `message_seq` int NOT NULL,
  `actor_id` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `question` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `closes_at` mediumtext COLLATE utf8mb4_unicode_ci,
  `closed_at` mediumtext COLLATE utf8mb4_unicode_ci,
  `version` int NOT NULL DEFAULT '1',
  `request_key` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `request_hash` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `message_seq` (`message_seq`),
  UNIQUE KEY `request_key` (`request_key`),
  KEY `club_chat_polls_thread` (`chat_id`,`message_seq`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `club_chats` (
  `id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `club_id` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `title` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `owner` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `club_mailings` (
  `id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `club_id` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `actor_id` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `subject` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `body` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `recipients` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `state` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `request_key` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `preview_hash` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `fields` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT (_utf8mb4'{}'),
  `attachments` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT (_utf8mb4'[]'),
  PRIMARY KEY (`id`),
  UNIQUE KEY `request_key` (`request_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `club_outbox` (
  `id` int NOT NULL AUTO_INCREMENT,
  `mailing_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `body` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `state` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT (_utf8mb4'pending'),
  `updated_at` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `error` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT (_utf8mb4''),
  `subject` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT (_utf8mb4''),
  PRIMARY KEY (`id`),
  UNIQUE KEY `mailing_id` (`mailing_id`,`email`),
  CONSTRAINT `club_outbox_ibfk_1` FOREIGN KEY (`mailing_id`) REFERENCES `club_mailings` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `club_settings` (
  `club_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `section` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `payload` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `version` int NOT NULL,
  `updated_at` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`club_id`,`section`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `clubs` (
  `id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `contact_archives` (
  `club_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `contact_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `ciphertext` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `archived_at` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `archived_by` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`club_id`,`contact_id`),
  CONSTRAINT `contact_archives_ibfk_1` FOREIGN KEY (`club_id`, `contact_id`) REFERENCES `contacts` (`club_id`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `contact_favourites` (
  `club_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `actor_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `contact_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`club_id`,`actor_id`,`contact_id`),
  KEY `club_id` (`club_id`,`contact_id`),
  CONSTRAINT `contact_favourites_ibfk_1` FOREIGN KEY (`club_id`, `contact_id`) REFERENCES `contacts` (`club_id`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `contacts` (
  `id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `club_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `kind` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT (_utf8mb4''),
  `address` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT (_utf8mb4''),
  `version` int NOT NULL DEFAULT '1',
  `phone` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT (_utf8mb4''),
  `birthday` mediumtext COLLATE utf8mb4_unicode_ci,
  `notes` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT (_utf8mb4''),
  `honorary` int NOT NULL DEFAULT '0',
  `legacy_member_number` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT (_utf8mb4''),
  `archived_at` mediumtext COLLATE utf8mb4_unicode_ci,
  `custom_fields` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT (_utf8mb4'{}'),
  PRIMARY KEY (`club_id`,`id`),
  KEY `contacts_name` (`club_id`,`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `contribution_deliveries` (
  `club_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `entry_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `type` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `cycle` int NOT NULL,
  `year` int NOT NULL,
  `scheduled_for` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `recipient_contact_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `recipient_email` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `document_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `actor_id` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `created_at` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `attempted_at` mediumtext COLLATE utf8mb4_unicode_ci,
  `sent_at` mediumtext COLLATE utf8mb4_unicode_ci,
  `sent_remaining_cents` bigint DEFAULT NULL,
  `reason` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT (_utf8mb4''),
  PRIMARY KEY (`club_id`,`id`),
  UNIQUE KEY `club_id` (`club_id`,`entry_id`,`type`,`cycle`),
  KEY `club_id_2` (`club_id`,`recipient_contact_id`),
  KEY `club_id_3` (`club_id`,`document_id`),
  KEY `contribution_deliveries_pending` (`club_id`,`status`,`scheduled_for`),
  CONSTRAINT `contribution_deliveries_ibfk_1` FOREIGN KEY (`club_id`, `entry_id`) REFERENCES `finance_entries` (`club_id`, `id`),
  CONSTRAINT `contribution_deliveries_ibfk_2` FOREIGN KEY (`club_id`, `recipient_contact_id`) REFERENCES `contacts` (`club_id`, `id`),
  CONSTRAINT `contribution_deliveries_ibfk_3` FOREIGN KEY (`club_id`, `document_id`) REFERENCES `documents` (`club_id`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `contribution_invoices` (
  `club_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `entry_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `document_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `recipient_contact_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `recipient_email` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `year` int NOT NULL,
  `state` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `missing_fields` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`club_id`,`entry_id`),
  KEY `club_id` (`club_id`,`document_id`),
  KEY `club_id_2` (`club_id`,`recipient_contact_id`),
  CONSTRAINT `contribution_invoices_ibfk_1` FOREIGN KEY (`club_id`, `entry_id`) REFERENCES `finance_entries` (`club_id`, `id`),
  CONSTRAINT `contribution_invoices_ibfk_2` FOREIGN KEY (`club_id`, `document_id`) REFERENCES `documents` (`club_id`, `id`),
  CONSTRAINT `contribution_invoices_ibfk_3` FOREIGN KEY (`club_id`, `recipient_contact_id`) REFERENCES `contacts` (`club_id`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `document_checks` (
  `club_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `title` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `category` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `document_id` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT (_utf8mb4''),
  `contact_id` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT (_utf8mb4''),
  `event_id` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT (_utf8mb4''),
  `responsible` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT (_utf8mb4''),
  `valid_until` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `review_on` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `reminder_days` int NOT NULL DEFAULT '30',
  `notes` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT (_utf8mb4''),
  `state` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `version` int NOT NULL DEFAULT '1',
  `updated_at` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`club_id`,`id`),
  KEY `document_checks_due` (`club_id`,`state`,`review_on`,`valid_until`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `document_files` (
  `club_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `document_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `revision` int NOT NULL,
  `name` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `mime` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `bytes` mediumblob NOT NULL,
  `sha256` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_by` mediumtext COLLATE utf8mb4_unicode_ci,
  `snapshot` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`club_id`,`document_id`,`revision`),
  CONSTRAINT `document_files_ibfk_1` FOREIGN KEY (`club_id`, `document_id`) REFERENCES `documents` (`club_id`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `document_folders` (
  `club_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `parent_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `scope` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `system_key` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `version` int NOT NULL DEFAULT '1',
  `admission_only` int NOT NULL DEFAULT '0',
  PRIMARY KEY (`club_id`,`id`),
  UNIQUE KEY `club_id` (`club_id`,`system_key`),
  UNIQUE KEY `document_folder_siblings` (`club_id`,`parent_id`,`name`),
  CONSTRAINT `document_folders_ibfk_1` FOREIGN KEY (`club_id`, `parent_id`) REFERENCES `document_folders` (`club_id`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `document_history` (
  `sequence` int NOT NULL AUTO_INCREMENT,
  `club_id` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `document_id` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `actor_id` mediumtext COLLATE utf8mb4_unicode_ci,
  `action` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `version` int NOT NULL,
  `happened_at` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `details` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`sequence`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `document_links` (
  `club_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `document_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `source_type` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `source_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`club_id`,`document_id`,`source_type`,`source_id`),
  KEY `documents_link_source` (`club_id`,`source_type`,`source_id`),
  CONSTRAINT `document_links_ibfk_1` FOREIGN KEY (`club_id`, `document_id`) REFERENCES `documents` (`club_id`, `id`),
  CONSTRAINT `document_links_ibfk_2` FOREIGN KEY (`club_id`, `source_type`, `source_id`) REFERENCES `document_sources` (`club_id`, `source_type`, `source_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `document_requests` (
  `club_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `request_key` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `request_hash` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `document_id` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`club_id`,`request_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `document_sources` (
  `club_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `source_type` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `source_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `label` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `document_date` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `scope` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `folder_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `available` int NOT NULL DEFAULT '1',
  PRIMARY KEY (`club_id`,`source_type`,`source_id`),
  KEY `club_id` (`club_id`,`folder_id`),
  CONSTRAINT `document_sources_ibfk_1` FOREIGN KEY (`club_id`, `folder_id`) REFERENCES `document_folders` (`club_id`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `documents` (
  `club_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `folder_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `title` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `kind` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `document_date` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `scopes` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `origin` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `state` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `notes` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT (_utf8mb4''),
  `version` int NOT NULL DEFAULT '1',
  `created_at` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_by` mediumtext COLLATE utf8mb4_unicode_ci,
  `admission_only` int NOT NULL DEFAULT '0',
  PRIMARY KEY (`club_id`,`id`),
  KEY `documents_folder` (`club_id`,`folder_id`,`state`),
  CONSTRAINT `documents_ibfk_1` FOREIGN KEY (`club_id`, `folder_id`) REFERENCES `document_folders` (`club_id`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `donation_contacts` (
  `club_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `source_type` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `source_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `contact_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`club_id`,`source_type`,`source_id`),
  KEY `club_id` (`club_id`,`contact_id`),
  CONSTRAINT `donation_contacts_ibfk_1` FOREIGN KEY (`club_id`, `contact_id`) REFERENCES `contacts` (`club_id`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `donation_preparation_status` (
  `club_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `source_type` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `source_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `source_fingerprint` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT (_utf8mb4''),
  `state` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `reason` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `documents` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `updated_at` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`club_id`,`source_type`,`source_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `donation_receipt_claims` (
  `club_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `source_type` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `source_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `receipt_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`club_id`,`source_type`,`source_id`),
  KEY `club_id` (`club_id`,`receipt_id`),
  CONSTRAINT `donation_receipt_claims_ibfk_1` FOREIGN KEY (`club_id`, `receipt_id`) REFERENCES `donation_receipts` (`club_id`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `donation_receipt_sources` (
  `club_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `receipt_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `source_type` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `source_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `amount_cents` bigint NOT NULL,
  `donated_on` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`club_id`,`receipt_id`,`source_type`,`source_id`,`donated_on`),
  CONSTRAINT `donation_receipt_sources_ibfk_1` FOREIGN KEY (`club_id`, `receipt_id`) REFERENCES `donation_receipts` (`club_id`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `donation_receipts` (
  `club_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `document_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `request_key` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `input_hash` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `kind` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `source_id` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `issued_at` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `issued_by` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `cancelled_at` mediumtext COLLATE utf8mb4_unicode_ci,
  `cancelled_by` mediumtext COLLATE utf8mb4_unicode_ci,
  `cancel_reason` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  PRIMARY KEY (`club_id`,`id`),
  UNIQUE KEY `club_id` (`club_id`,`request_key`),
  UNIQUE KEY `club_id_2` (`club_id`,`document_id`),
  CONSTRAINT `donation_receipts_ibfk_1` FOREIGN KEY (`club_id`, `document_id`) REFERENCES `documents` (`club_id`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `donation_waiver_receipt_claims` (
  `club_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `waiver_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `receipt_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`club_id`,`waiver_id`),
  KEY `club_id` (`club_id`,`receipt_id`),
  CONSTRAINT `donation_waiver_receipt_claims_ibfk_1` FOREIGN KEY (`club_id`, `waiver_id`) REFERENCES `donation_waivers` (`club_id`, `id`),
  CONSTRAINT `donation_waiver_receipt_claims_ibfk_2` FOREIGN KEY (`club_id`, `receipt_id`) REFERENCES `donation_receipts` (`club_id`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `donation_waivers` (
  `club_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `contact_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `amount_cents` bigint NOT NULL,
  `agreed_on` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `incurred_on` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `due_on` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `waived_on` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `regular` int NOT NULL,
  `claim_document_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `waiver_document_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `snapshot` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `request_key` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `input_hash` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_by` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `version` int NOT NULL DEFAULT '1',
  `cancelled_at` mediumtext COLLATE utf8mb4_unicode_ci,
  `cancel_reason` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  PRIMARY KEY (`club_id`,`id`),
  UNIQUE KEY `club_id` (`club_id`,`request_key`),
  UNIQUE KEY `club_id_2` (`club_id`,`contact_id`,`claim_document_id`),
  KEY `club_id_3` (`club_id`,`claim_document_id`),
  KEY `club_id_4` (`club_id`,`waiver_document_id`),
  CONSTRAINT `donation_waivers_ibfk_1` FOREIGN KEY (`club_id`, `contact_id`) REFERENCES `contacts` (`club_id`, `id`),
  CONSTRAINT `donation_waivers_ibfk_2` FOREIGN KEY (`club_id`, `claim_document_id`) REFERENCES `documents` (`club_id`, `id`),
  CONSTRAINT `donation_waivers_ibfk_3` FOREIGN KEY (`club_id`, `waiver_document_id`) REFERENCES `documents` (`club_id`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `einvoice_validations` (
  `id` int NOT NULL AUTO_INCREMENT,
  `club_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `document_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `revision` int NOT NULL,
  `sha256` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `actor_id` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `validator_sha256` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `configuration_sha256` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `state` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `scenario` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `report` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`id`),
  KEY `club_id` (`club_id`,`document_id`),
  CONSTRAINT `einvoice_validations_ibfk_1` FOREIGN KEY (`club_id`, `document_id`) REFERENCES `documents` (`club_id`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `event_gema` (
  `club_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `event_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `event_title` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `event_date` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `reference` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT (_utf8mb4''),
  `notes` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT (_utf8mb4''),
  `setlist` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT (_utf8mb4'check'),
  `entry_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `version` int NOT NULL DEFAULT '1',
  `details` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT (_utf8mb4'{}'),
  PRIMARY KEY (`club_id`,`event_id`),
  UNIQUE KEY `club_id` (`club_id`,`entry_id`),
  CONSTRAINT `event_gema_ibfk_1` FOREIGN KEY (`club_id`, `entry_id`) REFERENCES `finance_entries` (`club_id`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `finance_account_settings` (
  `club_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `account_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `active` int NOT NULL DEFAULT '1',
  `tax_account` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT (_utf8mb4''),
  `version` int NOT NULL DEFAULT '1',
  `fints_read` int NOT NULL DEFAULT '1',
  `fints_write` int NOT NULL DEFAULT '0',
  PRIMARY KEY (`club_id`,`account_id`),
  CONSTRAINT `finance_account_settings_ibfk_1` FOREIGN KEY (`club_id`, `account_id`) REFERENCES `finance_accounts` (`club_id`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `finance_accounts` (
  `id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `club_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `kind` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `opening_cents` bigint NOT NULL,
  `opened_on` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`club_id`,`id`),
  UNIQUE KEY `club_id` (`club_id`,`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `finance_allocations` (
  `id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `club_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `transaction_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `entry_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `cents` bigint NOT NULL,
  `request_key` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `voided_at` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `void_reason` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  PRIMARY KEY (`club_id`,`id`),
  UNIQUE KEY `club_id` (`club_id`,`request_key`),
  KEY `allocations_entry` (`club_id`,`entry_id`,`voided_at`),
  KEY `allocations_transaction` (`club_id`,`transaction_id`,`voided_at`),
  CONSTRAINT `finance_allocations_ibfk_1` FOREIGN KEY (`club_id`, `transaction_id`) REFERENCES `finance_transactions` (`club_id`, `id`),
  CONSTRAINT `finance_allocations_ibfk_2` FOREIGN KEY (`club_id`, `entry_id`) REFERENCES `finance_entries` (`club_id`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `finance_entries` (
  `id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `club_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `number` int NOT NULL,
  `kind` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `counterparty` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT (_utf8mb4''),
  `amount_cents` bigint NOT NULL,
  `document_date` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `due_date` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `paid_on` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `account_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `category` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `notes` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT (_utf8mb4''),
  `version` int NOT NULL DEFAULT '1',
  `request_key` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `display_number` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  PRIMARY KEY (`club_id`,`id`),
  UNIQUE KEY `club_id` (`club_id`,`number`),
  UNIQUE KEY `club_id_2` (`club_id`,`request_key`),
  UNIQUE KEY `finance_display_number` (`club_id`,`display_number`),
  KEY `club_id_3` (`club_id`,`account_id`),
  KEY `finance_dates` (`club_id`,`document_date`),
  KEY `finance_paid` (`club_id`,`paid_on`),
  CONSTRAINT `finance_entries_ibfk_1` FOREIGN KEY (`club_id`, `account_id`) REFERENCES `finance_accounts` (`club_id`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `finance_event_assignments` (
  `club_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `entry_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `event_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `purpose` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `event_title` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `starts_at` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `location` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT (_utf8mb4''),
  `actor_id` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`club_id`,`entry_id`),
  KEY `finance_event_assignments_event` (`club_id`,`event_id`),
  CONSTRAINT `finance_event_assignments_ibfk_1` FOREIGN KEY (`club_id`, `entry_id`) REFERENCES `finance_entries` (`club_id`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `finance_reimbursement_claims` (
  `club_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `entry_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `contact_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `amount_cents` bigint NOT NULL,
  `invoice_recipient` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `state` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `requested_on` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `approved_by` mediumtext COLLATE utf8mb4_unicode_ci,
  `approved_at` mediumtext COLLATE utf8mb4_unicode_ci,
  `paid_transaction_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `waived_on` mediumtext COLLATE utf8mb4_unicode_ci,
  `notes` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT (_utf8mb4''),
  `version` int NOT NULL DEFAULT '1',
  `request_key` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_by` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`club_id`,`id`),
  UNIQUE KEY `club_id` (`club_id`,`entry_id`),
  UNIQUE KEY `club_id_2` (`club_id`,`request_key`),
  KEY `club_id_4` (`club_id`,`paid_transaction_id`),
  KEY `finance_reimbursements_contact` (`club_id`,`contact_id`,`state`),
  KEY `finance_reimbursements_entry` (`club_id`,`entry_id`,`state`),
  CONSTRAINT `finance_reimbursement_claims_ibfk_1` FOREIGN KEY (`club_id`, `entry_id`) REFERENCES `finance_entries` (`club_id`, `id`),
  CONSTRAINT `finance_reimbursement_claims_ibfk_2` FOREIGN KEY (`club_id`, `contact_id`) REFERENCES `contacts` (`club_id`, `id`),
  CONSTRAINT `finance_reimbursement_claims_ibfk_3` FOREIGN KEY (`club_id`, `paid_transaction_id`) REFERENCES `finance_transactions` (`club_id`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `finance_reversals` (
  `club_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `entry_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `reversed_on` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `reason` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `actor_id` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`club_id`,`entry_id`),
  CONSTRAINT `finance_reversals_ibfk_1` FOREIGN KEY (`club_id`, `entry_id`) REFERENCES `finance_entries` (`club_id`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `finance_supplier_contacts` (
  `club_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `entry_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `contact_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `contact_version` int NOT NULL,
  `supplier_name` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `actor_id` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`club_id`,`entry_id`),
  KEY `club_id` (`club_id`,`contact_id`),
  CONSTRAINT `finance_supplier_contacts_ibfk_1` FOREIGN KEY (`club_id`, `entry_id`) REFERENCES `finance_entries` (`club_id`, `id`),
  CONSTRAINT `finance_supplier_contacts_ibfk_2` FOREIGN KEY (`club_id`, `contact_id`) REFERENCES `contacts` (`club_id`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `finance_transactions` (
  `id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `club_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `account_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `booked_on` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `counterparty` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT (_utf8mb4''),
  `description` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `cents` bigint NOT NULL,
  `source` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `reversal_of` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `request_key` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `fingerprint` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `version` int NOT NULL DEFAULT '1',
  PRIMARY KEY (`club_id`,`id`),
  UNIQUE KEY `club_id` (`club_id`,`request_key`),
  UNIQUE KEY `club_id_2` (`club_id`,`reversal_of`),
  KEY `transactions_account_date` (`club_id`,`account_id`,`booked_on`),
  CONSTRAINT `finance_transactions_ibfk_1` FOREIGN KEY (`club_id`, `account_id`) REFERENCES `finance_accounts` (`club_id`, `id`),
  CONSTRAINT `finance_transactions_ibfk_2` FOREIGN KEY (`club_id`, `reversal_of`) REFERENCES `finance_transactions` (`club_id`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `finance_transfer_orders` (
  `club_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `entry_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `account_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `execution_date` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `state` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_by` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `version` int NOT NULL DEFAULT '1',
  `request_key` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `snapshot_hash` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `sealed_snapshot` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `total_cents` bigint NOT NULL,
  `approved_by` mediumtext COLLATE utf8mb4_unicode_ci,
  `approved_at` mediumtext COLLATE utf8mb4_unicode_ci,
  `office_version` int DEFAULT NULL,
  `exported_at` mediumtext COLLATE utf8mb4_unicode_ci,
  `bank_reference` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT (_utf8mb4''),
  PRIMARY KEY (`club_id`,`id`),
  UNIQUE KEY `club_id` (`club_id`,`request_key`),
  KEY `club_id_3` (`club_id`,`account_id`),
  KEY `finance_transfer_entry` (`club_id`,`entry_id`,`state`),
  CONSTRAINT `finance_transfer_orders_ibfk_1` FOREIGN KEY (`club_id`, `entry_id`) REFERENCES `finance_entries` (`club_id`, `id`),
  CONSTRAINT `finance_transfer_orders_ibfk_2` FOREIGN KEY (`club_id`, `account_id`) REFERENCES `finance_accounts` (`club_id`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `fints_sync_runs` (
  `club_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `mapping_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `window_key` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `state` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `phase` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT (_utf8mb4''),
  `message` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT (_utf8mb4''),
  `rows_count` int NOT NULL DEFAULT '0',
  `started_at` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `finished_at` mediumtext COLLATE utf8mb4_unicode_ci,
  PRIMARY KEY (`club_id`,`id`),
  UNIQUE KEY `club_id` (`club_id`,`mapping_id`,`window_key`),
  KEY `fints_sync_runs_latest` (`club_id`,`mapping_id`,`started_at` DESC),
  CONSTRAINT `fints_sync_runs_ibfk_1` FOREIGN KEY (`club_id`, `mapping_id`) REFERENCES `bank_account_mappings` (`club_id`, `id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `fints_sync_settings` (
  `club_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `mapping_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `enabled` int NOT NULL DEFAULT '1',
  `first_time` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT (_utf8mb4'08:00'),
  `second_time` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT (_utf8mb4'18:00'),
  `version` int NOT NULL DEFAULT '1',
  `updated_by` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `updated_at` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `interval_minutes` int NOT NULL DEFAULT '5',
  PRIMARY KEY (`club_id`,`mapping_id`),
  CONSTRAINT `fints_sync_settings_ibfk_1` FOREIGN KEY (`club_id`, `mapping_id`) REFERENCES `bank_account_mappings` (`club_id`, `id`) ON DELETE CASCADE,
  CONSTRAINT `fints_sync_settings_chk_1` CHECK ((`interval_minutes` = 5))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `free_table_access` (
  `table_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `actor_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `access` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`table_id`,`actor_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `free_table_rows` (
  `id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `table_id` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `cells_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `version` int NOT NULL DEFAULT '1',
  `archived` int NOT NULL DEFAULT '0',
  `sequence` bigint unsigned NOT NULL AUTO_INCREMENT,
  PRIMARY KEY (`id`),
  UNIQUE KEY `sequence` (`sequence`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `free_tables` (
  `id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `club_id` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `owner_id` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `title` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `columns_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `version` int NOT NULL DEFAULT '1',
  `archived` int NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `goods_donations` (
  `id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `club_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `donor` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `received_on` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `value_cents` bigint NOT NULL,
  `valuation` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `request_key` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `designation` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT (_utf8mb4''),
  `origin` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT (_utf8mb4'unknown'),
  `evidence_present` int NOT NULL DEFAULT '0',
  `version` int NOT NULL DEFAULT '1',
  PRIMARY KEY (`club_id`,`id`),
  UNIQUE KEY `club_id` (`club_id`,`request_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `inventory_accessories` (
  `club_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `parent_item` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `child_item` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `usage_mode` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT (_utf8mb4'required'),
  `quantity_per_unit` int NOT NULL,
  PRIMARY KEY (`club_id`,`parent_item`,`child_item`),
  KEY `inventory_accessories_child` (`club_id`,`child_item`),
  CONSTRAINT `inventory_accessories_ibfk_1` FOREIGN KEY (`club_id`, `parent_item`) REFERENCES `inventory_items` (`club_id`, `id`),
  CONSTRAINT `inventory_accessories_ibfk_2` FOREIGN KEY (`club_id`, `child_item`) REFERENCES `inventory_items` (`club_id`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `inventory_acquisition_pools` (
  `club_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `document_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `revision` int NOT NULL,
  `sha256` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `source_ordinal` int NOT NULL,
  `gross_cents` bigint NOT NULL,
  `description` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_by` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`club_id`,`id`),
  UNIQUE KEY `club_id` (`club_id`,`sha256`,`source_ordinal`),
  KEY `club_id_2` (`club_id`,`document_id`,`revision`),
  CONSTRAINT `inventory_acquisition_pools_ibfk_1` FOREIGN KEY (`club_id`, `document_id`, `revision`) REFERENCES `document_files` (`club_id`, `document_id`, `revision`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `inventory_acquisition_sources` (
  `club_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `item_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `document_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `revision` int NOT NULL,
  `sha256` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `source_ordinal` int NOT NULL,
  `gross_cents` bigint DEFAULT NULL,
  `pool_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `acquired_on` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `pool_quantity` int DEFAULT NULL,
  PRIMARY KEY (`club_id`,`item_id`,`sha256`,`source_ordinal`),
  KEY `club_id` (`club_id`,`document_id`,`revision`),
  KEY `club_id_2` (`club_id`,`pool_id`),
  CONSTRAINT `inventory_acquisition_sources_ibfk_1` FOREIGN KEY (`club_id`, `item_id`) REFERENCES `inventory_items` (`club_id`, `id`),
  CONSTRAINT `inventory_acquisition_sources_ibfk_2` FOREIGN KEY (`club_id`, `document_id`, `revision`) REFERENCES `document_files` (`club_id`, `document_id`, `revision`),
  CONSTRAINT `inventory_acquisition_sources_ibfk_3` FOREIGN KEY (`club_id`, `pool_id`) REFERENCES `inventory_acquisition_pools` (`club_id`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `inventory_assets` (
  `club_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `item_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `mime` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `bytes` mediumblob NOT NULL,
  `created_at` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`club_id`,`id`),
  KEY `club_id` (`club_id`,`item_id`),
  CONSTRAINT `inventory_assets_ibfk_1` FOREIGN KEY (`club_id`, `item_id`) REFERENCES `inventory_items` (`club_id`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `inventory_events` (
  `sequence` int NOT NULL AUTO_INCREMENT,
  `club_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `actor_id` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `action` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `object_id` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `happened_at` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `details` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `request_key` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `request_hash` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`sequence`),
  UNIQUE KEY `club_id` (`club_id`,`request_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `inventory_integration_runs` (
  `club_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `request_key` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `actor_id` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `payload_hash` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `result_json` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`club_id`,`request_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `inventory_items` (
  `club_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `code` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `category` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT (_utf8mb4''),
  `unit` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT (_utf8mb4'St�ck'),
  `kind` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `minimum` int NOT NULL DEFAULT '0',
  `serial_number` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `notes` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT (_utf8mb4''),
  `active` int NOT NULL DEFAULT '1',
  `version` int NOT NULL DEFAULT '1',
  `stock_version` int NOT NULL DEFAULT '0',
  `source_entry` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `source_goods` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ownership` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT (_utf8mb4'club'),
  `owner_id` mediumtext COLLATE utf8mb4_unicode_ci,
  `responsible_id` mediumtext COLLATE utf8mb4_unicode_ci,
  `department` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT (_utf8mb4''),
  `home_location` mediumtext COLLATE utf8mb4_unicode_ci,
  `acquired_on` mediumtext COLLATE utf8mb4_unicode_ci,
  `condition_note` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT (_utf8mb4''),
  `unit_value_cents` bigint DEFAULT NULL,
  `value_note` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT (_utf8mb4''),
  `cash_account` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `tracking_mode` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT (_utf8mb4'quantity'),
  `inventory_group_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `content_quantity` int NOT NULL DEFAULT '1',
  `content_unit` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT (_utf8mb4'St�ck'),
  `deleted_at` mediumtext COLLATE utf8mb4_unicode_ci,
  `deleted_by` mediumtext COLLATE utf8mb4_unicode_ci,
  PRIMARY KEY (`club_id`,`id`),
  UNIQUE KEY `club_id` (`club_id`,`code`),
  KEY `club_id_2` (`club_id`,`source_entry`),
  KEY `club_id_3` (`club_id`,`source_goods`),
  KEY `inventory_cash_account` (`club_id`,`cash_account`),
  KEY `inventory_item_group` (`club_id`,`inventory_group_id`),
  KEY `inventory_individual_serial` (`club_id`,`serial_number`),
  CONSTRAINT `inventory_items_ibfk_1` FOREIGN KEY (`club_id`, `source_entry`) REFERENCES `finance_entries` (`club_id`, `id`),
  CONSTRAINT `inventory_items_ibfk_2` FOREIGN KEY (`club_id`, `source_goods`) REFERENCES `goods_donations` (`club_id`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `inventory_loan_lines` (
  `club_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `loan_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `item_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `location_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `quantity` int NOT NULL,
  `returned` int NOT NULL DEFAULT '0',
  `lost` int NOT NULL DEFAULT '0',
  `item_name` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `item_code` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `unit` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `content_quantity` int DEFAULT NULL,
  `content_unit` mediumtext COLLATE utf8mb4_unicode_ci,
  PRIMARY KEY (`club_id`,`id`),
  UNIQUE KEY `club_id` (`club_id`,`loan_id`,`item_id`,`location_id`),
  KEY `club_id_2` (`club_id`,`item_id`),
  KEY `club_id_3` (`club_id`,`location_id`),
  CONSTRAINT `inventory_loan_lines_ibfk_1` FOREIGN KEY (`club_id`, `loan_id`) REFERENCES `inventory_loans` (`club_id`, `id`),
  CONSTRAINT `inventory_loan_lines_ibfk_2` FOREIGN KEY (`club_id`, `item_id`) REFERENCES `inventory_items` (`club_id`, `id`),
  CONSTRAINT `inventory_loan_lines_ibfk_3` FOREIGN KEY (`club_id`, `location_id`) REFERENCES `inventory_locations` (`club_id`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `inventory_loans` (
  `club_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `number` int NOT NULL,
  `contact_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `starts_on` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `due_on` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `issued_on` mediumtext COLLATE utf8mb4_unicode_ci,
  `closed_on` mediumtext COLLATE utf8mb4_unicode_ci,
  `purpose` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `notes` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT (_utf8mb4''),
  `state` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `version` int NOT NULL DEFAULT '1',
  `current_place` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT (_utf8mb4''),
  PRIMARY KEY (`club_id`,`id`),
  UNIQUE KEY `club_id` (`club_id`,`number`),
  KEY `inventory_loan_contact` (`club_id`,`contact_id`,`due_on`),
  CONSTRAINT `inventory_loans_ibfk_1` FOREIGN KEY (`club_id`, `contact_id`) REFERENCES `contacts` (`club_id`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `inventory_locations` (
  `club_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `notes` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT (_utf8mb4''),
  `active` int NOT NULL DEFAULT '1',
  `version` int NOT NULL DEFAULT '1',
  `holder_id` mediumtext COLLATE utf8mb4_unicode_ci,
  `building` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT (_utf8mb4''),
  `room` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT (_utf8mb4''),
  `shelf` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT (_utf8mb4''),
  PRIMARY KEY (`club_id`,`id`),
  UNIQUE KEY `club_id` (`club_id`,`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `inventory_movements` (
  `sequence` int NOT NULL AUTO_INCREMENT,
  `club_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `item_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `location_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `event_sequence` int NOT NULL,
  `shelf_delta` int NOT NULL,
  `reserved_delta` int NOT NULL,
  `lent_delta` int NOT NULL,
  `blocked_delta` int NOT NULL,
  PRIMARY KEY (`sequence`),
  KEY `club_id_2` (`club_id`,`location_id`),
  KEY `inventory_movement_item` (`club_id`,`item_id`,`sequence`),
  CONSTRAINT `inventory_movements_ibfk_1` FOREIGN KEY (`club_id`, `item_id`) REFERENCES `inventory_items` (`club_id`, `id`),
  CONSTRAINT `inventory_movements_ibfk_2` FOREIGN KEY (`club_id`, `location_id`) REFERENCES `inventory_locations` (`club_id`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `inventory_purchase_units` (
  `club_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `item_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `serial_number` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT (_utf8mb4''),
  `unit_value_cents` bigint DEFAULT NULL,
  `value_note` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT (_utf8mb4''),
  `created_event` int NOT NULL,
  `retired_event` int DEFAULT NULL,
  `sequence` bigint unsigned NOT NULL AUTO_INCREMENT,
  PRIMARY KEY (`club_id`,`id`),
  UNIQUE KEY `sequence` (`sequence`),
  KEY `created_event` (`created_event`),
  KEY `retired_event` (`retired_event`),
  KEY `inventory_purchase_units_item` (`club_id`,`item_id`,`retired_event`),
  CONSTRAINT `inventory_purchase_units_ibfk_1` FOREIGN KEY (`club_id`, `item_id`) REFERENCES `inventory_items` (`club_id`, `id`),
  CONSTRAINT `inventory_purchase_units_ibfk_2` FOREIGN KEY (`created_event`) REFERENCES `inventory_events` (`sequence`),
  CONSTRAINT `inventory_purchase_units_ibfk_3` FOREIGN KEY (`retired_event`) REFERENCES `inventory_events` (`sequence`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `inventory_stock` (
  `club_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `item_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `location_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `shelf` int NOT NULL DEFAULT '0',
  `reserved` int NOT NULL DEFAULT '0',
  `lent` int NOT NULL DEFAULT '0',
  `blocked` int NOT NULL DEFAULT '0',
  `confirmed_at` mediumtext COLLATE utf8mb4_unicode_ci,
  `confirmed_by` mediumtext COLLATE utf8mb4_unicode_ci,
  PRIMARY KEY (`club_id`,`item_id`,`location_id`),
  KEY `club_id` (`club_id`,`location_id`),
  CONSTRAINT `inventory_stock_ibfk_1` FOREIGN KEY (`club_id`, `item_id`) REFERENCES `inventory_items` (`club_id`, `id`),
  CONSTRAINT `inventory_stock_ibfk_2` FOREIGN KEY (`club_id`, `location_id`) REFERENCES `inventory_locations` (`club_id`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `invoice_approvals` (
  `id` int NOT NULL AUTO_INCREMENT,
  `club_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `entry_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `actor_id` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `office_version` int NOT NULL,
  `office_evidence` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `decision` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `snapshot_hash` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `snapshot` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `reason` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT (_utf8mb4''),
  `request_key` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `input_hash` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `club_id` (`club_id`,`request_key`),
  KEY `invoice_approvals_entry` (`club_id`,`entry_id`,`id`),
  CONSTRAINT `invoice_approvals_ibfk_1` FOREIGN KEY (`club_id`, `entry_id`) REFERENCES `finance_entries` (`club_id`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `invoice_mail_events` (
  `id` int NOT NULL AUTO_INCREMENT,
  `club_id` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `mailbox_key` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `message_uid` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `happened_at` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `sender` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT (_utf8mb4''),
  `subject` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT (_utf8mb4''),
  `filename` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT (_utf8mb4''),
  `detail` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT (_utf8mb4''),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `invoice_mail_imports` (
  `club_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `content_hash` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `document_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `sender` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `subject` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `received_at` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `imported_at` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `message_uid` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `suggested_entry` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT (_utf8mb4''),
  `mailbox_key` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT (_utf8mb4''),
  `message_id` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT (_utf8mb4''),
  `original_date` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT (_utf8mb4''),
  `mailbox_label` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT (_utf8mb4''),
  PRIMARY KEY (`club_id`,`content_hash`),
  KEY `club_id` (`club_id`,`document_id`),
  CONSTRAINT `invoice_mail_imports_ibfk_1` FOREIGN KEY (`club_id`, `document_id`) REFERENCES `documents` (`club_id`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `invoice_mail_only` (
  `club_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `mailbox_key` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `message_uid` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `sender` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `subject` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `received_at` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `imported_at` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `state` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT (_utf8mb4'active'),
  `version` int NOT NULL DEFAULT '1',
  PRIMARY KEY (`club_id`,`id`),
  UNIQUE KEY `club_id` (`club_id`,`mailbox_key`,`message_uid`),
  CONSTRAINT `invoice_mail_only_ibfk_1` FOREIGN KEY (`club_id`, `mailbox_key`, `message_uid`) REFERENCES `invoice_mail_originals` (`club_id`, `mailbox_key`, `message_uid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `invoice_mail_original_links` (
  `club_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `document_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `mailbox_key` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `message_uid` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`club_id`,`document_id`,`mailbox_key`,`message_uid`),
  KEY `club_id` (`club_id`,`mailbox_key`,`message_uid`),
  CONSTRAINT `invoice_mail_original_links_ibfk_1` FOREIGN KEY (`club_id`, `document_id`) REFERENCES `documents` (`club_id`, `id`),
  CONSTRAINT `invoice_mail_original_links_ibfk_2` FOREIGN KEY (`club_id`, `mailbox_key`, `message_uid`) REFERENCES `invoice_mail_originals` (`club_id`, `mailbox_key`, `message_uid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `invoice_mail_originals` (
  `club_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `mailbox_key` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `message_uid` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `sha256` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `bytes` mediumblob NOT NULL,
  `created_at` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`club_id`,`mailbox_key`,`message_uid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `invoice_mailboxes` (
  `club_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `version` int NOT NULL DEFAULT '1',
  `config` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `secrets` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `configured_by` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `mailbox_key` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `uidvalidity` int NOT NULL DEFAULT '0',
  `last_uid` int NOT NULL DEFAULT '0',
  `lock_until` int NOT NULL DEFAULT '0',
  `last_run` mediumtext COLLATE utf8mb4_unicode_ci,
  `last_status` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT (_utf8mb4''),
  `updated_at` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`club_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `invoice_payment_reports` (
  `club_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `entry_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `version` int NOT NULL,
  `operation` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `paid_on` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `note` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `actor_id` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `entry_version` int NOT NULL,
  `request_key` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `fingerprint` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`club_id`,`entry_id`,`version`),
  UNIQUE KEY `club_id` (`club_id`,`request_key`),
  CONSTRAINT `invoice_payment_reports_ibfk_1` FOREIGN KEY (`club_id`, `entry_id`) REFERENCES `finance_entries` (`club_id`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `invoice_position_details` (
  `club_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `entry_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `ordinal` int NOT NULL,
  `details_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `actor_id` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`club_id`,`entry_id`,`ordinal`),
  CONSTRAINT `invoice_position_details_ibfk_1` FOREIGN KEY (`club_id`, `entry_id`) REFERENCES `finance_entries` (`club_id`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `invoice_position_items` (
  `club_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `entry_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `ordinal` int NOT NULL,
  `item_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `item_version` int NOT NULL,
  `description` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `actor_id` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`club_id`,`entry_id`,`ordinal`,`item_id`),
  KEY `club_id` (`club_id`,`item_id`),
  CONSTRAINT `invoice_position_items_ibfk_1` FOREIGN KEY (`club_id`, `entry_id`) REFERENCES `finance_entries` (`club_id`, `id`),
  CONSTRAINT `invoice_position_items_ibfk_2` FOREIGN KEY (`club_id`, `item_id`) REFERENCES `inventory_items` (`club_id`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `invoice_recognition` (
  `club_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `document_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `revision` int NOT NULL,
  `sha256` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `parser_version` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `state` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `result_json` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `lease_token` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT (_utf8mb4''),
  `lease_until` int NOT NULL DEFAULT '0',
  `updated_at` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `actor_id` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`club_id`,`document_id`),
  CONSTRAINT `invoice_recognition_ibfk_1` FOREIGN KEY (`club_id`, `document_id`) REFERENCES `documents` (`club_id`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `mail_channel_settings` (
  `club_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `version` int NOT NULL,
  `config` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `secrets` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `updated_by` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `updated_at` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`club_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `manager_notifications` (
  `id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `club_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `topic` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `source_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `source_version` int NOT NULL DEFAULT '1',
  `contact_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `starts_at` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT (_utf8mb4''),
  `source_fingerprint` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT (_utf8mb4''),
  `created_at` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `club_id` (`club_id`,`topic`,`source_id`,`source_version`,`contact_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `manager_push_deliveries` (
  `notification_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `subscription_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `state` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `updated_at` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `error` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT (_utf8mb4''),
  PRIMARY KEY (`notification_id`,`subscription_id`),
  KEY `subscription_id` (`subscription_id`),
  KEY `manager_push_pending` (`state`,`updated_at`),
  CONSTRAINT `manager_push_deliveries_ibfk_1` FOREIGN KEY (`notification_id`) REFERENCES `manager_notifications` (`id`) ON DELETE CASCADE,
  CONSTRAINT `manager_push_deliveries_ibfk_2` FOREIGN KEY (`subscription_id`) REFERENCES `push_subscriptions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `manual_member_accounts` (
  `club_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `contact_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `username` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `creation_key` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `pw_user_id` int DEFAULT NULL,
  `state` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `send_mail` int NOT NULL,
  `mail_status` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `application_id` mediumtext COLLATE utf8mb4_unicode_ci,
  `requested_by` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `sent_at` mediumtext COLLATE utf8mb4_unicode_ci,
  PRIMARY KEY (`club_id`,`contact_id`),
  CONSTRAINT `manual_member_accounts_ibfk_1` FOREIGN KEY (`club_id`, `contact_id`) REFERENCES `contacts` (`club_id`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `member_bank_config` (
  `club_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `sepa_enabled` int NOT NULL DEFAULT '0',
  `bank_online_mandate_accepted` int NOT NULL DEFAULT '0',
  `creditor_id` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT (_utf8mb4''),
  `creditor_name` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT (_utf8mb4''),
  `creditor_address` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT (_utf8mb4''),
  PRIMARY KEY (`club_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `member_bank_grants` (
  `token_hash` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `club_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `actor_id` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `contact_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `action` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `expected_version` int NOT NULL,
  `payload_hash` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `session_hash` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `expires_at` int NOT NULL,
  `used_at` int DEFAULT NULL,
  PRIMARY KEY (`token_hash`),
  KEY `club_id` (`club_id`,`contact_id`),
  CONSTRAINT `member_bank_grants_ibfk_1` FOREIGN KEY (`club_id`, `contact_id`) REFERENCES `contacts` (`club_id`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `member_bank_history` (
  `operation_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `club_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `contact_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `version` int NOT NULL,
  `action` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `key_version` int NOT NULL,
  `ciphertext` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `actor_id` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`operation_id`),
  UNIQUE KEY `club_id` (`club_id`,`contact_id`,`version`),
  CONSTRAINT `member_bank_history_ibfk_1` FOREIGN KEY (`club_id`, `contact_id`) REFERENCES `contacts` (`club_id`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `member_bank_records` (
  `club_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `contact_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `version` int NOT NULL,
  `key_version` int NOT NULL,
  `ciphertext` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `mandate_ref` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT (_utf8mb4''),
  `updated_at` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`club_id`,`contact_id`),
  CONSTRAINT `member_bank_records_ibfk_1` FOREIGN KEY (`club_id`, `contact_id`) REFERENCES `contacts` (`club_id`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `member_change_requests` (
  `id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `club_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `contact_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `actor_id` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `contact_version` int NOT NULL,
  `state` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `version` int NOT NULL DEFAULT '1',
  `ciphertext` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `request_key` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `request_hash` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `reviewed_at` mediumtext COLLATE utf8mb4_unicode_ci,
  `reviewed_by` mediumtext COLLATE utf8mb4_unicode_ci,
  PRIMARY KEY (`id`),
  UNIQUE KEY `request_key` (`request_key`),
  KEY `member_change_one_pending` (`club_id`,`contact_id`),
  KEY `member_change_requests_queue` (`club_id`,`state`,`created_at`),
  CONSTRAINT `member_change_requests_ibfk_1` FOREIGN KEY (`club_id`, `contact_id`) REFERENCES `contacts` (`club_id`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `member_dues_people` (
  `club_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `contact_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `year` int NOT NULL,
  `entry_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`club_id`,`contact_id`,`year`),
  KEY `club_id` (`club_id`,`entry_id`),
  CONSTRAINT `member_dues_people_ibfk_1` FOREIGN KEY (`club_id`, `contact_id`) REFERENCES `contacts` (`club_id`, `id`),
  CONSTRAINT `member_dues_people_ibfk_2` FOREIGN KEY (`club_id`, `entry_id`) REFERENCES `finance_entries` (`club_id`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `member_families` (
  `club_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `payer_contact_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `version` int NOT NULL DEFAULT '1',
  PRIMARY KEY (`club_id`,`id`),
  KEY `club_id` (`club_id`,`payer_contact_id`),
  CONSTRAINT `member_families_ibfk_1` FOREIGN KEY (`club_id`, `payer_contact_id`) REFERENCES `contacts` (`club_id`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `member_family_people` (
  `club_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `contact_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `family_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`club_id`,`contact_id`),
  KEY `club_id` (`club_id`,`family_id`),
  CONSTRAINT `member_family_people_ibfk_1` FOREIGN KEY (`club_id`, `contact_id`) REFERENCES `contacts` (`club_id`, `id`),
  CONSTRAINT `member_family_people_ibfk_2` FOREIGN KEY (`club_id`, `family_id`) REFERENCES `member_families` (`club_id`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `membership_account_onboarding` (
  `club_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `application_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `contact_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `pw_user_id` int DEFAULT NULL,
  `username` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `creation_key` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `password_cipher` mediumtext COLLATE utf8mb4_unicode_ci,
  `created_new` int NOT NULL,
  `password_change_required` int NOT NULL,
  `state` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `delivered_at` mediumtext COLLATE utf8mb4_unicode_ci,
  `password_changed_at` mediumtext COLLATE utf8mb4_unicode_ci,
  PRIMARY KEY (`club_id`,`application_id`),
  KEY `club_id` (`club_id`,`contact_id`),
  KEY `membership_account_user` (`pw_user_id`,`password_change_required`),
  CONSTRAINT `membership_account_onboarding_ibfk_1` FOREIGN KEY (`club_id`, `application_id`) REFERENCES `membership_applications` (`club_id`, `id`),
  CONSTRAINT `membership_account_onboarding_ibfk_2` FOREIGN KEY (`club_id`, `contact_id`) REFERENCES `contacts` (`club_id`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `membership_applications` (
  `id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `club_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `request_key` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `version` int NOT NULL DEFAULT '1',
  `status` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `answers` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `configuration` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `consent_snapshot` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `configuration_version` int NOT NULL,
  `submitted_at` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `reviewed_at` mediumtext COLLATE utf8mb4_unicode_ci,
  `reviewed_by` mediumtext COLLATE utf8mb4_unicode_ci,
  `review_note` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT (_utf8mb4''),
  `contact_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `access_mail` int DEFAULT NULL,
  PRIMARY KEY (`club_id`,`id`),
  UNIQUE KEY `club_id` (`club_id`,`request_key`),
  KEY `club_id_2` (`club_id`,`contact_id`),
  CONSTRAINT `membership_applications_ibfk_1` FOREIGN KEY (`club_id`, `contact_id`) REFERENCES `contacts` (`club_id`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `membership_contribution_rates` (
  `club_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `membership_type` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `catalog_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`club_id`,`membership_type`),
  UNIQUE KEY `club_id` (`club_id`,`catalog_id`),
  CONSTRAINT `membership_contribution_rates_ibfk_1` FOREIGN KEY (`club_id`, `catalog_id`) REFERENCES `settings_catalog` (`club_id`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `membership_welcome` (
  `club_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `application_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `contact_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `membership_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `entry_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `payload` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `welcome_status` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT (_utf8mb4'pending'),
  `invoice_status` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT (_utf8mb4'waiting'),
  `created_at` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `welcome_sent_at` mediumtext COLLATE utf8mb4_unicode_ci,
  `invoice_sent_at` mediumtext COLLATE utf8mb4_unicode_ci,
  `account_ack_at` mediumtext COLLATE utf8mb4_unicode_ci,
  PRIMARY KEY (`club_id`,`application_id`),
  KEY `club_id` (`club_id`,`contact_id`),
  KEY `club_id_2` (`club_id`,`membership_id`),
  KEY `club_id_3` (`club_id`,`entry_id`),
  CONSTRAINT `membership_welcome_ibfk_1` FOREIGN KEY (`club_id`, `application_id`) REFERENCES `membership_applications` (`club_id`, `id`),
  CONSTRAINT `membership_welcome_ibfk_2` FOREIGN KEY (`club_id`, `contact_id`) REFERENCES `contacts` (`club_id`, `id`),
  CONSTRAINT `membership_welcome_ibfk_3` FOREIGN KEY (`club_id`, `membership_id`) REFERENCES `memberships` (`club_id`, `id`),
  CONSTRAINT `membership_welcome_ibfk_4` FOREIGN KEY (`club_id`, `entry_id`) REFERENCES `finance_entries` (`club_id`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `memberships` (
  `id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `club_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `contact_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `member_number` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `starts_on` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `ends_on` mediumtext COLLATE utf8mb4_unicode_ci,
  `version` int NOT NULL DEFAULT '1',
  `starts_precision` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT (_utf8mb4'day'),
  PRIMARY KEY (`club_id`,`id`),
  UNIQUE KEY `club_id` (`club_id`,`member_number`),
  KEY `membership_contact` (`club_id`,`contact_id`),
  CONSTRAINT `memberships_ibfk_1` FOREIGN KEY (`club_id`, `contact_id`) REFERENCES `contacts` (`club_id`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `minutes` (
  `club_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `title` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `meeting_on` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `source_kind` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `body_html` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT (_utf8mb4''),
  `state` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `version` int NOT NULL DEFAULT '1',
  `created_at` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_by` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `updated_at` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `updated_by` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `published_at` mediumtext COLLATE utf8mb4_unicode_ci,
  `published_by` mediumtext COLLATE utf8mb4_unicode_ci,
  `request_key` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `request_hash` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`club_id`,`id`),
  UNIQUE KEY `club_id` (`club_id`,`request_key`),
  KEY `minutes_listing` (`club_id`,`state`,`meeting_on`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `minutes_files` (
  `club_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `minutes_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `mime` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `bytes` mediumblob NOT NULL,
  `sha256` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`club_id`,`minutes_id`),
  CONSTRAINT `minutes_files_ibfk_1` FOREIGN KEY (`club_id`, `minutes_id`) REFERENCES `minutes` (`club_id`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `minutes_history` (
  `sequence` int NOT NULL AUTO_INCREMENT,
  `club_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `minutes_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `actor_id` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `action` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `version` int NOT NULL,
  `happened_at` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `details` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`sequence`),
  KEY `club_id` (`club_id`,`minutes_id`),
  CONSTRAINT `minutes_history_ibfk_1` FOREIGN KEY (`club_id`, `minutes_id`) REFERENCES `minutes` (`club_id`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `notification_preferences` (
  `club_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `actor_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `topics` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT (_utf8mb4'[]'),
  `version` int NOT NULL DEFAULT '1',
  `updated_at` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`club_id`,`actor_id`),
  KEY `actor_id` (`actor_id`),
  CONSTRAINT `notification_preferences_ibfk_1` FOREIGN KEY (`actor_id`) REFERENCES `actors` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `number_counters` (
  `club_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `kind` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `next_number` int NOT NULL,
  PRIMARY KEY (`club_id`,`kind`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `outgoing_invoice_deliveries` (
  `club_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `invoice_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `recipient` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `subject` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `body` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `document_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `document_sha256` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `actor_id` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `state` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `updated_at` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `error` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT (_utf8mb4''),
  PRIMARY KEY (`club_id`,`invoice_id`),
  KEY `club_id` (`club_id`,`document_id`),
  CONSTRAINT `outgoing_invoice_deliveries_ibfk_1` FOREIGN KEY (`club_id`, `invoice_id`) REFERENCES `outgoing_invoices` (`club_id`, `id`),
  CONSTRAINT `outgoing_invoice_deliveries_ibfk_2` FOREIGN KEY (`club_id`, `document_id`) REFERENCES `documents` (`club_id`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `outgoing_invoice_history` (
  `club_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `invoice_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `version` int NOT NULL,
  `action` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `actor_id` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `happened_at` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `snapshot` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`club_id`,`invoice_id`,`version`),
  CONSTRAINT `outgoing_invoice_history_ibfk_1` FOREIGN KEY (`club_id`, `invoice_id`) REFERENCES `outgoing_invoices` (`club_id`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `outgoing_invoices` (
  `club_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `kind` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `parent_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `state` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `version` int NOT NULL DEFAULT '1',
  `payload` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `reviewed_hash` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT (_utf8mb4''),
  `entry_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `document_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `number` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT (_utf8mb4''),
  `created_by` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `issued_at` mediumtext COLLATE utf8mb4_unicode_ci,
  `request_key` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`club_id`,`id`),
  UNIQUE KEY `club_id` (`club_id`,`request_key`),
  UNIQUE KEY `club_id_2` (`club_id`,`entry_id`),
  KEY `club_id_3` (`club_id`,`parent_id`),
  KEY `club_id_4` (`club_id`,`document_id`),
  CONSTRAINT `outgoing_invoices_ibfk_1` FOREIGN KEY (`club_id`, `parent_id`) REFERENCES `outgoing_invoices` (`club_id`, `id`),
  CONSTRAINT `outgoing_invoices_ibfk_2` FOREIGN KEY (`club_id`, `entry_id`) REFERENCES `finance_entries` (`club_id`, `id`),
  CONSTRAINT `outgoing_invoices_ibfk_3` FOREIGN KEY (`club_id`, `document_id`) REFERENCES `documents` (`club_id`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `portal_document_shares` (
  `club_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `document_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `contact_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `revision` int NOT NULL,
  `created_by` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`club_id`,`document_id`,`contact_id`),
  KEY `club_id` (`club_id`,`contact_id`),
  CONSTRAINT `portal_document_shares_ibfk_1` FOREIGN KEY (`club_id`, `document_id`) REFERENCES `documents` (`club_id`, `id`),
  CONSTRAINT `portal_document_shares_ibfk_2` FOREIGN KEY (`club_id`, `contact_id`) REFERENCES `contacts` (`club_id`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `processwire_identities` (
  `pw_user_id` int NOT NULL,
  `actor_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `enabled` int NOT NULL DEFAULT '1',
  `version` int NOT NULL DEFAULT '1',
  PRIMARY KEY (`pw_user_id`),
  UNIQUE KEY `actor_id` (`actor_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `push_deliveries` (
  `subscription_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `message_seq` int NOT NULL,
  `state` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `updated_at` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `error` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT (_utf8mb4''),
  PRIMARY KEY (`subscription_id`,`message_seq`),
  KEY `message_seq` (`message_seq`),
  KEY `push_deliveries_state` (`state`,`updated_at`),
  CONSTRAINT `push_deliveries_ibfk_1` FOREIGN KEY (`subscription_id`) REFERENCES `push_subscriptions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `push_deliveries_ibfk_2` FOREIGN KEY (`message_seq`) REFERENCES `club_chat_messages` (`seq`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `push_subscriptions` (
  `id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `club_id` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `actor_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `endpoint_hash` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `subscription` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `endpoint_hash` (`endpoint_hash`),
  KEY `actor_id` (`actor_id`),
  CONSTRAINT `push_subscriptions_ibfk_1` FOREIGN KEY (`actor_id`) REFERENCES `actors` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `saved_lists` (
  `id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `club_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `filters` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_by` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`club_id`,`id`),
  UNIQUE KEY `club_id` (`club_id`,`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `schema_migrations` (
  `version` int NOT NULL,
  `applied_at` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`version`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `sepa_order_items` (
  `club_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `order_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `entry_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `contact_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `cents` bigint NOT NULL,
  `mandate_version` int NOT NULL,
  `mandate_reference` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `state` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `end_to_end` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`club_id`,`id`),
  UNIQUE KEY `club_id` (`club_id`,`end_to_end`),
  KEY `club_id_2` (`club_id`,`order_id`),
  KEY `club_id_4` (`club_id`,`contact_id`),
  KEY `sepa_active_entry` (`club_id`,`entry_id`),
  CONSTRAINT `sepa_order_items_ibfk_1` FOREIGN KEY (`club_id`, `order_id`) REFERENCES `sepa_orders` (`club_id`, `id`),
  CONSTRAINT `sepa_order_items_ibfk_2` FOREIGN KEY (`club_id`, `entry_id`) REFERENCES `finance_entries` (`club_id`, `id`),
  CONSTRAINT `sepa_order_items_ibfk_3` FOREIGN KEY (`club_id`, `contact_id`) REFERENCES `contacts` (`club_id`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `sepa_orders` (
  `club_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `account_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `collection_date` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `notice_date` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `state` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_by` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `version` int NOT NULL DEFAULT '1',
  `request_key` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `snapshot_hash` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `sealed_snapshot` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `total_cents` bigint NOT NULL,
  `approved_by` mediumtext COLLATE utf8mb4_unicode_ci,
  `approved_at` mediumtext COLLATE utf8mb4_unicode_ci,
  `office_version` int DEFAULT NULL,
  `exported_at` mediumtext COLLATE utf8mb4_unicode_ci,
  `bank_reference` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT (_utf8mb4''),
  PRIMARY KEY (`club_id`,`id`),
  UNIQUE KEY `club_id` (`club_id`,`request_key`),
  KEY `club_id_2` (`club_id`,`account_id`),
  CONSTRAINT `sepa_orders_ibfk_1` FOREIGN KEY (`club_id`, `account_id`) REFERENCES `finance_accounts` (`club_id`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `sepa_return_records` (
  `club_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `item_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `transaction_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `reason` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `document_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `actor_id` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`club_id`,`id`),
  UNIQUE KEY `club_id` (`club_id`,`item_id`),
  UNIQUE KEY `club_id_2` (`club_id`,`transaction_id`),
  KEY `club_id_3` (`club_id`,`document_id`),
  CONSTRAINT `sepa_return_records_ibfk_1` FOREIGN KEY (`club_id`, `item_id`) REFERENCES `sepa_order_items` (`club_id`, `id`),
  CONSTRAINT `sepa_return_records_ibfk_2` FOREIGN KEY (`club_id`, `transaction_id`) REFERENCES `finance_transactions` (`club_id`, `id`),
  CONSTRAINT `sepa_return_records_ibfk_3` FOREIGN KEY (`club_id`, `document_id`) REFERENCES `documents` (`club_id`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `settings_assets` (
  `club_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `mime` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `bytes` mediumblob NOT NULL,
  `sha256` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`club_id`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `settings_catalog` (
  `club_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `kind` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `payload` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `version` int NOT NULL,
  `active` int NOT NULL,
  PRIMARY KEY (`club_id`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `settings_revisions` (
  `club_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `section` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `version` int NOT NULL,
  `payload` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `actor_id` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `happened_at` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`club_id`,`section`,`version`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `settings_role_assignments` (
  `actor_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `club_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `role_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `version` int NOT NULL,
  PRIMARY KEY (`actor_id`),
  KEY `club_id` (`club_id`,`role_id`),
  CONSTRAINT `settings_role_assignments_ibfk_1` FOREIGN KEY (`club_id`, `role_id`) REFERENCES `settings_roles` (`club_id`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `settings_roles` (
  `club_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `members_access` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `finance_access` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `settings_access` int NOT NULL,
  `version` int NOT NULL,
  `inventory_access` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT (_utf8mb4'none'),
  `loans_access` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT (_utf8mb4'none'),
  `documents_access` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT (_utf8mb4'none'),
  `press_access` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT (_utf8mb4'none'),
  `minutes_access` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT (_utf8mb4'none'),
  `website_access` mediumtext COLLATE utf8mb4_unicode_ci,
  PRIMARY KEY (`club_id`,`id`),
  UNIQUE KEY `club_id` (`club_id`,`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `setup_acknowledgements` (
  `club_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `step` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `actor_id` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `completed_at` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`club_id`,`step`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `supplier_bank_details` (
  `club_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `contact_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `iban` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `bic` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `actor_id` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`club_id`,`contact_id`),
  CONSTRAINT `supplier_bank_details_ibfk_1` FOREIGN KEY (`club_id`, `contact_id`) REFERENCES `contacts` (`club_id`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `supplier_contact_requests` (
  `club_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `actor_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `request_key` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `request_hash` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `contact_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`club_id`,`actor_id`,`request_key`),
  KEY `club_id` (`club_id`,`contact_id`),
  CONSTRAINT `supplier_contact_requests_ibfk_1` FOREIGN KEY (`club_id`, `contact_id`) REFERENCES `contacts` (`club_id`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `tax_account_settings` (
  `club_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `year` int NOT NULL,
  `code` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `category` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `active` int NOT NULL,
  `custom` int NOT NULL,
  `version` int NOT NULL DEFAULT '1',
  PRIMARY KEY (`club_id`,`year`,`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `website_drafts` (
  `id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `club_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `kind` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `page_key` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `title` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `summary` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `body` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `version` int NOT NULL,
  `updated_at` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `club_id` (`club_id`,`id`),
  UNIQUE KEY `club_id_2` (`club_id`,`page_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `website_event_publications` (
  `club_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `page_id` int NOT NULL,
  `published_seen` int NOT NULL DEFAULT '0',
  `first_seen_at` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`club_id`,`page_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `website_notification_baselines` (
  `club_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `initialized_at` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`club_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `website_revisions` (
  `club_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `draft_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `version` int NOT NULL,
  `payload` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `actor_id` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `happened_at` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`club_id`,`draft_id`,`version`),
  CONSTRAINT `website_revisions_ibfk_1` FOREIGN KEY (`club_id`, `draft_id`) REFERENCES `website_drafts` (`club_id`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS=1;
