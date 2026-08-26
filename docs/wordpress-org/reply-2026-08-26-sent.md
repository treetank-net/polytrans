# Runda 3 — slug potwierdzony, przemianowanie wykonane

Data: 2026-08-26

## Odpowiedź recenzenta (dosłownie)

> The current display name PolyTrans and permalink polytrans still cannot be kept
> because of the registered POLYTRANS mark and the close existing plugin name. This
> requires a new public display name and permalink.
>
> Trans Trans Baby is not suitable because it unnaturally repeats the generic
> translation term and does not begin with a distinctive identifier. A distinctive
> identifier does not have to be your studio name specifically, but TreeTank is an
> appropriate identifier here. For example, TreeTank Translations or TreeTank
> Translation Workflow would be acceptable; another name following the same pattern
> is also fine.
>
> Please update the display name in the plugin headers and readme, then reply with
> one exact permalink you want us to reserve (for example, treetank-translations ).
> We cannot choose between alternative permalink requests on your behalf.
>
> --
> WordPress Plugins Team | plugins@wordpress.org

## Nasza odpowiedź (wysłana)

> Sure, let's reserve treetank-trans for the plugin name "TreeTank Translation
> Workflows" and I'll adjust plugin zip as soon as it's possible.

## Dlaczego slug krótszy niż nazwa

Sprawdzone, nie założone: **41 z 60** najpopularniejszych wtyczek w katalogu ma slug
inny niż slugifikowana nazwa wyświetlana, w większości znacznie krótszy —
`elementor`, `wordpress-seo` (Yoast SEO), `jetpack`, `wordfence`, `mailchimp-for-wp`,
`seo-by-rank-math`. Rozbieżność jest normą katalogu, nie wyjątkiem.

Zysk praktyczny: text domain musi równać się slugowi, a to 1306 miejsc w kodzie —
14 znaków zamiast 29, plus krótsze nazwy plików w `languages/`.

## Co zostało zrobione

| Etap | Zakres | Commit |
|---|---|---|
| A + B | Text domain 1306 × + `phpcs.xml` (razem, inaczej 1306 × `TextDomainMismatch`) | `672471d` |
| C + D + G | Nagłówek, `readme.txt`, `README.md`, `git mv` głównego pliku, `languages/` | `eb778d9` |
| F | Stringi widoczne dla użytkownika (PHP, Twig, JS) + prefiksy logów | `57b5c6f` |
| H + I | `.gitlab-ci.yml` (45 linii), `dev.sh` | `e0a002a` |
| K | `TREETANK_TRANS_ALLOW_UNAUTHENTICATED_ENDPOINTS` + stara nazwa jako fallback | `e016587` |
| — | `phpcs*.xml`, `CiWorkspacePermissionsTest` | `d092c71` |

## Bramki po zmianie

| Bramka | Wynik |
|---|---|
| `composer test` | 500 Unit + 11 Architecture, 0 padnięć |
| PHPCS (`--warning-severity=0`, jak w CI) | 0 błędów |
| PHPCS pełny | 0 błędów / 49 ostrzeżeń (bez zmian) |
| `./dev.sh smoke` (WP 7.1) | aktywacja pod `treetank-trans` OK, Plugin Check 0 / 53 |

## Zobowiązania z tego maila

| Obietnica | Stan |
|---|---|
| Nazwa wyświetlana w nagłówku i readme | zrobione |
| Slug `treetank-trans` | zrobione w kodzie, czeka na rezerwację po ich stronie |
| Nowy ZIP | do zbudowania z release'u |

## Otwarte

- Nazwa repozytorium GitLab/GitHub — `.gitlab-ci.yml` nadal pushuje do
  `treetank-net/polytrans`. Przemianowanie repo jest bezpieczne (GitHub trzyma
  redirect), ale `Plugin URI` w nagłówku wskazuje na tę nazwę, więc recenzent to
  zobaczy.
- Wpis „PolyTrans" na `treetank.net/projects`.
- Sync na 4 instancjach: katalog trzeba przemianować i aktywować wtyczkę ponownie
  (`active_plugins` trzyma starą ścieżkę). Dane w bazie nietknięte.
