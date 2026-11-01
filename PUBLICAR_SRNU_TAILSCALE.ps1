$ErrorActionPreference = "Stop"
Write-Host "SRNU CUSTOM - Publicacion temporal/continua con Tailscale Funnel" -ForegroundColor Cyan
if (-not (Get-Command tailscale -ErrorAction SilentlyContinue)) {
    Write-Host "No se encontro Tailscale. Instalalo y conecta esta PC a tu cuenta antes de continuar." -ForegroundColor Yellow
    exit 1
}
Write-Host "\nEstado actual:" -ForegroundColor Cyan
tailscale status
Write-Host "\nIntentando publicar SOLO la ruta del proyecto Laragon..." -ForegroundColor Cyan
Write-Host "Backend: http://127.0.0.1/srnu%20sql/" -ForegroundColor DarkGray
# Tailscale acepta un target HTTP con ruta. --bg mantiene Funnel activo en segundo plano.
tailscale funnel --bg 'http://127.0.0.1/srnu%20sql/'
Write-Host "\nEstado de Funnel:" -ForegroundColor Cyan
tailscale funnel status
Write-Host "\nCopia la URL https://....ts.net que aparece arriba y guardala en ERP > Acceso web." -ForegroundColor Green
