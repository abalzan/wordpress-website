#!/usr/bin/env python3
"""
validate-version-bumps.py — CI validation for component version bumps.

This script validates that:
1. Every changed component has a valid SemVer increment
2. Version constants are synchronized with headers
3. No unrelated components were bumped
4. No version downgrades or invalid increments
5. Documentation-only and test-only changes don't require version bumps

Usage:
    python3 scripts/validate-version-bumps.py --base-ref <base-branch> [--head-ref <head-branch>]
    python3 scripts/validate-version-bumps.py --help
"""

from __future__ import annotations

import argparse
import json
import os
import re
import subprocess
import sys
from dataclasses import dataclass
from pathlib import Path
from typing import Optional

REPO_ROOT = Path(os.environ.get("CONEXAO_REPO_ROOT") or Path(__file__).parent.parent).resolve()
PLUGINS_JSON = REPO_ROOT / "plugins.json"
THEME_SLUG = "conexao-br-irlanda"
THEME_STYLE_CSS = REPO_ROOT / "wp-content" / "themes" / THEME_SLUG / "style.css"
THEME_FUNCTIONS_PHP = REPO_ROOT / "wp-content" / "themes" / THEME_SLUG / "functions.php"

# Regex to match WordPress header Version: field
VERSION_HEADER_RE = re.compile(
    r"^\s*\*?\s*Version:\s*(?P<version>[0-9A-Za-z][0-9A-Za-z.\-+]*)\s*$",
    re.MULTILINE
)

# Regex to match PHP version constants (any constant ending with _VERSION)
VERSION_CONSTANT_RE = re.compile(
    r"(define\s*\(\s*['\"])(?P<const_name>[A-Z_]+_VERSION)(['\"]\s*,\s*['\"])(?P<version>[0-9A-Za-z][0-9A-Za-z.\-+]*)(['\"]\s*\))",
    re.MULTILINE
)

# Theme version constant
THEME_VERSION_CONSTANT_RE = re.compile(
    r"define\s*\(\s*['\"]CONEXAO_THEME_VERSION['\"]\s*,\s*['\"](?P<version>[0-9A-Za-z][0-9A-Za-z.\-+]*)['\"]\s*\)",
    re.MULTILINE
)

# Files that don't require version bumps when changed
NON_VERSION_FILES = {
    # Documentation
    "docs/",
    "README.md",
    "CHANGELOG.md",
    # Tests
    "tests/",
    # Scripts (unless they're the version bump script itself)
    "scripts/bump-component-version.py",
    "scripts/validate-version-bumps.py",
    # Configuration
    ".github/",
    "compose.yaml",
    "composer.json",
    "composer.lock",
    "phpcs.xml.dist",
    "phpstan.neon.dist",
    "phpcs-baseline.json",
    "phpstan-baseline.neon",
    # Generated files
    "dist/",
    # Lock files
    "package-lock.json",
    "yarn.lock",
}


@dataclass
class Component:
    slug: str
    type: str  # 'plugin' or 'theme'
    version_source: str
    production: bool
    build: bool
    class_: str
    status: str


@dataclass
class VersionInfo:
    slug: str
    type: str
    header_version: str
    constant_version: Optional[str]
    constant_name: Optional[str]
    source_file: str


def load_registry() -> dict:
    """Load and validate plugins.json."""
    if not PLUGINS_JSON.exists():
        raise RuntimeError(f"Plugin registry not found: {PLUGINS_JSON}")
    with open(PLUGINS_JSON, encoding="utf-8") as f:
        data = json.load(f)
    if not isinstance(data, dict) or not isinstance(data.get("load_order"), list):
        raise RuntimeError("plugins.json must contain a 'load_order' array")
    return data


def get_all_components() -> list[Component]:
    """Get all active components from registry."""
    registry = load_registry()
    components = []
    
    for entry in registry["load_order"]:
        if entry.get("status") == "active":
            components.append(Component(
                slug=entry["slug"],
                type="plugin",
                version_source=entry.get("version_source", ""),
                production=entry.get("production", False),
                build=entry.get("build", False),
                class_=entry.get("class", "unknown"),
                status=entry.get("status", "unknown"),
            ))
    
    # Add theme (hardcoded single active theme)
    components.append(Component(
        slug=THEME_SLUG,
        type="theme",
        version_source=str(THEME_STYLE_CSS.relative_to(REPO_ROOT)),
        production=True,
        build=True,
        class_="platform",
        status="active",
    ))
    
    return components


def get_theme_component(theme_slug: str) -> Optional[Component]:
    """Get theme component by slug."""
    if theme_slug == THEME_SLUG:
        return Component(
            slug=THEME_SLUG,
            type="theme",
            version_source=str(THEME_STYLE_CSS.relative_to(REPO_ROOT)),
            production=True,
            build=True,
            class_="platform",
            status="active",
        )
    return None


def get_component_by_slug(slug: str) -> Optional[Component]:
    """Get component by slug."""
    for comp in get_all_components():
        if comp.slug == slug:
            return comp
    return None


def parse_version(version_str: str) -> tuple[int, int, int]:
    """Parse a semantic version string into (major, minor, patch)."""
    parts = version_str.split(".")
    if len(parts) != 3:
        raise ValueError(f"Invalid version format: {version_str} (expected MAJOR.MINOR.PATCH)")
    try:
        return (int(parts[0]), int(parts[1]), int(parts[2]))
    except ValueError:
        raise ValueError(f"Invalid version numbers in: {version_str}")


def compare_versions(v1: str, v2: str) -> int:
    """Compare two version strings. Returns -1 if v1 < v2, 0 if equal, 1 if v1 > v2."""
    p1 = parse_version(v1)
    p2 = parse_version(v2)
    if p1 < p2:
        return -1
    elif p1 > p2:
        return 1
    return 0


def get_version_increment_type(old_version: str, new_version: str) -> Optional[str]:
    """Determine the type of version increment (patch, minor, major) or None if invalid."""
    try:
        old_major, old_minor, old_patch = parse_version(old_version)
        new_major, new_minor, new_patch = parse_version(new_version)
    except ValueError:
        return None
    
    if new_major > old_major:
        if new_minor == 0 and new_patch == 0:
            return "major"
        return None  # Invalid major bump (minor/patch not reset)
    elif new_minor > old_minor:
        if new_major == old_major and new_patch == 0:
            return "minor"
        return None  # Invalid minor bump (patch not reset)
    elif new_patch > old_patch:
        if new_major == old_major and new_minor == old_minor:
            return "patch"
        return None  # Invalid patch bump (major/minor changed)
    elif new_major == old_major and new_minor == old_minor and new_patch == old_patch:
        return "none"  # No change
    else:
        return "downgrade"  # Version decreased


def read_version_from_file(file_path: Path) -> Optional[str]:
    """Read version from a file's WordPress header."""
    if not file_path.exists():
        return None
    content = file_path.read_text(encoding="utf-8", errors="replace")
    match = VERSION_HEADER_RE.search(content)
    return match.group("version") if match else None


def read_constant_version(file_path: Path, constant_name: str) -> Optional[str]:
    """Read version from a PHP constant definition."""
    if not file_path.exists():
        return None
    content = file_path.read_text(encoding="utf-8", errors="replace")
    
    pattern = re.compile(
        rf"define\s*\(\s*['\"]{re.escape(constant_name)}['\"]\s*,\s*['\"](?P<version>[0-9A-Za-z][0-9A-Za-z.\-+]*)['\"]\s*\)",
        re.MULTILINE
    )
    match = pattern.search(content)
    
    return match.group("version") if match else None


def detect_version_constant(component: Component) -> Optional[str]:
    """Detect the version constant name from the plugin's main file or theme's functions.php."""
    if component.type == "theme":
        # For theme, check functions.php
        theme_dir = (REPO_ROOT / component.version_source).parent
        theme_functions = theme_dir / "functions.php"
        if theme_functions.exists():
            content = theme_functions.read_text(encoding="utf-8", errors="replace")
            match = VERSION_CONSTANT_RE.search(content)
            if match:
                return match.group("const_name")
        return "CONEXAO_THEME_VERSION"  # fallback
    
    source_path = REPO_ROOT / component.version_source
    if not source_path.exists():
        return None
    
    content = source_path.read_text(encoding="utf-8", errors="replace")
    match = VERSION_CONSTANT_RE.search(content)
    
    if match:
        return match.group("const_name")
    
    return None


def get_component_version_info(component: Component, ref: str = "HEAD") -> VersionInfo:
    """Get version info for a component at a specific git ref."""
    source_path = REPO_ROOT / component.version_source
    
    # Read header version
    if ref == "HEAD":
        header_version = read_version_from_file(source_path)
    else:
        try:
            content = subprocess.run(
                ["git", "show", f"{ref}:{component.version_source}"],
                cwd=REPO_ROOT, capture_output=True, text=True, check=True
            ).stdout
            match = VERSION_HEADER_RE.search(content)
            header_version = match.group("version") if match else None
        except subprocess.CalledProcessError:
            header_version = None
    
    # Read constant version
    constant_name = detect_version_constant(component)
    constant_version = None
    
    if constant_name:
        if component.type == "theme":
            const_file = (REPO_ROOT / component.version_source).parent / "functions.php"
        else:
            const_file = source_path
        
        if ref == "HEAD":
            constant_version = read_constant_version(const_file, constant_name)
        else:
            try:
                content = subprocess.run(
                    ["git", "show", f"{ref}:{const_file.relative_to(REPO_ROOT)}"],
                    cwd=REPO_ROOT, capture_output=True, text=True, check=True
                ).stdout
                pattern = re.compile(
                    rf"define\s*\(\s*['\"]{re.escape(constant_name)}['\"]\s*,\s*['\"](?P<version>[0-9A-Za-z][0-9A-Za-z.\-+]*)['\"]\s*\)",
                    re.MULTILINE
                )
                match = pattern.search(content)
                constant_version = match.group("version") if match else None
            except subprocess.CalledProcessError:
                constant_version = None
    
    return VersionInfo(
        slug=component.slug,
        type=component.type,
        header_version=header_version or "0.0.0",
        constant_version=constant_version,
        constant_name=constant_name,
        source_file=component.version_source,
    )


def get_changed_files(base_ref: str, head_ref: str = "HEAD") -> list[str]:
    """Get list of files changed between base and head refs."""
    try:
        result = subprocess.run(
            ["git", "diff", "--name-only", base_ref, head_ref],
            cwd=REPO_ROOT, capture_output=True, text=True, check=True
        )
        return [f.strip() for f in result.stdout.splitlines() if f.strip()]
    except subprocess.CalledProcessError as e:
        print(f"ERROR: Failed to get changed files: {e.stderr}", file=sys.stderr)
        return []


def is_version_related_file(file_path: str) -> bool:
    """Check if a file is version-related (source file or constant file)."""
    # Plugin main files
    if file_path.startswith("wp-content/plugins/") and file_path.endswith(".php"):
        plugin_slug = file_path.split("/")[2]
        if plugin_slug == os.path.basename(file_path).replace(".php", ""):
            return True
    
    # Theme files (any theme)
    if file_path.startswith("wp-content/themes/") and file_path.endswith((".css", ".php")):
        parts = file_path.split("/")
        if len(parts) >= 4:
            theme_slug = parts[2]
            filename = parts[3]
            if filename in ["style.css", "functions.php"]:
                return True
    
    return False


def is_non_version_change(file_path: str) -> bool:
    """Check if a file change should not require a version bump."""
    for pattern in NON_VERSION_FILES:
        if file_path.startswith(pattern) or file_path == pattern:
            return True
    return False


def get_changed_components(base_ref: str, head_ref: str = "HEAD") -> set[str]:
    """Determine which components have changed based on file changes."""
    changed_files = get_changed_files(base_ref, head_ref)
    changed_components = set()
    
    for file_path in changed_files:
        # Skip non-version files
        if is_non_version_change(file_path):
            continue
        
        # Check if it's a plugin main file
        if file_path.startswith("wp-content/plugins/"):
            parts = file_path.split("/")
            if len(parts) >= 3:
                plugin_slug = parts[2]
                # Check if it's the main plugin file
                main_file = f"{plugin_slug}.php"
                if os.path.basename(file_path) == main_file:
                    changed_components.add(plugin_slug)
        
        # Check theme files (any theme)
        elif file_path.startswith("wp-content/themes/") and file_path.endswith((".css", ".php")):
            parts = file_path.split("/")
            if len(parts) >= 4:
                theme_slug = parts[2]
                filename = parts[3]
                if filename in ["style.css", "functions.php"]:
                    theme_comp = get_theme_component(theme_slug)
                    if theme_comp:
                        changed_components.add(theme_comp.slug)
    
    return changed_components


def validate_version_bump(component: Component, base_ref: str, head_ref: str = "HEAD", changed_components: set[str] = None) -> list[str]:
    """Validate version bump for a single component. Returns list of errors."""
    errors = []
    
    # Get versions at base and head
    base_info = get_component_version_info(component, base_ref)
    head_info = get_component_version_info(component, head_ref)
    
    # Check if this component's source files were modified
    component_changed = changed_components and component.slug in changed_components
    
    # Check if header version changed
    if base_info.header_version != head_info.header_version:
        increment_type = get_version_increment_type(base_info.header_version, head_info.header_version)
        
        if increment_type is None:
            errors.append(
                f"{component.slug}: Invalid version increment from {base_info.header_version} "
                f"to {head_info.header_version} (not a valid SemVer patch/minor/major bump)"
            )
        elif increment_type == "downgrade":
            errors.append(
                f"{component.slug}: Version downgrade detected: {base_info.header_version} "
                f"→ {head_info.header_version}"
            )
        elif increment_type == "none":
            errors.append(
                f"{component.slug}: Header version unchanged but file was modified"
            )
        # Valid increment types (patch, minor, major) are OK
    elif component_changed:
        # Source files were modified but version wasn't bumped
        errors.append(
            f"{component.slug}: Source files modified but version not bumped "
            f"(current: {head_info.header_version})"
        )
    
    # Check constant synchronization
    if head_info.constant_name and head_info.constant_version:
        if head_info.header_version != head_info.constant_version:
            errors.append(
                f"{component.slug}: Version constant {head_info.constant_name} "
                f"({head_info.constant_version}) does not match header "
                f"({head_info.header_version})"
            )
    
    # Check base constant synchronization (if it existed)
    if base_info.constant_name and base_info.constant_version:
        if base_info.header_version != base_info.constant_version:
            errors.append(
                f"{component.slug}: Base version constant {base_info.constant_name} "
                f"({base_info.constant_version}) did not match base header "
                f"({base_info.header_version})"
            )
    
    return errors


def validate_no_unrelated_bumps(changed_components: set[str], base_ref: str, head_ref: str = "HEAD") -> list[str]:
    """Check that no unrelated components were bumped."""
    errors = []
    all_components = get_all_components()
    
    for component in all_components:
        if component.slug in changed_components:
            continue  # This component changed, version bump expected
        
        # Check if this component's version was bumped anyway
        base_info = get_component_version_info(component, base_ref)
        head_info = get_component_version_info(component, head_ref)
        
        if base_info.header_version != head_info.header_version:
            errors.append(
                f"{component.slug}: Version changed from {base_info.header_version} "
                f"to {head_info.header_version} but no source files were modified"
            )
    
    return errors


def main():
    parser = argparse.ArgumentParser(
        description="Validate component version bumps in CI",
        formatter_class=argparse.RawDescriptionHelpFormatter,
        epilog="""
Examples:
  # Validate against main branch
  python3 scripts/validate-version-bumps.py --base-ref main

  # Validate against specific base commit
  python3 scripts/validate-version-bumps.py --base-ref abc1234

  # Validate with explicit head ref
  python3 scripts/validate-version-bumps.py --base-ref main --head-ref feature-branch
        """
    )
    
    parser.add_argument(
        "--base-ref", "-b",
        required=True,
        help="Base git ref to compare against (e.g., main, origin/main, commit SHA)"
    )
    parser.add_argument(
        "--head-ref", "-H",
        default="HEAD",
        help="Head git ref (default: HEAD)"
    )
    parser.add_argument(
        "--list-changed",
        action="store_true",
        help="List changed components and exit"
    )
    
    args = parser.parse_args()
    
    print(f"Validating version bumps: {args.base_ref}..{args.head_ref}")
    print("=" * 80)
    
    # Get changed components
    changed_components = get_changed_components(args.base_ref, args.head_ref)
    
    print(f"Changed components (source files modified): {', '.join(sorted(changed_components)) if changed_components else 'none'}")
    
    if args.list_changed:
        return 0
    
    all_errors = []
    
    # Validate each changed component
    for slug in sorted(changed_components):
        component = get_component_by_slug(slug)
        if not component:
            all_errors.append(f"Unknown component: {slug}")
            continue
        
        print(f"\nValidating {component.slug} ({component.type})...")
        errors = validate_version_bump(component, args.base_ref, args.head_ref, changed_components)
        
        if errors:
            for error in errors:
                print(f"  ✗ {error}")
            all_errors.extend(errors)
        else:
            base_info = get_component_version_info(component, args.base_ref)
            head_info = get_component_version_info(component, args.head_ref)
            if base_info.header_version != head_info.header_version:
                increment_type = get_version_increment_type(base_info.header_version, head_info.header_version)
                print(f"  ✓ Valid {increment_type} bump: {base_info.header_version} → {head_info.header_version}")
                if head_info.constant_name:
                    print(f"  ✓ Constant {head_info.constant_name} synchronized")
            else:
                print(f"  ✓ No version change (source files modified but version unchanged)")
    
    # Check for unrelated bumps
    print("\nChecking for unrelated version bumps...")
    unrelated_errors = validate_no_unrelated_bumps(changed_components, args.base_ref, args.head_ref)
    for error in unrelated_errors:
        print(f"  ✗ {error}")
    all_errors.extend(unrelated_errors)
    
    if not unrelated_errors:
        print("  ✓ No unrelated version bumps detected")
    
    # Summary
    print("\n" + "=" * 80)
    if all_errors:
        print(f"VALIDATION FAILED: {len(all_errors)} error(s)")
        return 1
    else:
        print("VALIDATION PASSED: All version bumps are valid")
        return 0


if __name__ == "__main__":
    sys.exit(main())