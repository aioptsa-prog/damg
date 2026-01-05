<?php

namespace App\Services;

use App\Models\Icon;

class IconService
{
    public function uploadIcon($file)
    {
        // Logic to handle icon upload
        // Validate the file type and size
        // Move the uploaded file to the designated directory
    }

    public function getIcons()
    {
        // Logic to retrieve all icons from the database
        return Icon::all();
    }

    public function deleteIcon($iconId)
    {
        // Logic to delete an icon by its ID
        $icon = Icon::find($iconId);
        if ($icon) {
            // Remove the icon file from storage
            // Delete the icon record from the database
            $icon->delete();
        }
    }

    public function sanitizeIconName($name)
    {
        // Logic to sanitize the icon name before saving
        return preg_replace('/[^a-zA-Z0-9-_\.]/', '', $name);
    }
}