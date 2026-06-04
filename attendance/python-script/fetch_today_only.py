#!/usr/bin/env python
# -*- coding: utf-8 -*-
"""
Simple script to fetch ONLY today's attendance data from ZKTeco device
"""

from zk import ZK
from datetime import datetime, timedelta
import pymysql
import pandas as pd
import socket

# =====================================================
# CONFIGURATION
# =====================================================
IP = "103.189.232.7"
PORT = 4730

# Database configuration
DB_CONFIG = {
    'host': 'localhost',
    'user': 'root',
    'password': '',
    'database': 'balitech',
    'charset': 'utf8mb4'
}

# =====================================================
# FUNCTIONS
# =====================================================

def test_connection():
    """Test if device is reachable"""
    try:
        sock = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
        sock.settimeout(5)
        result = sock.connect_ex((IP, PORT))
        sock.close()
        return result == 0
    except Exception:
        return False

def get_today_records():
    """Fetch only today's attendance records from device"""
    
    today = datetime.now().date()
    today_start = datetime(today.year, today.month, today.day, 0, 0, 0)
    today_end = datetime(today.year, today.month, today.day, 23, 59, 59)
    
    print(f"\n{'='*60}")
    print(f"📅 FETCHING ATTENDANCE FOR: {today}")
    print(f"{'='*60}")
    
    if not test_connection():
        print("❌ Device not reachable!")
        print(f"   IP: {IP}")
        print(f"   Port: {PORT}")
        return []
    
    zk = ZK(IP, port=PORT, timeout=120, ommit_ping=True)
    conn = None
    
    try:
        print("📡 Connecting to device...")
        conn = zk.connect()
        print("✅ Connected!")
        
        # CRITICAL: Disable device before reading attendance
        print("🔒 Disabling device for data fetch...")
        conn.disable_device()
        
        print("⏰ Fetching all attendance records...")
        all_logs = conn.get_attendance()
        print(f"📊 Total records in device: {len(all_logs)}")
        
        # Re-enable device
        conn.enable_device()
        print("🔓 Device re-enabled")
        
        # Filter for today's records only
        today_logs = []
        for log in all_logs:
            log_date = log.timestamp.date()
            if log_date == today:
                today_logs.append(log)
        
        print(f"📋 Records for today ({today}): {len(today_logs)}")
        
        return today_logs
        
    except Exception as e:
        print(f"❌ Error: {e}")
        import traceback
        traceback.print_exc()
        return []
    finally:
        if conn:
            try:
                conn.disconnect()
                print("🔌 Disconnected")
            except Exception:
                pass

def sync_to_mysql(records):
    """Save today's records to MySQL database"""
    
    if not records:
        print("ℹ️ No records to sync")
        return 0
    
    try:
        conn = pymysql.connect(**DB_CONFIG)
        cursor = conn.cursor()
        
        # Ensure table exists
        cursor.execute("""
            CREATE TABLE IF NOT EXISTS attendance_raw (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id VARCHAR(50),
                name VARCHAR(255),
                timestamp DATETIME,
                date DATE,
                time TIME,
                sync_status VARCHAR(50),
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        """)
        
        inserted = 0
        skipped = 0
        
        for log in records:
            # Check if record already exists
            cursor.execute(
                "SELECT id FROM attendance_raw WHERE user_id = %s AND timestamp = %s",
                (str(log.user_id), log.timestamp)
            )
            
            if cursor.fetchone() is None:
                cursor.execute("""
                    INSERT INTO attendance_raw (user_id, name, timestamp, date, time, sync_status)
                    VALUES (%s, %s, %s, %s, %s, 'synced')
                """, (
                    str(log.user_id),
                    log.user_id,  # Using user_id as name fallback
                    log.timestamp,
                    log.timestamp.date(),
                    log.timestamp.time()
                ))
                inserted += 1
            else:
                skipped += 1
        
        conn.commit()
        print(f"✅ Synced {inserted} new records to MySQL (skipped {skipped} duplicates)")
        
        cursor.close()
        conn.close()
        return inserted
        
    except Exception as e:
        print(f"❌ MySQL error: {e}")
        return 0

def save_to_csv(records):
    """Save today's records to CSV file"""
    
    if not records:
        print("ℹ️ No records to save to CSV")
        return
    
    data = []
    for log in records:
        data.append({
            'user_id': log.user_id,
            'timestamp': log.timestamp,
            'date': log.timestamp.date(),
            'time': log.timestamp.time()
        })
    
    if data:
        df = pd.DataFrame(data)
        today_str = datetime.now().strftime('%Y%m%d')
        filename = f"attendance_{today_str}.csv"
        df.to_csv(filename, index=False)
        print(f"✅ Saved {len(data)} records to {filename}")
        
        # Also append to master file
        master_file = "attendance_master.csv"
        if os.path.exists(master_file):
            df.to_csv(master_file, mode='a', header=False, index=False)
        else:
            df.to_csv(master_file, index=False)
        print(f"✅ Appended to {master_file}")

def display_records(records):
    """Display today's records in a formatted table"""
    
    if not records:
        print("\n📭 No attendance records found for today!")
        print("\nPossible reasons:")
        print("  1. No one has punched in today")
        print("  2. Device date/time is incorrect")
        print("  3. Device storage is full")
        print("  4. Network connectivity issue")
        return
    
    print(f"\n📋 TODAY'S ATTENDANCE RECORDS ({datetime.now().strftime('%Y-%m-%d')})")
    print("-" * 70)
    print(f"{'User ID':<10} {'Time':<20} {'Date':<12}")
    print("-" * 70)
    
    for log in sorted(records, key=lambda x: x.timestamp):
        print(f"{log.user_id:<10} {log.timestamp.strftime('%H:%M:%S'):<20} {log.timestamp.date()}")
    
    print("-" * 70)
    print(f"Total: {len(records)} records")

def get_users_from_device():
    """Fetch users from device for name mapping"""
    
    zk = ZK(IP, port=PORT, timeout=120, ommit_ping=True)
    conn = None
    
    try:
        conn = zk.connect()
        users = conn.get_users()
        
        user_map = {}
        for user in users:
            user_map[str(user.user_id)] = user.name or f"User_{user.user_id}"
        
        # Save to Excel
        users_df = pd.DataFrame([(uid, name) for uid, name in user_map.items()], 
                                columns=['user_id', 'name'])
        users_df.to_excel("all_users.xlsx", index=False)
        
        print(f"✅ Fetched {len(user_map)} users")
        return user_map
        
    except Exception as e:
        print(f"❌ Error fetching users: {e}")
        return {}
    finally:
        if conn:
            conn.disconnect()

def update_records_with_names(records, user_map):
    """Add names to records"""
    for log in records:
        log.name = user_map.get(str(log.user_id), f"User_{log.user_id}")
    return records

# =====================================================
# MAIN
# =====================================================

if __name__ == "__main__":
    import os
    
    print("\n" + "="*60)
    print("🎯 ATTENDANCE FETCHER - TODAY ONLY")
    print("="*60)
    
    # Step 1: Get users from device
    print("\n📌 Step 1: Fetching users from device...")
    user_map = get_users_from_device()
    
    # Step 2: Get today's records
    print("\n📌 Step 2: Fetching today's attendance records...")
    today_records = get_today_records()
    
    if today_records:
        # Step 3: Add names
        today_records = update_records_with_names(today_records, user_map)
        
        # Step 4: Display records
        display_records(today_records)
        
        # Step 5: Save to CSV
        print("\n📌 Step 3: Saving to CSV...")
        save_to_csv(today_records)
        
        # Step 6: Sync to MySQL
        print("\n📌 Step 4: Syncing to MySQL...")
        inserted = sync_to_mysql(today_records)
        
        print("\n" + "="*60)
        print("✅ COMPLETED!")
        print(f"   Total records processed: {len(today_records)}")
        print(f"   New records inserted in MySQL: {inserted}")
        print("="*60)
    else:
        print("\n❌ No records found for today!")
        print("\n🔍 Troubleshooting steps:")
        print("   1. Check if device is online: ping " + IP)
        print("   2. Verify someone has punched in today")
        print("   3. Check device date/time settings")
        print("   4. Try rebooting the device")