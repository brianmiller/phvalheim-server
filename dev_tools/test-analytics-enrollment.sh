#!/bin/bash
# Persistent enrollment, restart, failure, opt-out and transport regression checks.
set -euo pipefail
cd "$(dirname "$0")/.."
python3 dev_tools/test-analytics-enrollment.py
