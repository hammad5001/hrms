#!/usr/bin/env python3

import sqlite3
import pymysql
import re
import time
from datetime import datetime
from pathlib import Path


SQLITE_DB = "/var/lib/hrms-i9-ac/attendance.sqlite3"

MYSQL = {
    "host": "localhost",
    "user": "balitech_user",
    "password": "12344321",
    "database": "balitech",
    "charset": "utf8mb4"
}

SERIAL = "VDE2261200711"


def zk_decode(t):
    second = t % 60
    t //= 60

    minute = t % 60
    t //= 60

    hour = t % 24
    t //= 24

    day = t % 31 + 1
    t //= 31

    month = t % 12 + 1
    t //= 12

    year = t + 2000

    return datetime(
        year,
        month,
        day,
        hour,
        minute,
        second
    )


def parse_fields(line):
    return dict(
        re.findall(
            r'(\w+)=([^\s]+)',
            line
        )
    )


def load_names():
    con = sqlite3.connect(SQLITE_DB)

    rows = con.execute("""
        SELECT payload
        FROM uploads
        WHERE table_name='user'
    """).fetchall()

    users = {}

    for row in rows:
        text = row[0].decode(
            "utf-8",
            errors="ignore"
        )

        for line in text.splitlines():

            f = parse_fields(line)

            if "pin" in f and "name" in f:
                users[f["pin"]] = f["name"]

    con.close()

    return users


def get_historical_transactions():

    con = sqlite3.connect(SQLITE_DB)

    rows = con.execute("""
        SELECT payload
        FROM uploads
        WHERE table_name='transaction'
        ORDER BY id
    """).fetchall()

    records = {}

    for row in rows:

        text = row[0].decode(
            "utf-8",
            errors="ignore"
        )

        for line in text.splitlines():

            if "eventtype=3" not in line:
                continue

            f = parse_fields(line)

            pin = f.get("pin")

            if not pin:
                continue

            index = f.get("index")

            if not index:
                continue

            punch = zk_decode(
                int(f["time_second"])
            )

            records[index] = {
                "pin": pin,
                "time": punch
            }

    con.close()

    return records



# I9 RTLOG SUPPORT V1
def get_transactions():
    historical = get_historical_transactions()
    records = {
        (str(row["pin"]), row["time"]): row
        for row in historical.values()
    }

    con = sqlite3.connect(
        "file:" + SQLITE_DB + "?mode=ro", uri=True, timeout=30
    )
    try:
        rows = con.execute("""
            SELECT payload FROM uploads
            WHERE table_name='rtlog' AND serial_number=?
            ORDER BY id
        """, (SERIAL,)).fetchall()
    finally:
        con.close()

    live_count = 0
    for (payload,) in rows:
        text = (
            payload.decode("utf-8", errors="replace")
            if isinstance(payload, bytes) else str(payload)
        )
        for line in text.splitlines():
            fields = parse_fields(line)
            if fields.get("event") != "3":
                continue
            pin = fields.get("pin")
            match = re.search(
                r"(?:^|\s)time=(\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2})(?=\s|$)",
                line
            )
            if not pin or not match:
                raise ValueError("RTLOG attendance record missing pin/time")
            punch = datetime.strptime(
                match.group(1).replace("T", " "),
                "%Y-%m-%d %H:%M:%S"
            )
            key = (str(pin), punch)
            if key not in records:
                live_count += 1
            records[key] = {"pin": str(pin), "time": punch}

    print("Unique additional RTLOG punches:", live_count)
    return records


def sync():

    names = load_names()

    records = get_transactions()

    print(
        "Users:",
        len(names)
    )

    print(
        "Punch records:",
        len(records)
    )


    conn = pymysql.connect(
        **MYSQL
    )

    cur = conn.cursor()


    inserted = 0
    skipped = 0


    for idx, row in records.items():

        pin = row["pin"]
        dt = row["time"]


        timestamp = dt.strftime(
            "%Y-%m-%d %H:%M:%S"
        )

        date = dt.strftime(
            "%Y-%m-%d"
        )

        time_val = dt.strftime(
            "%H:%M:%S"
        )


        cur.execute("""
            SELECT id
            FROM attendance_i9_raw
            WHERE user_id=%s
            AND timestamp=%s
            LIMIT 1
        """,
        (
            pin,
            timestamp
        ))


        if cur.fetchone():

            skipped += 1
            continue


        cur.execute("""
            INSERT INTO attendance_i9_raw
            (
                user_id,
                name,
                timestamp,
                date,
                time,
                sync_status
            )
            VALUES
            (%s,%s,%s,%s,%s,'synced')
        """,
        (
            pin,
            names.get(pin,"Unknown"),
            timestamp,
            date,
            time_val
        ))

        inserted += 1


    conn.commit()

    cur.close()
    conn.close()


    print(
        "Inserted:",
        inserted
    )

    print(
        "Skipped:",
        skipped
    )


if __name__ == "__main__":

    sync()
