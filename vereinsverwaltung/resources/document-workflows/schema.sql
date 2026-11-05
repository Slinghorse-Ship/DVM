CREATE TABLE IF NOT EXISTS dwf_folders (
    id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    club_id VARCHAR(128) COLLATE utf8mb4_bin NOT NULL,
    name VARCHAR(240) NOT NULL,
    parent_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
    version INT UNSIGNED NOT NULL,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY dwf_folder_tenant (club_id, id),
    KEY dwf_folder_parent (club_id, parent_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dwf_templates (
    id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    club_id VARCHAR(128) COLLATE utf8mb4_bin NOT NULL,
    title VARCHAR(240) NOT NULL,
    description TEXT NOT NULL,
    fields_json JSON NOT NULL,
    provenance_json JSON NOT NULL,
    version INT UNSIGNED NOT NULL,
    published_version INT UNSIGNED NOT NULL DEFAULT 0,
    status VARCHAR(20) NOT NULL,
    created_by VARCHAR(128) COLLATE utf8mb4_bin NOT NULL,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY dwf_template_tenant (club_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dwf_template_versions (
    club_id VARCHAR(128) COLLATE utf8mb4_bin NOT NULL,
    template_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    version INT UNSIGNED NOT NULL,
    snapshot_json JSON NOT NULL,
    published_by VARCHAR(128) COLLATE utf8mb4_bin NOT NULL,
    published_at DATETIME(6) NOT NULL,
    PRIMARY KEY (club_id, template_id, version),
    CONSTRAINT dwf_template_version_owner FOREIGN KEY (club_id, template_id)
        REFERENCES dwf_templates (club_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dwf_documents (
    id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    club_id VARCHAR(128) COLLATE utf8mb4_bin NOT NULL,
    folder_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
    template_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    template_version INT UNSIGNED NOT NULL,
    template_title VARCHAR(240) NOT NULL,
    template_description TEXT NOT NULL,
    title VARCHAR(240) NOT NULL,
    fields_json JSON NOT NULL,
    provenance_json JSON NOT NULL,
    values_json JSON NOT NULL,
    version INT UNSIGNED NOT NULL,
    status VARCHAR(20) NOT NULL,
    assignee_id VARCHAR(128) COLLATE utf8mb4_bin NOT NULL DEFAULT '',
    reviewer_id VARCHAR(128) COLLATE utf8mb4_bin NOT NULL DEFAULT '',
    created_by VARCHAR(128) COLLATE utf8mb4_bin NOT NULL,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY dwf_document_tenant (club_id, id),
    KEY dwf_document_list (club_id, status, updated_at),
    KEY dwf_document_folder (club_id, folder_id),
    CONSTRAINT dwf_document_template FOREIGN KEY (club_id, template_id)
        REFERENCES dwf_templates (club_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dwf_document_versions (
    club_id VARCHAR(128) COLLATE utf8mb4_bin NOT NULL,
    document_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    revision INT UNSIGNED NOT NULL,
    snapshot_json JSON NOT NULL,
    actor_id VARCHAR(128) COLLATE utf8mb4_bin NOT NULL,
    action VARCHAR(32) NOT NULL,
    created_at DATETIME(6) NOT NULL,
    PRIMARY KEY (club_id, document_id, revision),
    CONSTRAINT dwf_document_version_owner FOREIGN KEY (club_id, document_id)
        REFERENCES dwf_documents (club_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dwf_activities (
    id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    club_id VARCHAR(128) COLLATE utf8mb4_bin NOT NULL,
    document_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    revision INT UNSIGNED NOT NULL,
    actor_id VARCHAR(128) COLLATE utf8mb4_bin NOT NULL,
    action VARCHAR(32) NOT NULL,
    comment TEXT NOT NULL,
    created_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY dwf_activity_revision (club_id, document_id, revision),
    CONSTRAINT dwf_activity_owner FOREIGN KEY (club_id, document_id)
        REFERENCES dwf_documents (club_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dwf_attachments (
    id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    club_id VARCHAR(128) COLLATE utf8mb4_bin NOT NULL,
    document_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    name VARCHAR(240) NOT NULL,
    sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    size_bytes INT UNSIGNED NOT NULL,
    file_bytes LONGBLOB NOT NULL,
    added_revision INT UNSIGNED NOT NULL,
    removed_revision INT UNSIGNED NULL,
    created_by VARCHAR(128) COLLATE utf8mb4_bin NOT NULL,
    created_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    KEY dwf_attachment_revision (club_id, document_id, added_revision, removed_revision),
    CONSTRAINT dwf_attachment_owner FOREIGN KEY (club_id, document_id)
        REFERENCES dwf_documents (club_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dwf_signatures (
    id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
    club_id VARCHAR(128) COLLATE utf8mb4_bin NOT NULL,
    document_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    revision INT UNSIGNED NOT NULL,
    recipient_id VARCHAR(128) COLLATE utf8mb4_bin NOT NULL,
    document_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    statement TEXT NOT NULL,
    requested_by VARCHAR(128) COLLATE utf8mb4_bin NOT NULL,
    requested_at DATETIME(6) NOT NULL,
    signed_at DATETIME(6) NULL,
    signer_name VARCHAR(200) NULL,
    signature_png BLOB NULL,
    signature_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    UNIQUE KEY dwf_signer_revision (club_id,document_id,revision,recipient_id),
    KEY dwf_signer_own (club_id,recipient_id,requested_at),
    CONSTRAINT dwf_signature_version FOREIGN KEY (club_id,document_id,revision)
        REFERENCES dwf_document_versions (club_id,document_id,revision)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dwf_signature_files (
    signature_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
    name VARCHAR(240) NOT NULL,
    file_bytes LONGBLOB NOT NULL,
    sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    created_at DATETIME(6) NOT NULL,
    CONSTRAINT dwf_signed_file_owner FOREIGN KEY (signature_id) REFERENCES dwf_signatures(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dwf_reminders (
    id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
    club_id VARCHAR(128) COLLATE utf8mb4_bin NOT NULL,
    signature_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    requested_by VARCHAR(128) COLLATE utf8mb4_bin NOT NULL,
    request_key CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    snapshot_json JSON NOT NULL,
    snapshot_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    state VARCHAR(16) NOT NULL,
    error VARCHAR(240) NOT NULL DEFAULT '',
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    UNIQUE KEY dwf_reminder_request (club_id,request_key,signature_id),
    KEY dwf_reminder_pending (state,created_at),
    CONSTRAINT dwf_reminder_owner FOREIGN KEY (signature_id) REFERENCES dwf_signatures(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
