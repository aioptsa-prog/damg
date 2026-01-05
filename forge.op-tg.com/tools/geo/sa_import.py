"""
Populate storage/data/geo/sa/sa_geo.db with Saudi regions/cities/districts.
Sources: OSM extracts + official lists. This script expects a prepared CSV or GeoJSON inputs.

Usage:
  python tools/geo/sa_import.py --input data/sa_cities.csv --districts data/sa_districts.geojson
"""
import argparse, csv, json, os, sqlite3, time

ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), '..', '..'))
DATA_DIR = os.path.join(ROOT, 'storage', 'data', 'geo', 'sa')
DB_PATH = os.path.join(DATA_DIR, 'sa_geo.db')

def ensure_db(conn: sqlite3.Connection):
    c = conn.cursor()
    c.execute('PRAGMA journal_mode=WAL;')
    c.execute('PRAGMA synchronous=NORMAL;')
    c.execute('''CREATE TABLE IF NOT EXISTS regions(
        id INTEGER PRIMARY KEY, name_ar TEXT NOT NULL, name_en TEXT, alt_names TEXT
    )''')
    c.execute('''CREATE TABLE IF NOT EXISTS cities(
        id INTEGER PRIMARY KEY, region_id INTEGER NOT NULL, name_ar TEXT NOT NULL,
        name_en TEXT, alt_names TEXT, lat REAL, lon REAL,
        FOREIGN KEY(region_id) REFERENCES regions(id)
    )''')
    c.execute('''CREATE TABLE IF NOT EXISTS districts(
        id INTEGER PRIMARY KEY, city_id INTEGER NOT NULL, name_ar TEXT NOT NULL,
        name_en TEXT, alt_names TEXT, lat REAL, lon REAL,
        FOREIGN KEY(city_id) REFERENCES cities(id)
    )''')
    c.execute('CREATE INDEX IF NOT EXISTS idx_cities_region ON cities(region_id)')
    c.execute('CREATE INDEX IF NOT EXISTS idx_districts_city ON districts(city_id)')
    conn.commit()

def import_regions(conn, regions_csv):
    if not regions_csv: return
    with open(regions_csv, 'r', encoding='utf-8') as f:
        r = csv.DictReader(f)
        rows = [(int(row['id']), row['name_ar'], row.get('name_en',''), row.get('alt_names','')) for row in r]
    conn.executemany('INSERT OR REPLACE INTO regions(id,name_ar,name_en,alt_names) VALUES(?,?,?,?)', rows)
    conn.commit()

def import_cities(conn, cities_csv):
    if not cities_csv: return
    with open(cities_csv, 'r', encoding='utf-8') as f:
        r = csv.DictReader(f)
        rows = []
        for row in r:
            rows.append((int(row['id']), int(row['region_id']), row['name_ar'], row.get('name_en',''), row.get('alt_names',''), float(row.get('lat') or 0.0), float(row.get('lon') or 0.0)))
    conn.executemany('''INSERT OR REPLACE INTO cities(id,region_id,name_ar,name_en,alt_names,lat,lon)
                        VALUES(?,?,?,?,?,?,?)''', rows)
    conn.commit()

def import_districts(conn, districts_csv):
    if not districts_csv: return
    with open(districts_csv, 'r', encoding='utf-8') as f:
        r = csv.DictReader(f)
        rows = []
        for row in r:
            rows.append((int(row['id']), int(row['city_id']), row['name_ar'], row.get('name_en',''), row.get('alt_names',''), float(row.get('lat') or 0.0), float(row.get('lon') or 0.0)))
    conn.executemany('''INSERT OR REPLACE INTO districts(id,city_id,name_ar,name_en,alt_names,lat,lon)
                        VALUES(?,?,?,?,?,?,?)''', rows)
    conn.commit()

def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--regions', help='CSV with id,name_ar,name_en,alt_names')
    ap.add_argument('--cities', help='CSV with id,region_id,name_ar,name_en,alt_names,lat,lon')
    ap.add_argument('--districts', help='CSV with id,city_id,name_ar,name_en,alt_names,lat,lon')
    args = ap.parse_args()

    os.makedirs(DATA_DIR, exist_ok=True)
    conn = sqlite3.connect(DB_PATH)
    try:
        ensure_db(conn)
        import_regions(conn, args.regions)
        import_cities(conn, args.cities)
        import_districts(conn, args.districts)
        print('Import complete:', DB_PATH)
    finally:
        conn.close()

if __name__ == '__main__':
    main()
