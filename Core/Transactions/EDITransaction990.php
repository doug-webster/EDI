<?php
namespace GZMP\EDI\Core\Transactions;

use GZMP\EDI\Core\Metadata\EDICodes;
use GZMP\EDI\Core\Segments\EDISegment;

class EDITransaction990 extends EDITransaction
{
    public static function create(string $scac, $transaction_type_code = 990): EDITransaction990
    {
        return parent::create($scac, 990);
    }

    public function addSegmentB1($load_ref_num, $accept, $inc_response_code = false, $date = null)
    {
        if (is_null($date)) {
            $date = date($this->date_format);
        }

        $reservation_action_code = $accept ? 'A' : 'D';

        $segment = array(
            'B1',
            $this->scac,
            $load_ref_num,
            $date,
            $reservation_action_code,
        );

        if ($inc_response_code) {
            $segment[] = $accept ? 'Y' : 'N';
        }

        $this->addSegment(EDISegment::create($segment));
    }

    public function addSegmentL11($identification, $qualifier, $description, $response = null, $date = null)
    {
        if (is_null($date)) {
            $date = date($this->date_format);
        }

        $segment = array(
            'L11',
            $identification,
            $qualifier,
            $description,
            $date,
            $response,
        );
        $this->addSegment(EDISegment::create($segment));
    }

    public function addSegmentV9($eventCode, $reasonCode = '')
    {
        $segment = [
            'V9',
            $eventCode,
        ];

        if (!empty($reasonCode)) {
            $segment = array_pad($segment, 8, '');
            $segment[] = $reasonCode;
        }

        $this->addSegment(EDISegment::create($segment));
    }

    /**
     * Returns the primary reference number for the EDI transaction. This is the number the customer/partner uses to
     * identify the load.
     */
    public function getCustomersReferenceNumber(): string
    {
        return $this->getDataValue('B1', 2) ?? '';
    }

    /**
     * Returns the shipper's load id.
     */
    public function getShippersReferenceNumber(): string
    {
        return $this->getDataValue('N9', 2, 1, ['CN']) ?? '';
    }

    /**
     * Return text summarizing this transaction.
     */
    public function getSummaryText(): string
    {
        $summary = '';

        foreach ($this->segments as $segment) {
            if (strtoupper($segment->getId()) === 'B1') {
                $summary .= EDICodes::getDescriptionText('B1', 4, $segment->getValueByIndex(4));
            }
            if (strtoupper($segment->getId()) === 'L11' && $segment->getValueByIndex(3)) {
                $summary .= ": {$segment->getValueByIndex(3)}";
            }
        }

        return $summary;
    }

    public function addSegmentL9(string $code, float $amount)
    {
        if (empty($code)) {
            return;
        }

        $segment = array(
            'L9',
            $code,
            $amount,
        );

        $this->addSegment(EDISegment::create($segment));
    }
}
