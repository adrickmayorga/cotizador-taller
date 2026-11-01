@echo off
setlocal EnableExtensions
chcp 65001 >nul
title SRNU CUSTOM - Instalar IA Local

echo ============================================================
echo   SRNU CUSTOM - IA LOCAL GRATUITA
echo   Ollama + Qwen3-VL 4B + Poppler opcional
ECHO ============================================================
echo.

set "OLLAMA_EXE="
where ollama >nul 2>&1
if %errorlevel%==0 set "OLLAMA_EXE=ollama"
if not defined OLLAMA_EXE if exist "%LOCALAPPDATA%\Programs\Ollama\ollama.exe" set "OLLAMA_EXE=%LOCALAPPDATA%\Programs\Ollama\ollama.exe"

if not defined OLLAMA_EXE (
  echo [1/4] Ollama no esta instalado. Intentando instalar con winget...
  where winget >nul 2>&1
  if not %errorlevel%==0 (
    echo.
    echo ERROR: No se encontro winget.
    echo Instala Ollama manualmente y vuelve a ejecutar este archivo.
    echo https://ollama.com/download/windows
    pause
    exit /b 1
  )
  winget install -e --id Ollama.Ollama --accept-package-agreements --accept-source-agreements
  if exist "%LOCALAPPDATA%\Programs\Ollama\ollama.exe" set "OLLAMA_EXE=%LOCALAPPDATA%\Programs\Ollama\ollama.exe"
  if not defined OLLAMA_EXE set "OLLAMA_EXE=ollama"
) else (
  echo [1/4] Ollama ya esta instalado.
)

echo.
echo [2/4] Verificando servicio de Ollama...
curl -s --max-time 2 http://127.0.0.1:11434/api/tags >nul 2>&1
if not %errorlevel%==0 (
  echo Iniciando Ollama...
  start "Ollama SRNU" /min "%OLLAMA_EXE%" serve
  timeout /t 4 /nobreak >nul
)

echo.
echo [3/4] Descargando modelo qwen3-vl:4b-instruct...
echo Esto puede tardar y descargar varios GB la primera vez.
"%OLLAMA_EXE%" pull qwen3-vl:4b-instruct
if not %errorlevel%==0 (
  echo.
  echo ERROR: No se pudo descargar el modelo.
  echo Prueba manualmente: ollama pull qwen3-vl:4b-instruct
  pause
  exit /b 1
)

echo.
echo [4/4] Poppler para PDFs escaneados (opcional pero recomendado)...
where pdftoppm >nul 2>&1
if %errorlevel%==0 (
  echo Poppler/pdftoppm ya esta disponible.
) else (
  where winget >nul 2>&1
  if %errorlevel%==0 (
    echo Intentando instalar Poppler con winget...
    winget install -e --id oschwartz10612.Poppler --accept-package-agreements --accept-source-agreements
  ) else (
    echo No se pudo instalar Poppler automaticamente.
  )
)

echo.
echo ============================================================
echo LISTO.
echo 1. Reinicia Apache si estaba abierto.
echo 2. Abre: http://localhost/srnu%%20sql/ia_local.php
echo 3. Pulsa "Probar IA ahora".
echo ============================================================
pause
