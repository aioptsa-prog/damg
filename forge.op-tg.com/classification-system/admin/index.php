<?php
session_start();

// Check if the user is logged in as an admin
if (!isset($_SESSION['admin_logged_in'])) {
    header('Location: login.php');
    exit();
}

// Include necessary files
require_once '../config/database.php';
require_once '../src/Controllers/ClassificationController.php';
require_once '../src/Controllers/TypeaheadController.php';
require_once '../src/Controllers/IconPickerController.php';

// Initialize controllers
$classificationController = new ClassificationController();
$typeaheadController = new TypeaheadController();
$iconPickerController = new IconPickerController();

// Fetch data for the dashboard
$categories = $classificationController->getAllCategories();
$icons = $iconPickerController->getAllIcons();

?>

<!DOCTYPE html>
<html lang="ar">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="../public/css/app.css">
    <title>لوحة التحكم</title>
</head>
<body>
    <header>
        <h1>لوحة التحكم</h1>
        <nav>
            <ul>
                <li><a href="manage.php">إدارة الفئات</a></li>
                <li><a href="seed.php">تغذية البيانات</a></li>
                <li><a href="logout.php">تسجيل الخروج</a></li>
            </ul>
        </nav>
    </header>
    <main>
        <section>
            <h2>الفئات</h2>
            <ul>
                <?php foreach ($categories as $category): ?>
                    <li><?php echo htmlspecialchars($category['name']); ?></li>
                <?php endforeach; ?>
            </ul>
        </section>
        <section>
            <h2>الرموز</h2>
            <ul>
                <?php foreach ($icons as $icon): ?>
                    <li><?php echo htmlspecialchars($icon['name']); ?></li>
                <?php endforeach; ?>
            </ul>
        </section>
    </main>
</body>
</html>