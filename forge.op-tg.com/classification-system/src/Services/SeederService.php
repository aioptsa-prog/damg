<?php

namespace App\Services;

use App\Database\Connection;

class SeederService
{
    protected $db;

    public function __construct()
    {
        $this->db = new Connection();
    }

    public function dryRun()
    {
        // Logic for dry run of seeding
        // This will simulate the seeding process without making actual changes to the database
    }

    public function seed()
    {
        // Logic for actual seeding of data
        // This will insert initial data into the database
    }

    public function rollback()
    {
        // Logic for rolling back the last seeding operation
        // This will remove the last inserted data from the database
    }
}