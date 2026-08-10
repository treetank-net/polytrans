# Plan zgłoszenia do katalogu WordPress.org

Stan bazowy zmierzony 2026-08-11 na WP 6.8 + Plugin Check 2.0.0
(`wp plugin check polytrans --exclude-directories=vendor,node_modules`):
**29 ERROR / 132 WARNING**. Cel: **0 ERROR** i świadomie zaakceptowane WARNING-i.

**Stan po Etapie 1–2: Plugin Check 0 ERROR / 26 WARNING** (mierzone na paczce zbudowanej
przez `.distignore`, nie na drzewie roboczym — inaczej nie widać błędów pakowania).
PHPCS po naprawie WPCS i sanityzacji wejścia: **0 błędów**. Warningi Plugin Check
pozostają do świadomej oceny przed submitem.

Weryfikacja wykonana 2026-08-10:

- PHPCS: 0 błędów.
- Pest: Unit — 480 testów / 1357 asercji; Architecture — 7 testów / 61 asercji.
  Suite'y uruchomiono w osobnych procesach, tak jak w CI.
- Plugin Check na paczce dystrybucyjnej: 0 ERROR / 26 WARNING
  (WP 6.8, Plugin Check 2.0.0).

Jak odtworzyć pomiar — patrz `docs/development/plugin-check.md`.

---

## Etap 1 — Blokery Plugin Check (wymagane przed submitem)

| # | Problem | Plik | Status |
|---|---|---|---|
| 1.1 | Stable tag, plugin header, constant and changelog are aligned at 1.19.2 | `readme.txt`, `polytrans.php`, `CHANGELOG.md` | ☑ |
| 1.2 | `outdated_tested_upto_header`: Tested up to 6.9 < 7.0 | `readme.txt` | ☑ |
| 1.3 | `readme_mismatched_header_requires`: readme 5.0 ≠ header 5.3 | `readme.txt`, `polytrans.php` | ☑ |
| 1.4 | `wp_function_not_compatible_with_requires_wp` ×7 — `wp_timezone()`, `current_datetime()`, `wp_date()` (WP 5.3), `str_starts_with()` (WP 5.9) | podniesienie `Requires at least` do 6.0 | ☑ |
| 1.5 | `hidden_files` ×2 (`.codex`, `.rsync-exclude`) + zepsuty symlink `AGENTS.md` + `.playwright-mcp/`, `cache/`, `prompts/`, `CONTEXT.md`, `Makefile`, `phpcs*.xml`, `docker-compose.test.yml` w paczce | `.distignore` + `.gitlab-ci.yml` | ☑ |
| 1.6 | `EscapeOutput.OutputNotEscaped` ×7 — `phpcs:ignore` nad wielolinijkowym `echo` nie zakrywa linii 94–100 | `includes/Core/UsageMetaBox.php:92` | ☑ |
| 1.7 | `PreparedSQL.NotPrepared` ×8 — adnotacje wymieniają `InterpolatedNotPrepared`, a zapala się `NotPrepared` | `includes/PromptRefinement/RefinementRunStorage.php`, `includes/PostProcessing/Managers/WorkflowStorageManager.php` | ☑ |
| 1.8 | `AlternativeFunctions.strip_tags` ×3 | `Core/TextMetrics.php`, `Testing/PostTestContextBuilder.php`, `Testing/RecentPostsProvider.php` | ☑ |
| 1.9 | Brak `readme.txt` w formacie wp.org (był tylko `README.md` z markdownowymi nagłówkami — to dlatego parser katalogu przyciął opis) | `readme.txt` | ☑ |

## Etap 2 — Bezpieczeństwo (recenzent to znajdzie, nawet jeśli skaner nie zgłasza ERROR-a)

| # | Problem | Plik | Status |
|---|---|---|---|
| 2.1 | `permission_callback` zwraca `true`, gdy sekret jest pusty → na świeżej instalacji `POST /polytrans/v1/translation/translate` jest publicznym, nieuwierzytelnionym endpointem uruchamiającym płatne wywołania AI | `includes/Core/TranslationExtension.php:653` | ☑ |
| 2.2 | Porównanie sekretu przez `!==` zamiast `hash_equals()` (timing) | `includes/Core/TranslationExtension.php`, `includes/Receiver/Managers/SecurityManager.php` | ☑ |
| 2.3 | `InputNotSanitized` ×27 + `MissingUnslash` ×4 — prompty z `$_POST` bez `wp_unslash()`/`sanitize_textarea_field()` | `Menu/PostprocessingMenu.php`, `Menu/AssistantsMenu.php`, `Core/PostAutocomplete.php` | ☑ |
| 2.4 | ~100 wyjść `{{ }}` w szablonach bez escapowania, przy `autoescape => false` — m.in. nazwy modeli z odpowiedzi API (`templates/admin/usage/page.twig`) | `templates/**/*.twig` | ☑ |
| 2.5 | 4 akcje AJAX miały rejestracje w wrapperach kompatybilności i klasach właściwych | `Menu/PostprocessingMenu.php`, `Menu/SettingsMenu.php`, `class-polytrans.php` | ☑ |
| 2.6 | `wp_ajax_nopriv_polytrans_async_worker` autoryzuje HMAC-tokenem, nie nonce — poprawne, ale nieudokumentowane w kodzie | `Core/AsyncJobRunner.php:152` | ☑ |

## Etap 3 — Pipeline (żeby to się nie cofnęło)

Marcowe poprawki cofnęły się w kodzie dodanym później (feature „usage" wprowadził
7 nowych błędów escapowania), bo **nic tego nie bramkowało**. CI miało tylko Pest
oraz `php -l` z `allow_failure: true`. Teraz ma cztery bramki: `unit-tests`,
`php-syntax-check` (bez `allow_failure`), `phpcs` i `plugin-check`.

| # | Zadanie | Status |
|---|---|---|
| 3.1 | `wp-coding-standards/wpcs` 2.3 → `^3.1` + `phpcsstandards/phpcsutils`. Poprzednio `PrefixAllGlobalsSniff.php:280` rzucał fatal na PHP 8.3 → „*checking has been aborted*" dla **wszystkich** 121 plików. Zainstalowane 3.4.1, zero `Internal.Exception` | ☑ |
| 3.2 | Przepisany `phpcs.xml`: bez sniffów wcięć (35 251 z 35 661 naruszeń to „taby vs spacje"), z zachowanym bezpieczeństwem / i18n / escapowaniem. Po zmianie: 162 naruszenia w 23 sniffach zamiast 35 661 w 17 | ☑ |
| 3.3 | Job CI `phpcs` — blokujący (`--warning-severity=0`, bez `allow_failure`) | ☑ |
| 3.4 | Job CI `plugin-check` (WP 6.8 + PCP w Dockerze) — blokujący na ERROR, raport jako artefakt, także dla tagów | ☑ |
| 3.5 | Weryfikacja paczki wbudowana w job `build`: pliki ukryte, symlinki, pliki dev, obecność `readme.txt` | ☑ |
| 3.6 | Spójność wersji w jobie `build`: tag git = `Version:` = `POLYTRANS_VERSION` = `Stable tag` = sekcja w `CHANGELOG.md` | ☑ |
| 3.7 | Jedna lista wykluczeń (`.distignore`) zamiast rozjechanych `.gitattributes` i `.gitlab-ci.yml` | ☑ |

## Etap 4 — Odpowiedź do recenzenta

- **Nazwa/slug** — zgodnie z `REVIEW-ISSUES.md` argumentujemy za `polytrans`, bez zmian w kodzie.
- **Opis** — `readme.txt` skrócony do limitu widoczności katalogu.
- **Google Translate** — usunięty z opisu, schowany za `POLYTRANS_ENABLE_GOOGLE` (domyślnie wyłączony), katalog `includes/Providers/Google` nie wchodzi do paczki.
- **External services** — sekcja `== External services ==` w `readme.txt`, z danymi/ToS/Privacy per provider.
- Odpowiedź ma być krótka: bez listy zmian, tylko kontekst dotyczący nazwy.

## Etap 5 — Weryfikacja przed submitem

1. Unit i Architecture osobno (jak w CI):
   `docker compose -f docker-compose.test.yml run --rm polytrans-test php -d memory_limit=1G vendor/bin/pest --testsuite=Unit`
   `docker compose -f docker-compose.test.yml run --rm polytrans-test php -d memory_limit=1G vendor/bin/pest --testsuite=Architecture`
2. `composer phpcs` — zero błędów
3. Plugin Check na zbudowanym ZIP-ie — zero ERROR-ów
4. Ręczny test na czystym WP: aktywacja → konfiguracja providera → tłumaczenie → workflow
5. Bump wersji + CHANGELOG + tag (patrz `docs/RELEASE.md`)

---

## Decyzje projektowe podjęte w trakcie

- **`Requires at least: 6.0`** (z 5.0/5.3). Kod używa `str_starts_with()` (WP 5.9) i API
  timezone z 5.3; przy `Requires PHP: 8.1` deklarowanie WP 5.0 i tak było fikcją.
- **`readme.txt` jest źródłem prawdy dla katalogu**, `README.md` zostaje readme
  GitHubowym bez nagłówków wp.org — dwa zestawy nagłówków to gwarantowany rozjazd.
- **`autoescape` w Twigu zostaje `false`**, a escapowanie jest jawne w szablonach.
  Włączenie autoescape wymagałoby usunięcia ~300 ręcznych wywołań `esc_*` z szablonów
  (podwójne escapowanie) i przejrzenia każdego `|raw` — to refaktor na osobny release,
  nie na zgłoszenie. Uwaga: `Templating/TwigEngine.php` renderuje **prompty do AI**,
  nie HTML, i tam autoescape musi zostać wyłączony.
- **Interpolacja nazw tabel** w SQL zostaje, z dokładnymi adnotacjami `phpcs:ignore`
  i uzasadnieniem — nazwa pochodzi z `$wpdb->prefix`, nie z requestu.
- **Rejestracje AJAX (2.5) zostały ujednolicone.** Właścicielami endpointów są teraz
  klasy domenowe: `UserAutocomplete`, `PostAutocomplete`, `WorkflowManager`, a wczesną
  rejestrację walidacji klucza zachowuje `SettingsMenu`. Wrappery kompatybilności nadal
  mogą być wywołane bezpośrednio przez integracje, ale nie rejestrują drugiego callbacka.
  Test architektoniczny sprawdza, że literalne `wp_ajax_*` występują tylko raz.
