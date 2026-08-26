# Plan przemianowania wtyczki — WYKONANY

Slug potwierdzony i zarezerwowany 2026-08-26. Wykonanie: patrz
`reply-2026-08-26-sent.md` (tabela commitów i bramek).

- slug i text domain: **`treetank-trans`**
- nazwa wyświetlana: **`TreeTank Translation Workflows`**
- etykieta menu: **`TreeTank`**

Dokument zostaje jako zapis tego, co zostało zmienione, a co świadomie nie —
ta druga lista jest istotna, bo każdy przyszły `sed` musi ją respektować.

---

## Co się NIE zmienia

Zapowiedziane recenzentowi i uzasadnione danymi klientów:

| Rzecz | Liczba | Dlaczego zostaje |
|---|---|---|
| Namespace `PolyTrans\` | 156 plików | PSR-4, niewidoczny dla użytkownika |
| Tabele `polytrans_*` | 4 | Zmiana = utrata danych |
| Opcje (`polytrans_settings` itd.) | — | To samo |
| Post meta `_polytrans_*` | 77 kluczy | To samo |
| Akcje AJAX `wp_ajax_polytrans_*` | 60 | Integracje zewnętrzne |
| REST namespace `polytrans/v1` | 2 trasy | Publiczne API, recenzent nie zgłaszał |
| Stałe wewnętrzne (`POLYTRANS_VERSION`, `POLYTRANS_PLUGIN_DIR` …) | 11 | Niewidoczne |
| Prefiksy w `phpcs.xml` (`prefixes`) | 3 | Opisują to, co zostaje |
| Nazwa repozytorium (GitLab/GitHub) | — | Osobna decyzja, nie wymóg katalogu |

## Co się zmienia

### A. Text domain — 1306 miejsc

Drugi argument funkcji i18n. Zmiana mechaniczna, ale **nie** globalnym
`sed 's/polytrans/treetank-trans/g'` — to zniszczyłoby tabele, opcje i meta. Wzorzec musi
trafiać wyłącznie w argument text domain:

```bash
grep -rlE "'polytrans'\)" --include=*.php --include=*.twig includes/ templates/ polytrans.php \
  | xargs sed -i "s/'polytrans')/'treetank-trans')/g"
```

Weryfikacja po zmianie: `grep -rc "'polytrans')" ` musi dać 0, a
`grep -rc "'treetank-trans')" ` — 1306.

### B. `phpcs.xml:57` — **krytyczne**

```xml
<property name="text_domain" type="array">
    <element value="polytrans"/>   <!-- → treetank-trans -->
```

Bez tego wpisu PHPCS zgłasza 1306 × `WordPress.WP.I18n.TextDomainMismatch` i job
`phpcs` przestaje przechodzić. Zmieniamy razem z punktem A, jednym commitem.

### C. Nagłówek wtyczki i `readme.txt`

| Plik | Pole |
|---|---|
| `polytrans.php` | `Plugin Name: PolyTrans` → `TreeTank Translation Workflows` |
| `polytrans.php` | `Text Domain: polytrans` → `treetank-trans` |
| `readme.txt` | `=== PolyTrans ===` → `=== TreeTank Translation Workflows ===` |
| `readme.txt` | pozostałe 5 wzmianek w opisie |

### D. Nazwa głównego pliku

`polytrans.php` → `treetank-trans.php`, przez `git mv` (zachowuje historię).

Ścieżek nie trzeba nigdzie poprawiać: `POLYTRANS_PLUGIN_DIR/URL/FILE` liczą się
z `plugin_dir_path(__FILE__)`, a w kodzie **nie ma ani jednego** hardcode
`plugins/polytrans` (sprawdzone).

### E. Nazwa katalogu

Wynika ze sluga — WordPress.org rozpakowuje ZIP do katalogu o nazwie sluga. To sama
ścieżka: nie dotyka bazy, więc tabele, opcje i meta przechodzą nietknięte.

**Jedno następstwo na istniejących instalacjach:** `active_plugins` trzyma
`polytrans/polytrans.php`. Po przemianowaniu katalogu wtyczka jest nieaktywna i wymaga
jednego kliknięcia „Aktywuj". Dane zostają — `polytrans_activate()` używa `dbDelta`,
a klasy mają lazy `initialize()`, więc reaktywacja jest bezpieczna.

### F. Stringi widoczne dla użytkownika — 18 miejsc

Ręcznie, bo to redakcja tekstu, nie podmiana identyfikatora.

**PHP (9):**

| Plik | Linia |
|---|---|
| `includes/Menu/SettingsMenu.php` | 60, 61 (nazwa menu i strony) |
| `includes/class-polytrans.php` | 202 („PolyTrans Scheduler") |
| `includes/PostProcessing/WorkflowMetabox.php` | 59 („PolyTrans Workflows") |
| `includes/Menu/TagTranslation.php` | 146 (etykieta grupy w autocomplete) |
| `includes/Core/LogsManager.php` | 774 („…in PolyTrans settings") |
| `includes/PostProcessing/Steps/ManagedAssistantStep.php` | 419 („PolyTrans > AI Assistants") |
| `includes/Core/ModelCapabilities.php` | 1200, 1554 („PolyTrans then sends…") |

**Twig (9):** `settings/overview.twig` (5 ×), `logs/page.twig` (1),
`settings/tabs/maintenance-settings.twig` (2), `settings/tabs/tag-settings.twig` (1).

Uwaga: `ManagedAssistantStep.php:419` odwołuje się do ścieżki w menu („PolyTrans >
AI Assistants") — po zmianie nazwy menu tekst musi zgadzać się z punktem D.

### G. `languages/`

Nazwy plików muszą odpowiadać text domain, bo tak WordPress ich szuka:

- `polytrans.pot` → `treetank-trans.pot`
- `polytrans-pl_PL.po` → `treetank-trans-pl_PL.po`
- w obu: nagłówek `Project-Id-Version` oraz odwołania do domeny

### H. CI — `.gitlab-ci.yml`, 52 wystąpienia

Nazwa katalogu w paczce **musi** równać się slugowi, inaczej WordPress.org odrzuci ZIP:

| Linia | Co |
|---|---|
| 58 | `php -l /src/polytrans.php` → `treetank-trans.php` |
| 115, 144, 145 | `/tmp/polytrans-pcp-${CI_JOB_ID}` (kosmetyka, nazwa katalogu roboczego) |
| 119 | `wp-content/plugins/polytrans/` → `treetank-trans/` |
| 130 | `wp plugin check polytrans` → `treetank-trans` |
| 186, 195–209, 219 | `release/polytrans/` → `release/treetank-trans/` |
| 217, 218 | `polytrans.php` → `treetank-trans.php` (odczyt wersji) |
| 231–255 | nazwy ZIP-ów, sumy SHA, ścieżka pakietu generycznego |
| 284, 285, 302 | remote GitHub — **zostaje**, dopóki nie przemianujemy repo |
| 343, 348 | tekst opisu release'a |

### I. `dev.sh` — 15 wystąpień

`work_dir`, `mkdir`, `wp plugin activate polytrans`. Bez tego `./dev.sh smoke` przestaje
działać, a to jedyny lokalny sposób sprawdzenia na prawdziwym WordPressie.

### J. Skrypty sync (poza repo)

W `/home/jm/projects/trans-info/`:

- `polytrans-sync-dirs.txt` — 4 cele, ścieżki `plugins/polytrans/`
  (uwaga: cel trans.eu ma katalog `polytrans-main`)
- `sync-polytrans.sh`, `sync-polytrans-manual.sh`, `sync-polytrans-watch.sh`

Na instancjach trzeba przemianować katalog i aktywować wtyczkę ponownie (patrz E).

### K. `POLYTRANS_ALLOW_UNAUTHENTICATED_ENDPOINTS`

Jedyna stała, którą użytkownik wpisuje sam — do `wp-config.php`. Po zmianie nazwy
produktu prefiks `POLYTRANS_` w cudzej konfiguracji wygląda obco, ale zmiana zerwałaby
działające instalacje. Propozycja: nowa nazwa oparta na slugu **plus** stara jako
fallback w `Core\EndpointAuth`, udokumentowana jako przestarzała. Bez daty usunięcia.

---

## Kolejność wykonania

1. B + A jednym commitem (phpcs.xml i text domain muszą się zmienić razem)
2. C + D + G (nagłówek, readme, `git mv`, pliki tłumaczeń)
3. F (stringi UI — jedyny etap wymagający czytania)
4. H + I (CI i `dev.sh`) — bez tego pipeline nie zbuduje paczki
5. K (stała z fallbackiem)
6. Bramki: `composer test`, `phpcs`, `./dev.sh smoke`, Plugin Check
7. Release z nową nazwą, upload przez „Add your plugin"
8. J (sync na instancjach) — po release
9. Poza katalogiem: wpis „PolyTrans" na `treetank.net/projects`, decyzja o nazwie repo

## Pułapki

- Globalny `sed` na `polytrans` zniszczy tabele, opcje i meta. Wzorzec zawsze z
  kontekstem: `'polytrans')` dla text domain.
- `tests/Architecture/AjaxRegistrationTest.php` sprawdza literały `wp_ajax_polytrans_*` —
  te **zostają**, więc test nie wymaga zmian. Gdyby zaczął padać, znaczy to, że sed
  wyszedł za zakres.
- Nazwa katalogu w ZIP musi równać się slugowi — pilnuje tego `.gitlab-ci.yml`, ale
  dopiero po pushu tagu.
