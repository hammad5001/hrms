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


# ============================================================
# DEVICES CONFIG
# ============================================================

DEVICES = [
    {
        "name": "Main Branch",
        "slug": "main_branch",
        "ip": "203.215.161.244",
        "port": 4730
    },
    {
        "name": "Commercial Branch",
        "slug": "commercial_branch",
        "ip": "125.209.68.118",
        "port": 4370
    }
]

START_DATE = datetime(2026, 1, 1)
AUTO_SYNC_MINUTES = 15

SHIFT_GROUPING_START_HOUR = 14
LATE_SHIFT_START_HOUR = 18
LATE_GRACE_MINUTES = 10


class DailyAttendanceTracker:
    def __init__(self):
        print("🔌 Initializing Attendance Tracker - Separate Device Mode...")

        self.db_config = {
            'host': 'localhost',
            'user': 'root',
            'password': '',
            'database': 'balitech',
            'charset': 'utf8mb4'
        }

    # ============================================================
    # DEVICE HELPERS
    # ============================================================

    def get_device_files(self, device):
        slug = device["slug"]

        return {
            "data_file": f"attendance_master_{slug}.csv",
            "users_file": f"all_users_{slug}.xlsx",
            "checkpoint_file": f"last_sync_{slug}.txt"
        }

    def test_connection(self, device):
        try:
            sock = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
            sock.settimeout(10)
            result = sock.connect_ex((device["ip"], device["port"]))
            sock.close()
            return result == 0
        except Exception:
            return False

    def connect_device(self, device, timeout=300):
        zk = ZK(
            device["ip"],
            port=device["port"],
            timeout=timeout,
            ommit_ping=True
        )
        return zk.connect()

    def select_device_menu(self):
        print("\n📍 Select Device")
        print("1. Main Branch")
        print("2. Commercial Branch")

        choice = input("\nSelect option (1-2): ").strip()

        if choice == "1":
            return DEVICES[0]
        elif choice == "2":
            return DEVICES[1]
        else:
            print("❌ Invalid device option")
            return None

    # ============================================================
    # LOAD DEVICE DATA
    # ============================================================

    def load_device_data(self, device):
        files = self.get_device_files(device)

        data_file = files["data_file"]
        users_file = files["users_file"]
        checkpoint_file = files["checkpoint_file"]

        if os.path.exists(users_file):
            users_df = pd.read_excel(users_file)
            users_df["user_id"] = users_df["user_id"].astype(str)
            print(f"📂 Loaded {len(users_df)} users from {users_file}")
        else:
            users_df = pd.DataFrame(columns=[
                "user_id", "name", "privilege", "device_name", "device_ip"
            ])

        user_map = {}

        if len(users_df) > 0:
            for _, row in users_df.iterrows():
                user_map[str(row["user_id"])] = row["name"]

        if os.path.exists(data_file):
            df = pd.read_csv(data_file)

            if "timestamp" not in df.columns:
                df = pd.DataFrame(columns=[
                    "user_id", "name", "timestamp", "date", "time", "device_name", "device_ip"
                ])

            df["timestamp"] = pd.to_datetime(df["timestamp"], errors="coerce")
            df = df.dropna(subset=["timestamp"])
            df["user_id"] = df["user_id"].astype(str)

            if "name" not in df.columns:
                df["name"] = ""

            if "device_name" not in df.columns:
                df["device_name"] = device["name"]

            if "device_ip" not in df.columns:
                df["device_ip"] = device["ip"]

            current_time = datetime.now()
            df = df[df["timestamp"] <= current_time]

            df["date"] = df["timestamp"].dt.strftime("%Y-%m-%d")
            df["time"] = df["timestamp"].dt.strftime("%H:%M:%S")

            existing_records = set()

            for _, row in df.iterrows():
                existing_records.add(
                    self._record_key(row["user_id"], row["timestamp"])
                )

            print(f"📂 Loaded {len(df)} existing attendance records from {data_file}")
        else:
            df = pd.DataFrame(columns=[
                "user_id", "name", "timestamp", "date", "time", "device_name", "device_ip"
            ])
            existing_records = set()

        if os.path.exists(checkpoint_file):
            with open(checkpoint_file, "r") as f:
                last_sync_str = f.read().strip()
                try:
                    last_sync = datetime.strptime(last_sync_str, "%Y-%m-%d %H:%M:%S")
                except Exception:
                    last_sync = START_DATE
        else:
            last_sync = START_DATE

        print(f"📅 Last sync for {device['name']}: {last_sync.strftime('%Y-%m-%d %H:%M:%S')}")

        return df, users_df, user_map, existing_records, last_sync

    def save_checkpoint(self, device):
        files = self.get_device_files(device)

        with open(files["checkpoint_file"], "w") as f:
            f.write(datetime.now().strftime("%Y-%m-%d %H:%M:%S"))

    @staticmethod
    def _record_key(user_id, timestamp):
        if hasattr(timestamp, "strftime"):
            ts = timestamp.strftime("%Y-%m-%d %H:%M:%S")
        else:
            ts = str(timestamp).replace("T", " ")[:19]
        return f"{user_id}_{ts}"

    def clear_today_duplicates(self, existing_records):
        """Same as original collector — allow re-fetch of today's punches."""
        today = datetime.now().strftime("%Y-%m-%d")
        to_remove = [key for key in existing_records if today in key]
        for key in to_remove:
            existing_records.discard(key)
        if to_remove:
            print(f"✅ Cleared duplicate tracking for {today}")
        return len(to_remove)

    def _print_today_shift_table(self, today_shifts):
        print("\n📋 Today's shifts:")
        print("-" * 90)
        print(f"{'ID':<6} {'Name':<25} {'Check In':<12} {'Check Out':<12} {'Hours':<8} {'Status'}")
        print("-" * 90)
        for s in sorted(today_shifts, key=lambda x: x["check_in"]):
            check_out = s["check_out_time"] if s["check_out_time"] else "--:--"
            status = s["status"]
            if s["late_minutes"] > 0:
                status += f" ({s['late_minutes']}min)"
            check_out_display = f"{check_out} ✓" if s["check_out_time"] else check_out
            print(
                f"{s['user_id']:<6} {s['name']:<25} {s['check_in_time']:<12} "
                f"{check_out_display:<12} {s['working_hours']:<8} {status}"
            )
        print("-" * 90)
        completed = len([s for s in today_shifts if s["check_out_time"]])
        print(f"Total employees present today: {len(today_shifts)}")
        print(f"Completed shifts (with check-out): {completed}")
        print(f"Pending check-out: {len(today_shifts) - completed}")

    # ============================================================
    # MYSQL SYNC
    # ============================================================

    def get_mysql_tables(self, device):
        if device["slug"] == "commercial_branch":
            return {
                "attendance": "attendance_commercial_raw",
                "employees": "employees_commercial",
            }
        return {
            "attendance": "attendance_raw",
            "employees": "employees",
        }

    def sync_users_to_mysql(self, device, users_list):
        if not users_list:
            return 0

        tables = self.get_mysql_tables(device)

        try:
            conn = pymysql.connect(
                host=self.db_config["host"],
                user=self.db_config["user"],
                password=self.db_config["password"],
                database=self.db_config["database"],
                charset="utf8mb4"
            )
            cursor = conn.cursor()
            added = 0

            for u in users_list:
                user_id = str(u["user_id"])
                name = (u.get("name") or f"User_{user_id}").strip()
                cursor.execute(
                    f"SELECT id FROM {tables['employees']} WHERE employee_code = %s",
                    (user_id,)
                )
                if cursor.fetchone() is None:
                    cursor.execute(
                        f"""
                        INSERT INTO {tables['employees']}
                        (employee_code, full_name, department, branch)
                        VALUES (%s, %s, %s, %s)
                        """,
                        (user_id, name, "General", device["name"])
                    )
                    added += 1

            conn.commit()
            cursor.close()
            conn.close()
            print(f"✅ Synced {added} new users to {tables['employees']} ({device['name']})")
            return added

        except Exception as e:
            print(f"❌ MySQL user sync error: {e}")
            return 0

    def sync_to_mysql(self, device, records):
        if not records:
            print("ℹ️ No records to sync to MySQL")
            return

        tables = self.get_mysql_tables(device)
        attendance_table = tables["attendance"]

        try:
            conn = pymysql.connect(
                host=self.db_config["host"],
                user=self.db_config["user"],
                password=self.db_config["password"],
                database=self.db_config["database"],
                charset="utf8mb4"
            )

            cursor = conn.cursor()

            inserted = 0
            skipped = 0

            for record in records:
                cursor.execute(
                    f"SELECT id FROM {attendance_table} WHERE user_id = %s AND timestamp = %s",
                    (record["user_id"], record["timestamp"])
                )

                if cursor.fetchone() is None:
                    sql = f"""
                        INSERT INTO {attendance_table}
                        (user_id, name, timestamp, date, time, sync_status)
                        VALUES (%s, %s, %s, %s, %s, 'synced')
                    """

                    try:
                        cursor.execute(sql, (
                            record["user_id"],
                            record["name"],
                            record["timestamp"],
                            record["date"],
                            record["time"]
                        ))
                        inserted += 1
                    except Exception as e:
                        print(f"⚠️ Error inserting user {record['user_id']}: {e}")
                        skipped += 1
                else:
                    skipped += 1

            conn.commit()

            print(f"✅ Synced {inserted} new records to {attendance_table} ({device['name']}, skipped {skipped} duplicates)")

            cursor.close()
            conn.close()

        except Exception as e:
            print(f"❌ MySQL sync error: {e}")

    # ============================================================
    # FETCH USERS
    # ============================================================

    def fetch_users_for_device(self, device):
        print("\n" + "=" * 60)
        print(f"👥 FETCHING USERS - {device['name']}")
        print(f"🌐 {device['ip']}:{device['port']}")
        print("=" * 60)

        files = self.get_device_files(device)
        users_file = files["users_file"]

        if not self.test_connection(device):
            print(f"❌ Device not reachable: {device['name']}")
            return False

        c = None

        try:
            print("📡 Connecting to device...")
            c = self.connect_device(device, timeout=120)
            print("✅ Connected to device")

            users = c.get_users()
            print(f"📊 Found {len(users)} users")

            users_list = []

            for u in users:
                users_list.append({
                    "user_id": str(u.user_id),
                    "name": (u.name or f"User_{u.user_id}").strip(),
                    "privilege": "Admin" if u.privilege == 14 else "User",
                    "device_name": device["name"],
                    "device_ip": device["ip"]
                })

            users_df = pd.DataFrame(users_list)
            users_df["user_id_num"] = pd.to_numeric(users_df["user_id"], errors="coerce")
            users_df = users_df.sort_values("user_id_num").drop("user_id_num", axis=1)

            users_df.to_excel(users_file, index=False)

            print(f"✅ Saved {len(users_df)} users to {users_file}")
            self.sync_users_to_mysql(device, users_list)
            return True

        except Exception as e:
            print(f"❌ Error fetching users from {device['name']}: {e}")
            return False

        finally:
            if c:
                try:
                    c.disconnect()
                    print("🔌 Disconnected")
                except Exception:
                    pass

    def fetch_users_all_devices(self):
        success_count = 0

        for device in DEVICES:
            success = self.fetch_users_for_device(device)

            if success:
                success_count += 1

        print("\n" + "=" * 60)
        print(f"✅ Users fetch completed for {success_count}/{len(DEVICES)} devices")
        print("=" * 60)

    # ============================================================
    # LOG NORMALIZE
    # ============================================================

    def normalize_logs(self, raw_logs, device):
        """
        Convert ZK logs to dicts.
        Do NOT filter by PC clock — device clock may be ahead of PC
        (original collector only matched calendar date for today fetch).
        """
        normalized = []
        for log in raw_logs:
            if log.timestamp:
                normalized.append({
                    "user_id": str(log.user_id),
                    "timestamp": log.timestamp,
                    "device_name": device["name"],
                    "device_ip": device["ip"]
                })
        return normalized

    def filter_today_logs(self, raw_logs):
        """Original logic: calendar today only, no future-time filter."""
        today = datetime.now().date()
        today_logs = []
        for log in raw_logs:
            if log.timestamp and log.timestamp.date() == today:
                today_logs.append(log)
        return today_logs

    # ============================================================
    # SHIFT PROCESSING
    # ============================================================

    def process_shifts(self, logs, user_map, device):
        user_logs = defaultdict(list)

        for log in logs:
            user_logs[str(log["user_id"])].append(log)

        all_punches = []
        shift_summaries = []

        for user_id, user_log_list in user_logs.items():
            user_log_list.sort(key=lambda x: x["timestamp"])

            name = user_map.get(str(user_id), f"User_{user_id}")

            shifts = defaultdict(list)

            for log in user_log_list:
                log_time = log["timestamp"]

                if log_time.hour >= SHIFT_GROUPING_START_HOUR:
                    shift_date = log_time.strftime("%Y-%m-%d")
                elif log_time.hour < 12:
                    shift_date = (log_time - timedelta(days=1)).strftime("%Y-%m-%d")
                else:
                    shift_date = log_time.strftime("%Y-%m-%d")

                shifts[shift_date].append(log)

            for shift_date, shift_logs in shifts.items():
                shift_logs.sort(key=lambda x: x["timestamp"])

                check_in = shift_logs[0]
                check_out = shift_logs[-1] if len(shift_logs) > 1 else None

                shift_date_obj = datetime.strptime(shift_date, "%Y-%m-%d")
                shift_start = shift_date_obj.replace(
                    hour=LATE_SHIFT_START_HOUR, minute=0, second=0, microsecond=0
                )

                late_minutes = 0

                if check_in["timestamp"] > shift_start:
                    late_minutes = int((check_in["timestamp"] - shift_start).total_seconds() / 60)

                working_hours = 0

                if check_out:
                    in_time = check_in["timestamp"]
                    out_time = check_out["timestamp"]

                    if out_time < in_time:
                        out_time = out_time + timedelta(days=1)

                    working_hours = round((out_time - in_time).total_seconds() / 3600, 2)

                shift_summaries.append({
                    "user_id": str(user_id),
                    "name": name,
                    "device_name": device["name"],
                    "device_ip": device["ip"],
                    "shift_date": shift_date,
                    "check_in": check_in["timestamp"].strftime("%Y-%m-%d %H:%M:%S"),
                    "check_in_time": check_in["timestamp"].strftime("%H:%M"),
                    "check_out": check_out["timestamp"].strftime("%Y-%m-%d %H:%M:%S") if check_out else None,
                    "check_out_time": check_out["timestamp"].strftime("%H:%M") if check_out else None,
                    "punch_count": len(shift_logs),
                    "late_minutes": late_minutes if late_minutes > LATE_GRACE_MINUTES else 0,
                    "working_hours": working_hours,
                    "status": "late" if late_minutes > LATE_GRACE_MINUTES else "present"
                })

                for log in shift_logs:
                    all_punches.append({
                        "user_id": str(user_id),
                        "name": name,
                        "timestamp": log["timestamp"].strftime("%Y-%m-%d %H:%M:%S"),
                        "date": log["timestamp"].strftime("%Y-%m-%d"),
                        "time": log["timestamp"].strftime("%H:%M:%S"),
                        "device_name": device["name"],
                        "device_ip": device["ip"]
                    })

        return all_punches, shift_summaries

    # ============================================================
    # APPEND DEVICE CSV
    # ============================================================

    def append_to_device_csv(self, device, all_punches, df, existing_records):
        if not all_punches:
            return 0

        files = self.get_device_files(device)
        data_file = files["data_file"]

        new_df = pd.DataFrame(all_punches)
        new_df["timestamp"] = pd.to_datetime(new_df["timestamp"], errors="coerce")
        new_df = new_df.dropna(subset=["timestamp"])
        new_df["user_id"] = new_df["user_id"].astype(str)

        if new_df.empty:
            return 0

        new_df["key"] = new_df.apply(
            lambda row: self._record_key(row["user_id"], row["timestamp"]),
            axis=1
        )

        new_df = new_df[~new_df["key"].isin(existing_records)].copy()

        if new_df.empty:
            print("ℹ️ No new records to add (already in CSV)")
            return 0

        for key in new_df["key"]:
            existing_records.add(key)

        new_df = new_df.drop(columns=["key"])

        if len(df) == 0:
            df = new_df
        else:
            df["timestamp"] = pd.to_datetime(df["timestamp"], errors="coerce")
            df = pd.concat([df, new_df], ignore_index=True)

        df["timestamp"] = pd.to_datetime(df["timestamp"], errors="coerce")
        df = df.dropna(subset=["timestamp"])
        df["user_id"] = df["user_id"].astype(str)
        df["date"] = df["timestamp"].dt.strftime("%Y-%m-%d")
        df["time"] = df["timestamp"].dt.strftime("%H:%M:%S")
        df["device_name"] = device["name"]
        df["device_ip"] = device["ip"]
        df = df.sort_values("timestamp")

        df.to_csv(data_file, index=False)

        return len(new_df)

    # ============================================================
    # FETCH TODAY DEVICE
    # ============================================================

    def fetch_today_for_device(self, device):
        """Fetch today's attendance — same logic as original single-device collector."""
        today_str = datetime.now().strftime("%Y-%m-%d")

        print("\n" + "=" * 60)
        print(f"📅 FETCHING TODAY'S DATA - {today_str}")
        print(f"🏢 {device['name']}  🌐 {device['ip']}:{device['port']}")
        print("=" * 60)

        df, users_df, user_map, existing_records, last_sync = self.load_device_data(device)

        if len(users_df) == 0:
            print("⚠️ No users file found for this device. Fetching users first...")
            self.fetch_users_for_device(device)
            df, users_df, user_map, existing_records, last_sync = self.load_device_data(device)

        if not self.test_connection(device):
            print(f"❌ Device not reachable: {device['name']}")
            return False

        c = None

        try:
            print("\n📡 Connecting to device...")
            c = self.connect_device(device, timeout=300)
            print("✅ CONNECTED")

            print("🔒 Disabling device for data fetch...")
            c.disable_device()

            print("\n⏰ Fetching attendance records...")
            raw_logs = c.get_attendance()
            print(f"📊 Total records in device: {len(raw_logs)}")

            print("🔓 Re-enabling device...")
            c.enable_device()

            today_logs = self.filter_today_logs(raw_logs)
            print(f"📋 Records for today: {len(today_logs)}")

            if not today_logs:
                print("\n📭 No records found for today")
                return False

            print("\n🔄 Processing today's records...")
            normalized_logs = self.normalize_logs(today_logs, device)
            all_punches, shift_summaries = self.process_shifts(
                normalized_logs,
                user_map,
                device
            )

            if not all_punches:
                print("\n📭 No punches to process")
                return False

            self.clear_today_duplicates(existing_records)

            added_count = self.append_to_device_csv(
                device,
                all_punches,
                df,
                existing_records
            )

            print(f"\n✅ Added {added_count} new records to attendance_master_{device['slug']}.csv")

            print("\n🔄 Syncing to MySQL database...")
            self.sync_to_mysql(device, all_punches)

            today_shifts = [
                s for s in shift_summaries
                if s["shift_date"] == today_str
            ]

            if today_shifts:
                summary_file = f"shift_summary_{today_str}_{device['slug']}.csv"
                pd.DataFrame(today_shifts).to_csv(summary_file, index=False)
                print(f"\n📊 Today's shift summary saved to: {summary_file}")
                self._print_today_shift_table(today_shifts)

            self.save_checkpoint(device)
            print("\n✅ Successfully fetched today's data!")
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

    def fetch_today_all_devices(self):
        success_count = 0

        for device in DEVICES:
            success = self.fetch_today_for_device(device)

            if success:
                success_count += 1

        print("\n" + "=" * 60)
        print(f"✅ Today data fetch completed for {success_count}/{len(DEVICES)} devices")
        print("=" * 60)

    # ============================================================
    # FETCH MONTHLY DATA (original collector logic)
    # ============================================================

    def fetch_month_for_device(self, device, year, month):
        import calendar

        month_name = datetime(year, month, 1).strftime("%B")
        slug = device["slug"]
        last_day = calendar.monthrange(year, month)[1]
        start_date = datetime(year, month, 1, 0, 0, 0)
        end_date = datetime(year, month, last_day, 23, 59, 59)

        print("\n" + "=" * 60)
        print(f"📅 FETCHING ALL DATA FOR {month_name.upper()} {year} - {device['name']}")
        print(f"🌐 {device['ip']}:{device['port']}")
        print(f"📆 Range: {start_date.strftime('%Y-%m-%d')} to {end_date.strftime('%Y-%m-%d')}")
        print("=" * 60)

        df, users_df, user_map, existing_records, last_sync = self.load_device_data(device)

        if len(users_df) == 0:
            print("⚠️ No users file found. Fetching users first...")
            self.fetch_users_for_device(device)
            df, users_df, user_map, existing_records, last_sync = self.load_device_data(device)

        if not self.test_connection(device):
            print(f"❌ Device not reachable: {device['name']}")
            return False

        c = None
        try:
            print("\n📡 Connecting to device...")
            c = self.connect_device(device, timeout=300)
            print("✅ CONNECTED")

            print("🔒 Disabling device for data fetch...")
            c.disable_device()

            print("\n⏰ Fetching all attendance records (may take several minutes)...")
            raw_logs = c.get_attendance()
            print(f"📊 Total records in device: {len(raw_logs)}")

            print("🔓 Re-enabling device...")
            c.enable_device()

            month_logs = [
                log for log in raw_logs
                if log.timestamp and start_date <= log.timestamp <= end_date
            ]
            print(f"📋 Records in {month_name} {year}: {len(month_logs)}")

            if not month_logs:
                print(f"\n📭 No records found for {month_name} {year}")
                return False

            print(f"\n🔄 Processing shifts for {month_name} {year}...")
            normalized_logs = self.normalize_logs(month_logs, device)
            all_punches, shift_summaries = self.process_shifts(
                normalized_logs, user_map, device
            )

            if not all_punches:
                print("\n📭 No punches to process")
                return False

            added_count = self.append_to_device_csv(
                device, all_punches, df, existing_records
            )
            print(f"\n✅ Added {added_count} new records to attendance_master_{slug}.csv")

            print("\n🔄 Syncing to MySQL database...")
            self.sync_to_mysql(device, all_punches)

            month_tag = f"{month_name.lower()}_{year}"
            pd.DataFrame(all_punches).to_csv(f"attendance_{month_tag}_{slug}.csv", index=False)
            print(f"📁 Month data saved to: attendance_{month_tag}_{slug}.csv")

            if shift_summaries:
                pd.DataFrame(shift_summaries).to_csv(f"shifts_{month_tag}_{slug}.csv", index=False)
                print(f"📊 Shift summary saved to: shifts_{month_tag}_{slug}.csv")

            self.save_checkpoint(device)
            print(f"\n✅ Successfully fetched all {month_name} {year} data!")
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

    def run_monthly_fetch_menu(self, device):
        print("\n📅 SELECT MONTH TO FETCH")
        print("1. March 2026")
        print("2. April 2026")
        print("3. May 2026")
        print("4. June 2026")
        print("5. Cancel")
        choice = input("\nSelect option (1-5): ").strip()
        months = {
            "1": (2026, 3), "2": (2026, 4), "3": (2026, 5), "4": (2026, 6),
        }
        if choice not in months:
            print("Operation cancelled")
            return
        y, m = months[choice]
        confirm = input(f"\nFetch all {datetime(y,m,1).strftime('%B %Y')} data for {device['name']}? (yes/no): ").strip().lower()
        if confirm in ("yes", "y"):
            self.fetch_month_for_device(device, y, m)

    def export_raw_data_for_device(self, device):
        df, _, _, _, _ = self.load_device_data(device)
        if len(df) == 0:
            print(f"❌ No data to export for {device['name']}")
            return False
        filename = f"raw_attendance_{datetime.now().strftime('%Y%m%d_%H%M%S')}_{device['slug']}.csv"
        df.to_csv(filename, index=False)
        print(f"✅ Exported {len(df)} records to {filename}")
        return True

    # ============================================================
    # GENERATE REPORT
    # ============================================================

    def generate_report_for_device(self, device, report_date=None):
        if report_date is None:
            report_date = datetime.now()

        target_date = report_date.strftime("%Y-%m-%d")

        print("\n" + "=" * 60)
        print(f"📊 GENERATING REPORT - {device['name']} - {target_date}")
        print("=" * 60)

        df, users_df, user_map, existing_records, last_sync = self.load_device_data(device)

        if len(users_df) == 0:
            print("❌ No users found for this device")
            return None

        users_df["user_id"] = users_df["user_id"].astype(str)
        users_df["user_id_num"] = pd.to_numeric(users_df["user_id"], errors="coerce")
        users_df = users_df.sort_values("user_id_num")

        if len(df) > 0:
            df["timestamp"] = pd.to_datetime(df["timestamp"], errors="coerce")
            df["date"] = df["timestamp"].dt.strftime("%Y-%m-%d")

            previous_day = (report_date - timedelta(days=1)).strftime("%Y-%m-%d")

            relevant_df = df[
                (df["date"] == target_date) |
                (df["date"] == previous_day)
            ]

            logs = []

            for _, row in relevant_df.iterrows():
                logs.append({
                    "user_id": str(row["user_id"]),
                    "timestamp": row["timestamp"],
                    "device_name": device["name"],
                    "device_ip": device["ip"]
                })
        else:
            logs = []

        _, shifts = self.process_shifts(logs, user_map, device)

        target_shifts = [
            s for s in shifts
            if s["shift_date"] == target_date
        ]

        report = []

        for _, user in users_df.iterrows():
            user_id = str(user["user_id"])
            user_name = user["name"]

            user_shift = next(
                (s for s in target_shifts if s["user_id"] == user_id),
                None
            )

            if user_shift:
                check_in = user_shift["check_in_time"]
                check_out = user_shift["check_out_time"] if user_shift["check_out_time"] else "---"
                hours = f"{user_shift['working_hours']:.2f}" if user_shift["working_hours"] > 0 else "0.00"

                status = "Present"

                if user_shift["late_minutes"] > 0:
                    status = f"Late ({user_shift['late_minutes']}min)"

                report.append({
                    "user_id": user_id,
                    "name": user_name,
                    "device_name": device["name"],
                    "device_ip": device["ip"],
                    "check_in": check_in,
                    "check_out": check_out,
                    "hours": hours,
                    "status": status,
                    "has_check_out": "Yes" if user_shift["check_out_time"] else "No"
                })

            else:
                report.append({
                    "user_id": user_id,
                    "name": user_name,
                    "device_name": device["name"],
                    "device_ip": device["ip"],
                    "check_in": "---",
                    "check_out": "---",
                    "hours": "0.00",
                    "status": "Absent",
                    "has_check_out": "No"
                })

        report_df = pd.DataFrame(report)

        present = len(report_df[report_df["status"] != "Absent"])
        absent = len(report_df[report_df["status"] == "Absent"])
        completed = len(report_df[report_df["has_check_out"] == "Yes"])

        print(f"\n📊 Summary for {device['name']} - {target_date}:")
        print(f"   Total Users: {len(report_df)}")
        print(f"   Present: {present}")
        print(f"   Completed with check-out: {completed}")
        print(f"   Pending check-out: {present - completed}")
        print(f"   Absent: {absent}")

        total = present + absent

        if total > 0:
            print(f"   Attendance Rate: {(present / total * 100):.1f}%")
        else:
            print("   Attendance Rate: 0.0%")

        filename = f"daily_report_{report_date.strftime('%Y%m%d')}_{device['slug']}.csv"
        report_df.to_csv(filename, index=False)

        print(f"\n✅ Report saved to: {filename}")

        return report_df

    # ============================================================
    # SUMMARY
    # ============================================================

    def show_summary_for_device(self, device):
        print("\n" + "=" * 60)
        print(f"📊 DATA SUMMARY - {device['name']}")
        print("=" * 60)

        df, users_df, user_map, existing_records, last_sync = self.load_device_data(device)

        if len(df) == 0:
            print("No attendance data available")
            return

        df["timestamp"] = pd.to_datetime(df["timestamp"], errors="coerce")
        df["date"] = df["timestamp"].dt.strftime("%Y-%m-%d")

        min_date = df["date"].min()
        max_date = df["date"].max()

        print(f"Device: {device['name']}")
        print(f"IP: {device['ip']}:{device['port']}")
        print(f"Total users: {len(users_df)}")
        print(f"Total records: {len(df)}")
        print(f"Date range: {min_date} to {max_date}")

        print("\nLast 7 days:")

        for i in range(7):
            date = (datetime.now() - timedelta(days=i)).strftime("%Y-%m-%d")
            count = len(df[df["date"] == date])
            print(f"   {date}: {count} records")

    def show_summary_all_devices(self):
        for device in DEVICES:
            self.show_summary_for_device(device)

    # ============================================================
    # TEST CONNECTIONS
    # ============================================================

    def test_all_connections(self):
        print("\n" + "=" * 60)
        print("🔌 TESTING DEVICE CONNECTIONS")
        print("=" * 60)

        for device in DEVICES:
            print(f"\n🏢 {device['name']}")
            print(f"🌐 {device['ip']}:{device['port']}")

            if self.test_connection(device):
                print("✅ Reachable")
            else:
                print("❌ Not reachable")

    # ============================================================
    # AUTO SYNC
    # ============================================================

    def auto_sync_all_devices(self, minutes=AUTO_SYNC_MINUTES):
        print("\n" + "=" * 60)
        print(f"⏱️ AUTO SYNC STARTED - EVERY {minutes} MINUTES")
        print("Press Ctrl + C to stop")
        print("=" * 60)

        while True:
            try:
                print(f"\n🚀 Auto sync started at {datetime.now().strftime('%Y-%m-%d %H:%M:%S')}")

                self.fetch_today_all_devices()

                current_time = datetime.now()

                if current_time.hour < 12:
                    report_date = current_time - timedelta(days=1)
                else:
                    report_date = current_time

                for device in DEVICES:
                    self.generate_report_for_device(device, report_date)

                next_run = datetime.now() + timedelta(minutes=minutes)
                print(f"\n⏳ Next auto sync at: {next_run.strftime('%Y-%m-%d %H:%M:%S')}")

                time.sleep(minutes * 60)

            except KeyboardInterrupt:
                print("\n🛑 Auto sync stopped by user")
                break

            except Exception as e:
                print(f"\n❌ Auto sync error: {e}")
                print(f"⏳ Retrying in {minutes} minutes...")
                time.sleep(minutes * 60)


# ============================================================
# MAIN MENU
# ============================================================

def main():
    tracker = DailyAttendanceTracker()

    while True:
        print("\n" + "=" * 60)
        print("🏢 DAILY ATTENDANCE TRACKER - SEPARATE DEVICE DATA")
        print("=" * 60)
        print("1. Fetch Users - Main Branch")
        print("2. Fetch Users - Commercial Branch")
        print("3. Fetch Users - All Devices")
        print("4. Fetch Today Data - Main Branch")
        print("5. Fetch Today Data - Commercial Branch")
        print("6. Fetch Today Data - All Devices")
        print("7. Generate Report - Main Branch")
        print("8. Generate Report - Commercial Branch")
        print("9. Show Data Summary - Main Branch")
        print("10. Show Data Summary - Commercial Branch")
        print("11. Show Data Summary - All Devices")
        print("12. Test Device Connections")
        print("13. Start Auto Sync - All Devices")
        print("14. Fetch Monthly Data - Main Branch")
        print("15. Fetch Monthly Data - Commercial Branch")
        print("16. Export Raw CSV - Main Branch")
        print("17. Export Raw CSV - Commercial Branch")
        print("18. Exit")
        print("=" * 60)

        choice = input("\nSelect option (1-18): ").strip()

        if choice == "1":
            tracker.fetch_users_for_device(DEVICES[0])

        elif choice == "2":
            tracker.fetch_users_for_device(DEVICES[1])

        elif choice == "3":
            tracker.fetch_users_all_devices()

        elif choice == "4":
            success = tracker.fetch_today_for_device(DEVICES[0])

            if success:
                current_time = datetime.now()
                report_date = current_time - timedelta(days=1) if current_time.hour < 12 else current_time
                tracker.generate_report_for_device(DEVICES[0], report_date)

        elif choice == "5":
            success = tracker.fetch_today_for_device(DEVICES[1])

            if success:
                current_time = datetime.now()
                report_date = current_time - timedelta(days=1) if current_time.hour < 12 else current_time
                tracker.generate_report_for_device(DEVICES[1], report_date)

        elif choice == "6":
            tracker.fetch_today_all_devices()

            current_time = datetime.now()
            report_date = current_time - timedelta(days=1) if current_time.hour < 12 else current_time

            for device in DEVICES:
                tracker.generate_report_for_device(device, report_date)

        elif choice == "7":
            print("\n📊 Select Report Type")
            print("1. Today's Report")
            print("2. Yesterday's Report")

            report_choice = input("\nSelect option (1-2): ").strip()

            if report_choice == "2":
                report_date = datetime.now() - timedelta(days=1)
            else:
                report_date = datetime.now()

            tracker.generate_report_for_device(DEVICES[0], report_date)

        elif choice == "8":
            print("\n📊 Select Report Type")
            print("1. Today's Report")
            print("2. Yesterday's Report")

            report_choice = input("\nSelect option (1-2): ").strip()

            if report_choice == "2":
                report_date = datetime.now() - timedelta(days=1)
            else:
                report_date = datetime.now()

            tracker.generate_report_for_device(DEVICES[1], report_date)

        elif choice == "9":
            tracker.show_summary_for_device(DEVICES[0])

        elif choice == "10":
            tracker.show_summary_for_device(DEVICES[1])

        elif choice == "11":
            tracker.show_summary_all_devices()

        elif choice == "12":
            tracker.test_all_connections()

        elif choice == "13":
            tracker.auto_sync_all_devices(AUTO_SYNC_MINUTES)

        elif choice == "14":
            tracker.run_monthly_fetch_menu(DEVICES[0])

        elif choice == "15":
            tracker.run_monthly_fetch_menu(DEVICES[1])

        elif choice == "16":
            tracker.export_raw_data_for_device(DEVICES[0])

        elif choice == "17":
            tracker.export_raw_data_for_device(DEVICES[1])

        elif choice == "18":
            print("\n👋 Goodbye!")
            break

        else:
            print("❌ Invalid option")


if __name__ == "__main__":
    main()  