<?php
require_once '../src/Services/SeederService.php';

$seederService = new SeederService();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $dryRun = isset($_POST['dry_run']) ? true : false;

    if ($dryRun) {
        $result = $seederService->dryRun();
        echo json_encode(['status' => 'success', 'message' => 'Dry run completed.', 'result' => $result]);
    } else {
        $result = $seederService->run();
        echo json_encode(['status' => 'success', 'message' => 'Seeding completed.', 'result' => $result]);
    }
} else {
    // Display the seeding form
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Seed Database</title>
        <link rel="stylesheet" href="../public/css/app.css">
    </head>
    <body>
        <h1>Seed Database</h1>
        <form method="POST">
            <label>
                <input type="checkbox" name="dry_run"> Dry Run
            </label>
            <button type="submit">Run Seeder</button>
        </form>
    </body>
    </html>
    <?php
}
?>