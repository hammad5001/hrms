#!/usr/bin/env python
# -*- coding: utf-8 -*-

import csv
import pymysql
from datetime import datetime
import os

# MySQL connection
db_config = {
    'host': 'localhost',
    'user': 'root',
    'password': '',
    'database': 'balitech_attendance',
    'charset': 'utf8mb4'
}

print("=" * 60)
print("📤 IMPORTING CSV DATA TO MYSQL")
print("=" * 60)

# Check if CSV file exists
csv_file = "attendance_master.csv"
if not os.path.exists(csv_file):
    print(f"❌ CSV file not found: {csv_file}")
    print("📂 Available files:")
    for file in os.listdir('.'):
        if file.endswith('.csv'):
            print(f"   - {file}")
    exit(1)

print(f"📂 Reading CSV file: {csv_file}")
imported = 0
skipped = 0
total = 0

try:
    # Connect to MySQL
    print("🔌 Connecting to MySQL...")
    conn = pymysql.connect(**db_config)
    cursor = conn.cursor()
    print("✅ Connected to MySQL")
    
    # First, clear the table if you want fresh import
    # cursor.execute("TRUNCATE TABLE attendance_raw")
    # print("🧹 Cleared existing data")
    
    # Read CSV
    with open(csv_file, 'r', encoding='utf-8') as file:
        reader = csv.reader(file)
        headers = next(reader)  # Skip header row
        print(f"📋 CSV Headers: {headers}")
        
        for row in reader:
            total += 1
            if len(row) < 3:
                continue
                
            user_id = row[0]  # First column: user_id
            name = row[1] if len(row) > 1 else ''  # Second column: name
            timestamp = row[2]  # Third column: timestamp
            
            try:
                # Parse timestamp
                dt = datetime.strptime(timestamp, '%Y-%m-%d %H:%M:%S')
                date_str = dt.strftime('%Y-%m-%d')
                time_str = dt.strftime('%H:%M:%S')
                
                # Check if record already exists (using user_id and timestamp)
                check_sql = "SELECT id FROM attendance_raw WHERE user_id = %s AND timestamp = %s"
                cursor.execute(check_sql, (user_id, timestamp))
                
                if cursor.fetchone() is None:
                    # Insert new record with correct column names
                    insert_sql = """
                        INSERT INTO attendance_raw 
                        (user_id, name, timestamp, date, time, sync_status) 
                        VALUES (%s, %s, %s, %s, %s, 'pending')
                    """
                    cursor.execute(insert_sql, (user_id, name, timestamp, date_str, time_str))
                    imported += 1
                else:
                    skipped += 1
                    
                # Show progress every 1000 records
                if total % 1000 == 0:
                    print(f"   Progress: {total} records processed... (Imported: {imported}, Skipped: {skipped})")
                    conn.commit()  # Commit periodically
                    
            except Exception as e:
                print(f"❌ Error on record {total}: {e}")
                continue
    
    # Final commit
    conn.commit()
    
    print(f"\n✅ Import completed!")
    print(f"   📊 Total in CSV: {total}")
    print(f"   📥 Imported: {imported} new records")
    print(f"   ⏭️  Skipped: {skipped} duplicates")
    
    # Show sample of inserted data
    cursor.execute("SELECT COUNT(*) FROM attendance_raw")
    count = cursor.fetchone()[0]
    print(f"   📁 Total in database: {count} records")
    
    # Show first few records
    cursor.execute("SELECT user_id, name, timestamp FROM attendance_raw LIMIT 5")
    print("\n📋 Sample records:")
    for row in cursor.fetchall():
        print(f"   {row[0]} - {row[1]} - {row[2]}")
    
    cursor.close()
    conn.close()
    
except Exception as e:
    print(f"❌ Error: {e}")