# WordPress Plugin Directory - Review Issues for PolyTrans

Review ID: `AUTOPREREVIEW ❗TRM-DESC polytrans/jmarianski/15Mar26/T1 15Mar26/3.8`
Data otrzymania: 2026-03-15

---

## Issue 1: Nazwa i slug pluginu — ARGUMENTUJEMY ✉️

**Problem:** "PolyTrans" rzekomo zbyt podobna do istniejących pluginów.

**Nasza argumentacja (do wpisania w mailu):**
- "PolyTrans" to oryginalna nazwa, nie zarejestrowany trademark
- "Poly" nawiązuje do pluginu Polylang, z którym się integrujemy — jest istotne kontekstowo
- "Translate" jako pełna nazwa jest zajęta przez inny plugin (młodszy od naszego)
- Plugin powstał dla trans.info, stąd nazwa ma dodatkowe znaczenie
- Nie chcemy dodawać sztucznych prefiksów

**Status:** Do odpowiedzi w mailu, bez zmian w kodzie.

---

## Issue 2: Opis pluginu (readme) ✅ DONE

- [x] Usunięto "Google Translate… no API key required" z README.md
- [x] Google Translate ukryty za flagą `POLYTRANS_ENABLE_GOOGLE` (domyślnie wyłączony)
- [ ] **TODO:** Sprawdzić długość opisu w readme — czy nie przekracza limitu widoczności katalogu WP

---

## Issue 3: Proper escaping of outputs ✅ DONE

- [x] WorkflowDebug.php — wszystkie zmienne owinięte w `esc_html()`
- [x] Usunięto phpcs:disable dla escaping

---

## Issue 4: Nonces i uprawnienia użytkowników ✅ DONE

- [x] `handle_schedule_translation()` — dodano `current_user_can('edit_post', $post_id)`
- [x] `ajax_get_translation_status()` — dodano
- [x] `ajax_clear_translation_status()` — dodano
- [x] `ajax_retry_translation()` — dodano
- [x] `ajax_detach_translation()` — już miał (bez zmian)

---

## Issue 5: wp_enqueue dla JS/CSS ✅ DONE

- [x] `TranslationSettings.php` — `wp_add_inline_script()` zamiast admin_footer echo
- [x] `PostprocessingMenu.php` — `wp_add_inline_script()` zamiast inline `<script>`
- [x] `OpenAISettingsProvider.php:93` — `wp_add_inline_script()` zamiast inline `<script>`
- [x] `OpenAISettingsProvider.php:149` — `wp_add_inline_style()` zamiast inline `<style>`
- [x] `WorkflowMetabox.php` — `wp_add_inline_style()` zamiast inline `<style>`
- [x] Twig templates (editor.twig, tester.twig) — usunięto inline `<script>`, dane przez PHP

---

## Issue 6: Niepotrzebne load_plugin_textdomain() ✅ DONE

- [x] Usunięto z `polytrans.php`
- [x] Usunięto z `includes/class-polytrans.php`

---

## Issue 7: Nieudokumentowane usługi zewnętrzne ✅ PARTIAL

- [x] Sekcja `== External services ==` istnieje w README.md
- [x] Google Translate usunięty (ukryty za flagą)
- [ ] **TODO:** Zweryfikować czy format sekcji odpowiada wymaganiom WP (reviewer zgłosił 34 instancje)
- [ ] **TODO:** Upewnić się, że każdy serwis ma: opis, dane wysyłane, kiedy, ToS link, Privacy Policy link

---

## Issue 8: Brak composer.json ✅ DONE

- [x] Usunięto `composer.json export-ignore` z `.gitattributes`
- [x] Usunięto `--exclude='composer.json'` z `.gitlab-ci.yml`

---

## Dodatkowe poprawki (poza review):

- [x] Usunięto wzmianki Google Translate z docs deweloperskich (ARCHITECTURE.md, PROVIDER_EXTENSIBILITY_GUIDE.md)
- [x] Dodano `docs/roadmap` do wykluczeń z ZIP-a (.gitattributes + .gitlab-ci.yml)
- [x] Domyślny provider zmieniony z `'google'` na `'openai'`
- [x] Wszystkie `?? ['google']` i `?? 'google'` defaults zmienione na `[]` / `''`

---

## Pozostałe TODO:

1. **Sprawdzić długość opisu** w readme.txt/README.md
2. **Zweryfikować sekcję External Services** — czy format spełnia wymagania (34 instancje zgłoszone)
3. **Napisać odpowiedź mailową** — argumentacja za nazwą + info o poprawkach
4. **Przetestować plugin** — aktywacja, tłumaczenie, workflow po zmianach
5. **Zbudować nowy ZIP** i wgrać przez "Add your plugin"
