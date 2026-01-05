<?php

require_once '../src/Controllers/ClassificationController.php';

header('Content-Type: application/json');

$controller = new ClassificationController();

$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        if (isset($_GET['id'])) {
            $id = intval($_GET['id']);
            $response = $controller->getClassification($id);
        } else {
            $response = $controller->getAllClassifications();
        }
        break;

    case 'POST':
        $data = json_decode(file_get_contents('php://input'), true);
        $response = $controller->createClassification($data);
        break;

    case 'PUT':
        $id = intval($_GET['id']);
        $data = json_decode(file_get_contents('php://input'), true);
        $response = $controller->updateClassification($id, $data);
        break;

    case 'DELETE':
        $id = intval($_GET['id']);
        $response = $controller->deleteClassification($id);
        break;

    default:
        http_response_code(405);
        $response = ['message' => 'Method Not Allowed'];
        break;
}

echo json_encode($response);