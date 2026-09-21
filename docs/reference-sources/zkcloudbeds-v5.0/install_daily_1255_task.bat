@echo off
cd /d %~dp0
schtasks /Create /TN "ZKCloudbedsAuto Daily" /SC DAILY /ST 12:55 /TR "\"%CD%\run_auto_silent.bat\"" /F
pause
