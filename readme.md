# EDI Package Documentation

This package contains a suite of classes which are designed to handle the processing and generating of EDI documents.

## Core
At it's core, this package contains classes for the "wrappers" of the interchange, group, and transactions as well as a class for segments:
- **EDInterchange**
- **EDIGroup**
- **EDITransaction** (as well as child classes for specific transaction types)
- **EDISegment** (as well as child classes for specific segment ids)

These classes are all intended to be generic and not rely on anything specific to the application making use of them. (They may throw Exceptions and may generate messages to a PSR-3 Logger class.)

## EDI Partners
Another foundational class is that of **EDIPartner**. Because it interacts with the database, it relies to a certain extent on the host application. The EDIPartner is a base class containing all of the configuration specific to an EDI trading partner. Processor and Generator classes descend from the EDIPartner.

## EDI Metadata
- **EDISchemas** Schemas map data elements to segments and provide some additional metadata. This class handles retrieving this data.
- **EDIDataElements** handles retrieving data on specific data elements.
- **EDICodes** handles retrieving codes for specific elements.

## EDI Transaction Logging
- EDI Transactions are logged via **EDILogger**. Acknowledgements (997s) are recorded in the corresponding transaction's log record.

## Processing Incoming Messages
EDI Processing happens in two stages. First, the EDI is parsed into what is effectively PHP object representations of the raw EDI groups, transactions, segments, and data elements. The second step involves translating the EDI into more meaningful data. There is an EDIData parent class along with child classes which are responsible for doing this conversion. So for example, an EDITransaction object may contain N1, N3, and N4 segment objects. EDIData will transform this into an entity array with name and address elements. At this point, the host application can use the data as needed. Below is an example of how this might work (simplified):

    $partner = new EDIPartner($db, $id);
    // Instantiate an EDIProcessor object for the given partner.
    $processor = $partner->createProcessor();
    // Get and process all incoming EDI from partner.
    $processor->processIncoming();

"Under the hood", processIncoming() is doing:

    $interchange = new EDInterchange();
    // First stage of EDI processing.
    $interchange->parse($ediText);
    foreach ($interchange->getGroups() as $group) {
        // Create a specific EDIProcessor child object for the transaction type.
        $typeCode = $group->getTransactionTypeCode();
        $class = "\GZMP\EDI\Processors\EDIProcessor{$typeCode}";
        $processor = $this->createChild($class);
        foreach ($group->getTransactions() as $transaction) {
            $errors = $processor->process($transaction);
        }
    }

The EDIProcessor::process() function will likely transform the EDITransaction into EDIData such as in the following example:

    $data = new EDILoadTenderData();
    $warnings = $data->processTransaction($transaction);

Then the process() function can continue to take actions based on the data received.

## Generating EDI Messages

Generating EDI messages works in a manner similar to the following:

    $generator = $partner->createTenderResponseGenerator();
    $logId = $generator->respond($tender, $accept);

    $generator = $partner->createAcknowledgmentGenerator();
    $transaction = $generator->queueAcknowledgmentForGroup();

    $generator = $partner->createStatusUpdateGenerator();
    $generator->queueStatusUpdate($data);

    $generator = $partner->createBillingGenerator();
    try {
        $generator->queueInvoice((array)$load);
    } catch (Exception $exception) {
    }
