#!/usr/bin/env python
# -*- coding: utf-8 -*-
"""
CONNECTION DIAGNOSTIC TOOL
Run this first to identify the exact issue
"""

import socket
import subprocess
import sys
from zk import ZK

IP = "103.189.232.7"
PORT = 4730

print("=" * 60)
print("🔍 ZK DEVICE CONNECTION DIAGNOSTIC")
print("=" * 60)

# 1. PING TEST
print("\n[TEST 1] PING TEST")
print("-" * 40)
try:
    param = '-n' if sys.platform.lower() == 'win32' else '-c'
    result = subprocess.run(
        ['ping', param, '4', IP],
        capture_output=True,
        text=True,
        timeout=10
    )
    if result.returncode == 0:
        print("✅ PING SUCCESSFUL - Device is on network")
        # Show ping times
        for line in result.stdout.split('\n'):
            if 'time=' in line or 'time<' in line:
                print(f"   {line.strip()}")
    else:
        print("❌ PING FAILED - Device not reachable on network")
        print(f"Error: {result.stderr}")
except Exception as e:
    print(f"❌ PING ERROR: {e}")

# 2. PORT CONNECTION TEST
print("\n[TEST 2] PORT CONNECTION TEST")
print("-" * 40)
try:
    sock = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
    sock.settimeout(5)
    result = sock.connect_ex((IP, PORT))
    sock.close()
    
    if result == 0:
        print(f"✅ PORT {PORT} IS OPEN - Can establish TCP connection")
    else:
        print(f"❌ PORT {PORT} IS CLOSED - Connection refused (Error: {result})")
        print("   Possible causes:")
        print("   - Firewall blocking the port")
        print("   - Device using different port")
        print("   - Device not listening on this port")
except Exception as e:
    print(f"❌ PORT TEST ERROR: {e}")

# 3. ZK PROTOCOL TEST
print("\n[TEST 3] ZK PROTOCOL TEST")
print("-" * 40)

# Try with different timeouts
for timeout in [10, 20, 30, 60]:
    print(f"\nTrying with timeout: {timeout}s...")
    
    # Try TCP
    try:
        zk = ZK(IP, port=PORT, timeout=timeout, ommit_ping=True, force_udp=False)
        print("   Attempting TCP connection...")
        conn = zk.connect()
        print(f"   ✅ SUCCESS! Connected via TCP")
        try:
            firmware = conn.get_firmware_version()
            serial = conn.get_serialnumber()
            print(f"   Firmware: {firmware}")
            print(f"   Serial: {serial}")
        except:
            pass
        conn.disconnect()
        break
    except Exception as e:
        print(f"   ❌ TCP failed: {e}")
        
        # Try UDP
        try:
            zk = ZK(IP, port=PORT, timeout=timeout, ommit_ping=True, force_udp=True)
            print("   Attempting UDP connection...")
            conn = zk.connect()
            print(f"   ✅ SUCCESS! Connected via UDP")
            try:
                firmware = conn.get_firmware_version()
                serial = conn.get_serialnumber()
                print(f"   Firmware: {firmware}")
                print(f"   Serial: {serial}")
            except:
                pass
            conn.disconnect()
            break
        except Exception as e:
            print(f"   ❌ UDP failed: {e}")

# 4. ALTERNATIVE PORTS TEST
print("\n[TEST 4] TESTING ALTERNATIVE PORTS")
print("-" * 40)

alternative_ports = [4370, 80, 8080, 8000, 8081, 8090, 3000, 5000]

for alt_port in alternative_ports:
    print(f"\nTesting port {alt_port}...")
    
    # Quick TCP connect test first
    try:
        sock = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
        sock.settimeout(2)
        result = sock.connect_ex((IP, alt_port))
        sock.close()
        
        if result == 0:
            print(f"   Port {alt_port} is OPEN - Trying ZK protocol...")
            
            # Try ZK on this port
            try:
                zk = ZK(IP, port=alt_port, timeout=10, ommit_ping=True)
                conn = zk.connect()
                print(f"   ✅ ZK CONNECTION SUCCESSFUL on port {alt_port}!")
                try:
                    firmware = conn.get_firmware_version()
                    serial = conn.get_serialnumber()
                    print(f"   Firmware: {firmware}")
                    print(f"   Serial: {serial}")
                except:
                    pass
                conn.disconnect()
                print(f"\n🎯 CORRECT PORT FOUND: {alt_port}")
                break
            except Exception as e:
                print(f"   ❌ ZK on port {alt_port} failed: {e}")
        else:
            print(f"   Port {alt_port} is CLOSED")
    except Exception as e:
        print(f"   Error testing port {alt_port}: {e}")

print("\n" + "=" * 60)
print("DIAGNOSTIC COMPLETE")
print("=" * 60)