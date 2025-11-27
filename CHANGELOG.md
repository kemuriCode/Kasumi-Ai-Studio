# Changelog

Wszystkie znaczące zmiany w tym projekcie będą udokumentowane w tym pliku.

Format jest oparty na [Keep a Changelog](https://keepachangelog.com/pl/1.0.0/),
a wersjonowanie używa [Semantic Versioning](https://semver.org/lang/pl/).

## [0.1.2] - 2025-01-XX

### 🐛 Poprawki błędów
- Naprawiono błędy automatycznego skanowania WordPress.org:
  - Dodano nagłówek License (GPLv2 or later) w plugin header
  - Usunięto przestarzałą funkcję `load_plugin_textdomain()` (niepotrzebna od WordPress 4.6)
  - Zaktualizowano "Tested up to" do WordPress 6.8
  - Zmieniono Text Domain z `kasumi-ai-generator` na `kasumi-full-ai-content-generator` zgodnie ze slugiem wtyczki w WordPress.org
  - Ujednolicono nazwę wtyczki w readme.txt z nazwą w plugin header

## [0.1.0] - 2025-11-27

### ✨ Nowe funkcje
- Dodano opcję włączania/wyłączania całej wtyczki
- Dodano listę logów w sekcji diagnostyki
- Dodano import/export ustawień (JSON)
- Dodano opcję usuwania tabeli przy deaktywacji
- Dodano reset ustawień do domyślnych
- Dodano automatyczne wersjonowanie i generowanie changelog

### 🐛 Poprawki błędów
- Naprawiono błąd rejestracji REST API routes (użycie hooka `rest_api_init`)

### 🧪 Testy
- Dodano testy jednostkowe dla nowych funkcji
- Dodano testy integracyjne dla REST API i migracji bazy danych

### 📚 Dokumentacja
- Zaktualizowano README z instrukcjami release
- Dodano dokumentację Conventional Commits

