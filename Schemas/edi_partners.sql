CREATE TABLE edi_partners
(
    id                           SERIAL NOT NULL PRIMARY KEY,
    partner                      character varying NOT NULL,

    file_server_id               integer,
    inbound_filename             character varying,
    outbound_filename            character varying,
    outbound_filename_prefix     character varying,
    outbound_filename_postfix    character varying,

    -- These have to do with the ISA header
    headers                      jsonb,
    receiver_code                character varying,

    -- ignore_segments? best way to include/exclude segments, data points
    class204                     varchar,
    class990                     varchar,
    class214                     varchar,
    class210                     varchar,

    incoming_frequency           int,
    tender_in_customers          jsonb,
    tender_responses             jsonb,

    status_updates_out           jsonb,
    status_updates_out_frequency int,

    billing                      jsonb,
    billing_codes                jsonb,

    additional_data              jsonb,

    created_at                   timestamp,
    created_by                   int,
    updated_at                   timestamp,
    updated_by                   int,
    deleted_at                   timestamp,
    deleted_by                   int
);
COMMENT ON COLUMN edi_partners.partner IS 'The company providing EDI services; just for display/convenience.';
COMMENT ON COLUMN edi_partners.tender_in_customers IS 'Maps codes to customer ids. This is how to determine which customer a incoming 204 should be associated with.';
COMMENT ON COLUMN edi_partners.inbound_filename IS 'Filename format of incoming EDI files for which to look for on remote server (helps to filter out files we don''t want.';
COMMENT ON COLUMN edi_partners.outbound_filename IS 'Filename to use for the outgoing EDI file if a specific filename is required.';
COMMENT ON COLUMN edi_partners.outbound_filename_prefix IS 'If a specific prefix is required for the filename; can use {$type_code} to insert the transaction type code';
COMMENT ON COLUMN edi_partners.outbound_filename_postfix IS 'If a specific postfix or extension is required for the filename; can use {$type_code} to insert the transaction type code';
COMMENT ON COLUMN edi_partners.receiver_code IS 'For the Group Header [GS]';
COMMENT ON COLUMN edi_partners.incoming_frequency IS 'How often, in minutes, to check for new incoming EDI messages.';
COMMENT ON COLUMN edi_partners.status_updates_out_frequency IS 'How often, in minutes, to send any queued outgoing EDI messages.';
COMMENT ON COLUMN edi_partners.receiver_code IS 'The receiver_code are values to send in the group header.';

