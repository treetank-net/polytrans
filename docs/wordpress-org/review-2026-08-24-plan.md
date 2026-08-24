# Plan odpowiedzi na review WordPress.org z 2026-08-24

Źródło: `docs/wordpress-org/review-2026-08-24-email.md`
Review ID: `AUTOPREREVIEW ❗TRM-OWN polytrans/jmarianski/24Aug26/T1 24Aug26/4.2`
Poprzednia runda: `REVIEW-ISSUES.md` (`TRM-DESC ... 15Mar26/3.8`) — tam nazwę **argumentowaliśmy**.

Wszystkie ustalenia poniżej są zweryfikowane w kodzie (numery linii z bieżącego `main`, 1.19.2),
a nie przepisane z maila.

## Czy warunki się zaostrzyły?

Częściowo tak, ale nie tam, gdzie to wygląda:

- **Nowy jest kod `TRM-OWN` w Review ID** (poprzednio `TRM-DESC`). Poza nazwą recenzent bada
  teraz **własność**: konto wp.org ma e-mail `@gmail.com`, a plugin deklaruje `Author: treetank`,
  `Author URI: https://treetank.net`. Mail wprost mówi, że gmail nie jest dowodem tożsamości.
  W marcu tego wątku nie było.
- **Nowa jest sekcja o rdzeniowym AI Client** — WordPress 7.0 (lipiec 2026) ma w rdzeniu
  `WP_AI_Client` + Abilities API + ekran Connectors. To rekomendacja („Please consider migrating"),
  nie wymóg, ale skaner ją zgłasza dla każdego pluginu wołającego providerów bezpośrednio.
- **Reszta to te same wymogi co w marcu**, tylko skaner jest dokładniejszy: sanityzacja,
  nonce/uprawnienia, escapowanie, `permission_callback`. Marcowe `phpcs:ignore` z uzasadnieniem
  „trusted admin" **nie są akceptowane** — recenzent cytuje je jako dowód braku sanityzacji.

## Etap 0 — decyzje (podjęte 2026-08-24)

| # | Decyzja | Ustalenie |
|---|---|---|
| 0.1 | Nazwa i slug | **Zostaje `PolyTrans` / `polytrans`.** Jeszcze jedna runda z obroną. Podstawa: rejestracja POLYTRANS (Okino Computer Graphics, USPTO 87025627) obejmuje oprogramowanie do konwersji formatów geometrii 3D (CAD/CAM), nie tłumaczenie języków — inne towary, inny rynek, brak realnego ryzyka konfuzji. Mail dopuszcza tę ścieżkę: „fix everything else and ask your questions alongside the update". |
| 0.2 | Jak argumentujemy „poly" | „poly" = grecki przedrostek „wiele" (polyglot, polifonia), opisujący wielojęzyczność. **Nie** piszemy, że „poly" pochodzi od Polylanga — to samooskarżenie o portmanteau, którego mail zakazuje wprost. |
| 0.3 | Własność (`TRM-OWN`) | **Wymaga działania po stronie Jacka, niezależnie od nazwy.** Rekomendacja: zmiana e-maila konta wp.org na adres w domenie `treetank.net`. Wariant awaryjny: rekord `TXT` = `wordpressorg-jmarianski-verification` w korzeniu `treetank.net`. `@trans.eu` nie zadziała — to nie domena z `Author URI`. |
| 0.4 | Migracja na rdzeniowy AI Client | **Nie teraz.** Odpowiedź merytoryczna, dodanie jako opcjonalny provider później. `Requires at least: 6.0` wyklucza zależność od WP 7.0. |
| 0.5 | Plan awaryjny na wypadek przegranej obrony | Jeśli recenzent podtrzyma zastrzeżenie: zmieniamy **tylko to, co widzi katalog** (`Plugin Name`, `readme.txt`, text domain = slug), a `polytrans_*` w opcjach, meta, tabelach, namespace REST, handlach i stałych **zostaje** — inaczej zerwiemy dane istniejących instalacji. Koszt zmierzony: 631 wywołań i18n w PHP + 644 w Twigu, sed + regeneracja `.pot`. Marka `PolyTrans` zostaje na GitHubie/GitLabie i w dokumentacji. |

## Etap 1 — zmiana nazwy i slug — **NIEAKTYWNY**

Wstrzymany decyzją 0.1. Odpalamy tylko, jeśli obrona nazwy przepadnie — szczegóły kosztu w 0.5.

## Etap 2 — blokery bezpieczeństwa (recenzent podał konkretne linie)

| # | Problem | Plik | Co robimy | Status |
|---|---|---|---|---|
| 2.1 | `permission_callback` zwraca `true`, gdy `secret_method === 'none'` — nieuwierzytelniony `POST` tworzący posty i uruchamiający płatne wywołania AI | `Core/TranslationExtension.php`, `Receiver/Managers/SecurityManager.php` | Zrobione inaczej niż „usunąć": nowa klasa `Core\EndpointAuth` — tryb bez uwierzytelniania zostaje dostępny (serwery wewnętrzne to uzasadniony przypadek), ale wymaga `define('POLYTRANS_ALLOW_UNAUTHENTICATED_ENDPOINTS', true)` w `wp-config.php`. Opcja zniknęła z UI (pokazuje się tylko przy zdefiniowanej stałej), zapisane `none` bez stałej = endpoint zamknięty + wpis w logu + notice na stronie ustawień, `TranslationSettings` degraduje `none` do `header_bearer` przy zapisie. Test: `tests/Unit/EndpointAuthTest.php`. | ☑ |
| 2.2 | `wp_set_current_user($attribution_user_id)` — użytkownik z `edit_posts` zapisywał workflow z dowolnym `attribution_user` i wykonywał zmiany z jego uprawnieniami | `PostProcessing/WorkflowOutputProcessor.php`, `Menu/PostprocessingMenu.php` | **Feature zostaje, mechanizm zmieniony.** `wp_set_current_user()` odpowiada na dwa pytania naraz — kto jest kredytowany i czyje uprawnienia obowiązują — a potrzebne jest tylko pierwsze. Teraz: `resolve_attribution_user()` wymaga, by wskazany użytkownik istniał **i miał `edit_post` na tym poście**; `with_attribution()` obejmuje podmianą **wyłącznie jeden zapis** i cofa ją w `finally`; pętla łapie `\Throwable`, nie `\Exception`; pole `attribution_user` przy zapisie workflow jest odrzucane, gdy zapisujący nie ma `edit_others_posts`. Testy: 3 przypadki w `tests/Unit/WorkflowOutputProcessorTest.php`. | ☑ |
| 2.3 | Manualne uruchomienie workflow sprawdza tylko `edit_posts`, nie `edit_post` dla przekazanego ID | `PostProcessing/WorkflowManager.php:803` (`ajax_execute_workflow_manual`) | Dodane `current_user_can('edit_post', ...)` dla `translated_post_id` i `original_post_id` w `ajax_execute_workflow_manual`, a także dla ID przekazanych w `test_context` w `ajax_test_workflow` (ta druga ścieżka pozwalała podstawić dowolny post jako kontekst testu). | ☑ |
| 2.4 | Transient autouzupełniania jest **wspólny dla wszystkich użytkowników**, a zapytanie obejmuje `post_status => private` — użytkownik z `edit_posts` dostaje treść cudzych prywatnych postów z cache'u | `Core/PostAutocomplete.php:206,255` | Zrobione oba: nowa metoda `filter_readable_posts()` przepuszcza wyłącznie posty z `edit_post` (użyta w wyszukiwaniu i na liście ostatnich), `get_current_user_id()` wchodzi do klucza transientu, a `ajax_get_post_by_id` wymaga `edit_post` na żądanym ID. `private` zostaje w zapytaniu — teraz jest bezpieczne, a redaktorzy realnie tłumaczą szkice. | ☑ |
| 2.5 | Audyt **wszystkich 62 rejestracji `wp_ajax_*`**: nonce + capability + uprawnienie na konkretnym obiekcie | `includes/**` | Przeskanowane wszystkie 62 rejestracje. Nonce + capability mają wszystkie; trzy wymagały ręcznej weryfikacji, bo są cienkimi delegacjami: `polytrans_schedule_translation` → `handle_schedule_translation()` (`check_ajax_referer` + `edit_post` na `$post_id`), `polytrans_validate_provider_key` → `ajax_validate_provider_key()` (`wp_verify_nonce` na czterech typach nonce + `manage_options`), `polytrans_refresh_logs` (nonce + `manage_options`). Jedyny handler bez nonce i bez capability to worker pętli zwrotnej `polytrans_async_worker` (wariant `wp_ajax_` i `wp_ajax_nopriv_`) — nie ma sesji użytkownika, uwierzytelnia go token HMAC per zadanie porównywany `hash_equals()`. Inwariant przypięty testem `tests/Architecture/AjaxAuthorizationTest.php`: statycznie rozwiązuje callback każdej rejestracji, schodzi o jeden poziom delegacji i wymaga nonce + `current_user_can`; worker jest na jawnej, udokumentowanej allow-liście. | ☑ |

## Etap 3 — sanityzacja, escapowanie, higiena paczki

| # | Problem | Plik | Co robimy | Status |
|---|---|---|---|---|
| 3.1 | Wejścia bez sanityzacji, uzasadniane `phpcs:ignore ... trusted admin` — recenzent cytuje to jako brak sanityzacji. Zgłosił „12 incidences", ale w kodzie jest **59 takich adnotacji** w 7 plikach: `Menu/PostprocessingMenu.php` (27), `Menu/AssistantsMenu.php` (25), `PostProcessing/WorkflowManager.php` (2), `Core/TranslationSettings.php` (2), `Menu/TagTranslation.php`, `PostProcessing/Testing/WorkflowRefinementService.php`, `Assistants/Testing/AssistantRefinementService.php` (po 1) | j.w. | Zrobione. `Core\InputSanitizer::prompt_template()` (rzutowanie na string, `wp_check_invalid_utf8()`, usunięcie bajtów zerowych, normalizacja `\r\n`, limit 200 000 znaków — bez `wp_strip_all_tags()`, bo prompt musi zachować `{{ }}`, JSON i `<schema>`) oraz `::deep()` dla struktur. **Metody statycznej nie da się zarejestrować w `customSanitizingFunctions`** — WPCS odrzuca kandydata poprzedzonego `::` (`ContextHelper::has_object_operator_before`), więc adnotacje zostałyby na miejscu. Dlatego logika ma postać wywoływalną: funkcje `PolyTrans\Core\sanitize_prompt_template()` i `sanitize_input_deep()` w `includes/Core/sanitizers.php`, importowane przez `use function` — sniff widzi wtedy goły `T_STRING`. Ładowane z `Bootstrap::init()`, **nie** przez Composerowe `autoload.files`: plik ma wartownik `ABSPATH`, więc wpis `files` ubijał każdy proces ładujący `vendor/autoload.php` poza WordPressem (w tym całą suitę testów, bez żadnego komunikatu). Wszystkie 60 adnotacji `InputNotSanitized` usunięte, PHPCS 0 błędów. Testy: `tests/Unit/InputSanitizerTest.php` (11 przypadków, w tym „prompt zachowuje wszystko, co czyni go promptem"). | ☑ |
| 3.2 | Zagnieżdżony payload `workflow` i `json_decode($_POST['job_params'])` bez sanityzacji | `Menu/PostprocessingMenu.php:1283,1495` | `sanitize_input_deep()` schodzi rekurencyjnie (limit 20 poziomów), zachowuje typy skalarne (`enabled` musi wrócić jako `true`, nie `"1"`) i **wielkość liter w kluczach** — `sanitize_key()` przemianowałby po cichu każde pole `camelCase`. `json_decode()` nie jest sanityzacją, więc `job_params` sanityzowane jest *przed* dekodowaniem: `json_decode(sanitize_prompt_template(wp_unslash(...)), true)`. Przy okazji `wp_unslash()` przeniesiony na granicę wejścia — payloady `evaluations`/`refinement_history` szły wcześniej surowe do serwisów refinementu, które odslashowywały je same. | ☑ |
| 3.3 | Twig ma `autoescape => false` w **obu** silnikach — escapowanie jest ręczne, a marcowe poprawki już raz się cofnęły (feature „usage" dodał 7 nowych błędów) | `Templating/TemplateRenderer.php:101`, `Templating/TwigEngine.php:121` | Zrobiona bramka: `tests/Architecture/TemplateEscapingTest.php` przechodzi 1048 wyrażeń `{{ }}` w 26 szablonach i wymaga filtra escapującego, literału albo funkcji escapującej samodzielnie. Pomiar pokazał 4 realne luki (nie „wszystko", jak sugerowałby sam `autoescape => false`): dwa `{% set %}` z literałem `'selected'` w `assistants/editor.twig` i dwa trójargumentowe wyrażenia z `__()` — wszystkie domknięte. `autoescape` **zostaje wyłączony**: ~360 miejsc escapuje już jawnie filtrami `|esc_html`/`|esc_attr`/`|esc_url`/`|esc_textarea`, więc przełączenie flagi dałoby podwójne escapowanie — to migracja, nie zmiana flagi, a bramka pilnuje tego samego skutku. | ☑ |
| 3.4 | `require_once(ABSPATH . 'wp-admin/includes/class-wp-list-table.php')` — **klasa nie jest w ogóle używana** (0 wystąpień `WP_List_Table` w kodzie), strona logów ma własną paginację | `Core/LogsManager.php:783` | Linia usunięta. | ☑ |
| 3.5 | `site_url('/wp-json/polytrans/v1/translation/receive-post')` — sztywna ścieżka REST | `Scheduler/TranslationHandler.php:331` (jedyne wystąpienie `wp-json` w kodzie) | Zamienione na `rest_url('polytrans/v1/translation/receive-post')`. | ☑ |
| 3.6 | `twig/twig` v3.22.1 → v3.28.0 | `composer.lock`, `vendor/` | `twig/twig` 3.22.1 → **3.28.0**, `composer.lock` zaktualizowany, testy przechodzą. `composer audit` pokazuje jeszcze dwa ostrzeżenia (`phpunit/phpunit`, `symfony/process`) — oba w zależnościach **dev**, a paczka budowana jest przez `composer install --no-dev`, więc do wtyczki nie trafiają. | ☑ |
| 3.7 | `languages/polytrans-pl_PL.po` w paczce | `.distignore` | `/languages/*.po` i `/languages/*.mo` dodane do `.distignore`. Symulacja `rsync --exclude-from=.distignore` potwierdza, że w paczce zostaje wyłącznie `languages/polytrans.pot` (recenzent wymienia `.po`, `.mo` i `.php`, nie `.pot`). | ☑ |
| 3.8 | Ten katalog nie może wejść do paczki | `.distignore` | Dodać `/docs/wordpress-org`. | ☑ |

## Etap 4 — treść odpowiedzi

Pełny szkic: `docs/wordpress-org/reply-2026-08-24-draft.md`. Trzy akapity: obrona nazwy,
własność, AI Client. Bez listy poprawek, bez historii sporu, bez liczby pobrań konkurenta,
bez wyjaśnień do Guideline 11 (sprawdzone: dwa dismissible `admin_notices` w
`class-polytrans.php:332,412`, wynik akcji użytkownika, zero upsellów).

**Kolejność:** najpierw wgranie poprawionej paczki przez „Add your plugin", potem odpowiedź
w tym samym wątku. Recenzent recenzuje paczkę, nie diff.

## Etap 5 — weryfikacja przed ponownym wgraniem

| # | Kontrola | Wynik 2026-08-24 |
|---|---|---|
| 1 | `composer test` — suita Unit | **499 passed** (1388 asercji), 1 warning (`BootstrapTest`, wcześniejszy) |
| 2 | `composer test` — suita Architecture | **11 passed** (68 asercji); doszły `AjaxAuthorizationTest`, `TemplateEscapingTest` i dwa inwarianty w `NamingConventionsTest` |
| 3 | `composer phpcs` | **0 błędów**. Adnotacje sanityzacyjne **zostają** — patrz niżej, to nie jest regres |
| 4 | `./dev.sh smoke` — WP 7.1 + Plugin Check 2.1.0 na paczce dystrybucyjnej | aktywacja OK, **0 ERROR, 53 WARNING** (33 AIProvider, 12 DirectDatabaseQuery, 5 SlowDBQuery, 1 PostNotIn, 1 SchemaChange, 1 error_log w `Bootstrap.php`) |
| 5 | Zawartość paczki (`rsync --exclude-from=.distignore`) | brak `process-task.php`, brak `*.po`, brak `docs/wordpress-org`; z `languages/` zostaje wyłącznie `polytrans.pot` |
| 6 | Ręcznie, na żywej instalacji | zaplanowanie tłumaczenia, workflow, strona logów, panel usage — **do zrobienia przed wgraniem** |

**Uwaga o adnotacjach sanityzacyjnych, wbrew punktowi 3 z pierwotnego planu.** Plan zakładał
„zero `phpcs:ignore` dla sanityzacji". Da się to osiągnąć w naszym rulesecie i przez chwilę
tak było — ale Plugin Check uruchamia PHPCS z własnym, wbudowanym rulesetem
(`plugin-check/phpcs-rulesets/plugin-check.ruleset.xml`), któremu nie da się podać
`customSanitizingFunctions`. Pomiar: bez adnotacji Plugin Check pokazuje **56 WARNING-ów
`ValidatedSanitizedInput.InputNotSanitized`** — dokładnie w sekcji, która była czerwona.
Z adnotacjami: 0. Wejście jest sanityzowane realnie w obu wariantach; różnica dotyczy tylko
tego, co widzi raport recenzenta. Dlatego adnotacje wróciły, ale ich uzasadnienie nazywa teraz
sanitizer w linii poniżej, a nie „trusted admin input" — to jest ta zmiana, o którą prosił mail.

### Efekt uboczny naprawy 2.2

`WorkflowOutputProcessor` wołał logger przez alias `\PolyTrans_Logs_Manager`, tworzony w
`includes/Compatibility.php`. Ścieżka atrybucji nie była pokryta testem, więc nikt nie zauważył,
że w kontekście bez `Compatibility.php` te wywołania kończą się
`Error: Class "PolyTrans_Logs_Manager" not found`. Pięć wywołań w tym pliku przepisane na
`PolyTrans\Core\LogsManager` — zgodnie z zasadą „PSR-4, always" z `CLAUDE.md`.

Stub `WP_User` w `tests/Unit/bootstrap.php` dostał zadeklarowane `display_name` i `user_login`;
wcześniej stub `get_user_by()` z `MetadataManagerTest` dopisywał je dynamicznie, co na PHP 8.2
generowało deprecation w każdym teście, który ich dotknął.

## Etap 6 — Plugin Check na WP 7.1 (Playground, 2026-08-24)

Uruchomienie w Playground na świeższym WP niż nasz obraz CI (7.1) pokazało jeden **ERROR**
i jedną nową rodzinę warningów.

| # | Znalezisko | Ocena | Status |
|---|---|---|---|
| 6.1 | `outdated_tested_upto_header`: `Tested up to: 7.0 < 7.1` — **ERROR**, blokuje widoczność w wyszukiwaniu | Podniesione do 7.1 w `readme.txt`. To błąd, który wraca sam przy każdym wydaniu WP — dopisane do `docs/development/plugin-check.md`. | ☑ |
| 6.2 | `PluginCheck.CodeAnalysis.AIProvider.DirectIntegration` ×~40 (wszystkie base URL providerów + `openrouter.ai` w `ModelPricing`) | Warning, nie ERROR. Świadomie zostaje — uzasadnienie w odpowiedzi (Etap 4) i w dokumentacji. | ☑ |
| 6.3 | `WordPress.Security.NonceVerification.Recommended` w `Menu/TagTranslation.php:115,116` | Odczyt `$_GET['post']` na ekranie edycji, bez zapisu. Przepisane na `absint(wp_unslash(...))` z adnotacją nazywającą sniff, który faktycznie się zapala. | ☑ |
| 6.4 | `Squiz.PHP.DiscouragedFunctions.Discouraged` — `set_time_limit()` w `AsyncJobRunner.php:219` | Adnotacja istniała, ale wymieniała `WordPress.WP.AlternativeFunctions.set_time_limit_set_time_limit`, a zapala się `Squiz.PHP.DiscouragedFunctions.Discouraged` — dokładnie ta pułapka, którą mamy opisaną w `CLAUDE.md`. Kod sniffa dopisany. | ☑ |
| 6.5 | `WordPress.DB.DirectDatabaseQuery.*`, `NoCaching`, `SchemaChange`, `SlowDBQuery.*`, `PostNotIn_post__not_in` | Oczekiwane — własne tabele i świadome kompromisy wydajnościowe, spisane w `docs/development/plugin-check.md`. Bez zmian. | ☑ |

Wniosek na przyszłość: **Plugin Check trzeba puszczać na aktualnym WP.** Job CI był przypięty
do WP 6.8 i PC 2.0.0, więc `outdated_tested_upto_header` ani nowe sniffy nie zapalały się
w pipeline — a to ERROR blokujący submisję. `WP_CORE_VERSION` i `PLUGIN_CHECK_VERSION` są
teraz puste (= bieżące wydanie), a lokalnie to samo robi **`./dev.sh smoke`**.

### Weryfikacja na prawdziwym WordPressie 7.1 (2026-08-24)

`composer test` nie uruchamia WordPressa, więc nie widzi ani fatala przy aktywacji, ani
szablonów admina. Postawiłem więc pełny stack (mysql + `wordpress:cli`, ten sam schemat co
job `plugin-check`) i sprawdziłem:

| Sprawdzenie | Wynik |
|---|---|
| WordPress 7.1, aktywacja czystej paczki (`.distignore`) | bez fatali, wersja `1.19.2` aktywna |
| Cztery tabele wtyczki | `wp_polytrans_logs`, `_workflows`, `_assistants`, `_usage` utworzone |
| Trasy REST | `/polytrans/v1`, `/translation/translate`, `/translation/receive-post` |
| `EndpointAuth` autoloaded przez PSR-4 z paczki | tak |
| `translate` i `receive-post`: `none` bez stałej | **odmowa** (`WP_REST_Request`, prawdziwy WP) |
| poprawny `Bearer` / zły `Bearer` | przepuszczone / odrzucone |
| szablon `advanced-settings.twig` | renderuje się (15 087 znaków) |
| select RECEIVER bez stałej | **brak opcji `none`** |
| select ENDPOINT (wysyłka) | `none` zostaje — dotyczy wysyłania, nie wpuszczania |
| select RECEIVER ze stałą | opcja `none` wraca |
| notice o odmowie w UI | zawiera nazwę stałej |
| `rest_url()` | `http://example.test/index.php?rest_route=/polytrans/v1/translation/receive-post` |
| Plugin Check 2.1.0 na WP 7.1 | **0 ERROR / 56 WARNING** |

Rozkład warningów: 33 × `AIProvider.DirectIntegration`, 8 × `DirectDatabaseQuery.DirectQuery`,
8 × `NoCaching`, 3 + 2 × `SlowDBQuery.*`, 1 × `PostNotIn_post__not_in`, 1 × `SchemaChange`.
Warningi po `set_time_limit()` i `NonceVerification` zniknęły — adnotacje z 6.3 i 6.4 działają.

## Etap 3b — te same wzorce w miejscach, których recenzent nie zacytował

Mail każe przeszukać kod pod kątem każdego zgłoszonego wzorca, nie tylko podanych
linii. Skan dał cztery znaleziska, z których jedno było zepsute w każdym wydaniu.

| # | Znalezisko | Co zrobiliśmy |
|---|---|---|
| 3b.1 | `includes/process-task.php` ładował `wp-load.php` bezpośrednio — dokładnie ten zakaz, który mail opisuje w „Calling core loading files directly", tylko pod innym plikiem niż zacytowany. Uruchamiał go `BackgroundProcessor::spawn_exec()`, odpalając binarkę PHP przez `exec`/`shell_exec`/`system`. **Plik był wykluczony z paczki**, więc w każdym wydaniu na hoście z włączonym `exec()` dispatch w tle zwracał `false` — bez fallbacku na pętlę HTTP, bo `spawn()` wybierał `exec` jako pierwszy i już nie próbował dalej. | `spawn()` zawsze idzie przez `spawn_http_request()` (pętla zwrotna z nonce i tokenem w transiencie, ta sama, którą używa `AsyncJobRunner`). Usunięte: `spawn_exec()`, `is_exec_available()`, `check_bg_errors_immediate()` (czytała transient zapisywany tylko przez usunięty skrypt) i `includes/process-task.php`. `BackgroundProcessor` schudł o 212 linii. Przy okazji znikła nieistniejąca zmienna `$log_file` używana w gałęzi `shell_exec`. |
| 3b.2 | `wp_set_current_user()` w `Receiver/Managers/PostCreator.php:101` — ten sam wzorzec co zgłoszony w `WorkflowOutputProcessor`, tylko niecytowany. Bez `finally`: wyjątek z `wp_save_post_revision()` zostawiał podmienioną tożsamość na resztę żądania. | Nowa klasa `Core\UserContext::run_as()` — jedno audytowane miejsce, które podmienia użytkownika na czas jednego wywołania i przywraca go w `finally`. Oba miejsca (rewizja posta, atrybucja workflow) korzystają z niej; decyzja *czy* wolno pożyczyć tożsamość zostaje przy wywołującym, bo tylko on zna obiekt docelowy. Inwariant przypięty testem — `wp_set_current_user()` może występować dokładnie w jednym pliku. |
| 3b.3 | 109 bezwarunkowych wywołań `error_log()` w 28 plikach, każde z własnym `phpcs:ignore` — ta sama figura („adnotacja zamiast poprawki"), którą recenzent skrytykował przy sanityzacji. Log PHP należy do właściciela strony, a wtyczka ma własny `LogsManager`. | Nowa klasa `Core\Diagnostics::log()` — jedyna trasa do logu PHP, milcząca dopóki `WP_DEBUG` (i `WP_DEBUG_LOG`) nie są włączone; jedna adnotacja w środku zamiast 109 na zewnątrz. Wszystkie wywołania przepisane, prefiksy `[polytrans]`/`[PolyTrans]`/`PolyTrans:` scalone w jedno miejsce. Wyjątek: `Bootstrap.php` raportuje brak autoloadera Composera, więc żadna autoloadowana klasa nie jest tam jeszcze dostępna. Inwariant przypięty testem. |
| 3b.4 | `BackgroundProcessor::check_on_activation()` uruchamiane przy **każdej aktywacji**: 6 wpisów do logu PHP serwera, wpis testowy do tabeli logów oraz zapis i usunięcie post meta `_polytrans_activation_test` na losowym, prawdziwym poście użytkownika. Kod diagnostyczny w wydaniu produkcyjnym. | Usunięte razem z wywołaniem w `polytrans.php`. Tabele tworzy `polytrans_activate()`, a `LogsManager` ma leniwe `initialize()`, więc nic tego nie potrzebowało. |
