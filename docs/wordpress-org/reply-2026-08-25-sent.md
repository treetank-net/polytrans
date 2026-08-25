# Odpowiedź wysłana do WordPress.org — 2026-08-25

Wątek: `AUTOPREREVIEW ❗TRM-OWN` (druga runda recenzji).
Odpowiedź na definitywne odrzucenie nazwy `PolyTrans`.

## Treść wysłana (dosłownie)

> Hi,
>
> it's totally understandable. There is no point in being wrongly associated, however a lot of names don't have a nice ring and wanted to avoid renaming. We already have namespaces reserved in PSR-4 and - as AI calculated - 78 places where we use table names or options or meta keys that relate to polytrans. Also 1306 places within text domain. That's why I wanted to avoid changing the name.
>
> Replacing it is an hour or so for an agent, it would be insurmountable work for a human, however, current technology allows us to do such swaps. However, all already existing tables contain "polytrans" prefix, changing it would wipe data for my existing clients, so I would like the "polytrans" in code to remain.
>
> I don't have the best slug in mind that would best suit the situation. I would very much like "TreeTank" to remain as a group/company name, and therefore I wouldn't want to name a product using that. That's why I'd like to propose a different one:
>
> Trans Trans Baby - trans-trans-baby.
>
> This is our own idea that follows the naming convention I already use across our published work: ads-ads-baby, google-ads-baby, meta-ads-baby and so on, all at https://github.com/treetank-net. It is not a variant of already existing names or trademarks like polytrans or polytranslate-ai and nothing in the catalog seems to resemble the name. I was kinda hoping for portmanteau like transpoly which would advocate against further changes to PSR-4 namespaces and tables, however you've clearly stated I should avoid doing so.
>
> If "beginning with your own identifier" means specifically studio name rather than distinctive name of our own - regrettably - please reserve instead:
>
> TreeTank Translations - slug treetank-translations (or treetank-trans for short).
>
> Either work for us, however we would deeply appreciate a decision more favorable to us (as we've stated). Please reserve whichever meets the requirement; we are holding the rename until you confirm, so that the text domain - which has to match the slug - is only changed once.
>
> Best regards,
> Jacek Mariański

Literówka „Either work" (zamiast „works") poszła świadomie.

## Co zostało zobowiązane

| Zobowiązanie | Konsekwencja |
|---|---|
| Wstrzymujemy zmianę nazwy do potwierdzenia sluga | Nie ruszamy kodu, dopóki nie przyjdzie odpowiedź |
| Text domain zostanie zmieniony raz | Jedno przejście po 1306 miejscach, nie dwa |
| `polytrans` w tabelach/opcjach/meta zostaje | Powiedziane wprost, z uzasadnieniem (dane klientów) |
| Dwie akceptowalne opcje | `trans-trans-baby` (pierwszy wybór), `treetank-translations` (fallback) |

## Otwarte ryzyko

Recenzent może odrzucić `trans-trans-baby`, uznając, że „beginning with your own
identifier" znaczy dosłownie nazwę studia. Mail zawiera na to gotowy fallback, więc
najgorszy scenariusz to jedna dodatkowa wymiana zdań, nie kolejna runda recenzji.
