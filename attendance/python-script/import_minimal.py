#!/usr/bin/env python
# -*- coding: utf-8 -*-

import csv
import pymysql
from datetime import datetime
import os

print("=" * 60)
print("📤 MINIMAL CSV TO MYSQL IMPORT")
print("=" * 60)

# MySQL connection
conn = None
try:
    conn = pymysql.connect(
        host='localhost',
        user='root',
        password='',
        database='balitech_attendance',
        charset='utf8mb4'
    )
    cursor = conn.cursor()
    print("✅ Connected to MySQL")
    
    # Check table structure
    cursor.execute("DESCRIBE attendance_raw")
    print("\n📋 Table columns:")
    for col in cursor.fetchall():
        print(f"   {col[0]} - {col[1]}")
    
    # Read CSV
    csv_file = "attendance_master.csv"
    if not os.path.exists(csv_file):
        print(f"❌ CSV file not found: {csv_file}")
        exit(1)
    
    print(f"\n📂 Reading {csv_file}...")
    
    with open(csv_file, 'r', encoding='utf-8') as f:
        reader = csv.reader(f)
        headers = next(reader)
        print(f"📋 CSV headers: {headers}")
        
        # Insert first 100 records as test
        inserted = 0
        for i, row in enumerate(reader):
            if i >= 100:  # Only first 100 for testing
                break
                
            try:
                user_id = row[0].strip()
                name = row[1].strip() if len(row) > 1 else f"User_{user_id}"
                timestamp_str = row[2].strip()
                
                dt = datetime.strptime(timestamp_str, '%Y-%m-%d %H:%M:%S')
                date_str = dt.strftime('%Y-%m-%d')
                time_str = dt.strftime('%H:%M:%S')
                
                sql = """INSERT INTO attendance_raw 
                        (user_id, name, timestamp, date, time, sync_status) 
                        VALUES (%s, %s, %s, %s, %s, %s)"""
                cursor.execute(sql, (
                    user_id, name, timestamp_str, date_str, time_str, 'test'
                ))
                inserted += 1
                
            except Exception as e:
                print(f"❌ Error on row {i}: {e}")
                print(f"   Row data: {row}")
        
        conn.commit()
        print(f"\n✅ Inserted {inserted} test records")
        
        # Verify
        cursor.execute("SELECT COUNT(*) FROM attendance_raw")
        count = cursor.fetchone()[0]
        print(f"📊 Total records: {count}")
        
        if count > 0:
            cursor.execute("SELECT * FROM attendance_raw LIMIT 5")
            print("\n📋 Sample records:")
            for row in cursor.fetchall():
                print(f"   {row}")
    
except Exception as e:
    print(f"❌ Error: {e}")
    import traceback
    traceback.print_exc()
    
finally:
    if conn:
        cursor.close()
        conn.close()
        print("\n🔌 Connection closed")