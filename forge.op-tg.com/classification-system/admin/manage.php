<?php
require_once '../src/Controllers/ClassificationController.php';
require_once '../src/Controllers/TypeaheadController.php';
require_once '../src/Controllers/IconPickerController.php';

$classificationController = new ClassificationController();
$typeaheadController = new TypeaheadController();
$iconPickerController = new IconPickerController();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Handle form submissions for managing categories, keywords, and icons
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'add_category':
                $classificationController->addCategory($_POST['category_name']);
                break;
            case 'update_category':
                $classificationController->updateCategory($_POST['category_id'], $_POST['category_name']);
                break;
            case 'delete_category':
                $classificationController->deleteCategory($_POST['category_id']);
                break;
            case 'upload_icon':
                $iconPickerController->uploadIcon($_FILES['icon']);
                break;
        }
    }
}

$categories = $classificationController->getAllCategories();
$icons = $iconPickerController->getAllIcons();

?>

<!DOCTYPE html>
<html lang="ar">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="../public/css/app.css">
    <title>إدارة التصنيفات</title>
</head>
<body>
    <h1>إدارة التصنيفات والكلمات الرئيسية والرموز</h1>

    <h2>إضافة تصنيف جديد</h2>
    <form method="POST">
        <input type="text" name="category_name" placeholder="اسم التصنيف" required>
        <input type="hidden" name="action" value="add_category">
        <button type="submit">إضافة</button>
    </form>

    <h2>التصنيفات الحالية</h2>
    <ul>
        <?php foreach ($categories as $category): ?>
            <li>
                <?php echo htmlspecialchars($category['name']); ?>
                <form method="POST" style="display:inline;">
                    <input type="hidden" name="category_id" value="<?php echo $category['id']; ?>">
                    <input type="text" name="category_name" placeholder="تحديث الاسم" required>
                    <input type="hidden" name="action" value="update_category">
                    <button type="submit">تحديث</button>
                </form>
                <form method="POST" style="display:inline;">
                    <input type="hidden" name="category_id" value="<?php echo $category['id']; ?>">
                    <input type="hidden" name="action" value="delete_category">
                    <button type="submit">حذف</button>
                </form>
            </li>
        <?php endforeach; ?>
    </ul>

    <h2>تحميل رمز جديد</h2>
    <form method="POST" enctype="multipart/form-data">
        <input type="file" name="icon" required>
        <input type="hidden" name="action" value="upload_icon">
        <button type="submit">تحميل</button>
    </form>

    <h2>الرموز الحالية</h2>
    <ul>
        <?php foreach ($icons as $icon): ?>
            <li>
                <img src="<?php echo htmlspecialchars($icon['path']); ?>" alt="<?php echo htmlspecialchars($icon['name']); ?>" width="50">
                <?php echo htmlspecialchars($icon['name']); ?>
            </li>
        <?php endforeach; ?>
    </ul>
</body>
</html>