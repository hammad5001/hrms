COMMERCIAL IMPORT FOLDER
========================

Use this when the commercial device is NOT reachable from your PC
(but your fellow can fetch from the commercial office).

Steps:
1. Fellow runs fetch_today_commercial.bat on their PC (at commercial branch).
2. Fellow copies these files into THIS folder:
   - attendance_master_commercial.csv
   - all_users_commercial.xlsx  (optional, for names)
3. You run:
   python attendance_collector_commercial.py import
   OR double-click fetch_today_commercial.bat (auto-imports if device fails)

Files are merged into your local database — duplicates are skipped.
