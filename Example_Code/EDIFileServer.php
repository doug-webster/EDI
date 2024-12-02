<?php

namespace GZMP\EDI;

use DateTime;
use PDODB;
use DateTimeInterface;
use DateTimeZone;
use Exception;
use FTPAbstract;
use FTPFactory;
use GZMP\EDI\Core\Data\EDIData;
use GZMP\EDI\Core\EDIGroup;
use GZMP\EDI\Core\EDInterchange;
use GZMP\EDI\Core\EDInterchangeAck;
use GZMP\EDI\Core\Transactions\EDITransaction;
use GZMP\EDI\Processors\EDIProcessor;
use GZMP\EDI\Processors\EDIProcessor997;

class EDIFileServer
{
    protected PDODB $db;
    protected FTPAbstract $server;
    protected EDILogger $logger;
    protected array $partners = [];
    protected int $logId = 0;
    protected bool $errors = false;
    protected array $messages = [];
    protected array $codesToPartnerIds = [];
    protected EDIProcessor997 $processor997;
    protected int $incomingFileLimit = 100;
    protected int $statusUpdateLimit = 100;
    protected string $fileLogPath = '';

    public function __construct(PDODB $db, int $id = 0, array $partners = [])
    {
        $this->db = $db;
        $this->server = FTPFactory::build($id);
        $this->partners = $partners;
        $this->logger = new EDILogger($this->db);

        foreach ($partners as $partner) {
            if (!$partner instanceof EDIPartner) {
                continue;
            }

            $this->codesToPartnerIds = $this->codesToPartnerIds + $partner->getPartnerCodesToIds();
        }
    }

    public function exchangeMessages(): void
    {
        Logger::info("EDI exchange: {$this->server->getLabel()}");

        if (!$this->server->connect()) {
            Logger::info("{$this->server->getLabel()}: {$this->server->getErrorMsg()}");
            return;
        }

        // Send outgoing EDI messages
        $this->processOutgoingQueue();

        $this->getAndQueueIncomingEDI();

        Logger::info($this->messages);
    }

    protected function logMessage(string $message)
    {
        Logger::info($message);
    }

    protected function logError(string $message)
    {
        $this->errors = true;
        Logger::info($message);
    }

    /*
     * Send all queued outgoing EDI messages to current file server
     */
    protected function processOutgoingQueue()
    {
        $partners = $this->getQueuedTransactionsByPartner();

        foreach ($partners as $partner_id => $groupCodes) {
            foreach ($groupCodes as $groupCode => $types) {
                // Though in theory multiple transaction types could be included in an interchange, it seems that
                // most if not all partners do not support this.
                foreach ($types as $type_id => $transactions) {
                    if (!$this->partners[$partner_id] instanceof EDIPartner) {
                        continue;
                    }
                    $partner = $this->partners[$partner_id];

                    try {
                        // Create the Interchange
                        $interchange = $partner->createInterchange($type_id);

                        // Create an EDI Group
                        $group = EDIGroup::create(
                            $partner->headers['x12_version'],
                            $type_id,
                            $partner->sender_code,
                            $groupCode
                        );

                        $class = EDITransaction::getTypeSpecificTransactionClass($type_id);

                        // Add transactions
                        $logs = [];
                        $loadIds = [];
                        foreach ($transactions as $transaction) {
                            $logs[] = [
                                'id' => $transaction['id'],
                                'interchange_control_number' => $interchange->getControlNumber(),
                                'group_control_number' => $group->getControlNumber(),
                            ];
                            $loadIds[] = $transaction['load_id'];
                            $segments = json_decode($transaction['contents'], true);
                            $transaction = $class::create($partner->scac);
                            $transaction->setSegments($segments);
                            $group->addTransaction($transaction);
                        }

                        $interchange->addGroup($group);

                        // Send interchange
                        $tmpFileName = $this->sendDataFile($partner, $interchange);

                        foreach ($logs as $log) {
                            $this->logger->completeQueuedOutgoingTransaction(
                                $log['id'],
                                $log['interchange_control_number'],
                                $log['group_control_number'],
                                new DateTime('now', new DateTimeZone('UTC')),
                                $tmpFileName
                            );
                        }

                        $count = $interchange->countTransactions();
                        if ($count) {
                            $s = ($count > 1) ? 's' : '';
                            $this->logMessage("$count transaction$s sent to {$this->server->getLabel()}.");
                        }
                    } catch (Exception $exception) {
                        $this->logError($exception->getMessage());
                    }

                    $this->additionalSendingActions($type_id, $partner, $loadIds);
                } // End loop for each transaction type
            } // End loop for each group code
        } // End loop for each partner
    }

    protected function getQueuedTransactionsByPartner(): array
    {
        $binds = [
            'limit' => $this->statusUpdateLimit,
        ];
        $placeholder = $this->db->bindMultiNew(array_keys($this->partners), $binds);

        if (empty($placeholder)) {
            throw new Exception('No partners.');
        }

        $query = <<<SQL
            SELECT id,
                   partner_id,
                   type,
                   contents,
                   group_partner_code,
                   load_id
            FROM edi_transactions_log
            WHERE partner_id IN ({$placeholder})
              AND status = 'Queued'
              AND (group_partner_code IS NOT NULL AND group_partner_code != '')
            ORDER BY partner_id, group_partner_code, type DESC
            LIMIT :limit
        SQL;
        $records = $this->db->fetchAll($query, $binds);

        if (!is_array($records)) {
            return [];
        }

        $partners = [];
        foreach ($records as $record) {
            $partners[$record['partner_id']]
            [$record['group_partner_code']]
            [$record['type']]
            [] = $record;
        }

        return $partners;
    }

    /**
     * Send EDI file to partner (and log).
     */
    public function sendDataFile(EDIPartner $partner, EDInterchange $interchange): string
    {
        if ($interchange->countTransactions() < 1) {
            throw new Exception('No transactions in interchange!');
        }

        $tmpFileName = $partner->createTemporaryFile($interchange);

        $remote_filename = $partner->getRemoteFilename($tmpFileName, $interchange);

        // We don't want to overwrite a file of the same name on the server,
        // however, if $tmpFileName is unique on our system as generated above,
        // there is not likely to be a conflict on the remote server either.

        if (!$this->server->uploadFile($tmpFileName, $remote_filename)) {
            throw new Exception($this->server->getErrorMsg());
        }

        return str_replace($this->fileLogPath, '', $tmpFileName);
    }

    protected function additionalSendingActions(int $type_id, EDIPartner $partner, array $loadIds)
    {
        if ($type_id !== 210 || empty($loadIds) || !is_array($loadIds)) {
            return;
        }

        $generator = $partner->createBillingGenerator();
        $required_doc_types = $generator->getRequiredDocTypes();
        if (empty($required_doc_types) || !is_array($required_doc_types)) {
            return;
        }

        foreach ($loadIds as $loadId) {
            foreach ($required_doc_types as $required_doc_type) {
                try {
                    $generator->sendLoadDocument(
                        $required_doc_type['img_lu_documents_id'],
                        $loadId,
                        $required_doc_type['filename']
                    );
                } catch (Exception $exception) {
                    $this->logMessage($exception->getMessage());
                }
            } // end loop for each doc type
        } // end loop for each load
    }

    protected function getFileNameFormats(): string
    {
        $formats = [];
        foreach ($this->partners as $partner) {
            if (!$partner instanceof EDIPartner) {
                continue;
            }
            // If any partner does not have a file name format specified, we have to download all files.
            if (empty($partner->inbound_filename)) {
                return '';
            }
            $formats[] = $partner->inbound_filename;
        }

        return implode('|', $formats);
    }

    /**
     * Attempt to open local file and return its contents.
     */
    public static function getFileText(string $file): string
    {
        if (!file_exists($file)) {
            throw new Exception("File {$file} not found.");
        }

        $fp = fopen($file, 'r');
        if (!$fp) {
            throw new Exception("Couldn't open file {$file}.");
        }

        // read file
        $filesize = filesize($file);
        if ($filesize <= 0) {
            throw new Exception("{$file} is empty.");
            // throw new Exception("{$file} is empty; there was probably a problem downloading {$remotePath}");
        }

        $fileText = fread($fp, $filesize);
        if (!$fileText) {
            throw new Exception("Couldn't read file {$file}.");
        }
        fclose($fp);

        // Check encoding and convert if not UTF-8
        $encoding = mb_detect_encoding($fileText, ['UTF-8', 'ISO-8859-1']);

        if ($encoding === false) {
            throw new Exception("{$file} contains invalid character encoding or is not a text file.");
        }

        if ($encoding !== 'UTF-8') {
            // Using 'UTF-8//IGNORE' always returns false.
            $fileText = iconv($encoding, 'UTF-8', $fileText);
        }

        return $fileText;
    }

    /*
     * Get all incoming EDI messages from the current file server
     */
    public function getAndQueueIncomingEDI(): void
    {
        try {
            $files = $this->server->getFiles($this->getFileNameFormats(), $this->incomingFileLimit);
        } catch (Exception $exception) {
            $this->logError($exception->getMessage());
            return;
        }

        // Check for any download errors.
        $message = $this->server->getErrorMsg();
        if (!empty($message)) {
            $this->logError($message);
        }

        $file_count = count($files);
        if ($file_count <= 0) {
            return;
        }

        $s = ($file_count > 1) ? 's' : '';
        $this->logMessage("$file_count file$s downloaded.");

        $values = $this->getTransactionValuesFromFiles($files);

        if (empty($values)) {
            return;
        }

        $count = $this->saveTransactions($values);

        if ($count != count($values)) {
            $this->logError("There was apparently a problem with queueing the transactions.");
        } else {
            $this->removeRemoteFiles(array_column($files, 'remote'));
        }

        if ($count) {
            $s = ($count > 1) ? 's' : '';
            $this->logMessage("$count interchange$s added to incoming EDI queue.");
        }
    }

    /**
     * Inserts the records currently held in the values array into the database.
     */
    public function saveTransactions(array $values): int
    {
        $valuesSQL = [];
        $binds = [];
        foreach ($values as $i => $valueSet) {
            $valuesSQL[] = <<<SQL
                (
                    :partner_id$i,
                    :interchange_partner_code$i,
                    :group_partner_code$i,
                    :date$i,
                    :file$i,
                    :interchange_control_number$i,
                    :group_control_number$i,
                    :control_number$i,
                    :type$i,
                    :status$i,
                    :customers_reference_number$i,
                    :contents$i,
                    :comments$i,
                    :response$i,
                    :response_date$i
                )
            SQL;
            $binds["partner_id$i"] = $valueSet['partner_id'];
            $binds["interchange_partner_code$i"] = $valueSet['interchange_partner_code'];
            $binds["group_partner_code$i"] = $valueSet['group_partner_code'];
            $binds["date$i"] = $valueSet['date'];
            $binds["file$i"] = $valueSet['file'];
            $binds["interchange_control_number$i"] = $valueSet['interchange_control_number'];
            $binds["group_control_number$i"] = $valueSet['group_control_number'];
            $binds["control_number$i"] = $valueSet['control_number'];
            $binds["type$i"] = $valueSet['type'];
            $binds["status$i"] = $valueSet['status'];
            $binds["customers_reference_number$i"] = $valueSet['customers_reference_number'];
            $binds["contents$i"] = $valueSet['contents'];
            $binds["comments$i"] = $valueSet['comments'];
            $binds["response$i"] = $valueSet['response'];
            $binds["response_date$i"] = $valueSet['response_date'];
        }

        $query = <<<SQL
            INSERT INTO edi_transactions_log (
                partner_id,
                interchange_partner_code,
                group_partner_code,
                date,
                file,
                interchange_control_number,
                group_control_number,
                control_number,
                type,
                status,
                customers_reference_number,
                contents,
                comments,
                response,
                response_date
            )
        VALUES\n
        SQL;
        $query .= implode(",\n", $valuesSQL);
        $query .= "\nON CONFLICT DO NOTHING";

        // Call update here even though we're doing an insert in order to get a row count.
        $result = $this->db->update($query, $binds);

        return (is_numeric($result)) ? (int)$result : 0;
    }

    /*
     * Remove files from remote server.
     */
    protected function removeRemoteFiles(array $files)
    {
        foreach ($files as $file) {
            $this->server->deleteFile($file);
        }
    }

    /*
     * Return an array of values to insert into the transaction log.
     */
    public function getTransactionValuesFromFiles(array &$files): array
    {
        $values = [];
        foreach ($files as $i => $file) {
            try {
                $response = $this->getTransactionValuesFromFile($file['local']);
                if (!empty($response)) {
                    $values = array_merge($values, $response);
                } else {
                    unset($files[$i]);
                }
            } catch (Exception $exception) {
                $message = $exception->getMessage();
                if ($message) {
                    $this->logError($message);
                }
                if (file_exists($file['local'])) {
                    unlink($file['local']);
                    unset($files[$i]);
                }
            }
        } // End loop for each file

        return $values;
    }

    /*
     * Get the file text and convert to EDInterchange object.
     */
    public function getInterchangeFromFile(string $file): EDInterchange
    {
        try {
            $ediText = EDIFileServer::getFileText($file);
            $interchange = new EDInterchange();
            $interchange->parse($ediText);
        } catch (Exception $exception) {
            throw new Exception("Error parsing {$file}: {$exception->getMessage()}");
        }

        return $interchange;
    }

    /*
     * Verify the EDI sender code matches one of our partners.
     */
    public function getPartner(EDInterchange $interchange): EDIPartner
    {
        $senderCode = $interchange->getSender();
        if (!array_key_exists($senderCode, $this->codesToPartnerIds)) {
            throw new Exception('');
        }

        return $this->getPartnerFromCode($senderCode);
    }

    /*
     * Return the partner matching the given interchange sender/receiver code.
     */
    public function getPartnerFromCode(string $senderCode): EDIPartner
    {
        if (empty($this->partners[$this->codesToPartnerIds[$senderCode][0]])) {
            throw new Exception("Partner for $senderCode not found.");
        }

        return $this->partners[$this->codesToPartnerIds[$senderCode][0]];
    }

    /*
     * Generate Functional Acknowledgement interchange, send if necessary, and return interchange in any case.
     */
    public function queueFunctionalAcknowledgement(EDInterchange $interchange, EDIPartner $partner): EDInterchangeAck
    {
        $sendAcknowledgement = $this->sendAcknowledgement($interchange, $partner);

        $acknowledgmentGenerator = $partner->createAcknowledgmentGenerator();
        return $acknowledgmentGenerator->queueAcknowledgement($interchange, $sendAcknowledgement);
    }

    /*
     * Whether or not to send acknowledgements to specified partner.
     */
    protected function sendAcknowledgement(EDInterchange $interchange, EDIPartner $partner): bool
    {
        $this->processor997 = $partner->createChild(EDIProcessor::getProcessorClass(997));
        $send997 = $partner->sendAcknowledgments();
        // 0 = never send, 1 = always send, 2 = send if requested by ISA header
        return ($send997 === 1 || ($send997 === 2 && $interchange->acknowledgmentRequested()));
    }

    /*
     * Return an array of values to insert into the transaction log.
     */
    public function getTransactionValuesFromFile(string $file): array
    {
        $interchange = $this->getInterchangeFromFile($file);
        $partner = $this->getPartner($interchange);

        // Log any warnings.
        $interchangeWarnings = $interchange->getWarnings();
        if (!empty($interchangeWarnings)) {
            $this->logMessage("{$file}:\n" . implode("\n", $interchangeWarnings));
        }

        // Create/send 997 acknowledgement
        if ($interchange->getTypeCode() != 997) {
            $acknowledgmentInterchange = $this->queueFunctionalAcknowledgement($interchange, $partner);
        }

        $path = $this->fileLogPath;

        // Gather the values
        $values = [];
        foreach ($interchange->getGroups() as $group) {
            $values = array_merge(
                $values,
                $this->getTransactionValuesFromGroup(
                    $group,
                    $partner,
                    $interchange,
                    str_replace($path, '', $file),
                    $acknowledgmentInterchange ?? null
                )
            );
        } // End loop for each group

        return array_filter($values);
    }

    public function getTransactionValuesFromGroup(
        EDIGroup $group,
        EDIPartner $partner,
        EDInterchange $interchange,
        string $file,
        EDInterchangeAck $acknowledgmentInterchange = null
    ): array {
        // Gather processing warnings.
        if (!empty($acknowledgmentInterchange)) {
            $data = $acknowledgmentInterchange->getAcknowledgementData(
                $group->getFunctionalIdCode(),
                $group->getControlNumber()
            );
            $groupAckMessage = $this->processor997->getGroupResponse($data);
            $transactionResponses = $data->getValue('transactionResponses', true);
        }

        $values = [];
        foreach ($group->getTransactions() as $transaction) {
            $response = $this->getTransResponseMessage(
                $transaction,
                $transactionResponses ?? null,
                $groupAckMessage ?? null
            );
            $timeZone = new DateTimeZone($partner->timezone);
            $returnTimeZone = new DateTimeZone('UTC');
            $values[] = [
                'partner_id' => $partner->getId(),
                'date' => $interchange->getDateTime(DateTimeInterface::ATOM, $timeZone, $returnTimeZone),
                'file' => $file,
                'interchange_control_number' => $interchange->getControlNumber(),
                'interchange_partner_code' => $interchange->getSender(),
                'group_control_number' => $group->getControlNumber(),
                'group_partner_code' => $group->getSenderCode(),
                'control_number' => $transaction->getControlNumber(),
                'type' => $transaction->getTransactionTypeCode(),
                'status' => 'Pending',
                'customers_reference_number' => $transaction->getCustomersReferenceNumber(),
                'contents' => json_encode($transaction->getSegments(true)),
                'comments' => $transaction->getSummaryText(),
                'response' => $response ?? '',
                'response_date' => gmdate(DATE_ATOM),
            ];
        } // End loop for each transaction

        return $values;
    }

    public function getTransResponseMessage(
        EDITransaction $transaction,
        array $transactionResponses = null,
        string $groupAckMessage = null
    ): string {
        $response = '';

        if (empty($transactionResponses)) {
            return $groupAckMessage ?? '';
        }

        foreach ($transactionResponses as $response) {
            $type = EDIData::getCodeFromDescription($response['Transaction Set Identifier Code']);
            if ($transaction->getTransactionTypeCode() != $type
                || $transaction->getControlNumber() != $response['Transaction Set Control Number']
            ) {
                continue;
            }
            $transResponseMessage = $this->processor997->getTransactionResponseMessages($response);
        } // End loop for each transaction response

        if (!empty($groupAckMessage) && !empty($transResponseMessage)) {
            $response = $groupAckMessage . "\n" . $transResponseMessage;
        } elseif (!empty($groupAckMessage)) {
            $response = $groupAckMessage;
        } elseif (!empty($transResponseMessage)) {
            $response = $transResponseMessage;
        }

        return $response;
    }

    public function setIncomingFileLimit(int $limit): void
    {
        $this->incomingFileLimit = $limit;
    }

    public function setStatusUpdateLimit(int $limit): void
    {
        $this->statusUpdateLimit = $limit;
    }

    public function setFileLogPath(string $path): void
    {
        $this->fileLogPath = $path;
    }
}
