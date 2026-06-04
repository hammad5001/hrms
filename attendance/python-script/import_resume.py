#!/usr/bin/env python
# -*- coding: utf-8 -*-

import csv
import pymysql
from datetime import datetime
import os
import time

# MySQL connection
db_config = {
    'host': 'localhost',
    'user': 'root',
    'password': '',
    'database': 'balitech_attendance',
    'charset': 'utf8mb4'
}

print("=" * 60)
print("📤 RESUMING CSV IMPORT TO MYSQL")
print("=" * 60)

csv_file = "attendance_master.csv"
if not os.path.exists(csv_file):
    print(f"❌ CSV file not found: {csv_file}")
    exit(1)

print(f"📂 Reading CSV file: {csv_file}")

try:
    conn = pymysql.connect(**db_config)
    cursor = conn.cursor()
    
    # Get count of existing records
    cursor.execute("SELECT COUNT(*) FROM attendance_raw")
    existing_count = cursor.fetchone()[0]
    print(f"📁 Already in database: {existing_count} records")
    
    # Read CSV and continue from where we left off
    with open(csv_file, 'r', encoding='utf-8') as file:
        reader = csv.reader(file)
        headers = next(reader)
        
        imported = 0
        skipped = 0
        total = 0
        
        for row in reader:
            total += 1
            
            # Skip if we've already processed this many
            if total <= existing_count:
                continue
                
            if len(row) < 3:
                continue
                
            user_id = row[0]
            name = row[1] if len(row) > 1 else ''
            timestamp = row[2]
            
            try:
                dt = datetime.strptime(timestamp, '%Y-%m-%d %H:%M:%S')
                date_str = dt.strftime('%Y-%m-%d')
                time_str = dt.strftime('%H:%M:%S')
                
                insert_sql = """
                    INSERT INTO attendance_raw 
                    (user_id, name, timestamp, date, time, sync_status) 
                    VALUES (%s, %s, %s, %s, %s, 'pending')
                """
                cursor.execute(insert_sql, (user_id, name, timestamp, date_str, time_str))
                imported += 1
                
                if total % 100 == 0:
                    conn.commit()
                    print(f"   Progress: {total}/57522 records... (New: {imported})")
                    
            except Exception as e:
                print(f"❌ Error on record {total}: {e}")
                continue
        
        conn.commit()
        
        print(f"\n✅ Import completed!")
        print(f"   📊 Total processed: {total}")
        print(f"   📥 Newly imported: {imported}")
        
        cursor.execute("SELECT COUNT(*) FROM attendance_raw")
        final_count = cursor.fetchone()[0]
        print(f"   📁 Final total: {final_count} records")
        
        cursor.close()
        conn.close()
        
except Exception as e:
    print(f"❌ Error: {e}")