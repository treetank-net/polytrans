# Backlog — potencjalne rozszerzenia

Pomysły bez przypisanej wersji. Przy podjęciu: przenieść do planu wersji i opisać tu, dokąd poszły.

## Tłumaczenia

### Wycofać angielski jako język pośredni

Dziś ścieżki tłumaczeń przechodzą przez angielski (`pl>en>de`): każdy relay to dodatkowe
wywołanie modelu (koszt — `UsageRecorder` liczy je jako hop z `target_language` ≠
`final_language`), dodatkowe opóźnienie i kolejne miejsce, w którym znika treść (embedy,
linki, formatowanie psują się na każdym hopie). Obecne modele tłumaczą bezpośrednio
między dowolną parą wystarczająco dobrze.

- Domyślnie tłumaczyć bezpośrednio źródło → cel; relay tylko tam, gdzie świadomie skonfigurowany.
- Migracja istniejących ścieżek: pokazać w ustawieniach, które pary idą przez relay, i dać „zamień na bezpośrednie”.
- Przed zmianą domyślnej: porównać jakość i koszt na kilku parach (raport kosztów ma już `translation_path` i `path_step`).

### Łatwy wybór asystenta tłumaczącego

W praktyce wszystkie pary językowe używają jednego asystenta, a wybór jest per para, w
tabeli w ustawieniach (na trans.info ta tabela potrafi się nie doładować). Zmiana
asystenta oznacza przeklikanie każdej pary.

- Jedno ustawienie „domyślny asystent tłumaczący”, używane przez każdą parę bez własnego.
- Nadpisanie per para jako wyjątek, widoczny w tabeli jako różnica od domyślnego.
- Zmiana asystenta dostępna też z ekranu asystenta („użyj do tłumaczeń”).

### Ochrona embedów i linków zewnętrznych

Pomiar na trans.info (2026-11): w 80% par źródło/tłumaczenie embed (iframe YouTube/Facebook,
`blockquote.twitter-tweet` z linkiem i skryptem) znika już przy tłumaczeniu; linki
zewnętrzne giną w 27% tłumaczeń, część przy tłumaczeniu, część w kroku workflowu.
Reguły w promptach zostały dodane, ale prompt nie daje gwarancji.

- Przed wysłaniem do modelu podmienić iframe'y, embedy social media, skrypty i shortcody na tokeny `[[EMBED_n]]`, po odpowiedzi wstawić z powrotem — w tłumaczeniu i w krokach workflowów zmieniających treść.
- Brakujący token albo spadek liczby zewnętrznych `href` → wynik kroku odrzucony albo post oznaczony do przeglądu.

### Walidatory wyniku

- Liczba `<strong>` w tłumaczeniu nie większa niż w źródle.
- Tytuł ≤ 100 znaków; dłuższy → ponowienie kroku albo oznaczenie do przeglądu.
- Wspólny mechanizm „needs review” dla wszystkich walidatorów zamiast osobnych reakcji.

## Dostawcy

### Dostawca „WordPress AI Client” (WP 7.0+)

Zapowiedziany recenzentowi WordPress.org w odpowiedzi na rundę 2026-10-16. Dodatkowy
dostawca obok OpenAI/Claude/Gemini, widoczny tylko gdy istnieje `wp_ai_client_prompt()`:
klucze i model konfiguruje się centralnie w WordPressie, wtyczka nie trzyma własnego klucza.

- Nie zastępuje bezpośrednich integracji: AI Client nie ma reasoning effort, Responses API
  (wymagane dla modeli OpenAI 5.6+), Assistants ani streamingu, a domyślny timeout 30 s
  jest za krótki na długie artykuły — przez `Core\AsyncJobRunner` i ewentualnie z
  podniesionym timeoutem, jeśli API na to pozwala.
- `ModelCapabilities` musi zgłaszać dla niego brak reasoning effort, żeby UI nie oferował
  ustawień, których nie da się przekazać.
- `usage`: zapisać to, co AI Client zwraca; jeśli brak liczników tokenów, wywołanie jest
  „unpriced”, nie zerowe.
- Pakiet compat `wordpress/wp-ai-client` dla WP < 7.0 jest deprecated — nie dołączać.
