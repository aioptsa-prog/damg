<?php

namespace App\Controllers;

use App\Services\IconService;

class IconPickerController
{
    protected $iconService;

    public function __construct()
    {
        $this->iconService = new IconService();
    }

    public function getIcons()
    {
        $icons = $this->iconService->fetchAllIcons();
        return json_encode($icons);
    }

    public function uploadIcon($request)
    {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $file = $_FILES['icon'];
            $result = $this->iconService->uploadIcon($file);
            return json_encode($result);
        }
        return json_encode(['error' => 'Invalid request method']);
    }
}