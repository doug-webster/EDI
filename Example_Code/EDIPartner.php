<?php

namespace GZMP\EDI;

use PDODB;
use Exception;
use FTPFactory;
use GZMP\EDI\Core\EDInterchange;
use GZMP\EDI\Core\Metadata\EDICodes;
use GZMP\EDI\Core\Metadata\EDISchemas;
use GZMP\EDI\Core\Transactions\EDITransaction204;
use GZMP\EDI\Generators\EDIGenerator210;
use GZMP\EDI\Generators\EDIGenerator214;
use GZMP\EDI\Generators\EDIGenerator990;
use GZMP\EDI\Generators\EDIGenerator997;
use GZMP\EDI\Processors\EDIProcessor;
use TypeError;
use Utility;

/**
 * This class represent an entity (company) with whom we exchange EDI messages, and is generally for containing related
 * settings. Deciding who the trading partner is is more complicated than expected. This is because many partners use a
 * third party for EDI. So for example, EDI is exchanged with Carrier and Atlas Roofing, but they both use Ryder for
 * exchanging EDI. I (Doug) have determined that having one EDI partner for each receiver_id makes the most sense. (The
 * exception to this is when an EDI partner uses a different receiver_id for billing.)
 */
class EDIPartner
{
    protected PDODB $db;
    protected array $errors = [];
    protected array $warnings = [];
    protected EDILogger $edi_logger;
    protected static string $scac = '';
    protected string $tempFolder = '';
    protected string $path = '';

    protected int $id = 0;
    protected string $partner = '';
    protected int $file_server_id = 0;
    protected string $inbound_filename = '';
    protected string $outbound_filename = '';
    protected string $outbound_filename_prefix = '';
    protected string $outbound_filename_postfix = '';
    public array $headers = [
        'receiver_id_qualifier' => null,
        'receiver_id' => null,
        'acknowledgment_requested' => null,
        'usage' => null,
    ];
    protected string $sender_code = '';
    protected string $receiver_code = '';
    protected string $class204 = '';
    protected string $class990 = '';
    protected string $class214 = '';
    protected string $class210 = '';
    protected string $class997 = '';
    protected int $exchange_frequency = 0;
    protected int $frequency_offset = 0;
    protected array $tender_responses = [];
    protected array $tender_in_customers = [];
    protected string $timezone;
    protected bool $send_email_notifications = false;
    protected int $send_acknowledgments = 0;
    protected array $status_updates_out = [];
    protected array $billing = [];
    protected array $billing_codes = [];
    protected array $load_requirements = [];
    protected array $additional_data = [];

    protected static array $partnerCodesToIds = [];

    public function __construct(PDODB $db, $input = null, string $scac = '')
    {
        $this->db = $db;
        $this->edi_logger = new EDILogger($this->db);
        if ($scac) {
            $this->setScac($scac);
        }
        // Default values for sending EDI
        $this->headers['sender_id_qualifier'] = '02';
        $this->headers['sender_id'] = $this->scac;
        $this->sender_code = $this->scac;
        $this->timezone = date_default_timezone_get();

        if (is_int($input)) {
            $input = $this->get($input);
        }

        if (is_array($input) && !empty($input)) {
            $this->loadFromArray($input);
        }
    }

    public function setScac(string $scac): void
    {
        static::$scac = $scac;
    }

    /**
     * Retrieve partner record matching the given id from the database.
     */
    public function get(int $id): array
    {
        $query = <<<SQL
            SELECT * FROM edi_partners WHERE deleted_at IS NULL AND id = :id
        SQL;
        $results = $this->db->getRecord($query, ['id' => $id]);
        if (!is_array($results)) {
            $results = [];
        }

        return $results;
    }

    /**
     * Populate this object's properties based on the values in the given array. This is designed to work with an array
     * matching a partner record in the database.
     */
    public function loadFromArray(array $input)
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

            // Do things this way so as to only overwrite array keys in input, not the whole array
            if ($property == 'headers' && !empty($input['headers']) && is_array($input['headers'])) {
                $this->headers = array_merge($this->headers, $input['headers']);
            } else {
                try {
                    $this->$property = $value ?? $input[$property];
                } catch (TypeError $exception) {
                }
            }
        } // End loop for each property
    }

    /**
     * Returns an object of the specified class which must be an EDIPartner or child class. Copies values from the
     * present object into the new class. This is mainly used to create processor objects.
     */
    public function createChild(string $class): EDIPartner
    {
        if (!class_exists($class)) {
            throw new Exception("Class {$class} does not exist.");
        }

        if ($this instanceof $class || !is_subclass_of($class, get_class())) {
            return $this;
        }

        return new $class($this->db, get_object_vars($this));
    }

    public static function getPartnerByCode(PDODB $db, string $code): EDIPartner
    {
        $query = <<<SQL
            SELECT *
            FROM edi_partners
            WHERE deleted_at IS NULL
              AND headers->>'receiver_code' = :receiver_code
        SQL;

        $binds = [
            'receiver_code' => $code,
        ];

        $record = $db->getRecord($query, $binds);

        if (!empty($record) && is_array($record)) {
            return new EDIPartner($db, $record);
        }

        return new EDIPartner($db);
    }

    /**
     * Return an array of all partner objects
     */
    public static function all(PDODB $db): array
    {
        $query = <<<SQL
            SELECT * FROM edi_partners WHERE deleted_at IS NULL ORDER BY partner
        SQL;
        $results = $db->fetchAll($query);
        if (!is_array($results)) {
            $results = [];
        }

        foreach ($results as $i => $result) {
            $results[$i] = new EDIPartner($db, $result);
        }

        return $results;
    }

    /**
     * Return an array of all partners with additional information joined.
     */
    public static function allExtended(PDODB $db, $interval = '1 month'): array
    {
        $intervalEscaped = $db->quote($interval) . "::interval";
        $query = <<<SQL
            SELECT p.*,
                   p.headers->>'receiver_id' AS receiver_id,
                   (p.tender_responses->>'autoAccept')::boolean AS auto_accept,
                   fs.label,
                   q1.tender_in_count,
                   q2.update_out_count,
                   q3.invoice_out_count
            FROM edi_partners p
                LEFT JOIN file_servers fs ON p.file_server_id = fs.id
                LEFT JOIN (
                    SELECT partner_id,
                           count(*) AS tender_in_count
                    FROM edi_transactions_log
                    WHERE date >= 'now'::timestamp - $interval
                      AND type = 204
                    GROUP BY partner_id
                    ) q1 ON p.id = q1.partner_id
                LEFT JOIN (
                    SELECT partner_id,
                           count(*) AS update_out_count
                    FROM edi_transactions_log
                    WHERE date >= 'now'::timestamp - $interval
                      AND type = 214
                    GROUP BY partner_id
                    ) q2 ON p.id = q2.partner_id
                LEFT JOIN (
                    SELECT partner_id,
                           count(*) AS invoice_out_count
                    FROM edi_transactions_log
                    WHERE date >= 'now'::timestamp - $interval
                      AND type = 210
                    GROUP BY partner_id
                    ) q3 ON p.id = q3.partner_id
            WHERE deleted_at IS NULL\n
        SQL;
        $results = $db->fetchAll($query);
        if (!is_array($results)) {
            $results = [];
        }

        return $results;
    }

    /**
     * Returns an array of partners to be used as select input options.
     */
    public static function getOptions(PDODB $db): array
    {
        $partners = [];
        foreach (EDIPartner::all($db) as $partner) {
            $partners[$partner->getId()] = $partner->getName();
        }

        return $partners;
    }

    /**
     * Create and return an EDIProcessor or child object.
     */
    public function createProcessor(): EDIProcessor
    {
        $class = __NAMESPACE__ . '\\Processors\\' . ($this->class204 ?: 'EDIProcessor');
        return new $class($this->db, get_object_vars($this));
    }

    /**
     * Create and return an EDIGenerator997 or child object.
     */
    public function createAcknowledgmentGenerator(): EDIGenerator997
    {
        $class = __NAMESPACE__ . '\\Generators\\' . ($this->class997 ?: 'EDIGenerator997');
        return new $class($this->db, get_object_vars($this));
    }

    /**
     * Create and return an EDIGenerator990 or child object.
     */
    public function createTenderResponseGenerator(): EDIGenerator990
    {
        $class = __NAMESPACE__ . '\\Generators\\' . ($this->class990 ?: 'EDIGenerator990');
        return new $class($this->db, get_object_vars($this));
    }

    /**
     * Create and return an EDIGenerator214 or child object.
     */
    public function createStatusUpdateGenerator(): EDIGenerator214
    {
        $class = __NAMESPACE__ .  '\\Generators\\' . ($this->class214 ?: 'EDIGenerator214');
        return new $class($this->db, get_object_vars($this));
    }

    /**
     * Create and return an EDIGenerator210 or child object.
     */
    public function createBillingGenerator(): EDIGenerator210
    {
        $class = __NAMESPACE__ . '\\Generators\\' . ($this->class210 ?: 'EDIGenerator210');
        return new $class($this->db, get_object_vars($this));
    }

    /**
     * Return the frequency in minutes to check for new incoming EDI from this partner.
     */
    public function frequencyToExchangeMessages()
    {
        return $this->exchange_frequency;
    }

    /**
     * Return the frequency in minutes to check for new incoming EDI from this partner.
     */
    public function frequencyOffset()
    {
        return $this->frequency_offset;
    }

    /**
     * Return an EDInterchange matching this partner's EDI header options.
     */
    public function createInterchange(int $typeId = null)
    {
        $properties = [];
        if ($typeId === 210) {
            if (!empty($this->billing['receiver_id_qualifier'])) {
                $properties['receiver_id_qualifier'] = $this->billing['receiver_id_qualifier'];
            }
            if (!empty($this->billing['receiver_id'])) {
                $properties['receiver_id'] = $this->billing['receiver_id'];
            }
        }
        return new EDInterchange(array_merge($this->headers, $properties));
    }

    /**
     * Whether or not a reason is required for declining a tender.
     */
    public function reasonRequired(): bool
    {
        return isset($this->tender_responses['sendReason']) && $this->tender_responses['sendReason'] == 2;
    }

    /**
     * Return an array of potential tender response reasons.
     */
    public function getTenderResponseReasons(): array
    {
        $segment = $this->tender_responses['reason']['segment'] ?? null;
        $index = $this->tender_responses['reason']['index'] ?? null;

        if (empty($segment) || empty($index)) {
            return [];
        }

        return EDICodes::getCodesForDataElement(EDISchemas::getDataElementId($segment, $index));
    }

    /**
     * Whether or not customer wants an EDI 214 for the specified EDI code
     */
    public function sendUpdate(string $code): bool
    {
        if (!isset($this->status_updates_out['codesToSend'])) {
            return false;
        }
        return in_array($code, explode(',', $this->status_updates_out['codesToSend']));
    }

    /**
     * Whether or not to send email notifications to users for this partner.
     */
    public function sendEmailNotifications()
    {
        return !empty($this->send_email_notifications);
    }

    public function getId()
    {
        return $this->id;
    }

    public function getName()
    {
        return $this->partner;
    }

    public function __get(string $property)
    {
        return $this->$property ?? null;
    }

    /**
     * Retrieve the specified parameter from the additional data field.
     */
    public function getAdditionalData(string $key)
    {
        return $this->additional_data[$key] ?? null;
    }


    public function save()
    {
        if (empty($this->id)) {
            $result = $this->insert();
            if ($result && is_numeric($result)) {
                $this->id = $result;
            }
        } else {
            $result = $this->update();
        }

        if (!$result) {
            throw new Exception("Error saving partner record.");
        }
    }

    protected function insert()
    {
        $query = <<<SQL
        INSERT INTO edi_partners
            (
             partner,
             file_server_id,
             inbound_filename,
             outbound_filename,
             outbound_filename_prefix,
             outbound_filename_postfix,
             headers,
             receiver_code,
             class204,
             class990,
             class214,
             class210,
             exchange_frequency,
             frequency_offset,
             tender_responses,
             tender_in_customers,
             status_updates_out,
             billing,
             billing_codes,
             additional_data,
             created_at,
             created_by,
             updated_at,
             updated_by,
             timezone,
             send_email_notifications,
             send_acknowledgments,
             load_requirements
            )
            VALUES (
             :partner,
             :file_server_id,
             :inbound_filename,
             :outbound_filename,
             :outbound_filename_prefix,
             :outbound_filename_postfix,
             :headers,
             :receiver_code,
             :class204,
             :class990,
             :class214,
             :class210,
             :exchange_frequency,
             :frequency_offset,
             :tender_responses,
             :tender_in_customers,
             :status_updates_out,
             :billing,
             :billing_codes,
             :additional_data,
             now(),
             :created_by,
             now(),
             :updated_by,
             :timezone,
             :send_email_notifications,
             :send_acknowledgments,
             :load_requirements
            );
        SQL;

        $data = $this->getBindValues();
        $data['created_by'] = USER_ID;
        return $this->db->insert($query, $data);
    }

    protected function update()
    {
        $query = <<<SQL
        UPDATE edi_partners
        SET
             partner = :partner,
             file_server_id = :file_server_id,
             inbound_filename = :inbound_filename,
             outbound_filename = :outbound_filename,
             outbound_filename_prefix = :outbound_filename_prefix,
             outbound_filename_postfix = :outbound_filename_postfix,
             headers = :headers,
             receiver_code = :receiver_code,
             class204 = :class204,
             class990 = :class990,
             class214 = :class214,
             class210 = :class210,
             exchange_frequency = :exchange_frequency,
             frequency_offset = :frequency_offset,
             tender_responses = :tender_responses,
             tender_in_customers = :tender_in_customers,
             status_updates_out = :status_updates_out,
             billing = :billing,
             billing_codes = :billing_codes,
             additional_data = :additional_data,
             updated_at = now(),
             updated_by = :updated_by,
             timezone = :timezone,
             send_email_notifications = :send_email_notifications,
             send_acknowledgments = :send_acknowledgments,
             load_requirements = :load_requirements
        WHERE id = :id
        SQL;

        $data = $this->getBindValues();
        $data['id'] = $this->id;
        return $this->db->update($query, $data);
    }

    /**
     * Return an array of this partner's values as an array to use for the insert and update queries.
     */
    protected function getBindValues(): array
    {
        $values = [
            'partner' => $this->partner,
            'file_server_id' => $this->file_server_id,
            'inbound_filename' => $this->inbound_filename,
            'outbound_filename' => $this->outbound_filename,
            'outbound_filename_prefix' => $this->outbound_filename_prefix,
            'outbound_filename_postfix' => $this->outbound_filename_postfix,
            'headers' => $this->headers,
            'receiver_code' => $this->receiver_code,
            'class204' => $this->class204,
            'class990' => $this->class990,
            'class214' => $this->class214,
            'class210' => $this->class210,
            'exchange_frequency' => $this->exchange_frequency,
            'frequency_offset' => $this->frequency_offset,
            'tender_responses' => $this->tender_responses,
            'tender_in_customers' => $this->tender_in_customers,
            'status_updates_out' => $this->status_updates_out,
            'billing' => $this->billing,
            'billing_codes' => $this->billing_codes,
            'additional_data' => $this->additional_data,
            'updated_by' => USER_ID,
            'timezone' => $this->timezone,
            'send_email_notifications' => $this->send_email_notifications,
            'send_acknowledgments' => $this->send_acknowledgments,
            'load_requirements' => $this->load_requirements,
        ];

        return array_map(fn($a) => is_array($a) ? json_encode($a) : $a, $values);
    }

    public function updateNotificationList(array $userIds)
    {
        if (empty($this->id)) {
            return;
        }

        $userIds = array_filter($userIds, fn ($a) => is_numeric($a));

        // First remove users
        $data = ['partner_id' => $this->id];
        $query = <<<SQL
            DELETE FROM edi_partner_user
            WHERE partner_id = :partner_id
            SQL;
        if (!empty($userIds)) {
            $binds = $this->db->bindMulti($userIds, $data);
            $query .= " AND user_id NOT IN ({$binds['placeholder']})";
            $data = $binds['values'];
        }
        $this->db->update($query, $data);

        $values = [];
        foreach ($userIds as $userId) {
            $values[] = [
                'user_id' => $userId,
                'partner_id' => $this->id,
            ];
        }
        $query = <<<SQL
            INSERT INTO edi_partner_user (user_id, partner_id) VALUES (:user_id, :partner_id) ON CONFLICT DO NOTHING;
        SQL;
        $this->db->insertMulti($query, $values);
    }

    /**
     * Return a list of users to notify regarding this partner.
     */
    public function getNotificationList(bool $onlyActiveUsers = false)
    {
        if (empty($this->id)) {
            return [];
        }

        $query = <<<SQL
            SELECT eu.*,
                   u."firstName",
                   u."lastName",
                   u.email
            FROM edi_partner_user eu
                LEFT JOIN "systemUser" u
                    ON u.id = eu.user_id
            WHERE partner_id = :partner_id
              AND u."rowStatus" = 1
            SQL;
        if ($onlyActiveUsers) {
            $query .= " AND disabled = 0 ";
        }
        $query .= ' ORDER BY u."lastName", u."firstName"';
        return $this->db->fetchAll($query, ['partner_id' => $this->id]);
    }

    /**
     * Whether or not to send a 990 response for expired tenders
     */
    public function sendResponseForExpired(bool $default = false): bool
    {
        return $this->tender_responses['respondToExpired'] ?? $default;
    }

    public function sendAcknowledgments(): int
    {
        return $this->send_acknowledgments;
    }

    public function usesMultipleGroupCodes()
    {
        return empty($this->receiver_code);
    }

    protected function setPartnerCodesToIds(): void
    {
        $query = <<<SQL
            SELECT headers->>'receiver_id' AS partner_code,
                   billing->>'receiver_id' AS billing_code,
                   id
            FROM edi_partners
            WHERE deleted_at IS NULL
        SQL;
        $records = $this->db->fetchAll($query);

        self::$partnerCodesToIds = [];
        if (!empty($records) && is_array($records)) {
            foreach ($records as $record) {
                self::$partnerCodesToIds[$record['partner_code']][] = $record['id'];
                if (!empty($record['billing_code'])) {
                    self::$partnerCodesToIds[$record['billing_code']][] = $record['id'];
                }
            }
        }
    }

    public function getPartnerCodesToIds(): array
    {
        if (empty(self::$partnerCodesToIds)) {
            $this->setPartnerCodesToIds();
        }

        return self::$partnerCodesToIds;
    }

    public function getPartnerIdsByPartnersCode(string $code): array
    {
        $ids = $this->getPartnerCodesToIds();

        return (array_key_exists($code, $ids)) ? $ids[$code] : [];
    }

    public function getWarnings(): array
    {
        return $this->warnings;
    }

    /**
     * Only used by old EDI invoicing process
     * Send EDI file to partner (and log).
     */
    public function sendDataFile(EDInterchange $interchange): void
    {
        if ($interchange->countTransactions() < 1) {
            throw new Exception('No transactions in interchange!');
        }

        $tmpFileName = $this->createTemporaryFile($interchange);

        $remote_filename = $this->getRemoteFilename($tmpFileName, $interchange);

        // We don't want to overwrite a file of the same name on the server,
        // however, if $tmpFileName is unique on our system as generated above,
        // there is not likely to be a conflict on the remote server either.

        $ftp = FTPFactory::build($this->file_server_id);
        if (!$ftp->connect() || !$ftp->uploadFile($tmpFileName, $remote_filename)) {
            throw new Exception($ftp->getErrorMsg());
        }

        // Functional Acknowledgements should already have been logged.
        if ($interchange->getGroups()[0]->getFunctionalIdCode() !== 'FA') {
            $this->edi_logger->log($interchange, $this->id, 'Sent', $tmpFileName);
        }
    }

    public function setPath(string $path): void
    {
        $this->path = $path;
    }

    /**
     * Converts the interchange object into a local file and returns the path & file name.
     */
    public function createTemporaryFile(EDInterchange $interchange): string
    {
        $path = $this->path;
        $folder = Utility::nonAlphaNumericReplace("EDI_{$this->partner}_{$interchange->getTypeCode()}");
        $path .= Utility::ensureTrailingSlash($this->tempFolder) . $folder;
        if (!is_dir($path)) {
            mkdir($path, 0755, true);
        }

        $tmpFile = "{$this->id}_{$interchange->getTypeCode()}_" . date('YmdHis') . '.edi';
        $tmpFile = Utility::ensureUniqueFilename($tmpFile, $path);
        $tmpFile = Utility::ensureTrailingSlash($path) . $tmpFile;

        if (!$fp = fopen($tmpFile, 'w')) {
            throw new Exception("Could not open file {$tmpFile} for writing.");
        }

        $ediText = (string)$interchange;
        $to_encoding = $this->getAdditionalData('encoding');
        if (!empty($to_encoding)) {
            $ediText = iconv('UTF-8', $to_encoding, $ediText);
        }

        if ($this->getAdditionalData('uppercaseValues')) {
            $ediText = strtoupper($ediText);
        }

        if (!fputs($fp, $ediText)) {
            fclose($fp);
            throw new Exception("Error writing to {$tmpFile}.");
        }

        fclose($fp);

        return $tmpFile;
    }

    /**
     * Determines what the name of the remote file should be.
     */
    public function getRemoteFilename(string $tmpFile, EDInterchange $interchange): string
    {
        $remote_filename = (!empty($this->outbound_filename)) ? $this->outbound_filename : basename($tmpFile);

        // Set prefix if not already correct
        if (!empty($this->outbound_filename_prefix)) {
            if (strpos($remote_filename, $this->outbound_filename_prefix) !== 0) {
                $remote_filename = "{$this->outbound_filename_prefix}{$remote_filename}";
            }
        }

        // Set postfix if not already correct
        if (!empty($this->outbound_filename_postfix)) {
            $strrpos = strrpos($remote_filename, $this->outbound_filename_postfix);
            if ($strrpos !== strlen($remote_filename) - strlen($this->outbound_filename_postfix)) {
                $remote_filename = "{$remote_filename}{$this->outbound_filename_postfix}";
            }
        }

        // Replace variables
        $type_code = $interchange->getTypeCode();
        $remote_filename = str_replace('{$type_code}', $type_code, $remote_filename);
        $remote_filename = str_replace('{$scac}', $this->scac, $remote_filename);
        $remote_filename = Utility::dateReplace($remote_filename);
        if (preg_match('/\{\$type_code(\{.+\})\}/', $remote_filename, $matches) && isset($matches[1])) {
            $array = json_decode($matches[1], true);
            if (array_key_exists($type_code, $array)) {
                $remote_filename = str_replace($matches[0], $array[$type_code], $remote_filename);
            }
        }

        return $remote_filename;
    }

    /**
     * Whether or not a response should be sent for an automatically accepted tender.
     */
    public function shouldSendAutoAcceptResponse(EDITransaction204 $transaction = null, bool $default = true): bool
    {
        return $this->getResponseOption('sendResponse', $transaction, $default);
    }

    /**
     * Search partner's tender_response settings for the specified value and return if found. Also parses conditions
     * and returns a value based on the transaction if applicable. Will return the $default if neither of the previous
     * produce a result.
     */
    protected function getResponseOption(string $key, EDITransaction204 $transaction = null, bool $default = true): bool
    {
        if (empty($this->tender_responses)) {
            return $default;
        }

        if (isset($this->tender_responses[$key])) {
            // Replace the default rather than returning the value so that, if applicable, the conditions below can
            // override.
            $default = $this->tender_responses[$key];
        }

        if (!isset($this->tender_responses['conditions'])
            || !is_iterable($this->tender_responses['conditions'])
            || !$transaction instanceof EDITransaction204
        ) {
            return $default;
        }

        foreach ($this->tender_responses['conditions'] as $condition) {
            $value = $transaction->getDataValue(
                $condition['segment'],
                $condition['element'],
                $condition['qualifierElement'] ?? null,
                $condition['qualifiers'] ?? null
            );
            if (isset($value) && $value == $condition['value']) {
                return $key == 'autoAccept' ? true : $condition['sendResponse'] ?? $default;
            }
        } // end loop for each condition

        return $default;
    }

    public function setTempFolderPath(string $path): void
    {
        $this->tempFolder = $path;
    }
}
