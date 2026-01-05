# Update Flow

- Worker checks latest.json (GET /api/latest.php or /storage/releases/latest.json)
- Compares version; if newer, downloads installer, verifies SHA256, invokes update_worker.ps1 for atomic swap and service restart
- Channels: default stable; future beta channel via latest-beta.json
