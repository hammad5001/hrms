#!/usr/bin/env python
# -*- coding: utf-8 -*-
"""
Auto Attendance Runner (Commercial Branch) - Runs automatically and fetches data
"""

from attendance_collector_commercial import DailyAttendanceTracker
from datetime import datetime
import time
import schedule
import sys
import os

class AutoAttendanceRunnerCommercial:
    def __init__(self):
        print("Initializing Commercial Auto Attendance Runner...")
        self.tracker = DailyAttendanceTracker()
        self.log_file = "auto_run_commercial_log.txt"
        
    def log(self, message):
        """Log messages to file - without emojis"""
        timestamp = datetime.now().strftime('%Y-%m-%d %H:%M:%S')
        # Remove any emojis or special characters
        clean_message = message.encode('ascii', 'ignore').decode('ascii')
        with open(self.log_file, 'a', encoding='utf-8') as f:
            f.write(f"[{timestamp}] {clean_message}\n")
        print(f"[{timestamp}] {clean_message}")
        
    def fetch_and_process(self):
        """Main function to fetch and process attendance"""
        try:
            self.log("Starting attendance fetch for Commercial Branch...")
            
            # Fetch today's data
            success = self.tracker.fetch_today_data()
            
            if success:
                self.log("Commercial data fetched successfully")
                
                # Generate today's report
                report = self.tracker.generate_today_report()
                if report is not None:
                    filename = f"daily_report_{datetime.now().strftime('%Y%m%d')}_commercial.csv"
                    report.to_csv(filename, index=False)
                    self.log(f"Commercial report saved: {filename}")
                    
                # Show summary
                self.tracker.show_data_summary()
            else:
                self.log("No new commercial data found or fetch failed")
                
        except Exception as e:
            self.log(f"Error: {str(e)}")
            
    def start_auto_run(self):
        """Start the auto runner"""
        self.log("=" * 50)
        self.log("COMMERCIAL AUTO RUNNER STARTED")
        self.log("=" * 50)
        
        # Run immediately on start
        self.fetch_and_process()
        
        # Schedule to run every hour from 6 AM to 12 AM
        schedule.every().day.at("06:00").do(self.fetch_and_process)
        schedule.every().day.at("09:00").do(self.fetch_and_process)
        schedule.every().day.at("12:00").do(self.fetch_and_process)
        schedule.every().day.at("15:00").do(self.fetch_and_process)
        schedule.every().day.at("18:00").do(self.fetch_and_process)
        schedule.every().day.at("21:00").do(self.fetch_and_process)
        schedule.every().day.at("23:59").do(self.fetch_and_process)
        
        self.log("Scheduled times: 6AM, 9AM, 12PM, 3PM, 6PM, 9PM, 12AM")
        
        try:
            while True:
                schedule.run_pending()
                time.sleep(60)  # Check every minute
        except KeyboardInterrupt:
            self.log("Auto runner stopped by user")
        except Exception as e:
            self.log(f"Auto runner error: {e}")

if __name__ == "__main__":
    runner = AutoAttendanceRunnerCommercial()
    runner.start_auto_run()
