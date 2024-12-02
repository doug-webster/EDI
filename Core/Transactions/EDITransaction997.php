<?php
namespace GZMP\EDI\Core\Transactions;

use GZMP\EDI\Core\Segments\EDISegment;

class EDITransaction997 extends EDITransaction
{
    /**
     * Create and return new EDITransaction997.
     */
    public static function create(string $scac, $transaction_type_code = 997): EDITransaction997
    {
        return parent::create($scac, 997);
    }

    public function addSegmentAK1(string $functionalIdCode, string $controlNumber, string $x12Version)
    {
        $segment = [
            'AK1',
            $functionalIdCode,
            $controlNumber,
        ];

        if ((int)$this->x12_version >= 4040) {
            $segment[] = $x12Version;
        }

        $this->addSegment(EDISegment::create($segment));
    }

    public function addSegmentAK2(string $transactionType, string $controlNumber)
    {
        $segment = [
            'AK2',
            $transactionType,
            $controlNumber,
        ];

        $this->addSegment(EDISegment::create($segment));
    }

    public function addSegmentAK3(string $segmentId, int $positionInTransaction, array $errorCodes, $loopId = '')
    {
        if (empty($errorCodes)) {
            return;
        }

        $segment = [
            'AK3',
            $segmentId,
            $positionInTransaction,
            $loopId,
        ];

        // Include no more than 5 unique error codes.
        $errorCodes = array_slice(array_unique($errorCodes), 0, 5);

        foreach ($errorCodes as $errorCode) {
            $segment[] = $errorCode;
        }

        $this->addSegment(EDISegment::create($segment));
    }

    public function addSegmentAK4(int $positionInSegment, ?int $dataElementId, int $errorCode, $value)
    {
        $segment = [
            'AK4',
            $positionInSegment,
            $dataElementId,
            $errorCode,
            $value,
        ];

        $this->addSegment(EDISegment::create($segment));
    }

    public function addSegmentAK5(string $acceptanceCode, array $errorCodes)
    {
        $segment = [
            'AK5',
            $acceptanceCode,
        ];

        // Include no more than 5 unique error codes.
        $errorCodes = array_slice(array_unique($errorCodes), 0, 5);

        foreach ($errorCodes as $errorCode) {
            $segment[] = $errorCode;
        }

        $this->addSegment(EDISegment::create($segment));
    }

    public function addSegmentAK9(
        string $acceptanceCode,
        int $transactionsCount,
        int $transactionsReceivedCount,
        int $transactionsAcceptedCount,
        array $errorCodes
    ) {
        $segment = [
            'AK9',
            $acceptanceCode,
            $transactionsCount,
            $transactionsReceivedCount,
            $transactionsAcceptedCount,
        ];

        // Include no more than 5 unique error codes.
        $errorCodes = array_slice(array_unique($errorCodes), 0, 5);

        foreach ($errorCodes as $errorCode) {
            $segment[] = $errorCode;
        }

        $this->addSegment(EDISegment::create($segment));
    }
}
