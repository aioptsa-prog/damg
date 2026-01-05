<?php
require_once '../src/Services/TypeaheadService.php';

header('Content-Type: application/json');

// CSRF protection can be implemented here

$searchTerm = isset($_GET['query']) ? $_GET['query'] : '';

$typeaheadService = new TypeaheadService();
$suggestions = $typeaheadService->getSuggestions($searchTerm);

echo json_encode($suggestions);
?>