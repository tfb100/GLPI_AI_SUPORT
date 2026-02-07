@echo off
setlocal enabledelayedexpansion

echo ========================================
echo   GLPI Chatbot - Windows Installer
echo ========================================

:: Check if php is available
where php >nul 2>	id[Label (Extra Info)]
if %ERRORLEVEL% neq 0 (
    echo [ERROR] PHP nao encontrado no PATH.
    echo Por favor, adicione o executavel do PHP ao seu PATH ou execute este script a partir do terminal do XAMPP.
    pause
    exit /b 1
)

:: Run the PHP installer
echo Iniciando instalacao via CLI...
php install.php

echo.
echo ========================================
echo   Instalacao Finalizada!
echo ========================================
pause
