#!/bin/bash

echo "=== Testing acceptProject mutation ==="
echo ""

# Test 1: Check if curator-processing status exists
echo "1. Checking if 'curator-processing' status exists in database:"
echo "Run this SQL query on production:"
echo "SELECT id, value, slug FROM project_statuses WHERE slug = 'curator-processing';"
echo ""

# Test 2: Test the mutation with curl
echo "2. Testing acceptProject mutation:"
echo ""

# You need to replace these with actual IDs from your database
PROJECT_ID="01JEXAMPLE123"  # Replace with actual project ID
USER_ID="01JEXAMPLE456"     # Replace with actual user ID

echo "Testing with:"
echo "  Project ID: $PROJECT_ID"
echo "  User ID: $USER_ID"
echo ""

MUTATION='mutation {
  acceptProject(projectId: "'$PROJECT_ID'", userId: "'$USER_ID'") {
    id
    user_id
    project_id
    created_at
  }
}'

echo "Sending mutation:"
echo "$MUTATION"
echo ""

curl -X POST https://api.bonus.band/graphql \
  -H "Content-Type: application/json" \
  -H "Origin: https://admin.bonus.band" \
  --cookie-jar cookies.txt \
  --cookie cookies.txt \
  -d "{\"query\":\"$(echo $MUTATION | tr '\n' ' ')\"}" \
  -v 2>&1 | tee response.txt

echo ""
echo ""
echo "3. Check Laravel logs for errors:"
echo "tail -n 50 storage/logs/laravel.log | grep -A 5 -B 5 AcceptProject"
echo ""

echo "4. Common issues to check:"
echo "   - Status 'curator-processing' exists in project_statuses table"
echo "   - Project with given ID exists"
echo "   - User with given ID exists"
echo "   - CORS headers are present in response"
echo "   - No database constraint errors"
