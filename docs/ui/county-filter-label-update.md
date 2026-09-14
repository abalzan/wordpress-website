# County Filter Label Update

## Summary

Updated the visible/filter terminology for the county filter from "Localização" to "County" for both the Eventos and Lazer archive filters. This is a terminology/UI-only change — the underlying `county` query parameter, taxonomy, slugs, and filtering logic remain unchanged.

## Files Changed

### Eventos
- `wp-content/themes/conexao-br-irlanda/template-parts/event-filters.php`

### Lazer
- `wp-content/themes/conexao-br-irlanda/template-parts/leisure-filters.php`

## Implementation Notes

Eventos and Lazer use **separate implementations** (different template files). They were both updated independently while keeping their existing architecture. They were NOT merged.

## Exact Labels Changed

### Eventos (`event-filters.php`)

Desktop:
| Location | Before | After |
|---|---|---|
| Trigger button aria-label | `Filtrar por Localização` | `Filtrar por County` |
| Dropdown label | `Localização` | `County` |
| Search input placeholder | `Procurar localização` | `Procurar county` |
| Search input aria-label | `Procurar localização` | `Procurar county` |
| Listbox aria-label | `Localização` | `County` |
| Empty state message | `Nenhuma localização encontrada.` | `Nenhum county encontrado.` |

Mobile:
| Location | Before | After |
|---|---|---|
| Section legend | `Localização` | `County` |
| Search input placeholder | `Procurar localização` | `Procurar county` |
| Search input aria-label | `Procurar localização` | `Procurar county` |
| Empty state message | `Nenhuma localização encontrada` | `Nenhum county encontrado` |

### Lazer (`leisure-filters.php`)

Desktop:
| Location | Before | After |
|---|---|---|
| Trigger label (variable) | `Localização` | `County` |
| Trigger aria-label | `Filtrar por Localização` / `Filtrar por Localização. Filtro ativo: %s` | `Filtrar por County` / `Filtrar por County. Filtro ativo: %s` |
| Search input placeholder | `Procurar localização` | `Procurar county` |
| Search input aria-label | `Procurar localização` | `Procurar county` |
| Empty state message | `Nenhuma localização encontrada.` | `Nenhum county encontrado.` |

Note: The Lazer desktop listbox aria-label was already `Condado` (not `Localização`) — no change needed there.

Mobile:
| Location | Before | After |
|---|---|---|
| Section legend | `Localização` | `County` |
| Search input placeholder | `Procurar localização` | `Procurar county` |
| Search input aria-label | `Procurar localização` | `Procurar county` |
| Empty state message | `Nenhuma localização encontrada` | `Nenhum county encontrado` |

## What Was NOT Changed

- The `county` query parameter (remains `?county=slug`)
- The `conexao_county` taxonomy (unchanged)
- County slugs (e.g., `carlow`, `cavan`, `dublin`)
- Filtering logic (server-side `pre_get_posts` behavior)
- AND semantics with other filters
- URLs (structure unchanged)
- Pagination behavior
- Existing option ordering
- Existing dropdown behavior
- CSS/layout (no changes required — "County" fits within existing spacing)
- Mobile/desktop interaction behavior
- "Todas"/"Todos" reset option text (unchanged — separate from the filter dimension label)
- Internal code comments describing the data model (unchanged)

## Regression Tests Performed

### Eventos
- [x] Open Eventos archive
- [x] Confirm dropdown label is "County"
- [x] Confirm search placeholder is "Procurar county"
- [x] Confirm accessibility labels say "County"
- [x] Select Carlow
- [x] Verify URL contains `county=carlow`
- [x] Verify results are filtered correctly
- [x] Clear with "Todas"
- [x] Verify results return to unfiltered state
- [x] Test combined county + category/city filters

### Lazer
- [x] Open Lazer archive
- [x] Confirm equivalent label is "County"
- [x] Confirm search placeholder
- [x] Select a county
- [x] Verify URL/filter behavior remains unchanged
- [x] Test clearing the filter
- [x] Test combination with other Lazer filters

### Responsive
- [x] Desktop behavior verified
- [x] Mobile behavior verified

## Status

**PASSED**

All acceptance criteria met:
- Eventos shows "County" instead of "Localização"
- Lazer shows "County" instead of "Localização"
- All related accessibility/search/empty-state labels are updated
- Underlying `county=` functionality is unchanged
- Existing filtering behavior passes regression testing
- No unrelated uses of "Localização" were changed (Empregos directory filter left untouched)
- Desktop/mobile behavior remains correct
