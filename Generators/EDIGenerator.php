<?php
namespace GZMP\EDI\Generators;

use PDODB;
use GZMP\EDI\EDIPartner;

/**
 * Class for generating new EDI.
 */
class EDIGenerator extends EDIPartner
{
    public function __construct(PDODB $db, $input = null)
    {
        parent::__construct($db, $input);
    }

    public function getReceiverCode(?string $customers_reference_number = ''): string
    {
        if (!$this->usesMultipleGroupCodes()) {
            return $this->receiver_code;
        }

        if (empty($customers_reference_number)) {
            return '';
        }

        // Ordering by type (ASC) should prioritize the tender
        $query = <<<SQL
            SELECT group_partner_code
            FROM edi_transactions_log
            WHERE partner_id = :partner_id
              AND customers_reference_number = :customers_reference_number
              AND group_partner_code IS NOT NULL
              AND group_partner_code != ''
            ORDER BY type, date DESC
        SQL;
        $data = [
            'partner_id' => $this->id,
            'customers_reference_number' => $customers_reference_number,
        ];
        $gcn = $this->db->getValue($query, $data);

        if (!is_string($gcn)) {
            return '';
        }

        return $gcn;
    }
}
