#!/usr/bin/env python3
"""
Breaking change detection for OpenAPI specifications.
Compares current spec against base (main/develop) branch to detect incompatibilities.

This becomes a merge gate: blocks PRs that introduce breaking changes without explicit approval.
"""

import yaml
import json
from pathlib import Path
from typing import Dict, List, Tuple, Set
from collections import defaultdict

class BreakingChangeDetector:
    """Detect breaking changes between OpenAPI specifications."""
    
    def __init__(self, current_spec: Dict, base_spec: Dict):
        self.current = current_spec
        self.base = base_spec
        self.breaking_changes = []
        self.warnings = []
        self.compatible = True
    
    def get_operations(self, spec: Dict) -> Dict[str, Dict]:
        """Extract all operations from spec with their full metadata."""
        ops = {}
        for path, methods in spec.get('paths', {}).items():
            for method, operation in methods.items():
                if isinstance(operation, dict) and 'operationId' in operation:
                    op_id = operation['operationId']
                    ops[op_id] = {
                        'method': method.upper(),
                        'path': path,
                        'operationId': op_id,
                        'tags': operation.get('tags', []),
                        'parameters': operation.get('parameters', []),
                        'requestBody': operation.get('requestBody', {}),
                        'responses': operation.get('responses', {}),
                        'x-maturity': operation.get('x-maturity', 'unknown'),
                    }
        return ops
    
    def detect_removed_operations(self):
        """Detect removed operations (always breaking for Stable)."""
        base_ops = self.get_operations(self.base)
        current_ops = self.get_operations(self.current)
        
        removed = set(base_ops.keys()) - set(current_ops.keys())
        
        for op_id in removed:
            op = base_ops[op_id]
            maturity = op.get('x-maturity', 'unknown')
            
            if maturity == 'stable':
                self.breaking_changes.append({
                    'type': 'REMOVED_STABLE_OPERATION',
                    'severity': 'critical',
                    'operationId': op_id,
                    'path': op['path'],
                    'message': f"Stable operation removed: {op_id} ({op['path']})",
                    'remediation': 'Deprecate first, then remove in major version bump'
                })
                self.compatible = False
            else:
                self.warnings.append({
                    'type': 'REMOVED_NON_STABLE_OPERATION',
                    'severity': 'warning',
                    'operationId': op_id,
                    'path': op['path'],
                    'message': f"Non-stable operation removed: {op_id} ({maturity})"
                })
    
    def detect_changed_operation_ids(self):
        """Detect changed operation IDs (always breaking)."""
        base_ops = self.get_operations(self.base)
        current_ops = self.get_operations(self.current)
        
        base_paths = {v['path']: k for k, v in base_ops.items()}
        current_paths = {v['path']: k for k, v in current_ops.items()}
        
        for path in set(base_paths.keys()) & set(current_paths.keys()):
            old_id = base_paths[path]
            new_id = current_paths[path]
            
            if old_id != new_id:
                op = base_ops[old_id]
                self.breaking_changes.append({
                    'type': 'CHANGED_OPERATION_ID',
                    'severity': 'critical',
                    'path': path,
                    'oldId': old_id,
                    'newId': new_id,
                    'message': f"Operation ID changed: {old_id} -> {new_id}",
                    'remediation': 'Operation IDs are immutable. Revert to original ID.'
                })
                self.compatible = False
    
    def detect_changed_methods(self):
        """Detect changed HTTP methods (always breaking)."""
        base_ops = self.get_operations(self.base)
        current_ops = self.get_operations(self.current)
        
        for op_id in set(base_ops.keys()) & set(current_ops.keys()):
            if base_ops[op_id]['method'] != current_ops[op_id]['method']:
                old_method = base_ops[op_id]['method']
                new_method = current_ops[op_id]['method']
                path = base_ops[op_id]['path']
                
                self.breaking_changes.append({
                    'type': 'CHANGED_HTTP_METHOD',
                    'severity': 'critical',
                    'operationId': op_id,
                    'path': path,
                    'oldMethod': old_method,
                    'newMethod': new_method,
                    'message': f"HTTP method changed: {old_method} -> {new_method}",
                    'remediation': 'HTTP methods are immutable. Revert change.'
                })
                self.compatible = False
    
    def detect_removed_required_parameters(self):
        """Detect removed required parameters (breaking for Stable)."""
        base_ops = self.get_operations(self.base)
        current_ops = self.get_operations(self.current)
        
        for op_id in set(base_ops.keys()) & set(current_ops.keys()):
            base_params = {p['name'] for p in base_ops[op_id].get('parameters', []) if p.get('required')}
            current_params = {p['name'] for p in current_ops[op_id].get('parameters', []) if p.get('required')}
            
            removed = base_params - current_params
            
            if removed:
                maturity = base_ops[op_id].get('x-maturity', 'unknown')
                severity = 'critical' if maturity == 'stable' else 'warning'
                
                change = {
                    'type': 'REMOVED_REQUIRED_PARAMETER',
                    'severity': severity,
                    'operationId': op_id,
                    'path': base_ops[op_id]['path'],
                    'parameters': list(removed),
                    'message': f"Required parameter(s) removed: {', '.join(removed)}",
                    'remediation': 'Make parameters optional or increment major version'
                }
                
                if severity == 'critical':
                    self.breaking_changes.append(change)
                    self.compatible = False
                else:
                    self.warnings.append(change)
    
    def detect_maturity_regressions(self):
        """Detect maturity downgrades (e.g., Stable -> Beta)."""
        base_ops = self.get_operations(self.base)
        current_ops = self.get_operations(self.current)
        
        maturity_order = {'stable': 3, 'beta': 2, 'experimental': 1, 'internal': 0}
        
        for op_id in set(base_ops.keys()) & set(current_ops.keys()):
            base_maturity = base_ops[op_id].get('x-maturity', 'unknown')
            current_maturity = current_ops[op_id].get('x-maturity', 'unknown')
            
            base_level = maturity_order.get(base_maturity, -1)
            current_level = maturity_order.get(current_maturity, -1)
            
            if base_level > current_level:
                self.breaking_changes.append({
                    'type': 'MATURITY_REGRESSION',
                    'severity': 'critical',
                    'operationId': op_id,
                    'oldMaturity': base_maturity,
                    'newMaturity': current_maturity,
                    'message': f"Maturity downgraded: {base_maturity} -> {current_maturity}",
                    'remediation': 'Maturity can only stay same or increase. Revert change.'
                })
                self.compatible = False
    
    def detect_all(self):
        """Run all breaking change detection."""
        self.detect_removed_operations()
        self.detect_changed_operation_ids()
        self.detect_changed_methods()
        self.detect_removed_required_parameters()
        self.detect_maturity_regressions()
    
    def report(self) -> Dict:
        """Generate report of breaking changes and warnings."""
        return {
            'compatible': self.compatible,
            'breaking_changes': self.breaking_changes,
            'warnings': self.warnings,
            'summary': {
                'breaking_count': len(self.breaking_changes),
                'warning_count': len(self.warnings),
                'status': 'PASS' if self.compatible else 'FAIL'
            }
        }

def load_specs_from_git(current_path: str) -> Tuple[Dict, Dict]:
    """Load current and base specs for comparison."""
    # In a real CI environment, this would fetch from git
    # For now, we load current and assume base is the same (first run)
    
    with open(current_path, 'r') as f:
        current = yaml.safe_load(f)
    
    # Try to load a .base.yaml file if it exists (for testing)
    base_path = current_path.replace('.yaml', '.base.yaml')
    if Path(base_path).exists():
        with open(base_path, 'r') as f:
            base = yaml.safe_load(f)
    else:
        base = current.copy()  # No changes on first run
    
    return current, base

def main():
    """Run breaking change detection."""
    import sys
    
    current_spec_path = sys.argv[1] if len(sys.argv) > 1 else 'openapi/openapi.yaml'
    
    print("Detecting breaking changes in OpenAPI specification...\n")
    
    try:
        current_spec, base_spec = load_specs_from_git(current_spec_path)
    except Exception as e:
        print(f"Error loading specs: {e}")
        return 1
    
    detector = BreakingChangeDetector(current_spec, base_spec)
    detector.detect_all()
    report = detector.report()
    
    # Print report
    print(f"Status: {report['summary']['status']}\n")
    
    if report['breaking_changes']:
        print(f"BREAKING CHANGES ({len(report['breaking_changes'])}):\n")
        for change in report['breaking_changes']:
            print(f"  [{change['severity'].upper()}] {change['type']}")
            print(f"    {change['message']}")
            print(f"    Remediation: {change['remediation']}")
            print()
    
    if report['warnings']:
        print(f"WARNINGS ({len(report['warnings'])}):\n")
        for warning in report['warnings']:
            print(f"  [{warning['severity'].upper()}] {warning['type']}")
            print(f"    {warning['message']}")
            print()
    
    if report['summary']['breaking_count'] == 0:
        print("✓ No breaking changes detected\n")
    else:
        print(f"✗ {report['summary']['breaking_count']} breaking change(s) detected\n")
        print("Merge blocked until breaking changes are resolved or major version is bumped.\n")
    
    # Return exit code: 0 if compatible, 1 if breaking changes
    return 0 if report['compatible'] else 1

if __name__ == '__main__':
    import sys
    sys.exit(main())
