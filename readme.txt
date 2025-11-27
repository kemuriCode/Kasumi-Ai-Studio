=== AI Content & Image Generator – OpenAI, Gemini, Imagic, Pixabay ===
Contributors: kemuricodes
Donate link: https://kemuri.codes
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 8.1
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Tags: ai, openai, gemini, content generator, image generator, pixabay, wp-cron, comments

Generate SEO-friendly posts, featured images (Imagic+Pixabay) and AI comments using OpenAI & Gemini. Fully automated with WP-Cron.

== Description ==

Kasumi – AI Content & Image Generator automates long-form posts, featured images and smart comment suggestions, thanks to native integrations with OpenAI, Google Gemini, Imagic and Pixabay. Configure WP-Cron schedules, custom prompts, and moderation workflows to keep your WordPress site fresh without manual workload.

=== Key features ===

* **AI content engine (OpenAI + Google Gemini)**
	+ Generate SEO-ready posts with titles, headings, excerpts, meta descriptions and internal-link prompts.
	+ Multi-language support, custom system/user prompts, and JSON logs for editorial review.
	+ Outputs native Gutenberg blocks (paragraphs, headings, lists, quotes, images) so no “Classic” block conversion is required, while still working with classic editor and page builders.
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

Kasumi automatyzuje tworzenie treści na WordPressie:

* generuje wpisy z użyciem OpenAI i Gemini,
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
Yes. You can pick OpenAI, Google Gemini, or a hybrid mode per task (content, images, comments). API keys are stored via the WordPress options API.

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

= 0.1.1 =
* AI-generated markdown now serializes directly to native Gutenberg blocks (paragraph/list/heading/quote/image/code/separator) with graceful `core/html` fallback.
* Added WP-CLI snippet in documentation to inspect the generated block tree.

= 0.1.0 =
* Initial release: OpenAI + Gemini content engine, Imagic + Pixabay featured images, AI comments, WP-Cron automation, diagnostics tab.
