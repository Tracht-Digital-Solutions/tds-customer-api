<?php
declare(strict_types=1);

namespace Tds\CustomerApi\Infrastructure;

use PDO;

final class Database
{
    /** @param array{host:string,port:string,name:string,user:string,pass:string} $cfg */
    public static function connect(array $cfg): PDO
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            $cfg['host'], $cfg['port'], $cfg['name']
        );
        return new PDO($dsn, $cfg['user'], $cfg['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }
}
