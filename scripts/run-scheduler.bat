@echo off
REM Windows Task Scheduler: run every 1 minute
REM Program: this .bat file  OR  C:\xampp\php\php.exe with args below
REM Start in: D:\mifumo\smartfinance\smartfinance

cd /d "D:\mifumo\smartfinance\smartfinance"

set PHP_EXE=C:\xampp\php\php.exe
if not exist "%PHP_EXE%" set PHP_EXE=php

"%PHP_EXE%" artisan schedule:run >> storage\logs\scheduler.log 2>&1
