# Release v0.1.3

## 🔒 Bezpieczeństwo

- Poprawiono escaping danych wyjściowych zgodnie z wytycznymi WordPress.org:
  - Zmieniono `$next_run` i `$last_error` na "escape late" (escapowanie podczas wyświetlania zamiast wcześniej)
  - Wszystkie dane wyjściowe są teraz poprawnie escapowane przed renderowaniem

## ✅ Zgodność z wytycznymi WordPress.org

- Weryfikacja kompletności escapingu danych wyjściowych
- Potwierdzenie sanityzacji wszystkich danych wejściowych
- Weryfikacja użycia nonce dla wszystkich formularzy

## 📦 Instalacja

1. Pobierz plik `kasumi-ai-generator.zip`
2. Przejdź do WordPress → Wtyczki → Dodaj nową → Wgraj wtyczkę
3. Wybierz pobrany plik ZIP i zainstaluj

## 🔗 Linki

- [WordPress.org Plugin Directory](https://wordpress.org/plugins/kasumi-full-ai-content-generator/)
- [Dokumentacja](https://github.com/kemuriCode/Kasumi-AI-Content-Generator)

