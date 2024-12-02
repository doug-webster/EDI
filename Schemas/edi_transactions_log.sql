CREATE TABLE edi_transactions_log
(
    id                         BIGSERIAL NOT NULL PRIMARY KEY,
    partner_id                 integer,
    interchange_control_number int,
    interchange_partner_code   varchar,
    group_control_number       int,
    group_partner_code         varchar,
    control_number             varchar(9),
    type                       smallint,
    status                     varchar,
    customers_reference_number varchar,
    load_id                    integer,
    contents                   jsonb,
    date                       timestamp,
    response_date              timestamp,
    response                   text,
    file                       varchar,
    comments                   text
);
COMMENT ON COLUMN edi_transactions_log.type IS 'Type of EDI message/"Transaction Set Identifier Code" (204, 214, etc.)';
COMMENT ON COLUMN edi_transactions_log.status IS 'Deleted, Queued, Sent, or Received';
COMMENT ON COLUMN edi_transactions_log.customers_reference_number IS 'Primary EDI reference number found in the B2.04, B1.02, B10.01, and B3.03.';
COMMENT ON COLUMN edi_transactions_log.response_contents IS 'The contents of the functional acknowledgment (997) sent or received.';
COMMENT ON COLUMN edi_transactions_log.file IS 'The path/filename to the corresponding EDI file.';
COMMENT ON COLUMN edi_transactions_log.comments IS 'System messages related to issues sending or processing the transaction.';

CREATE INDEX edi_transactions_log_status_partner_id_idx ON edi_transactions_log (status, partner_id);
CREATE INDEX edi_transactions_log_partner_id_customers_ref_num_idx ON edi_transactions_log (partner_id, customers_reference_number);
CREATE INDEX edi_transactions_log_type_group_cnum_partner_id_cnum_idx ON edi_transactions_log (type, group_control_number, partner_id, control_number);
CREATE INDEX edi_transactions_log_date_type_partner_id_idx ON edi_transactions_log (date, type, partner_id);
