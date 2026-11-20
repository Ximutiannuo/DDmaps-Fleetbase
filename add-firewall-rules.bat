@echo off
echo ============================================
echo  Fleetbase - 防火墙规则 + 网络配置
echo ============================================
echo.

echo 1. Adding firewall rules for ports 4200, 8000...
netsh advfirewall firewall add rule name="Fleetbase HTTPS 4200" dir=in action=allow protocol=TCP localport=4200
netsh advfirewall firewall add rule name="Fleetbase HTTP 8000" dir=in action=allow protocol=TCP localport=8000
echo.

echo 2. Setting WLAN network to Private...
powershell -Command "Set-NetConnectionProfile -InterfaceAlias 'WLAN' -NetworkCategory Private"
echo.

echo Done! Now your phone should be able to access:
echo   https://10.78.87.215:4200/driver-track
echo.
pause
