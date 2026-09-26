#!/usr/bin/env python3
"""
Robust IVVCC Production Import Script
Handles rate limiting and timeouts properly.
"""

import os
import sys
import json
import time
import base64
import urllib.request
import urllib.error
from datetime import datetime, timezone

def main():
    # Configuration
    base_url = os.environ.get('WP_BASE_URL', 'https://conexaobr.ie').rstrip('/')
    username = os.environ.get('WP_USERNAME', 'andreibalzan@gmail.com')
    app_password = os.environ.get('WP_APPLICATION_PASSWORD', '9cPg ot1b m4Ya FMe9 T1n2 3SSZ')
    export_file = os.environ.get('IVVCC_EXPORT_FILE', 'dist/ivvcc-only-export.json')
    
    if not os.path.exists(export_file):
        export_file = '/home/andrei/IdeaProjects/wordpress-website/' + export_file
    
    print(f'Configuration:')
    print(f'  Base URL: {base_url}')
    print(f'  Export file: {export_file}')
    print(f'  Username: {username}')
    print()
    
    # Load export
    print('Loading export file...')
    try:
        with open(export_file, 'r', encoding='utf-8') as f:
            export_data = json.load(f)
    except Exception as e:
        print(f'ERROR loading export file: {e}')
        sys.exit(1)
    
    events = export_data.get('events', [])
    print(f'Loaded {len(events)} events')
    print()
    
    # Setup auth
    token = base64.b64encode(f'{username}:{app_password}'.encode()).decode()
    headers = {
        'Authorization': f'Basic {token}',
        'Content-Type': 'application/json',
        'User-Agent': 'ConexaoBR/1.0 (IVVCC Import)',
        'Accept': 'application/json',
    }
    
    stats = {'created': 0, 'updated': 0, 'failed': 0, 'errors': []}
    
    for i, event in enumerate(events, 1):
        print(f'[{i}/{len(events)}] Processing: {event["post"]["title"]}')
        
        # Load event data
        event_meta = event.get('meta', {})
        event_uuid = event.get('uuid', '')
        event_source = event_meta.get('_event_source', '')
        event_source_id = event_meta.get('_event_source_id', '')
        
        print(f'    UUID: {event_uuid}')
        print(f'    Source: {event_source}/{event_source_id}')
        
        # Step 1: Check if event already exists by UUID
        existing_id = None
        try:
            all_events = fetch_all_events(base_url, headers)
            for existing in all_events:
                existing_meta = existing.get('meta', {})
                if existing_meta.get('_event_export_uuid') == event_uuid:
                    existing_id = existing['id']
                    print(f'    Found existing by UUID: ID {existing_id}')
                    break
        except Exception as e:
            print(f'    Warning: Could not check for existing: {e}')
        
        # Step 2: Create or update
        if existing_id:
            success, result = update_event(base_url, headers, existing_id, event)
        else:
            success, result = create_event(base_url, headers, event)
        
        if success:
            if existing_id:
                stats['updated'] += 1
                print(f'    Updated: OK')
            else:
                stats['created'] += 1
                print(f'    Created: OK (ID: {result.get("id")})')
                # Update UUID meta for future deduplication
                if result.get('id'):
                    update_uuid_meta(base_url, headers, result['id'], event_uuid)
        else:
            stats['failed'] += 1
            error_msg = result.get('message', str(result)) if isinstance(result, dict) else str(result)
            print(f'    FAILED: {error_msg}')
            stats['errors'].append(f'{event["post"]["title"]}: {error_msg}')
        
        print()
        
        # Rate limiting - be polite
        time.sleep(1)
    
    # Summary
    print('=' * 60)
    print('IMPORT SUMMARY')
    print('=' * 60)
    print(f'Created:  {stats["created"]}')
    print(f'Updated:  {stats["updated"]}')
    print(f'Failed:   {stats["failed"]}')
    if stats['errors']:
        print('\nErrors:')
        for err in stats['errors']:
            print(f'  - {err}')
    
    sys.exit(0 if stats['failed'] == 0 else 1)


def fetch_all_events(base_url, headers):
    """Fetch all events with pagination."""
    all_events = []
    page = 1
    while True:
        url = f'{base_url}/wp-json/wp/v2/event?per_page=100&page={page}&_fields=ID,slug,title,meta'
        req = urllib.request.Request(url, headers=headers)
        try:
            with urllib.request.urlopen(req, timeout=20) as resp:
                data = json.loads(resp.read())
                if not isinstance(data, list):
                    break
                all_events.extend(data)
                if len(data) < 100:
                    break
                page += 1
                time.sleep(0.3)
        except Exception as e:
            print(f'    Warning: Error fetching page {page}: {e}')
            break
    return all_events


def create_event(base_url, headers, event):
    """Create a new event."""
    post_data = prepare_post_data(event)
    
    url = f'{base_url}/wp-json/wp/v2/event'
    req = urllib.request.Request(
        url,
        data=json.dumps(post_data).encode('utf-8'),
        headers=headers,
        method='POST'
    )
    
    try:
        with urllib.request.urlopen(req, timeout=30) as resp:
            result = json.loads(resp.read())
            if resp.status in (200, 201):
                return True, result
            return False, result
    except urllib.error.HTTPError as e:
        error_body = e.read().decode('utf-8', errors='replace')
        try:
            return False, json.loads(error_body)
        except:
            return False, {'message': error_body}
    except Exception as e:
        return False, {'message': str(e)}


def update_event(base_url, headers, event_id, event):
    """Update an existing event."""
    post_data = prepare_post_data(event)
    
    url = f'{base_url}/wp-json/wp/v2/event/{event_id}'
    req = urllib.request.Request(
        url,
        data=json.dumps(post_data).encode('utf-8'),
        headers=headers,
        method='POST'
    )
    
    try:
        with urllib.request.urlopen(req, timeout=30) as resp:
            result = json.loads(resp.read())
            if resp.status in (200, 201):
                return True, result
            return False, result
    except urllib.error.HTTPError as e:
        error_body = e.read().decode('utf-8', errors='replace')
        try:
            return False, json.loads(error_body)
        except:
            return False, {'message': error_body}
    except Exception as e:
        return False, {'message': str(e)}


def update_uuid_meta(base_url, headers, event_id, uuid):
    """Update the UUID meta for an event."""
    url = f'{base_url}/wp-json/wp/v2/event/{event_id}'
    data = {'_meta': {'_event_export_uuid': uuid}}
    
    req = urllib.request.Request(
        url,
        data=json.dumps(data).encode('utf-8'),
        headers=headers,
        method='POST'
    )
    
    try:
        with urllib.request.urlopen(req, timeout=15) as resp:
            if resp.status in (200, 201):
                print(f'    UUID meta updated')
                return True
    except Exception as e:
        print(f'    Warning: Could not update UUID meta: {e}')
    return False


def prepare_post_data(event):
    """Prepare post data for REST API."""
    post = event.get('post', {})
    meta = event.get('meta', {})
    
    # Extract rendered values
    title = post.get('title', '')
    if isinstance(title, dict):
        title = title.get('rendered', '')
    
    content = post.get('content', '')
    if isinstance(content, dict):
        content = content.get('rendered', '')
    
    excerpt = post.get('excerpt', '')
    if isinstance(excerpt, dict):
        excerpt = excerpt.get('rendered', '')
    
    slug = post.get('slug', '')
    if not slug:
        slug = title.lower().replace(' ', '-').replace('@', '-at-')[:200]
    
    date = post.get('date', datetime.now(timezone.utc).isoformat())
    status = post.get('status', 'publish')
    
    return {
        'title': title,
        'content': content,
        'excerpt': excerpt,
        'status': status,
        'slug': slug,
        'date': date,
        'type': 'event',
    }


if __name__ == '__main__':
    main()
