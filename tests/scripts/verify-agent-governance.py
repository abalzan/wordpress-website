#!/usr/bin/env python3
"""Stage K agent-governance gate.

A STATIC check over the repository's agent-governance surface: the WordPress
agent skills under `.agents/skills/`, the orientation document `AGENTS.md`, the
task/report templates, the pull-request template and the documentation index.

It loads no WordPress, opens no socket, contacts no network, writes nothing and
can never contact production. It only reads files in this repository.

Engineering standard §13 requires the repository to maintain WordPress-domain
skills, and §13.2 makes "read AGENTS.md + the standard", "use the plan and
report templates" and "never introduce a second source of truth" MUSTs. This
gate turns those into something CI can fail on, so a skill that rots or a
template that is deleted is caught rather than discovered a year later.

What it asserts:

   1. The governance files exist: AGENTS.md, docs/templates/plan.md,
      docs/templates/report.md, .github/PULL_REQUEST_TEMPLATE.md.
   2. .agents/skills/ exists and every skill directory has a SKILL.md.
   3. Every active SKILL.md has the six required sections, in order.
   4. Every required Stage K WordPress skill is present.
   5. Every repository path a skill references actually exists (no invented
      paths, no dangling documentation references).
   6. The active skill set contains no obsolete Dart/Flutter/mobile skills, and
      the retired material lives outside the active namespace.
   7. No skill introduces a second plugin registry or contradicts plugins.json.
   8. AGENTS.md points at the engineering standard, the templates and
      plugins.json.
   9. The PR template carries the core engineering-standard checks.
  10. docs/README.md exposes the templates and the agent skills.
  11. The plan and report templates carry the content/route/Polylang/verification
      and rollback sections the standard requires.
  12. No governance file introduces a localhost URL, a hard-coded credential or
      an external filesystem path.
  13. The Stage K surface is documentation-only: no runtime file under
      wp-content/ is introduced by the governance layer.
  14. Every active skill follows the full standard format (Purpose, When to use,
      When not to use, Required reading, Authoritative sources, Preconditions,
      Steps, Guardrails, Verification, Failure handling, Evidence and reporting,
      Definition of done), and repeats no heading.
  15. A skill that operates a live system or writes data states the
      no-production-write sentence.
  16. No skill references a skill that does not exist (dangling reference), and
      no skill directs work at the permanently out-of-scope mobile repository.
  17. .agents/skills/README.md lists every active skill, no stale entry, and
      carries its _Last verified_ line.

Exit code: 0 when every check passes, 1 otherwise. Every failure is printed
with the offending path so the fix is obvious.
"""

from __future__ import annotations

import os
import re
import sys

REPO_ROOT = os.environ.get("CONEXAO_REPO_ROOT") or os.path.abspath(
    os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "..")
)

SKILLS_DIR = os.path.join(REPO_ROOT, ".agents", "skills")
LEGACY_DIR = os.path.join(REPO_ROOT, ".agents", "legacy-flutter-skills")

AGENTS_MD = "AGENTS.md"
PLAN_TEMPLATE = os.path.join("docs", "templates", "plan.md")
REPORT_TEMPLATE = os.path.join("docs", "templates", "report.md")
PR_TEMPLATE = os.path.join(".github", "PULL_REQUEST_TEMPLATE.md")
DOCS_INDEX = os.path.join("docs", "README.md")
REGISTRY = "plugins.json"

# The six sections §13.1 requires of every active skill, in order.
REQUIRED_SECTIONS = (
    "When to use",
    "Required reading",
    "Steps",
    "Guardrails",
    "Verification",
    "Definition of done",
)

# The Stage K WordPress skill set (engineering standard §13.1 plus the registry
# skill). These are the domains this repository actually has.
REQUIRED_SKILLS = (
    "wp-add-content-type",
    "wp-add-admin-screen",
    "wp-write-in-process-test",
    "wp-http-acceptance-matrix",
    "wp-release-deploy",
    "wp-update-docs",
    "wp-security-review",
    "wp-frontend-perf",
    "wp-translation-rollout",
    "wp-plugin-registry",
    # Orientation, content writes, test execution and live-site operations.
    # These four own the workflows the ten above assume but none of them
    # defined: orient/plan, change content, run the contract, operate
    # production.
    "wp-repository",
    "wp-content-change",
    "wp-testing",
    "wp-production-operations",
)

# The skill index. It must list every ACTIVE skill and nothing else, so an
# agent can answer "which skill applies?" without reading fourteen files.
SKILL_INDEX = os.path.join(".agents", "skills", "README.md")

# The full standard skill format (engineering standard §13.1). The six
# REQUIRED_SECTIONS above are the governed core and must appear in that order;
# the entries below are the additional sections every active skill carries so a
# skill states its purpose, its boundaries, its authoritative inputs and what
# to do when something goes wrong. A skill that is only a summary is not an
# executable workflow, which is the whole point.
STANDARD_SECTIONS = (
    "Purpose",
    "When to use",
    "When not to use",
    "Required reading",
    "Authoritative sources",
    "Preconditions",
    "Steps",
    "Guardrails",
    "Verification",
    "Failure handling",
    "Evidence and reporting",
    "Definition of done",
)

# Skills that operate a live system or write data must say so explicitly. The
# repository's central safety claim is that agents do not mutate production
# implicitly, and a skill that omits the sentence weakens that claim.
PRODUCTION_SKILLS = (
    "wp-release-deploy",
    "wp-production-operations",
    "wp-content-change",
    "wp-translation-rollout",
)

PRODUCTION_SENTENCE = "no production write"

# Anything whose name says Dart/Flutter/mobile does not belong in the ACTIVE
# WordPress skill namespace. Retired material is relocated, not deleted, and
# lives under .agents/legacy-flutter-skills/ (see that directory's README).
OBSOLETE_SKILL_PREFIXES = ("dart-", "flutter-", "mobile-", "android-", "ios-")
OBSOLETE_SKILL_TOKENS = ("flutter", "dart", "pubspec", "widget tree", "dartdoc")

# A backticked token that looks like a repository path. Deliberately narrow: it
# only matches tokens that begin with a known top-level entry point, so prose
# (function names, flags, URLs, /en/ routes) is never mistaken for a path.
PATH_RE = re.compile(
    r"`((?:AGENTS\.md|README\.md|plugins\.json|compose\.yaml|\.htaccess|"
    r"docs/|scripts/|tests/|wp-content/|content-inventory/|docker/|"
    r"\.github/|\.agents/)[A-Za-z0-9_./\-]*[A-Za-z0-9_])`"
)

PASSED = 0
FAILURES: list[str] = []
NOTES: list[str] = []

# The §9.2 marker. The skill index is a living governance document, so it is
# held to the same last-verified rule as docs/README.md and AGENTS.md.
LAST_VERIFIED_RE = re.compile(r"^_Last verified: \d{4}-\d{2}-\d{2} by .+_$", re.M)


def check(condition: bool, message: str) -> bool:
    """Record one assertion. Never raises."""
    global PASSED
    if condition:
        PASSED += 1
    else:
        FAILURES.append(message)
    return bool(condition)


def note(message: str) -> None:
    """Record a non-asserting observation (context, not a failure)."""
    NOTES.append(message)


def read(path: str) -> str:
    with open(path, encoding="utf-8", errors="replace") as handle:
        return handle.read()


def rel(path: str) -> str:
    return os.path.relpath(path, REPO_ROOT)


def active_skills() -> list[str]:
    """Skill directory names in the active namespace, sorted."""
    if not os.path.isdir(SKILLS_DIR):
        return []
    return sorted(
        name
        for name in os.listdir(SKILLS_DIR)
        if os.path.isdir(os.path.join(SKILLS_DIR, name))
        and not name.startswith(".")
    )


def sections_of(body: str) -> list[str]:
    """The '## ' headings of a Markdown document, in order."""
    return [
        line[3:].strip()
        for line in body.splitlines()
        if line.startswith("## ")
    ]


def report_and_exit() -> None:
    """Print the deterministic summary and exit with the aggregate status."""
    for failure in FAILURES:
        print(f"FAIL: {failure}")
    for observation in NOTES:
        print(f"note: {observation}")
    print(f"Stage K governance verification: {PASSED} passed, {len(FAILURES)} failed")
    sys.exit(1 if FAILURES else 0)


def main() -> int:
    # -- 1. Required governance files exist ---------------------------------
    for relpath, label in (
        (AGENTS_MD, "the agent orientation document"),
        (PLAN_TEMPLATE, "the task plan template"),
        (REPORT_TEMPLATE, "the completion report template"),
        (PR_TEMPLATE, "the pull-request template"),
    ):
        check(
            os.path.isfile(os.path.join(REPO_ROOT, relpath)),
            f"{relpath} is missing ({label}); engineering standard §13.2 requires it",
        )
    check(
        os.path.isfile(os.path.join(REPO_ROOT, REGISTRY)),
        f"{REGISTRY} is missing; it is the single source of plugin registry truth",
    )

    # -- 2. The active skill namespace exists -------------------------------
    if not check(
        os.path.isdir(SKILLS_DIR),
        ".agents/skills/ is missing; the repository MUST maintain WordPress-domain "
        "skills there (engineering standard §13.1)",
    ):
        report_and_exit()

    skills = active_skills()
    check(bool(skills), ".agents/skills/ contains no skill directory")

    # -- 3. Every active skill has a SKILL.md -------------------------------
    for name in skills:
        check(
            os.path.isfile(os.path.join(SKILLS_DIR, name, "SKILL.md")),
            f".agents/skills/{name}/ has no SKILL.md; a skill IS its SKILL.md",
        )

    # -- 4. Every active skill has the six required sections, in order ------
    section_report: list[str] = []
    for name in skills:
        skill_file = os.path.join(SKILLS_DIR, name, "SKILL.md")
        if not os.path.isfile(skill_file):
            continue
        body = read(skill_file)
        found = sections_of(body)
        missing = [s for s in REQUIRED_SECTIONS if s not in found]
        if missing:
            check(
                False,
                f".agents/skills/{name}/SKILL.md is missing required section(s): "
                f"{', '.join(missing)}",
            )
        positions = [found.index(s) for s in REQUIRED_SECTIONS if s in found]
        check(
            positions == sorted(positions),
            f".agents/skills/{name}/SKILL.md has the required sections out of order: "
            f"{' -> '.join(found)}",
        )
        check(
            body.lstrip().startswith("# "),
            f".agents/skills/{name}/SKILL.md must start with a '# <Skill Name>' title",
        )
        section_report.append(f"    {name}: {' -> '.join(found)}")
    note("skill sections:\n" + "\n".join(section_report))

    # -- 5. Every required Stage K skill is present -------------------------
    for name in REQUIRED_SKILLS:
        check(
            os.path.isdir(os.path.join(SKILLS_DIR, name)),
            f"required Stage K skill '{name}' is missing from .agents/skills/",
        )

    # -- 6. The active set has no obsolete Dart/Flutter/mobile skills -------
    for name in skills:
        check(
            not name.startswith(OBSOLETE_SKILL_PREFIXES),
            f".agents/skills/{name}/ is a Dart/Flutter/mobile skill; it does not "
            f"belong in this repository's active WordPress skill namespace "
            f"(retired material belongs under .agents/legacy-flutter-skills/)",
        )
        skill_file = os.path.join(SKILLS_DIR, name, "SKILL.md")
        if not os.path.isfile(skill_file):
            continue
        # A skill MUST state "never touch the Flutter/mobile repository"
        # (engineering standard §13.2), so a mention inside a scope-exclusion
        # sentence is correct, not a violation. Only a mobile token used as
        # actual subject matter (Dart/Flutter instructions) is a failure.
        body = read(skill_file)
        lines = body.splitlines()
        subject_lines = [
            line
            for line in lines
            if any(t in line.lower() for t in OBSOLETE_SKILL_TOKENS)
            and not re.search(
                r"never|not\b|no\b|out of scope|untouched|excluded|freeze",
                line,
                re.IGNORECASE,
            )
        ]
        if subject_lines:
            check(
                False,
                f".agents/skills/{name}/SKILL.md carries mobile-domain instructions "
                f"outside a scope-exclusion sentence: {subject_lines[0].strip()!r}; a "
                f"WordPress skill must not teach Dart/Flutter work",
            )
        else:
            check(
                True,
                f".agents/skills/{name}/SKILL.md mentions mobile only to exclude it",
            )

    # The retired material is relocated, not deleted, and lives OUTSIDE the
    # active namespace. Presence is documented; absence from .agents/skills/ is
    # asserted above.
    if os.path.isdir(LEGACY_DIR):
        readme = os.path.join(LEGACY_DIR, "README.md")
        check(
            os.path.isfile(readme),
            ".agents/legacy-flutter-skills/ has no README.md; relocated material "
            "must be documented so it is not mistaken for active skills",
        )
        if os.path.isfile(readme):
            body = read(readme)
            check(
                "not" in body.lower() and "active" in body.lower(),
                ".agents/legacy-flutter-skills/README.md must state the material is "
                "NOT an active skill set",
            )
        note(
            "retired Flutter/Dart material is present under "
            ".agents/legacy-flutter-skills/ (provenance only, not active)"
        )

    # -- 7. No second plugin registry; no contradiction of plugins.json -----
    registry_slugs: set[str] = set()
    registry_path = os.path.join(REPO_ROOT, REGISTRY)
    if os.path.isfile(registry_path):
        import json

        try:
            data = json.loads(read(registry_path))
            registry_slugs = {
                entry.get("slug", "")
                for entry in data.get("load_order", [])
                if isinstance(entry, dict)
            }
        except (ValueError, AttributeError):
            check(False, f"{REGISTRY} is not valid JSON; the registry gate owns that")

    for candidate in ("plugins-list.json", "registry.json", "plugins.registry.json"):
        check(
            not os.path.isfile(os.path.join(REPO_ROOT, candidate)),
            f"{candidate} exists; {REGISTRY} is the single source of truth for the "
            f"plugin registry (never introduce a second machine-readable registry)",
        )

    for name in skills:
        skill_file = os.path.join(SKILLS_DIR, name, "SKILL.md")
        if not os.path.isfile(skill_file):
            continue
        body = read(skill_file)
        check(
            '"load_order"' not in body,
            f".agents/skills/{name}/SKILL.md declares its own 'load_order' array; "
            f"the plugin registry is {REGISTRY} only",
        )
        # Only PLUGIN slugs are registry entries. The theme slug and PHP class
        # file stems (class-conexao-<plugin>-<concern>.php) are resolved by the
        # path check above, not by the registry.
        theme_slugs = {
            name
            for name in os.listdir(os.path.join(REPO_ROOT, "wp-content", "themes"))
            if os.path.isdir(os.path.join(REPO_ROOT, "wp-content", "themes", name))
        } if os.path.isdir(os.path.join(REPO_ROOT, "wp-content", "themes")) else set()
        for slug in sorted(set(re.findall(r"conexao-[a-z0-9-]+", body))):
            if not registry_slugs or slug in theme_slugs:
                continue
            # A class-file stem such as 'conexao-translation-rollout-engine' is
            # a real class, not a plugin; accept it when a matching plugin
            # directory exists.
            if any(slug.startswith(entry) or entry.startswith(slug) for entry in registry_slugs):
                continue
            check(
                False,
                f".agents/skills/{name}/SKILL.md references plugin slug '{slug}', "
                f"which is not an entry in {REGISTRY}",
            )

    # -- 8. Every path a skill references actually exists -------------------
    # A skill that points at a file which does not exist teaches the next agent
    # to look for something that was never built. Paths are checked with a glob
    # where a skill documents a pattern (e.g. a suite naming convention).
    missing_paths: list[str] = []
    for name in skills:
        skill_file = os.path.join(SKILLS_DIR, name, "SKILL.md")
        if not os.path.isfile(skill_file):
            continue
        body = read(skill_file)
        for token in sorted(set(PATH_RE.findall(body))):
            candidate = token.rstrip("/")
            if "*" in candidate or "?" in candidate:
                import glob as _glob

                # A documented pattern must match at least one real file.
                matches = _glob.glob(os.path.join(REPO_ROOT, candidate))
                if not matches:
                    missing_paths.append(
                        f".agents/skills/{name}/SKILL.md references the pattern "
                        f"'{token}', which matches no file"
                    )
                continue
            if not os.path.exists(os.path.join(REPO_ROOT, candidate)):
                missing_paths.append(
                    f".agents/skills/{name}/SKILL.md references '{token}', "
                    f"which does not exist in this repository"
                )
    for message in missing_paths:
        check(False, message)
    if not missing_paths:
        note("every repository path referenced by an active skill exists")

    # -- 9. AGENTS.md points at the authoritative sources -------------------
    agents_path = os.path.join(REPO_ROOT, AGENTS_MD)
    if os.path.isfile(agents_path):
        agents = read(agents_path)
        for label, needle in (
            ("the engineering standard", "docs/engineering-standard.md"),
            ("the plan template", "docs/templates/plan.md"),
            ("the report template", "docs/templates/report.md"),
            ("the plugin registry", "plugins.json"),
            ("the agent skills", ".agents/skills/"),
            ("the documentation index", "docs/README.md"),
            ("the release contract", "docs/releases.md"),
        ):
            check(
                needle in agents,
                f"{AGENTS_MD} does not point at {label} ({needle}); an agent must "
                f"be able to find the authoritative source from the entry point",
            )
        check(
            "Flutter" in agents and "Never" in agents,
            f"{AGENTS_MD} must state that the Flutter/mobile repository is never "
            f"touched from this repository",
        )
        check(
            "PASS WITH LIMITATION" in agents or "templates/report.md" in agents,
            f"{AGENTS_MD} must point agents at the reporting standard "
            f"(docs/templates/report.md)",
        )
        check(
            len(agents.splitlines()) < 250,
            f"{AGENTS_MD} is {len(agents.splitlines())} lines; it must stay a short "
            f"orientation document (engineering standard §9.2 suggests < ~250 lines)",
        )

    # -- 10. The PR template carries the core engineering-standard checks ----
    pr_path = os.path.join(REPO_ROOT, PR_TEMPLATE)
    if os.path.isfile(pr_path):
        pr = read(pr_path)
        pr_body = pr.lower()
        for label, needle in (
            ("a plan reference", "docs/templates/plan.md"),
            ("the engineering standard", "docs/engineering-standard.md"),
            ("Polylang/English impact", "polylang"),
            ("PT content safety", "pt content"),
            ("the plugin registry as single source of truth", "plugins.json"),
            ("tests with real numbers", "numbers"),
            ("HTTP verification", "http verification"),
            ("production changes", "production changes"),
            ("rollback", "rollback"),
            ("the mobile repository exclusion", "flutter"),
        ):
            check(
                needle.lower() in pr_body,
                f"{PR_TEMPLATE} is missing the {label} section/check ('{needle}'); "
                f"the PR template must reflect the engineering standard",
            )
        # Each required confirmation must exist as an actual '- [ ]' checklist
        # item. Checked against the raw text so that a needle wrapped in
        # backticks ('`AGENTS.md`') still matches.
        checklist_items = [
            line for line in pr.splitlines() if line.strip().startswith("- [ ]")
        ]
        checklist = "\n".join(checklist_items).lower()
        for checkbox in (
            "agents.md",
            "plan",
            "pt content",
            "polylang",
            "plugins.json",
            "source of truth",
            "tests",
            "http acceptance",
            "documentation",
            "secrets",
            "production",
            "rollback",
            "flutter",
        ):
            check(
                checkbox in checklist,
                f"{PR_TEMPLATE} checklist is missing a '- [ ]' item covering "
                f"'{checkbox}'",
            )
        check(
            len(checklist_items) >= 10,
            f"{PR_TEMPLATE} has only {len(checklist_items)} checklist items; the "
            f"engineering-standard confirmations must each be an explicit item",
        )

    # -- 11. The templates carry the sections the standard requires ---------
    plan_path = os.path.join(REPO_ROOT, PLAN_TEMPLATE)
    if os.path.isfile(plan_path):
        plan = read(plan_path).lower()
        for label, needle in (
            ("explicit non-goals", "non-goal"),
            ("the repository baseline SHA", "sha"),
            ("files expected to change", "files expected to change"),
            ("files expected NOT to change", "not to change"),
            ("content/data impact", "content / data impact"),
            ("route/HTTP impact", "route / http impact"),
            ("Polylang/English impact", "polylang / english impact"),
            ("security impact", "security impact"),
            ("performance impact", "performance impact"),
            ("production impact", "production impact"),
            ("the test plan", "test plan"),
            ("the acceptance matrix plan", "acceptance matrix plan"),
            ("the rollback plan", "rollback plan"),
            ("the documentation plan", "documentation plan"),
            ("release implications", "release implications"),
            ("risks", "risks"),
            ("verification gates", "verification gates"),
            ("completion criteria", "completion criteria"),
        ):
            check(
                needle in plan,
                f"{PLAN_TEMPLATE} does not cover the {label} the engineering "
                f"standard requires ('{needle}')",
            )
        check(
            "template" in plan.split("\n\n")[0].lower()
            or "copy it" in plan.lower(),
            f"{PLAN_TEMPLATE} must open by telling the reader it is a template to be "
            f"copied, not edited in place",
        )

    report_path = os.path.join(REPO_ROOT, REPORT_TEMPLATE)
    if os.path.isfile(report_path):
        rep = read(report_path)
        rep_body = rep.lower()
        for label, needle in (
            ("the start SHA", "start sha"),
            ("the final SHA", "final sha"),
            ("the working-tree status", "working tree"),
            ("scope not completed", "not completed"),
            ("the runtime impact", "runtime impact"),
            ("the content/data impact", "content / data impact"),
            ("the Polylang impact", "polylang impact"),
            ("the route/HTTP impact", "route / http impact"),
            ("production actions", "production actions"),
            ("the verification commands", "verification commands"),
            ("numeric test results", "numeric test results"),
            ("HTTP assertion results", "http acceptance"),
            ("static-analysis results", "static analysis"),
            ("script-contract results", "script-contract results"),
            ("release/build results", "release / build results"),
            ("failure proofs / negative tests", "failure proofs"),
            ("the regression comparison", "regression comparison"),
            ("known pre-existing failures", "known pre-existing failures"),
            ("limitations", "limitations"),
            ("the evidence paths", "evidence paths"),
            ("the documentation updated", "documentation updated"),
            ("the rollback/recovery", "rollback / recovery"),
        ):
            check(
                needle in rep_body,
                f"{REPORT_TEMPLATE} does not record the {label} the engineering "
                f"standard requires ('{needle}')",
            )
        # The four permitted statuses must all be defined, and the template must
        # warn against an unsupported "fully verified" claim.
        for status in ("PASS", "PASS WITH LIMITATION", "BLOCKED", "NOT TESTABLE"):
            check(
                f"`{status}`" in rep,
                f"{REPORT_TEMPLATE} does not define the '{status}' final status",
            )
        check(
            "fully verified" in rep_body,
            f"{REPORT_TEMPLATE} must explicitly warn against an unsupported "
            f"'fully verified' claim when a verifier was unavailable",
        )
        check(
            "blocked" in rep_body and "not tested" in rep_body,
            f"{REPORT_TEMPLATE} must distinguish blocked/unavailable from "
            f"not-tested results",
        )

    # -- 12. The documentation index exposes the governance workflow ---------
    docs_index = os.path.join(REPO_ROOT, DOCS_INDEX)
    if os.path.isfile(docs_index):
        index = read(docs_index)
        for label, needle in (
            ("the plan template", "templates/plan.md"),
            ("the report template", "templates/report.md"),
            ("the agent skills", ".agents/skills"),
            ("the engineering standard", "engineering-standard.md"),
            ("the evidence directory rule", "evidence"),
            ("the script catalogue", "../scripts/README.md"),
            ("the release contract", "releases.md"),
        ):
            check(
                needle in index,
                f"{DOCS_INDEX} does not link {label} ('{needle}'); the new "
                f"governance workflow must be discoverable from the index",
            )

    # -- 13. No localhost URLs, credentials or external paths in the
    #        governance surface --------------------------------------------
    # The governance layer is documentation: it must not smuggle in a local
    # URL, a credential literal or a path outside this repository.
    governance_files = [AGENTS_MD, PLAN_TEMPLATE, REPORT_TEMPLATE, PR_TEMPLATE]
    for name in skills:
        governance_files.append(
            os.path.join(".agents", "skills", name, "SKILL.md")
        )
    if os.path.isfile(docs_index):
        governance_files.append(DOCS_INDEX)

    localhost_re = re.compile(r"https?://(?:localhost|127\.0\.0\.1)(?::\d+)?")
    external_path_re = re.compile(r"(?:^|[\s`'\"(])/(?:home|Users|var/folders|mnt)/")
    credential_res = (
        re.compile(r"WP_APPLICATION_PASSWORD\s*=\s*['\"][^'\"]+['\"]"),
        re.compile(r"-----BEGIN [A-Z ]*PRIVATE KEY-----"),
    )
    for relpath in governance_files:
        abs_path = os.path.join(REPO_ROOT, relpath)
        if not os.path.isfile(abs_path):
            continue
        body = read(abs_path)
        match = localhost_re.search(body)
        check(
            match is None,
            f"{relpath} contains a localhost URL"
            + (f" at offset {match.start()}" if match else "")
            + "; a governance document must not hard-code a local target",
        )
        match = external_path_re.search(body)
        check(
            match is None,
            f"{relpath} references an external filesystem path"
            + (f" at offset {match.start()}" if match else "")
            + "; governance documents must reference repository-relative paths only",
        )
        for pattern in credential_res:
            check(
                pattern.search(body) is None,
                f"{relpath} contains a credential literal; credentials come from the "
                f"environment only",
            )

    # -- 14. The governance surface is documentation-only -------------------
    # Stage K is behaviour-neutral: the governance layer must not introduce any
    # runtime file under wp-content/. A skill that pointed a maintainer at a
    # runtime file it also created would be a behaviour change in disguise, so
    # the check is on the governance tree itself.
    for relpath in governance_files:
        check(
            not relpath.replace("\\", "/").startswith("wp-content/"),
            f"{relpath} is inside wp-content/; the agent-governance surface is "
            f"documentation and must not change runtime files",
        )
    check(
        not os.path.isfile(os.path.join(REPO_ROOT, "skills-lock.json")),
        "skills-lock.json still exists at the repository root; the retired "
        "Flutter/Dart lock file must not remain an active root-level file "
        "(engineering standard §13.1 requires no lock model)",
    )

    # -- 15. The verifier is discoverable by the canonical runner -----------
    # The Stage I runner discovers tests/scripts/verify-*.py by convention; the
    # standard's own gate must not need a hardcoded list (Stage E).
    check(
        os.path.basename(os.path.abspath(__file__)).startswith("verify-"),
        "this gate is not named verify-*.py, so scripts/run-tests.sh will not "
        "discover it",
    )
    # -- 16. Every active skill uses the full standard skill format ----------
    # The six REQUIRED_SECTIONS are the governed core; STANDARD_SECTIONS is the
    # format a skill must follow so it states its purpose, its boundaries, its
    # authoritative inputs, and what to do when a step fails. A skill that is
    # only a summary is not an executable workflow, which is the whole point.
    for name in skills:
        skill_file = os.path.join(SKILLS_DIR, name, "SKILL.md")
        if not os.path.isfile(skill_file):
            continue
        found = sections_of(read(skill_file))
        missing = [s for s in STANDARD_SECTIONS if s not in found]
        check(
            not missing,
            f".agents/skills/{name}/SKILL.md is missing standard section(s): "
            f"{', '.join(missing)}; a skill is an executable workflow, not a "
            f"summary, and must state its purpose, boundaries, authoritative "
            f"inputs and failure handling",
        )
        # A duplicated heading means two sections collided during an edit and
        # one of them silently lost its body. That is a real editing defect, not
        # a cosmetic one.
        duplicates = sorted({s for s in found if found.count(s) > 1})
        check(
            not duplicates,
            f".agents/skills/{name}/SKILL.md repeats section heading(s): "
            f"{', '.join(duplicates)}; a duplicated heading means one of the two "
            f"sections has no body",
        )

    # -- 17. Production-facing skills carry the safety sentence -----------
    # The repository's central safety claim is that an agent never mutates
    # production implicitly. A skill that operates a live system or writes data
    # must state that sentence, or the claim is weaker than the documentation
    # says it is.
    for name in PRODUCTION_SKILLS:
        skill_file = os.path.join(SKILLS_DIR, name, "SKILL.md")
        if not os.path.isfile(skill_file):
            continue
        body = read(skill_file).lower()
        check(
            PRODUCTION_SENTENCE in body,
            f".agents/skills/{name}/SKILL.md does not state '{PRODUCTION_SENTENCE}'; "
            f"a skill that operates a live system or writes data must say "
            f"explicitly that no production write occurred",
        )

    # -- 18. No dangling skill-to-skill reference --------------------------
    # A skill that tells the next agent to use a skill that does not exist sends
    # them to a directory with no workflow in it. This is exactly the failure
    # the migration was commissioned to remove, so it is enforced, not trusted.
    # Plugin slugs are not skills, and `wp-content` is the top-level WordPress
    # directory; the check only fires for a name that is neither. The directory
    # is matched EXACTLY, not by prefix: a prefix test would also swallow
    # `wp-content-rollout`, which is precisely the dangling reference this check
    # exists to catch.
    known_skills = set(skills)
    not_a_skill = set(registry_slugs) | {"wp-content"}
    for name in skills:
        skill_file = os.path.join(SKILLS_DIR, name, "SKILL.md")
        if not os.path.isfile(skill_file):
            continue
        body = read(skill_file)
        for referenced in sorted(set(re.findall(r"`(wp-[a-z0-9-]+)`", body))):
            if referenced in known_skills or referenced in not_a_skill:
                continue
            check(
                False,
                f".agents/skills/{name}/SKILL.md references '{referenced}', which is "
                f"neither an active skill nor a plugin in {REGISTRY}; a dangling "
                f"skill reference sends the next agent to a workflow that does "
                f"not exist",
            )

    # -- 19. No active skill points at the external mobile repository ------
    # The Flutter/mobile repository is permanently out of scope. A skill must
    # not send an agent there to do work, even though a skill may *mention* the
    # exclusion. This distinguishes "never touch it" from "go and change it".
    mobile_work_re = re.compile(
        r"(?:clone|cd|edit|modify|run|deploy)\s+"
        r"(?:in\s+|inside\s+|the\s+)?(?:the\s+)?"
        r"(?:flutter|mobile|dart)[\w./-]*(?:repo|repository|app|project)",
        re.IGNORECASE,
    )
    for name in skills:
        skill_file = os.path.join(SKILLS_DIR, name, "SKILL.md")
        if not os.path.isfile(skill_file):
            continue
        match = mobile_work_re.search(read(skill_file))
        check(
            match is None,
            f".agents/skills/{name}/SKILL.md appears to direct work at the "
            f"external Flutter/mobile repository"
            + (f" ({match.group(0)!r})" if match else "")
            + f"; that repository is permanently out of scope from this one",
        )

    # -- 20. The skill index matches the active skill set -------------------
    # An index that drifts from the directory is worse than no index: an agent
    # trusts it and picks a skill that no longer exists. Both directions are
    # checked — a missing skill and a stale entry.
    index_path = os.path.join(REPO_ROOT, SKILL_INDEX)
    check(
        os.path.isfile(index_path),
        f"{SKILL_INDEX} is missing; an agent must be able to answer 'which skill "
        f"applies to this task?' from a single index",
    )
    if os.path.isfile(index_path):
        index = read(index_path)
        # Only the index table's own links are parsed, so a prose mention of a
        # skill name in another context cannot mask a missing row.
        linked = set(re.findall(r"\]\(([a-z0-9-]+)/SKILL\.md\)", index))
        for name in sorted(set(skills)):
            check(
                name in linked,
                f"{SKILL_INDEX} does not list the active skill '{name}'; the index "
                f"has drifted from .agents/skills/ and an agent would not find it",
            )
        for name in sorted(linked - set(skills)):
            check(
                False,
                f"{SKILL_INDEX} lists '{name}', which is not an active skill; remove "
                f"the stale entry so the index matches .agents/skills/",
            )
        check(
            LAST_VERIFIED_RE.search(index) is not None,
            f"{SKILL_INDEX} does not carry its _Last verified_ line "
            f"(engineering standard §9.2)",
        )

    report_and_exit()
    return 0  # unreachable; report_and_exit() exits


if __name__ == "__main__":
    main()