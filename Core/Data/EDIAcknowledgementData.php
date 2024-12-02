<?php

namespace GZMP\EDI\Core\Data;

/**
 * A class representing the data contained within an EDI acknowledgment message.
 */
class EDIAcknowledgementData extends EDIData
{
    protected array $loopHierarchy = [
        100 => [
            200 => [],
        ],
    ];
    protected array $loopInitializers = [
        100 => 'AK2',
        200 => 'AK3',
    ];
    protected array $loopIdsToKeys = [
        'AK2' => 'transactionResponses',
        'AK3' => 'segmentErrors',
    ];

    public function postProcessing()
    {
        parent::postProcessing();

        // Flatten segment error messages
        if (!empty($this->data['transactionResponses'])) {
            foreach ($this->data['transactionResponses'] as $i => $response) {
                if (!empty($response['segmentErrors'])) {
                    foreach ($response['segmentErrors'] as $j => $segmentError) {
                        if (!empty($segmentError['Message']) && is_array($segmentError['Message'])) {
                            $this->data['transactionResponses'][$i]['segmentErrors'][$j]['Message']
                                = implode("\n", $segmentError['Message']);
                        }
                    }
                }
            }
        }
    }
}
