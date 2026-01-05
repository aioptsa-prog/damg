<?php
require_once '../src/Database/Connection.php';
require_once '../src/Controllers/ClassificationController.php';
require_once '../src/Controllers/TypeaheadController.php';
require_once '../src/Controllers/IconPickerController.php';

// Initialize database connection
$connection = new Connection();
$db = $connection->connect();

// Route handling
$requestUri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$requestMethod = $_SERVER['REQUEST_METHOD'];

switch ($requestUri) {
    case '/classifications':
        $controller = new ClassificationController($db);
        if ($requestMethod === 'GET') {
            $controller->index();
        } elseif ($requestMethod === 'POST') {
            $controller->store();
        }
        break;

    case '/typeahead':
        $controller = new TypeaheadController($db);
        if ($requestMethod === 'GET') {
            $controller->suggest();
        }
        break;

    case '/icons':
        $controller = new IconPickerController($db);
        if ($requestMethod === 'POST') {
            $controller->upload();
        }
        break;

    default:
        http_response_code(404);
        echo json_encode(['message' => 'Not Found']);
        break;
}
?>