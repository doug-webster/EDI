<?php
namespace GZMP\EDI\Processors;

use PDODB;
use Exception;
use Logger;
use PDOStatement;
use GZMP\EDI\Core\Data\EDIAcknowledgementData;
use GZMP\EDI\Core\Data\EDIData;
use GZMP\EDI\Core\Transactions\EDITransaction997;
use GZMP\EDI\EDILogger;
use Utility;

class EDIProcessor997 extends EDIProcessor
{
    /**
     * Process an incoming EDI acknowledgment by converting it to an EDIAcknowledgementData object and then proceeding
     * based on the information contained within. The acknowledgment may contain one group response as well as multiple
     * transaction responses.
     */
    public function process(EDITransaction997 $transaction, int $log_id = null)
    {
        $data = new EDIAcknowledgementData();
        $this->warnings = $data->processTransaction($transaction);

        $this->processGroupResponse($data);
        $this->processTransactionResponses($data);
    }

    /**
     * Process and logs one group acknowledgement/response.
     */
    protected function processGroupResponse(EDIAcknowledgementData $data)
    {
        $message = $this->getGroupResponse($data);
        if (!empty($message)) {
            $this->edi_logger->updateGroupResponse($data, $message, $this->id);
        }
    }

    /**
     * Process one group acknowledgement/response.
     */
    public function getGroupResponse(EDIAcknowledgementData $data): string
    {
        $errors = $data->getValue('Functional Group Syntax Error Code', true);
        if (!empty($errors)) {
            $comments = array();
            $comments[] = "Group {$data->getValue('Group Acknowledgement')}";
            if (!is_array($errors)) {
                $errors = [$errors];
            }
            foreach ($errors as $error) {
                $comments[] = "Group Error: {$error}";
            }

            $comments[] = implode(', ', [
                "Transactions Included: {$data->getValue('Transaction Count')}",
                "Transactions Received: {$data->getValue('Received Transaction Count')}",
                "Transactions Accepted: {$data->getValue('Accepted Transaction Count')}"
            ]);
            return implode("\n", $comments);
        }

        // Though I think transaction responses should be included for each transaction,
        // in practice, some partners only send the group response when all transactions are accepted.
        $transactionResponses = $data->getValue('transactionResponses', true);
        $transactionsReceivedCount = $data->getValue('Received Transaction Count');
        $transactionsAcceptedCount = $data->getValue('Accepted Transaction Count');

        if (empty($transactionResponses) && $transactionsReceivedCount === $transactionsAcceptedCount) {
            $response = $data->getValue('Group Acknowledgement');
            if (!empty($response)) {
                return $response;
            }
        }

        return '';
    }

    /**
     * Process and log all the transaction responses within one group response.
     */
    protected function processTransactionResponses(EDIAcknowledgementData $data)
    {
        $transactionResponses = $data->getValue('transactionResponses', true);
        if (empty($transactionResponses) || !is_array($transactionResponses)) {
            return;
        }

        $partnerIds = $this->getPartnerIdsByPartnersCode($this->headers['receiver_id']);

        foreach ($transactionResponses as $response) {
            // This is done to handle the case of US Bank having two partner records (for Trane and Nissan) both using
            // the same partner code.
            foreach ($partnerIds as $partnerId) {
                // Always log the response.
                $recordsUpdated = $this->edi_logger->logTransactionResponse(
                    $response,
                    $this->getTransactionResponseMessages($response),
                    $partnerId,
                    (int)$data->getValue('Group Control Number')
                );
                if ($recordsUpdated) {
                    break;
                }
            }

            // If there are no errors, we don't need to do anything further with this response.
            $accepted = $response['Transaction Set Acknowledgement Code'] ?? '';
            $accepted = $accepted === 'Accepted [A]';
            if ($accepted && empty($response['segmentErrors'])) {
                continue;
            }

            $this->handleTransactionErrors($response, $data->getValue('Group Control Number'));
        } // end loop for each transaction response
    }

    /**
     * Prepare and return an SQL query to get the load id of a given EDI transaction.
     * @return false|PDOStatement
     */
    protected function prepareLoadIdQuery()
    {
        $query = <<<SQL
            SELECT load_id
            FROM edi_transactions_log
            WHERE partner_id = :partner_id
              AND group_control_number = :group_control_number
              AND control_number = :control_number
              AND type = :type
        SQL;
        if (!$sth = $this->db->dbh->prepare($query)) {
            try {
                $this->db->handleError($this->db->dbh, $query);
            } catch (Exception $exception) {
                Logger::error($exception->getMessage());
                return false;
            }
        }

        return $sth;
    }

    /**
     * Handle errors in reported in a 990 transaction.
     */
    protected function handle990Errors(array $transactionResponse)
    {
        $message = "This load's EDI acceptance has been rejected by {$this->partner}. Rejection Message(s): ";
        $message .= $this->getTransactionResponseMessages($transactionResponse);

        // Add priority note to load
        $load->addNote($message, true);

        $subject = "{$this->partner} Load Acceptance Rejected";

        $notificationList = $this->getErrorNotificationList();

        if (empty($notificationList)) {
            return;
        }

        if ($this->send_email_notifications) {
            // Send email notifications
            Utility::sendEmail($notificationList, null, $subject, $message);
        }
    }

    /**
     * Convert the transaction response into user friendly text.
     */
    public function getTransactionResponseMessages(array $response): string
    {
        $message = array();

        $message[] = "Transaction {$response['Transaction Set Acknowledgement Code']}";

        $transactionErrors = $response['Transaction Set Syntax Error Code'] ?? [];

        if (!empty($transactionErrors) && is_array($transactionErrors)) {
            foreach ($transactionErrors as $error) {
                $message[] = "Transaction Error: {$error}";
            }
        }

        if (!empty($response['segmentErrors']) && is_array($response['segmentErrors'])) {
            foreach ($response['segmentErrors'] as $errors) {
                $message[] = implode("\n", $errors);
            }
        }

        return implode("\n", $message);
    }

    /**
     * Get a list of users to notify of errors in 990 transactions.
     */
    public function getErrorNotificationList(): array
    {
        return [];
    }

    /**
     * Handle errors reported in EDI transactions.
     */
    protected function handleTransactionErrors(array $transactionResponse, string $groupControlNumber)
    {
        $type_code = EDIData::getCodeFromDescription($transactionResponse['Transaction Set Identifier Code']);

        // No action for now for transactions other than load tender responses.
        if ($type_code !== '990') {
            return;
        }

        $load = $this->getLoad(
            $groupControlNumber,
            $transactionResponse['Transaction Set Control Number'],
            $type_code
        );

        if (!$load->getVar('gn_id')) {
            return;
        }

        $this->handle990Errors($transactionResponse, $load);
    }

    public function completeQueuedTransaction(EDILogger $logger, int $id)
    {
        $logger->delete($id);
    }
}
