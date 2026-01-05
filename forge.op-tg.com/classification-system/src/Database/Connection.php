<?php

namespace Database;

use PDO;
use PDOException;

class Connection
{
    private $host;
    private $db;
    private $user;
    private $pass;
    private $charset;
    private $pdo;
    private $stmt;

    public function __construct()
    {
        $this->host = getenv('DB_HOST') ?: 'localhost';
        $this->db = getenv('DB_NAME') ?: 'classification_system';
        $this->user = getenv('DB_USER') ?: 'root';
        $this->pass = getenv('DB_PASS') ?: '';
        $this->charset = 'utf8mb4';

        $this->connect();
    }

    private function connect()
    {
        $dsn = "mysql:host={$this->host};dbname={$this->db};charset={$this->charset}";
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];

        try {
            $this->pdo = new PDO($dsn, $this->user, $this->pass, $options);
        } catch (PDOException $e) {
            throw new PDOException($e->getMessage(), (int)$e->getCode());
        }
    }

    public function query($sql, $params = [])
    {
        $this->stmt = $this->pdo->prepare($sql);
        $this->stmt->execute($params);
    }

    public function fetchAll()
    {
        return $this->stmt->fetchAll();
    }

    public function fetch()
    {
        return $this->stmt->fetch();
    }

    public function lastInsertId()
    {
        return $this->pdo->lastInsertId();
    }
}