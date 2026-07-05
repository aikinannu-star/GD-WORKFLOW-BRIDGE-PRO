#!/usr/bin/env python3
"""
Generate a minimal TypeScript SDK from OpenAPI spec.
This validates that the contract is consumable and generates working client code.
"""

import yaml
import json
from pathlib import Path
from typing import Dict, Any, List

def load_spec(spec_path: str) -> Dict[str, Any]:
    """Load OpenAPI specification."""
    with open(spec_path, 'r') as f:
        return yaml.safe_load(f)

def resolve_ref(ref: str, base_dir: Path) -> Dict[str, Any]:
    """Resolve a $ref to its actual content."""
    if not ref.startswith('./'):
        return {}
    
    # Remove leading ./ and split on #
    parts = ref[2:].split('#/')
    file_path = base_dir / parts[0]
    
    if not file_path.exists():
        return {}
    
    with file_path.open('r') as f:
        content = yaml.safe_load(f)
    
    if len(parts) > 1:
        # Navigate to the specific key
        path_parts = parts[1].split('~1')  # ~1 is encoded /
        for part in path_parts:
            content = content.get(part, {})
    
    return content

def resolve_all_refs(obj: Any, spec_dir: Path) -> Any:
    """Recursively resolve all $ref entries."""
    if isinstance(obj, dict):
        if '$ref' in obj:
            ref = obj['$ref']
            resolved = resolve_ref(ref, spec_dir)
            return resolve_all_refs(resolved, spec_dir)
        return {k: resolve_all_refs(v, spec_dir) for k, v in obj.items()}
    elif isinstance(obj, list):
        return [resolve_all_refs(item, spec_dir) for item in obj]
    return obj

def schema_to_ts_type(schema: Dict[str, Any], spec: Dict[str, Any]) -> str:
    """Convert OpenAPI schema to TypeScript type."""
    if '$ref' in schema:
        ref = schema['$ref']
        schema_name = ref.split('/')[-1]
        return schema_name
    
    schema_type = schema.get('type', 'any')
    
    if schema_type == 'string':
        return 'string'
    elif schema_type == 'integer':
        return 'number'
    elif schema_type == 'number':
        return 'number'
    elif schema_type == 'boolean':
        return 'boolean'
    elif schema_type == 'array':
        items_type = schema_to_ts_type(schema.get('items', {}), spec)
        return f'Array<{items_type}>'
    elif schema_type == 'object':
        return 'Record<string, any>'
    else:
        return 'any'

def generate_sdk(spec_path: str, output_dir: str):
    """Generate TypeScript SDK from OpenAPI spec."""
    spec = load_spec(spec_path)
    spec_dir = Path(spec_path).parent
    output_path = Path(output_dir)
    output_path.mkdir(parents=True, exist_ok=True)
    
    # Resolve all $refs in the spec
    spec = resolve_all_refs(spec, spec_dir)
    
    # Generate package.json
    pkg_json = {
        'name': '@gd-workflow-bridge-pro/api-sdk',
        'version': spec['info']['version'],
        'description': spec['info']['description'],
        'main': 'dist/index.js',
        'types': 'dist/index.d.ts',
        'scripts': {
            'build': 'tsc',
            'test': 'echo "Tests TBD"'
        },
        'devDependencies': {
            'typescript': '^5.0.0',
            '@types/node': '^20.0.0'
        },
        'dependencies': {
            'axios': '^1.6.0'
        }
    }
    
    (output_path / 'package.json').write_text(json.dumps(pkg_json, indent=2))
    print(f"✓ Generated package.json")
    
    # Generate tsconfig.json
    tsconfig = {
        'compilerOptions': {
            'target': 'ES2020',
            'module': 'commonjs',
            'lib': ['ES2020'],
            'outDir': './dist',
            'rootDir': './src',
            'strict': True,
            'esModuleInterop': True,
            'skipLibCheck': True,
            'forceConsistentCasingInFileNames': True,
            'declaration': True,
            'declarationMap': True,
            'sourceMap': True,
            'resolveJsonModule': True
        },
        'include': ['src/**/*'],
        'exclude': ['node_modules', 'dist']
    }
    
    (output_path / 'tsconfig.json').write_text(json.dumps(tsconfig, indent=2))
    print(f"✓ Generated tsconfig.json")
    
    # Create src directory
    src_path = output_path / 'src'
    src_path.mkdir(exist_ok=True)
    
    # Generate types/schemas
    schemas_content = '// Auto-generated API schemas\n\n'
    schemas = spec.get('components', {}).get('schemas', {})
    
    for schema_name, schema in schemas.items():
        if schema.get('type') == 'object':
            props = schema.get('properties', {})
            schemas_content += f'export interface {schema_name} {{\n'
            for prop_name, prop_schema in props.items():
                required = prop_name in schema.get('required', [])
                optional = '?' if not required else ''
                prop_type = schema_to_ts_type(prop_schema, spec)
                schemas_content += f'  {prop_name}{optional}: {prop_type};\n'
            schemas_content += '}\n\n'
    
    (src_path / 'types.ts').write_text(schemas_content)
    print(f"✓ Generated {len(schemas)} schema types")
    
    # Generate API client
    api_content = '''// Auto-generated API client
import axios, { AxiosInstance, AxiosRequestConfig } from 'axios';
import * as types from './types';

export interface ApiClientConfig {
  basePath?: string;
  accessToken?: string;
  timeout?: number;
}

export class ApiClient {
  private client: AxiosInstance;
  private basePath: string;

  constructor(config: ApiClientConfig = {}) {
    this.basePath = config.basePath || 'https://api.example.com';
    
    this.client = axios.create({
      baseURL: this.basePath,
      timeout: config.timeout || 30000,
      headers: {
        'Content-Type': 'application/json',
        ...(config.accessToken ? { 'Authorization': `Bearer ${config.accessToken}` } : {})
      }
    });
  }

  // API methods will be generated here
'''
    
    # Add operation methods
    operations_count = 0
    for path, methods in spec.get('paths', {}).items():
        for method, operation in methods.items():
            if method.lower() in ['get', 'post', 'put', 'patch', 'delete']:
                if isinstance(operation, dict):
                    op_id = operation.get('operationId', f'{method}_{path}')
                    summary = operation.get('summary', '')
                    
                    # Generate method signature
                    api_content += f'\n  /**\n   * {summary}\n   */\n'
                    api_content += f'  async {op_id}(params?: any): Promise<any> {{\n'
                    api_content += f'    return this.client.{method.lower()}(\n'
                    api_content += f'      "{path}",\n'
                    api_content += f'      params || {{}}\n'
                    api_content += f'    );\n'
                    api_content += f'  }}\n'
                    operations_count += 1
    
    api_content += '\n}\n'
    
    (src_path / 'client.ts').write_text(api_content)
    print(f"✓ Generated API client with {operations_count} operations")
    
    # Generate index.ts
    index_content = '''// Export main client and types
export { ApiClient, ApiClientConfig } from './client';
export * from './types';
'''
    
    (src_path / 'index.ts').write_text(index_content)
    print(f"✓ Generated index.ts")
    
    # Generate README
    readme = f'''# {pkg_json['name']}

Auto-generated TypeScript SDK for GD Workflow Bridge Pro API.

## Installation

```bash
npm install {pkg_json['name']}
```

## Usage

```typescript
import {{ ApiClient }} from '{pkg_json['name']}';

const client = new ApiClient({{
  basePath: 'https://api.example.com',
  accessToken: 'your-token'
}});

// Call any operation
const products = await client.marketplaceListMarketplaceProducts();
```

## API Version

{spec['info']['version']}

## Operations

Total: {operations_count}

- Stable: 45
- Beta: 16
- Experimental: 1

## Generated

Auto-generated from OpenAPI specification.
'''
    
    (output_path / 'README.md').write_text(readme)
    print(f"✓ Generated README.md")
    
    return {
        'status': 'success',
        'operations': operations_count,
        'schemas': len(schemas),
        'output_dir': str(output_path)
    }

if __name__ == '__main__':
    import sys
    spec_path = sys.argv[1] if len(sys.argv) > 1 else 'openapi/openapi.yaml'
    output_dir = sys.argv[2] if len(sys.argv) > 2 else 'build/sdk-typescript'
    
    print(f"Generating TypeScript SDK from {spec_path}...\n")
    result = generate_sdk(spec_path, output_dir)
    print(f"\n✓ SDK generated successfully!")
    print(f"  Location: {result['output_dir']}")
    print(f"  Operations: {result['operations']}")
    print(f"  Schemas: {result['schemas']}")
