<?php

namespace GZMP\EDI;

use PDODB;
use DateTime;
use DateTimeInterface;
use DateTimeZone;
use Exception;
use Logger;
use PDOStatement;
use GZMP\EDI\Core\Data\EDIData;
use GZMP\EDI\Core\EDIGroup;
use GZMP\EDI\Core\EDInterchange;
use GZMP\EDI\Core\Transactions\EDITransaction;

class EDILogger
{
    private PDODB $db;
    protected static $select_query;
    protected static $insert_query;
    protected static $queue_query;
    protected static $complete_queued_query;
    protected static $update_load_id_query;
    protected static $update_group_response_query;
    protected static $update_transaction_response_query;
    protected static $update_status_query;
    protected static $delete_query;
    protected string $fileLogPath = '';

    public function __construct(PDODB $db)
    {
        $this->db = $db;
    }

    protected function prepare(string $query, string $property)
    {
        $sth = $this->db->prepare($query);

        self::$$property = $sth;

        return $sth;
    }

    /**
     * Prepares the query for inserting a new record into the log.
     */
    protected function prepareSelectQuery()//: PDOStatement
    {
        $query = <<<SQL
            SELECT *
            FROM edi_transactions_log
            WHERE id = :id
        SQL;

        return $this->prepare($query, 'select_query');
    }

    /**
     * Returns the PDO statement handle for the insert query.
     */
    protected function getSelectQuery(): PDOStatement
    {
        if (self::$select_query) {
            return self::$select_query;
        }

        return $this->prepareSelectQuery();
    }

    /**
     * Prepares the query for inserting a new record into the log.
     */
    protected function prepareInsertQuery()//: PDOStatement
    {
        $query = <<<SQL
        INSERT INTO edi_transactions_log
        (
         interchange_control_number,
         group_control_number,
         control_number,
         type,
         status,
         customers_reference_number,
         load_id,
         contents,
         date,
         file,
         comments,
         partner_id,
         group_partner_code,
         interchange_partner_code
        )
        VALUES
        (
         :interchange_control_number,
         :group_control_number,
         :control_number,
         :type,
         :status,
         :customers_reference_number,
         :load_id,
         :contents,
         CURRENT_TIMESTAMP,
         :file,
         :comments,
         :partner_id,
         :group_partner_code,
         :interchange_partner_code
        );
        SQL;

        return $this->prepare($query, 'insert_query');
    }

    /**
     * Returns the PDO statement handle for the insert query.
     */
    protected function getInsertQuery(): PDOStatement
    {
        if (self::$insert_query) {
            return self::$insert_query;
        }

        return $this->prepareInsertQuery();
    }

    /**
     * Prepares the query for inserting a new record into the log.
     */
    protected function prepareQueueQuery()//: PDOStatement
    {
        $query = <<<SQL
        INSERT INTO edi_transactions_log
        (
         control_number,
         type,
         status,
         customers_reference_number,
         load_id,
         contents,
         date,
         comments,
         partner_id,
         group_partner_code,
         interchange_partner_code
        )
        VALUES
        (
         :control_number,
         :type,
         :status,
         :customers_reference_number,
         :load_id,
         :contents,
         CURRENT_TIMESTAMP,
         :comments,
         :partner_id,
         :group_partner_code,
         :interchange_partner_code
        );
        SQL;

        return $this->prepare($query, 'queue_query');
    }

    /**
     * Returns the PDO statement handle for the insert query.
     */
    protected function getQueueQuery(): PDOStatement
    {
        if (self::$queue_query) {
            return self::$queue_query;
        }

        return $this->prepareQueueQuery();
    }

    /**
     * Prepares the query for inserting a new record into the log.
     */
    protected function prepareCompleteQueuedQuery()//: PDOStatement
    {
        $query = <<<SQL
            UPDATE edi_transactions_log
            SET date = :date,
                interchange_control_number = :interchange_control_number,
                group_control_number = :group_control_number,
                status = 'Sent',
                file = :file
            WHERE id = :id
        SQL;

        return $this->prepare($query, 'complete_queued_query');
    }

    /**
     * Returns the PDO statement handle for the insert query.
     */
    protected function getCompleteQueuedQuery(): PDOStatement
    {
        if (self::$complete_queued_query) {
            return self::$complete_queued_query;
        }

        return $this->prepareCompleteQueuedQuery();
    }

    /**
     * Prepares the query for updating the load id.
     */
    protected function prepareUpdateLoadIdQuery()//: PDOStatement
    {
        $query = <<<SQL
        UPDATE edi_transactions_log
        SET load_id = :load_id
        WHERE partner_id = :partner_id
          AND customers_reference_number = :customers_reference_number
          AND (load_id IS NULL OR load_id = 0)
        SQL;

        return $this->prepare($query, 'update_load_id_query');
    }

    /**
     * Returns the PDO statement handle for the update load id query.
     */
    protected function getUpdateLoadIdQuery(): PDOStatement
    {
        if (self::$update_load_id_query) {
            return self::$update_load_id_query;
        }

        return $this->prepareUpdateLoadIdQuery();
    }

    /**
     * Prepares the query for appending response to all transactions in a group.
     */
    protected function prepareUpdateGroupResponseQuery()//: PDOStatement
    {
        $query = <<<SQL
        UPDATE edi_transactions_log
        SET response = CASE WHEN response IS NULL OR response = '' THEN :response
                                ELSE response || CHR(10) || :response
                END,
            response_date = :response_date
        WHERE partner_id = :partner_id
          AND type = :type
          AND group_control_number = :group_control_number\n
        SQL;

        return $this->prepare($query, 'update_group_response_query');
    }

    /**
     * Returns the PDO statement handle for the group response append query.
     */
    protected function getUpdateGroupResponseQuery(): PDOStatement
    {
        if (self::$update_group_response_query) {
            return self::$update_group_response_query;
        }

        return $this->prepareUpdateGroupResponseQuery();
    }

    /**
     * Prepares the query for adding a response to a transaction.
     */
    protected function prepareUpdateTransactionResponseQuery()//: PDOStatement
    {
        $query = <<<SQL
            UPDATE edi_transactions_log
            SET response = CASE WHEN response IS NULL OR response = '' THEN :response
                                ELSE response || CHR(10) || :response
                END,
            response_date = :response_date
            WHERE type = :type
              AND partner_id = :partner_id
              AND group_control_number = :group_control_number
              AND control_number = :control_number\n
        SQL;

        return $this->prepare($query, 'update_transaction_response_query');
    }

    /**
     * Returns the PDO statement handle for the transaction response query.
     */
    protected function getUpdateTransactionResponseQuery(): PDOStatement
    {
        if (self::$update_transaction_response_query) {
            return self::$update_transaction_response_query;
        }

        return $this->prepareUpdateTransactionResponseQuery();
    }

    /**
     * Prepares the query for adding a response to a transaction.
     */
    protected function prepareUpdateStatusQuery()//: PDOStatement
    {
        $query = <<<SQL
            UPDATE edi_transactions_log
            SET status = :status,
                date = now()
            WHERE id = :id\n
        SQL;

        return $this->prepare($query, 'update_status_query');
    }

    /**
     * Returns the PDO statement handle for the transaction response query.
     */
    protected function getUpdateStatusQuery(): PDOStatement
    {
        if (self::$update_status_query) {
            return self::$update_status_query;
        }

        return $this->prepareUpdateStatusQuery();
    }

    /**
     * Prepares the query for adding a response to a transaction.
     */
    protected function prepareDeleteQuery()//: PDOStatement
    {
        $query = <<<SQL
            DELETE
            FROM edi_transactions_log
            WHERE id = :id\n
        SQL;

        return $this->prepare($query, 'delete_query');
    }

    /**
     * Returns the PDO statement handle for the transaction response query.
     */
    protected function getDeleteQuery(): PDOStatement
    {
        if (self::$delete_query) {
            return self::$delete_query;
        }

        return $this->prepareDeleteQuery();
    }


    /**
     * Creates a new EDI transaction log record.
     * @param EDInterchange $interchange
     * @param int $partner_id
     * @param string $status
     * @param string $file
     * @return bool
     */
    public function log(EDInterchange $interchange, $partner_id, $status, $file)
    {
        $sth = $this->getInsertQuery();

        $file = str_replace($this->fileLogPath, '', $file);

        foreach ($interchange->getGroups() as $group) {
            foreach ($group->getTransactions() as $transaction) {
                $binds = [
                    'interchange_control_number' => $interchange->getControlNumber(),
                    'interchange_partner_code' => $interchange->getReceiver(),
                    'group_control_number' => $group->getControlNumber(),
                    'group_partner_code' => $group->getReceiverCode(),
                    'control_number' => $transaction->getControlNumber(),
                    'type' => $transaction->getTransactionTypeCode(),
                    'status' => $status,
                    'customers_reference_number' => $transaction->getCustomersReferenceNumber() ?: null,
                    'load_id' => $transaction->getShippersReferenceNumber() ?: null,
                    'contents' => json_encode($transaction->getSegments(true)),
                    'file' => $file,
                    'comments' => $transaction->getSummaryText(),
                    'partner_id' => $partner_id,
                ];
                return $this->db->insert($sth, $binds);
            } // end loop for each transaction
        } // end loop for each group

        return true;
    }

    /**
     * Creates a new EDI transaction log record set to a status of queued.
     */
    public function queueOutgoingTransaction(
        int $partner_id,
        string $interchange_partner_code,
        string $group_partner_code,
        EDITransaction $transaction,
        array $logs = []
    ) {
        $sth = $this->getQueueQuery();

        $load_id = $transaction->getShippersReferenceNumber() ?: null;
        if (!is_numeric($load_id)) {
            $load_id = null;
        }
        $binds = [
            'interchange_partner_code' => $interchange_partner_code, //$interchange->getReceiver(),
            'group_partner_code' => $group_partner_code, //$group->getReceiverCode(),
            'control_number' => $transaction->getControlNumber(),
            'type' => $transaction->getTransactionTypeCode(),
            'status' => 'Queued',
            'customers_reference_number' => $transaction->getCustomersReferenceNumber() ?: null,
            'load_id' => $load_id,
            'contents' => json_encode($transaction->getSegments(true)),
            'comments' => $transaction->getSummaryText(),
            'partner_id' => $partner_id,
        ];
        if (!$this->exists($binds, $logs)) {
            return $this->db->insert($sth, $binds);
        }

        return true;
    }

    /**
     * Creates a new EDI transaction log record set to a status of queued.
     */
    public function completeQueuedOutgoingTransaction(
        int $id,
        string $interchange_control_number,
        string $group_control_number,
        DateTimeInterface $date,
        string $file
    ): bool {
        $sth = $this->getCompleteQueuedQuery();

        $binds = [
            'id' => $id,
            'interchange_control_number' => $interchange_control_number,
            'group_control_number' => $group_control_number,
            'date' => $date->format(DateTimeInterface::ATOM),
            'file' => $file,
        ];
        return $this->db->update($sth, $binds);
    }

    /*
     * Attempt to find an entry in $logs matching $data.
     */
    public function exists(array $data, array $logs): bool
    {
        foreach ($logs as $log) {
            if (
                $data['partner_id'] === $log['partner_id']
                && $data['type'] === $log['type']
                && $data['load_id'] === $log['load_id']
                && $data['comments'] === $log['comments']
            ) {
                return true;
            }
        } // End loop for each log

        return false;
    }

    /**
     * Updates the load id.
     * @return bool|int|PDOStatement
     */
    public function updateLoadId($customers_reference_number, $partner_id, $load_id)
    {
        $binds = array(
            'load_id' => $load_id,
            'partner_id' => $partner_id,
            'customers_reference_number' => $customers_reference_number,
        );
        return $this->db->update($this->getUpdateLoadIdQuery(), $binds);
    }

    /**
     * Update the response column on transactions matching the given information.
     */
    public function updateGroupResponse(EDIData $data, string $response, int $partner_id)
    {
        $group = new EDIGroup();

        $functional_id_code = EDIData::getCodeFromDescription($data->getValue('Functional Identifier Code'));
        $type = $group->getTransactionTypeCode($functional_id_code);
        $binds = array(
            'response' => $response,
            // Use gmdate() because postgres assumes UTC for columns w/o time zones
            'response_date' => gmdate(DATE_ATOM),
            'partner_id' => $partner_id,
            'type' => ($type) ? (int)$type : 0,
            'group_control_number' => $data->getValue('Group Control Number'),
        );

        return $this->db->update($this->getUpdateGroupResponseQuery(), $binds);
    }

    /**
     * Logs transaction acknowledgement and error messages to the corresponding transaction log.
     */
    public function logTransactionResponse(array $response, string $comments, int $partner_id, int $group_control_number)
    {
        $type = EDIData::getCodeFromDescription($response['Transaction Set Identifier Code']);
        $binds = array(
            'response' => $comments,
            'partner_id' => $partner_id,
            'type' => ($type) ? (int)$type : 0,
            'group_control_number' => $group_control_number,
            'control_number' => $response['Transaction Set Control Number'],
            // Use gmdate() because postgres assumes UTC for columns w/o time zones
            'response_date' => gmdate(DATE_ATOM),
        );

        return $this->db->update($this->getUpdateTransactionResponseQuery(), $binds);
    }

    public function updateStatus(int $id, string $status)
    {
        $binds = [
            'id' => $id,
            'status' => $status,
        ];

        return $this->db->update($this->getUpdateStatusQuery(), $binds);
    }

    public function delete(int $id)
    {
        $binds = [
            'id' => $id,
        ];

        return $this->db->update($this->getDeleteQuery(), $binds);
    }

    public function requeue(int $id): bool
    {
        $binds = [
            'id' => $id,
        ];

        try {
            $record = $this->db->getRecord($this->getSelectQuery(), $binds);
            if (empty($record) || empty($record['partner_id'])) {
                return false;
            }

            $contents = json_decode($record['contents'], true);
            $transaction = EDITransaction::create('', $record['type']);
            $transaction->setSegments($contents);
            $logId = $this->queueOutgoingTransaction(
                $record['partner_id'],
                $record['interchange_partner_code'],
                $record['group_partner_code'],
                $transaction
            );

            return (bool)$logId;
        } catch (Exception $exception) {
            Logger::error($exception->getMessage());
            return false;
        }
    }

    /*
     * Get all logs for the given load.
     */
    public function getLogsForLoad(int $loadId): array
    {
        $query = <<<SQL
        SELECT *
        FROM edi_transactions_log
        WHERE load_id = :load_id
        SQL;

        $logs = $this->db->fetchAll($query, ['load_id' => $loadId]);
        return $logs ?: [];
    }

    public static function convertToTransaction(string $contents): EDITransaction
    {
        $segments = json_decode($contents, true);
        if (empty($segments) || !is_array($segments)) {
            throw new Exception('There was an error retrieving the transaction.');
        }

        // Use EDIGroup method to transform log contents into an EDITransaction object
        $group = new EDIGroup();
        $group->parseTransactions($segments);
        $transactions = $group->getTransactions();
        if (empty($transactions) || !is_array($transactions)) {
            throw new Exception('There was an error parsing the transaction.');
        }

        // There should only be one transaction
        return $transactions[0];
    }

    public static function formatDateTime(string $datetime, string $format = 'Y-m-d H:i:s'): string
    {
        // The datetime is stored in the DB in UTC; we have to inform DateTime of this so that it doesn't use
        // the default timezone.
        $date = new DateTime($datetime, new DateTimeZone('UTC'));

        // Now we want to convert it to the time zone we want it display as.
        $date->setTimezone(new DateTimeZone(date_default_timezone_get()));

        return $date->format($format);
    }

    public function setFileLogPath(string $path): void
    {
        $this->fileLogPath = $path;
    }
}
