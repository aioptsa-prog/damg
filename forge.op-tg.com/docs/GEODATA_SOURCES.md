# Saudi Arabia Geo Data Sources (Authoritative and Supporting)

Authoritative:
- GASTAT (General Authority for Statistics) — Administrative regions and official naming
  - https://www.stats.gov.sa/en
- SPL (Saudi Post | National Address) — Districts and addressing (license considerations)
  - https://splonline.com.sa/
- NDP (Saudi National Data Portal) — Open datasets (SDAIA)
  - https://data.gov.sa/

Supporting:
- HDX — Administrative boundaries (level 0–1) for reference
  - https://data.humdata.org/
- OpenStreetMap — Names and boundaries (ODbL; use as secondary verification)
  - https://wiki.openstreetmap.org/
- City open data portals (e.g., RCRC Open Data for Riyadh)
  - https://opendata.rcrc.gov.sa/

Guidelines:
- Fix the 13 regions from GASTAT; do not deviate from official names.
- Cross-check city/district names with SPL/NDP; use OSM for sanity checks, not as sole source.
- Record data version and download date; keep incremental updates in storage/releases/geo/sa/.
- If polygons are used from OSM, include ODbL attribution.
 - Save the exact import command and commit hash for reproducibility.

Acceptance targets:
- ≥100 sample points for point-to-city classification
- ≥98% city accuracy
- p50 ≤ 50 ms (report p95 as well)

Artifacts to commit after import:
- storage/data/geo/sa/sa_geo.db
- storage/data/geo/sa/sa_hierarchy.json
- storage/data/geo/sa/normalization.json (Arabic normalization/synonyms)
- storage/data/geo/sa/acceptance_results.json
