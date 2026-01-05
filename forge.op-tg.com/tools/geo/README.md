# Saudi Geo Data (SA)

This directory contains scripts and data for a local Saudi Arabia geo database used by the app.

Contents:
- `sa_geo_init.php`: Creates `storage/data/geo/sa/sa_geo.db` with base schema and seeds the 13 regions.
- `sa_osm_cities_fetch.php`: Fetches SA cities/towns from OpenStreetMap (Overpass) and inserts/updates them.
- `sa_osm_districts_fetch.php`: Fetches SA districts (neighbourhoods/suburbs/quarters) from OSM and links them to nearest cities.
- `sa_cities_import.php`: Imports cities from a TSV/CSV file if you have a curated dataset.
- `sa_districts_import.php`: Imports districts (neighborhoods) from a TSV/CSV file.

The app uses this DB for:
- Reverse lookup: map point -> nearest city (and region)
- Forward lookup: city text -> city center (to snap the map marker)

## Quick start
1. Initialize schema and regions:
```powershell
php tools/geo/sa_geo_init.php
```
2. Fetch cities from OpenStreetMap:
```powershell
php tools/geo/sa_osm_cities_fetch.php
```
3. Fetch districts from OpenStreetMap:
```powershell
php tools/geo/sa_osm_districts_fetch.php
```
3. (Optional) Import curated cities TSV with columns:
`region_code,name_ar,name_en,alt_names,lat,lon,population,wikidata`
```powershell
php tools/geo/sa_cities_import.php path\to\sa_cities.tsv
```
4. (Optional) Import districts TSV with columns:
`city_name,name_ar,name_en,lat,lon`
```powershell
php tools/geo/sa_districts_import.php path\to\sa_districts.tsv
```

## Notes
- Overpass usage is rate-limited. For large imports, consider caching or running once.
- Districts are not provided by Overpass in a consistent manner. Prefer curated data.
- All scripts are idempotent where possible (upserts by reasonable keys).
# Acceptance tests
- tools/geo/acceptance_test.php checks point classification accuracy and performance against city centers.
- After fetching districts, you can test district text matching via lib/geo.php::geo_classify_text(city,district).
# Geo data pipeline (SA-first)

Folders:
- storage/data/geo/sa/
  - sa_geo.db (SQLite with regions, cities, districts)
  - normalization.json (Arabic normalization and synonyms)
  - sa_regions.json (13 administrative regions)
  - sa_hierarchy.json (Region -> Cities -> Districts)
  - districts.geojson (optional, polygons)

Importer scripts (to be added next iterations):
- sa_import.py: Import CSV/GeoJSON and populate sa_geo.db (regions/cities/districts). Seeds: `storage/data/geo/sa/regions_sample.csv`, `cities_sample.csv`.

Notes:
- Keep UTF-8 encoding for Arabic names.
- Preserve official names; store variants in alt_names.
- Attribution for OSM required (see docs/ATTRIBUTION.md).
- Target acceptance: ≥98% city accuracy (≥100 sample points), p50 ≤ 50ms.
