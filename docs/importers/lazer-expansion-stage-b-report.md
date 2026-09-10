# Lazer Expansion — Stage B

## Candidate Verification, Normalization & Curated Dataset

- **Date**: 2026-10-09
- **Stage**: READ-ONLY. No WordPress records created, updated, deleted or migrated. Production untouched. Import not implemented.
- **Authority**: `docs/importers/lazer-expansion-stage-a-audit.md` (Stage A — PASSED)
- **Output dataset**: `docs/importers/lazer-expansion-stage-b-dataset.json` (20 normalized candidates)
- **Scope**: Republic of Ireland / 26-county model only. No Northern Ireland candidates in the import dataset.

---

## 0. Method

1. Re-read Stage A authority (6 HIGH, 13 MEDIUM, 1 LOW) — no Stage A classification discarded without evidence.
2. Deduplicated every candidate against all existing Lazer records in the repo dataset (`scripts/seed-leisure-locations.php`, `scripts/data/leisure-expansion-data-1.php`, `leisure-expansion-data-2.php`) using canonical official URL → Discover Ireland URL → source URL → normalized title → title + town/county → known aliases. No title-only matching.
3. Verified candidates individually against the preferred source order: official attraction/operator website → official public body (dlr CoCo, Monaghan CoCo, National Archives, OPW/Heritage Ireland) → Discover Ireland attraction page → Ireland.com page (Stage A fetches).
4. Original Stage A discovery URLs preserved in every record (`source_discovery_url`), even where generic (county hubs) — recorded as discovery references only, never stored as attraction URLs (§14).
5. Verified facts only; no prose, images, logos or PDFs copied from either source site. No prices invented.

**Status symbols**: ✅ fetched and confirmed in Stage B (2026-10-09) · 🔁 confirmed in Stage A, not refetched · ⚠ could not be confirmed (fetch failed / URL unresolved — improvement or review note recorded).

---

## 1. Classification summary (20 candidates)

| Status | Count | Candidates |
|---|---|---|
| `NEW` | **12** | Chester Beatty, The Forty Foot, Derrigimlagh, James Joyce Tower Museum, The Model (Niland Collection), Doagh Famine Village, Dursey Island, Old Head of Kinsale, Lough Muckno Leisure Park, The John F. Kennedy Arboretum, Cathedral of St Patrick & St Felim (Cavan), National Design & Craft Gallery |
| `REVIEW` (Hold) | **4** | National Archives of Ireland, Arranmore Island, Dan O'Hara's Homestead, Duncannon Fort |
| `EXISTING — DATA IMPROVEMENT` | **1** | Knocknarea / Queen Maeve's Trail (existing record `queen-maeves-trail-knocknarea`) |
| `REJECTED` | **3** | Galway city, Mullaghmore, Jerpoint Glass Studio |
| `DUPLICATE OF ANOTHER CANDIDATE` | 0 | — |

---

## 2. Deduplication findings (against 233 existing records)

| Candidate | Existing-record risk | Resolution |
|---|---|---|
| **Knocknarea / Queen Maeve's Trail** | `queen-maeves-trail-knocknarea` (expansion-2, Sligo) — identity + locality match | Stage A listed it as "genuinely new"; Stage B found the existing record. **`EXISTING — DATA IMPROVEMENT`** — no second record. |
| Galway city | `spanish-arch-long-walk`, `galway-city-museum`, `salthill`, `brigits-garden`, `coole-park`, `portumna-castle-gardens`, `thoor-ballylee`, `sky-road` | City sites already covered by specific records; discovery URL is a county hub. **`REJECTED`.** |
| Arranmore (Donegal) | `aran-islands` (Galway), `dun-aonghasa` | Different island group; no identity collision — but no canonical attraction URL confirmed → REVIEW, not duplicate. |
| Jerpoint Glass | `jerpoint-abbey`, `jerpoint-park` (Kilkenny) | Different identity (studio/shop vs abbey/park); no collision. Rejected on product-intent grounds (§7). |
| Mullaghmore (Sligo) | `strandhill-beach`, `carrowmore`, Benbulben set | No collision; rejected on LOW add-value (Stage A). |
| The Model (Sligo) | Strandhill/Carrowmore/Sligo outdoor set | No collision (indoor gallery ≠ outdoor sites). NEW. |
| Chester Beatty, Forty Foot, Derrigimlagh, Joyce Tower, Doagh, Dursey, Old Head, Muckno, JFK Arboretum, Cavan Cathedral, NDCG | name/URL/identity greps across seed + expansion-1/2 = **0 hits** | Genuinely absent from the dataset. NEW. |

No duplicate attraction records proposed. No existing record modified.

---

## 3. Verification evidence per candidate (NEW set)

| # | Candidate | Stage A class | Primary source (Stage B) | Free/paid | Year-round | Key verified facts |
|---|---|---|---|---|---|---|
| 1 | Chester Beatty | HIGH | ✅ chesterbeatty.ie + ✅ DI page | Gratuito (doação sugerida €10) | A | "Admission is Free"; Dublin Castle, D02 AD92; sem pré-reserva; áudio-guia em 13 idiomas; **fechado 15/06–31/12/2026 (Presidência UE)**, confirmado em ambos os sites |
| 2 | The Forty Foot | HIGH | ✅ dlrcoco.ie (conselho dlr) | Gratuito | A | Banho de mar gratuito e permanente; estacionamentos públicos próximos; DART Sandycove & Glasthule; sem salva-vidas. Sem página DI dedicada (404 × 2) |
| 3 | Derrigimlagh | HIGH | ✅ DI page + 🔁 Ireland.com | Gratuito | A | Ballyconneely, Co. Galway; tag "Free to visit"; endereço Ballinaboy; Marconi + memorial Alcock & Brown; sem operador próprio |
| 4 | James Joyce Tower Museum | MEDIUM | ✅ joycetower.ie | Gratuito | A | Terça–domingo 10h–16h, segunda fechada; grupos grátis com doação €1 e reserva; **não acessível a cadeirantes** (site oficial); A96 FX33 |
| 5 | The Model (Niland Collection) | MEDIUM | ✅ themodel.ie | Gratuito (galerias) | A | Free Entry; ter–sáb 11h–17h, dom 12h–16h; The Mall, F91 TP20; página de acessibilidade e programação familiar próprias |
| 6 | Doagh Famine Village | MEDIUM | ✅ DI page (operador 403 a agentes) | Pago | B | Lagacurry, Ballyliffin, F93 PK19; família/cachorros/estacionamento grátis (tags DI); predominantemente coberto; **horários sazonais a reconfirmar** |
| 7 | Dursey Island | MEDIUM | 🔁 Ireland.com (operador fetch falhou) | Gratuito em determinadas condições | A | Ilha gratuita; teleférico pago; operador (cable car) a validar na implementação |
| 8 | Old Head of Kinsale | MEDIUM | 🔁 Ireland.com (DI 404) | Gratuito em determinadas condições | A | Headland gratuito; farol pago; interior do golf links privado — conteúdo voltado ao mirante/torre-sinal |
| 9 | Lough Muckno Leisure Park | MEDIUM | ⚠ monaghan.ie não localizada | Gratuito | A | Parque lacustre municipal em Castleblayney; identidade física clara; página dedicada nem em DI nem no conselho — coletar antes de publicar |
| 10 | The John F. Kennedy Arboretum | MEDIUM | ✅ DI page | Gratuito em determinadas condições | A | 252 ha, +5.000 tipos de árvores; Ballysop, New Ross, Y34 KA48; tours gratuitos Mar–Out; estacionamento pago; família/cachorros (tags DI) |
| 11 | Cathedral of St Patrick & St Felim | MEDIUM | ⚠ sem fonte primária confirmada | Gratuito | A | Interior Art Deco notável; visitável como local de culto; endereço de rua e site paroquial **não confirmados** — coletar na implementação |
| 12 | National Design & Craft Gallery | MEDIUM | ✅ ndcg.ie | Gratuito | A | Castle Yard (séc. XVIII), R95 CAA6; "Ireland's only dedicated design and craft gallery"; horários a capturar |

Promotions with evidence: Joyce Tower (MEDIUM → included P2; Stage A's "seasonal" concern resolved — official site shows permanent weekly hours, not seasonal closure) and The Model (MEDIUM → included P2; official gratuity/hours confirmed).


---

## 4. REVIEW / HOLD candidates (not in the import set)

| Candidate | Stage A class | Verification | Why held |
|---|---|---|---|
| National Archives of Ireland | MEDIUM | ✅ nationalarchives.ie (gratuidade, bilhete de leitor, Bishop St D08 DF85, 5 dias/semana) | Fatos sólidos, mas perfil de **repositório de pesquisa**, não de destino de lazer (§7/§21). Decisão editorial antes de qualquer import. |
| Arranmore Island | MEDIUM | ⚠ arranmoreferry.com fetch falhou | Sem URL canônica de atração confirmada (§21); página dedicada em DI não localizada. Ilha física confirmada pelo Stage A; revalidar operador/tarifa. |
| Dan O'Hara's Homestead | MEDIUM | ⚠ connemaraheritage.ie fetch falhou | URL canônica não validada (§21); horários/temporada não confirmados; centro comercial pequeno — confirmar relevância. |
| Duncannon Fort | MEDIUM/LOW | ⚠ URL DI candidata 404 (`-fort`); `-tour` não revalidada | Perfil modesto; URL registrada termina em `-tour` (produto de tour, não atração canônica). Se promovido: B (terreno permanente, interior sazonal). |

## 5. REJECTED candidates (com rationale)

| Candidate | Stage A class | Rationale |
|---|---|---|
| Galway city | MEDIUM | 1) fonte de descoberta = hub de condado (URL genérica — §14); 2) pontos centrais já cobertos por 6+ registros específicos; 3) baixo valor agregado (Stage A). Reversível com decisão editorial (precedente Carlingford). |
| Mullaghmore | LOW | Vilarejo base sem atração física própria; Sligo já denso no diretório (Stage A: baixo valor agregado). Página Ireland.com dedicada existe — reversível. |
| Jerpoint Glass Studio | LOW | Propósito primário comercial confirmado pelo domínio oficial (e-commerce/loja; sem página de visitação localizada) — §7 "commercial-primary". Distinto de Jerpoint Abbey/Park existentes. |

## 6. EXISTING — DATA IMPROVEMENT (registro `queen-maeves-trail-knocknarea`)

Improvements documented, **not applied** (§20). Application deferred to the implementation stage:

1. `_leisure_town` vazio → definir **Strandhill**.
2. URL DI dedicada ausente → localizar no sitemap DI (candidata não confirmada nesta etapa).
3. Atributo **Estacionamento** ausente → confirmar estacionamento da trilha antes de atribuir.
4. Mapa determinístico ausente → `Knocknarea, Strandhill, Co. Sligo`.


---

## 7. Year-round classification (A/B/C/D)

| Class | Candidates |
|---|---|
| **A — permanente** (11/12 NEW) | Chester Beatty (nota de fechamento temporário 2026), Forty Foot, Derrigimlagh, Joyce Tower, The Model, Dursey, Old Head, Muckno, JFK Arboretum, Cavan Cathedral, NDCG |
| **B — permanente com facilities sazonais** (1/12 NEW) | Doagh Famine Village (destino físico permanente; horários/tripulação sazonais — registrado com `year_round: false` e nota de reconfirmação obrigatória) |
| **C — sazonal** | — (nenhum candidato no dataset NEW) |
| **D — temporário/evento** | — (nenhum candidato; o fechamento 2026 do Chester Beatty é **temporário e documentado**, não define o destino) |

Distinção aplicada (regra §8): "centro de visitantes fechado no inverno" ≠ "destino indisponível no inverno". Joyce Tower (horários semanais fixos, operação por voluntários) e Doagh (coberto, aberto na baixa temporada conforme reconfirmação) foram avaliados pelo destino físico.

## 8. Categories & attributes (controlled vocabularies)

- **`conexao_category`**: usados apenas termos existentes — Museus, Cultura, Praias, História, Natureza, Patrimônio, Jardins, Ilhas, Caminhadas, Cidades. **Nenhum termo novo proposto** (nenhum candidato exige vocabulário fora do existente).
- **`conexao_leisure_attribute`**: atribuídos só com evidência verificada (§12) — Exterior, Interior, Interior + exterior, Famílias, Pet friendly, Acessível, Estacionamento, Acesso de transporte público, Bicicleta. **Não atribuídos** sem suporte: Acessível (Joyce Tower explicitamente não acessível), Estacionamento (Muckno/Cavan/Old Head/Derrigimlagh não verificados), Necessita reserva (Joyce Tower só para grupos — nota prática, não atributo).

## 9. Map data (deterministic)

Todos os 20 registros incluem `map_query` construído apenas de dados verificados: nome oficial + endereço/Eircode (quando autoritativo) ou lugar + cidade + condado. Ex.: `Chester Beatty, Dublin Castle, Dublin 2, D02 AD92`; `Derrigimlagh Bog, Ballinaboy, Clifden, Co. Galway`. Para Cavan Cathedral e Duncannon Fort, `map_query` usa o nível cidade/condado (nível mais autoritativo disponível — sem endereço inventado, §10). Coordenadas de artigos-fonte não foram usadas. O modelo existente (`_leisure_map_url`, derivado em render) é reutilizado sem mudanças.

## 10. Images & legal boundaries (§18/§19)

- **Nenhuma imagem baixada.** Cada registro tem `image_status` com: candidato provável/incerto no Wikimedia Commons, caminho de licença a confirmar, e ausência de imagem Conexão BR existente.
- Imagens de Discover Ireland / Ireland.com **não reutilizáveis** (© Fáilte Ireland / Tourism Ireland; sem licença aberta) — confirmado no Stage A e mantido.
- Nenhum texto-fonte copiado; descrições são autorais em português, factuais, curtas (regra §15).
- URLs canônicas (oficial / DI / Ireland.com) armazenadas como referência factual e atribuição — nunca hub genérico como URL de atração (§14).
- Ambigüidades documentadas por registro em `notes` (conflitos não encontrados entre fontes; lacunas marcadas ⚠ em vez de preenchidas).

## 11. Recommended implementation priority (§22)

**Priority 1** — HIGH + localização forte + fonte canônica + evidência year-round:
1. Chester Beatty (Dublin) — confirmar reabertura jan/2027 antes de publicar
2. The Forty Foot (Dublin)
3. Derrigimlagh (Galway)

**Priority 2** — MEDIUM promovidos após verificação:
4. James Joyce Tower Museum (Dublin)
5. The Model (Sligo)
6. Doagh Famine Village (Donegal; reconfirmar horários)
7. Dursey Island (Cork; validar operador)
8. Old Head of Kinsale (Cork; localizar URL DI)
9. Lough Muckno (Monaghan; coletar página dedicada)
10. JFK Arboretum (Wexford; localizar página OPW)
11. Cathedral St Patrick & St Felim (Cavan; coletar fonte primária)

**Priority 3** — valor editorial menor / melhoria de registro:
12. National Design & Craft Gallery (Kilkenny; condado já denso)
13. Knocknarea — `EXISTING — DATA IMPROVEMENT` (4 melhorias listadas em §6)

**Hold** — REVIEW: National Archives, Arranmore, Dan O'Hara's Homestead, Duncannon Fort.

## 12. Recommended next step (out of scope here)

1. Presentar o dataset NEW (12) + melhoria (1) para sign-off editorial.
2. Na implementação: rodar o fluxo existente de verificação de URLs (padrão `scripts/verify-lazer-urls.php`) nos `official_url`/`discover_ireland_url`/`map_query`, coletar as lacunas marcadas ⚠, e só então Stage C import.
3. Nenhuma mudança de produção nesta etapa.

---

LAZER EXPANSION STAGE B COMPLETE — dataset curated; production untouched.

