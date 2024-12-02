CREATE TABLE edi_tender_history (
    id SERIAL NOT NULL PRIMARY KEY,
    edi_tender_id int,
        CONSTRAINT fk_edi_tender_id FOREIGN KEY (edi_tender_id) REFERENCES edi_tenders (id),
    edi_transaction_log_id int,
        CONSTRAINT fk_edi_transaction_log_id FOREIGN KEY (edi_transaction_log_id) REFERENCES edi_transactions_log (id),
    description varchar,
    changes jsonb,
    created_at timestamp,
    created_by integer
);

CREATE INDEX ON edi_tender_history (edi_transaction_log_id);
