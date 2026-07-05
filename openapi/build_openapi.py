from pathlib import Path
import yaml

base = Path(__file__).parent
root = yaml.safe_load(Path(base / 'openapi.yaml').read_text(encoding='utf-8'))
root['paths'] = yaml.safe_load((base / 'paths' / 'index.yaml').read_text(encoding='utf-8'))
root['components'] = {
    'schemas': yaml.safe_load((base / 'schemas' / 'index.yaml').read_text(encoding='utf-8')),
    'securitySchemes': yaml.safe_load((base / 'security' / 'securitySchemes.yaml').read_text(encoding='utf-8'))
}
Path(base / 'openapi.yaml').write_text(yaml.safe_dump(root, sort_keys=False), encoding='utf-8')
