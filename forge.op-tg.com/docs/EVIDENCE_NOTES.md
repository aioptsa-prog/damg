# Evidence Notes

This file summarizes how each artifact in the evidence zip was produced and where it lives in the repository.

- Deploy transcripts
  - SFTP DryRun / Maintenance / Rollback: tools/ops/capture_deploy.ps1 with labels; outputs in storage/logs/validation (winscp_*.winscp, *.stdout.txt, *.stderr.txt)
  - cPanel UAPI fallback: tools/deploy/deploy_cpanel_uapi.ps1; use -NoTerminal to capture Fileman-only move plan when Terminal is restricted

- HTTP probes
  - tools/http_probe.ps1 -BaseUrl <host> -OutFile storage/logs/validation/http_probe_<ts>_<label>.txt

- Worker updater
  - worker/update_worker.ps1 -SetupPath <path> [-Silent]; logs to storage/logs/update-worker.log; includes SHA256, exit code, restart

- Geo acceptance
  - tools/geo/sa_import.py (build sa_geo.db), tools/geo/gen_hierarchy.php, tools/geo/acceptance_test.php
  - Save acceptance_results.json under storage/data/geo/sa/

- Packaging
  - tools/ops/make_evidence_bundle.ps1 bundles storage/logs/validation/* to storage/releases/evidence_*.zip

- Ingestion probes (Sprint 0)
  - tools/ops/ingest_probe.php: ينشئ Job داخلي ويُدخل عناصر مرتين لإظهار added/duplicates؛ يطبع JSON.
  - tools/ops/capture_ingestion_evidence.php: يشغّل ingest_probe ويسجّل المخرجات إلى:
    - storage/logs/validation/ingestion_probe.json
    - storage/logs/validation/usage_counters_today.json
