#!/bin/bash

echo "========================================"
echo "  GLPI Chatbot - Linux Installer"
echo "========================================"

# Check if php is available
if ! command -v php &> /dev/null
then
    echo "[ERROR] PHP não encontrado. Por favor, instale o PHP e tente novamente."
    exit 1
fi

# Set permissions for the installer script if needed
chmod +x install.php 2>/dev/null

# Run the PHP installer
echo "Iniciando instalação via CLI..."
php install.php

echo ""
echo "========================================"
echo "  Instalação Finalizada!"
echo "========================================"
