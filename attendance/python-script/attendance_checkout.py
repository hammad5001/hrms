#!/usr/bin/env python
# -*- coding: utf-8 -*-

from zk import ZK
import csv
import pandas as pd
from datetime import datetime, timedelta
import time
import os
import socket
import pymysql
from pathlib import Path
from collections import defaultdict

IP = "103.189.232.7"
PORT = 4730
START_DATE = datetime(2026, 1, 1)  # January 1, 2026

class DailyAttendanceTracker:
    def __init__(self):
        print(f"🔌 Initializing Attendance Tracker...")
        self.data_file = "attendance_master.csv"
        self.users_file = "all_users.xlsx"
        self.checkpoint_file = "last_sync.txt"
        self.consolidated_report = "complete_attendance_report.xlsx"
        
        # MySQL connection
        self.db_config = {
            'host': 'localhost',
            'user': 'root',
            'password': '',
            'database': 'balitech_attendance',
            'charset': 'utf8mb4'
        }
        
        self.load_existing_data()
        
    def load_existing_data(self):
        """Load existing data to avoid duplicates"""
        # Load users if exist
        if os.path.exists(self.users_file):
            self.users_df = pd.read_excel(self.users_file)
            self.users_df['user_id'] = self.users_df['user_id'].astype(str)
            self.user_map = dict(zip(self.users_df['user_id'], self.users_df['name']))
            print(f"📂 Loaded {len(self.users_df)} users from {self.users_file}")
        else:
            self.user_map = {}
            self.users_df = pd.DataFrame()
        
        # Load attendance data
        if os.path.exists(self.data_file):
            self.df = pd.read_csv(self.data_file)
            # Convert timestamp to datetime with consistent format
            self.df['timestamp'] = pd.to_datetime(self.df['timestamp'])
            self.df['user_id'] = self.df['user_id'].astype(str)
            
            # Filter out future dates
            current_time = datetime.now()
            self.df = self.df[self.df['timestamp'] <= current_time]
            
            # Create set of existing records (user_id + timestamp) for better duplicate detection
            self.existing_records = set()
            for _, row in self.df.iterrows():
                key = f"{row['user_id']}_{row['timestamp']}"
                self.existing_records.add(key)
            
            print(f"📂 Loaded {len(self.df)} existing attendance records")
            
            # Get last sync time
            if os.path.exists(self.checkpoint_file):
                with open(self.checkpoint_file, 'r') as f:
                    last_sync_str = f.read().strip()
                    self.last_sync = datetime.strptime(last_sync_str, '%Y-%m-%d %H:%M:%S')
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
    
    def test_connection(self):
        """Test if device is reachable"""
        try:
            sock = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
            sock.settimeout(5)
            result = sock.connect_ex((IP, PORT))
            sock.close()
            return result == 0
        except:
            return False
    
    def sync_to_mysql(self, records):
        """Sync records to MySQL database - IMPROVED: doesn't delete, just adds new"""
        if not records:
            return
        
        try:
            # Connect to MySQL
            conn = pymysql.connect(
                host=self.db_config['host'],
                user=self.db_config['user'],
                password=self.db_config['password'],
                database=self.db_config['database'],
                charset='utf8mb4'
            )
            cursor = conn.cursor()
            
            # Insert new records (don't delete existing ones)
            inserted = 0
            skipped = 0
            
            for record in records:
                # Check if record already exists
                cursor.execute(
                    "SELECT id FROM attendance_raw WHERE user_id = %s AND timestamp = %s",
                    (record['user_id'], record['timestamp'])
                )
                
                if cursor.fetchone() is None:
                    sql = """INSERT INTO attendance_raw 
                            (user_id, name, timestamp, date, time, sync_status) 
                            VALUES (%s, %s, %s, %s, %s, 'synced')"""
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
        """Fetch and save users"""
        print("\n👥 Fetching users...")
        
        # Test connection first
        if not self.test_connection():
            print("❌ Device not reachable. Please check:")
            print(f"   IP: {IP}")
            print(f"   Port: {PORT}")
            print("   Make sure you're on the correct network")
            return False
        
        # Use longer timeout for users
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
            
            # Sort by user_id numerically
            self.users_df = pd.DataFrame(users_list)
            self.users_df['user_id_num'] = pd.to_numeric(self.users_df['user_id'])
            self.users_df = self.users_df.sort_values('user_id_num').drop('user_id_num', axis=1)
            
            # Save to Excel
            self.users_df.to_excel(self.users_file, index=False)
            self.user_map = dict(zip(self.users_df['user_id'], self.users_df['name']))
            
            print(f"✅ Saved {len(users_list)} users to {self.users_file}")
            
            # Show sample
            print("\n📋 Users (sorted by ID):")
            for _, row in self.users_df.head(10).iterrows():
                print(f"   ID: {row['user_id']:<5} - {row['name']}")
            if len(self.users_df) > 10:
                print(f"   ... and {len(self.users_df)-10} more users")
            
            return True
            
        except Exception as e:
            print(f"❌ Error fetching users: {e}")
            return False
        finally:
            if c:
                try:
                    c.disconnect()
                except:
                    pass
    
    def process_shifts(self, logs):
        """
        Process raw logs into shifts with proper check-in/check-out
        """
        # Group logs by user
        user_logs = defaultdict(list)
        for log in logs:
            user_logs[str(log.user_id)].append(log)
        
        all_punches = []
        shift_summaries = []
        
        for user_id, user_log_list in user_logs.items():
            # Sort logs by time
            user_log_list.sort(key=lambda x: x.timestamp)
            
            # Get user name
            name = self.user_map.get(user_id, f"User_{user_id}")
            
            # Group into shifts (2PM to 11:59AM next day)
            shifts = defaultdict(list)
            
            for log in user_log_list:
                log_time = log.timestamp
                
                # Determine shift date
                if log_time.hour >= 14:  # After 2PM - belongs to current day's shift
                    shift_date = log_time.strftime('%Y-%m-%d')
                elif log_time.hour < 12:  # Before noon - belongs to previous day's shift
                    shift_date = (log_time - timedelta(days=1)).strftime('%Y-%m-%d')
                else:  # 12PM-2PM - belongs to current day's shift (early check-in)
                    shift_date = log_time.strftime('%Y-%m-%d')
                
                shifts[shift_date].append(log)
            
            # Process each shift
            for shift_date, shift_logs in shifts.items():
                shift_logs.sort(key=lambda x: x.timestamp)
                
                # First punch is check-in
                check_in = shift_logs[0]
                
                # Last punch is check-out (if more than one punch)
                check_out = shift_logs[-1] if len(shift_logs) > 1 else None
                
                # Calculate late minutes (compare to 7:00 PM)
                shift_start = datetime(check_in.timestamp.year, 
                                     check_in.timestamp.month, 
                                     check_in.timestamp.day, 19, 0, 0)
                late_minutes = 0
                if check_in.timestamp > shift_start:
                    late_minutes = int((check_in.timestamp - shift_start).total_seconds() / 60)
                
                # Calculate working hours
                working_hours = 0
                if check_out:
                    out_time = check_out.timestamp
                    in_time = check_in.timestamp
                    if out_time < in_time:  # Next day
                        out_time = out_time + timedelta(days=1)
                    working_hours = round((out_time - in_time).total_seconds() / 3600, 2)
                
                # Store shift summary
                shift_summaries.append({
                    'user_id': user_id,
                    'name': name,
                    'shift_date': shift_date,
                    'check_in': check_in.timestamp.strftime('%Y-%m-%d %H:%M:%S'),
                    'check_in_time': check_in.timestamp.strftime('%H:%M'),
                    'check_out': check_out.timestamp.strftime('%Y-%m-%d %H:%M:%S') if check_out else None,
                    'check_out_time': check_out.timestamp.strftime('%H:%M') if check_out else None,
                    'punch_count': len(shift_logs),
                    'late_minutes': late_minutes if late_minutes > 15 else 0,
                    'working_hours': working_hours,
                    'status': 'late' if late_minutes > 15 else 'present'
                })
                
                # Store individual punches
                for log in shift_logs:
                    all_punches.append({
                        'user_id': user_id,
                        'name': name,
                        'timestamp': log.timestamp.strftime('%Y-%m-%d %H:%M:%S'),
                        'date': log.timestamp.strftime('%Y-%m-%d'),
                        'time': log.timestamp.strftime('%H:%M:%S')
                    })
        
        return all_punches, shift_summaries
    
    # ==================== NEW FUNCTION ADDED ====================
    def fetch_date_range(self, start_date, end_date):
        """
        Fetch attendance data for a specific date range
        Example: fetch_date_range('2026-03-01', '2026-03-18')
        """
        print("\n" + "=" * 60)
        print(f"📅 FETCHING DATA FROM {start_date} TO {end_date}")
        print("=" * 60)
        
        # Convert string dates to datetime
        try:
            start_dt = datetime.strptime(start_date, '%Y-%m-%d')
            end_dt = datetime.strptime(end_date + ' 23:59:59', '%Y-%m-%d %H:%M:%S')
        except ValueError as e:
            print(f"❌ Invalid date format: {e}")
            return False
        
        # Test connection first
        if not self.test_connection():
            print("\n❌ Device not reachable. Please check:")
            print(f"   IP: {IP}")
            print(f"   Port: {PORT}")
            print("   Make sure you're on the correct network")
            return False
        
        # Use longer timeout for large data transfer
        zk = ZK(IP, port=PORT, timeout=300, ommit_ping=True)
        c = None
        
        try:
            print("\n📡 Connecting to device...")
            c = zk.connect()
            print("✅ CONNECTED")
            
            print("\n⏰ Fetching all attendance records (this may take a moment)...")
            
            # Get all attendance
            all_logs = c.get_attendance()
            print(f"📊 Total in device: {len(all_logs)}")
            
            # Filter logs by date range
            range_logs = []
            for log in all_logs:
                if start_dt <= log.timestamp <= end_dt:
                    range_logs.append(log)
            
            print(f"📋 Records in date range: {len(range_logs)}")
            
            if not range_logs:
                print("\n📭 No records found in the specified date range")
                return False
            
            # Process into shifts
            print("\n🔄 Processing shifts...")
            all_punches, shift_summaries = self.process_shifts(range_logs)
            
            if not all_punches:
                print("\n📭 No punches to process")
                return False
            
            # Convert to DataFrame
            new_df = pd.DataFrame(all_punches)
            
            # Remove duplicates
            new_df = new_df[~new_df.apply(lambda row: 
                f"{row['user_id']}_{row['timestamp']}" in self.existing_records, axis=1)]
            
            if len(new_df) == 0:
                print("\n📭 No new punches to add")
                return True
            
            # Append to master file
            if len(self.df) == 0:
                self.df = new_df
            else:
                self.df = pd.concat([self.df, new_df], ignore_index=True)
            
            # Sort by timestamp
            self.df = self.df.sort_values('timestamp')
            
            # Save to CSV
            self.df.to_csv(self.data_file, index=False)
            print(f"\n✅ Added {len(new_df)} new records to CSV")
            
            # Update existing records set
            for _, row in new_df.iterrows():
                self.existing_records.add(f"{row['user_id']}_{row['timestamp']}")
            
            # SYNC TO MYSQL
            print("\n🔄 Syncing to MySQL database...")
            self.sync_to_mysql(new_df.to_dict('records'))
            
            # Save date range summary
            if shift_summaries:
                # Filter shifts within date range
                range_shifts = [s for s in shift_summaries 
                              if start_date <= s['shift_date'] <= end_date]
                
                if range_shifts:
                    summary_df = pd.DataFrame(range_shifts)
                    summary_file = f"shifts_{start_date}_to_{end_date}.csv"
                    summary_df.to_csv(summary_file, index=False)
                    print(f"\n📊 Shift summary saved to: {summary_file}")
                    
                    # Count by date
                    from collections import Counter
                    date_counts = Counter([s['shift_date'] for s in range_shifts])
                    print(f"\n📋 Records by date:")
                    for date in sorted(date_counts.keys()):
                        print(f"   {date}: {date_counts[date]} employees")
            
            # Update last sync
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
                except:
                    pass
    
    def fetch_today_data(self):
        """Fetch ONLY today's attendance data - WITH PROPER SHIFT PROCESSING"""
        print("\n" + "=" * 60)
        today_str = datetime.now().strftime('%Y-%m-%d')
        print(f"📅 FETCHING TODAY'S DATA - {today_str}")
        print("=" * 60)
        
        # Test connection first
        if not self.test_connection():
            print("\n❌ Device not reachable. Please check:")
            print(f"   IP: {IP}")
            print(f"   Port: {PORT}")
            print("   Make sure you're on the correct network")
            return False
        
        # Use MUCH longer timeout for large data transfer
        zk = ZK(IP, port=PORT, timeout=300, ommit_ping=True)  # 5 minute timeout
        c = None
        
        try:
            print("\n📡 Connecting to device...")
            c = zk.connect()
            print("✅ CONNECTED")
            
            print("\n⏰ Fetching attendance records (this may take a moment)...")
            
            # Get all attendance
            all_logs = c.get_attendance()
            print(f"📊 Total in device: {len(all_logs)}")
            
            # Filter out future dates
            current_time = datetime.now()
            valid_logs = [log for log in all_logs if log.timestamp <= current_time]
            
            # Get today and yesterday (for night shifts)
            today_start = current_time.replace(hour=0, minute=0, second=0, microsecond=0)
            yesterday_start = today_start - timedelta(days=1)
            
            # Filter logs from yesterday and today
            recent_logs = [log for log in valid_logs 
                          if log.timestamp >= yesterday_start]
            
            print(f"📋 Recent records (last 48h): {len(recent_logs)}")
            
            if not recent_logs:
                print("\n📭 No recent records found")
                return False
            
            # Process into shifts
            print("\n🔄 Processing shifts...")
            all_punches, shift_summaries = self.process_shifts(recent_logs)
            
            if not all_punches:
                print("\n📭 No punches to process")
                return False
            
            # Convert to DataFrame
            new_df = pd.DataFrame(all_punches)
            
            # Remove duplicates
            new_df = new_df[~new_df.apply(lambda row: 
                f"{row['user_id']}_{row['timestamp']}" in self.existing_records, axis=1)]
            
            if len(new_df) == 0:
                print("\n📭 No new punches to add")
                return True
            
            # Append to master file
            if len(self.df) == 0:
                self.df = new_df
            else:
                self.df = pd.concat([self.df, new_df], ignore_index=True)
            
            # Sort by timestamp
            self.df = self.df.sort_values('timestamp')
            
            # Save to CSV
            self.df.to_csv(self.data_file, index=False)
            print(f"\n✅ Added {len(new_df)} new records to CSV")
            
            # Update existing records set
            for _, row in new_df.iterrows():
                self.existing_records.add(f"{row['user_id']}_{row['timestamp']}")
            
            # SYNC TO MYSQL
            print("\n🔄 Syncing to MySQL database...")
            self.sync_to_mysql(new_df.to_dict('records'))
            
            # Save shift summary
            if shift_summaries:
                # Filter for today's shifts only
                today_shifts = [s for s in shift_summaries if s['shift_date'] == today_str]
                
                if today_shifts:
                    summary_df = pd.DataFrame(today_shifts)
                    summary_file = f"shift_summary_{today_str}.csv"
                    summary_df.to_csv(summary_file, index=False)
                    print(f"\n📊 Today's shift summary saved to: {summary_file}")
                    
                    # Display today's shifts
                    print("\n📋 Today's shifts:")
                    print("-" * 90)
                    print(f"{'ID':<6} {'Name':<25} {'Check In':<12} {'Check Out':<12} {'Hours':<8} {'Status'}")
                    print("-" * 90)
                    
                    for s in sorted(today_shifts, key=lambda x: x['check_in']):
                        check_out = s['check_out_time'] if s['check_out_time'] else '--:--'
                        status = s['status']
                        if s['late_minutes'] > 0:
                            status += f" ({s['late_minutes']}min)"
                        
                        # Check-out indicator
                        check_out_display = check_out
                        if s['check_out_time']:
                            check_out_display += " ✓"
                        
                        print(f"{s['user_id']:<6} {s['name']:<25} {s['check_in_time']:<12} {check_out_display:<12} {s['working_hours']:<8} {status}")
                    
                    print("-" * 90)
                    print(f"Total employees present today: {len(today_shifts)}")
                    
                    # Count completed shifts (have check-out)
                    completed = len([s for s in today_shifts if s['check_out_time']])
                    print(f"Completed shifts (with check-out): {completed}")
                    print(f"Pending check-out: {len(today_shifts) - completed}")
            
            # Update last sync
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
                except:
                    pass
    
    def generate_today_report(self):
        """Generate today's report from existing data"""
        today = datetime.now().strftime('%Y-%m-%d')
        
        print(f"\n📊 GENERATING TODAY'S REPORT FOR {today}")
        print("-" * 60)
        
        if len(self.users_df) == 0:
            print("❌ No users found")
            return None
        
        # Sort users by ID
        self.users_df['user_id_num'] = pd.to_numeric(self.users_df['user_id'])
        self.users_df = self.users_df.sort_values('user_id_num')
        
        # Get all logs from today and yesterday
        if len(self.df) > 0:
            self.df['timestamp'] = pd.to_datetime(self.df['timestamp'])
            yesterday = (datetime.now() - timedelta(days=1)).strftime('%Y-%m-%d')
            relevant_logs = self.df[(self.df['date'] == today) | (self.df['date'] == yesterday)]
        else:
            relevant_logs = pd.DataFrame()
        
        # Convert to log objects for processing
        class SimpleLog:
            def __init__(self, user_id, timestamp):
                self.user_id = user_id
                self.timestamp = timestamp
        
        logs = []
        for _, row in relevant_logs.iterrows():
            logs.append(SimpleLog(row['user_id'], row['timestamp']))
        
        # Process shifts
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
        
        # Show summary
        present = len([r for r in report if r['status'] != 'Absent'])
        absent = len([r for r in report if r['status'] == 'Absent'])
        completed = len([r for r in report if r['has_check_out'] == 'Yes'])
        
        print(f"\n📊 Summary for {today}:")
        print(f"   Present: {present}")
        print(f"   Completed (with check-out): {completed}")
        print(f"   Pending check-out: {present - completed}")
        print(f"   Absent: {absent}")
        print(f"   Attendance Rate: {(present/(present+absent)*100):.1f}%")
        
        return report_df
    
    def show_data_summary(self):
        """Show summary of existing data"""
        print("\n" + "=" * 60)
        print("📊 DATA SUMMARY")
        print("=" * 60)
        
        if len(self.df) == 0:
            print("No attendance data available")
            return
        
        # Ensure timestamps are datetime
        self.df['timestamp'] = pd.to_datetime(self.df['timestamp'])
        
        # Get date range
        min_date = self.df['date'].min()
        max_date = self.df['date'].max()
        
        print(f"Total records: {len(self.df)}")
        print(f"Date range: {min_date} to {max_date}")
        print(f"Total users: {len(self.users_df)}")
        
        # Count by date (last 7 days)
        print(f"\nLast 7 days of data:")
        today = datetime.now().strftime('%Y-%m-%d')
        for i in range(7):
            date = (datetime.now() - timedelta(days=i)).strftime('%Y-%m-%d')
            count = len(self.df[self.df['date'] == date])
            if count > 0:
                print(f"   {date}: {count} records")
            else:
                print(f"   {date}: No data")

def main():
    tracker = DailyAttendanceTracker()
    
    # Check if users exist, if not fetch them
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
        print("6. FETCH DATE RANGE (e.g., March 1 to today)")  # NEW OPTION
        print("7. Exit")
        print("=" * 60)
        
        choice = input("\nSelect option (1-7): ").strip()
        
        if choice == '1':
            tracker.fetch_users()
            
        elif choice == '2':
            print(f"\n📅 FETCHING TODAY'S DATA WITH PROPER SHIFT GROUPING...")
            print("⚠️ This may take a few minutes...")
            success = tracker.fetch_today_data()
            if success:
                print("\n✅ Successfully fetched today's data!")
                report = tracker.generate_today_report()
                if report is not None:
                    filename = f"daily_report_{datetime.now().strftime('%Y%m%d')}.csv"
                    report.to_csv(filename, index=False)
                    print(f"\n✅ Report saved to: {filename}")
            else:
                print("\n❌ Failed to fetch today's data")
            
        elif choice == '3':
            report = tracker.generate_today_report()
            if report is not None:
                filename = f"daily_report_{datetime.now().strftime('%Y%m%d')}.csv"
                report.to_csv(filename, index=False)
                print(f"\n✅ Report saved to: {filename}")
            
        elif choice == '4':
            tracker.show_data_summary()
            
        elif choice == '5':
            if len(tracker.df) > 0:
                filename = f"raw_attendance_{datetime.now().strftime('%Y%m%d_%H%M%S')}.csv"
                tracker.df.to_csv(filename, index=False)
                print(f"\n✅ Saved {len(tracker.df)} records to {filename}")
            else:
                print("❌ No data to export")
        
        # NEW OPTION: Fetch date range
        elif choice == '6':
            print("\n📅 FETCH DATA BY DATE RANGE")
            print("-" * 40)
            start_date = input("Enter start date (YYYY-MM-DD) [default: 2026-03-01]: ").strip()
            if not start_date:
                start_date = "2026-03-01"
            
            end_date = input("Enter end date (YYYY-MM-DD) [default: today]: ").strip()
            if not end_date:
                end_date = datetime.now().strftime('%Y-%m-%d')
            
            print(f"\n📊 Fetching data from {start_date} to {end_date}...")
            print("⚠️ This may take several minutes...")
            success = tracker.fetch_date_range(start_date, end_date)
            if success:
                print("\n✅ Successfully fetched date range data!")
            else:
                print("\n❌ Failed to fetch date range data")
            
        elif choice == '7':
            print("\n👋 Goodbye!")
            break
        else:
            print("❌ Invalid option")

if __name__ == "__main__":
    main()