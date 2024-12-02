<?php
namespace GZMP\EDI\Core\Transactions;

use GZMP\EDI\Core\Segments\EDISegmentB2A;

class EDITransaction204 extends EDITransaction
{
    /**
     * Returns the primary reference number for the EDI transaction. This is the number the customer/partner uses to
     * identify the load.
     */
    public function getCustomersReferenceNumber(): string
    {
        return $this->getDataValue('B2', 4) ?? '';
    }

    /**
     * Return text summarizing this transaction.
     */
    public function getSummaryText(): string
    {
        foreach ($this->segments as $segment) {
            if ($segment instanceof EDISegmentB2A) {
                return $segment->getPurpose();
            }
        }

        return '';
    }
}
