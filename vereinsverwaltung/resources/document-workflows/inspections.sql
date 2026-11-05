CREATE TABLE IF NOT EXISTS dwf_inspections (
    club_id VARCHAR(64) NOT NULL,
    id CHAR(32) NOT NULL,
    version INT NOT NULL,
    title VARCHAR(240) NOT NULL,
    instruction TEXT NOT NULL,
    assignee_id VARCHAR(64) NOT NULL,
    deadline VARCHAR(10) NOT NULL DEFAULT '',
    status VARCHAR(24) NOT NULL,
    implementation TEXT NOT NULL,
    completed_on VARCHAR(10) NOT NULL DEFAULT '',
    source_json JSON NOT NULL,
    created_by VARCHAR(64) NOT NULL,
    request_key CHAR(32) NOT NULL,
    request_hash CHAR(64) NOT NULL,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (club_id,id),
    UNIQUE KEY dwf_inspection_request (club_id,created_by,request_key),
    KEY dwf_inspection_due (club_id,status,deadline,id),
    KEY dwf_inspection_assignee (club_id,assignee_id,status,id),
    CONSTRAINT dwf_inspection_status CHECK (status IN ('open','in_progress','submitted','returned','verified','cancelled'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS dwf_inspection_versions (
    club_id VARCHAR(64) NOT NULL,
    inspection_id CHAR(32) NOT NULL,
    revision INT NOT NULL,
    action VARCHAR(24) NOT NULL,
    actor_id VARCHAR(64) NOT NULL,
    comment TEXT NOT NULL,
    snapshot_json JSON NOT NULL,
    created_at DATETIME(6) NOT NULL,
    PRIMARY KEY (club_id,inspection_id,revision),
    CONSTRAINT dwf_inspection_history_parent FOREIGN KEY (club_id,inspection_id) REFERENCES dwf_inspections(club_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
