#!/usr/bin/env python
# -*- coding: utf-8 -*-

import pandas as pd
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

# File paths
csv_file = "attendance_master.csv"
users_file = "all_users.xlsx"

print("=" * 60)
print("📊 IMPORT ALL HISTORICAL DATA TO MYSQL")
print("=" * 60)

# Step 1: Load users
print("\n👥 Loading users...")
if os.path.exists(users_file):
    users_df = pd.read_excel(users_file)
    users_df['user_id'] = users_df['user_id'].astype(str)
    user_map = dict(zip(users_df['user_id'], users_df['name']))
    print(f"✅ Loaded {len(users_df)} users")
else:
    print("❌ Users file not found!")
    exit(1)

# Step 2: Load attendance data
print("\n📂 Loading attendance data...")
if os.path.exists(csv_file):
    # Read CSV in chunks to handle large file
    chunk_size = 10000
    chunks = pd.read_csv(csv_file, chunksize=chunk_size)
    
    total_records = 0
    all_records = []
    
    for chunk in chunks:
        chunk['timestamp'] = pd.to_datetime(chunk['timestamp'])
        # Filter out future dates
        chunk = chunk[chunk['timestamp'] <= datetime.now()]
        all_records.append(chunk)
        total_records += len(chunk)
        print(f"   Processed {total_records} records...")
    
    df = pd.concat(all_records, ignore_index=True)
    print(f"✅ Loaded {len(df)} valid records from CSV")
else:
    print("❌ CSV file not found!")
    exit(1)

# Step 3: Show data summary
print("\n📊 Data Summary:")
print(f"   Total records: {len(df)}")
print(f"   Date range: {df['date'].min()} to {df['date'].max()}")
print(f"   Unique dates: {len(df['date'].unique())}")

# Count records by month
df['month'] = pd.to_datetime(df['date']).dt.to_period('M')
monthly_counts = df.groupby('month').size()
print("\n📅 Records by month:")
for month, count in monthly_counts.items():
    print(f"   {month}: {count} records")

# Step 4: Connect to MySQL
print("\n🔌 Connecting to MySQL...")
try:
    conn = pymysql.connect(**db_config)
    cursor = conn.cursor()
    print("✅ Connected to MySQL")
except Exception as e:
    print(f"❌ MySQL connection error: {e}")
    exit(1)

# Step 5: Clear existing data (optional - uncomment if you want to start fresh)
# print("\n🗑️ Clearing existing data...")
# cursor.execute("TRUNCATE TABLE attendance_raw")
# print("✅ Table cleared")

# Step 6: Import data in chunks
print("\n📤 Importing data to MySQL...")
inserted = 0
skipped = 0
batch_size = 1000
batches = len(df) // batch_size + 1

for batch_num in range(batches):
    start_idx = batch_num * batch_size
    end_idx = min((batch_num + 1) * batch_size, len(df))
    
    if start_idx >= len(df):
        break
    
    batch_df = df.iloc[start_idx:end_idx]
    
    for _, row in batch_df.iterrows():
        # Get user name from map or use default
        name = user_map.get(str(row['user_id']), row.get('name', f"User_{row['user_id']}"))
        
        sql = """INSERT INTO attendance_raw 
                (user_id, name, timestamp, date, time, sync_status) 
                VALUES (%s, %s, %s, %s, %s, 'historical')"""
        
        try:
            cursor.execute(sql, (
                str(row['user_id']),
                name,
                row['timestamp'].strftime('%Y-%m-%d %H:%M:%S'),
                row['date'],
                row['time']
            ))
            inserted += 1
        except Exception as e:
            skipped += 1
            if skipped < 10:  # Show first few errors
                print(f"   ⚠️ Error: {e}")
    
    # Commit every batch
    conn.commit()
    print(f"   Batch {batch_num + 1}/{batches}: Inserted {inserted} records so far...")

# Step 7: Final summary
print("\n" + "=" * 60)
print("📊 IMPORT COMPLETE")
print("=" * 60)
print(f"✅ Successfully inserted: {inserted} records")
print(f"⚠️ Skipped: {skipped} records")

# Step 8: Verify the data
print("\n🔍 Verifying data in MySQL...")
cursor.execute("SELECT COUNT(*) FROM attendance_raw")
total = cursor.fetchone()[0]
print(f"📊 Total records in MySQL: {total}")

cursor.execute("SELECT MIN(date), MAX(date) FROM attendance_raw")
min_date, max_date = cursor.fetchone()
print(f"📅 Date range: {min_date} to {max_date}")

cursor.execute("SELECT date, COUNT(*) FROM attendance_raw GROUP BY date ORDER BY date DESC LIMIT 10")
print("\n📅 Last 10 days of data:")
for date, count in cursor.fetchall():
    print(f"   {date}: {count} records")

cursor.close()
conn.close()
print("\n✅ Done!")