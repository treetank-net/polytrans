# Szkic odpowiedzi na review z 2026-08-24 (runda 2 — obrona nazwy)

Decyzja Jacka (2026-08-24): **zostajemy przy `PolyTrans` / `polytrans`** i argumentujemy jeszcze raz.
Mail dopuszcza taką ścieżkę wprost: „If you have doubts about a specific issue, fix everything else
and ask your questions alongside the update."

## Kontekst: co już wysłaliśmy

Treść „additional info" z uploadu 2026-08-20 i sprostowanie zdania „nobody's trademark":
`docs/wordpress-org/submission-notes-2026-08-20.md`.

## Kolejność, która ma znaczenie

Recenzent **nie porównuje zmian** — recenzuje wgraną paczkę („we will review the entire plugin
again, we won't compare the changes"). Dlatego: najpierw wszystkie poprawki i nowy ZIP przez
„Add your plugin", **potem** odpowiedź w tym samym wątku mailowym.

## Czego nie piszemy

- Listy wprowadzonych poprawek („do not list the changes made").
- Historii sporu o nazwę z marca.
- Wyjaśnień do Guideline 11 — sprawdzone, nie ma czego wyjaśniać.
- Liczby pobrań `polytranslate-ai`. Reguła podobieństwa u nich nie zależy od popularności;
  podniesienie tego argumentu tylko pokazuje, że nie zrozumieliśmy reguły.

## Czego nie piszemy w sprawie „poly" — i dlaczego

**Nie**: „»poly« jest uzasadnione, bo integrujemy się z Polylangiem."
Mail zakazuje „altered forms of a trademark, such as blend words or portmanteaus". To zdanie
samo przyznaje, że nazwa jest pochodną cudzego znaku — czyli dokładnie zakazany wzorzec.

**Tak**: „poly" to grecki przedrostek „wiele" (polyglot, polifonia), opisujący wielojęzyczność.
Integracja z Polylangiem jest faktem technicznym opisanym w readme, a nie źródłem nazwy.

---

## Treść (do wysłania po wgraniu paczki)

> Hi,
>
> The reported technical issues have been addressed in version 1.20.0, which has just been
> uploaded. Five points need context.
>
> **Name and slug.** We would like to keep "PolyTrans" / `polytrans`.
>
> First, a correction to our own submission note, which said the name is nobody's trademark. A
> registration does exist — POLYTRANS, Okino Computer Graphics, USPTO 87025627 — and we should
> have found it before writing that. It covers 3D computer-graphics software for viewing,
> rendering and converting geometry file formats, that is CAD/CAM data translation, not human
> language. The goods, the market and the customers do not overlap with a WordPress multilingual
> content plugin, and the plugin uses no altered form of that mark, so we do not see a realistic
> source of confusion.
>
> "Poly" is used in its ordinary sense of the Greek prefix for "many", as in polyglot or
> polyphony, describing multilingual content. It is not an altered form of another project's
> name; the plugin's Polylang integration is documented in the readme as a technical
> requirement, not used as branding, and the readme states that the plugin is not affiliated
> with or endorsed by Polylang.
>
> On `polytranslate-ai`: we were not aware of it. The initial commit of our repository is dated
> 2025-07-09, the same day that plugin was first published in the directory, so the two names
> were arrived at independently rather than one being derived from the other. The repository has
> been public since that date: https://gitlab.com/treetank/polytrans (mirror:
> https://github.com/treetank-net/polytrans). We consider "PolyTrans" and "PolyTranslate AI for
> Polylang" distinguishable both in writing and in meaning, but if the team disagrees after
> looking at this, tell us and we will rename in the next upload.
>
>
> **Ownership.** The WordPress.org account e-mail has been changed to an address under
> `treetank.net`, the domain declared as `Author URI` in the plugin. Further correspondence will
> reach that address.
>
> **Unauthenticated endpoints.** Both REST routes now require the shared secret. The
> unauthenticated mode the permission callbacks used to allow is no longer reachable from the
> settings UI at all — it takes a constant in `wp-config.php`, and exists for multi-server
> installations where the translator and the receiver sit on an internal network behind an IP
> allow-list.
>
> **Direct provider integrations.** Plugin Check reports 0 errors and 53 warnings on this ZIP.
> Thirty-three of those warnings are `AIProvider.DirectIntegration`, one per provider base URL
> and one for the price list the cost accounting reads. They are intentional: the site owner
> supplies their own API key and the plugin talks to the provider they chose, with every endpoint
> declared under "== External services ==" in the readme. The remainder are direct database
> queries against the plugin's own four tables, which have no Core API equivalent.
>
> **Core AI Client.** The plugin declares `Requires at least: 6.0` and depends on per-call
> parameters the core client does not expose: reasoning-effort levels, provider-side
> assistants and managed prompts, and the raw `usage` block that our per-post cost accounting
> is built on. We intend to add the core AI Client as an additional provider once we can raise
> the minimum supported WordPress version.
>
> Best regards,
> Jacek Mariański

## Wariant akapitu o własności — **rozstrzygnięte: wariant A** (e-mail konta zmieniony 2026-08-24)

To **osobna sprawa od nazwy** (`TRM-OWN` w Review ID). Nawet wygrana obrona nazwy nie odblokuje
zgłoszenia, dopóki konto wp.org ma e-mail `@gmail.com`, a wtyczka deklaruje `Author: treetank`
i `Author URI: https://treetank.net`.

**Wariant A — wybrany, wklejony już w treść powyżej.** Zmiana e-maila konta wp.org na adres w domenie `treetank.net`:

> **Ownership.** The WordPress.org account e-mail has been changed to an address under
> `treetank.net`, the domain declared as `Author URI` in the plugin. Further correspondence will
> reach that address.

**Wariant B — nieużywany.** Rekord DNS, gdyby e-mail konta miał wrócić na poprzedni:

> **Ownership.** A TXT record with the value `wordpressorg-jmarianski-verification` has been added
> at the root of `treetank.net`, the domain declared as `Author URI`. Treetank is my own company
> and I am the sole author of this plugin; there is no third-party client involved.

Uwaga do wariantu B: mail zastrzega, że sam DNS „will only be considered valid if we can relate
your account as being controlled by the owner", więc wariant A jest pewniejszy. `@trans.eu`
**nie zadziała** — to nie domena z `Author URI`.
