<?php
// 📂 ملف: upload.php
// يرفع أي ملف (صورة / فيديو / PDF / صوت) ويحفظه في مجلد /uploads/ ثم يُرجع JSON بالرابط النهائي

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

$uploadDir = __DIR__ . '/uploads/';

// إنشاء المجلد إذا لم يكن موجودًا
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0775, true);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_FILES['file'])) {
    echo json_encode(['success' => false, 'message' => 'لم يتم رفع أي ملف.']);
    exit;
}

$file = $_FILES['file'];
$allowed = ['jpg','jpeg','png','gif','mp4','mov','pdf','doc','docx','aac','mp3','wav'];
$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

if (!in_array($ext, $allowed)) {
    echo json_encode(['success' => false, 'message' => 'صيغة الملف غير مسموح بها.']);
    exit;
}

if ($file['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['success' => false, 'message' => 'خطأ أثناء الرفع: ' . $file['error']]);
    exit;
}

// حجم افتراضي آمن (السيرفر قد يقيّد عبر php.ini)
$maxMB = 50;
if ($file['size'] > $maxMB * 1024 * 1024) {
    echo json_encode(['success' => false, 'message' => 'حجم الملف يتجاوز ' . $maxMB . 'MB']);
    exit;
}

// توليد اسم فريد
$filename = uniqid('file_', true) . '.' . $ext;
$path = $uploadDir . $filename;

if (move_uploaded_file($file['tmp_name'], $path)) {
    // توليد الرابط العام
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'];
    $basePath = rtrim(str_replace('upload.php', '', $_SERVER['PHP_SELF']), '/');
    $url = $scheme . '://' . $host . $basePath . '/uploads/' . $filename;
    echo json_encode(['success' => true, 'url' => $url]);
} else {
    echo json_encode(['success' => false, 'message' => 'فشل حفظ الملف.']);
}
