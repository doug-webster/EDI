<?php

namespace GZMP\Dependencies;

abstract class FTPAbstract
{
    protected PDODB $db;

    protected int $id = 0;
    protected string $label = '';
    protected string $protocol = '';
    protected string $host = '';
    protected string $port = '';
    protected string $user = '';
    protected string $pass = '';
    protected string $outbound_directory = '';
    protected string $inbound_directory = '';

    public function __construct(PDODB $db)
    {
        $this->db = $db;
    }

    public function getErrorMsg(): string
    {
        return $this->error;
    }

    public function clearError(): void
    {
        $this->error = '';
    }

    public function validate(): bool
    {
        $proceed = true;

        $required = array(
            'label' => 'Label',
            'host' => 'Host',
            'user' => 'Username',
            'pass' => 'Password',
        );
        foreach ($required as $field => $label) {
            if ($this->$field === '') {
                $this->logError("{$label} is required.\n");
                $proceed = false;
            }
        }

        if (!empty($this->port) && !is_numeric($this->port)) {
            $this->logError("Port must be numeric.\n");
            $proceed = false;
        }

        return $proceed;
    }

    public function save(): bool
    {
        $c = new cryptography();

        if ($this->pass !== '') {
            $this->pass = $c->crypt($this->pass);
        }

        $this->protocol = get_class($this);
        $data = [
            'label'              => $this->label,
            'protocol'           => $this->protocol,
            'host'               => $this->host,
            'port'               => $this->port,
            'user'               => $this->user,
            'pass'               => $this->pass,
            'outbound_directory' => $this->outbound_directory,
            'inbound_directory'  => $this->inbound_directory,
        ];
        if (empty($this->id)) {
            $query = <<<SQL
            INSERT INTO file_servers
                (
                    label,
                    protocol,
                    host,
                    port,
                    "user",
                    pass,
                    outbound_directory,
                    inbound_directory
                    ) VALUES (
                            :label,
                            :protocol,
                            :host,
                            :port,
                            :user,
                            :pass,
                            :outbound_directory,
                            :inbound_directory
                    )\n
            SQL;
            $id = $this->db->insert($query, $data);
            if ($id) {
                $this->id = $id;
            }
        } else {
            $query = <<<SQL
            UPDATE file_servers
            SET label = :label,
                protocol = :protocol,
                host = :host,
                port = :port,
                "user" = :user,
                pass = :pass,
                outbound_directory = :outbound_directory,
                inbound_directory = :inbound_directory
            WHERE id = :id\n
            SQL;
            $data['id'] = $this->id;
            $count = $this->db->update($query, $data);
        }

        if ($this->pass !== '') {
            $this->pass = $c->decrypt($this->pass);
        }

        return !empty($id) || !empty($count);
    }

    public function setFileLogPath(string $path): void
    {
        $this->fileLogPath = $path;
    }

    /**
     * Download all files matching the file name pattern (if applicable) from the remote server and return an array of
     * remote and local file names.
     */
    public function getFiles(string $filenameFormat = '', int $limit = 0): array
    {
        $path = $this->getFileLogPath(Utility::nonAlphaNumericReplace($this->label));

        $files = [];
        foreach ($this->getFilteredFileList($filenameFormat) as $remoteFile) {
            if (!$this->getVar('outbound_directory')
                || strpos($remoteFile, $this->getVar('outbound_directory')) === 0
            ) {
                $remoteFileWithPath = $remoteFile;
            } else {
                $remoteFileWithPath = Utility::ensureTrailingSlash($this->getVar('outbound_directory')) . $remoteFile;
            }

            $localFile = $path . date('YmdHis') . '_' . count($files) . ".edi";

            if (!$this->downloadFile($localFile, $remoteFileWithPath)) {
                continue;
            }

            $files[] = [
                'local' => $localFile,
                'remote' => $remoteFileWithPath,
            ];

            if ($limit > 0 && count($files) >= $limit) {
                break;
            }
        } // End loop for each file

        return $files;
    }

    /**
     * Whether or not to include the file name when the inbound filename (pattern) is set.
     */
    protected function filenameFilter(string $format, string $file, string $delimiter = '/'): bool
    {
        $pattern = str_replace($delimiter, "\{$delimiter}", $format);
        return preg_match("{$delimiter}{$pattern}{$delimiter}", $file);
    }

    /**
     * Return the path to the file logging directory.
     */
    protected function getFileLogPath(string $name): string
    {
        $path = Utility::ensureTrailingSlash($this->fileLogPath) . $name;

        if (!is_dir($path)) {
            mkdir($path, 0755, true);
        }

        return Utility::ensureTrailingSlash($path);
    }

    /**
     * Return an array of files on the remote server.
     */
    protected function getFilteredFileList(string $filenameFormat = ''): array
    {
        $remoteDir = trim($this->getVar('outbound_directory')) != '' ? $this->getVar('outbound_directory') : '.';

        //NOTE: THIS RETURNS THE FULL PATH TO THE FILE
        $fileList = $this->getFileList($remoteDir);
        if ($fileList === false || !is_array($fileList)) {
            $err_msg = $this->getErrorMsg();
            if ($err_msg) {
                $err_msg = "{$this->label}: $err_msg";
            } else {
                $err_msg = "Invalid \$filelist value for {$this->label}: " . var_export($fileList, true);
            }
            throw new Exception($err_msg);
        }

        if (empty($filenameFormat)) {
            return $fileList;
        }

        foreach ($fileList as $i => $filename) {
            if (!$this->filenameFilter($filenameFormat, $filename)) {
                array_splice($fileList, $i, 1);
            }
        }

        return $fileList;
    }

    protected function logError(string $error)
    {
        if (!empty($this->error)) {
            $this->error .= "\n";
        }
        $this->error .= $error;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    abstract public function connect();
    abstract public function getFileList($directory = '');
    abstract public function downloadFile($local_file, $remote_file);
    abstract public function uploadFile($file_list, $ftpFileName = '');
    abstract public function deleteFile($remote_file);
}
