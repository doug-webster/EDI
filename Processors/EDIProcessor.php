<?php

namespace GZMP\EDI\Processors;

use Exception;
use GZMP\EDI\EDILogger;
use GZMP\EDI\EDIPartner;

class EDIProcessor extends EDIPartner
{
    public function completeQueuedTransaction(EDILogger $logger, int $id)
    {
        $logger->updateStatus($id, 'Received');
    }

    public static function getProcessorClass($typeCode): string
    {
        $class = "\GZMP\EDI\Processors\EDIProcessor{$typeCode}";

        if (!class_exists($class)) {
            throw new Exception("No EDI Processor for {$typeCode} transactions.");
        }

        return $class;
    }
}
