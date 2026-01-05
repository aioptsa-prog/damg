<?php
// icons.php

require_once '../src/Services/IconService.php';

header('Content-Type: application/json');

$iconService = new IconService();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $icons = $iconService->getAllIcons();
    echo json_encode($icons);
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $iconData = json_decode(file_get_contents('php://input'), true);
    $result = $iconService->uploadIcon($iconData);
    echo json_encode($result);
} else {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
}
?>