#!/bin/bash

# Skrypt do automatycznego wersjonowania pluginu
# Używa semantic versioning (MAJOR.MINOR.PATCH)

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"

# Pobierz aktualną wersję z pliku głównego
CURRENT_VERSION=$(grep -E "^\s*\*\s*Version:" "${PLUGIN_DIR}/kasumi-ai-generator.php" | sed -E 's/.*Version:\s*([0-9]+\.[0-9]+\.[0-9]+).*/\1/')

if [ -z "$CURRENT_VERSION" ]; then
    echo "❌ Nie znaleziono aktualnej wersji w pliku głównym"
    exit 1
fi

echo "📦 Aktualna wersja: $CURRENT_VERSION"

# Parse version
IFS='.' read -r -a VERSION_PARTS <<< "$CURRENT_VERSION"
MAJOR=${VERSION_PARTS[0]}
MINOR=${VERSION_PARTS[1]}
PATCH=${VERSION_PARTS[2]}

# Określ typ bumpu (major, minor, patch)
BUMP_TYPE=${1:-patch}

case $BUMP_TYPE in
    major)
        MAJOR=$((MAJOR + 1))
        MINOR=0
        PATCH=0
        ;;
    minor)
        MINOR=$((MINOR + 1))
        PATCH=0
        ;;
    patch)
        PATCH=$((PATCH + 1))
        ;;
    *)
        echo "❌ Nieprawidłowy typ bumpu. Użyj: major, minor lub patch"
        exit 1
        ;;
esac

NEW_VERSION="${MAJOR}.${MINOR}.${PATCH}"
echo "🚀 Nowa wersja: $NEW_VERSION"

# Aktualizuj wersję w pliku głównym wtyczki
sed -i "s/Version: ${CURRENT_VERSION}/Version: ${NEW_VERSION}/" "${PLUGIN_DIR}/kasumi-ai-generator.php"
sed -i "s/define( 'KASUMI_AI_VERSION', '${CURRENT_VERSION}' );/define( 'KASUMI_AI_VERSION', '${NEW_VERSION}' );/" "${PLUGIN_DIR}/kasumi-ai-generator.php"

# Aktualizuj wersję w readme.txt
sed -i "s/Stable tag: ${CURRENT_VERSION}/Stable tag: ${NEW_VERSION}/" "${PLUGIN_DIR}/readme.txt"

echo "✅ Wersja zaktualizowana do $NEW_VERSION"
echo "📝 Pamiętaj o commitowaniu zmian:"
echo "   git add kasumi-ai-generator.php readme.txt"
echo "   git commit -m \"chore: bump version to $NEW_VERSION\""

