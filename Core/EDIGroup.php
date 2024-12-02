<?php
namespace GZMP\EDI\Core;

use Exception;
use GZMP\EDI\Core\Segments\EDISegment;
use GZMP\EDI\Core\Transactions\EDITransaction;

/**
 * A class representing an EDI group.
 */
class EDIGroup extends EDIBase
{
    public const FUNCTIONAL_ID_CODES = [
        204 => 'SM',
        210 => 'IM',
        214 => 'QM',
        990 => 'GF',
        997 => 'FA',
    ];

    public const SUPPORTED_VERSIONS = [
        '004010',
        '005030',
    ];

    // The setProperties() method is unable to set private properties
    protected string $functional_id_code = '';
    protected string $application_sender_code = '';
    protected string $application_receiver_code = '';
    protected string $responsible_agency_code = 'X';
    protected array $transactions = [];

    /**
     * The number of transaction sent as specified by the group footer.
     */
    protected int $transactionCount = 0;

    /**
     * The number of transactions accepted.
     */
    protected int $acceptedCount = 0;

    protected string $acceptanceCode = '';

    /**
     * An array of AK9 error codes for errors found in this group.
     */
    protected array $errorCodes = [];

    public function __construct($properties = null)
    {
        parent::__construct($properties);

        if (isset($properties['transaction_type_code'])) {
            $this->setFunctionalIdCodeFromTransCode($properties['transaction_type_code']);
        }
    }

    /**
     * Converts a transaction type code to a group functional id code and then sets group's corresponding property.
     */
    public function setFunctionalIdCodeFromTransCode($transaction_type_code)
    {
        if (array_key_exists($transaction_type_code, self::FUNCTIONAL_ID_CODES)) {
            $this->functional_id_code = self::FUNCTIONAL_ID_CODES[$transaction_type_code];
        }
    }

    /**
     * Create and return a new EDIGroup.
     */
    public static function create(
        $x12_version = null,
        $transaction_type_code = null,
        $sender_code = null,
        $receiver_code = null
    ): EDIGroup {
        $properties = array(
            'transaction_type_code' => $transaction_type_code,
            'application_sender_code' => $sender_code,
            'application_receiver_code' => $receiver_code,
            'x12_version' => $x12_version,
        );

        return new EDIGroup($properties);
    }

    /**
     * Return the transaction type code which corresponds to the given group function code or the group object's
     * function code.
     */
    public function getTransactionTypeCode(string $functional_id_code = '')
    {
        if (empty($functional_id_code)) {
            $functional_id_code = $this->functional_id_code;
        }

        $codes = array_flip(self::FUNCTIONAL_ID_CODES);
        if (isset($codes[$functional_id_code])) {
            return $codes[$functional_id_code];
        }

        return '';
    }

    public function getFunctionalIdCode()
    {
        return $this->functional_id_code;
    }

    public function getX12Version()
    {
        return $this->x12_version;
    }

    public function getErrorCodes(): array
    {
        return $this->errorCodes;
    }

    /**
     * Add an EDITransaction object to the array of this group's transactions.
     */
    public function addTransaction(EDITransaction $transaction)
    {
        if ($transaction->countSegments()) {
            $this->transactions[] = $transaction;
        }
    }

    /**
     * Return an array of this group's transactions as objects.
     */
    public function getTransactions()
    {
        return $this->transactions;
    }

    /**
     * Returns the number of transactions as specified by the group's footer.
     */
    public function getSubmittedTransactionCount()
    {
        return $this->transactionCount;
    }

    /**
     * Return an array of segments representing this group as either objects or arrays.
     */
    public function getSegments(bool $segmentsAsArray = false): array
    {
        $segments = [];
        foreach ($this->transactions as $transaction) {
            // append transaction segments to previous transaction(s) (if any)
            $segments = array_merge($segments, $transaction->getSegments($segmentsAsArray));
        }

        if (empty($segments)) {
            return [];
        }

        // add group header segment to beginning of array
        array_unshift($segments, $this->generateHeader());
        // add group footer segment to end of array
        $segments[] = $this->generateFooter();

        if ($segmentsAsArray) {
            foreach ($segments as $i => $segment) {
                if ($segment instanceof EDISegment) {
                    $segments[$i] = $segment->getValues();
                }
            }
        }

        return $segments;
    }

    /**
     * Return an EDISegment object representing this group's header.
     */
    public function generateHeader(): EDISegment
    {
        return new EDISegment([
            'GS',
            $this->functional_id_code,
            $this->application_sender_code,
            $this->application_receiver_code,
            $this->date,
            $this->time,
            $this->control_number,
            $this->responsible_agency_code,
            $this->x12_version,
        ]);
    }

    /**
     * Return an EDISegment object representing this group's footer.
     */
    public function generateFooter(): EDISegment
    {
        return new EDISegment([
            'GE',
            count($this->transactions),
            $this->control_number,
        ]);
    }

    /**
     * Convert an array representing the group header into property values
     */
    public function parseHeader(array $segment): void
    {
        // 0 should = GS
        $this->functional_id_code = $segment[1];
        $this->application_sender_code = $segment[2];
        $this->application_receiver_code = $segment[3];
        $this->date = $segment[4];
        $this->time = $segment[5];
        $this->control_number = $segment[6];
        $this->responsible_agency_code = $segment[7];
        $this->x12_version = $segment[8];

        $this->validateFunctionalIdCode();
        $this->validateVersion();
        $this->validateControlNumber();
    }

    /**
     * Use the group footer to check the validity of the group.
     */
    public function parseFooter(array $segment): void
    {
        if (strtoupper($segment[0]) != 'GE' || count($segment) < 3) {
            $this->addErrorCode(3);
            throw new Exception('Invalid group footer.', 3);
        }

        $this->transactionCount = $segment[1];

        $errors = [];
        try {
            $this->validateTransactionCount($segment[1]);
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

    protected function validateFunctionalIdCode()
    {
        if (!in_array($this->functional_id_code, self::FUNCTIONAL_ID_CODES)) {
            $msg = "Unsupported group type $this->functional_id_code.";
            $this->addErrorCode(1);
            throw new Exception($msg, 1);
        }
    }

    protected function validateVersion()
    {
        if (!in_array($this->x12_version, self::SUPPORTED_VERSIONS)) {
            $msg = "Unsupported group version {$this->x12_version}.";
            $this->addErrorCode(2);
            throw new Exception($msg, 2);
        }
    }

    protected function validateControlNumber()
    {
        if (empty($this->control_number) || trim($this->control_number) == '') {
            $msg = "Invalid group control number '{$this->control_number}'.";
            $this->addErrorCode(6);
            throw new Exception($msg, 6);
        }
    }

    protected function validateTransactionCount(string $footerCount): void
    {
        $num_trans = count($this->transactions);
        if ($footerCount != $num_trans) {
            $msg = "Group footer expects {$footerCount} transactions, but {$num_trans} counted in group";
            $msg .= " {$this->control_number}.";
            $this->addErrorCode(5);
            throw new Exception($msg, 5);
        }
    }

    protected function validateFooterControlNumber(string $controlNumber): void
    {
        if ($controlNumber != $this->control_number) {
            $msg = "Group control number {$this->control_number} expected in group footer but {$controlNumber} found.";
            $this->addErrorCode(4);
            throw new Exception($msg, 4);
        }
    }

    /**
     * Loop through an array of segments as arrays representing one group, create corresponding transactions.
     */
    public function parseTransactions(array $segments)
    {
        $skipToNextTransaction = false;
        $controlNumbers = [];

        foreach ($segments as $segment) {
            $segment[0] = strtoupper($segment[0]);

            if ($skipToNextTransaction && $segment[0] != 'ST') {
                continue;
            }

            switch ($segment[0]) {
                case 'ST':
                    $skipToNextTransaction = false;
                    try {
                        $transaction = EDITransaction::create('', $segment[1] ?? $this->getTransactionTypeCode());
                        $transaction->parseHeader($segment);
                        // Ensure control number is unique.
                        if (in_array($transaction->getControlNumber(), $controlNumbers)) {
                            $transaction->addErrorCode(23);
                            $msg = "An additional transaction with the same control number of";
                            $msg .= " {$transaction->getControlNumber()} was found in group.";
                            throw new Exception($msg, 23);
                        }
                        $controlNumbers[] = $transaction->getControlNumber();
                    } catch (Exception $exception) {
                        $this->warnings[] = $exception->getMessage();
                        if (!empty($transaction) && $transaction instanceof EDITransaction) {
                            $transaction->setAcceptance('R'); // Transaction rejected
                            $this->addTransaction($transaction);
                            unset($transaction);
                        }
                        $skipToNextTransaction = true;
                    }
                    break;
                case 'SE':
                    try {
                        $transaction->parseFooter($segment);
                    } catch (Exception $exception) {
                        $this->warnings[] = $exception->getMessage();
                    }
                    $transaction->setAcceptance();
                    $this->addTransaction($transaction);
                    unset($transaction);
                    break;
                case 'GE':
                    // Once we reach the group footer, we're done parsing transactions for this group so jump out of
                    // the switch and foreach loop.
                    break 2;
                default:
                    if (!isset($transaction)) {
                        break;
                    }
                    $segment = EDISegment::create($segment, false);
                    $segment->validate();
                    $transaction->addSegment($segment);
            } // end switch
        } // end loop for each segment
    }

    /**
     * Return the number of transactions in this group.
     */
    public function countTransactions()
    {
        return count($this->transactions);
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
        $warnings = $this->warnings;

        foreach ($this->getTransactions() as $transaction) {
            $warnings = array_merge($warnings, $transaction->getWarnings());
        }

        return $warnings;
    }

    /**
     * Set the code for accepted/rejected to be used by acknowledgment.
     */
    public function setAcceptance(string $acceptanceCode = ''): void
    {
        $this->acceptedCount = 0;
        foreach ($this->getTransactions() as $transaction) {
            if ($transaction->getAcceptance() !== 'R') {
                $this->acceptedCount++;
            }
        }

        $this->acceptanceCode = 'A';

        if (!empty($acceptanceCode)) {
            $this->acceptanceCode = $acceptanceCode;
        } elseif ($this->acceptedCount === 0) {
            $this->acceptanceCode = 'R'; // Group rejected
        } elseif ($this->acceptedCount < $this->countTransactions()) {
            $this->acceptanceCode = 'P'; // Partially rejected
        } elseif (!empty($this->errorCodes)) {
            $this->acceptanceCode = 'E';
        } else {
            foreach ($this->getTransactions() as $transaction) {
                if ($transaction->getErrorCodes()) {
                    $this->acceptanceCode = 'E';
                    break;
                }
            }
        }
    }

    /**
     * Return the code specifying if this group was accepted.
     */
    public function getAcceptanceCode(): string
    {
        return $this->acceptanceCode;
    }

    public function getAcceptedCount(): string
    {
        return $this->acceptedCount;
    }

    public function getSenderCode(): string
    {
        return $this->application_sender_code;
    }

    public function getReceiverCode(): string
    {
        return $this->application_receiver_code;
    }
}
