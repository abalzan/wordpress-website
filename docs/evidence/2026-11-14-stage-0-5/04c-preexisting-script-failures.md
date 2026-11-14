# Pre-existing script-contract failures — proof they are independent of Stage 0.5

## verify-i18n-freshness.py

The gate compares GIT COMMIT timestamps (see its header: "git commit time, not
filesystem mtime"), so it is a property of the committed history and cannot be
affected by working-tree edits. Measured here with git only:

```
catalogue: wp-content/themes/conexao-br-irlanda/languages/conexao-br-irlanda.pot
  commit:   2026-11-06 11:36:54 +0000 657c334
source:    wp-content/themes/conexao-br-irlanda/template-parts/event-filters.php
  commit:   2026-11-10 19:19:01 +0000 6bc43c8

catalogue: wp-content/plugins/conexao-admin-ux/languages/conexao-admin-ux.pot
  commit:   2026-11-06 11:36:54 +0000 657c334
source:    wp-content/plugins/conexao-admin-ux/includes/class-editor.php
  commit:   2026-11-06 13:18:17 +0000 b18fa58

catalogue: wp-content/plugins/conexao-commemorative/languages/conexao-commemorative.pot
  commit:   2026-11-06 11:36:54 +0000 657c334
source:    wp-content/plugins/conexao-commemorative/includes/class-conexao-commemorative-admin.php
  commit:   2026-11-06 13:18:17 +0000 b18fa58

```

Both paths are unmodified in this stage:

```
(no output = clean)
```
