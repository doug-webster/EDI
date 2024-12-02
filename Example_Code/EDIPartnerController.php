<?php
namespace GZMP\EDI;

use PDODB;
use Exception;
use Logger;
use GZMP\EDI\Core\Transactions\EDITransaction;
use GZMP\EDI\Processors\EDIProcessor;

/**
 * This class is a placeholder and incomplete example of what an EDIPartner managing object might look like. 
 */

 class EDIPartnerController
{
    protected int $incomingQueueLimit = 100;
    protected bool $enableAutoDecline = true;

    /*
     * Batch script for triggering the process of exchanging EDI messages with our partners.
     */
    public static function exchangeMessages(PDODB $db): void
    {
        ini_set('memory_limit', '2048M');
        set_time_limit(600);

        $minutes = (int)date('i') + (int)date('G') * 60;

        $servers = self::getPartnersByFileServer($db);
        foreach ($servers as $serverId => $partners) {
            foreach ($partners as $partner) {
                if (!$partner instanceof EDIPartner) {
                    continue;
                }

                // Bypass frequency check if specific partner has been specified
                if (empty($_GET['partner_id']) || !is_numeric($_GET['partner_id'])) {
                    $frequency = $partner->frequencyToExchangeMessages();
                    $offset = $partner->frequencyOffset();
                    if ($frequency <= 0 || (($minutes - $offset) % $frequency !== 0)) {
                        continue;
                    }
                }

                $server = new EDIFileServer($db, $serverId, $partners);
                $server->exchangeMessages();

                // We only need to do the above once per file server
                break;
            } // End loop for each EDI partner using the file server
        } // End loop for each file server
    }

    /*
     * Return a list of EDI transactions from the incoming queue.
     */
    protected static function getIncomingQueuedTransactions(PDODB $db): array
    {
        $query = <<<SQL
            SELECT *
            FROM edi_transactions_log
            WHERE status = 'Pending'
            ORDER BY partner_id, date, interchange_control_number, group_control_number, control_number
            LIMIT :limit
        SQL;

        $data = ['limit' => (int)$this->incomingQueueLimit];
        if (!empty($_GET['partner_id']) && is_numeric($_GET['partner_id'])) {
            $query = str_replace('WHERE', "WHERE partner_id = :partner_id\nAND", $query);
            $data['partner_id'] = (int)$_GET['partner_id'];
        }

        $records = $db->fetchAll($query, $data);

        if (!is_array($records)) {
            throw new Exception("There was an error attempting to get records from the database.");
        }

        return $records;
    }

    /*
     * Process EDI transactions which are queued.
     */
    public static function processIncomingQueue(PDODB $db): void
    {
        Logger::info(__FUNCTION__);
        $logger = new EDILogger($db);
        $errors = [];
        $warnings = [];

        try {
            if ($this->enableAutoDecline) {
                self::declineExpiredTenders($db, $dbSQL);
            }
        } catch (Exception $exception) {
            Logger::error("Error declining expired EDI tenders: {$exception->getMessage()}");
        }

        try {
            $records = self::getIncomingQueuedTransactions($db);
        } catch (Exception $exception) {
            Logger::info($exception->getMessage());
            return;
        }

        foreach ($records as $record) {
            // Instantiate partner object as needed
            if (empty($partner) || $record['partner_id'] != $partner->id) {
                $partner = new EDIPartner($db, $record['partner_id']);
                $processor = $partner->createProcessor();
                $newProcessor = true;
            }

            // Ensure we have the correct processor object
            if ($newProcessor || empty($previousType) || $previousType != $record['type']) {
                $processor = $processor->createChild(
                    EDIProcessor::getProcessorClass($record['type'])
                );
                $transaction = EDITransaction::create($partner->scac, $record['type']);
                $newProcessor = false;
                $previousType = $record['type'];
            }

            try {
                // Hydrate the transaction with the record contents.
                $transaction->setSegments(json_decode($record['contents'], true));

                // Process the transaction
                $processor->process($transaction, $record['id']);
                $warnings = array_merge($warnings, $processor->getWarnings());

                // Update the log record
                $processor->completeQueuedTransaction($logger, $record['id']);
            } catch (Exception $exception) {
                $errors[] = $exception->getMessage();
            }
        } // End loop for each record

        $errors = array_merge($errors, $warnings);
        $errors[] = count($records) . " queued EDI transactions were processed.";
        Logger::info(implode("\n", $errors));
    }

    /*
     * Return an array of EDIPartner objects, keyed by file server id and partner id.
     */
    public static function getPartnersByFileServer(PDODB $db): array
    {
        $query = <<<SQL
            SELECT *
            FROM edi_partners p
            WHERE deleted_at IS NULL
        SQL;

        $data = [];
        if (!empty($_GET['partner_id']) && is_numeric($_GET['partner_id'])) {
            $query .= "\nAND id = :id";
            $data = ['id' => (int)$_GET['partner_id']];
        }

        $partners = $db->fetchAll($query, $data);

        if (empty($partners) || !is_array($partners)) {
            return [];
        }

        $partnersByFileServerId = [];
        foreach ($partners as $partner) {
            if (empty($partner['file_server_id'])) {
                continue;
            }

            $partnersByFileServerId[$partner['file_server_id']][$partner['id']] = new EDIPartner($db, $partner);
        }

        return $partnersByFileServerId;
    }

    /**
     * Check for unaccepted load tenders which are expired and automatically decline them
     */
    public static function declineExpiredTenders(PDODB $db): void
    {
        Logger::info(__FUNCTION__);
        $errors = [];

        $query = <<<SQL
        SELECT *
        FROM edi_tenders t
        WHERE t.partner_id IS NOT NULL
          AND t.partner_id > 0
          AND t.deleted_at IS NULL
          AND t.status = 'Response Needed'
          AND (must_respond_by < :date1
                OR (must_respond_by IS NULL AND t."gn_pickUpEnd" < :date2)
                OR (must_respond_by IS NULL AND t."gn_pickUpEnd" IS NULL AND t."gn_pickUp" + INTERVAL '1 day' < :date3)
            )
        SQL;
        $data = [
            'date1' => date('Y-m-d H:i'),
            'date2' => date('Y-m-d H:i'),
            'date3' => date('Y-m-d H:i'),
        ];
        $values = $db->fetchAll($query, $data);

        if (empty($values) || !is_array($values)) {
            Logger::info('success');
            return;
        }

        $expired_tenders = [];
        foreach ($values as $value) {
            if (!empty($value['partner_id'])) {
                $expired_tenders[$value['partner_id']][] = $value;
            }
        }

        $notices = [];
        $idsToUpdate = [];
        $count = 0;
        foreach ($expired_tenders as $ediPartnerId => $tenders) {
            $partner = new EDIPartner($db, $ediPartnerId);
            if (!$partner->sendResponseForExpired()) {
                $idsToUpdate = array_merge($idsToUpdate, array_column($tenders, 'id'));
                continue;
            }

            foreach ($tenders as $tender) {
                $tender = new EDITender($tender, $partner);
                try {
                    $tender->decline(true);
                    $count++;
                } catch (Exception $exception) {
                    $notices[] = $exception->getMessage();
                }
            } // End loop for each expired tender.
        } // end loop for each partner

        if (!empty($idsToUpdate)) {
            // Update tenders which won't have been updated via the decline process above.
            $binds = $db->bindMulti($idsToUpdate);
            $query = <<<SQL
                UPDATE edi_tenders SET status = 'Expired' WHERE id IN ({$binds['placeholder']})
            SQL;
            $count += $db->update($query, $binds['values']);
        }

        if (!empty($count)) {
            $s = ($count > 1) ? 's' : '';
            $notices[] = "{$count} expired tender$s automatically removed from the tendering queue.";
        }

        $result = (empty($errors)) ? 'success' : 'failure';
        if (!empty($notices)) {
            $errors = array_merge($errors, $notices);
        }
        Logger::info(implode("\n", $errors));
    }

    /*
     * Validate data submitted on the EDI partner form.
     */
    public static function validate(PDODB $db, array $request): EDIPartner
    {
        $errors = self::checkRequiredFields($request, [
            'partner' => 'Partner\'s Name',
            'file_server_id' => 'File Server',
            'headers' => [
                'sender_id_qualifier' => 'Our ID Qualifier',
                'sender_id' => 'Our ID',
                'receiver_id_qualifier' => 'Partner\'s ID Qualifier',
                'receiver_id' => 'Partner\'s ID',
                'x12_version' => 'EDI Version',
                'acknowledgment_requested' => 'Acknowledgement Requested',
                'usage' => 'Usage',
                'component_terminator' => 'Component Terminator',
            ],
        ]);

        if (!empty($errors)) {
            throw new Exception("Missing required fields:\n" . implode("\n", $errors));
        }

        return new EDIPartner($db, $request);
    }

    /**
     * Checks for the presence of each required field in the request and returns an array of any missing items.
     */
    private static function checkRequiredFields(array $request, array $requiredFields): array
    {
        $errors = [];

        foreach ($requiredFields as $requiredField => $requiredFieldName) {
            if (is_array($requiredFieldName)) {
                $errors = array_merge($errors, self::checkRequiredFields($request[$requiredField] ?? [], $requiredFieldName));
                continue;
            }

            if (empty($request[$requiredField])) {
                $errors[] = "{$requiredFieldName} must be set.";
            }
        }

        return $errors;
    }
}
