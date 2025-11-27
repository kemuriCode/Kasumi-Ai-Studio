#!/bin/bash

# Generuje changelog z commit messages używając Conventional Commits
# Format: type(scope): description

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"

# Pobierz tagi
LAST_TAG=$(git describe --tags --abbrev=0 2>/dev/null || echo "")
CURRENT_VERSION=$(grep -E "^\s*\*\s*Version:" "${PLUGIN_DIR}/kasumi-ai-generator.php" | sed -E 's/.*Version:\s*([0-9]+\.[0-9]+\.[0-9]+).*/\1/')

if [ -z "$CURRENT_VERSION" ]; then
    echo "❌ Nie znaleziono aktualnej wersji"
    exit 1
fi

echo "📝 Generowanie changelog dla wersji $CURRENT_VERSION..."

# Jeśli nie ma tagów, użyj wszystkich commitów
if [ -z "$LAST_TAG" ]; then
    COMMITS=$(git log --pretty=format:"%s" --no-merges)
    echo "⚠️  Brak poprzednich tagów, używam wszystkich commitów"
else
    COMMITS=$(git log ${LAST_TAG}..HEAD --pretty=format:"%s" --no-merges)
    echo "📌 Zmiany od tagu: $LAST_TAG"
fi

# Kategoryzuj commity według Conventional Commits
FEATURES=()
FIXES=()
BREAKING=()
CHORES=()
PERF=()
REFACTOR=()
DOCS=()
STYLE=()
TEST=()

while IFS= read -r commit; do
    if [[ $commit =~ ^(feat|feature)(\(.+\))?: ]]; then
        FEATURES+=("$commit")
    elif [[ $commit =~ ^(fix|bugfix)(\(.+\))?: ]]; then
        FIXES+=("$commit")
    elif [[ $commit =~ ^BREAKING\ CHANGE: ]]; then
        BREAKING+=("$commit")
    elif [[ $commit =~ ^(chore|build|ci)(\(.+\))?: ]]; then
        CHORES+=("$commit")
    elif [[ $commit =~ ^perf(\(.+\))?: ]]; then
        PERF+=("$commit")
    elif [[ $commit =~ ^refactor(\(.+\))?: ]]; then
        REFACTOR+=("$commit")
    elif [[ $commit =~ ^docs(\(.+\))?: ]]; then
        DOCS+=("$commit")
    elif [[ $commit =~ ^style(\(.+\))?: ]]; then
        STYLE+=("$commit")
    elif [[ $commit =~ ^test(\(.+\))?: ]]; then
        TEST+=("$commit")
    else
        # Domyślnie do innych zmian
        CHORES+=("$commit")
    fi
done <<< "$COMMITS"

# Generuj changelog
CHANGELOG="## [$CURRENT_VERSION] - $(date +%Y-%m-%d)\n\n"

if [ ${#BREAKING[@]} -gt 0 ]; then
    CHANGELOG+="### ⚠️ Breaking Changes\n\n"
    for change in "${BREAKING[@]}"; do
        CHANGELOG+="- ${change#BREAKING CHANGE: }\n"
    done
    CHANGELOG+="\n"
fi

if [ ${#FEATURES[@]} -gt 0 ]; then
    CHANGELOG+="### ✨ Nowe funkcje\n\n"
    for change in "${FEATURES[@]}"; do
        # Usuń prefix "feat:" lub "feature:"
        clean_change=$(echo "$change" | sed -E 's/^(feat|feature)(\(.+\))?:\s*//')
        CHANGELOG+="- ${clean_change}\n"
    done
    CHANGELOG+="\n"
fi

if [ ${#FIXES[@]} -gt 0 ]; then
    CHANGELOG+="### 🐛 Poprawki błędów\n\n"
    for change in "${FIXES[@]}"; do
        clean_change=$(echo "$change" | sed -E 's/^(fix|bugfix)(\(.+\))?:\s*//')
        CHANGELOG+="- ${clean_change}\n"
    done
    CHANGELOG+="\n"
fi

if [ ${#PERF[@]} -gt 0 ]; then
    CHANGELOG+="### ⚡ Optymalizacje wydajności\n\n"
    for change in "${PERF[@]}"; do
        clean_change=$(echo "$change" | sed -E 's/^perf(\(.+\))?:\s*//')
        CHANGELOG+="- ${clean_change}\n"
    done
    CHANGELOG+="\n"
fi

if [ ${#REFACTOR[@]} -gt 0 ]; then
    CHANGELOG+="### ♻️ Refaktoryzacja\n\n"
    for change in "${REFACTOR[@]}"; do
        clean_change=$(echo "$change" | sed -E 's/^refactor(\(.+\))?:\s*//')
        CHANGELOG+="- ${clean_change}\n"
    done
    CHANGELOG+="\n"
fi

if [ ${#DOCS[@]} -gt 0 ]; then
    CHANGELOG+="### 📚 Dokumentacja\n\n"
    for change in "${DOCS[@]}"; do
        clean_change=$(echo "$change" | sed -E 's/^docs(\(.+\))?:\s*//')
        CHANGELOG+="- ${clean_change}\n"
    done
    CHANGELOG+="\n"
fi

if [ ${#TEST[@]} -gt 0 ]; then
    CHANGELOG+="### 🧪 Testy\n\n"
    for change in "${TEST[@]}"; do
        clean_change=$(echo "$change" | sed -E 's/^test(\(.+\))?:\s*//')
        CHANGELOG+="- ${clean_change}\n"
    done
    CHANGELOG+="\n"
fi

if [ ${#CHORES[@]} -gt 0 ] && [ ${#BREAKING[@]} -eq 0 ] && [ ${#FEATURES[@]} -eq 0 ] && [ ${#FIXES[@]} -eq 0 ]; then
    CHANGELOG+="### 🔧 Inne zmiany\n\n"
    for change in "${CHORES[@]}"; do
        clean_change=$(echo "$change" | sed -E 's/^(chore|build|ci)(\(.+\))?:\s*//')
        CHANGELOG+="- ${clean_change}\n"
    done
    CHANGELOG+="\n"
fi

# Jeśli brak zmian
if [ ${#FEATURES[@]} -eq 0 ] && [ ${#FIXES[@]} -eq 0 ] && [ ${#BREAKING[@]} -eq 0 ] && [ ${#PERF[@]} -eq 0 ] && [ ${#REFACTOR[@]} -eq 0 ]; then
    CHANGELOG+="* Brak znaczących zmian w tej wersji\n"
fi

echo -e "$CHANGELOG"

