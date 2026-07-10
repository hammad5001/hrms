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
print("📤 FIXED CSV TO MYSQL IMPORT")
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

try:
    # Connect to MySQL
    print("🔌 Connecting to MySQL...")
    conn = pymysql.connect(**db_config)
    cursor = conn.cursor()
    print("✅ Connected to MySQL")
    
    # Count existing records
    cursor.execute("SELECT COUNT(*) FROM attendance_raw")
    before_count = cursor.fetchone()[0]
    print(f"📊 Records before import: {before_count}")
    
    # Read and import CSV
    imported = 0
    skipped = 0
    total = 0
    
    with open(csv_file, 'r', encoding='utf-8') as file:
        reader = csv.reader(file)
        headers = next(reader)  # Skip header
        print(f"📋 CSV Headers: {headers}")
        
        for row in reader:
            total += 1
            if len(row) < 3:
                skipped += 1
                continue
                
            user_id = row[0].strip()
            name = row[1].strip() if len(row) > 1 else f"User_{user_id}"
            timestamp_str = row[2].strip() if len(row) > 2 else None
            
            if not user_id or not timestamp_str:
                skipped += 1
                continue
            
            try:
                # Parse timestamp
                dt = datetime.strptime(timestamp_str, '%Y-%m-%d %H:%M:%S')
                date_str = dt.strftime('%Y-%m-%d')
                time_str = dt.strftime('%H:%M:%S')
                
                # Check if record already exists
                check_sql = "SELECT id FROM attendance_raw WHERE user_id = %s AND timestamp = %s"
                cursor.execute(check_sql, (user_id, timestamp_str))
                
                if cursor.fetchone() is None:
                    # Insert new record
                    insert_sql = """
                        INSERT INTO attendance_raw 
                        (user_id, name, timestamp, date, time, sync_status) 
                        VALUES (%s, %s, %s, %s, %s, %s)
                    """
                    cursor.execute(insert_sql, (
                        user_id, 
                        name, 
                        timestamp_str, 
                        date_str, 
                        time_str, 
                        'imported'
                    ))
                    imported += 1
                else:
                    skipped += 1
                
                # Commit every 1000 records
                if total % 1000 == 0:
                    conn.commit()
                    print(f"   Progress: {total} records processed... (Imported: {imported})")
                    
            except Exception as e:
                print(f"   ❌ Error on line {total}: {e}")
                skipped += 1
                continue
    
    # Final commit
    conn.commit()
    
    # Get final count
    cursor.execute("SELECT COUNT(*) FROM attendance_raw")
    after_count = cursor.fetchone()[0]
    
    print(f"\n✅ Import completed!")
    print(f"   📊 Total CSV records: {total}")
    print(f"   📥 Newly imported: {imported}")
    print(f"   ⏭️  Skipped: {skipped}")
    print(f"   📁 Records in DB: {before_count} → {after_count}")
    
    # Show sample of imported data
    if after_count > 0:
        cursor.execute("SELECT user_id, name, timestamp FROM attendance_raw ORDER BY timestamp DESC LIMIT 5")
        print("\n📋 Latest records:")
        for row in cursor.fetchall():
            print(f"   {row[0]} - {row[1]} - {row[2]}")
    
    cursor.close()
    conn.close()
    print("\n🔌 MySQL connection closed")
    
except Exception as e:
    print(f"❌ Error: {e}")
    import traceback
    traceback.print_exc()