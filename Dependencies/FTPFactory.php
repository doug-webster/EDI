<?php

namespace GZMP\Dependencies;

class FTPFactory
{
    /**
     * @param int|string $file_server_id
     * @return FTP|SFTP
     * @throws Exception
     */
    public static function build($file_server_id)
    {
        $db = new database();
        if (is_numeric($file_server_id)) {
            $query = "SELECT * FROM file_servers WHERE id = :id";
            $binds = array('id' => $file_server_id);
        } elseif (!empty($file_server_id) && is_string($file_server_id)) {
            $query = "SELECT * FROM file_servers WHERE label = :label";
            $binds = array('label' => $file_server_id);
        }
        if (!empty($query) && !empty($binds)) {
            $record = $db->callMethod('getRecord', $query, $binds);
        }
        if (empty($record) || !is_array($record)) {
            return new FTP();
        }

        $crypt = new cryptography();
        if (!empty($record['pass'])) {
            $record['pass'] = $crypt->decrypt($record['pass']);
        }

        if (!empty($record['protocol']) && class_exists($record['protocol'])) {
            return new $record['protocol']($record);
        } else {
            return new FTP($record);
        }
    }
}
