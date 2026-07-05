#!/usr/bin/env python3
"""
Add API maturity metadata to all operations.
Annotates every operation with lifecycle information.
"""

import yaml
from pathlib import Path
from datetime import datetime

# Maturity assignments by domain
MATURITY_MAP = {
    'Marketplace': {
        'level': 'stable',
        'since': '1.4.0',
        'owner': 'Marketplace Team',
        'breakingChangesAllowed': False,
    },
    'Platform': {
        'level': 'stable',
        'since': '1.2.0',
        'owner': 'Platform Team',
        'breakingChangesAllowed': False,
    },
    'Remediation': {
        'level': 'stable',
        'since': '1.3.0',
        'owner': 'Remediation Team',
        'breakingChangesAllowed': False,
    },
    'Health': {
        'level': 'stable',
        'since': '1.0.0',
        'owner': 'Health Team',
        'breakingChangesAllowed': False,
    },
    'Intelligence': {
        'level': 'beta',
        'since': '1.5.0-beta.1',
        'owner': 'Intelligence Team',
        'breakingChangesAllowed': True,
        'expectedStable': '1.6.0',
    },
    'Risk Zones': {
        'level': 'beta',
        'since': '1.4.5-beta.1',
        'owner': 'Risk Team',
        'breakingChangesAllowed': True,
        'expectedStable': '1.5.0',
    },
    'Drift Analysis': {
        'level': 'beta',
        'since': '1.5.0-beta.1',
        'owner': 'Analytics Team',
        'breakingChangesAllowed': True,
        'expectedStable': '1.6.0',
    },
    'Testing': {
        'level': 'experimental',
        'since': '1.0.0-test',
        'owner': 'Quality Assurance',
        'breakingChangesAllowed': True,
        'warning': 'For testing purposes only. May change or be removed without notice.',
    },
    'Untagged': {
        'level': 'experimental',
        'since': '1.0.0-unstable',
        'owner': 'Platform Team',
        'breakingChangesAllowed': True,
    },
}

def add_maturity_to_spec(spec, maturity_map):
    """Add maturity metadata to all operations in spec."""
    reviewed_date = datetime.now().strftime('%Y-%m-%d')
    
    for path, methods in spec.get('paths', {}).items():
        for method, operation in methods.items():
            if isinstance(operation, dict) and 'operationId' in operation:
                # Get domain from tags
                tags = operation.get('tags', ['Untagged'])
                domain = tags[0] if tags else 'Untagged'
                
                # Look up maturity config
                if domain in maturity_map:
                    config = maturity_map[domain]
                else:
                    config = maturity_map['Testing']  # Default to experimental
                
                # Add maturity metadata
                operation['x-maturity'] = config['level']
                operation['x-stabilitySince'] = config['since']
                operation['x-owner'] = config['owner']
                operation['x-reviewed'] = reviewed_date
                operation['x-breakingChangesAllowed'] = config['breakingChangesAllowed']
                
                # Add conditional fields
                if 'expectedStable' in config:
                    operation['x-expectedStable'] = config['expectedStable']
                
                if 'warning' in config:
                    operation['x-warning'] = config['warning']
                
                # Add feedback channel
                operation['x-feedback'] = 'https://github.com/gd-workflow-bridge-pro/api/issues'
    
    return spec

def process_modular_files(maturity_map):
    """Add maturity to all modular path files."""
    paths_dir = Path('openapi/paths')
    processed = 0
    
    for path_file in sorted(paths_dir.glob('*.yaml')):
        if path_file.name == 'index.yaml':
            continue
        
        try:
            with path_file.open('r', encoding='utf-8') as f:
                content = yaml.safe_load(f)
            
            # Add maturity to all operations in this file
            if content:
                # Each file has paths at top level
                for endpoint, methods in content.items():
                    for method, operation in methods.items():
                        if isinstance(operation, dict) and 'operationId' in operation:
                            domain = operation.get('tags', ['Untagged'])[0]
                            if domain in maturity_map:
                                config = maturity_map[domain]
                            else:
                                config = maturity_map['Testing']
                            
                            operation['x-maturity'] = config['level']
                            operation['x-stabilitySince'] = config['since']
                            operation['x-owner'] = config['owner']
                            operation['x-reviewed'] = datetime.now().strftime('%Y-%m-%d')
                            operation['x-breakingChangesAllowed'] = config['breakingChangesAllowed']
                            
                            if 'expectedStable' in config:
                                operation['x-expectedStable'] = config['expectedStable']
                            
                            if 'warning' in config:
                                operation['x-warning'] = config['warning']
                            
                            operation['x-feedback'] = 'https://github.com/gd-workflow-bridge-pro/api/issues'
                
                # Write back
                with path_file.open('w', encoding='utf-8') as f:
                    yaml.safe_dump(content, f, sort_keys=False, allow_unicode=True, default_flow_style=False)
                
                processed += 1
                print(f"✓ Updated {path_file.name}")
        
        except Exception as e:
            print(f"✗ Error processing {path_file.name}: {e}")
    
    return processed

def main():
    print("📋 Adding API Maturity Metadata to Operations\n")
    
    # Process modular files
    print("Updating modular path files...")
    processed = process_modular_files(MATURITY_MAP)
    print(f"\n✓ Processed {processed} domain files\n")
    
    # Rebuild root spec
    print("Rebuilding root OpenAPI spec with maturity metadata...")
    try:
        base_path = Path('openapi')
        root_spec = yaml.safe_load((base_path / 'openapi.yaml').read_text(encoding='utf-8'))
        root_spec['paths'] = yaml.safe_load((base_path / 'paths' / 'index.yaml').read_text(encoding='utf-8'))
        root_spec['components'] = {
            'schemas': yaml.safe_load((base_path / 'schemas' / 'index.yaml').read_text(encoding='utf-8')),
            'securitySchemes': yaml.safe_load((base_path / 'security' / 'securitySchemes.yaml').read_text(encoding='utf-8'))
        }
        (base_path / 'openapi.yaml').write_text(yaml.safe_dump(root_spec, sort_keys=False), encoding='utf-8')
        print("✓ Root spec rebuilt\n")
    except Exception as e:
        print(f"✗ Failed to rebuild: {e}\n")
        return False
    
    # Verify maturity coverage
    print("Verifying maturity coverage...")
    with Path('openapi/openapi.yaml').open('r') as f:
        spec = yaml.safe_load(f)
    
    by_maturity = {'stable': 0, 'beta': 0, 'experimental': 0, 'internal': 0}
    missing_maturity = []
    
    for path, methods in spec.get('paths', {}).items():
        for method, operation in methods.items():
            if isinstance(operation, dict) and 'operationId' in operation:
                maturity = operation.get('x-maturity', 'unknown')
                if maturity in by_maturity:
                    by_maturity[maturity] += 1
                else:
                    missing_maturity.append(f"{method.upper()} {path}")
    
    print(f"\n✓ Maturity Distribution:")
    print(f"  Stable:        {by_maturity['stable']:2d} operations")
    print(f"  Beta:          {by_maturity['beta']:2d} operations")
    print(f"  Experimental:  {by_maturity['experimental']:2d} operations")
    print(f"  Internal:      {by_maturity['internal']:2d} operations")
    
    if missing_maturity:
        print(f"\n✗ Missing maturity ({len(missing_maturity)}):")
        for op in missing_maturity[:5]:
            print(f"  - {op}")
        return False
    
    total = sum(by_maturity.values())
    print(f"\n✅ {total} operations annotated with maturity metadata")
    return True

if __name__ == '__main__':
    success = main()
    exit(0 if success else 1)
