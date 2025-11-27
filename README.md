# Kasumi-AI-Content-Generator

Automatyzuje generowanie wpisów, komentarzy i grafik przy użyciu OpenAI oraz Google Gemini.

## Opis

Kasumi – Full AI Content Generator to wtyczka WordPress, która wykorzystuje sztuczną inteligencję do automatycznego generowania treści na stronach WordPress. Wtyczka obsługuje zarówno OpenAI jak i Google Gemini.

## Gutenberg-ready output

- Markdown wygenerowany przez AI jest mapowany na natywne bloki `core/*` (nagłówki, akapity, listy, cytaty, obrazy, kod, separatory).
- Nietypowe fragmenty trafiają do bloku `core/html`, więc nadal można je edytować po stronie Gutenberga bez utraty formatowania.
- Jeżeli konwersja na bloki się nie powiedzie, logger zapisze ostrzeżenie i włączy się bezpieczny fallback do czystego HTML.

### Jak przetestować bloki

Po utworzeniu wpisu możesz zweryfikować wygenerowaną strukturę bloków poleceniem WP-CLI:

```bash
wp eval 'print_r( parse_blocks( get_post(123)->post_content ) );'
```

(Podmień `123` na ID świeżo wygenerowanego wpisu).

## Wymagania

- WordPress 5.8+
- PHP 8.1+
- Rozszerzenia PHP: cURL, mbstring

## Instalacja

1. Pobierz paczkę wtyczki
2. Prześlij folder wtyczki do `/wp-content/plugins/`
3. Uruchom `composer install` w katalogu wtyczki
4. Aktywuj wtyczkę w panelu administracyjnym WordPress

## Wymagane zależności

```bash
composer install
```

## Budowanie paczki ZIP

### Lokalnie

Aby utworzyć paczkę ZIP wtyczki gotową do dystrybucji:

```bash
./scripts/build.sh
```

Skrypt utworzy plik `kasumi-ai-generator.zip` w katalogu głównym wtyczki.

**Uwaga:** Skrypt automatycznie wyklucza:
- Testy (`tests/`, `phpunit.xml.dist`)
- Zależności deweloperskie (Composer `--no-dev`)
- Pliki konfiguracyjne (`.git`, `.env`, itp.)

### Automatyczny release na GitHub

System używa **Semantic Versioning** (MAJOR.MINOR.PATCH) i **Conventional Commits** do automatycznego generowania changelog.

#### Sposób 1: Automatyczne wersjonowanie (zalecane)

1. **Użyj GitHub Actions:**
   - Przejdź do Actions → "Build and Release"
   - Kliknij "Run workflow"
   - Wybierz typ bumpu:
     - `patch` - poprawki błędów (0.1.0 → 0.1.1)
     - `minor` - nowe funkcje (0.1.0 → 0.2.0)
     - `major` - breaking changes (0.1.0 → 1.0.0)
   - Workflow automatycznie:
     - Zaktualizuje wersję w plikach
     - Wygeneruje changelog z commitów
     - Utworzy tag i release
     - Zbuduje paczkę ZIP

#### Sposób 2: Ręczne wersjonowanie

1. **Lokalnie:**
   ```bash
   # Zwiększ wersję
   ./scripts/version-bump.sh patch  # lub minor, major
   
   # Wygeneruj changelog
   ./scripts/generate-changelog.sh
   
   # Commit i push
   git add kasumi-ai-generator.php readme.txt
   git commit -m "chore: bump version to X.Y.Z"
   git tag -a "vX.Y.Z" -m "Release vX.Y.Z"
   git push origin main --tags
   ```

2. **GitHub automatycznie:**
   - Wykryje nowy tag
   - Zbuduje paczkę
   - Utworzy release z changelog

#### Format commitów (Conventional Commits)

Aby changelog był automatycznie generowany, używaj formatu:

```
feat: dodano nową funkcję
fix: naprawiono błąd
perf: optymalizacja wydajności
refactor: refaktoryzacja kodu
docs: aktualizacja dokumentacji
test: dodano testy
chore: zmiany techniczne
```

Przykłady:
- `feat: dodano eksport ustawień`
- `fix: naprawiono błąd w REST API`
- `feat(ui): poprawiono responsywność sekcji harmonogramu`

#### Co jest wykluczone z paczki ZIP

- Testy (`tests/`, `phpunit.xml.dist`)
- Zależności deweloperskie
- Pliki konfiguracyjne (`.git`, `.env`, itp.)
- Skrypty buildowe (`scripts/`)

Paczka zawiera tylko pliki potrzebne do działania wtyczki.

## Autor

Marcin Dymek (KemuriCodes)

## Licencja

GPL-2.0-or-later

