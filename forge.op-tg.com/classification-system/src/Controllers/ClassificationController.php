<?php

namespace App\Controllers;

use App\Models\Category;
use App\Services\SeederService;

class ClassificationController
{
    protected $categoryModel;
    protected $seederService;

    public function __construct()
    {
        $this->categoryModel = new Category();
        $this->seederService = new SeederService();
    }

    public function index()
    {
        $categories = $this->categoryModel->getAll();
        // Return view with categories
    }

    public function create()
    {
        // Return view for creating a new category
    }

    public function store($data)
    {
        $this->categoryModel->create($data);
        // Redirect or return response
    }

    public function edit($id)
    {
        $category = $this->categoryModel->find($id);
        // Return view for editing the category
    }

    public function update($id, $data)
    {
        $this->categoryModel->update($id, $data);
        // Redirect or return response
    }

    public function destroy($id)
    {
        $this->categoryModel->delete($id);
        // Redirect or return response
    }

    public function seed()
    {
        $this->seederService->run();
        // Return response or redirect
    }
}