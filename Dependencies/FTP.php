<?php

namespace GZMP\Dependencies;

class FTP extends FTPAbstract
{
    protected $port = 21;
    protected $conn;
    protected $active_mode = false;
    protected $transfer_mode = FTP_BINARY;

    public function __construct($passed = array())
    {
        parent::__construct($passed);
    }

    /**
     * connect attempts to connect to an FTP server
     *
     * @return bool
     */
    public function connect()
    {
        //make sure fields are set
        if (empty($this->host) || empty($this->user) || empty($this->pass)) {
            $this->logError('Missing FTP information.');
            return false;
        }

        //ensure that the connection was established
        if (($this->conn = ftp_connect($this->host, $this->port)) === false) {
            $this->logError("Could not connect to {$this->host} via FTP.");
            return false;
        }

        //ensure that login was successful
        if (!ftp_login($this->conn, $this->user, $this->pass)) {
            $this->logError('Could not login to FTP server.');
            return false;
        }

        if (!$this->active_mode) {
            return $this->setPassive();
        }

        return true;
    }

    /**
     *
     */
    public function setPassive()
    {
        if ($this->error) {
            return false;
        }

        return ftp_pasv($this->conn, true);
    }

    /**
     * @param string $directory
     * @return bool|array
     */
    public function getFileList($directory = '')
    {
        if ($this->error) {
            return false;
        }

        if (!isset($directory)) {
            $directory = $this->outbound_directory;
        }

        return ftp_nlist($this->conn, $directory);
    }

    /**
     * @param $local_file
     * @param $remote_file
     * @return bool
     */
    public function downloadFile($local_file, $remote_file)
    {
        if ($this->error) {
            return false;
        }

        if (!@ftp_get($this->conn, $local_file, $remote_file, $this->transfer_mode)) {
            $this->logError("Couldn't download {$remote_file} from {$this->label} to {$local_file}");
            return false;
        }

        return true;
    }

    /**
     * FTP a file to an FTP Server
     *
     * @param string - The local location of the file to FTP
     * @param string $remote_file - The destination filename
     * @return bool
     */
    public function uploadFile($local_file, $remote_file = '')
    {
        if ($this->error) {
            return false;
        }

        if (!$fp = fopen($local_file, 'r')) {
            $this->logError("Could not open {$local_file} for reading.");
            return false;
        }

        // if the remote filename isn't set, default to the same as the temp/input file name
        if (empty($remote_file)) {
            $remote_file = basename($local_file);
        }

        // add remote folder if specified
        if (!empty($this->inbound_directory)) {
            $remote_file = "{$this->inbound_directory}/{$remote_file}";
        }

        $result = ftp_fput($this->conn, $remote_file, $fp, $this->transfer_mode);
        fclose($fp);
        if (!$result) {
            $this->logError("Could not FTP {$local_file} to ftp://{$this->host}/{$remote_file}.");
            return false;
        }

        return true;
    }

    public function deleteFile($remote_file)
    {
        if ($this->error) {
            return false;
        }

        return ftp_delete($this->conn, $remote_file);
    }

    public function __destruct()
    {
        if ($this->conn) {
            ftp_close($this->conn);
        }
    }
}
