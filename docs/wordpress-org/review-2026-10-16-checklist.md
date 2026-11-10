# Runda 4 — review `treetank-trans-1.23.4.zip` (2026-10-16)

Review ID: `R ❗ACT treetank-trans/jmarianski/16Oct26/T1 16Oct26/4.3 (P0TDX357593HGN)`

Recenzent zaznacza, że podaje tylko przykłady — każdy punkt obejmuje **wszystkie**
wystąpienia danego wzorca, nie tylko wymienione linie. Kolumna „automat” mówi, która
bramka CI ma to łapać w przyszłości.

## 1. 🔴 Aktywacja na czystej instalacji z `WP_DEBUG`

Zgłoszone: `LogsManager::maybe_cleanup_old_logs()` czyta `$settings['logs_retention_days']`
bez `??` → `Undefined array key` (LogsManager.php:736) na pierwszym `admin_init`.

- [x] Poprawić odczyt `logs_retention_days` (brak klucza = domyślna retencja, bez warningu)
- [x] Przejrzeć pozostałe bezpośrednie odczyty `$settings['…']` z `polytrans_settings` — grep + smoke na wszystkich ekranach: brak kolejnych
- [x] Aktywacja nic nie wypisuje (brak „unexpected output”)
- [x] Tabele tworzone przez `dbDelta` z `$wpdb->prefix` i `get_charset_collate()` — sprawdzić, że wszystkie 4 istnieją po aktywacji
- [x] Zależności (Polylang, rozszerzenia PHP, wersja WP/PHP) sprawdzane przed użyciem, z komunikatem zamiast błędu
- [x] Style/skrypty tylko na naszych ekranach (smoke: tylko `admin.php?page=polytrans*` i edytor wpisu); `@keyframes spin/fadeIn/slideDown` dostały prefiks
- [x] Aktywacja nie łączy się z zewnętrznymi serwerami (Guideline 7)
- [x] **Automat:** job `activation-smoke` — świeży WP, `WP_DEBUG`+`WP_DEBUG_LOG`, aktywacja, przejście zalogowanym adminem po kokpicie, edytorze wpisu i wszystkich ekranach wtyczki; fail przy dowolnym wpisie w `debug.log` pochodzącym z wtyczki, przy wyjściu z aktywacji, braku tabeli, naszych assetach na kokpicie lub zewnętrznym żądaniu HTTP

## 2. 🔴 Bezpośrednia integracja z dostawcami AI (WP 7.0 AI Client)

Zgłoszone: 38 wystąpień URL-i API (Anthropic, Gemini, OpenAI, DeepSeek w docs).

- [x] Ustalić, co daje core AI Client w 7.0 i czy istnieje pakiet compat dla WP < 7.0 (research)
- [x] Decyzja: bezpośrednie integracje zostają (Responses API i reasoning effort dla OpenAI 5.6+, zadania dłuższe niż 30 s, pełne `usage` do kosztów); opcjonalny dostawca „WordPress AI Client” w backlogu (`docs/roadmap/BACKLOG.md`), zapowiedziany w odpowiedzi
- [x] `docs/` w ZIP-ie — cały katalog wyłączony w `.distignore` (18 z wystąpień URL-i API było w dokumentacji); od 1.24.2
- [x] Odpowiedź do recenzenta z uzasadnieniem — szkic w `reply-2026-10-16.md`

## 3. 🔴 cURL zamiast HTTP API

Zgłoszone: `includes/Core/BackgroundProcessor.php:334 curl_exec($ch)`.

- [x] Usunąć fallback cURL (i fallback `file_get_contents` po HTTP) z `BackgroundProcessor::spawn()` — tylko `wp_remote_post()`
- [x] **Automat:** test architektury: zakaz `curl_*()` i `file_get_contents('http…')`/`fopen` po URL w `includes/`

## 4. 🔴 Sanityzacja — przekazywanie `$_COOKIE`

Zgłoszone: `AsyncJobRunner.php:54` — `'cookies' => $_COOKIE ?? []`, wszystkie ciasteczka bez sanityzacji.

- [x] ~~Przekazywać tylko ciasteczka uwierzytelniające~~ — wcale: worker jest `nopriv` i uwierzytelnia się tokenem HMAC, żaden typ zadania nie czyta bieżącego użytkownika
- [x] Przejrzeć inne użycia `$_COOKIE`/`$_SERVER` przekazywane dalej
- [x] **Automat:** lista sniffów, których **nie wolno** wyciszać `phpcs:ignore` (recenzent czyta kod, nie nasze adnotacje): `curl_*`, `RestrictedVariables.cache_constraints___COOKIE`, `EscapeOutput.OutputNotEscaped`

## 5. 🔴 Escapowanie przy `echo`

Zgłoszone: `echo TemplateRenderer::render(...)` (12 wystąpień, m.in. TranslationSettings, AssistantsMenu,
UsageMetaBox `echo $html`) oraz `wp_add_inline_style` w WorkflowMetabox.

- [x] Każde wyjście szablonu przez `wp_kses($html, TemplateRenderer::allowed_html())` w miejscu `echo` (wszystkie ~21 wywołań, nie tylko 12 zgłoszonych)
- [x] Lista dozwolonego HTML (`Core\AdminMarkup`) pokrywa wszystko, czego używają szablony (formularze, `data-*`, `aria-*`) + wyjście `wp_editor()` i hooków dostawców
- [x] W trybie debug: log, gdy `wp_kses` coś wyciął (żeby smoke złapał zepsuty formularz) — złapał od razu: `list-style`, a na WP 6.0 `display:none` (zakładki ustawień) i `minmax()`; naprawione (`safe_style_css` wokół naszego `wp_kses`, klasa CSS zamiast `minmax()`)
- [x] Też poza szablonami: `OpenAISettingsProvider` (`ReasoningEffortField`, `<option selected>`), inline CSS w panelu OpenAI
- [x] CSS metaboxa workflowów do pliku w `assets/css/` zamiast `wp_add_inline_style`
- [x] **Automat:** test architektury: zakaz `echo TemplateRenderer::render` / `echo $html` bez funkcji escapującej w tej samej instrukcji

## Przed wysłaniem

- [x] `composer test`, `phpcs`, Plugin Check: 0 errors (pipeline v1.24.1)
- [x] `./dev.sh smoke` (z nowymi asercjami) na bieżącym WP i na „Requires at least” (`activation-smoke` w pipeline v1.24.1)
- [x] Wydanie 1.24.2 (bez `docs/`), upload `treetank-trans-1.24.2.zip` w „Add your plugin” (2026-11-10, z notką w Additional Information)
- [x] Test na WordPress Playground (SQLite, loopback) — Playground CLI z ZIP-em 1.24.2: aktywacja, 22 ekrany, `debug.log` czysty, zapis asystenta przez AJAX, worker async przez loopback; w CI wariant `SMOKE_DB=sqlite` w `activation-smoke`
- [ ] Po akceptacji: 1.25.0 — dostawca AI Client, tokenizacja embedów, walidatory (backlog)
- [x] Odpowiedź na email (2026-11-10, treść jak w `reply-2026-10-16.md`)
