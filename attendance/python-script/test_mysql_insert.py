#!/usr/bin/env python
# -*- coding: utf-8 -*-

import pymysql
from datetime import datetime

# MySQL connection
db_config = {
    'host': 'localhost',
    'user': 'root',
    'password': '',
    'database': 'balitech_attendance',
    'charset': 'utf8mb4'
}

print("=" * 60)
print("🔌 TESTING MYSQL CONNECTION AND INSERT")
print("=" * 60)

# Test 1: Check connection
try:
    conn = pymysql.connect(**db_config)
    cursor = conn.cursor()
    print("✅ MySQL connection successful")
except Exception as e:
    print(f"❌ MySQL connection failed: {e}")
    exit(1)

# Test 2: Insert a test record
try:
    now = datetime.now()
    test_sql = """
        INSERT INTO attendance_raw (user_id, name, timestamp, date, time, sync_status) 
        VALUES (%s, %s, %s, %s, %s, %s)
    """
    test_data = (
        '999', 
        'Test User', 
        now.strftime('%Y-%m-%d %H:%M:%S'),
        now.strftime('%Y-%m-%d'),
        now.strftime('%H:%M:%S'),
        'test'
    )
    cursor.execute(test_sql, test_data)
    conn.commit()
    print("✅ Test record inserted successfully")
except Exception as e:
    print(f"❌ Failed to insert test record: {e}")

# Test 3: Count records
cursor.execute("SELECT COUNT(*) FROM attendance_raw")
count = cursor.fetchone()[0]
print(f"📊 Total records in database: {count}")

# Test 4: Show all records
cursor.execute("SELECT user_id, name, timestamp FROM attendance_raw LIMIT 10")
records = cursor.fetchall()
print("\n📋 Records in database:")
if records:
    for r in records:
        print(f"   {r[0]} - {r[1]} - {r[2]}")
else:
    print("   No records found")

cursor.close()
conn.close()
print("\n🔌 Test complete")