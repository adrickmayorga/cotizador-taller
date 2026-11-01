@echo off
setlocal
chcp 65001 >nul
set "OLLAMA_EXE=ollama"
if exist "%LOCALAPPDATA%\Programs\Ollama\ollama.exe" set "OLLAMA_EXE=%LOCALAPPDATA%\Programs\Ollama\ollama.exe"
curl -s --max-time 2 http://127.0.0.1:11434/api/tags >nul 2>&1
if %errorlevel%==0 (
  echo Ollama ya esta funcionando.
  timeout /t 2 >nul
  exit /b 0
)
start "Ollama SRNU" /min "%OLLAMA_EXE%" serve
timeout /t 3 /nobreak >nul
curl -s --max-time 3 http://127.0.0.1:11434/api/tags >nul 2>&1
if %errorlevel%==0 (echo IA SRNU iniciada correctamente.) else (echo No se pudo iniciar Ollama. Abre Ollama manualmente.)
pause
