# User-friendly backlog (prioritized)

Prioritás: **impact** (felhasználói érték / hibák csökkentése) vs **effort** (fejlesztési költség). A terv (audit + log) és a meglévő UX-listák alapján.

## Kész / stabilizált (P0–P1, audit)

| Tétel | Impact | Effort | Megjegyzés |
|--------|--------|--------|------------|
| Gov 500 / parse error javítások | Magas | Alacsony | `UrbanPredictionEngine`, `h()`, `json_response` headers-safe |
| Egységes admin API auth válasz | Magas | Alacsony | `require_admin()` + JSON 401/403 XHR/JSON esetén |
| Közös `CivicApi.fetchJson` | Közepes | Közepes | `assets/api_client.js` – nyilvános térkép, admin, report oldal |

## P2 – Következő nagy UX tételek

| # | Funkció | Impact | Effort | Rövid leírás |
|---|---------|--------|--------|----------------|
| 1 | **Bejelentés wizard** (lépések + progress) | Magas | Magas | Kategória → leírás/fotó → cím/hely → értesítés; kevesebb kognitív terhelés |
| 2 | **Menthető draft** (localStorage) | Magas | Közepes | Modal bezárás / hálózati hiba után ne vesszen el a szöveg |
| 3 | **Betöltés + retry** (rétegek, listák) | Magas | Közepes | Skeleton / „Betöltés…” + „Újrapróbálás”; már van backoff a GET gateway hibákra az API kliensben |
| 4 | **Toast / inline hiba** `alert` helyett | Közepes | Közepes | Egységes, elhalványuló visszajelzés; hibaüzenet `CivicApi.parseErrorMessage` alapján |
| 5 | **Kategória / státusz konzisztencia** | Közepes | Közepes | Lakossági / gov / admin: közös színek és címkék egy forrásból (CSS token + i18n kulcs) |
| 6 | **Onboarding / üres állapotok** | Közepes | Alacsony–közepes | Első látogatás: rövid tooltip vagy 1 képernyős intro a térképhez |
| 7 | **Offline / lassú hálózat** | Közepes | Magas | Service worker finomhangolás, queue a bejelentéshez (nehéz, de nagy nyereség) |
| 8 | **Hozzáférhetőség (a11y)** | Közepes | Közepes | Fókusz rend, ARIA a modáloknál és FAB-nál |
| 9 | **Gov dashboard: üres és hibaállapot** | Közepes | Közepes | Üres chart vs API hiba vizuálisan elkülönítve; „Frissítés” gomb |

## Ajánlott sorrend (sprint-szerűen)

1. **Toast + inline hiba** – gyors nyereség az `alert`-csökkentéssel, jól párosul az `api_client`-tel.  
2. **Draft localStorage** – relatív kis kockázat, nagy „nem veszett el” érzet.  
3. **Wizard** – nagyobb refaktor; érdemes a draft + lépésenkénti validáció után.  
4. **Közös kategória/státusz forrás** – párhuzamosan a wizardral vagy előtte egy „design token” PR.  
5. **Onboarding** – marketing / bevezető szöveg + meglévő tour (`tour.js`) összekapcsolása.

## Metrikák (opcionális, később)

- Bejelentés leadási arány (funnel lépések).  
- API hibák száma session-enként (4xx/5xx).  
- „Újrapróbálás” használata vs elhagyás.

---

*Utolsó frissítés: audit terv alapján; a fenti táblázat impact/effort becslés, finomítható backlog grooming során.*
