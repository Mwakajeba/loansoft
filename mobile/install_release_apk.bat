@echo off
REM Install the release APK over USB (USB debugging on). Avoids WhatsApp corrupting the file.
set APK=%~dp0build\app\outputs\flutter-apk\app-release.apk
if not exist "%APK%" (
  echo APK not found. Run: flutter build apk --release
  exit /b 1
)
where adb >nul 2>nul
if errorlevel 1 (
  echo adb not in PATH. Add Android SDK platform-tools to PATH.
  exit /b 1
)
adb uninstall com.yawote.yawote_app 2>nul
adb install -r "%APK%"
if errorlevel 1 (
  echo.
  echo If install failed: on the phone enable Developer options ^> USB debugging,
  echo authorize the PC, and try again. Uninstall any old EPMFINANCE build first.
  exit /b 1
)
echo Done.
exit /b 0
