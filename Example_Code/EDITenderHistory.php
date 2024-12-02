<?php

namespace GZMP\EDI;

use PDODB;

class EDITenderHistory
{
    public static function record(
        PDODB $db,
        int $tender_id,
        int $log_id,
        ?string $description,
        array $changes,
        int $createdBy
    ) {
        $query = <<<SQL
            INSERT INTO edi_tender_history (
                edi_tender_id,
                edi_transaction_log_id,
                description,
                changes,
                created_at,
                created_by
            ) VALUES (
                :edi_tender_id,
                :edi_transaction_log_id,
                :description,
                :changes,
                now(),
                :created_by
            )
        SQL;

        $data = [
            'edi_tender_id' => $tender_id,
            'edi_transaction_log_id' => $log_id ?: null,
            'description' => $description ?? 'unknown code',
            'changes' => json_encode($changes),
            'created_by' => $createdBy,
        ];

        return $db->insert($query, $data);
    }
}
