@echo off
setlocal
if /i "%~1"=="init" goto init
if /i "%~1"=="build" goto build
if /i "%~1"=="up" goto up
docker compose %*
exit /b %errorlevel%

:init
docker run --rm -v "%CD%:/project" -w /project php:8.4-cli php deploy/init-env.php
if errorlevel 1 exit /b 1
:build
docker compose build
if errorlevel 1 exit /b 1
:up
docker compose run --rm --no-deps -T --entrypoint php smtp artisan mail:parse --check-limit
if errorlevel 1 exit /b 1
docker compose up -d
exit /b %errorlevel%
