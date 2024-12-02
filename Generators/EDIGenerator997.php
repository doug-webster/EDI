<?php

namespace GZMP\EDI\Generators;

use Exception;
use Logger;
use GZMP\EDI\Core\EDIGroup;
use GZMP\EDI\Core\EDInterchange;
use GZMP\EDI\Core\EDInterchangeAck;
use GZMP\EDI\Core\Metadata\EDISchemas;
use GZMP\EDI\Core\Segments\EDISegment;
use GZMP\EDI\Core\Transactions\EDITransaction;
use GZMP\EDI\Core\Transactions\EDITransaction997;

class EDIGenerator997 extends EDIGenerator
{
    /**
     * Create a 997 acknowledgement for the provided interchange.
     */
    public function queueAcknowledgement(
        EDInterchange $receivedInterchange,
        bool $sendAcknowledgement
    ): EDInterchangeAck {
        if ($receivedInterchange->getTypeCode() == 997) {
            throw new Exception("Can't acknowledge an acknowledgement.");
        }

        // Create the Interchange
        // Since outgoing EDI is now queued by transaction, this interchange ends up only being used to record the
        // result of our processing in the transactions log.
        $interchange = $this->createInterchange();

        // Create an EDI Group
        $group = EDIGroup::create(
            $this->headers['x12_version'],
            '997',
            $this->sender_code,
            $this->receiver_code ?: ''
        );

        foreach ($receivedInterchange->getGroups() as $receivedGroup) {
            $transaction = $this->queueAcknowledgmentForGroup(
                $receivedGroup,
                $receivedInterchange->getSender(),
                $sendAcknowledgement
            );

            if ($transaction) {
                $group->addTransaction($transaction);
            }
        }

        if ($group->countTransactions() < 1) {
            throw new Exception("No transactions");
        }

        $interchange->addGroup($group);

        return $interchange;
    }

    /**
     * Return an EDInterchange matching this partner's EDI header options.
     */
    public function createInterchange(int $typeId = null)
    {
        return new EDInterchangeAck($this->headers);
    }

    public function queueAcknowledgmentForGroup(
        EDIGroup $receivedGroup,
        string $interchangeSender,
        bool $sendAcknowledgement
    ):? EDITransaction997
    {
        // Never send acknowledgments for acknowledgments.
        if ($receivedGroup->getFunctionalIdCode() === 'FA') {
            return null;
        }

        // Add transaction
        $transaction = EDITransaction997::create($this->scac);

        try {
            $this->addSegments($transaction, $receivedGroup);
            if ($sendAcknowledgement) {
                $logId = $this->edi_logger->queueOutgoingTransaction(
                    $this->id,
                    $interchangeSender,
                    $receivedGroup->getSenderCode(),
                    $transaction
                );
            }

            return $transaction;
        } catch (Exception $exception) {
            Logger::notice($exception->getMessage());
        }

        return null;
    }

    /**
     * Add the necessary segments to the transaction.
     */
    protected function addSegments(EDITransaction997 &$transaction, EDIGroup $receivedGroup): void
    {
        $transaction->addSegmentAK1(
            $receivedGroup->getFunctionalIdCode(),
            $receivedGroup->getControlNumber(),
            $receivedGroup->getX12Version()
        );

        foreach ($receivedGroup->getTransactions() as $receivedTransaction) {
            $this->addSegmentsForTransaction($transaction, $receivedTransaction);
        }

        $transaction->addSegmentAK9(
            $receivedGroup->getAcceptanceCode(),
            $receivedGroup->getSubmittedTransactionCount(),
            $receivedGroup->countTransactions(),
            $receivedGroup->getAcceptedCount(),
            $receivedGroup->getErrorCodes()
        );
    }

    /**
     * Add segments acknowledging the provided transaction.
     */
    protected function addSegmentsForTransaction(EDITransaction997 &$transaction, EDITransaction $receivedTransaction): void
    {
        $transaction->addSegmentAK2(
            $receivedTransaction->getTransactionTypeCode(),
            $receivedTransaction->getControlNumber()
        );

        foreach ($receivedTransaction->getSegments() as $i => $receivedSegment) {
            $this->addSegmentsForSegment($transaction, $receivedSegment, $i);
        }

        $transaction->addSegmentAK5($receivedTransaction->getAcceptance(), $receivedTransaction->getErrorCodes());
    }

    /**
     * Include segments responding to the provided segment but only if there are errors.
     */
    protected function addSegmentsForSegment(
        EDITransaction997 &$transaction,
        EDISegment $receivedSegment,
        int $positionInTransaction
    ): void {
        $transaction->addSegmentAK3(
            $receivedSegment->getId(),
            $positionInTransaction,
            $receivedSegment->getErrorCodes()
        );

        foreach ($receivedSegment->getElementErrors() as $elementError) {
            $transaction->addSegmentAK4(
                $elementError['position'],
                EDISchemas::getDataElementId($receivedSegment->getId(), $elementError['position']),
                $elementError['errorCode'],
                $elementError['value']
            );
        } // End loop for each element error
    }
}
