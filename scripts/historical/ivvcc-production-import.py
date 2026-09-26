#!/usr/bin/env python3
"""
IVVCC Production Import Script

Imports IVVCC events from the local export JSON into the production
WordPress.com site via the REST API.

Usage:
    export WP_USERNAME='your-wpcom-username'
    export WP_APPLICATION_PASSWORD='xxxx xxxx xxxx xxxx xxxx xxxx'
    python3 scripts/ivvcc-production-import.py [--dry-run] [--file PATH]

Requires Python 3.8+ (standard library only).
"""

import argparse
import base64
import json
import os
import sys
import time
import urllib.error
import urllib.request
import re
from datetime import datetime, timezone

# ---------------------------------------------------------------------------
# Configuration
# ---------------------------------------------------------------------------

def get_config():
    """Get configuration from environment."""
    return {
        'base_url': os.environ.get('WP_BASE_URL', 'https://conexaobr.ie').rstrip('/'),
        'username': os.environ.get('WP_USERNAME'),
        'app_password': os.environ.get('WP_APPLICATION_PASSWORD'),
        'export_file': os.environ.get('IVVCC_EXPORT_FILE', 'dist/ivvcc-only-export.json'),
        'dry_run': False,
    }

# ---------------------------------------------------------------------------
# REST API Client
# ---------------------------------------------------------------------------

class WpRest:
    """Simple WordPress REST API client with Application Password auth."""
    
    def __init__(self, base_url, username, app_password):
        self.base_url = base_url.rstrip('/')
        token = base64.b64encode(f"{username}:{app_password}".encode()).decode()
        self.headers = {
            'Authorization': f'Basic {token}',
            'Content-Type': 'application/json',
            'User-Agent': 'ConexaoBR/1.0 (IVVCC Import)',
            'Accept': 'application/json',
        }
    
    def request(self, method, path, data=None):
        """Make a REST API request."""
        url = f'{self.base_url}{path}'
        body = None
        if data is not None:
            body = json.dumps(data).encode('utf-8')
        
        req = urllib.request.Request(url, data=body, headers=self.headers, method=method)
        
        try:
            with urllib.request.urlopen(req, timeout=30) as resp:
                response_data = resp.read()
                if resp.status in (200, 201):
                    try:
                        return json.loads(response_data), resp.status
                    except json.JSONDecodeError:
                        return None, resp.status
                else:
                    return json.loads(response_data) if response_data else None, resp.status
        except urllib.error.HTTPError as e:
            error_body = e.read().decode('utf-8', errors='replace')
            try:
                error_data = json.loads(error_body)
            except json.JSONDecodeError:
                error_data = {'message': error_body}
            return error_data, e.code
        except urllib.error.URLError as e:
            return {'message': str(e.reason)}, 0
    
    def get_all_events(self):
        """Get all events."""
        all_events = []
        page = 1
        while True:
            result, status = self.request('GET', f'/wp/v2/event?per_page=100&page={page}&_fields=ID,slug,title,meta')
            if status != 200 or not result:
                break
            if not isinstance(result, list):
                break
            all_events.extend(result)
            if len(result) < 100:
                break
            page += 1
        return all_events
    
    def get_event_by_id(self, post_id):
        """Get an event by its ID."""
        result, status = self.request('GET', f'/wp/v2/event/{post_id}')
        if status == 200 and result:
            return result
        return None
    
    def create_event(self, event_data):
        """Create a new event via REST API."""
        result, status = self.request('POST', '/wp/v2/event', event_data)
        return result, status
    
    def update_event(self, post_id, event_data):
        """Update an existing event via REST API."""
        result, status = self.request('POST', f'/wp/v2/event/{post_id}', event_data)
        return result, status
    
    def update_meta(self, post_id, meta_key, meta_value):
        """Update a post meta field."""
        # Try the standard meta endpoint
        result, status = self.request('POST', f'/wp/v2/event/{post_id}', {'_meta': {meta_key: meta_value}})
        if status in (200, 201):
            return True, result
        # Fallback: try direct meta update
        result, status = self.request('POST', f'/wp/v2/event/{post_id}/meta', {
            'key': meta_key,
            'value': meta_value
        })
        return status in (200, 201), result


# ---------------------------------------------------------------------------
# IVVCC Import Logic
# ---------------------------------------------------------------------------

class IvvccImporter:
    """Import IVVCC events from export JSON to production site."""
    
    def __init__(self, rest_client, export_file):
        self.rest = rest_client
        self.export_file = export_file
        self.stats = {
            'created': 0,
            'updated': 0,
            'skipped': 0,
            'failed': 0,
            'errors': [],
        }
        self.events = []
    
    def load_export(self):
        """Load and validate the export file."""
        try:
            with open(self.export_file, 'r', encoding='utf-8') as f:
                data = json.load(f)
        except FileNotFoundError:
            print(f'ERROR: Export file not found: {self.export_file}')
            sys.exit(1)
        except json.JSONDecodeError as e:
            print(f'ERROR: Invalid JSON in export file: {e}')
            sys.exit(1)
        
        if data.get('manifest', {}).get('format') != 'conexao-event-export':
            print('ERROR: Invalid export format')
            sys.exit(1)
        
        self.events = data.get('events', [])
        if not self.events:
            print('ERROR: No events in export file')
            sys.exit(1)
        
        print(f'Loaded {len(self.events)} events from {self.export_file}')
        return True
    
    def find_existing_event(self, event_data):
        """Find an existing event matching the import data."""
        meta = event_data.get('meta', {})
        uuid = event_data.get('uuid', '')
        
        # 1. UUID match
        if uuid:
            all_events = self.rest.get_all_events()
            for existing in all_events:
                existing_uuid = existing.get('meta', {}).get('_event_export_uuid', '')
                if existing_uuid == uuid:
                    print(f"  Found by UUID: {uuid}")
                    return existing.get('id')
        
        # 2. Source ID match
        source = meta.get('_event_source', '')
        source_id = meta.get('_event_source_id', '')
        if source and source_id:
            all_events = self.rest.get_all_events()
            for existing in all_events:
                existing_source = existing.get('meta', {}).get('_event_source', '')
                existing_source_id = existing.get('meta', {}).get('_event_source_id', '')
                if existing_source == source and existing_source_id == source_id:
                    print(f"  Found by source: {source}/{source_id}")
                    return existing.get('id')
        
        # 3. Source URL match
        source_url = meta.get('_event_source_url') or meta.get('_event_url', '')
        if source_url:
            all_events = self.rest.get_all_events()
            for existing in all_events:
                existing_url = existing.get('meta', {}).get('_event_url', '')
                existing_source_url = existing.get('meta', {}).get('_event_source_url', '')
                if existing_url == source_url or existing_source_url == source_url:
                    print(f"  Found by URL: {source_url}")
                    return existing.get('id')
        
        # 4. Content-based match (title + date)
        title = event_data.get('post', {}).get('title', '')
        event_date = meta.get('_event_date', '')
        if title and event_date:
            all_events = self.rest.get_all_events()
            for existing in all_events:
                existing_title = existing.get('title', {}).get('rendered', '')
                existing_date = existing.get('meta', {}).get('_event_date', '')
                if existing_title == title and existing_date == event_date:
                    print(f"  Found by content: {title} / {event_date}")
                    return existing.get('id')
        
        return None
    
    def sanitize_post_data(self, event_data):
        """Sanitize post data for REST API."""
        post = event_data.get('post', {})
        
        # Title
        title = post.get('title', '')
        if isinstance(title, dict):
            title = title.get('rendered', '')
        
        # Content (description)
        content = post.get('content', '')
        if isinstance(content, dict):
            content = content.get('rendered', '')
        
        # Excerpt
        excerpt = post.get('excerpt', '')
        if isinstance(excerpt, dict):
            excerpt = excerpt.get('rendered', '')
        
        # Status
        status = post.get('status', 'publish')
        
        # Slug
        slug = post.get('slug', '')
        if not slug:
            slug = self._generate_slug(title)
        
        # Date
        date = post.get('date', datetime.now(timezone.utc).isoformat())
        
        return {
            'title': title,
            'content': content,
            'excerpt': excerpt,
            'status': status,
            'slug': slug,
            'date': date,
        }
    
    def _generate_slug(self, title):
        """Generate a slug from title."""
        slug = title.lower()
        slug = re.sub(r'[^a-z0-9]+', '-', slug)
        slug = re.sub(r'^-|-$', '', slug)
        return slug[:200] if slug else 'event'
    
    def import_event(self, event_data):
        """Import a single event."""
        print(f"\nProcessing: {event_data['post']['title']}")
        print(f"  UUID: {event_data.get('uuid', 'N/A')}")
        print(f"  Source: {event_data.get('meta', {}).get('_event_source')}/{event_data.get('meta', {}).get('_event_source_id')}")
        
        if self.dry_run:
            print('  [DRY RUN] Would process this event')
            return {'action': 'dry_run', 'post_id': 0}
        
        # Find existing event
        existing_id = self.find_existing_event(event_data)
        
        if existing_id:
            if self.dry_run:
                print(f'  [DRY RUN] Would update existing event {existing_id}')
                return {'action': 'dry_run_update', 'post_id': existing_id}
            
            # Update existing event
            print(f'  Updating existing event {existing_id}...')
            post_data = self.sanitize_post_data(event_data)
            
            result, status = self.rest.update_event(existing_id, {
                'title': post_data['title'],
                'content': post_data['content'],
                'excerpt': post_data['excerpt'],
                'status': post_data['status'],
                'slug': post_data['slug'],
            })
            
            if status in (200, 201) and result:
                # Update meta
                self.update_event_meta(existing_id, event_data)
                print(f'  Updated: {result.get("id", existing_id)}')
                self.stats['updated'] += 1
                return {'action': 'updated', 'post_id': result.get('id', existing_id)}
            else:
                error_msg = result.get('message', 'Unknown error') if result else 'No response'
                print(f'  FAILED: {error_msg}')
                self.stats['failed'] += 1
                self.stats['errors'].append(f'Update failed for {event_data["post"]["title"]}: {error_msg}')
                return {'action': 'failed', 'post_id': 0, 'error': error_msg}
        else:
            if self.dry_run:
                print('  [DRY RUN] Would create new event')
                return {'action': 'dry_run_create', 'post_id': 0}
            
            # Create new event
            print('  Creating new event...')
            post_data = self.sanitize_post_data(event_data)
            
            result, status = self.rest.create_event({
                'title': post_data['title'],
                'content': post_data['content'],
                'excerpt': post_data['excerpt'],
                'status': post_data['status'],
                'slug': post_data['slug'],
                'date': post_data['date'],
                'type': 'event',
            })
            
            if status in (200, 201) and result:
                new_id = result.get('id')
                # Update meta
                self.update_event_meta(new_id, event_data)
                print(f'  Created: {new_id}')
                self.stats['created'] += 1
                return {'action': 'created', 'post_id': new_id}
            else:
                error_msg = result.get('message', 'Unknown error') if result else 'No response'
                print(f'  FAILED: {error_msg}')
                self.stats['failed'] += 1
                self.stats['errors'].append(f'Create failed for {event_data["post"]["title"]}: {error_msg}')
                return {'action': 'failed', 'post_id': 0, 'error': error_msg}
    
    def update_event_meta(self, post_id, event_data):
        """Update event meta fields."""
        meta = event_data.get('meta', {})
        
        # Meta keys to update
        allowed_meta = [
            '_event_date',
            '_event_time',
            '_event_start_time',
            '_event_end_date',
            '_event_end_time',
            '_event_location',
            '_event_venue',
            '_event_address',
            '_event_map_url',
            '_event_url',
            '_event_source_url',
            '_event_banner',
            '_event_registration',
            '_event_cta',
            '_event_source',
            '_event_source_id',
            '_event_organizer',
            '_event_price',
            '_event_import_date',
            '_event_last_checked',
            '_event_status',
            '_event_imported',
            '_event_export_uuid',
        ]
        
        for key in allowed_meta:
            if key in meta:
                value = str(meta[key])
                success, _ = self.rest.update_meta(post_id, key, value)
                if not success:
                    print(f'    Warning: Could not update meta {key}')
        
        # Update taxonomies
        taxonomies = event_data.get('taxonomies', {})
        for tax_name, terms in taxonomies.items():
            if terms and isinstance(terms, list):
                try:
                    result, status = self.rest.request('POST', f'/wp/v2/event/{post_id}', {
                        tax_name: terms
                    })
                    if status not in (200, 201):
                        print(f'    Warning: Could not update taxonomy {tax_name}')
                except Exception as e:
                    print(f'    Warning: Taxonomy update failed for {tax_name}: {e}')
    
    def run(self, dry_run=False):
        """Run the import process."""
        self.dry_run = dry_run
        
        print('=' * 60)
        print('IVVCC PRODUCTION IMPORT')
        print('=' * 60)
        print(f'Target: {self.rest.base_url}')
        print(f'Export file: {self.export_file}')
        print(f'Dry run: {dry_run}')
        print()
        
        # Load export
        if not self.load_export():
            return False
        
        print(f'\nProcessing {len(self.events)} events...\n')
        
        # Process each event
        for event_data in self.events:
            result = self.import_event(event_data)
            # Small delay to be polite to the API
            time.sleep(0.5)
        
        # Summary
        print('\n' + '=' * 60)
        print('IMPORT SUMMARY')
        print('=' * 60)
        print(f"Created:  {self.stats['created']}")
        print(f"Updated:  {self.stats['updated']}")
        print(f"Skipped:  {self.stats['skipped']}")
        print(f"Failed:   {self.stats['failed']}")
        
        if self.stats['errors']:
            print('\nErrors:')
            for error in self.stats['errors']:
                print(f'  - {error}')
        
        return self.stats['failed'] == 0


# ---------------------------------------------------------------------------
# Main
# ---------------------------------------------------------------------------

def main():
    parser = argparse.ArgumentParser(
        description='Import IVVCC events to production WordPress.com site'
    )
    parser.add_argument(
        '--dry-run',
        action='store_true',
        help='Preview what would be imported without making changes',
    )
    parser.add_argument(
        '--file',
        default=None,
        help='Path to export JSON file',
    )
    parser.add_argument(
        '--base-url',
        default=None,
        help='Production site URL',
    )
    
    args = parser.parse_args()
    
    # Get configuration
    config = get_config()
    
    if args.dry_run:
        config['dry_run'] = True
    if args.file:
        config['export_file'] = args.file
    if args.base_url:
        config['base_url'] = args.base_url.rstrip('/')
    
    # Validate credentials
    if not config['username'] or not config['app_password']:
        print('ERROR: WordPress.com credentials not configured')
        print('Set WP_USERNAME and WP_APPLICATION_PASSWORD environment variables')
        sys.exit(1)
    
    # Create REST client
    rest = WpRest(config['base_url'], config['username'], config['app_password'])
    
    # Test connection
    print('Testing connection to production site...')
    result, status = rest.request('GET', '/wp-json/wp/v2/event?per_page=1')
    if status != 200:
        print(f'ERROR: Cannot connect to production site (status {status})')
        if result:
            print(f'Response: {json.dumps(result, indent=2)}')
        sys.exit(1)
    print('Connection OK\n')
    
    # Run import
    importer = IvvccImporter(rest, config['export_file'])
    success = importer.run(dry_run=config['dry_run'])
    
    sys.exit(0 if success else 1)


if __name__ == '__main__':
    main()
