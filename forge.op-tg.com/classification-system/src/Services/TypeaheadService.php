<?php

namespace App\Services;

use App\Models\Category;

class TypeaheadService
{
    protected $categories;

    public function __construct()
    {
        $this->categories = new Category();
    }

    public function getSuggestions($query)
    {
        if (empty($query)) {
            return [];
        }

        return $this->categories->search($query);
    }
}