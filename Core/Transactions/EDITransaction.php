<?php
namespace GZMP\EDI\Core\Transactions;

use Exception;
use GZMP\EDI\Core\EDIBase;
use GZMP\EDI\Core\EDIGroup;
use GZMP\EDI\Core\Segments\EDISegment;

class EDITransaction extends EDIBase
{
    // The setProperties() method is unable to set private properties
    protected string $transaction_type_code;
    protected $partner_code; // code for trading partner
    // protected $repetition_separator;
    // protected $component_terminator = '>';
    protected array $segments = [];
    protected array $data = [];
    protected array $loop_index = [];
    protected string $timezone_code = '';
    protected string $scac = '';
    protected string $acceptanceCode = '';
    /**
     * An array of AK5 error codes for errors found in this transaction.
     */
    protected array $errorCodes = [];

    /**
     * Similar to __construct, returns a new EDITransaction or child class based on the specified type.
     */
    public static function create(string $scac, $transaction_type_code): EDITransaction
    {
        $class = EDITransaction::getTypeSpecificTransactionClass($transaction_type_code);

        $properties = array(
            'scac' => $scac,
            'transaction_type_code' => $transaction_type_code,
        );

        return new $class($properties);
    }

    /**
     * Set the code for accepted/rejected to be used by acknowledgment.
     */
    public function setAcceptance(string $acceptanceCode = ''): void
    {
        $this->acceptanceCode = 'A';

        if (!empty($acceptanceCode)) {
            $this->acceptanceCode = $acceptanceCode;
        } elseif (!empty($this->errorCodes)) {
            $this->acceptanceCode = 'E';
        } else {
            foreach ($this->getSegments() as $segment) {
                if ($segment->getErrorCodes() || $segment->getElementErrors()) {
                    $this->acceptanceCode = 'E';
                    break;
                }
            }
        }
    }

    /**
     * Return the code specifying if this transaction was accepted.
     */
    public function getAcceptance(): string
    {
        return $this->acceptanceCode;
    }

    /**
     * Return the appropriate class name for the given transaction type.
     */
    public static function getTypeSpecificTransactionClass($transaction_type_code)
    {
        $class = __NAMESPACE__ . "\\EDITransaction{$transaction_type_code}";
        if (!class_exists($class)) {
            $class = __NAMESPACE__ . '\\Transactions\\EDITransaction';
        }
        if (!class_exists($class)) {
            throw new Exception("$class does not exist.");
        }

        return $class;
    }

    public function __call($name, $arguments)
    {
        if (preg_match('/^addSegment([A-Z0-9]{2,3})$/', $name, $matches)) {
            $className = (class_exists("GZMP\EDI\Core\Segments\EDISegment{$matches[0]}"))
                ? "GZMP\EDI\Core\Segments\EDISegment{$matches[0]}" : 'GZMP\EDI\Core\Segments\EDISegment';
            $this->addSegment(new $className($arguments));
        }
    }

    /**
     * Add an EDISegment or child object to the array of this transaction's segments.
     */
    public function addSegment(EDISegment $segment = null)// default value only until union types in PHP 8
    {
        if ($segment && count($segment->getValues()) > 1) {
            $this->segments[] = $segment;
        }
    }

    /*
     * Fills the transactions segments property given an array of EDISegment objects or an array of arrays representing
     *  the values of segments.
     */
    public function setSegments(array $segments, bool $conform = false): void
    {
        $this->segments = [];

        foreach ($segments as $segment) {
            if (!$segment instanceof EDISegment) {
                $segment = EDISegment::create($segment, $conform);
            }

            switch ($segment->getId()) {
                case 'ST':
                    $this->parseHeader($segment->getValues());
                    break;
                case 'SE':
                    $this->parseFooter($segment->getValues());
                    break;
                default:
                    $this->segments[] = $segment;
            }
        }
    }

    /**
     * Return this transaction's type code.
     */
    public function getTransactionTypeCode()
    {
        return $this->transaction_type_code;
    }

    /*public function __toString(): string
    {
        $segments = [];

        foreach ($this->segments as $segment) {
            $segments[] = $segment->toArray();
        }

        // add transaction header segment to beginning of array
        array_unshift($segments, $this->generateHeader());

        // add transaction footer segment to end of array
        $segments[] = $this->generateFooter();

        return implode($this->segment_terminator, $segments);
    }*/

    /**
     * Return an array of segments representing this transaction as either objects or arrays.
     */
    public function getSegments(bool $segmentsAsArray = false): array
    {
        $segments = $this->segments;

        // add transaction header segment to beginning of array
        array_unshift($segments, $this->generateHeader());

        // add transaction footer segment to end of array
        $segments[] = $this->generateFooter();

        if ($segmentsAsArray) {
            foreach ($segments as $i => $segment) {
                $segments[$i] = $segment->getValues();
            }
        }

        return $segments;
    }

    public function getErrorCodes(): array
    {
        return $this->errorCodes;
    }

    /**
     * Return an EDISegment object representing this transaction's header.
     */
    public function generateHeader(): EDISegment
    {
        return new EDISegment([
            'ST',
            $this->transaction_type_code,
            $this->control_number,
        ]);
    }

    /**
     * Return an EDISegment object representing this transaction's footer.
     */
    public function generateFooter(): EDISegment
    {
        return new EDISegment([
            'SE',
            count($this->segments) + 2, // Add two for header and footer
            $this->control_number,
        ]);
    }

    /**
     * Convert an array representing the transaction header into property values
     */
    public function parseHeader(array $segment): void
    {
        // 0 should = ST
        $this->transaction_type_code = $segment[1];
        $this->control_number = $segment[2];

        $this->validateTypeCode();
        $this->validateControlNumber();
    }

    /**
     * Use the transaction footer to check the validity of the transaction.
     */
    public function parseFooter(array $segment): void
    {
        if (strtoupper($segment[0]) != 'SE' || count($segment) < 3) {
            $this->addErrorCode(2);
            throw new Exception('Invalid transaction footer.', 2);
        }

        $errors = [];
        try {
            $this->validateSegmentCount($segment[1]);
        } catch (Exception $exception) {
            $errors[] = $exception->getMessage();
        }
        try {
            $this->validateFooterControlNumber($segment[2]);
        } catch (Exception $exception) {
            $errors[] = $exception->getMessage();
        }

        if (!empty($errors)) {
            throw new Exception(implode("\n", $errors));
        }
    }

    protected function validateTypeCode()
    {
        if (empty($this->transaction_type_code)) {
            $msg = "Missing transaction type.";
            $this->addErrorCode(6);
            throw new Exception($msg, 6);
        }

        if (!array_key_exists($this->transaction_type_code, EDIGroup::FUNCTIONAL_ID_CODES)) {
            $msg = "Unsupported transaction type $this->transaction_type_code.";
            $this->addErrorCode(1);
            throw new Exception($msg, 1);
        }
    }

    protected function validateControlNumber()
    {
        if (empty($this->control_number) || trim($this->control_number) == '') {
            $msg = "Invalid transaction control number '{$this->control_number}'.";
            $this->addErrorCode(7);
            throw new Exception($msg, 7);
        }
    }

    protected function validateSegmentCount(string $footerCount): void
    {
        $num_segments = count($this->segments) + 2; // +2 for the header and footer lines
        if ($footerCount != $num_segments) {
            $msg = "Transaction footer expects {$footerCount} segments, but {$num_segments} counted in transaction";
            $msg .= " {$this->control_number}.";
            $this->addErrorCode(4);
            throw new Exception($msg, 4);
        }
    }

    protected function validateFooterControlNumber(string $controlNumber): void
    {
        if ($controlNumber != $this->control_number) {
            $msg = "Transaction control number {$this->control_number} expected in transaction footer";
            $msg .= " but {$controlNumber} found.";
            $this->addErrorCode(3);
            throw new Exception($msg, 3);
        }
    }

    /**
     * Set an acknowledgement (997) error code.
     */
    public function addErrorCode(int $code)
    {
        $this->errorCodes[] = $code;
    }

    public function getWarnings()
    {
        return $this->warnings;
    }

    /**
     * Return value from specified segment and index.
     */
    public function getDataValue($segment_code, $data_index, $qualifier_index = null, $qualifiers = null)
    {
        foreach ($this->segments as $segment) {
            if ($segment->getId() != $segment_code) {
                continue;
            }

            if (is_null($qualifier_index) || is_null($qualifiers)) {
                return $segment->getValueByIndex($data_index);
            }

            if ($qualifier_index && is_array($qualifiers)) {
                $value = $segment->getValueByIndex($qualifier_index);
                if (!in_array($value, $qualifiers)) {
                    continue;
                }
            }

            return $segment->getValueByIndex($data_index);
        }

        return null;
    }

    public function countSegments(): int
    {
        return count($this->segments);
    }

    /**
     * Returns the primary reference number for the EDI transaction. This is the number the customer/partner uses to
     * identify the load.
     */
    public function getCustomersReferenceNumber(): string
    {
        return '';
    }

    /**
     * Returns the shipper's load id.
     */
    public function getShippersReferenceNumber(): string
    {
        return '';
    }

    /**
     * Return text summarizing this transaction.
     */
    public function getSummaryText(): string
    {
        return '';
    }


    public function addSegmentN1($entity_type_code, $name, $entity_id_qualifier = null, $entity_id = null)
    {
        if ((string)$name === '') {
            return;
        }

        $segment = array(
            'N1',
            $entity_type_code,
            $name,
        );
        if (!empty($entity_id_qualifier) && !empty($entity_id)) {
            $segment[] = $entity_id_qualifier;
            $segment[] = $entity_id;
        }

        $this->addSegment(EDISegment::create($segment));
    }

    public function addSegmentN3($address)
    {
        if (empty($address)) {
            return;
        }

        $segment = array(
            'N3',
            $address,
        );

        $this->addSegment(EDISegment::create($segment));
    }

    public function addSegmentN4(
        $city,
        $state,
        $postal_code,
        $country = null,
        $location_qualifier = null,
        $location_id = null
    ) {
        $segment = array(
            'N4',
            $city,
            $state,
            $postal_code,
        );

        if (!empty($country)) {
            $segment[] = $country;
        }

        if (!empty($location_qualifier) && !empty($location_id)) {
            $segment = array_pad($segment, 5, '');
            $segment[] = $location_qualifier;
            $segment[] = $location_id;
        }

        $this->addSegment(EDISegment::create($segment));
    }

    public function addSegmentN9($qualifier, $reference_id = null, $description = null)
    {
        if (empty($qualifier) || (empty($reference_id) && empty($description))) {
            return;
        }

        $segment = array(
            'N9',
            $qualifier,
            $reference_id,
            $description,
        );

        $this->addSegment(EDISegment::create($segment));
    }

    public function addSegmentLX($number)
    {
        $segment = array(
            'LX',
            $number,
        );

        $this->addSegment(EDISegment::create($segment));
    }
}
