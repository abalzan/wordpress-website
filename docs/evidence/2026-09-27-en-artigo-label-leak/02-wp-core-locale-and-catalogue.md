# WordPress core label / locale behaviour (Phase 4)

Measured **inside the running WordPress** (7.1.2) via a throwaway script
loaded from `/tmp` inside the container. It was deleted after the run; no
working-tree file was created or modified.

```
WP version: 7.1.2
get_locale(): pt_BR
pll_current_language: pt
post->labels->singular_name: Artigo
post->labels->name: Blog
after switch en_US singular_name: Post
after switch pt_BR singular_name: Post
theme textdomain loaded: NO
theme en_US.mo: MISSING
__('Artigos e Notícias') => Artigos e Notícias
```

## Answers to the Phase 4 questions

| Question | Answer |
|---|---|
| WP core version | **7.1.2** |
| WP locale on a default request | **`pt_BR`** |
| Polylang language | **`pt`** (CLI default; Polylang sets `en` on `/en/`) |
| `post` → `singular_name` under `pt_BR` | **`Artigo`** |
| `post` → `singular_name` under `en_US` | **`Post`** |
| Does core already ship `Artigo → Post`? | **Yes.** The msgid core registers is `Post`; `pt_BR` renders it `Artigo`. Switching to `en_US` yields `Post`. |

**Core is not at fault and is not bypassed.** Core's own `post` label is
correctly translated. It is simply irrelevant to the string on screen,
because the string on screen is not a core label at all (§1 of
`01-source-trace.md`).

Note the last two lines of the switch experiment: after `switch_to_locale()`
the value stays `Post`, because `get_post_type_object()` returns the
**already-instantiated** object cached at `init`. Labels are frozen per
request at `init` time. This is why a runtime `switch_to_locale()` cannot
retro-fix a label, and why any correct fix must go through gettext on the
msgid, not through re-registration.

## The deleted catalogue *did* contain the correct translation

Reading the `en_US.mo` that `597b04e` deleted, loaded from outside the repo:

```
MO imported (the catalogue deleted by 597b04e): YES

  Artigos e Notícias      => Articles & News
  Guia Prático            => Practical Guide
  Mais Lidos              => Most Read
  Categorias do blog      => Blog categories

--- bare msgid 'Artigo' in the EN catalogue? ---
  string(6) "Artigo"      <-- NO entry; returns verbatim
```

Two conclusions:

1. **The English for the on-screen string already existed** as
   `Articles & News` and was removed by the baseline commit. This is a
   *regression*, not a missing translation.
2. There is **no** bare `Artigo` msgid in the catalogue. A post-type-label
   leak of the literal `Artigo` would still have needed core's `Post →
   Artigo`/`Post` pair — but no such occurrence exists on `/en/` now.
