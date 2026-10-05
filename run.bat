@echo off
title Badminton Tournament Entry
cd /d "%~dp0"

if not exist ".venv\Scripts\activate.bat" (
  echo Virtual environment missing. Run install.bat first.
  pause
  exit /b 1
)

call .venv\Scripts\activate.bat
echo Opening http://127.0.0.1:5055
echo Username: admin
echo Password: admin123
echo Keep this window open while using the software.
echo.
start "" http://127.0.0.1:5055
python app.py
pause
