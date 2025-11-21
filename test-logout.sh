#!/bin/bash
# Get new token
RESPONSE=$(curl -s -X POST http://localhost:8001/api/login \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{"email": "jwttest@example.com", "password": "password123"}')

TOKEN=$(echo $RESPONSE | grep -o '"token":"[^"]*' | cut -d'"' -f4)

echo "Token obtained: ${TOKEN:0:50}..."
echo ""
echo "Testing logout..."

# Test logout
curl -s -X POST http://localhost:8001/api/logout \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -H "Authorization: Bearer ${TOKEN}"
