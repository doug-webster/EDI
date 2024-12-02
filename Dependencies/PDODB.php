<?php

namespace GZMP\Dependencies;

class PDODB
{
    public ?PDO $dbh = null; // PDO handler
    private $host;
    private $port;
    private $database;
    private $pdo_driver;
    private $charset = 'utf8mb4';
    private $connection_string = '';
    private $connection_options = array();

    // connecting -------------------------------------------------------------
    public function __construct($host_or_cs = null, $username = null, $password = null, $database = null, $port = null, $pdo_driver = 'mysql', $charset = null, $connection_options = null)
    {
        if (!empty($host_or_cs)) {
            // attempt to determine PDO driver in order to know if first parameter is a connection string or host
            $potential_pdo_driver = substr($host_or_cs, 0, strpos($host_or_cs, ':'));
            if (self::isValidPDODriver($potential_pdo_driver)) {
                $this->setPDODriver($potential_pdo_driver);
                $this->setConnectionString($host_or_cs);
            } else {
                $this->setPDODriver($pdo_driver);
                $this->setHost($host_or_cs);
                $this->setPort($port);
                $this->setDatabase($database);
                $this->setConnectionString(null, $username, $password);
                $this->setConnectionOptions($connection_options);
                if (!empty($charset)) {
                    $this->setCharSet($charset);
                }
            }
            $this->connect($username, $password);
        }
    }

    // returns true if the parameter is a valid PDO driver, false otherwise
    public static function isValidPDODriver($pdo_driver)
    {
        $pdo_drivers = array(
            'mysql',
            'cubrid',
            'sybase',
            'mssql',
            'dblib',
            'firebird',
            'ibm',
            'informix',
            'oci',
            'odbc',
            'pgsql',
            'sqlite',
            'sqlite2',
            'sqlsrv',
            '4D',
        );
        return in_array($pdo_driver, $pdo_drivers);
    }

    // connect to the database
    public function connect($username = null, $password = null)
    {
        $cs = $this->getConnectionString();
        try {
            $this->dbh = new PDO($cs, $username, $password, $this->connection_options);
            //$this->dbh->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->dbh->query("SET NAMES " . $this->dbh->quote($this->charset));
        } catch (PDOException $e) {
            trigger_error("PDODB database connection failed: {$e->getMessage()}", E_USER_WARNING);
        }
    }

    // set connection string manually to the specified string, or sets it based on pdo_driver and parameters
    public function setConnectionString($cs = null, $username = null, $password = null)
    {
        if (!empty($cs)) {
            if (strpos($cs, $this->pdo_driver) !== 0) {
                $cs = "{$this->pdo_driver}:{$cs}";
            }
            $this->connection_string = $cs;
            return;
        }

        $cs = "{$this->pdo_driver}:";
        switch ($this->pdo_driver) {
            case 'mysql':
                if (!empty($this->port)) {
                    $cs .= "host={$this->host};port={$this->port};dbname={$this->database};charset={$this->charset}";
                } else {
                    $cs .= "host={$this->host};dbname={$this->database};charset={$this->charset}";
                }
                break;
            case 'cubrid':
                if (!empty($this->port)) {
                    $cs .= "host={$this->host};dbname={$this->database}";
                } else {
                    $cs .= "host={$this->host};port={$this->port};dbname={$this->database}";
                }
                break;
            case 'sybase':
            case 'mssql':
            case 'dblib':
                $cs .= "host={$this->host};dbname={$this->database}";
                break;
            case 'firebird':
                if (!empty($this->port)) {
                    $cs .= "dbname={$this->host}/{$this->port}:{$this->database}";
                } else {
                    $cs .= "dbname={$this->host}:{$this->database}";
                }
                break;
            case 'ibm':
                $cs .= "Driver={IBM DB2 ODBC DRIVER};DATABASE={$this->database};HOSTNAME={$this->host};PORT={$this->port};PROTOCOL=TCPIP;UID={$username};PWD={$password};";
                break;
            case 'informix':
                //$cs .= "host={$this->host}; service=9800; database={$this->database}; server=ids_server; protocol=onsoctcp; EnableScrollableCursors=1";
                break;
            case 'oci':
                if (!empty($this->host)) {
                    if (!empty($this->port)) {
                        $cs .= "dbname=//{$this->host}:{$this->port}/{$this->database}";
                    } else {
                        $cs .= "dbname=//{$this->host}/{$this->database}";
                    }
                } else {
                    $cs .= "dbname={$this->database}";
                }
                break;
            case 'odbc':
                $cs .= "{$this->database}";
                //$cs .= "Driver={SQL Server Native Client 11.0};Server={$this->host};Database={$this->database};UID={$username};PWD={$password};";
                break;
            case 'pgsql':
                if (!empty($this->port)) {
                    $cs .= "host={$this->host};port={$this->port};dbname={$this->database};user={$username};password={$password}";
                } else {
                    $cs .= "host={$this->host};dbname={$this->database};user={$username};password={$password}";
                }
                break;
            case 'sqlite':
            case 'sqlite2':
                $cs .= "{$this->database}";
                break;
            case 'sqlsrv':
                if (!empty($this->port)) {
                    $cs .= "Server={$this->host},{$this->port};Database={$this->database}";
                } else {
                    $cs .= "Server={$this->host};Database={$this->database}";
                }
                break;
            case '4D':
                if (!empty($this->port)) {
                    $cs .= "host={$this->host};port={$this->port};user={$username};password={$password};charset={$this->charset}";
                } else {
                    $cs .= "host={$this->host};user={$username};password={$password};charset={$this->charset}";
                }
                break;
        }
        $this->connection_string = $cs;
    }

    public function setConnectionOptions($options)
    {
        if (!is_array($options)) {
            return;
        }
        $this->connection_options = $options;
    }

    // returns the connection string
    public function getConnectionString()
    {
        return $this->connection_string;
    }

    public function setHost($host)
    {
        $this->host = $host;
    }

    public function setPort($port)
    {
        $this->port = $port;
    }

    public function setDatabase($database)
    {
        $this->database = $database;
    }

    // change the database type to the specified PDO pdo_driver
    public function setPDODriver($pdo_driver)
    {
        if (self::isValidPDODriver($pdo_driver)) {
            $this->pdo_driver = $pdo_driver;
        }
    }

    public function setCharSet($charset)
    {
        $this->charset = $charset;
    }

    // transaction methods ----------------------------------------------------
    public function inTransaction():bool
    {
        return $this->dbh->inTransaction();
    }

    public function beginTransaction():bool
    {
        return $this->dbh->beginTransaction();
    }

    public function commit():bool
    {
        return $this->dbh->commit();
    }

    // query methods ----------------------------------------------------------
    // runs prepared statement and returns insertID
    public function insert($query, $binds = array(), $id_index = null)
    {
        if (!$sth = $this->prepAndRunQuery($query, $binds)) {
            return $sth;
        }
        return ($this->dbh instanceof PDO) ? $this->dbh->lastInsertId($id_index) : false;
    }

    /**
     * @param string $query the SQL query
     * @param array $values an array of bind value arrays: array(0 => array('bind1' => 'value1', ...), ...)
     * @param null $id_index
     * @return array|bool returns an array of inserted ids on success or false on failure
     * @throws Exception
     */
    public function insertMulti($query, $values = array(), $id_index = null)
    {
        if (!is_array($values) || !($this->dbh instanceof PDO)) {
            return false;
        }
        if (!$sth = $this->dbh->prepare($query)) {
            return $this->handleError($this->dbh, $query, $values);
        }

        $ids = array();
        foreach ($values as $binds) {
            $err = $this->bindValues($sth, $binds);
            if (!empty($err)) {
                return $this->handleError($sth, "{$query} {$err}", $binds);
            }
            if (!$sth->execute()) {
                return $this->handleError($sth, $query, $binds);
            }
            if ($id_index && $this->dbh instanceof PDO) {
                $ids[] = $this->dbh->lastInsertId($id_index);
            }
        }

        return $ids;
    }

    // runs prepared statement and returns number of affected rows
    public function update($query, $binds = array())
    {
        if (!$sth = $this->prepAndRunQuery($query, $binds)) {
            return $sth;
        }
        return $sth->rowCount();
    }

    // returns an array of rows from a query result
    // $key selects a field to use as the index of the returned array
    public function fetchAll($query, $binds = array(), $key = null, $fetch_style = PDO::FETCH_ASSOC)
    {
        if (!$sth = $this->prepAndRunQuery($query, $binds)) {
            return $sth;
        }

        $records = array();
        while ($row = $sth->fetch($fetch_style)) {
            if (!empty($key)) {
                $records[$row[$key]] = $row;
            } else {
                $records[] = $row;
            }
        }
        return $records;
    }

    // returns an array of data including query result from a query with LIMIT (paged)
    /* page - current page number
        per_page - number of records to show per page
        total - total number of records
        ftotal - total formatted for display
        pages - total number of pages
        fpages - pages formatted for display
        limit -
        start - first record on a page
        end - last record on a page
        prev - true if there is a prior page
        next - true if there is a following page
        next_page - page number of next page
        prev_page - page number of previous page
    */
    public function queryWithPagination($query, $binds = array(), $page = 1, $per_page = 10, $key = null, $fetch_style = PDO::FETCH_ASSOC)
    {
        $result = array();

        if (!$sth = $this->prepAndRunQuery($query, $binds)) {
            return $sth;
        }

        // Get total number of results
        $result['total'] = $sth->rowCount();

        // Calculate pagination
        $result['pages'] = ceil($result['total'] / $per_page); // number of pages
        $result['page'] = min(max($page, 1), $result['pages']); // make the current page number in range
        $result['limit'] = max(($result['page'] - 1) * $per_page, 0); // first result for LIMIT in query; = start - 1
        $result['start'] = max(($result['page'] - 1) * $per_page + 1, 0); // first result for page
        $result['end'] = min($result['start'] - 1 + $per_page, $result['total']); // last result for page
        $result['prev'] = ($result['page'] > 1); // is there a previous page?
        $result['next'] = ($result['end'] < $result['total']); // is there a next page?

        if ($result['next']) {
            $result['next_page'] = $result['page'] + 1;
        }

        if ($result['prev']) {
            $result['prev_page'] = $result['page'] - 1;
        }

        $records = array();
        $i = 0;
        while ($row = $sth->fetch($fetch_style)) {
            $i++;
            if ($i < $result['start']) {
                continue;
            }
            if ($i > $result['end']) {
                break;
            }
            if (!empty($key) && isset($records[$row[$key]])) {
                $records[$row[$key]] = $row;
            } else {
                $records[] = $row;
            }
        }
        $result['records'] = $records;

        // Format
        $result['fpages'] = number_format($result['pages']);
        $result['start']  = number_format($result['start']);
        $result['end']    = number_format($result['end']);
        $result['ftotal'] = number_format($result['total']);

        return $result;
    }

    // get a single row from a query (first row if more than one record in the results)
    public function getRecord($query, $binds = array(), $fetch_style = PDO::FETCH_ASSOC)
    {
        if (!$sth = $this->prepAndRunQuery($query, $binds)) {
            return $sth;
        }
        return $sth->fetch($fetch_style);
    }

    // returns a single value from the database (the first value from first row of a query if more than one rows and/or columns in results)
    public function getValue($query, $binds = array())
    {
        if (!$sth = $this->prepAndRunQuery($query, $binds)) {
            return $sth;
        }
        if ($row = $sth->fetch(PDO::FETCH_NUM)) {
            return $row[0];
        }
    }

    // returns an array of data from one column (first column in results if more than one column)
    public function getColumn($query, $binds = array(), $key = null)
    {
        if (!$sth = $this->prepAndRunQuery($query, $binds)) {
            return $sth;
        }

        $records = array();
        while ($row = $sth->fetch(PDO::FETCH_BOTH)) {
            if ($key) {
                $records[$row[$key]] = $row[0];
            } else {
                $records[] = $row[0];
            }
        }
        return $records;
    }

    // Utility methods --------------------------------------------------------
    public function quote($string): string
    {
        return $this->dbh->quote($string);
    }

    // prepares and executes a query, returning a PDOStatement handler object on success
    public function prepAndRunQuery($query, $binds = array())
    {
        if ($query instanceof PDOStatement) {
            $sth = $query;
        } else {
            if (!($this->dbh instanceof PDO)) {
                return false;
            }
            if (!$sth = $this->dbh->prepare($query)) {
                return $this->handleError($this->dbh, $query, $binds);
            }
        }
        $err = $this->bindValues($sth, $binds);
        if (!empty($err)) {
            return $this->handleError($sth, "{$sth->queryString} {$err}", $binds);
        }
        if (!$sth->execute()) {
            return $this->handleError($sth, $sth->queryString, $binds);
        }
        return $sth;
    }

    public function prepare($query)
    {
        if (!$sth = $this->dbh->prepare($query)) {
            return $this->handleError($this->dbh, $query);
        }
        return $sth;
    }

    /*
     * This function was added because execute assumes all bind values are to be treated as PDO::PARAM_STR.
     * This apparently works for all input types except booleans
     * */
    public function bindValues($sth, $binds)
    {
        if (empty($binds) || !is_array($binds)) {
            return;
        }
        foreach ($binds as $parameter => $value) {
            switch (gettype($value)) {
                case 'boolean':
                    $data_type = PDO::PARAM_BOOL;
                    $data_type_text = 'boolean';
                    break;
                case 'integer':
                    $data_type = PDO::PARAM_INT;
                    $data_type_text = 'integer';
                    break;
                case 'NULL':
                    $data_type = PDO::PARAM_NULL;
                    $data_type_text = 'null';
                    break;
                case 'string':
                case 'double': // there is apparently no PDO constant for floating point numbers
                    // the following three types may be returned by typeof but have no SQL equivalent type
                case 'array':
                case 'object':
                case 'resource':
                default:
                    $data_type = PDO::PARAM_STR;
                    $data_type_text = 'string';
                    break;
            }
            if (!$sth->bindValue($parameter, $value, $data_type)) {
                $err_msg = "Error binding {$parameter} as {$data_type_text} with value = " . var_export($value, true);
                $this->handleError($sth, $err_msg, $binds);
            }
        }
        return null;
    }

    /**
     * Allows for binding of a variable number of values; particularly useful for "IN ()" clauses
     * @param array $values
     * @param array $binds
     * @return array Returns an array with two keys: placeholder (text) and values (array)
     */
    public function bindMulti($values, $binds = array())
    {
        $bind_placeholders = array();
        $i = 0;
        if (is_array($values)) {
            foreach ($values as $value) {
                do {
                    $i++;
                    $key = "binds$i";
                } while (array_key_exists($key, $binds) && $i < 9999); // The second condition prevents an infinite loop
                $bind_placeholders[$key] = ":$key";
                $binds[$key] = $value;
            }
        }

        return array(
            'placeholder' => implode(', ', $bind_placeholders),
            'values' => $binds,
        );
    }

    /**
     * Allows for binding of a variable number of values; particularly useful for "IN ()" clauses
     */
    public function bindMultiNew(array $values, array &$binds = []): string
    {
        $bind_placeholders = [];
        $i = 0;
        if (is_array($values)) {
            foreach ($values as $value) {
                do {
                    $i++;
                    $key = "binds$i";
                } while (array_key_exists($key, $binds) && $i < 9999); // The second condition prevents an infinite loop
                $bind_placeholders[$key] = ":$key";
                $binds[$key] = $value;
            }
        }

        return implode(', ', $bind_placeholders);
    }

    // handles errors
    public function handleError($obj, $query = '', $binds = array())
    {
        if ($this->dbh->inTransaction()) {
            $this->dbh->rollBack();
        }

        $log_msg = print_r($query, true) . "\n\n";
        $log_msg .= var_export($binds, true);
        $error = $obj->errorInfo();
        if (!empty($error[2])) {
            throw new Exception("PDODB: {$error[2]}\n\n{$log_msg}");
        }
        if (defined('DEVELOPER') && DEVELOPER) {
            echo '<pre>' . htmlspecialchars($log_msg) . '</pre>';
        }
        return false;
    }
}
