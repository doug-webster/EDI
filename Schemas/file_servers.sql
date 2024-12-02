CREATE TABLE file_servers
(
    id                 SERIAL  NOT NULL PRIMARY KEY,
    label              character varying,
    protocol           character varying DEFAULT 'FTP',
    host               character varying,
    port               integer,
    "user"             character varying,
    pass               character varying,
    outbound_directory character varying,
    inbound_directory  character varying,

    created_at         timestamp,
    created_by         int,
    updated_at         timestamp,
    updated_by         int,
    deleted_at         timestamp,
    deleted_by         int
);

COMMENT ON COLUMN "file_servers"."label" IS 'For reference only';
COMMENT ON COLUMN "file_servers"."protocol" IS 'FTP or SFTP';
