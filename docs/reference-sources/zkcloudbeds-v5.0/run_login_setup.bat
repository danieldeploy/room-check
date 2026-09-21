@echo off
cd /d %~dp0
python -m src.app login_setup
pause
