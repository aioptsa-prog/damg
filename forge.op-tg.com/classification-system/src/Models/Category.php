<?php

namespace App\Models;

class Category
{
    private $id;
    private $name;
    private $icon;

    public function __construct($name, $icon = null)
    {
        $this->name = $name;
        $this->icon = $icon;
    }

    public function getId()
    {
        return $this->id;
    }

    public function getName()
    {
        return $this->name;
    }

    public function getIcon()
    {
        return $this->icon;
    }

    public function setId($id)
    {
        $this->id = $id;
    }

    public function setName($name)
    {
        $this->name = $name;
    }

    public function setIcon($icon)
    {
        $this->icon = $icon;
    }

    public function save()
    {
        // Logic to save the category to the database
    }

    public static function all()
    {
        // Logic to retrieve all categories from the database
    }

    public static function find($id)
    {
        // Logic to find a category by its ID
    }

    public function delete()
    {
        // Logic to delete the category from the database
    }
}