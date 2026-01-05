# Security

- INTERNAL_SECRET: 64-hex recommended, exact match via hash_equals
- Rotation: change in Admin; old secret yields 401; new secret 200 (tested)
- HTTPS + HSTS recommended at the web server; no secrets embedded in EXE
- Storage permissions: storage/, storage/logs, storage/releases writable by PHP; no directory listings
- Download security: static binary only, Range/HEAD/ETag; no query parameters
