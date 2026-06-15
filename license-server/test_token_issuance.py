#!/usr/bin/env python3
"""Test token issuance with plan entitlements using direct HTTP"""

import json
import base64
import httpx
import os

print("=== Testing Token Issuance with Plan Entitlements ===\n")

# Test issuing token for different plans
test_cases = [
    {
        "name": "Free tier license",
        "license_key": "FREE-TIER-TEST-1234567890123456",
        "plan": "free"
    },
    {
        "name": "Professional tier license",
        "license_key": "PRO-TIER-TEST-12345678901234567",
        "plan": "pro"
    },
    {
        "name": "Enterprise tier license",
        "license_key": "ENT-TIER-TEST-12345678901234567",
        "plan": "enterprise"
    }
]

client = httpx.Client()
base_url = "http://127.0.0.1:8001"

# Get admin token if available
admin_token = os.getenv('LICENSE_ADMIN_TOKEN', '')
if not admin_token:
    # Try to read from file
    try:
        with open('keys/admin_token.txt', 'r') as f:
            admin_token = f.read().strip()
    except:
        admin_token = ''

headers = {}
if admin_token:
    headers['Authorization'] = f'Bearer {admin_token}'
    print(f"Using admin token for plan override\n")
else:
    print("Warning: No admin token found. Plan override may not work.\n")

for test in test_cases:
    print(f"Test: {test['name']}")
    print(f"  License Key: {test['license_key']}")
    print(f"  Plan: {test['plan']}")
    
    try:
        # Issue token via license grant
        response = client.post(
            f"{base_url}/api/v1/token",
            json={
                "grant_type": "license",
                "license_key": test["license_key"],
                "plan": test["plan"],
                "site": "https://example.com"
            },
            headers=headers,
            timeout=10
        )
        
        if response.status_code == 200:
            data = response.json()
            if 'access_token' in data:
                token = data['access_token']
                print(f"  ✓ Token issued")
                
                # Decode JWT to see claims (without verification for testing)
                parts = token.split('.')
                if len(parts) == 3:
                    # Decode payload
                    payload_b64 = parts[1]
                    # Add padding if needed
                    padding = 4 - len(payload_b64) % 4
                    if padding != 4:
                        payload_b64 += '=' * padding
                    
                    try:
                        payload_json = base64.urlsafe_b64decode(payload_b64)
                        payload = json.loads(payload_json)
                        
                        print(f"  Claims in token:")
                        print(f"    - plan: {payload.get('plan', 'N/A')}")
                        print(f"    - tier: {payload.get('tier', 'N/A')}")
                        print(f"    - features: {len(payload.get('features', []))} items")
                        if len(payload.get('features', [])) <= 3:
                            print(f"      {payload.get('features', [])}")
                        else:
                            print(f"      {payload.get('features', [])[:3]} +more")
                        print(f"    - sub: {payload.get('sub', 'N/A')}")
                        print(f"    - iat: {payload.get('iat', 'N/A')}")
                        print(f"    - exp: {payload.get('exp', 'N/A')}")
                    except Exception as e:
                        print(f"  ⚠ Could not decode payload: {e}")
            else:
                print(f"  ✗ No access_token in response: {data}")
        else:
            print(f"  ✗ HTTP {response.status_code}: {response.text}")
            
    except Exception as e:
        print(f"  ✗ Error: {e}")
    
    print()

print("=== Test Complete ===")
