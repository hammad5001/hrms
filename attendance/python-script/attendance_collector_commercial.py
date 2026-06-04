#!/usr/bin/env python
# -*- coding: utf-8 -*-

from zk import ZK
import pandas as pd
from datetime import datetime, timedelta
import time
import os
import socket
import pymysql
from collections import defaultdict

IP = "125.209.68.118"
PORT = 4370
START_DATE = datetime(2026, 1, 1)  # January 1, 2026
AUTO_SYNC_MINUTES = 15

SHIFT_GROUPING_START_HOUR = 14   # 2 PM
LATE_SHIFT_START_HOUR = 18       # 6 PM
LATE_GRACE_MINUTES = 10          # 10 minutes grace


class DailyAttendanceTracker:
    def __init__(self):
        print("🔌 Initializing Attendance Tracker...")
        self.data_file = "attendance_master_commercial.csv"
        self.users_file = "all_users_commercial.xlsx"
        self.checkpoint_file = "last_sync_commercial.txt"
        self.consolidated_report = "complete_attendance_report_commercial.xlsx"

        self.db_config = {
            'host': 'localhost',
            'user': 'root',
            'password': '',
            'database': 'balitech',
            'charset': 'utf8mb4'
        }

        self.load_existing_data()

    def load_existing_data(self):
        """Load existing data to avoid duplicates"""
        if os.path.exists(self.users_file):
            self.users_df = pd.read_excel(self.users_file)
            self.users_df['user_id'] = self.users_df['user_id'].astype(str)
            self.user_map = dict(zip(self.users_df['user_id'], self.users_df['name']))
            print(f"📂 Loaded {len(self.users_df)} users from {self.users_file}")
        else:
            self.user_map = {}
            self.users_df = pd.DataFrame(columns=['user_id', 'name'])

        if os.path.exists(self.data_file):
            self.df = pd.read_csv(self.data_file)

            if 'timestamp' not in self.df.columns:
                self.df = pd.DataFrame(columns=['user_id', 'name', 'timestamp', 'date', 'time'])

            self.df['timestamp'] = pd.to_datetime(self.df['timestamp'], errors='coerce')
            self.df = self.df.dropna(subset=['timestamp'])
            self.df['user_id'] = self.df['user_id'].astype(str)

            current_time = datetime.now()
            self.df = self.df[self.df['timestamp'] <= current_time]

            if 'date' not in self.df.columns:
                self.df['date'] = self.df['timestamp'].dt.strftime('%Y-%m-%d')
            if 'time' not in self.df.columns:
                self.df['time'] = self.df['timestamp'].dt.strftime('%H:%M:%S')
            if 'name' not in self.df.columns:
                self.df['name'] = ''

            self.existing_records = set()
            for _, row in self.df.iterrows():
                key = f"{row['user_id']}_{row['timestamp']}"
                self.existing_records.add(key)

            print(f"📂 Loaded {len(self.df)} existing attendance records")

            if os.path.exists(self.checkpoint_file):
                with open(self.checkpoint_file, 'r') as f:
                    last_sync_str = f.read().strip()
                    try:
                        self.last_sync = datetime.strptime(last_sync_str, '%Y-%m-%d %H:%M:%S')
                    except Exception:
                        self.last_sync = START_DATE
            else:
                self.last_sync = START_DATE
        else:
            self.df = pd.DataFrame(columns=['user_id', 'name', 'timestamp', 'date', 'time'])
            self.existing_records = set()
            self.last_sync = START_DATE

        print(f"📅 Last sync: {self.last_sync.strftime('%Y-%m-%d %H:%M:%S')}")

    def save_checkpoint(self):
        """Save last sync time"""
        with open(self.checkpoint_file, 'w') as f:
            f.write(datetime.now().strftime('%Y-%m-%d %H:%M:%S'))

    def clear_today_duplicates(self):
        """Clear duplicate tracking for today's date to force re-fetch"""
        today = datetime.now().strftime('%Y-%m-%d')
        
        # Remove today's records from existing_records set
        to_remove = []
        for key in self.existing_records:
            if today in key:
                to_remove.append(key)
        
        for key in to_remove:
            self.existing_records.discard(key)
        
        print(f"✅ Cleared duplicate tracking for {today}")
        return len(to_remove)

    def test_connection(self):
        """Test if device is reachable"""
        try:
            sock = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
            sock.settimeout(5)
            result = sock.connect_ex((IP, PORT))
            sock.close()
            return result == 0
        except Exception:
            return False

    def ensure_tables_exist(self):
        """Create attendance_commercial_raw and employees_commercial tables if they don't exist"""
        try:
            conn = pymysql.connect(
                host=self.db_config['host'],
                user=self.db_config['user'],
                password=self.db_config['password'],
                database=self.db_config['database'],
                charset='utf8mb4'
            )
            cursor = conn.cursor()
            
            # Create attendance_commercial_raw table
            cursor.execute("""
                CREATE TABLE IF NOT EXISTS attendance_commercial_raw (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    user_id VARCHAR(50),
                    name VARCHAR(255),
                    timestamp DATETIME,
                    date DATE,
                    time TIME,
                    sync_status VARCHAR(50),
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_user_timestamp (user_id, timestamp),
                    INDEX idx_timestamp (timestamp),
                    INDEX idx_date (date)
                )
            """)
            
            # Create employees_commercial table if not exists
            cursor.execute("""
                CREATE TABLE IF NOT EXISTS employees_commercial (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    employee_code VARCHAR(50) UNIQUE,
                    full_name VARCHAR(255),
                    department VARCHAR(100) DEFAULT 'General',
                    is_active TINYINT DEFAULT 1,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_employee_code (employee_code)
                )
            """)
            
            conn.commit()
            print("✅ Tables 'attendance_commercial_raw' and 'employees_commercial' verified/created")
            
            cursor.close()
            conn.close()
            
        except Exception as e:
            print(f"⚠️ Table creation warning: {e}")

    def sync_users_to_mysql(self):
        """Sync users from device to MySQL employees_commercial table"""
        if len(self.users_df) == 0:
            print("❌ No users to sync to MySQL")
            return 0
        
        try:
            # Ensure tables exist
            self.ensure_tables_exist()
            
            conn = pymysql.connect(
                host=self.db_config['host'],
                user=self.db_config['user'],
                password=self.db_config['password'],
                database=self.db_config['database'],
                charset='utf8mb4'
            )
            cursor = conn.cursor()
            
            added = 0
            updated = 0
            
            print("\n🔄 Syncing users to MySQL database...")
            
            for _, row in self.users_df.iterrows():
                user_id = str(row['user_id'])
                name = row['name'].strip() if row['name'] else f"User_{user_id}"
                
                # Escape single quotes in name
                name_escaped = name.replace("'", "\\'")
                
                # Check if employee exists
                cursor.execute("SELECT id, full_name FROM employees_commercial WHERE employee_code = %s", (user_id,))
                existing = cursor.fetchone()
                
                if existing:
                    # Update name if changed and not a generic "User_XXX" name
                    existing_name = existing[1]
                    if name != existing_name and not name.startswith('User_'):
                        cursor.execute(
                            "UPDATE employees_commercial SET full_name = %s WHERE employee_code = %s",
                            (name, user_id)
                        )
                        updated += 1
                        print(f"   📝 Updated: ID {user_id} - {name}")
                else:
                    # Insert new employee
                    cursor.execute(
                        "INSERT INTO employees_commercial (employee_code, full_name, department, is_active) VALUES (%s, %s, 'General', 1)",
                        (user_id, name)
                    )
                    added += 1
                    print(f"   ➕ Added: ID {user_id} - {name}")
            
            conn.commit()
            print(f"\n✅ User sync complete: {added} new employees_commercial added, {updated} updated")
            
            # Get total count
            cursor.execute("SELECT COUNT(*) as count FROM employees_commercial WHERE is_active = 1")
            total = cursor.fetchone()[0]
            print(f"📊 Total active employees_commercial in database: {total}")
            
            cursor.close()
            conn.close()
            return added
            
        except Exception as e:
            print(f"❌ MySQL user sync error: {e}")
            import traceback
            traceback.print_exc()
            return 0

    def sync_to_mysql(self, records):
        """Sync records to MySQL database - adds only new records"""
        if not records:
            print("ℹ️ No records to sync to MySQL")
            return

        try:
            self.ensure_tables_exist()
            
            conn = pymysql.connect(
                host=self.db_config['host'],
                user=self.db_config['user'],
                password=self.db_config['password'],
                database=self.db_config['database'],
                charset='utf8mb4'
            )
            cursor = conn.cursor()

            inserted = 0
            skipped = 0

            for record in records:
                cursor.execute(
                    "SELECT id FROM attendance_commercial_raw WHERE user_id = %s AND timestamp = %s",
                    (record['user_id'], record['timestamp'])
                )

                if cursor.fetchone() is None:
                    sql = """
                        INSERT INTO attendance_commercial_raw
                        (user_id, name, timestamp, date, time, sync_status)
                        VALUES (%s, %s, %s, %s, %s, 'synced')
                    """
                    try:
                        cursor.execute(sql, (
                            record['user_id'],
                            record['name'],
                            record['timestamp'],
                            record['date'],
                            record['time']
                        ))
                        inserted += 1
                    except Exception as e:
                        print(f"⚠️ Error inserting record for user {record['user_id']}: {e}")
                        skipped += 1
                else:
                    skipped += 1

            conn.commit()
            print(f"✅ Synced {inserted} new records to MySQL database (skipped {skipped} duplicates)")

            cursor.close()
            conn.close()

        except Exception as e:
            print(f"❌ MySQL sync error: {e}")

    def fetch_users(self):
        """Fetch and save users, then sync to MySQL"""
        print("\n👥 Fetching users...")

        if not self.test_connection():
            print("❌ Device not reachable. Please check:")
            print(f"   IP: {IP}")
            print(f"   Port: {PORT}")
            print("   Make sure you're on the correct network")
            return False

        zk = ZK(IP, port=PORT, timeout=120, ommit_ping=True)
        c = None

        try:
            print("📡 Connecting to device...")
            c = zk.connect()
            print("✅ Connected to device")

            users = c.get_users()
            print(f"📊 Found {len(users)} users")

            users_list = []
            for u in users:
                users_list.append({
                    'user_id': str(u.user_id),
                    'name': (u.name or f"User_{u.user_id}").strip(),
                    'privilege': 'Admin' if u.privilege == 14 else 'User'
                })

            self.users_df = pd.DataFrame(users_list)
            self.users_df['user_id_num'] = pd.to_numeric(self.users_df['user_id'], errors='coerce')
            self.users_df = self.users_df.sort_values('user_id_num').drop('user_id_num', axis=1)

            self.users_df.to_excel(self.users_file, index=False)
            self.user_map = dict(zip(self.users_df['user_id'], self.users_df['name']))

            print(f"✅ Saved {len(users_list)} users to {self.users_file}")

            print("\n📋 Users (sorted by ID):")
            for _, row in self.users_df.head(10).iterrows():
                print(f"   ID: {row['user_id']:<5} - {row['name']}")
            if len(self.users_df) > 10:
                print(f"   ... and {len(self.users_df)-10} more users")

            # CRITICAL: Sync users to MySQL database
            added_count = self.sync_users_to_mysql()
            
            if added_count > 0:
                print(f"\n🎉 Added {added_count} new users to the dashboard!")
                print("   Refresh your browser to see all users.")
            
            return True

        except Exception as e:
            print(f"❌ Error fetching users: {e}")
            return False
        finally:
            if c:
                try:
                    c.disconnect()
                except Exception:
                    pass

    def process_shifts(self, logs):
        """
        Process raw logs into shifts with proper check-in/check-out
        """
        user_logs = defaultdict(list)
        for log in logs:
            user_logs[str(log.user_id)].append(log)

        all_punches = []
        shift_summaries = []

        for user_id, user_log_list in user_logs.items():
            user_log_list.sort(key=lambda x: x.timestamp)
            name = self.user_map.get(user_id, f"User_{user_id}")

            shifts = defaultdict(list)

            for log in user_log_list:
                log_time = log.timestamp

                if log_time.hour >= SHIFT_GROUPING_START_HOUR:
                    shift_date = log_time.strftime('%Y-%m-%d')
                elif log_time.hour < 12:
                    shift_date = (log_time - timedelta(days=1)).strftime('%Y-%m-%d')
                else:
                    shift_date = log_time.strftime('%Y-%m-%d')

                shifts[shift_date].append(log)

            for shift_date, shift_logs in shifts.items():
                shift_logs.sort(key=lambda x: x.timestamp)

                check_in = shift_logs[0]
                check_out = shift_logs[-1] if len(shift_logs) > 1 else None

                shift_start = datetime(
                    check_in.timestamp.year,
                    check_in.timestamp.month,
                    check_in.timestamp.day,
                    LATE_SHIFT_START_HOUR,
                    0,
                    0
                )

                late_minutes = 0
                if check_in.timestamp > shift_start:
                    late_minutes = int((check_in.timestamp - shift_start).total_seconds() / 60)

                working_hours = 0
                if check_out:
                    out_time = check_out.timestamp
                    in_time = check_in.timestamp
                    if out_time < in_time:
                        out_time = out_time + timedelta(days=1)
                    working_hours = round((out_time - in_time).total_seconds() / 3600, 2)

                shift_summaries.append({
                    'user_id': user_id,
                    'name': name,
                    'shift_date': shift_date,
                    'check_in': check_in.timestamp.strftime('%Y-%m-%d %H:%M:%S'),
                    'check_in_time': check_in.timestamp.strftime('%H:%M'),
                    'check_out': check_out.timestamp.strftime('%Y-%m-%d %H:%M:%S') if check_out else None,
                    'check_out_time': check_out.timestamp.strftime('%H:%M') if check_out else None,
                    'punch_count': len(shift_logs),
                    'late_minutes': late_minutes if late_minutes > LATE_GRACE_MINUTES else 0,
                    'working_hours': working_hours,
                    'status': 'late' if late_minutes > LATE_GRACE_MINUTES else 'present'
                })

                for log in shift_logs:
                    all_punches.append({
                        'user_id': user_id,
                        'name': name,
                        'timestamp': log.timestamp.strftime('%Y-%m-%d %H:%M:%S'),
                        'date': log.timestamp.strftime('%Y-%m-%d'),
                        'time': log.timestamp.strftime('%H:%M:%S')
                    })

        return all_punches, shift_summaries

    def append_to_master_csv(self, all_punches):
        """Append only new punches to master CSV"""
        if not all_punches:
            return 0

        new_df = pd.DataFrame(all_punches)
        new_df['timestamp'] = pd.to_datetime(new_df['timestamp'], errors='coerce')
        new_df = new_df.dropna(subset=['timestamp'])
        new_df['user_id'] = new_df['user_id'].astype(str)

        if new_df.empty:
            return 0

        new_df['key'] = new_df.apply(lambda row: f"{row['user_id']}_{row['timestamp']}", axis=1)
        new_df = new_df[~new_df['key'].isin(self.existing_records)].copy()

        if new_df.empty:
            return 0

        new_df = new_df.drop(columns=['key'])

        if len(self.df) == 0:
            self.df = new_df
        else:
            if not pd.api.types.is_datetime64_any_dtype(self.df['timestamp']):
                self.df['timestamp'] = pd.to_datetime(self.df['timestamp'], errors='coerce')
            self.df = pd.concat([self.df, new_df], ignore_index=True)

        self.df['timestamp'] = pd.to_datetime(self.df['timestamp'], errors='coerce')
        self.df = self.df.dropna(subset=['timestamp'])
        self.df['user_id'] = self.df['user_id'].astype(str)
        self.df['date'] = self.df['timestamp'].dt.strftime('%Y-%m-%d')
        self.df['time'] = self.df['timestamp'].dt.strftime('%H:%M:%S')
        self.df = self.df.sort_values('timestamp')

        self.df.to_csv(self.data_file, index=False)

        for _, row in new_df.iterrows():
            self.existing_records.add(f"{row['user_id']}_{row['timestamp']}")

        return len(new_df)

    def fetch_march_data(self):
        """Fetch ALL attendance data for March 2026"""
        print("\n" + "=" * 60)
        print("📅 FETCHING ALL DATA FOR MARCH 2026")
        print("=" * 60)

        start_date = datetime(2026, 3, 1, 0, 0, 0)
        end_date = datetime(2026, 3, 31, 23, 59, 59)

        print(f"📆 Date range: {start_date.strftime('%Y-%m-%d')} to {end_date.strftime('%Y-%m-%d')}")

        if not self.test_connection():
            print("\n❌ Device not reachable. Please check:")
            print(f"   IP: {IP}")
            print(f"   Port: {PORT}")
            print("   Make sure you're on the correct network")
            return False

        zk = ZK(IP, port=PORT, timeout=300, ommit_ping=True)
        c = None

        try:
            print("\n📡 Connecting to device...")
            c = zk.connect()
            print("✅ CONNECTED")
            
            print("🔒 Disabling device for data fetch...")
            c.disable_device()

            print("\n⏰ Fetching all attendance records from device (this may take several minutes)...")
            all_logs = c.get_attendance()
            print(f"📊 Total records in device: {len(all_logs)}")
            
            print("🔓 Re-enabling device...")
            c.enable_device()

            march_logs = []
            for log in all_logs:
                if start_date <= log.timestamp <= end_date:
                    march_logs.append(log)

            print(f"📋 Records in March 2026: {len(march_logs)}")

            if not march_logs:
                print("\n📭 No records found for March 2026")
                return False

            print("\n🔄 Processing shifts for March 2026...")
            all_punches, shift_summaries = self.process_shifts(march_logs)

            if not all_punches:
                print("\n📭 No punches to process")
                return False

            added_count = self.append_to_master_csv(all_punches)
            print(f"\n✅ Added {added_count} new records to {self.data_file}")

            print("\n🔄 Syncing to MySQL database...")
            self.sync_to_mysql(all_punches)

            march_df = pd.DataFrame(all_punches)
            if not march_df.empty:
                march_df.to_csv("attendance_march_2026_commercial.csv", index=False)
                print("\n📁 March data saved to: attendance_march_2026_commercial.csv")

            if shift_summaries:
                summary_df = pd.DataFrame(shift_summaries)
                summary_df.to_csv("shifts_march_2026_commercial.csv", index=False)
                print("📊 Shift summary saved to: shifts_march_2026_commercial.csv")

                print("\n📋 March 2026 Summary by Date:")
                print("-" * 50)

                date_groups = defaultdict(list)
                for s in shift_summaries:
                    date_groups[s['shift_date']].append(s)

                for date in sorted(date_groups.keys()):
                    shifts = date_groups[date]
                    present_count = len([s for s in shifts if s['status'] == 'present'])
                    late_count = len([s for s in shifts if s['status'] == 'late'])
                    print(f"   {date}: {len(shifts)} employees_commercial (Present: {present_count}, Late: {late_count})")

            self.last_sync = datetime.now()
            self.save_checkpoint()

            print("\n✅ Successfully fetched all March 2026 data!")
            return True

        except Exception as e:
            print(f"\n❌ Error: {e}")
            import traceback
            traceback.print_exc()
            return False
        finally:
            if c:
                try:
                    c.disconnect()
                    print("🔌 Disconnected")
                except Exception:
                    pass

    def fetch_april_data(self):
        """Fetch ALL attendance data for April 2026"""
        print("\n" + "=" * 60)
        print("📅 FETCHING ALL DATA FOR APRIL 2026")
        print("=" * 60)

        start_date = datetime(2026, 4, 1, 0, 0, 0)
        end_date = datetime(2026, 4, 30, 23, 59, 59)

        print(f"📆 Date range: {start_date.strftime('%Y-%m-%d')} to {end_date.strftime('%Y-%m-%d')}")

        if not self.test_connection():
            print("\n❌ Device not reachable. Please check:")
            print(f"   IP: {IP}")
            print(f"   Port: {PORT}")
            print("   Make sure you're on the correct network")
            return False

        zk = ZK(IP, port=PORT, timeout=300, ommit_ping=True)
        c = None

        try:
            print("\n📡 Connecting to device...")
            c = zk.connect()
            print("✅ CONNECTED")
            
            print("🔒 Disabling device for data fetch...")
            c.disable_device()

            print("\n⏰ Fetching all attendance records from device (this may take several minutes)...")
            all_logs = c.get_attendance()
            print(f"📊 Total records in device: {len(all_logs)}")
            
            print("🔓 Re-enabling device...")
            c.enable_device()

            april_logs = []
            for log in all_logs:
                if start_date <= log.timestamp <= end_date:
                    april_logs.append(log)

            print(f"📋 Records in April 2026: {len(april_logs)}")

            if not april_logs:
                print("\n📭 No records found for April 2026")
                return False

            print("\n🔄 Processing shifts for April 2026...")
            all_punches, shift_summaries = self.process_shifts(april_logs)

            if not all_punches:
                print("\n📭 No punches to process")
                return False

            added_count = self.append_to_master_csv(all_punches)
            print(f"\n✅ Added {added_count} new records to {self.data_file}")

            print("\n🔄 Syncing to MySQL database...")
            self.sync_to_mysql(all_punches)

            april_df = pd.DataFrame(all_punches)
            if not april_df.empty:
                april_df.to_csv("attendance_april_2026_commercial.csv", index=False)
                print("\n📁 April data saved to: attendance_april_2026_commercial.csv")

            if shift_summaries:
                summary_df = pd.DataFrame(shift_summaries)
                summary_df.to_csv("shifts_april_2026_commercial.csv", index=False)
                print("📊 Shift summary saved to: shifts_april_2026_commercial.csv")

                print("\n📋 April 2026 Summary by Date:")
                print("-" * 50)

                date_groups = defaultdict(list)
                for s in shift_summaries:
                    date_groups[s['shift_date']].append(s)

                for date in sorted(date_groups.keys()):
                    shifts = date_groups[date]
                    present_count = len([s for s in shifts if s['status'] == 'present'])
                    late_count = len([s for s in shifts if s['status'] == 'late'])
                    print(f"   {date}: {len(shifts)} employees_commercial (Present: {present_count}, Late: {late_count})")

            self.last_sync = datetime.now()
            self.save_checkpoint()

            print("\n✅ Successfully fetched all April 2026 data!")
            return True

        except Exception as e:
            print(f"\n❌ Error: {e}")
            import traceback
            traceback.print_exc()
            return False
        finally:
            if c:
                try:
                    c.disconnect()
                    print("🔌 Disconnected")
                except Exception:
                    pass

    # =====================================================
    # NEW FUNCTION: FETCH ALL MAY 2026 DATA
    # =====================================================
    def fetch_may_data(self):
        """Fetch ALL attendance data for May 2026"""
        print("\n" + "=" * 60)
        print("📅 FETCHING ALL DATA FOR MAY 2026")
        print("=" * 60)

        start_date = datetime(2026, 5, 1, 0, 0, 0)
        end_date = datetime(2026, 5, 31, 23, 59, 59)

        print(f"📆 Date range: {start_date.strftime('%Y-%m-%d')} to {end_date.strftime('%Y-%m-%d')}")

        if not self.test_connection():
            print("\n❌ Device not reachable. Please check:")
            print(f"   IP: {IP}")
            print(f"   Port: {PORT}")
            print("   Make sure you're on the correct network")
            return False

        zk = ZK(IP, port=PORT, timeout=300, ommit_ping=True)
        c = None

        try:
            print("\n📡 Connecting to device...")
            c = zk.connect()
            print("✅ CONNECTED")
            
            print("🔒 Disabling device for data fetch...")
            c.disable_device()

            print("\n⏰ Fetching all attendance records from device (this may take several minutes)...")
            all_logs = c.get_attendance()
            print(f"📊 Total records in device: {len(all_logs)}")
            
            print("🔓 Re-enabling device...")
            c.enable_device()

            may_logs = []
            for log in all_logs:
                if start_date <= log.timestamp <= end_date:
                    may_logs.append(log)

            print(f"📋 Records in May 2026: {len(may_logs)}")

            if not may_logs:
                print("\n📭 No records found for May 2026")
                return False

            print("\n🔄 Processing shifts for May 2026...")
            all_punches, shift_summaries = self.process_shifts(may_logs)

            if not all_punches:
                print("\n📭 No punches to process")
                return False

            added_count = self.append_to_master_csv(all_punches)
            print(f"\n✅ Added {added_count} new records to {self.data_file}")

            print("\n🔄 Syncing to MySQL database...")
            self.sync_to_mysql(all_punches)

            may_df = pd.DataFrame(all_punches)
            if not may_df.empty:
                may_df.to_csv("attendance_may_2026_commercial.csv", index=False)
                print("\n📁 May data saved to: attendance_may_2026_commercial.csv")

            if shift_summaries:
                summary_df = pd.DataFrame(shift_summaries)
                summary_df.to_csv("shifts_may_2026_commercial.csv", index=False)
                print("📊 Shift summary saved to: shifts_may_2026_commercial.csv")

                print("\n📋 May 2026 Summary by Date:")
                print("-" * 50)

                date_groups = defaultdict(list)
                for s in shift_summaries:
                    date_groups[s['shift_date']].append(s)

                for date in sorted(date_groups.keys()):
                    shifts = date_groups[date]
                    present_count = len([s for s in shifts if s['status'] == 'present'])
                    late_count = len([s for s in shifts if s['status'] == 'late'])
                    print(f"   {date}: {len(shifts)} employees_commercial (Present: {present_count}, Late: {late_count})")

            self.last_sync = datetime.now()
            self.save_checkpoint()

            print("\n✅ Successfully fetched all May 2026 data!")
            return True

        except Exception as e:
            print(f"\n❌ Error: {e}")
            import traceback
            traceback.print_exc()
            return False
        finally:
            if c:
                try:
                    c.disconnect()
                    print("🔌 Disconnected")
                except Exception:
                    pass

    def fetch_today_data(self):
        """Fetch today's attendance data only"""
        print("\n" + "=" * 60)
        today_str = datetime.now().strftime('%Y-%m-%d')
        print(f"📅 FETCHING TODAY'S DATA - {today_str}")
        print("=" * 60)

        if not self.test_connection():
            print("\n❌ Device not reachable. Please check:")
            print(f"   IP: {IP}")
            print(f"   Port: {PORT}")
            print("   Make sure you're on the correct network")
            return False

        zk = ZK(IP, port=PORT, timeout=300, ommit_ping=True)
        c = None

        try:
            print("\n📡 Connecting to device...")
            c = zk.connect()
            print("✅ CONNECTED")
            
            # CRITICAL: Disable device before reading attendance
            print("🔒 Disabling device for data fetch...")
            c.disable_device()

            print("\n⏰ Fetching attendance records...")
            all_logs = c.get_attendance()
            print(f"📊 Total records in device: {len(all_logs)}")
            
            # Re-enable device
            print("🔓 Re-enabling device...")
            c.enable_device()

            # Filter for today's records only
            today_logs = []
            for log in all_logs:
                if log.timestamp.date() == datetime.now().date():
                    today_logs.append(log)
            
            print(f"📋 Records for today: {len(today_logs)}")

            if not today_logs:
                print("\n📭 No records found for today")
                return False

            print("\n🔄 Processing today's records...")
            all_punches, shift_summaries = self.process_shifts(today_logs)

            if not all_punches:
                print("\n📭 No punches to process")
                return False

            # Clear duplicate tracking for today before adding
            self.clear_today_duplicates()
            
            added_count = self.append_to_master_csv(all_punches)
            print(f"\n✅ Added {added_count} new records to CSV")

            print("\n🔄 Syncing to MySQL database...")
            self.sync_to_mysql(all_punches)

            if shift_summaries:
                today_shifts = [s for s in shift_summaries if s['shift_date'] == today_str]

                if today_shifts:
                    summary_df = pd.DataFrame(today_shifts)
                    summary_file = f"shift_summary_{today_str}_commercial.csv"
                    summary_df.to_csv(summary_file, index=False)
                    print(f"\n📊 Today's shift summary saved to: {summary_file}")

                    print("\n📋 Today's shifts:")
                    print("-" * 90)
                    print(f"{'ID':<6} {'Name':<25} {'Check In':<12} {'Check Out':<12} {'Hours':<8} {'Status'}")
                    print("-" * 90)

                    for s in sorted(today_shifts, key=lambda x: x['check_in']):
                        check_out = s['check_out_time'] if s['check_out_time'] else '--:--'
                        status = s['status']
                        if s['late_minutes'] > 0:
                            status += f" ({s['late_minutes']}min)"

                        check_out_display = check_out
                        if s['check_out_time']:
                            check_out_display += " ✓"

                        print(f"{s['user_id']:<6} {s['name']:<25} {s['check_in_time']:<12} {check_out_display:<12} {s['working_hours']:<8} {status}")

                    print("-" * 90)
                    print(f"Total employees_commercial present today: {len(today_shifts)}")
                    completed = len([s for s in today_shifts if s['check_out_time']])
                    print(f"Completed shifts (with check-out): {completed}")
                    print(f"Pending check-out: {len(today_shifts) - completed}")

            self.last_sync = datetime.now()
            self.save_checkpoint()

            return True

        except Exception as e:
            print(f"\n❌ Error: {e}")
            import traceback
            traceback.print_exc()
            return False
        finally:
            if c:
                try:
                    c.disconnect()
                    print("🔌 Disconnected")
                except Exception:
                    pass

    def generate_today_report(self):
        """Generate today's report from existing CSV data"""
        today = datetime.now().strftime('%Y-%m-%d')

        print(f"\n📊 GENERATING TODAY'S REPORT FOR {today}")
        print("-" * 60)

        if len(self.users_df) == 0:
            print("❌ No users found")
            return None

        self.users_df['user_id_num'] = pd.to_numeric(self.users_df['user_id'], errors='coerce')
        self.users_df = self.users_df.sort_values('user_id_num')

        if len(self.df) > 0:
            self.df['timestamp'] = pd.to_datetime(self.df['timestamp'], errors='coerce')
            yesterday = (datetime.now() - timedelta(days=1)).strftime('%Y-%m-%d')
            relevant_logs = self.df[(self.df['date'] == today) | (self.df['date'] == yesterday)]
        else:
            relevant_logs = pd.DataFrame()

        class SimpleLog:
            def __init__(self, user_id, timestamp):
                self.user_id = user_id
                self.timestamp = timestamp

        logs = []
        for _, row in relevant_logs.iterrows():
            logs.append(SimpleLog(row['user_id'], row['timestamp']))

        _, shifts = self.process_shifts(logs)
        today_shifts = [s for s in shifts if s['shift_date'] == today]

        report = []
        for _, user in self.users_df.iterrows():
            user_id = user['user_id']
            user_name = user['name']

            user_shift = next((s for s in today_shifts if s['user_id'] == user_id), None)

            if user_shift:
                check_in = user_shift['check_in_time']
                check_out = user_shift['check_out_time'] if user_shift['check_out_time'] else '---'
                hours = f"{user_shift['working_hours']:.2f}" if user_shift['working_hours'] > 0 else '0.00'
                status = 'Present'
                if user_shift['late_minutes'] > 0:
                    status = f"Late ({user_shift['late_minutes']}min)"

                report.append({
                    'user_id': user_id,
                    'name': user_name,
                    'check_in': check_in,
                    'check_out': check_out,
                    'hours': hours,
                    'status': status,
                    'has_check_out': 'Yes' if user_shift['check_out_time'] else 'No'
                })
            else:
                report.append({
                    'user_id': user_id,
                    'name': user_name,
                    'check_in': '---',
                    'check_out': '---',
                    'hours': '0.00',
                    'status': 'Absent',
                    'has_check_out': 'No'
                })

        report_df = pd.DataFrame(report)

        present = len([r for r in report if r['status'] != 'Absent'])
        absent = len([r for r in report if r['status'] == 'Absent'])
        completed = len([r for r in report if r['has_check_out'] == 'Yes'])

        print(f"\n📊 Summary for {today}:")
        print(f"   Present: {present}")
        print(f"   Completed (with check-out): {completed}")
        print(f"   Pending check-out: {present - completed}")
        print(f"   Absent: {absent}")

        total = present + absent
        if total > 0:
            print(f"   Attendance Rate: {(present / total * 100):.1f}%")
        else:
            print("   Attendance Rate: 0.0%")

        return report_df

    def show_data_summary(self):
        """Show summary of existing data"""
        print("\n" + "=" * 60)
        print("📊 DATA SUMMARY")
        print("=" * 60)

        if len(self.df) == 0:
            print("No attendance data available")
            return

        self.df['timestamp'] = pd.to_datetime(self.df['timestamp'], errors='coerce')

        min_date = self.df['date'].min()
        max_date = self.df['date'].max()

        print(f"Total records: {len(self.df)}")
        print(f"Date range: {min_date} to {max_date}")
        print(f"Total users: {len(self.users_df)}")

        print(f"\nLast 7 days of data:")
        for i in range(7):
            date = (datetime.now() - timedelta(days=i)).strftime('%Y-%m-%d')
            count = len(self.df[self.df['date'] == date])
            if count > 0:
                print(f"   {date}: {count} records")
            else:
                print(f"   {date}: No data")

    def auto_sync_loop(self, minutes=AUTO_SYNC_MINUTES):
        """Auto sync every X minutes"""
        print("\n" + "=" * 60)
        print(f"⏱️ AUTO SYNC MODE STARTED - EVERY {minutes} MINUTES")
        print("Press Ctrl + C to stop")
        print("=" * 60)

        while True:
            try:
                print(f"\n🚀 Auto sync started at {datetime.now().strftime('%Y-%m-%d %H:%M:%S')}")
                success = self.fetch_today_data()

                if success:
                    print("✅ Auto sync completed successfully")

                    report = self.generate_today_report()
                    if report is not None:
                        filename = f"daily_report_{datetime.now().strftime('%Y%m%d')}_commercial.csv"
                        report.to_csv(filename, index=False)
                        print(f"✅ Report saved to: {filename}")
                else:
                    print("⚠️ Auto sync finished but no new data or sync failed")

                next_run = datetime.now() + timedelta(minutes=minutes)
                print(f"⏳ Next auto sync at: {next_run.strftime('%Y-%m-%d %H:%M:%S')}")
                time.sleep(minutes * 60)

            except KeyboardInterrupt:
                print("\n🛑 Auto sync stopped by user")
                break
            except Exception as e:
                print(f"\n❌ Auto sync error: {e}")
                print(f"⏳ Retrying in {minutes} minutes...")
                time.sleep(minutes * 60)


def main():
    tracker = DailyAttendanceTracker()

    if len(tracker.users_df) == 0:
        print("\n📌 First time setup - Fetching users...")
        tracker.fetch_users()

    while True:
        print("\n" + "=" * 60)
        print("🏢 DAILY ATTENDANCE TRACKER - CORRECT IN/OUT HANDLING")
        print("=" * 60)
        print("1. Fetch Users (if not already loaded)")
        print("2. FETCH TODAY'S DATA (with proper shift grouping)")
        print("3. Generate Today's Report")
        print("4. Show Data Summary")
        print("5. Export Raw Data (CSV)")
        print("6. FETCH ALL MARCH 2026 DATA")
        print("7. START AUTO SYNC (every 15 minutes)")
        print("8. FETCH ALL APRIL 2026 DATA")
        print("9. FETCH ALL MAY 2026 DATA")      # NEW: Added May 2026 option
        print("10. Exit")
        print("=" * 60)

        choice = input("\nSelect option (1-10): ").strip()

        if choice == '1':
            tracker.fetch_users()

        elif choice == '2':
            print("\n📅 FETCHING TODAY'S DATA WITH PROPER SHIFT GROUPING...")
            print("⚠️ This may take a few minutes...")
            success = tracker.fetch_today_data()
            if success:
                print("\n✅ Successfully fetched today's data!")
                report = tracker.generate_today_report()
                if report is not None:
                    filename = f"daily_report_{datetime.now().strftime('%Y%m%d')}_commercial.csv"
                    report.to_csv(filename, index=False)
                    print(f"\n✅ Report saved to: {filename}")
            else:
                print("\n❌ Failed to fetch today's data")

        elif choice == '3':
            report = tracker.generate_today_report()
            if report is not None:
                filename = f"daily_report_{datetime.now().strftime('%Y%m%d')}_commercial.csv"
                report.to_csv(filename, index=False)
                print(f"\n✅ Report saved to: {filename}")

        elif choice == '4':
            tracker.show_data_summary()

        elif choice == '5':
            if len(tracker.df) > 0:
                filename = f"raw_attendance_{datetime.now().strftime('%Y%m%d_%H%M%S')}_commercial.csv"
                tracker.df.to_csv(filename, index=False)
                print(f"\n✅ Saved {len(tracker.df)} records to {filename}")
            else:
                print("❌ No data to export")

        elif choice == '6':
            print("\n📅 FETCHING ALL MARCH 2026 DATA...")
            print("⚠️ This will fetch all attendance records from March 1 to March 31, 2026")
            print("⚠️ This may take several minutes depending on the amount of data")

            confirm = input("\nAre you sure you want to proceed? (yes/no): ").strip().lower()
            if confirm in ('yes', 'y'):
                success = tracker.fetch_march_data()
                if success:
                    print("\n✅ Successfully fetched all March 2026 data!")
                    print("📁 Files created:")
                    print("   - attendance_march_2026_commercial.csv (all raw punches)")
                    print("   - shifts_march_2026_commercial.csv (shift summaries)")
                    print("   - Data also added to attendance_master_commercial.csv and MySQL")
                else:
                    print("\n❌ Failed to fetch March 2026 data")
            else:
                print("Operation cancelled")

        elif choice == '7':
            tracker.auto_sync_loop(AUTO_SYNC_MINUTES)

        elif choice == '8':
            print("\n📅 FETCHING ALL APRIL 2026 DATA...")
            print("⚠️ This will fetch all attendance records from April 1 to April 30, 2026")
            print("⚠️ This may take several minutes depending on the amount of data")

            confirm = input("\nAre you sure you want to proceed? (yes/no): ").strip().lower()
            if confirm in ('yes', 'y'):
                success = tracker.fetch_april_data()
                if success:
                    print("\n✅ Successfully fetched all April 2026 data!")
                    print("📁 Files created:")
                    print("   - attendance_april_2026_commercial.csv (all raw punches)")
                    print("   - shifts_april_2026_commercial.csv (shift summaries)")
                    print("   - Data also added to attendance_master_commercial.csv and MySQL")
                else:
                    print("\n❌ Failed to fetch April 2026 data")
            else:
                print("Operation cancelled")

        # =====================================================
        # NEW: MAY 2026 OPTION
        # =====================================================
        elif choice == '9':
            print("\n📅 FETCHING ALL MAY 2026 DATA...")
            print("⚠️ This will fetch all attendance records from May 1 to May 31, 2026")
            print("⚠️ This may take several minutes depending on the amount of data")

            confirm = input("\nAre you sure you want to proceed? (yes/no): ").strip().lower()
            if confirm in ('yes', 'y'):
                success = tracker.fetch_may_data()
                if success:
                    print("\n✅ Successfully fetched all May 2026 data!")
                    print("📁 Files created:")
                    print("   - attendance_may_2026_commercial.csv (all raw punches)")
                    print("   - shifts_may_2026_commercial.csv (shift summaries)")
                    print("   - Data also added to attendance_master_commercial.csv and MySQL")
                else:
                    print("\n❌ Failed to fetch May 2026 data")
            else:
                print("Operation cancelled")

        elif choice == '10':
            print("\n👋 Goodbye!")
            break

        else:
            print("❌ Invalid option")


if __name__ == "__main__":
    main()
