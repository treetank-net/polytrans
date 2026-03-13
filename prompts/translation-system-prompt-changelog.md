# Changelog: System Prompt Translatora

## 1. Nazwy firm w cudzysłowach

**Było** (uniwersalna reguła dla wszystkich języków):
```
- Always translate company and program names in quotation marks (e.g., Girteka → „Girteka")
```

**Jest** (domyślnie bez cudzysłowów, wyjątek dla litewskiego):
```
Keep company and brand names in their original form without any additional formatting
(no quotation marks, no italics, no special treatment). Simply write the name as-is.

{% if target_language == 'lt' %}
**Lithuanian exception**: In Lithuanian, company and brand names MUST be enclosed
in Lithuanian quotation marks „" (e.g., Girteka → „Girteka", Trans.eu → „Trans.eu").
This is required by Lithuanian grammar rules.
{% endif %}
```

---

## 2. Formatowanie walut

**Było**: Brak instrukcji — model sam decydował czy pisać "euro", "koron duńskich" itp.

**Jest** (nowa sekcja):
```
## Currency Formatting

Always use currency symbols or standard abbreviations instead of spelling out currency names:
- Use € instead of "euro", "euros", "eur" etc.
- Use $ instead of "dollars", "dolarów" etc.
- Use £ instead of "pounds", "funtów" etc.
- Use DKK instead of "Danish kroner", "koron duńskich" etc.
- Use SEK instead of "Swedish kronor", "koron szwedzkich" etc.
- Use NOK instead of "Norwegian krone", "koron norweskich" etc.
- Use PLN or zł instead of "Polish zloty", "złotych" etc.
- Use CZK instead of "Czech koruna", "korun czeskich" etc.
- Use HUF instead of "Hungarian forint", "forintów" etc.
- For any other currency, prefer the ISO 4217 code or widely recognized symbol.
```

---

## 3. Rok bez sufiksów

**Było**: Brak instrukcji — model dodawał "r.", "roku", "year" itp.

**Jest** (nowa sekcja):
```
## Year References

When mentioning years, write just the number without any suffix or label:
- Write "w 2026" NOT "w 2026 r." or "w 2026 roku"
- Write "in 2026" NOT "in the year 2026"
- Write "since 2020" NOT "since the year 2020"
- This applies to all languages — never add words like "r.", "rok", "year", "Jahr",
  "année", "год" etc. after a year number, unless the sentence would be ambiguous without it.
```

---

## 4. Tytuły — przepisywanie zamiast dosłownego tłumaczenia

**Było** (ogólnikowa wskazówka w Translation Guidelines):
```
3. **Avoid literal translation** - focus on conveying meaning.
```

**Jest** (dedykowana sekcja):
```
## Titles and Headlines

Titles and headlines should NOT be translated literally. Instead, rewrite them
from scratch in {{ target_language }} to be:
- Natural and fluent, as if originally written in {{ target_language }}
- Concise — avoid unnecessarily long titles
- Engaging and clickable — use a touch of tasteful clickbait where appropriate
- Faithful to the core meaning and topic of the original

Think of yourself as a {{ target_language }} editor writing a headline,
not a translator reproducing one.
```

---

## 5. Sekcje lokalizacyjne per język (Twig)

**Było**: Brak mechanizmu — wszystkie reguły stosowane jednakowo do każdego języka.

**Jest** (warunkowe bloki Twig, łatwo rozszerzalne):
```
{% if target_language == 'lt' %}
## Lithuanian-Specific Notes
- Company and brand names must be in „quotation marks" as noted above.
- Follow standard Lithuanian capitalization and grammar rules.
{% endif %}
```

Wzorzec do kopiowania dla kolejnych języków (DE, FR, RU itd.) w miarę napływu uwag lokalizacyjnych.
