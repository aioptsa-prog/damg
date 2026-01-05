# Deployment (shared hosting)

1) Upload nexus.op-tg.com-release.zip and extract to web root/subdir
2) Ensure PHP 8+, SQLite enabled; set file permissions so storage/ (logs, releases) are writable
3) Enable OPcache in php.ini; set memory/timeouts as needed
4) Place signed installer into storage/releases/ and write latest.json
5) Configure Admin settings: Worker Base URL=https://nexus.op-tg.com, INTERNAL_SECRET (64-hex), PULL_INTERVAL_SEC=30
6) Verify Admin→Worker Setup shows version/SHA and download works
