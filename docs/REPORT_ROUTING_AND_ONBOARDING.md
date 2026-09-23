# Bejelentés routing, ügyiratszám, hatóság onboarding

## Ügyiratszám (CIV)

**Új formátum:** `CIV-{város2betű}-{YYYYMMDD}-{NNNN}`

Példa: `CIV-BU-20260902-0003` (Budaörs, 2026.09.02., napi 3. sorszám)

- A város prefix ékezet nélkül, első 2 betű (transzliteráció).
- Napi sorszám város-prefix + dátum szerint (`case_serials` tábla).
- Régi bejelentések: `OH-YYYY-NNNNNN` fallback, ha nincs `reports.case_no`.

## Automatikus routing (mock e-mail)

Bejelentés létrehozásakor (`ReportRoutingService::routeAfterCreate`):

| Kategória | Feltétel | Cél | Mock e-mail (config) |
|-----------|----------|-----|----------------------|
| `lighting` | – | MVM Lumen | `ROUTING_EMAIL_MVM_LUMEN` |
| `road`, `sidewalk`, `traffic` | Országút / M-út / főút név | Közút Nonprofit | `ROUTING_EMAIL_STATE_ROAD` |
| `road`, `sidewalk`, `traffic` | Egyéb út | Önkormányzat jegyző | `ROUTING_EMAIL_MUNICIPAL` |
| egyéb + van `authority_id` | – | Hatóság inbox | `authorities.contact_email` |

Országút heurisztika: `M1`, `51-es főút`, `országút`, `autópálya` stb.

Állapot: sikeres routing után `status = forwarded`, log: `report_routing_log`.

### Config (`.env` / `config.local.php`)

```
ROUTING_MOCK_ENABLED=1
ROUTING_EMAIL_MUNICIPAL=mock-jegyzo@civicai.test
ROUTING_EMAIL_STATE_ROAD=mock-kozut@civicai.test
ROUTING_EMAIL_MVM_LUMEN=mock-mvm-lumen@civicai.test
GOV_JOIN_AUTO_APPROVE=1
```

## Hatóság – felhasználó kapcsolat

### Régi (fenntarthatatlan nagy létszámnál)

Admin → Hatóságok → e-mail alapján kézi `assign_user`.

### Új folyamat

1. **Regisztráció** (`govuser`): város + szervezet megadása → `authority_join_requests`.
2. **Egyértelmű város-egyezés** + `GOV_JOIN_AUTO_APPROVE=1` → azonnali `authority_users` link.
3. **Több / nulla találat** → admin jóváhagyás (Admin → Függő hatóság-kapcsolási kérelmek).
4. **Meglévő gov user** kapcsolat nélkül: `/gov/` figyelmeztetés + kérelem űrlap → `POST /api/authority_join_request.php`.

## Gov bejelentés-kezelés

A **Bejelentések** fülön táblázat: ügyiratszám, kategória, routing cél, **státusz dropdown** (mint az Ötleteknél).

Backend: meglévő `set_status` POST handler (jogosultság: saját hatóság).

## Admin: hatóság + routing override

Minden bejelentés kártyán (Admin → Bejelentések):

- **Hatóság** dropdown → manuális `authority_id`
- **Routing cél** → automatikus vagy kényszerített cél (jegyző / Közút / MVM / hatóság inbox)
- **Mentés + routing** → mentés és azonnali továbbítás
- **Újrouting** → újra futtatás meglévő adatokkal
- **GPS hatóság** → `resolve_authority_for_location` egy bejelentésre
- **Routing napló** → `report_routing_log` sorok

API: `POST /api/admin_report_routing.php`

| action | Leírás |
|--------|--------|
| `set_authority` | Csak hatóság mentés |
| `set_override` | Routing override cél mentés |
| `save_and_reroute` | Hatóság + override + küldés |
| `reroute` | Újrouting |
| `reassign_geo` | GPS alapú hatóság |

## Valós integráció (email / webhook)

Célenként `ROUTING_MODE_*`:

| Mód | Viselkedés |
|-----|------------|
| `mock` | Mock e-mail cím (teszt) |
| `email` | Valós cím (`ROUTING_EMAIL_*` vagy hatóság `contact_email`) |
| `webhook` | HTTP POST JSON a megadott URL-re |

Webhook payload mezők: `case_no`, `report_id`, `category`, `lat`, `lng`, `road`, `city`, `description`, …

Válaszból kiolvasható: `ticket_id` / `id` / `external_id` → `reports.external_ticket_id`

Példa config (`config.local.php`):

```php
'ROUTING_MODE_STATE_ROAD' => 'webhook',
'ROUTING_WEBHOOK_STATE_ROAD_URL' => 'https://api.partner.hu/kozut/intake',
'ROUTING_WEBHOOK_MVM_LUMEN_URL' => 'https://api.partner.hu/mvm/intake',
'ROUTING_WEBHOOK_TOKEN' => 'bearer-token',
'ROUTING_MOCK_ENABLED' => '0',
```

## Migráció

```bash
# 2026-35 alap
Get-Content sql/2026-35-report-routing-foundation.sql | docker compose exec -T db mysql ...
# 2026-36 admin + delivery oszlopok
Get-Content sql/2026-36-routing-delivery-admin.sql | docker compose exec -T db mysql ...
```

## Következő lépések (terv)

- [ ] Valós partner API szerződés (Közút, MVM, önkormányzati ügyfélkapu)
- [ ] OSM / KÖUT adat alapú út-tulajdonos felismerés (ne csak név-heurisztika)
- [x] Civil / community automatikus geo-kapcsolás
- [x] Admin routing override UI
- [x] Webhook / email delivery váz
- [ ] SMTP relay konfiguráció dokumentáció (sendmail helyett)

## Civil / közület kapcsolás

- Regisztráció: `communityuser` / `civiluser` megadja a várost → egyértelmű találat esetén `authority_id` beállítás a profil rekordokon.
- `facility_save` / `civil_event_create`: GPS + cím alapján `resolve_authority_for_location` → `authority_id` mentés.
