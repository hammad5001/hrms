# scripts/recovery.py - Automated Backup & Rollback Utility for HRMS Codebase and Database

import os
import sys
import re
import shutil
import zipfile
import subprocess
from datetime import datetime

# Configuration
WORKSPACE_DIR = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
SNAPSHOTS_DIR = os.path.join(WORKSPACE_DIR, 'snapshots')
MAX_SNAPSHOTS = 5

# Paths to ignore during codebase backup
IGNORE_DIRS = {
    '.git',
    'node_modules',
    'snapshots',
    'uploads',
    'chat-ws/node_modules',
    'call-token-service/node_modules'
}

def parse_db_config():
    config_path = os.path.join(WORKSPACE_DIR, 'config.php')
    if not os.path.exists(config_path):
        print(f"Error: config.php not found at {config_path}")
        sys.exit(1)
        
    with open(config_path, 'r', encoding='utf-8') as f:
        content = f.read()
        
    db_host = re.search(r"define\('DB_HOST',\s*'([^']+)'\);", content)
    db_user = re.search(r"define\('DB_USER',\s*'([^']+)'\);", content)
    db_pass = re.search(r"define\('DB_PASS',\s*'([^']*)'\);", content)
    db_name = re.search(r"define\('DB_NAME',\s*'([^']+)'\);", content)
    
    if not (db_host and db_user and db_name):
        print("Error: Could not parse database configurations from config.php")
        sys.exit(1)
        
    return {
        'host': db_host.group(1),
        'user': db_user.group(1),
        'pass': db_pass.group(1) if db_pass else '',
        'name': db_name.group(1)
    }

def get_mysql_tool_path(tool_name):
    # Try common XAMPP path first
    xampp_path = f"C:\\xampp\\mysql\\bin\\{tool_name}.exe"
    if os.path.exists(xampp_path):
        return xampp_path
    return tool_name

def prune_snapshots():
    if not os.path.exists(SNAPSHOTS_DIR):
        return
    snapshots = sorted([
        f for f in os.listdir(SNAPSHOTS_DIR) 
        if f.startswith('snapshot_') and f.endswith('.zip')
    ])
    while len(snapshots) >= MAX_SNAPSHOTS:
        oldest = snapshots.pop(0)
        os.remove(os.path.join(SNAPSHOTS_DIR, oldest))
        print(f"Pruned oldest redundant snapshot: {oldest}")

def backup():
    os.makedirs(SNAPSHOTS_DIR, exist_ok=True)
    db = parse_db_config()
    timestamp = datetime.now().strftime('%Y%m%d_%H%M%S')
    snapshot_name = f"snapshot_{timestamp}"
    snapshot_zip_path = os.path.join(SNAPSHOTS_DIR, f"{snapshot_name}.zip")
    
    # 1. Export MySQL Database
    db_dump_path = os.path.join(WORKSPACE_DIR, f"{snapshot_name}.sql")
    dump_tool = get_mysql_tool_path('mysqldump')
    
    print(f"Exporting database '{db['name']}'...")
    cmd = [dump_tool, '-h', db['host'], '-u', db['user']]
    if db['pass']:
        cmd.append(f"-p{db['pass']}")
    cmd.extend([db['name'], f"--result-file={db_dump_path}"])
    
    try:
        subprocess.run(cmd, check=True)
        print("Database exported successfully.")
    except subprocess.CalledProcessError as e:
        print(f"Database export failed: {e}")
        if os.path.exists(db_dump_path):
            os.remove(db_dump_path)
        sys.exit(1)

    # 2. Package Codebase & DB Dump
    print(f"Creating codebase snapshot: {snapshot_name}.zip...")
    try:
        with zipfile.ZipFile(snapshot_zip_path, 'w', zipfile.ZIP_DEFLATED) as zipf:
            # Add DB dump
            zipf.write(db_dump_path, f"{snapshot_name}.sql")
            
            # Add files
            for root, dirs, files in os.walk(WORKSPACE_DIR):
                # Filter ignored directories
                rel_path = os.path.relpath(root, WORKSPACE_DIR)
                if any(rel_path == d or rel_path.startswith(d + os.sep) for d in IGNORE_DIRS):
                    continue
                for file in files:
                    # Skip the temporary sql dump and the current zip being written
                    if file == f"{snapshot_name}.sql" or file == f"{snapshot_name}.zip":
                        continue
                    full_file_path = os.path.join(root, file)
                    archive_path = os.path.relpath(full_file_path, WORKSPACE_DIR)
                    zipf.write(full_file_path, archive_path)
                    
        print(f"Snapshot created successfully: {snapshot_zip_path}")
        prune_snapshots()
    finally:
        if os.path.exists(db_dump_path):
            os.remove(db_dump_path)

def rollback():
    if not os.path.exists(SNAPSHOTS_DIR):
        print("No snapshots directory found. Nothing to restore.")
        return
        
    snapshots = sorted([
        f for f in os.listdir(SNAPSHOTS_DIR) 
        if f.startswith('snapshot_') and f.endswith('.zip')
    ])
    
    if not snapshots:
        print("No snapshots available.")
        return
        
    print("\nAvailable Snapshots:")
    for idx, s in enumerate(snapshots):
        print(f"[{idx}] {s}")
        
    try:
        sel = input(f"\nSelect snapshot index to restore (0-{len(snapshots)-1}) [Latest]: ").strip()
        if sel == '':
            sel_idx = len(snapshots) - 1
        else:
            sel_idx = int(sel)
        selected_snapshot = snapshots[sel_idx]
    except (ValueError, IndexError):
        print("Invalid selection.")
        return
        
    confirm = input(f"Are you sure you want to rollback to {selected_snapshot}? All current unstaged progress will be lost. (y/n): ").strip().lower()
    if confirm != 'y':
        print("Rollback cancelled.")
        return
        
    db = parse_db_config()
    zip_path = os.path.join(SNAPSHOTS_DIR, selected_snapshot)
    temp_extract_dir = os.path.join(SNAPSHOTS_DIR, 'temp_restore')
    os.makedirs(temp_extract_dir, exist_ok=True)
    
    try:
        # Extract snapshot
        print("Extracting snapshot archive...")
        with zipfile.ZipFile(zip_path, 'r') as zipf:
            zipf.extractall(temp_extract_dir)
            
        # 1. Restore Database
        sql_file = [f for f in os.listdir(temp_extract_dir) if f.endswith('.sql')]
        if sql_file:
            sql_path = os.path.join(temp_extract_dir, sql_file[0])
            print(f"Importing database dump: {sql_file[0]}...")
            mysql_tool = get_mysql_tool_path('mysql')
            cmd = [mysql_tool, '-h', db['host'], '-u', db['user']]
            if db['pass']:
                cmd.append(f"-p{db['pass']}")
            cmd.append(db['name'])
            
            with open(sql_path, 'r') as sf:
                subprocess.run(cmd, stdin=sf, check=True)
            print("Database restored successfully.")
            os.remove(sql_path)
            
        # 2. Restore Codebase Files
        print("Restoring codebase files...")
        # Delete existing files (excluding ignored directories)
        for item in os.listdir(WORKSPACE_DIR):
            item_path = os.path.join(WORKSPACE_DIR, item)
            if item in IGNORE_DIRS:
                continue
            if os.path.isdir(item_path):
                shutil.rmtree(item_path)
            else:
                os.remove(item_path)
                
        # Copy files back
        for item in os.listdir(temp_extract_dir):
            src_item = os.path.join(temp_extract_dir, item)
            dest_item = os.path.join(WORKSPACE_DIR, item)
            if os.path.isdir(src_item):
                shutil.copytree(src_item, dest_item)
            else:
                shutil.copy2(src_item, dest_item)
                
        print("Codebase restored successfully.")
    except Exception as e:
        print(f"Error during rollback: {e}")
    finally:
        if os.path.exists(temp_extract_dir):
            shutil.rmtree(temp_extract_dir)

if __name__ == '__main__':
    if len(sys.argv) < 2:
        print("Usage: python recovery.py [backup|rollback]")
        sys.exit(1)
        
    action = sys.argv[1].lower()
    if action == 'backup':
        backup()
    elif action == 'rollback':
        rollback()
    else:
        print(f"Unknown action: {action}")
        print("Use 'backup' or 'rollback'")
