<?php

namespace GZMP\EDI\Core;

use GZMP\EDI\Core\Data\EDIAcknowledgementData;
use GZMP\EDI\Core\Segments\EDISegmentAK1;
use GZMP\EDI\Core\Transactions\EDITransaction997;

class EDInterchangeAck extends EDInterchange
{
    /*
     * Return an object representing the acknowledgement for the given group control number.
     */
    public function getAcknowledgementData(string $functionalIdCode, string $groupControlNumber): EDIAcknowledgementData
    {
        $data = new EDIAcknowledgementData();

        $groups = $this->getGroups();
        if (empty($groups[0]) || !$groups[0] instanceof EDIGroup) {
            return $data;
        }

        foreach ($groups[0]->getTransactions() as $transaction) {
            if (!$transaction instanceof EDITransaction997) {
                continue;
            }

            foreach ($transaction->getSegments() as $segment) {
                if (!$segment instanceof EDISegmentAK1
                    || $segment->getValues()[1] != $functionalIdCode
                    || $segment->getValues()[2] != $groupControlNumber
                ) {
                    continue;
                }

                $warnings = $data->processTransaction($transaction);
            } // End loop for each segment
        } // End loop for each transaction

        return $data;
    }
}
