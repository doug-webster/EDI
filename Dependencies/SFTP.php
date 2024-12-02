<?php

namespace GZMP\Dependencies;

class SFTP extends FTPAbstract
{
    protected $port = 22;
    protected $sftp;

    public function __construct($passed = array())
    {
        parent::__construct($passed);
        define('NET_SFTP_LOGGING', phpseclib\Net\SSH2::LOG_COMPLEX);
    }

    /**
     * connect attempts to connect to an SFTP server
     *
     * @return bool
     */
    public function connect()
    {
        $this->sftp = new phpseclib\Net\SFTP($this->host, $this->port);
        $return = $this->sftp->login($this->user, $this->pass);
        if (!$return) {
            $this->logError("SFTP connection error: {$this->sftp->getLastSFTPError()}");
        }
        return $return;
    }

    public function changeDirectory($directory)
    {
        $return = $this->sftp->chdir($directory);
        if (!$return) {
            $this->logError($this->sftp->getLastSFTPError());
        }
        return $return;
    }

    protected function handleFilenameWithDirectory($file)
    {
        // Parse the filename into path & filename
        $filename = basename($file);
        $path = rtrim(substr($file, 0, strlen($file) - strlen($filename)), '\\/');
        if (empty($path)) {
            return $filename;
        }

        // Check to see if we're already in the correct directory; if not, change to that directory
        $current_dir = $this->sftp->pwd();
        $preg_path = preg_quote($path, '/');
        if (empty($current_dir) || !preg_match("/{$preg_path}\/?$/", $current_dir)) {
            $this->changeDirectory($path);
        }

        return $filename;
    }

    /**
     * @param string|null $directory
     * @return bool|mixed
     */
    public function getFileList($directory = null)
    {
        if (!isset($directory)) {
            $directory = $this->outbound_directory;
        }

        $list = $this->sftp->nlist($directory);
        if (empty($list) || !is_array($list)) {
            return array();
        }

        $allowed_types = [
            NET_SFTP_TYPE_REGULAR, // regular file
            NET_SFTP_TYPE_SYMLINK, // symbolic link
        ];

        foreach ($list as $i => $item) {
            $list[$i] = "$directory/$item";
            $stat = $this->sftp->stat($list[$i]);
            // Filter out directories, etc. - we only want files
            if (!in_array($stat['type'], $allowed_types)) {
                unset($list[$i]);
            }
        }

        return $list;
    }

    /**
     * @param $local_file
     * @param $remote_file
     * @return bool
     */
    public function downloadFile($local_file, $remote_file)
    {
        $remote_file = $this->handleFilenameWithDirectory($remote_file);
        if (!$this->sftp->get($remote_file, $local_file)) {
            $this->logError("Couldn't download {$remote_file} from {$this->label} to {$local_file}: {$this->sftp->getLastSFTPError()}");
            return false;
        }
        return true;
    }

    /**
     * FTP a file to an SFTP Server
     *
     * @param string $local_file - The local location of the file to FTP
     * @param string $remote_file - The destination filename
     * @return bool
     */
    public function uploadFile($local_file, $remote_file = '')
    {
        // if the remote filename isn't set, default to the same as the temp/input file name
        if (empty($remote_file)) {
            $remote_file = basename($local_file);
        }

        if (!empty($this->inbound_directory)) {
            $remote_file = "{$this->inbound_directory}/{$remote_file}";
        }

        $return = $this->sftp->put($remote_file, $local_file, phpseclib\Net\SFTP::SOURCE_LOCAL_FILE);
        if (!$return) {
            $this->logError($this->sftp->getLastSFTPError());
        }
        return $return;
    }

    public function deleteFile($remote_file)
    {
        $remote_file = $this->handleFilenameWithDirectory($remote_file);
        $return = $this->sftp->delete($remote_file);
        if (!$return) {
            $this->logError($this->sftp->getLastSFTPError());
        }
        return $return;
    }

    public function __destruct()
    {
        if ($this->sftp instanceof phpseclib\Net\SFTP) {
            Logger::debug($this->sftp->getSFTPLog());
            $this->sftp = null;
        }
    }
}
