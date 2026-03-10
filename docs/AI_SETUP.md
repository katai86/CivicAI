# AI (Mistral) beállítás

## API kulcs

- **Elsőbbség:** Admin → Beépülő modulok → Mistral AI → „API kulcs” mező. Ha itt megadod, azt használja a rendszer.
- **Fallback:** `.env` vagy környezeti változó: `MISTRAL_API_KEY=...`
- Kulcs szerezhető: [platform.mistral.ai](https://platform.mistral.ai) (API keys).

## Limitek (adminban beállítható)

A Mistral modulnál (Beépülő modulok):

- **Napi max AI összefoglaló hívás** – gov/admin összefoglaló és ESG (alap: 20).
- **Napi max bejelentés-kategorizálás** – bejelentésnél AI kategória (alap: 1000).
- **Napi max kép-elemzés** – kép alapú AI (alap: 300).

Üresen hagyva az env/config értékek (vagy alapértelmezett) érvényesek.

## Teszt

Admin → Beépülő modulok → „Teszt Mistral” gomb. Siker: „Mistral: kapcsolat rendben.” Hiba: pl. „AI nincs bekapcsolva”, „Mistral API kulcs érvénytelen”, „Napi limit elfogyott”.
