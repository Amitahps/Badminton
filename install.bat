@echo off
title Badminton Tournament Entry - Install
cd /d "%~dp0"

echo ============================================
echo   Badminton Tournament Entry - Install
echo ============================================
echo.

where python >nul 2>nul
if errorlevel 1 (
  where py >nul 2>nul
  if errorlevel 1 (
    echo ERROR: Python is not installed.
    echo Download Python 3.10+ from https://www.python.org/downloads/
    echo During install, tick "Add Python to PATH".
    pause
    exit /b 1
  )
  set PY=py -3
) else (
  set PY=python
)

echo Creating virtual environment...
%PY% -m venv .venv
if errorlevel 1 (
  echo Failed to create virtual environment.
  pause
  exit /b 1
)

call .venv\Scripts\activate.bat
python -m pip install --upgrade pip
pip install -r requirements.txt
if errorlevel 1 (
  echo Failed to install packages.
  pause
  exit /b 1
)

echo.
echo Install complete.
echo Next: double-click run.bat
echo Login: admin / admin123
echo.
pause
