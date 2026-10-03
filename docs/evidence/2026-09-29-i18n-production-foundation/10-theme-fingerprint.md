# Phase 0 baseline + Phase 1 artifact gates — production fingerprint

Captured 2026-09-29, read-only (GET/OPTIONS only). No production write performed.

## Theme fingerprint (positive discrimination)

| File | production status | production bytes | production md5 | i18n HEAD md5 | match |
|---|---|---|---|---|---|
| assets/css/main.css | 200 | 192920 | a4bab9a49b7dd0bdcce665f2380ce423 | a367b7950e0511958a3768d284a4d35b | NO |
| inc/i18n/guard.php | 404 | 60107 | 0822f986c3cb5e340d75a77f6b55327f | 0f982c38686ed287185f37ed8f00d1fe | NO |
| inc/rest-language.php | 404 | 60110 | bb74ead1ba4b1cf8716ea3654a3036df | 0b1f05d24cca8b4da4914a304aa86b71 | NO |

## Conclusion

Production serves the `master` build. `inc/i18n/` and `inc/rest-language.php` are 404.
