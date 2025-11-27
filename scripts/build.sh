#!/bin/bash

# Skrypt do budowania paczki ZIP wtyczki WordPress
# Kasumi AI Generator

set -e

PLUGIN_NAME="kasumi-ai-generator"
# Przejdź do katalogu głównego wtyczki (jeden poziom wyżej od scripts/)
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
BUILD_DIR="${PLUGIN_DIR}/build"
TEMP_DIR="${BUILD_DIR}/temp"
ZIP_NAME="${PLUGIN_NAME}.zip"

echo "🔨 Budowanie paczki wtyczki WordPress..."

# Czyszczenie poprzednich buildów
rm -rf "${BUILD_DIR}"
mkdir -p "${TEMP_DIR}/${PLUGIN_NAME}"

echo "📦 Kopiowanie plików wtyczki..."

# Kopiowanie plików (wykluczając niepotrzebne)
rsync -av \
  --exclude='.git' \
  --exclude='.gitignore' \
  --exclude='node_modules' \
  --exclude='vendor' \
  --exclude='build' \
  --exclude='scripts' \
  --exclude='*.zip' \
  --exclude='*.log' \
  --exclude='.env' \
  --exclude='.env.*' \
  --exclude='.idea' \
  --exclude='.vscode' \
  --exclude='*.swp' \
  --exclude='*.swo' \
  --exclude='.DS_Store' \
  --exclude='Thumbs.db' \
  --exclude='README.md' \
  "${PLUGIN_DIR}/" "${TEMP_DIR}/${PLUGIN_NAME}/"

echo "📚 Instalacja zależności Composer..."

# Instalacja zależności Composer (production only, bez dev dependencies)
cd "${TEMP_DIR}/${PLUGIN_NAME}"
composer install --no-dev --optimize-autoloader --no-interaction --quiet

# Usunięcie composer.lock z paczki (nie jest potrzebny w dystrybucji)
rm -f composer.lock

echo "🗜️  Tworzenie archiwum ZIP..."

# Utworzenie ZIP
cd "${BUILD_DIR}"
zip -r "${ZIP_NAME}" "${PLUGIN_NAME}" -q

# Przeniesienie ZIP do katalogu głównego wtyczki
mv "${ZIP_NAME}" "${PLUGIN_DIR}/"

# Czyszczenie tymczasowych plików
rm -rf "${TEMP_DIR}"

echo "✅ Gotowe! Paczka utworzona: ${PLUGIN_DIR}/${ZIP_NAME}"
echo "📊 Rozmiar pliku: $(du -h "${PLUGIN_DIR}/${ZIP_NAME}" | cut -f1)"

