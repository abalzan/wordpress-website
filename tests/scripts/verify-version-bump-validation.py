#!/usr/bin/env python3
"""
verify-version-bump-validation.py — Test the version bump validation logic.

This test verifies that the validate-version-bumps.py script correctly:
1. Accepts valid patch/minor/major bumps
2. Rejects invalid increments (e.g., 1.9.1 → 1.10.1)
3. Rejects version downgrades
4. Detects unsynchronized constants
5. Detects unrelated version bumps
6. Allows documentation-only changes without version bumps
"""

from __future__ import annotations

import json
import os
import re
import shutil
import subprocess
import sys
import tempfile
from pathlib import Path

REPO_ROOT = Path(os.environ.get("CONEXAO_REPO_ROOT") or Path(__file__).parent.parent.parent).resolve()
SCRIPT = REPO_ROOT / "scripts" / "validate-version-bumps.py"
BUMP_SCRIPT = REPO_ROOT / "scripts" / "bump-component-version.py"

PASSED = 0
FAILED = 0
FAILURES: list[str] = []


def check(condition: bool, message: str) -> bool:
    global PASSED, FAILED
    if condition:
        PASSED += 1
        print(f"  ✓ {message}")
        return True
    else:
        FAILED += 1
        FAILURES.append(message)
        print(f"  ✗ {message}")
        return False


def run_script(*args, cwd=None, env=None, check=True):
    """Run a script and return (returncode, stdout, stderr)."""
    cmd = [sys.executable, str(SCRIPT)] + list(args)
    test_env = {**os.environ, "CONEXAO_REPO_ROOT": str(cwd or REPO_ROOT), **(env or {})}
    result = subprocess.run(
        cmd,
        cwd=cwd or REPO_ROOT,
        capture_output=True,
        text=True,
        env=test_env
    )
    if check and result.returncode != 0:
        print(f"Command failed: {' '.join(cmd)}")
        print(f"stdout: {result.stdout}")
        print(f"stderr: {result.stderr}")
    return result.returncode, result.stdout, result.stderr


def run_bump_script(*args, cwd=None, env=None, check=True):
    """Run the bump script and return (returncode, stdout, stderr)."""
    cmd = [sys.executable, str(BUMP_SCRIPT)] + list(args)
    test_env = {**os.environ, "CONEXAO_REPO_ROOT": str(cwd or REPO_ROOT), **(env or {})}
    result = subprocess.run(
        cmd,
        cwd=cwd or REPO_ROOT,
        capture_output=True,
        text=True,
        env=test_env
    )
    if check and result.returncode != 0:
        print(f"Bump command failed: {' '.join(cmd)}")
        print(f"stdout: {result.stdout}")
        print(f"stderr: {result.stderr}")
    return result.returncode, result.stdout, result.stderr


def setup_test_repo() -> Path:
    """Create a temporary git repo with test components."""
    temp_dir = Path(tempfile.mkdtemp(prefix="version-bump-test-"))
    
    # Initialize git repo
    subprocess.run(["git", "init"], cwd=temp_dir, check=True, capture_output=True)
    subprocess.run(["git", "config", "user.email", "test@test.com"], cwd=temp_dir, check=True)
    subprocess.run(["git", "config", "user.name", "Test User"], cwd=temp_dir, check=True)
    
    # Create plugins.json
    plugins_json = {
        "$schema_version": 1,
        "conventions": {
            "load_order": "Activation/load order",
            "class": "platform = production runtime | tooling = local-only",
            "status": "active = in the current lifecycle | retired = rollout already applied",
            "production": "Part of the production steady state",
            "build": "Included in release plugin ZIPs",
            "mount": "Bind-mounted into the local Compose stack",
            "version_source": "The plugin main file whose WordPress header Version field is authoritative",
            "documentation": "Path to the plugin's documentation file"
        },
        "load_order": [
            {
                "slug": "test-plugin-a",
                "name": "Test Plugin A",
                "class": "platform",
                "status": "active",
                "production": True,
                "build": True,
                "mount": True,
                "dependencies": [],
                "version_source": "wp-content/plugins/test-plugin-a/test-plugin-a.php",
                "documentation": "docs/plugins/test-plugin-a.md"
            },
            {
                "slug": "test-plugin-b",
                "name": "Test Plugin B",
                "class": "tooling",
                "status": "active",
                "production": False,
                "build": True,
                "mount": True,
                "dependencies": [],
                "version_source": "wp-content/plugins/test-plugin-b/test-plugin-b.php",
                "documentation": "docs/plugins/test-plugin-b.md"
            }
        ]
    }
    
    (temp_dir / "plugins.json").write_text(json.dumps(plugins_json, indent=2))
    
    # Create plugin directories and files
    for slug in ["test-plugin-a", "test-plugin-b"]:
        plugin_dir = temp_dir / "wp-content" / "plugins" / slug
        plugin_dir.mkdir(parents=True)
        
        plugin_file = plugin_dir / f"{slug}.php"
        const_name = f"TEST_{slug.upper().replace('-', '_')}_VERSION"
        plugin_file.write_text(f"""<?php
/**
 * Plugin Name: {slug.title()}
 * Description: Test plugin
 * Version: 1.0.0
 * Text Domain: {slug}
 */

defined( 'ABSPATH' ) || exit;

define( '{const_name}', '1.0.0' );
""")
    
    # Create theme
    theme_dir = temp_dir / "wp-content" / "themes" / "test-theme"
    theme_dir.mkdir(parents=True)
    
    (theme_dir / "style.css").write_text("""/*
Theme Name: Test Theme
Version: 1.0.0
*/
""")
    
    (theme_dir / "functions.php").write_text("""<?php
define( 'TEST_THEME_VERSION', '1.0.0' );
""")
    
    # Add plugins.json to git
    subprocess.run(["git", "add", "plugins.json"], cwd=temp_dir, check=True)
    subprocess.run(["git", "add", "wp-content"], cwd=temp_dir, check=True)
    subprocess.run(["git", "commit", "-m", "Initial commit"], cwd=temp_dir, check=True, capture_output=True)
    
    return temp_dir


def test_valid_patch_bump():
    """Test that a valid patch bump passes validation."""
    print("\n=== Test: Valid patch bump ===")
    temp_dir = setup_test_repo()
    
    try:
        # Bump plugin A patch version
        run_bump_script("--component", "test-plugin-a", "--level", "patch", "--yes", cwd=temp_dir)
        
        # Commit the bump
        subprocess.run(["git", "add", "."], cwd=temp_dir, check=True)
        subprocess.run(["git", "commit", "-m", "Bump version"], cwd=temp_dir, check=True, capture_output=True)
        
        # Validate
        rc, stdout, stderr = run_script("--base-ref", "HEAD~1", "--head-ref", "HEAD", cwd=temp_dir, check=False)
        check(rc == 0, "Valid patch bump passes validation")
        check("Valid patch bump" in stdout, "Output mentions valid patch bump")
    finally:
        shutil.rmtree(temp_dir)


def test_valid_minor_bump():
    """Test that a valid minor bump passes validation."""
    print("\n=== Test: Valid minor bump ===")
    temp_dir = setup_test_repo()
    
    try:
        # Bump plugin A minor version
        run_bump_script("--component", "test-plugin-a", "--level", "minor", "--yes", cwd=temp_dir)
        
        # Commit the bump
        subprocess.run(["git", "add", "."], cwd=temp_dir, check=True)
        subprocess.run(["git", "commit", "-m", "Bump version"], cwd=temp_dir, check=True, capture_output=True)
        
        # Validate
        rc, stdout, stderr = run_script("--base-ref", "HEAD~1", "--head-ref", "HEAD", cwd=temp_dir, check=False)
        check(rc == 0, "Valid minor bump passes validation")
        check("Valid minor bump" in stdout, "Output mentions valid minor bump")
    finally:
        shutil.rmtree(temp_dir)


def test_valid_major_bump():
    """Test that a valid major bump passes validation."""
    print("\n=== Test: Valid major bump ===")
    temp_dir = setup_test_repo()
    
    try:
        # Bump plugin A major version
        run_bump_script("--component", "test-plugin-a", "--level", "major", "--yes", cwd=temp_dir)
        
        # Commit the bump
        subprocess.run(["git", "add", "."], cwd=temp_dir, check=True)
        subprocess.run(["git", "commit", "-m", "Bump version"], cwd=temp_dir, check=True, capture_output=True)
        
        # Validate
        rc, stdout, stderr = run_script("--base-ref", "HEAD~1", "--head-ref", "HEAD", cwd=temp_dir, check=False)
        check(rc == 0, "Valid major bump passes validation")
        check("Valid major bump" in stdout, "Output mentions valid major bump")
    finally:
        shutil.rmtree(temp_dir)


def test_invalid_increment():
    """Test that an invalid increment (1.9.1 → 1.10.1) fails validation."""
    print("\n=== Test: Invalid increment (1.9.1 → 1.10.1) ===")
    temp_dir = setup_test_repo()
    
    try:
        # Manually create an invalid version bump
        plugin_file = temp_dir / "wp-content" / "plugins" / "test-plugin-a" / "test-plugin-a.php"
        content = plugin_file.read_text()
        content = content.replace("Version: 1.0.0", "Version: 1.9.1")
        content = content.replace("'1.0.0'", "'1.9.1'")
        plugin_file.write_text(content)
        
        subprocess.run(["git", "add", "."], cwd=temp_dir, check=True)
        subprocess.run(["git", "commit", "-m", "Base version 1.9.1"], cwd=temp_dir, check=True, capture_output=True)
        
        # Now make invalid bump to 1.10.1
        content = content.replace("Version: 1.9.1", "Version: 1.10.1")
        content = content.replace("'1.9.1'", "'1.10.1'")
        plugin_file.write_text(content)
        
        subprocess.run(["git", "add", "."], cwd=temp_dir, check=True)
        subprocess.run(["git", "commit", "-m", "Invalid bump to 1.10.1"], cwd=temp_dir, check=True, capture_output=True)
        
        # Validate - should fail
        rc, stdout, stderr = run_script("--base-ref", "HEAD~1", "--head-ref", "HEAD", cwd=temp_dir, check=False)
        check(rc != 0, "Invalid increment fails validation")
        check("Invalid version increment" in stdout or "not a valid SemVer" in stdout, "Error mentions invalid SemVer")
    finally:
        shutil.rmtree(temp_dir)


def test_version_downgrade():
    """Test that a version downgrade fails validation."""
    print("\n=== Test: Version downgrade ===")
    temp_dir = setup_test_repo()
    
    try:
        # Bump to 1.1.0 first
        run_bump_script("--component", "test-plugin-a", "--level", "minor", "--yes", cwd=temp_dir)
        subprocess.run(["git", "add", "."], cwd=temp_dir, check=True)
        subprocess.run(["git", "commit", "-m", "Bump to 1.1.0"], cwd=temp_dir, check=True, capture_output=True)
        
        # Now downgrade to 1.0.5
        plugin_file = temp_dir / "wp-content" / "plugins" / "test-plugin-a" / "test-plugin-a.php"
        content = plugin_file.read_text()
        content = content.replace("Version: 1.1.0", "Version: 1.0.5")
        content = content.replace("'1.1.0'", "'1.0.5'")
        plugin_file.write_text(content)
        
        subprocess.run(["git", "add", "."], cwd=temp_dir, check=True)
        subprocess.run(["git", "commit", "-m", "Downgrade to 1.0.5"], cwd=temp_dir, check=True, capture_output=True)
        
        # Validate - should fail
        rc, stdout, stderr = run_script("--base-ref", "HEAD~1", "--head-ref", "HEAD", cwd=temp_dir, check=False)
        check(rc != 0, "Version downgrade fails validation")
        # The validation detects this as an invalid increment (not a clean downgrade)
        check("Invalid version increment" in stdout or "downgrade" in stdout.lower() or "downgrade" in stderr.lower(), "Error mentions invalid increment or downgrade")
    finally:
        shutil.rmtree(temp_dir)


def test_unsynchronized_constant():
    """Test that unsynchronized constant fails validation."""
    print("\n=== Test: Unsynchronized constant ===")
    temp_dir = setup_test_repo()
    
    try:
        # Bump header but not constant
        plugin_file = temp_dir / "wp-content" / "plugins" / "test-plugin-a" / "test-plugin-a.php"
        content = plugin_file.read_text()
        content = content.replace("Version: 1.0.0", "Version: 1.0.1")
        # Don't update the constant
        plugin_file.write_text(content)
        
        subprocess.run(["git", "add", "."], cwd=temp_dir, check=True)
        subprocess.run(["git", "commit", "-m", "Bump header only"], cwd=temp_dir, check=True, capture_output=True)
        
        # Validate - should fail
        rc, stdout, stderr = run_script("--base-ref", "HEAD~1", "--head-ref", "HEAD", cwd=temp_dir, check=False)
        check(rc != 0, "Unsynchronized constant fails validation")
        check("does not match header" in stdout or "constant" in stdout.lower() or "constant" in stderr.lower(), "Error mentions constant mismatch")
    finally:
        shutil.rmtree(temp_dir)


def test_changed_component_without_version_bump():
    """Test that modifying a component's main file without bumping its version fails validation."""
    print("\n=== Test: Changed component without version bump ===")
    temp_dir = setup_test_repo()
    
    try:
        # Modify plugin A's MAIN file AND bump its version (valid)
        plugin_a_file = temp_dir / "wp-content" / "plugins" / "test-plugin-a" / "test-plugin-a.php"
        content = plugin_a_file.read_text()
        content = content.replace("Version: 1.0.0", "Version: 1.0.1")
        content = content.replace("'1.0.0'", "'1.0.1'")
        plugin_a_file.write_text(content)
        
        # Modify plugin B's MAIN file but DON'T bump its version
        plugin_b_file = temp_dir / "wp-content" / "plugins" / "test-plugin-b" / "test-plugin-b.php"
        content = plugin_b_file.read_text()
        content = content.replace("defined( 'ABSPATH' ) || exit;", "defined( 'ABSPATH' ) || exit;\n// Modified but no version bump")
        plugin_b_file.write_text(content)
        
        subprocess.run(["git", "add", "."], cwd=temp_dir, check=True)
        subprocess.run(["git", "commit", "-m", "Modified plugin B without version bump"], cwd=temp_dir, check=True, capture_output=True)
        
        # Validate - should fail because plugin B was modified but its version wasn't bumped
        rc, stdout, stderr = run_script("--base-ref", "HEAD~1", "--head-ref", "HEAD", cwd=temp_dir, check=False)
        check(rc != 0, "Changed component without version bump fails validation")
        check("Source files modified but version not bumped" in stdout, "Error mentions source files modified but version not bumped")
    finally:
        shutil.rmtree(temp_dir)


def test_docs_only_change():
    """Test that documentation-only changes don't require version bumps."""
    print("\n=== Test: Documentation-only change ===")
    temp_dir = setup_test_repo()
    
    try:
        # Create docs directory and modify a doc file
        docs_dir = temp_dir / "docs"
        docs_dir.mkdir()
        (docs_dir / "README.md").write_text("# Test\n\nDocumentation.")
        
        subprocess.run(["git", "add", "."], cwd=temp_dir, check=True)
        subprocess.run(["git", "commit", "-m", "Update docs"], cwd=temp_dir, check=True, capture_output=True)
        
        # Validate - should pass (no version bumps needed)
        rc, stdout, stderr = run_script("--base-ref", "HEAD~1", "--head-ref", "HEAD", cwd=temp_dir, check=False)
        check(rc == 0, "Documentation-only change passes validation")
        check("Changed components" in stdout and "none" in stdout.lower(), "No changed components detected")
    finally:
        shutil.rmtree(temp_dir)


def test_test_only_change():
    """Test that test-only changes don't require version bumps."""
    print("\n=== Test: Test-only change ===")
    temp_dir = setup_test_repo()
    
    try:
        # Create tests directory and modify a test file
        tests_dir = temp_dir / "tests"
        tests_dir.mkdir()
        (tests_dir / "test_something.py").write_text("# Test file\n\ndef test_something():\n    assert True\n")
        
        subprocess.run(["git", "add", "."], cwd=temp_dir, check=True)
        subprocess.run(["git", "commit", "-m", "Add test"], cwd=temp_dir, check=True, capture_output=True)
        
        # Validate - should pass (no version bumps needed)
        rc, stdout, stderr = run_script("--base-ref", "HEAD~1", "--head-ref", "HEAD", cwd=temp_dir, check=False)
        check(rc == 0, "Test-only change passes validation")
        check("Changed components" in stdout and "none" in stdout.lower(), "No changed components detected")
    finally:
        shutil.rmtree(temp_dir)


def test_multiple_components():
    """Test that multiple components can be bumped independently."""
    print("\n=== Test: Multiple components bumped independently ===")
    temp_dir = setup_test_repo()
    
    try:
        # Bump both plugins
        run_bump_script("--component", "test-plugin-a", "--component", "test-plugin-b", "--level", "patch", "--yes", cwd=temp_dir)
        
        # Commit the bumps
        subprocess.run(["git", "add", "."], cwd=temp_dir, check=True)
        subprocess.run(["git", "commit", "-m", "Bump versions"], cwd=temp_dir, check=True, capture_output=True)
        
        # Validate
        rc, stdout, stderr = run_script("--base-ref", "HEAD~1", "--head-ref", "HEAD", cwd=temp_dir, check=False)
        check(rc == 0, "Multiple component bumps pass validation")
        check("test-plugin-a" in stdout and "test-plugin-b" in stdout, "Both components validated")
    finally:
        shutil.rmtree(temp_dir)


def test_theme_bump():
    """Test that theme version bump works."""
    print("\n=== Test: Theme version bump ===")
    temp_dir = setup_test_repo()
    
    try:
        # Add theme to plugins.json (using the hardcoded THEME_SLUG)
        plugins_json = json.loads((temp_dir / "plugins.json").read_text())
        plugins_json["load_order"].append({
            "slug": "conexao-br-irlanda",
            "name": "Test Theme",
            "class": "platform",
            "status": "active",
            "production": True,
            "build": True,
            "mount": True,
            "dependencies": [],
            "version_source": "wp-content/themes/conexao-br-irlanda/style.css",
            "documentation": "docs/themes/conexao-br-irlanda.md"
        })
        (temp_dir / "plugins.json").write_text(json.dumps(plugins_json, indent=2))
        
        # Create theme directory
        theme_dir = temp_dir / "wp-content" / "themes" / "conexao-br-irlanda"
        theme_dir.mkdir(parents=True)
        (theme_dir / "style.css").write_text("""/*
Theme Name: Test Theme
Version: 1.0.0
*/
""")
        (theme_dir / "functions.php").write_text("""<?php
define( 'CONEXAO_THEME_VERSION', '1.0.0' );
""")
        
        subprocess.run(["git", "add", "plugins.json"], cwd=temp_dir, check=True)
        subprocess.run(["git", "add", "wp-content/themes/conexao-br-irlanda"], cwd=temp_dir, check=True)
        subprocess.run(["git", "commit", "-m", "Add theme to registry"], cwd=temp_dir, check=True, capture_output=True)
        
        # Bump theme
        run_bump_script("--component", "conexao-br-irlanda", "--level", "patch", "--yes", cwd=temp_dir)
        
        # Commit the bump
        subprocess.run(["git", "add", "."], cwd=temp_dir, check=True)
        subprocess.run(["git", "commit", "-m", "Bump theme version"], cwd=temp_dir, check=True, capture_output=True)
        
        # Validate
        rc, stdout, stderr = run_script("--base-ref", "HEAD~1", "--head-ref", "HEAD", cwd=temp_dir, check=False)
        check(rc == 0, "Theme bump passes validation")
        check("conexao-br-irlanda" in stdout, "Theme component validated")
    finally:
        shutil.rmtree(temp_dir)


def main():
    print("=" * 80)
    print("Version Bump Validation Tests")
    print("=" * 80)
    
    test_valid_patch_bump()
    test_valid_minor_bump()
    test_valid_major_bump()
    test_invalid_increment()
    test_version_downgrade()
    test_unsynchronized_constant()
    test_changed_component_without_version_bump()
    test_docs_only_change()
    test_test_only_change()
    test_multiple_components()
    test_theme_bump()
    
    print("\n" + "=" * 80)
    print(f"Results: {PASSED} passed, {FAILED} failed")
    print("=" * 80)
    
    if FAILURES:
        print("\nFailures:")
        for failure in FAILURES:
            print(f"  - {failure}")
        return 1
    
    return 0


if __name__ == "__main__":
    sys.exit(main())