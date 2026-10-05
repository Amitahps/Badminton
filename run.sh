#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")"

if [[ ! -f .venv/bin/activate ]]; then
  echo "Virtual environment missing. Run ./install.sh first."
  exit 1
fi

# shellcheck disable=SC1091
source .venv/bin/activate
echo "Open in browser: http://127.0.0.1:5055"
echo "Username: admin"
echo "Password: admin123"
echo "Keep this terminal open while using the software."
python app.py
