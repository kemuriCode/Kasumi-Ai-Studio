=== Kasumi – Full AI Content Generator ===
Contributors: kemuricodes
Donate link: https://kemuri.codes
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 8.1
Stable tag: 0.1.8
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Tags: ai, openai, gemini, pixabay, wp-cron

Generate SEO-friendly posts, featured images (Imagic+Pixabay) and AI comments using the latest AI models: GPT-5.1, GPT-4o (OpenAI) and Gemini 3 (Google). Full backward compatibility with older models (GPT-4.1, GPT-4o-mini, Gemini 2.0 Flash). Fully automated with WP-Cron.

== Description ==

Kasumi – AI Content & Image Generator automates long-form posts, featured images and smart comment suggestions, powered by cutting-edge AI models. **Full support for the newest models: GPT-5.1, GPT-4o (OpenAI) and Gemini 3 (Google)**. Backward compatible with all previous models including GPT-4.1, GPT-4o-mini, Gemini 2.0 Flash, and older versions. Choose the perfect model for your needs and budget! Experience the latest in AI technology with native integrations for OpenAI, Google Gemini, Imagic and Pixabay. Configure WP-Cron schedules, custom prompts, and moderation workflows to keep your WordPress site fresh without manual workload.

=== Key features ===

* **AI content engine with full model support - from latest to legacy (OpenAI GPT-5.1/GPT-4o + Google Gemini 3)**
	+ **Newest models:** GPT-5.1, GPT-4o (OpenAI) and Gemini 3 (Google) for cutting-edge performance.
	+ **All models supported:** Backward compatible with GPT-4.1, GPT-4o-mini, Gemini 2.0 Flash, and older versions - choose what works best for you!
	+ Generate SEO-ready posts with titles, headings, excerpts, meta descriptions and internal-link prompts using any available model.
	+ Multi-language support, custom system/user prompts, and JSON logs for editorial review.
	+ Outputs native Gutenberg blocks (paragraphs, headings, lists, quotes, images) so no "Classic" block conversion is required, while still working with classic editor and page builders.
= Does it create native Gutenberg blocks? =
Yes. Markdown returned by the AI is mapped to core blocks (paragraph, heading, list, quote, image, code, separator). If an element is unknown it falls back to a `core/html` block, so everything stays editable. To inspect the block tree you can run:

```
wp eval 'print_r( parse_blocks( get_post(123)->post_content ) );'
```

(Replace `123` with the generated post ID.)

* **AI featured images (Imagic + Pixabay)**
	+ Fetch topic-matched photos via Pixabay and apply consistent branding with Imagic overlays.
	+ Auto-generate ALT text and titles for accessibility/SEO.
	+ Cache images locally, set as featured image automatically.
* **AI comment assistant**
	+ Draft suggested replies, summarize long threads or create conversation starters.
	+ Works alongside Akismet/spam plugins—AI suggestions never auto-approve without your consent.
* **WP-Cron automation & logging**
	+ Schedule hourly/daily/weekly runs with queue management to avoid API limits.
	+ Detailed logs for content, images and comments; optional preview mode for safe testing.
	+ Diagnostics tab warns about missing PHP extensions or outdated versions.
* **Developer-friendly**
	+ Clean OOP architecture with filters and actions for extending prompts, outputs, logging.
	+ Supports CPTs/taxonomies via hooks; easy to integrate with custom workflows.

=== Use cases ===

* Auto-draft posts for editors to polish later.
* Refresh old articles with new featured images and AI comments.
* Keep niche sites updated with multi-language content.
* Give moderators AI-suggested replies or summaries to speed up moderation.

=== Krótki opis (PL) ===

Kasumi automatyzuje tworzenie treści na WordPressie z pełnym wsparciem dla najnowszych i starszych modeli AI:

* generuje wpisy przy użyciu najnowszych modeli GPT-5.1, GPT-4o (OpenAI) oraz Gemini 3 (Google),
* pełna kompatybilność wsteczna - obsługuje także starsze modele (GPT-4.1, GPT-4o-mini, Gemini 2.0 Flash i inne) - wybierz odpowiedni dla siebie!
* tworzy obrazy wyróżniające z Imagic + Pixabay,
* proponuje komentarze AI,
* działa w oparciu o harmonogram WP-Cron i logi diagnostyczne.

== Installation ==

1. Upload the plugin files to `/wp-content/plugins/ai-content-image-generator-openai-gemini` or install from the WordPress plugin directory.
2. Activate the plugin through the “Plugins” screen in WordPress.
3. Go to **Settings → Kasumi AI** to enter your OpenAI / Google Gemini / Pixabay API keys and configure schedules.
4. (Optional) Visit the **Diagnostics** tab to confirm PHP version and required extensions (cURL, mbstring).

== Frequently Asked Questions ==

= Does this plugin use OpenAI or Google Gemini? =
Yes. Kasumi supports the **newest AI models: GPT-5.1, GPT-4o (OpenAI) and Gemini 3 (Google)**, plus full backward compatibility with all previous models including GPT-4.1, GPT-4o-mini, Gemini 2.0 Flash, and older versions. You can pick OpenAI, Google Gemini, or a hybrid mode per task (content, images, comments). All models available in your API accounts are automatically listed - choose the perfect one for your needs! API keys are stored securely via the WordPress options API.

= Can it fully automate blog posts? =
Yes. Configure WP-Cron (hourly, daily, custom) to generate drafts or published posts with custom prompts.

= How does AI image generation work? =
Kasumi builds prompts from your post topic, fetches images via Pixabay, optionally applies Imagic overlays, and sets the result as the featured image with ALT text.

= Is the output SEO safe? =
The plugin generates structured content (headings, summaries, internal links) but you should still review AI drafts before publishing to ensure accuracy and tone.

= Does it work with page builders and custom post types? =
Yes. It uses standard `post` objects by default but can be filtered to support CPTs, taxonomies, and custom fields/builders.

= What happens if my server lacks required extensions? =
The Diagnostics tab and admin notices alert you if PHP < 8.1 or extensions like cURL/mbstring are missing, so you can fix issues before enabling automation.

== Screenshots ==

1. AI content generator settings with OpenAI and Gemini providers.
2. Automated featured image pipeline using Imagic + Pixabay.
3. AI comment assistant panel inside WordPress comments.
4. WP-Cron scheduler for auto-posting and logging.
5. Diagnostics tab showing PHP version and required extensions.

== Changelog ==

= 0.1.8 =
* Panel „Kontrola WP-Cron” został przebudowany na natywne formularze WordPressa (bez JavaScript) – wszystkie akcje start/stop/restart/publikuj działają natychmiast i wyświetlają komunikaty w standardowych notyfikacjach.
* Dodano czytelny podgląd stanu automatyzacji (aktywna/zatrzymana/niedostępna), najbliższych zdarzeń oraz kolejki komentarzy wraz z blokującym komunikatem, jeśli cron nie może się uruchomić.
* Usunięto niepotrzebne endpointy REST/AJAX i logikę JS sterującą cronem, aby uprościć proces i spełnić wymagania bezpieczeństwa WordPress.org (sanityzacja, csfr, late escaping).

= 0.1.7 =
* Dodano panel kontroli WP-Cron (start/stop/restart, wymuszenie publikacji i kolejki), który resetuje i monitoruje harmonogram bezpośrednio z ustawień Kasumi.
* Scheduler obsługuje stan „wstrzymany”, umożliwia ręczne uruchamianie zadań mimo pauzy oraz udostępnia REST API dla nowych przycisków.
* Wykresy statystyk wypełniają brakujące dni (0 wartości) i aktualizują się prawidłowo; licznik dni użytkowania zapisuje datę instalacji tylko raz.
* Uzupełniono tłumaczenia (PL/EN/DE/ES) i testy jednostkowe dla nowych funkcji oraz usprawniono komunikaty translatorskie.

= 0.1.6 =
* Dodano presety proporcji obrazka (16:9, 4:3, 1:1, 2:3) z automatycznym uzupełnianiem pól szerokości/wysokości oraz poprawionym UI na urządzeniach mobilnych.
* Panel harmonogramu zyskał responsywne filtry i bardziej neutralne placeholdery dla tytułu.
* Scheduler automatycznie blokuje WP-Cron, gdy brakuje kluczy API lub moduł jest wyłączony, i prezentuje ostrzeżenie w karcie „Status modułu AI”.
* Wprowadzono rejestrowanie użycia tokenów/kosztów dla OpenAI i Gemini bezpośrednio w `StatsTracker`, aby statystyki odzwierciedlały realne zużycie.
* Naprawiono wcześniejsze ostrzeżenia dot. `load_plugin_textdomain` i dodano jasny komunikat o stanie automatyzacji.

= 0.1.5 =
* Poprawiono zgodność wersji Stable tag w readme.txt

= 0.1.4 =
* Dodano trzy style nakładki tekstu (nowoczesny, klasyczny, oldschool) z wbudowanym wsparciem dla polskich znaków.
* Nowe opcje grafiki: kontrola koloru/siły nakładki, własne wyrównanie i pozycja tekstu, możliwość wyłączenia podpisu, niestandardowa szerokość/wysokość płótna.
* Ujednolicono tłumaczenia en/de/es dla nowych opcji graficznych i opisów panelu.

= 0.1.3 =
* Poprawiono escaping danych wyjściowych zgodnie z wytycznymi WordPress.org (escape late)
* Weryfikacja kompletności bezpieczeństwa wtyczki

= 0.1.2 =
* Naprawiono błędy automatycznego skanowania WordPress.org:
  * Dodano nagłówek License (GPLv2 or later)
  * Usunięto przestarzałą funkcję load_plugin_textdomain()
  * Zaktualizowano "Tested up to" do WordPress 6.8
  * Zmieniono Text Domain na kasumi-full-ai-content-generator zgodnie ze slugiem wtyczki
  * Ujednolicono nazwę wtyczki w readme.txt

= 0.1.1 =
* AI-generated markdown now serializes directly to native Gutenberg blocks (paragraph/list/heading/quote/image/code/separator) with graceful `core/html` fallback.
* Added WP-CLI snippet in documentation to inspect the generated block tree.

= 0.1.0 =
* Initial release: OpenAI + Gemini content engine, Imagic + Pixabay featured images, AI comments, WP-Cron automation, diagnostics tab.
