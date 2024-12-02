<?php
namespace GZMP\EDI\Processors;

use PDODB;
use GZMP\EDI\Core\Data\EDIData;
use GZMP\EDI\Core\Data\EDILoadTenderData;
use GZMP\EDI\Core\Transactions\EDITransaction204;
use GZMP\EDI\EDITender;

class EDIProcessor204 extends EDIProcessor
{
    public const NEW_CODES = ['00', '06', '49'];
    public const CANCEL_CODES = ['01', '03'];
    public const UPDATE_CODES = ['04', '05'];
    public const NO_RESPONSE_CODES = ['49'];

    public function __construct(PDODB $db, $input = null)
    {
        parent::__construct($db, $input);
    }

    /**
     * Process an incoming load tender by converting it to an EDILoadTenderData object and then proceeding based on
     * the information contained within.
     */
    public function process(EDITransaction204 $transaction, int $log_id = null)
    {
        $data = new EDILoadTenderData();
        $warnings = $data->processTransaction($transaction);

        $customerId = $this->determineCustomerId($transaction);
        if (empty($customerId)) {
            $warnings[] = $this->handleCustomerNotFound($data, $transaction);
        }

        $tender = $this->getOrCreateTender($data, $customerId);

        $this->warnings = array_merge(
            $warnings,
            $tender->handleIncoming(
                $data,
                $this->shouldAutoAccept($transaction),
                $transaction,
                $log_id
            )
        );
    }

    /**
     * Determine which customer id the tender should be assigned to.
     */
    protected function determineCustomerId(EDITransaction204 $transaction): int
    {
        /*$this->tender_in_customers = [
            'customer_lookups' => [
                0 => [
                    'segment' => 'segment to look in',
                    'qualifierElement' => 'if only codes for specific qualifiers, the index of the data element containing the qualifier',
                    'qualifiers' => [
                        0 => '',
                    ],
                    'element' => 'the index of the data element containing a code',
                ],
            ],
            'codes' => [
                'code 1' => 'customer id 1',
            ],
            'default' => 'default customer id',
        ];*/

        if (!empty($this->tender_in_customers['lookups'])
            && is_iterable($this->tender_in_customers['lookups'])
        ) {
            foreach ($this->tender_in_customers['lookups'] as $lookup) {
                $qualifiers = isset($lookup['qualifiers']) ? (is_string($lookup['qualifiers'])
                    ? explode(',', $lookup['qualifiers']) : $lookup['qualifiers']) : null;
                $value = $transaction->getDataValue(
                    $lookup['segment'],
                    $lookup['element'],
                    $lookup['qualifierElement'] ?? null,
                    $qualifiers
                );
                // Check for the presence of the code in the list of codes and return the value if found.
                if (array_key_exists($value, $this->tender_in_customers['codes'])) {
                    return $this->tender_in_customers['codes'][$value];
                }
            } // end loop for each customer lookup point
        }

        return (!empty($this->tender_in_customers['default'])) ? $this->tender_in_customers['default'] : 0;
    }

    /**
     * Handle the case of not being able to determine which customer to assign tender to.
     */
    protected function handleCustomerNotFound(EDIData $data, EDITransaction204 $transaction): string
    {
        $subject = "No customer for load tender";
        $message = "Unable to determine customer for load tender {$data->getValue('referenceNumber')}"
            . " (EDI Transaction ID {$transaction->getControlNumber()})";
        // Do something here
 
        return $message;
    }

    /**
     * If an edi_tender matching the customer and reference number already exists, will return the corresponding
     * EDITender object. Otherwise, will create and return a new EDITender object with the customer info set.
     */
    protected function getOrCreateTender(EDILoadTenderData $data, int $customerId): EDITender
    {
        $tender = new EDITender([], $this);
        $tender->setByReferenceNumber($data->getValue('Shipment Identification Number'), $customerId);
        $tender->setVar('partner_id', $this->id);

        return $tender;
    }

    /**
     * Whether or not tenders should be automatically accepted upon receipt.
     */
    public function shouldAutoAccept(EDITransaction204 $transaction = null, bool $default = false): bool
    {
        return $this->getResponseOption('autoAccept', $transaction, $default);
    }
}
