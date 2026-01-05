# Evidence Checklist

Use this list to verify all artifacts are captured before producing the final evidence zip under `storage/releases`.

Deployment transcripts
- [ ] SFTP DryRun script (winscp_*.winscp) under storage/logs/validation
- [ ] SFTP Maintenance deploy transcript (stdout/stderr) under storage/logs/validation
- [ ] SFTP Rollback transcript (stdout/stderr) under storage/logs/validation
- [ ] cPanel UAPI fallback transcript (upload/extract/swap), including Fileman-only move when Terminal is restricted

HTTP probes
- [ ] latest.php: HEAD + ETag + 304 evidence
- [ ] download_worker.php: HEAD + Range 206 evidence

Worker updater logs
- [ ] Full update cycle log with checksum match/mismatch note, installer exit code, and service restart (storage/logs/update-worker.log)

Admin screenshots
- [ ] Worker Setup: version, SHA, publisher
- [ ] Monitoring with Top Cities
- [ ] Geo page: entity counts and last update

Saudi dataset acceptance
- [ ] Full sa_geo.db populated (13 regions, all cities, districts for metros)
- [ ] sa_hierarchy.json generated
- [ ] acceptance_test.php run with ≥100 points; results ≥98% accuracy; p50 ≤50ms
- [ ] acceptance_results.json committed under storage/data/geo/sa/
- [ ] GEODATA_SOURCES.md + ATTRIBUTION.md updated with timestamps and sources

Docs finalization
- [ ] RUNBOOK updated with evidence notes
- [ ] CHANGELOG includes acceptance results

Ingestion pipeline evidence (Sprint 0)
- [ ] API report_results sample payload and response showing { added, duplicates, done }
- [ ] usage_counters snapshot for day: ingest_added and ingest_duplicates before/after a run (from storage/logs/validation/usage_counters_today.json)
- [ ] Probe outputs saved: storage/logs/validation/ingestion_probe.json
- [ ] Screenshot of Admin Monitor badges showing duplicates ratio (24h) and 7-day trend

Map incident and verification
- [ ] Screenshot(s) of admin/agent fetch pages with map visible and status line showing active tile source
- [ ] Console/Network snapshot showing Leaflet loaded from local assets (or documented CDN fallback) without errors
- [ ] If fallbacks triggered: captured status messages indicating source switches
- [ ] Copy of current `tile_sources_json` (sanitized) used in production and link to CONFIG_REFERENCE.md

Packaging
- [ ] storage/releases/evidence_*.zip contains: transcripts, probe outputs, updater log, screenshots, acceptance_results.json, and relevant docs updates