#!/usr/bin/env python3
"""
bump-component-version.py — Safe, controlled version bumping for plugins and theme.

This script implements the versioning policy:
- Semantic Versioning (MAJOR.MINOR.PATCH)
- PATCH is the normal default for bug fixes and routine improvements
- Each component has its own independent version
- Only increment components that actually changed
- Documentation-only and test-only changes do not force version bumps
- Version constants are kept synchronized with the authoritative header

Usage:
    python3 scripts/bump-component-version.py --component <slug> --level patch|minor|major [--dry-run]
    python3 scripts/bump-component-version.py --component <slug1> --component <slug2> --level patch [--dry-run]
    python3 scripts/bump-component-version.py --list-components
    python3 scripts/bump-component-version.py --help
"""

from __future__ import annotations

import argparse
import json
import os
import re
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
    r"^(\s*\*?\s*Version:\s*)(?P<version>[0-9A-Za-z][0-9A-Za-z.\-+]*)(\s*)$",
    re.MULTILINE
)

# Regex to match PHP version constants (any constant ending with _VERSION)
VERSION_CONSTANT_RE = re.compile(
    r"(define\s*\(\s*['\"])(?P<const_name>[A-Z_]+_VERSION)(['\"]\s*,\s*['\"])(?P<version>[0-9A-Za-z][0-9A-Za-z.\-+]*)(['\"]\s*\))",
    re.MULTILINE
)

# Theme version constant in functions.php
THEME_VERSION_CONSTANT_RE = re.compile(
    r"(define\s*\(\s*['\"]CONEXAO_THEME_VERSION['\"]\s*,\s*['\"])(?P<version>[0-9A-Za-z][0-9A-Za-z.\-+]*)(['\"]\s*\))",
    re.MULTILINE
)


@dataclass
class Component:
    slug: str
    type: str  # 'plugin' or 'theme'
    version_source: str
    version_constant: Optional[str]
    production: bool
    build: bool
    class_: str
    status: str


def load_registry() -> dict:
    """Load and validate plugins.json."""
    if not PLUGINS_JSON.exists():
        raise RuntimeError(f"Plugin registry not found: {PLUGINS_JSON}")
    with open(PLUGINS_JSON, encoding="utf-8") as f:
        data = json.load(f)
    if not isinstance(data, dict) or not isinstance(data.get("load_order"), list):
        raise RuntimeError("plugins.json must contain a 'load_order' array")
    return data


def get_component(slug: str) -> Component:
    """Get component metadata from registry."""
    registry = load_registry()
    
    # Check plugins
    for entry in registry["load_order"]:
        if entry.get("slug") == slug:
            if entry.get("status") == "retired":
                raise RuntimeError(f"Component '{slug}' is retired and cannot be versioned")
            
            # Check if it's a theme (version_source points to style.css)
            version_source = entry.get("version_source", "")
            is_theme = version_source.endswith("style.css")
            
            if is_theme:
                return Component(
                    slug=slug,
                    type="theme",
                    version_source=version_source,
                    version_constant=None,  # Will be detected from file
                    production=entry.get("production", False),
                    build=entry.get("build", False),
                    class_=entry.get("class", "unknown"),
                    status=entry.get("status", "unknown"),
                )
            else:
                return Component(
                    slug=slug,
                    type="plugin",
                    version_source=version_source,
                    version_constant=None,  # Will be detected from file
                    production=entry.get("production", False),
                    build=entry.get("build", False),
                    class_=entry.get("class", "unknown"),
                    status=entry.get("status", "unknown"),
                )
    
    # Check hardcoded theme (backward compatibility)
    if slug == THEME_SLUG:
        return Component(
            slug=slug,
            type="theme",
            version_source=str(THEME_STYLE_CSS.relative_to(REPO_ROOT)),
            version_constant="CONEXAO_THEME_VERSION",
            production=True,
            build=True,
            class_="platform",
            status="active",
        )
    
    raise RuntimeError(f"Component '{slug}' not found in registry")


def list_components() -> list[Component]:
    """List all active components from registry."""
    registry = load_registry()
    components = []
    
    for entry in registry["load_order"]:
        if entry.get("status") == "active":
            components.append(Component(
                slug=entry["slug"],
                type="plugin",
                version_source=entry.get("version_source", ""),
                version_constant=None,
                production=entry.get("production", False),
                build=entry.get("build", False),
                class_=entry.get("class", "unknown"),
                status=entry.get("status", "unknown"),
            ))
    
    # Add theme
    components.append(Component(
        slug=THEME_SLUG,
        type="theme",
        version_source=str(THEME_STYLE_CSS.relative_to(REPO_ROOT)),
        version_constant="CONEXAO_THEME_VERSION",
        production=True,
        build=True,
        class_="platform",
        status="active",
    ))
    
    return components


def parse_version(version_str: str) -> tuple[int, int, int]:
    """Parse a semantic version string into (major, minor, patch)."""
    # Handle versions like "1.0.0", "1.2.3", "0.6.0", etc.
    parts = version_str.split(".")
    if len(parts) != 3:
        raise ValueError(f"Invalid version format: {version_str} (expected MAJOR.MINOR.PATCH)")
    try:
        return (int(parts[0]), int(parts[1]), int(parts[2]))
    except ValueError:
        raise ValueError(f"Invalid version numbers in: {version_str}")


def format_version(major: int, minor: int, patch: int) -> str:
    """Format version tuple as string."""
    return f"{major}.{minor}.{patch}"


def bump_version(version_str: str, level: str) -> str:
    """Bump version according to SemVer rules."""
    major, minor, patch = parse_version(version_str)
    
    if level == "patch":
        patch += 1
    elif level == "minor":
        minor += 1
        patch = 0
    elif level == "major":
        major += 1
        minor = 0
        patch = 0
    else:
        raise ValueError(f"Invalid bump level: {level} (must be patch, minor, or major)")
    
    return format_version(major, minor, patch)


def read_current_version(component: Component) -> str:
    """Read current version from authoritative source file."""
    source_path = REPO_ROOT / component.version_source
    
    if not source_path.exists():
        raise RuntimeError(f"Version source file not found: {source_path}")
    
    content = source_path.read_text(encoding="utf-8", errors="replace")
    match = VERSION_HEADER_RE.search(content)
    
    if not match:
        raise RuntimeError(f"No 'Version:' header found in {component.version_source}")
    
    return match.group("version")


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


def update_version_in_file(file_path: Path, old_version: str, new_version: str, constant_name: Optional[str] = None, is_theme: bool = False) -> list[str]:
    """Update version in a file. Returns list of changes made."""
    content = file_path.read_text(encoding="utf-8", errors="replace")
    original_content = content
    changes = []
    
    # Update WordPress header Version: field
    def replace_header(match):
        prefix = match.group(1)
        suffix = match.group(3)
        changes.append(f"  Header Version: {old_version} → {new_version}")
        return f"{prefix}{new_version}{suffix}"
    
    content = VERSION_HEADER_RE.sub(replace_header, content)
    
    # Update version constant if present
    if constant_name:
        const_pattern = re.compile(
            rf"(define\s*\(\s*['\"]{re.escape(constant_name)}['\"]\s*,\s*['\"]){re.escape(old_version)}(['\"]\s*\))",
            re.MULTILINE
        )
        
        def replace_const(match):
            prefix = match.group(1)
            suffix = match.group(2)
            changes.append(f"  Constant {constant_name}: {old_version} → {new_version}")
            return f"{prefix}{new_version}{suffix}"
        
        content = const_pattern.sub(replace_const, content)
    
    if content != original_content:
        file_path.write_text(content, encoding="utf-8")
    
    return changes


def bump_component(component: Component, level: str, dry_run: bool = False) -> dict:
    """Bump a single component's version."""
    # Read current version
    current_version = read_current_version(component)
    
    # Calculate new version
    new_version = bump_version(current_version, level)
    
    # Detect version constant
    constant_name = detect_version_constant(component)
    
    # Determine files to update
    files_to_update = []
    source_path = REPO_ROOT / component.version_source
    files_to_update.append((source_path, constant_name))
    
    # For theme, also update functions.php
    if component.type == "theme":
        theme_dir = (REPO_ROOT / component.version_source).parent
        theme_functions = theme_dir / "functions.php"
        # Detect the actual constant name from functions.php
        theme_const = detect_version_constant(component)
        if theme_const:
            files_to_update.append((theme_functions, theme_const))
        else:
            # Fallback to standard constant name
            files_to_update.append((theme_functions, "CONEXAO_THEME_VERSION"))
    
    # Perform updates
    all_changes = []
    for file_path, const_name in files_to_update:
        if file_path.exists():
            changes = update_version_in_file(file_path, current_version, new_version, const_name, is_theme=(component.type == "theme"))
            if changes:
                all_changes.append(f"{file_path.relative_to(REPO_ROOT)}:")
                all_changes.extend(changes)
    
    return {
        "slug": component.slug,
        "type": component.type,
        "old_version": current_version,
        "new_version": new_version,
        "level": level,
        "files_changed": len(all_changes) > 0,
        "changes": all_changes,
        "dry_run": dry_run,
    }


def main():
    parser = argparse.ArgumentParser(
        description="Bump component versions safely with SemVer",
        formatter_class=argparse.RawDescriptionHelpFormatter,
        epilog="""
Examples:
  # Bump a single plugin patch version (dry run first)
  python3 scripts/bump-component-version.py --component conexao-content --level patch --dry-run
  python3 scripts/bump-component-version.py --component conexao-content --level patch

  # Bump multiple components
  python3 scripts/bump-component-version.py --component conexao-content --component conexao-event-runtime --level patch

  # Bump theme minor version
  python3 scripts/bump-component-version.py --component conexao-br-irlanda --level minor

  # List all components
  python3 scripts/bump-component-version.py --list-components
        """
    )
    
    parser.add_argument(
        "--component", "-c",
        action="append",
        help="Component slug to bump (can be specified multiple times)"
    )
    parser.add_argument(
        "--level", "-l",
        choices=["patch", "minor", "major"],
        default="patch",
        help="Version bump level (default: patch)"
    )
    parser.add_argument(
        "--dry-run", "-n",
        action="store_true",
        help="Show what would be changed without writing files"
    )
    parser.add_argument(
        "--list-components",
        action="store_true",
        help="List all active components and their current versions"
    )
    parser.add_argument(
        "--yes", "-y",
        action="store_true",
        help="Skip confirmation prompt (use with caution)"
    )
    
    args = parser.parse_args()
    
    # Handle list components
    if args.list_components:
        components = list_components()
        print(f"{'Component':<35} {'Type':<8} {'Version':<10} {'Production':<12} {'Build':<8} {'Class':<10} {'Status'}")
        print("-" * 100)
        for comp in components:
            version = read_current_version(comp)
            print(f"{comp.slug:<35} {comp.type:<8} {version:<10} {str(comp.production):<12} {str(comp.build):<8} {comp.class_:<10} {comp.status}")
        return 0
    
    # Validate component arguments
    if not args.component:
        parser.error("At least one --component is required (or use --list-components)")
    
    # Validate each component exists
    components_to_bump = []
    for slug in args.component:
        try:
            comp = get_component(slug)
            components_to_bump.append(comp)
        except RuntimeError as e:
            print(f"ERROR: {e}", file=sys.stderr)
            return 1
    
    # Show preview
    print(f"Version bump preview (level: {args.level}):")
    print("=" * 80)
    
    results = []
    for comp in components_to_bump:
        current = read_current_version(comp)
        new_ver = bump_version(current, args.level)
        const_name = detect_version_constant(comp)
        
        print(f"\n{comp.slug} ({comp.type})")
        print(f"  Current version: {current}")
        print(f"  New version:     {new_ver}")
        print(f"  Source file:     {comp.version_source}")
        if const_name:
            print(f"  Version constant: {const_name}")
        if comp.type == "theme":
            print(f"  Also updates:    {THEME_FUNCTIONS_PHP.relative_to(REPO_ROOT)} (CONEXAO_THEME_VERSION)")
        
        results.append({
            "component": comp,
            "current": current,
            "new": new_ver,
            "constant": const_name,
        })
    
    if args.dry_run:
        print("\n[DRY RUN] No files were modified.")
        return 0
    
    # Confirm
    if not args.yes:
        print("\n" + "=" * 80)
        response = input("Proceed with these version bumps? [y/N]: ").strip().lower()
        if response not in ("y", "yes"):
            print("Aborted.")
            return 1
    
    # Perform bumps
    print("\nApplying version bumps...")
    all_success = True
    
    for result in results:
        comp = result["component"]
        try:
            bump_result = bump_component(comp, args.level, dry_run=False)
            if bump_result["files_changed"]:
                print(f"\n✓ {comp.slug}: {bump_result['old_version']} → {bump_result['new_version']}")
                for change in bump_result["changes"]:
                    print(f"  {change}")
            else:
                print(f"\n⚠ {comp.slug}: No changes made (version may already be in sync)")
        except Exception as e:
            print(f"\n✗ {comp.slug}: FAILED - {e}", file=sys.stderr)
            all_success = False
    
    if all_success:
        print("\n✓ All version bumps applied successfully.")
        print("\nNext steps:")
        print("  1. Review the changes with: git diff")
        print("  2. Run tests: ./scripts/run-tests.sh")
        print("  3. Run lint: ./scripts/lint.sh")
        print("  4. Commit the changes in the same PR as the code changes")
    else:
        print("\n✗ Some version bumps failed.", file=sys.stderr)
        return 1
    
    return 0


if __name__ == "__main__":
    sys.exit(main())