# Odpowiedź wysłana 2026-08-24 (runda 2)

Wysłane po wgraniu paczki 1.20.0. `hello@treetank.net` (adres podpięty pod konto wp.org)
dodany w kopii. Zapisane dosłownie — recenzent recenzuje paczkę, ale wątek jest jedynym
zapisem tego, co obiecaliśmy.

Różnice względem szkicu (`reply-2026-08-24-draft.md`): dwa dopisane zdania, oba w treści
poniżej — jedno o adresie do korespondencji w punkcie „Ownership", jedno o klientach na
starszych WordPressach w punkcie „Core AI Client".

---

> Hi,
>
> The reported technical issues have been addressed in version 1.20.0, which has just been uploaded. Five points need context.
>
> Name and slug. We would like to keep "PolyTrans" / polytrans.
>
> First, a correction to our own submission note, which said the name is nobody's trademark. A registration does exist — POLYTRANS, Okino Computer Graphics, USPTO 87025627 — and we should have found it before writing that. It covers 3D computer-graphics software for viewing, rendering and converting geometry file formats, that is CAD/CAM data translation, not human language. The goods, the market and the customers do not overlap with a WordPress multilingual content plugin, and the plugin uses no altered form of that mark, so we do not see a realistic source of confusion.
>
> "Poly" is used in its ordinary sense of the Greek prefix for "many", as in polyglot or polyphony, describing multilingual content. It is not an altered form of another project's name; the plugin's Polylang integration is documented in the readme as a technical requirement, not used as branding, and the readme states that the plugin is not affiliated with or endorsed by Polylang.
>
> On polytranslate-ai: we were not aware of it. The initial commit of our repository is dated 2025-07-09, the same day that plugin was first published in the directory, so the two names were arrived at independently rather than one being derived from the other. The repository has been public since that date: https://gitlab.com/treetank/polytrans (mirror: https://github.com/treetank-net/polytrans). We consider "PolyTrans" and "PolyTranslate AI for Polylang" distinguishable both in writing and in meaning, but if the team disagrees after looking at this, tell us and we will rename in the next upload.
>
> Ownership. The WordPress.org account e-mail has been changed to an address under treetank.net, the domain declared as Author URI in the plugin. Further correspondence will reach that address. For continued thread of conversation I've replied using original email address with original email message included. Discussion can be continued using this email address or my personal one. I prefer the personal one, as it is tied to my personal phone and I can react immediately.
>
> Unauthenticated endpoints. Both REST routes now require the shared secret. The unauthenticated mode the permission callbacks used to allow is no longer reachable from the settings UI at all — it takes a constant in wp-config.php, and exists for multi-server installations where the translator and the receiver sit on an internal network behind an IP allow-list.
>
> Direct provider integrations. Plugin Check reports 0 errors and 53 warnings on this ZIP. Thirty-three of those warnings are AIProvider.DirectIntegration, one per provider base URL and one for the price list the cost accounting reads. They are intentional: the site owner supplies their own API key and the plugin talks to the provider they chose, with every endpoint declared under "== External services ==" in the readme. The remainder are direct database queries against the plugin's own four tables, which have no Core API equivalent.
>
> Core AI Client. The plugin declares Requires at least: 6.0 and depends on per-call parameters the core client does not expose: reasoning-effort levels, provider-side assistants and managed prompts, and the raw usage block that our per-post cost accounting is built on. We intend to add the core AI Client as an additional provider once we can raise the minimum supported WordPress version, however our concern is to support our ongoing clients using older versions of wordpress.
>
> Best regards, Jacek Mariański

## Zobowiązania, które z tego wynikają

| Obietnica | Co ją domknie |
|---|---|
| „if the team disagrees … we will rename in the next upload" | Zmiana nazwy i sluga — Etap 1 planu, obecnie nieaktywny |
| „We intend to add the core AI Client as an additional provider once we can raise the minimum supported WordPress version" | Nowy provider, gdy `Requires at least` da się podnieść do 6.8 |

## Jedno miejsce, które może wrócić

Akapit „Ownership" mówi, że preferowanym kanałem jest adres prywatny. Mail recenzenta w tej
samej rundzie stwierdzał, że konto `gmail.com` **nie może** służyć jako identyfikacja
właściciela. Te dwie rzeczy są formalnie o czym innym — identyfikacja dotyczy e-maila
**konta wp.org** (już zmienionego na `treetank.net`), a nie adresu, z którego prowadzi się
korespondencję — ale zestawienie może wywołać dopytanie.

Jeśli wróci, odpowiedź jest krótka i nie wymaga niczego cofać:

> The account e-mail is the one under treetank.net; that is what identifies the account and it
> has not changed back. The preference I mentioned was only about which mailbox I read fastest,
> not about which address represents ownership. hello@treetank.net is on copy of this thread and
> reaches me as well — please use whichever the team considers appropriate.
