# Backup & Restore Guide

## Overview

This document covers backup and restore procedures for the Forge + OP-Target system.

---

## 1. Forge (SQLite)

### Database Location
```
forge.op-tg.com/data/forge.sqlite
```

### Backup

#### Manual Backup
```bash
# From forge.op-tg.com directory
cp data/forge.sqlite backups/forge_$(date +%Y%m%d_%H%M%S).sqlite
```

#### Automated Daily Backup (cron)
```bash
# Add to crontab -e
0 2 * * * cd /path/to/forge.op-tg.com && cp data/forge.sqlite backups/forge_$(date +\%Y\%m\%d).sqlite
```

#### Backup with Compression
```bash
sqlite3 data/forge.sqlite ".backup 'backups/forge_backup.sqlite'"
gzip backups/forge_backup.sqlite
```

### Restore
```bash
# Stop PHP server first
cp backups/forge_YYYYMMDD.sqlite data/forge.sqlite
# Restart PHP server
```

### Verify Backup Integrity
```bash
sqlite3 backups/forge_backup.sqlite "PRAGMA integrity_check;"
# Should return: ok
```

---

## 2. OP-Target (Neon PostgreSQL)

### Connection Info
- Provider: Neon (https://console.neon.tech)
- Connection: `DATABASE_URL` in `.env`

### Backup

#### Using pg_dump
```bash
# Export DATABASE_URL or use connection string
pg_dump "$DATABASE_URL" > backups/op_target_$(date +%Y%m%d).sql

# With compression
pg_dump "$DATABASE_URL" | gzip > backups/op_target_$(date +%Y%m%d).sql.gz
```

#### Neon Console Backup
1. Go to https://console.neon.tech
2. Select your project
3. Go to Branches → Create Branch (point-in-time backup)

#### Neon CLI
```bash
neonctl branches create --name backup-$(date +%Y%m%d)
```

### Restore

#### From SQL dump
```bash
psql "$DATABASE_URL" < backups/op_target_YYYYMMDD.sql
```

#### From Neon Branch
1. Go to Neon Console
2. Select the backup branch
3. Use "Restore" or switch primary endpoint

---

## 3. Worker Data

### Profile Data (Playwright)
```
forge.op-tg.com/worker/profile-data/
```

This contains browser session data. Usually not critical to backup, but if needed:
```bash
tar -czf backups/worker_profile_$(date +%Y%m%d).tar.gz worker/profile-data/
```

---

## 4. Logs

### Location
```
forge.op-tg.com/logs/YYYY-MM-DD.log
```

### Backup Logs
```bash
# Keep last 30 days, archive older
find logs/ -name "*.log" -mtime +30 -exec gzip {} \;
mv logs/*.gz backups/logs/
```

---

## 5. Environment Files

**NEVER backup .env files to shared storage!**

For disaster recovery, document required variables in `.env.example` and store actual values in a secure password manager.

---

## 6. Full System Backup Script

```bash
#!/bin/bash
# backup_all.sh

BACKUP_DIR="/path/to/backups/$(date +%Y%m%d)"
mkdir -p "$BACKUP_DIR"

# 1. Forge SQLite
cp forge.op-tg.com/data/forge.sqlite "$BACKUP_DIR/forge.sqlite"

# 2. OP-Target PostgreSQL
pg_dump "$DATABASE_URL" | gzip > "$BACKUP_DIR/op_target.sql.gz"

# 3. Logs (last 7 days)
find forge.op-tg.com/logs/ -name "*.log" -mtime -7 -exec cp {} "$BACKUP_DIR/" \;

# 4. Verify
sqlite3 "$BACKUP_DIR/forge.sqlite" "PRAGMA integrity_check;"
gunzip -t "$BACKUP_DIR/op_target.sql.gz"

echo "Backup completed: $BACKUP_DIR"
```

---

## 7. Disaster Recovery Checklist

1. [ ] Restore Forge SQLite from backup
2. [ ] Restore OP-Target from Neon branch or SQL dump
3. [ ] Verify `.env` files are configured
4. [ ] Run health checks:
   - `GET /v1/api/health.php` (Forge)
   - `GET /api/health` (OP-Target)
5. [ ] Test login flow
6. [ ] Test job creation and worker pull
7. [ ] Test WhatsApp send (sandbox)

---

## 8. Retention Policy

| Data | Retention | Storage |
|------|-----------|---------|
| SQLite daily backups | 30 days | Local + Cloud |
| PostgreSQL branches | 7 days | Neon |
| Logs | 90 days | Local |
| Worker profiles | Not backed up | - |

---

## 9. Monitoring Backup Health

Add to health check:
```php
// Check last backup age
$lastBackup = glob('backups/forge_*.sqlite');
rsort($lastBackup);
$backupAge = time() - filemtime($lastBackup[0] ?? '');
if ($backupAge > 86400 * 2) {
    // Alert: backup older than 2 days
}
```

---

> Last updated: 2026-01-05
