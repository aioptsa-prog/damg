<?php

namespace App\Controllers;

use App\Services\TypeaheadService;

class TypeaheadController
{
    protected $typeaheadService;

    public function __construct()
    {
        $this->typeaheadService = new TypeaheadService();
    }

    public function getSuggestions($query)
    {
        $suggestions = $this->typeaheadService->fetchSuggestions($query);
        return json_encode($suggestions);
    }
}