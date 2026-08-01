#!/bin/bash

echo "========================================================="
echo "   BIO-NOTIFIER LOAD TEST - 16GB PRODUCTION SIMULATION"
echo "========================================================="
echo ""
echo "This script will simulate your exact scenario:"
echo "- 1,000 Concurrent Web Users (Dashboard Polling)"
echo "- Peak Webhook Traffic (Simulating 10,000 users punching)"
echo ""
echo "Ensure you run this ON the production server itself"
echo "to bypass Cloudflare DDoS protection and get raw server stats."
echo ""

# Check if wrk is installed
if ! command -v wrk &> /dev/null
then
    echo "Error: 'wrk' is not installed."
    echo "Please install it by running: sudo apt install wrk"
    exit 1
fi

# The live URL (must include https:// or http://)
DOMAIN="https://noti.ariise.cloud"
URL="$DOMAIN/secumax"
WEBHOOK_URL="$DOMAIN/api/ebio/webhook/test-token"
echo "Testing Dashboard against: $URL"
echo "Testing Webhook against: $WEBHOOK_URL"
echo ""

echo "---------------------------------------------------------"
echo "TEST 1: Simulating 1,000 Concurrent Web Users"
echo "Running for 30 seconds... (This will spike CPU, watch your 'htop')"
echo "---------------------------------------------------------"
# -t4 (4 threads), -c1000 (1000 connections)
wrk -t4 -c1000 -d30s "$URL"

echo ""
echo "---------------------------------------------------------"
echo "TEST 2: Simulating Heavy Webhook Traffic (Punches)"
echo "Running for 30 seconds..."
echo "---------------------------------------------------------"
# Simulating the webhook endpoint being hit
wrk -t2 -c100 -d30s -s <(echo "wrk.method = \"POST\"") "$WEBHOOK_URL"

echo ""
echo "========================================================="
echo "LOAD TEST COMPLETE"
echo "========================================================="
echo "If your 'Requests/sec' is consistently above 150,"
echo "your 16GB server is easily crushing the workload!"
