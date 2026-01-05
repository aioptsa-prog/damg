<?php

namespace App\Models;

class Classification
{
    private $id;
    private $name;
    private $categoryId;
    private $iconId;

    public function __construct($name, $categoryId, $iconId)
    {
        $this->name = $name;
        $this->categoryId = $categoryId;
        $this->iconId = $iconId;
    }

    public function getId()
    {
        return $this->id;
    }

    public function getName()
    {
        return $this->name;
    }

    public function getCategoryId()
    {
        return $this->categoryId;
    }

    public function getIconId()
    {
        return $this->iconId;
    }

    public function setId($id)
    {
        $this->id = $id;
    }

    public function setName($name)
    {
        $this->name = $name;
    }

    public function setCategoryId($categoryId)
    {
        $this->categoryId = $categoryId;
    }

    public function setIconId($iconId)
    {
        $this->iconId = $iconId;
    }
}