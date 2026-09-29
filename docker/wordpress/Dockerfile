# Local/CI WordPress image for the Conexão BR Irlanda test environment.
#
# WHY THIS FILE EXISTS
#
# The official `wordpress:latest` image contains NO gettext locales:
# /usr/share/i18n/locales is empty. PHP's setlocale(LC_TIME, ...) therefore
# falls back to "C", and date_i18n() renders ENGLISH month names in every
# language context.
#
# That breaks a real product contract of this bilingual site, not just a test:
# `wp-content/themes/conexao-br-irlanda/tests/test-i18n-foundation.php` asserts
# that dates render PORTUGUESE month names ("setembro") in the PT context, and
# the archive cards and singles render their dates through the same path. On a
# developer machine the assertion happened to pass only because the host image
# carried locales; on a fresh CI runner it could not pass at all. Making the
# locale part of the ENVIRONMENT — rather than an accident of the host — is
# what makes the check reproducible.
#
# SCOPE AND SAFETY
#
#   - This affects the LOCAL/CI Docker stack only. Production is WordPress.com
#     and never builds or reads this file; it has its own locale configuration.
#   - The base image is the SAME official image and the same tag the stack used
#     before, so no other behaviour changes. Nothing else is added, removed or
#     overridden.
#   - `locales` is a standard, maintained Debian package, and its version is
#     NOT pinned to a point release on purpose: pinning a single patch version
#     would make this image fail to build the moment Debian supersedes it,
#     which is a worse failure mode than picking up a compatible locale data
#     update. The package name is the contract; the data is not a
#     security-sensitive dependency.
#
# The `locales-all` package is used rather than generating a single locale
# because it is a single maintained package that guarantees the full set, so
# there is no per-locale build step to drift.
FROM wordpress:latest

# hadolint ignore=DL3008
RUN apt-get update \
 && apt-get install --yes --no-install-recommends locales locales-all \
 && rm -rf /var/lib/apt/lists/*

# Compile the locales into /usr/lib/locale, which is what PHP's setlocale()
# actually reads. `locale-gen` is a Debian convenience wrapper and is NOT
# present in this base image, so the underlying `localedef` is invoked directly
# against the installed locale archive.
#
# `locales-all` already compiles the whole set during the package install, so
# no separate localedef RUN is needed and there is no per-locale build step to
# drift. The two locales this site serves — pt_BR and en_US — are then asserted
# to be present, so the image FAILS TO BUILD rather than silently degrading to
# English if a future base image changes how the package installs. A missing
# locale is invisible at runtime (dates just render in English), so making it a
# build-time failure is the only way it can actually be caught.
RUN test -e /usr/lib/locale/pt_BR.utf8 || test -e /usr/lib/locale/pt_BR.UTF-8 \
 || test -e /usr/lib/locale/pt_BR/LC_TIME \
 && test -e /usr/lib/locale/en_US.utf8 || test -e /usr/lib/locale/en_US.UTF-8 \
 || test -e /usr/lib/locale/en_US/LC_TIME
