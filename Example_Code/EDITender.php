<?php

namespace GZMP\EDI;

use customers;
use DateTimeInterface;
use Exception;
use load;
use Logger;
use GZMP\EDI\Core\Data\EDILoadTenderData;
use GZMP\EDI\Core\Metadata\EDICodes;
use GZMP\EDI\Core\Metadata\EDISchemas;
use GZMP\EDI\Core\Transactions\EDITransaction204;
use GZMP\EDI\Processors\EDIProcessor204;
use TypeError;
use Utility;

/**
 * This class is a placeholder and incomplete example of what an EDITender object may look like. 
 */

class EDITender
{
    protected $id;
    protected $partner_id;
    protected $must_respond_by;
    protected $status;

    protected $customerId;
    protected $companyName;
    protected $bid_amount;

    protected $stops = [];
    protected $data;
    private $changes = [];

    private customers $customer;
    private load $load;
    private $dispatches;
    private $ediPartner;

    private array $tenderMap = [
        '' => 'Shipment Identification Number',
        'must_respond_by' => 'Must Respond By [64]',

        '' => ['stops' => [1 => ['entities' => [0 => 'Name']]]],
        '' => ['stops' => [1 => ['entities' => [0 => ['Address Information' => 0]]]]],
        '' => ['stops' => [1 => ['entities' => [0 => ['Address Information' => 1]]]]],
        '' => ['stops' => [1 => ['entities' => [0 => 'City']]]],
        '' => ['stops' => [1 => ['entities' => [0 => 'State / Province']]]],
        '' => ['stops' => [1 => ['entities' => [0 => 'Postal Code']]]],
        '' => ['stops' => [1 => ['entities' => [0 => ['Contacts' => 'Name']]]]],
        '' => ['stops' => [1 => ['entities' => [0 => ['Contacts' => 'Email']]]]],
        '' => ['stops' => [1 => ['entities' => [0 => ['Contacts' => 'Telephone']]]]],
        '' => ['stops' => [1 => ['entities' => [0 => ['Contacts' => 'Facsimile']]]]],
        '' => ['stops' => [1 => ['entities' => [0 => ['Identification Code']]]]],

        // -1 here indicates last stop, aka the consignee
        '' => ['stops' => [-1 => ['entities' => [0 => 'Name']]]],
        '' => ['stops' => [-1 => ['entities' => [0 => ['Address Information' => 0]]]]],
        '' => ['stops' => [-1 => ['entities' => [0 => ['Address Information' => 1]]]]],
        '' => ['stops' => [-1 => ['entities' => [0 => 'City']]]],
        '' => ['stops' => [-1 => ['entities' => [0 => 'State / Province']]]],
        '' => ['stops' => [-1 => ['entities' => [0 => 'Postal Code']]]],
        '' => ['stops' => [-1 => ['entities' => [0 => ['Contacts' => 'Name']]]]],
        '' => ['stops' => [-1 => ['entities' => [0 => ['Contacts' => 'Email']]]]],
        '' => ['stops' => [-1 => ['entities' => [0 => ['Contacts' => 'Telephone']]]]],
        '' => ['stops' => [-1 => ['entities' => [0 => ['Contacts' => 'Facsimile']]]]],
        '' => ['stops' => [-1 => ['entities' => [0 => ['Identification Code']]]]],

        '' => ['Freight Rate', 'Charge'],
        '' => 'Miles [DH]',

        '' => ['stops' => [1 => [
            'Ship Not Before [37]',
            'Requested Ship/Pick-up Date [10]',
            'Scheduled Pickup Date [69]',
            'Transaction Control Date [BB]',
        ]]], // TODO: if time portion = 0, assume time intended to be null?
        '' => ['stops' => [1 => [
            'Ship Not Later Than [38]',
            'Requested Ship/Pick-up Date [10]',
            'Scheduled Pickup Date [69]',
            'Transaction Control Date [BB]',
        ]]],
        '' => ['stops' => [-1 => [
            'Deliver Not Before [53]',
            'Requested Delivery Date [68]',
            'Delivery Requested on This Date [02]',
            'Scheduled Delivery Date [70]',
        ]]],
        '' => ['stops' => [-1 => [
            'Deliver Not Later Than [54]',
            'Requested Delivery Date [68]',
            'Delivery Requested on This Date [02]',
            'Scheduled Delivery Date [70]',
        ]]],

        '' => ['stops' => [1 => ['Pick Up Number']]],
        // "*" means that the data will be searched globally
        '' => ['*' => ['Purchase Order #', 'PO Number']],
        '' => ['*' => [
            'Bill of Lading',
            'BOL',
            'BOL NUMBER',
        ]],
        '' => ['equipment' => ['Number']],
        '' => 'Length',
        '' => 'Width',
        '' => 'Height',
        '' => 'Lading Quantity',
        // Weight Qualifier can be Gross Weight [G], Actual Net Weight [N], or Freight Weight [FR]
        '' => ['Weight'],
    ];

    private array $stopMap = [
        '' => 'Stop Sequence Number',

        '' => ['entities' => [0 => 'Name']],
        '' => ['entities' => [0 => ['Address Information' => 0]]],
        '' => ['entities' => [0 => ['Address Information' => 1]]],
        '' => ['entities' => [0 => 'City']],
        '' => ['entities' => [0 => 'State / Province']],
        '' => ['entities' => [0 => 'Postal Code']],
        '' => ['entities' => [0 => ['Contacts' => 'Name']]],
        '' => ['entities' => [0 => ['Contacts' => 'Email']]],
        '' => ['entities' => [0 => ['Contacts' => 'Telephone']]],
        '' => ['entities' => [0 => ['Contacts' => 'Facsimile']]],

        '' => [
            'Ship Not Before [37]',
            'Requested Ship/Pick-up Date [10]',
            'Scheduled Pickup Date [69]',
            'Ship Not Later Than [38]',

            'Deliver Not Before [53]',
            'Deliver Not Later Than [54]',
            'Requested Delivery Date [68]',
            'Delivery Requested on This Date [02]',
            'Scheduled Delivery Date [70]',

            'Transaction Control Date [BB]',
        ],
        '' => ['Order #', 'PSL ORDER #'],
        '' => 'Lading Quantity',
        '' => ['Reference Identification', 'Mutually Agreed Upon'],
        '' => ['Weight', 'Gross Weight'],
    ];

    public function __construct(EDIPartner $partner = null)
    {
        if (is_numeric($passed)) {
            $passed = $this->getById((int)$passed);
        }

        if (is_array($passed)) {
            $this->loadFromArray($passed);
        }

        if (!empty($this->id)) {
            $this->setLoad();
        }

        if ($partner instanceof EDIPartner) {
            $this->ediPartner = $partner;
        }
    }

    protected function getById(int $id)
    {
        $query = <<<SQL
        SELECT *
        FROM edi_tenders
        WHERE deleted_at IS NULL
          AND id = :id
        SQL;
        return $this->db->getRecord($query, ['id' => $id]);
    }

    /**
     * Populate this object's properties based on the values in the given array. This is designed to work with an array
     * matching an edi_tender record in the database.
     */
    public function loadFromArray(array $input): void
    {
        foreach (array_keys(get_class_vars(get_class())) as $property) {
            $value = null;

            if (!isset($input[$property])) {
                continue;
            }

            if (is_string($input[$property])) {
                // If the value is json, we want to decode it; if it's not, null is returned
                $value = json_decode($input[$property], true);
            }

            try {
                $this->$property = $value ?? $input[$property];
            } catch (TypeError $exception) {
            }
        } // End loop for each property

        $this->setCustomer($this->bt_customerId);
    }

    /*
     * Attempt to look up and set the object properties by customer and reference number.
     */
    public function setByReferenceNumber(string $referenceNumber, int $customerId): void
    {
    }

    public function setCustomer(?int $customerId): void
    {
    }

    private function prepForSave(): void
    {
        $this->stops = json_encode($this->stops);
        $this->data = json_encode($this->data);
        $this->changes = !empty($this->changes) ? json_encode($this->changes) : null;
    }

    public function save(): bool
    {
        $this->prepForSave();


        $this->postSave();
    }

    private function postSave()
    {
        // decode so that the data can be used as normal
        $this->stops = json_decode($this->stops, true);
        $this->data = json_decode($this->data, true);
        $this->changes = json_decode($this->changes ?? '[]', true);
    }


    /**
     * Handle an incoming tender.
     */
    public function handleIncoming(
        EDILoadTenderData $data,
        bool $autoAccept,
        EDITransaction204 $transaction,
        int $log_id
    ): array {
        $warnings = [];

        // Inactive customer
        if (isset($this->customer) && !empty($this->customer->id) && !$this->customer->isActive()) {
            $warnings[] = $this->handleInactiveCustomer();
        }

        $purposeCode = $data->getCode('Transaction Set Purpose Code');

        if (!empty($this->id)) {
            $orgData = $this->getValuesForComparison();
        }

        $this->convertFromEDIData($data);

        // Canceled tender
        if (in_array($purposeCode, EDIProcessor204::CANCEL_CODES)) {
            $this->cancel();
        } else {
            if (!empty($this->id)) {
                $changes = self::getDifferences($orgData, $this->getValuesForComparison());
                $this->changes = array_merge_recursive($this->changes, $changes);
                if (empty($this->changes)) {
                    $this->changes = $changes = ['No Changes'];
                }
            }

            $this->status = 'Response Needed';

            if ($this->bidRequest($transaction)) {
                $this->status = 'Bid';
            }
        }

        // Save
        if (!$this->save()) {
            throw new Exception("Failure saving EDI tender for {$this->companyName} load {$this->gn_refNumber}");
        }

        EDITenderHistory::record(
            $this->db,
            $this->id,
            $log_id,
            EDICodes::getDescriptionText('B2A', 1, $purposeCode, false),
            $changes ?? [],
            -1
        );

        if (!isset($this->customer) || !$this->customer->isActive()) {
            return $warnings;
        }

        if ($this->status == 'Response Needed') {
            if ($autoAccept) {
                try {
                    $partner = $this->getEDIPartner();
                    $this->accept($partner->shouldSendAutoAcceptResponse($transaction));
                } catch (Exception $exception) {
                    $warnings[] = $exception->getMessage();
                }
                $this->sendLoadAcceptedNotification();
            } else {
                $this->sendTenderNotification();
            }
        }

        return $warnings;
    }

    /**
     * Addresses the case of an incoming tender for an inactive customer.
     */
    protected function handleInactiveCustomer()
    {
        $subject = "Load Tender Received for Inactive Customer";
        $message = "We received an EDI load tender for {$this->companyName}"
            . " ($this->bt_customerId) but this customer is inactive.";

        return $message;
    }

    protected function getValuesForComparison(): array
    {
        $data = [];
        return $data;
    }

    protected function bidRequest(EDITransaction204 $transaction)
    {
        $partner = $this->getEDIPartner();
        $bidRequest = $partner->getAdditionalData('bidRequest');
        if (empty($bidRequest)) {
            return false;
        }

        $qualifiers = isset($bidRequest['qualifiers']) ? (is_string($bidRequest['qualifiers'])
            ? explode(',', $bidRequest['qualifiers']) : $bidRequest['qualifiers']) : null;
        $value = $transaction->getDataValue(
            $bidRequest['segment'],
            $bidRequest['element'],
            $bidRequest['qualifierElement'],
            $qualifiers
        );

        return strtoupper($value) == $bidRequest['value'];
    }

    /**
     * Populates this object's properties based on the given EDILoadTenderData.
     */
    public function convertFromEDIData(EDILoadTenderData $data)
    {
        $this->data = $dataArray = $data->getAll();
        $stops = $data->getValue('stops', true);
        $this->reindexConsigneeMap(count($stops));

        $this->filterMaps($this->getEDIPartner());

        // Loop through mapping
        $values = [];
        foreach ($this->tenderMap as $property => $dataKey) {
            if (is_array($dataKey) && array_key_exists('*', $dataKey)) {
                $values[$property] = $this->pullValueFromArrayRecursively($dataArray, $dataKey['*']);
            } else {
                $values[$property] = $this->pullValueFromArray($dataArray, $dataKey);
            }
        }
        $this->loadFromArray($values);

        if ($this->pullValueFromArray($dataArray, 'Measurement Unit Qualifier') === 'Inches [N]') {
            $this->length = round($this->length / 12, 2);
        }

        $this->status = 'Response Needed';

        if (empty($this->miles)) {
            $this->miles = $this->calculateMiles();
        }

        $this->setTarp($data);

        $this->comments = $this->compileComments($data, $dataArray);

        $this->stops = []; // Reset in case we're updating an existing tender
        foreach ($stops as $stop) { // $stopNumber should start with 1
            if (!isset($stop['Stop Sequence Number']) && !is_numeric($stop['Stop Sequence Number'])) {
                Logger::notice("Load tender {$this->id} contains an invalid stop.");
                continue;
            }
            if ($stop['Stop Sequence Number'] == 1) {
                $this->setLoadShipper(1, $stop);
            } elseif ($stop['Stop Sequence Number'] == count($stops)) {
                $this->setLoadConsignee($stop['Stop Sequence Number'], $stop);
            } else {
                $this->setLoadIntermediateStop($stop['Stop Sequence Number'], $data);
            }
        }
    }

    private function reindexConsigneeMap(int $stopNumber)
    {
        // '' => ['stops' => [-1 => ['entities' => [0 => 'Name']]]],
        foreach ($this->tenderMap as $key => $value) {
            if (empty($value['stops'][-1])) {
                continue;
            }

            $this->tenderMap[$key]['stops'][$stopNumber] = $value['stops'][-1];
            unset($this->tenderMap[$key]['stops'][-1]);
        }
    }

    private function filterMaps(EDIPartner $partner)
    {
        $excludes = array_flip($partner->getAdditionalData('tenderExcludes') ?: []);
        $this->tenderMap = array_diff_key($this->tenderMap, $excludes);
        $this->stopMap = array_diff_key($this->stopMap, $excludes);
    }

    private function pullValueFromArray(array &$array, $key, bool $allowReturnArray = false)
    {
        if (is_string($key)) {
            $value = $array[$key] ?? null;
            unset($array[$key]);
            if (is_array($value) && !$allowReturnArray) {
                $value = array_shift($value);
            }
            if ($value instanceof DateTimeInterface) {
                $value = date('c', $value->getTimestamp());
            }
            return $value;
        }

        // This is an unexpected condition if encountered
        if (!is_array($key)) {
            return null;
        }

        $keys = $key;

        foreach ($keys as $index => $key) {
            // This allows us into nested values
            // There are a few possibilities here:
            // 1. the key may be a string index to look up in the array
            // 2. the key may be an array of indexes to look up in the array, using the first non-empty value
            // 3. the index may be set to match to a key in array to look into recursively
            // 4. the index may be a string index to look up in the array and the key may be a numeric key to pull if the
            // value in $array is an array; if it is not, return the value if key is 0 or null otherwise
            if (isset($array[$index])) {
                $array2 = $array[$index] ?: [];
                if (!is_array($array2)) {
                    $value = ($key === 0) ? $array2 : null;
                } else {
                    $value = $this->pullValueFromArray($array2, $key, $allowReturnArray);
                }
                if ($array2) {
                    $array[$index] = $array2;
                }
            } else {
                $value = $this->pullValueFromArray($array, $key, $allowReturnArray);
            }

            if ($value) {
                return (is_array($value)) ? array_shift($value) : $value;
            }
        } // end loop

        return null;
    }

    private function pullValueFromArrayRecursively(array &$array, $keys, bool $allowReturnArray = false)
    {
        if (!is_array($keys)) {
            $keys = [$keys];
        }

        // Try to find any of the matching keys in the top level of the array.
        foreach ($keys as $key) {
            if (empty($array[$key])) {
                continue;
            }
            $value = $array[$key];
            unset($array[$key]);
            if (is_array($value) && !$allowReturnArray) {
                $value = array_shift($value);
            }
            if ($value instanceof DateTimeInterface) {
                $value = date('c', $value->getTimestamp());
            }
            return $value;
        }

        // If none of the keys have been found, begin searching in any child arrays.
        foreach ($array as $i => $innerArray) {
            if (!is_array($innerArray)) {
                continue;
            }
            $value = $this->pullValueFromArrayRecursively($innerArray, $keys, $allowReturnArray);
            if (!empty($value)) {
                $array[$i] = $innerArray;
                return $value;
            }
        }

        return null;
    }

    private function setTarp(EDILoadTenderData $data)
    {
        $haystack = print_r($data->getAll(), true);
        $search = implode('|', [
            'Protective Tarp For Security Purposes',
            'Tarp Required',
            'TARPING REQUIRED',
        ]);
        $this->tarp_required = (bool)preg_match("/{$search}/i", $haystack);
    }

    private function compileComments(EDILoadTenderData $data, array $dataArray)
    {
        unset($dataArray['stops']);
        unset($dataArray['Transaction Set Identifier Code']);
        unset($dataArray['Transaction Set Control Number']);
        unset($dataArray['SCAC (Standard Carrier Alpha Code)']);
        EDITender::removeEmptyValuesRecursively($dataArray);

        return Utility::convertArrayForDisplay($data->toArray($dataArray));
    }

    public static function removeEmptyValuesRecursively(array &$array)
    {
        foreach ($array as $key => $item) {
            if (empty($item)) {
                unset($array[$key]);
                continue;
            }
            if (is_array($item)) {
                EDITender::removeEmptyValuesRecursively($item);
            }
        }
    }

    private function setLoadShipper(int $stopNumber, array $stop)
    {
    }

    private function setLoadConsignee($stopNumber, $stop)
    {
    }

    /**
     * Add info for specified stop to stops based on given data.
     */
    protected function setLoadIntermediateStop(int $stopNumber, EDILoadTenderData $data)
    {
        $dataArray = $data->getStop($stopNumber);

        // Loop through mapping
        $stop = [];
        foreach ($this->stopMap as $property => $dataKey) {
            $stop[$property] = $this->pullValueFromArray($dataArray, $dataKey);

            // Encountered some issues with values which are double-precision columns in the load_stopOffs table
            if (in_array($property, ['st_pieces', 'st_weight']) && !is_null($stop[$property])) {
                if (!is_array($stop[$property])) {
                    $array = [$stop[$property]];
                } else {
                    $array = $stop[$property];
                }
                $stop[$property] = 0;
                foreach ($array as $v) {
                    $stop[$property] += (int)$v;
                }
            }
        }

        $loadOptions = [
            'Load [LD]',
            'Complete Load [CL]',
            'Pickup PreLoad [PA]',
            'Partial Load [PL]',
        ];
        $reason = $data->getStopValue($stopNumber, 'Stop Reason Code');
        $stop['type'] = (in_array($reason, $loadOptions)) ? 'PU' : 'SO';

        EDITender::removeEmptyValuesRecursively($dataArray);
        $stop['comments'] = $this->getStopNotes($stopNumber);

        $this->stops[] = $stop;
    }

    public function getLocation(string $prefix, array $values = [])
    {
    }

    protected function getStopNotes(int $stopNumber): string
    {
        $output = [];

        if (!isset($this->data['stops'][$stopNumber])) {
            return '';
        }

        $stop = $this->data['stops'][$stopNumber];

        $entities = $stop['entities'] ?? [];
        if (!is_array($entities)) {
            $entities = [$entities];
        }

        foreach ($entities as $entity) {
            // from G61
            $contacts = $entity['Contacts'] ?? [];
            if (!is_array($contacts)) {
                $contacts = [$contacts];
            }

            foreach ($contacts as $contact) {
                $contactText = '';

                if (!empty($contact['Type'])) {
                    $contactText .= "{$contact['Type']}:\n";
                }

                if (!empty($contact['Name'])) {
                    $contactText .= "{$contact['Name']}\n";
                }

                $types = EDICodes::getCodesForDataElement(EDISchemas::getDataElementId('G61', 3));
                $types[''] = ''; // add blank in order to check for blank key below
                foreach ($types as $value) {
                    if (!empty($contact[$value])) {
                        if (!empty($value)) {
                            $contactText .= "{$value}: ";
                        }
                        $contactText .= $contact[$value];
                    }
                }

                $output[] = $contactText;
            }
        } // end loop for each entity

        // from NTE and  AT5
        if (!empty($this->data['stops'][$stopNumber]['Notes'])) {
            if (is_array($this->data['stops'][$stopNumber]['Notes'])) {
                $output[] = implode("\n", $this->data['stops'][$stopNumber]['Notes']);
            } else {
                $output[] = $this->data['stops'][$stopNumber]['Notes'];
            }
        }

        return implode("\n", array_filter($output));
    }

    protected function calculateMiles()
    {
    }

    public static function formatAddress($a, $linebreaks = true, $html = false)
    {
        $address = '';
        if (!empty($a['address1'])) {
            $address .= $a['address1'];
        }
        if (!empty($address) && !empty($a['address2'])) {
            $address .= ($linebreaks) ? "\n" : ' ';
        }
        if (!empty($a['address2'])) {
            $address .= $a['address2'];
        }
        if (!empty($address) && !empty($a['city'])) {
            $address .= ($linebreaks) ? "\n" : ', ';
        }
        if (!empty($a['city'])) {
            $address .= $a['city'];
        }
        if (!empty($address) && !empty($a['state'])) {
            $address .= ', ';
        }
        if (!empty($a['state'])) {
            $address .= $a['state'];
        }
        $address .= ' ';
        if (!empty($a['zipcode'])) {
            $address .= $a['zipcode'];
        }
        if (!empty($address) && !empty($a['country'])) {
            $address .= ($linebreaks) ? "\n" : ' ';
        }
        if (!empty($a['country'])) {
            $address .= $a['country'];
        }

        return ($html) ? nl2br(htmlspecialchars($address)) : $address;
    }


    /**
     * Handle an incoming EDI tender cancelation message.
     */
    public function cancel()
    {
        $partner = $this->getEDIPartner();

        // If this tender was already canceled, we shouldn't need to do anything.
        if ($this->status == 'Canceled') {
            return;
        }

        $companyName = $partner->getName();
        if (empty($this->customer)) {
            $customer = new customers($this->customerId);
            $companyName = $customer->companyName;
        }

        $message = '';

        $subject = "EDI 204 Load Canceled - {$partner->getName()}";

        $load = $this->getMatchingLoad();

        if ($load->id) {
            $note = "This load has been canceled by the customer: {$companyName}";
            $load->addNote($note, true);

            $this->sendNotifications(
                $subject,
                $message,
                true,
                1,
                5,
                ['id' => $load->id]
            );
        } elseif ($this->status == 'Accepted') {
            Logger::warning("edi_tender {$this->id} has a status of {$this->status} but the corresponding load was not found.");
        } else {
            if (in_array($this->status, ['Response Needed'])) {
                $message .= "{$companyName} has withdrawn a load that was still in the 204 Queue (Shipper Load #: {$this->gn_refNumber}).";
                $this->sendNotifications($subject, $message, $partner->sendEmailNotifications());
            }
        }

        // Update this edi_tender
        $this->status = 'Canceled';
    }


    public function getMatchingLoad(): load
    {
        if (empty($this->load)) {
            $this->setLoad();
        }

        return $this->load;
    }

    public function setLoad(): void
    {
        $query = <<<SQL
            SELECT id
            FROM loads
            WHERE edi_tender_id = :edi_tender_id
        SQL;
        $binds = [
            'edi_tender_id' => $this->id,
        ];
        $loadId = $this->db->getvalue($query, $binds);

        if ($loadId) {
            $this->load = new load($loadId, true);
        } elseif (!empty($this->bt_customerId) && !empty($this->referenceNumber)) {
            $this->setLoadByRefNum();
        } else {
            $this->load = new load();
        }

        $this->id = (int)$this->load->getVar('id');
    }

    /*
     * Attempt to match load by customer and reference number.
     */
    protected function setLoadByRefNum()
    {
        if (empty($this->customerId)) {
            $this->load = new load();
            return;
        }

        // Find the most recent non-canceled load matching the customer and reference number.
        $query = <<<SQL
            SELECT id
            FROM loads
            WHERE customer_id = :customer_id
              AND TRIM(reference_number) = :reference_number
            ORDER BY created_at DESC
            LIMIT 1
        SQL;
        $binds = [
            'customer_id' => $this->customerId,
            'reference_number' => $this->referenceNumber,
        ];
        $loads = $this->db->fetchAll($query, $binds);

        if (!empty($loads) && is_array($loads)) {
            if (count($loads) === 1) {
                $load = array_pop($loads);
                $this->load = new load($load['id'], true);
            } else {
                if (count($loads) === 1) {
                    $load = array_pop($loads);
                    $this->load = new load($load['id'], true);
                } else {
                    // If we still have multiple matches, how would we determine which one to use?
                    return;
                }
            }
            $this->id = (int)$this->load->getVar('id');
            $this->updateLoadTenderId();
        } else {
            $this->load = new load();
        }
    }

    protected function updateLoadTenderId()
    {
        if (empty($this->load->id) || empty($this->id)) {
            return;
        }

        $this->load->edi_tender_id = $this->id;
        $this->load->save();
    }

    public function getDispatches()
    {
    }

    public function setDispatches()
    {
    }

    public static function getDifferences(array $oldArray, array $newArray, array $exclude = [], $strict = false): array
    {
        $differences = [];
        // In order to get a combined array with all keys present in either
        $compareArray = array_merge_recursive($oldArray, $newArray);

        foreach ($compareArray as $key => $value) {
            if (in_array($key, $exclude)) {
                continue;
            }

            if (
                (!isset($oldArray[$key]) || $oldArray[$key] === [])
                && (!isset($newArray[$key]) || $newArray[$key] === [])
            ) {
                continue;
            }

            if (is_array($oldArray[$key]) && is_array($newArray[$key])) {
                $diff = self::getDifferences($oldArray[$key], $newArray[$key]);
                if ($diff !== []) {
                    $differences[$key] = $diff;
                }
            } elseif (!$strict && empty($oldArray[$key]) && empty($newArray[$key])) {
                continue;
            } elseif (!isset($oldArray[$key])) {
                $differences[$key]['old'] = null;
                $differences[$key]['new'] = $newArray[$key];
            } elseif (!isset($newArray[$key])) {
                $differences[$key]['old'] = $oldArray[$key];
                $differences[$key]['new'] = null;
            } elseif (($strict && $oldArray[$key] !== $newArray[$key])
                || (!$strict && $oldArray[$key] != $newArray[$key])
            ) {
                $differences[$key]['old'] = $oldArray[$key];
                $differences[$key]['new'] = $newArray[$key];
            }
        }

        return $differences;
    }

    public function updateLoad(array $customMapping = [])
    {
        // Prevent automatic updating of a load which has already been dispatched.
        $dispatches = $this->getDispatches();
        if (!empty($dispatches) && is_array($dispatches)) {
            throw new Exception("There are dispatches assigned to load {$this->load->id}, so it will need to be manually updated.");
        }

        $this->copyValuesToLoad($customMapping);

        if (!$this->load->save()) {
            throw new Exception('Could not save load');
        }

        // Set the id in case this is a new load
        $this->id = (int)$this->load->getVar('id');

        // Update EDI transactions log in order to add load id to 204 tender log
        $edi_logger = new EDILogger($this->db);
        $edi_logger->updateLoadId($this->gn_refNumber, $this->partner_id, $this->id);

        $this->load->saveStopoffs();

        return $this->load;
    }

    private function copyValuesToLoad(array $customMapping = [])
    {
        $properties = get_object_vars($this);

        // If there is an existing load and if there are changes, only update the fields which have changed.
        if (!empty($this->changes) && $this->load->id) {
            $keys = array_keys($this->changes);
            $properties = array_intersect_key($properties, array_flip($keys));
        }

        foreach ($properties as $property => $value) {
            // Prevent overwriting of load values with blank values.
            if (!is_null($value) && $value !== '') {
                $this->load->setVar($property, $value);
            }
        } // end loop for each property

        if (array_key_exists('stops', $properties)) {
            $this->copyValuesToLoadStops($customMapping);
        }

        $this->customLoadMapping($customMapping);
    }

    private function copyValuesToLoadStops(array $customMapping = [])
    {
        // If the load is new or if a stop has been added or removed, replace stops.
        if (empty($this->changes) || !$this->load->id
            || count($this->load->stops) != count($this->stops)
        ) {
            $this->load->stops = $this->stops;
            return;
        }

        if (empty($this->changes['stops'])) {
            return;
        }

        foreach ($this->changes['stops'] as $i => $stop) {
            foreach (array_keys($stop) as $key) {
                $this->load->stops[$i][$key] = $this->stops[$i][$key];
            }
        }
    }

    public function updateNotes($newNotes, &$existingNotes)
    {
        $newNotes = trim($newNotes);
        $existingNotes = trim($existingNotes);

        if (empty($existingNotes)) {
            $existingNotes = $newNotes;
        } elseif ($newNotes !== $existingNotes) {
            $existingNotes .= "\n\n{$newNotes}";
        }
    }

    /*
     * Allows for specifying custom mapping from the tender data array to a load parameter. $customMapping should
     * = [
     *      'customerId' => 0, // optional; to filter to only a specific customer
     *      'loadField' => 'field', // load property name
     *      'dataKeyMap' => [mixed], // See comment on getValueFromData() for value type
     * ]
     */
    protected function customLoadMapping(array $customMapping): void
    {
        foreach ($customMapping as $map) {
            if (!empty($map['customerId']) && $map['customerId'] != $this->customerId) {
                continue;
            }

            $value = $this->getValueFromData($map['dataKeyMap']);
            if (!empty($value)) {
                $this->load->setVar($map['loadField'], $value);
            }
        } // End loop for each map item
    }

    /*
     * Pulls value from tender's data array.
     * $key Can be a string key or an array in the form of ['nested1' => ['nested2' => 'field']]
     */
    public function getValueFromData($key)
    {
        $data = $this->data;

        if (is_array($key)) {
            do {
                $data = array_intersect_key($data, $key);
                $data = array_pop($data);
                $key = array_pop($key);
            } while (is_array($data) && is_array($key));

            if (is_array($key) || is_null($key)) {
                return null;
            }
        }

        return $data[$key] ?? null;
    }

    /**
     * Decline a load tender.
     */
    public function decline(bool $expired = false, int $reasonId = null)
    {
        $partner = $this->getEDIPartner();

        $sendResponse = true;
        if (!empty($this->data['Transaction Set Purpose Code'])
            && in_array($this->data['Transaction Set Purpose Code'], EDIProcessor204::NO_RESPONSE_CODES)
        ) {
            $sendResponse = false;
        } elseif ($expired) {
            $sendResponse = $partner->sendResponseForExpired();
        }

        if ($sendResponse) {
            $generator = $partner->createTenderResponseGenerator();
            $logId = $generator->respond($this, false, $reasonId);
        }

        $this->status = $expired ? 'Expired' : 'Declined';
        $this->save();

        EDITenderHistory::record(
            $this->db,
            $this->id,
            $logId ?? 0,
            $expired ? 'Auto Decline Expired' : 'Declined',
            [],
            0
        );

        if ($expired) {
            $this->notifyOfDeclinedLoad();
        }

        $dispatches = $this->getDispatches();
        if (!empty($dispatches) && is_array($dispatches)) {
            throw new Exception("There are dispatches assigned to this load, so it will need to be manually canceled.");
        }
    }

    protected function notifyOfDeclinedLoad()
    {
        $partner = $this->getEDIPartner();
        $load = $this->getMatchingLoad();

        if (!$load->id) {
            return;
        }

        $note = "This load was declined by {$name}";
        $load->addNote($note, true);

        $this->sendNotifications(
            "EDI 204 Load Declined - {$partner->getName()}",
            $message,
            true,
            1,
            5,
            ['id' => $load->id]
        );
    }

    /**
     * Accept this incoming tender.
     */
    public function accept(bool $sendAutoAcceptResponse = null): void
    {
        $partner = $this->getEDIPartner();
        $load = $this->getMatchingLoad();

        try {
            $load = $this->updateLoad($partner->getAdditionalData('customMapping') ?? []);
        } catch (Exception $exception) {
            $notices[] = "{$exception->getMessage()}";
        }

        $sendResponse = true;
        if (!empty($this->data['Transaction Set Purpose Code'])
            && in_array($this->data['Transaction Set Purpose Code'], EDIProcessor204::NO_RESPONSE_CODES)
        ) {
            $sendResponse = false;
        } elseif (isset($sendAutoAcceptResponse)) {
            $sendResponse = $sendAutoAcceptResponse;
        }

        if ($sendResponse) {
            $generator = $partner->createTenderResponseGenerator();
            $logId = $generator->respond($this, true);
        }

        // Update tender
        $this->status = 'Accepted';
        // Reset the changes field in case another tender comes in; we want to know that we already accepted the current
        // set of changes.
        $this->changes = [];
        $this->save();

        EDITenderHistory::record(
            $this->db,
            $this->id,
            $logId ?: 0,
            'Accepted',
            [],
            0
        );

        if (!empty($notices)) {
            throw new Exception(implode("\n", $notices));
        }
    }

    public function bid(float $amount)
    {
        $this->bid_amount = $amount;
        if (!$this->save()) {
            return false;
        }

        // send response
        $partner = $this->getEDIPartner();
        $generator = $partner->createTenderResponseGenerator();
        $logId = $generator->respondBid($this, $amount);

        EDITenderHistory::record(
            $this->db,
            $this->id,
            $logId ?? 0,
            "Bid \$" . number_format($amount, 2),
            [],
            0
        );

        return true;
    }

    public function setEDIPartner()
    {
        $this->ediPartner = new EDIPartner($this->db, $this->partner_id ?? null);
    }

    public function getEDIPartner(): EDIPartner
    {
        if (is_null($this->ediPartner)) {
            $this->setEDIPartner();
        }

        return $this->ediPartner;
    }

    public function getLoadPreview(array $customMapping = []): load
    {
        $this->load = $this->getMatchingLoad(); // Just to ensure the load is loaded
        $this->copyValuesToLoad($customMapping);
        return $this->load;
    }

    public function __toArray(): array
    {
        return get_object_vars($this);
    }


    /**
     * Send a notification for an incoming load tender.
     */
    protected function sendTenderNotification()
    {
        $partner = $this->getEDIPartner();
        $message = "A {$this->companyName} load has been added to the Load Tendering Queue.";
        $subject = "New Load Tender - {$this->companyName}";
        $this->sendNotifications($subject, $message, $partner->sendEmailNotifications());
    }

    /**
     * Send a notification for an automatically accepted incoming load tender.
     */
    protected function sendLoadAcceptedNotification()
    {
        $partner = $this->getEDIPartner();
        $message = "A {$this->companyName} load has been added to the Available Loads section on the Freight Ops Dashboard.";
        $this->sendNotifications("New Load Available", $message, $partner->sendEmailNotifications());
    }

    /**
     * Send notifications: send email if $sendEmailNotifications is true.
     */
    public function sendNotifications(
        string $subject,
        string $message,
        bool $sendEmailNotifications = true,
        $iconId = 1,
        $actionId = 12,
        $actionData = []
    ) {
        $users_to_notify = $this->getNotificationList(true);
        if (empty($users_to_notify) || !is_array($users_to_notify)) {
            return;
        }

        $notificationList = array();
        foreach ($users_to_notify as $user) {
            $notificationList[$user['user_id']] = $user['email'];
        }

        $message .= "\n{$this->getLoadText()}";

        if ($sendEmailNotifications) {
            Utility::sendEmail($notificationList, null, $subject, $message, '', '', false, nl2br($message));
        }
    }

    /**
     * Determine and return an array of users to notify based on this tender's EDI partner.
     */
    public function getNotificationList(bool $onlyActiveUsers = false)
    {
        $partner = $this->getEDIPartner();

        $notificationList = $partner->getNotificationList($onlyActiveUsers);

        return $notificationList;
    }

    /**
     * Return a text summary of this tender.
     */
    public function getLoadText()
    {
    }

    /**
     * Return a summary of this tender's stops.
     */
    public function getStopsText()
    {
    }

    /**
     * Return a summary of the stop based on the given stop data.
     */
    public function getStopText($info)
    {
    }
}
