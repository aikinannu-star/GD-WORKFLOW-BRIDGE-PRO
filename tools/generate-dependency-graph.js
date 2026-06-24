#!/usr/bin/env node
const fs = require('fs');
const path = require('path');

const versionsPath = path.join(__dirname, '..', 'services', 'data', 'marketplace_plugins_versions.json');
const pluginsPath = path.join(__dirname, '..', 'services', 'data', 'marketplace_plugins.json');
const outPath = path.join(__dirname, 'dep-graph.mmd');

function safeId(str) { return str.replace(/[^A-Za-z0-9_]/g, '_'); }

let versions = [];
if (fs.existsSync(versionsPath)) {
  try { versions = JSON.parse(fs.readFileSync(versionsPath, 'utf8') || '[]'); } catch (e) { console.error('Failed to parse versions file', e.message); process.exit(2); }
} else {
  console.error('No versions file found at', versionsPath);
  process.exit(1);
}

let plugins = [];
if (fs.existsSync(pluginsPath)) {
  try { plugins = JSON.parse(fs.readFileSync(pluginsPath, 'utf8') || '[]'); } catch (e) { /* ignore */ }
}
const pluginNameById = {};
for (const p of plugins) { if (p && p.id) pluginNameById[p.id] = p.name || p.id; }

let lines = [];
lines.push('graph TD;');

for (const v of versions) {
  const pid = v.plugin_id || v.plugin || v['plugin_id'];
  const ver = v.version || v['version'] || (v.manifest && v.manifest.version) || 'v';
  if (!pid) continue;
  const nodeId = safeId(`${pid}@${ver}`);
  const label = `${(pluginNameById[pid] || pid)}\\n${ver}`;
  lines.push(`${nodeId}(["${label}"]);`);
  const manifest = v.manifest || {};
  if (manifest && Array.isArray(manifest.dependencies)) {
    for (const dep of manifest.dependencies) {
      const depPid = dep.plugin_id || dep.plugin || dep.id;
      const depVer = dep.version || dep.range || 'latest';
      if (!depPid) continue;
      const depNode = safeId(`${depPid}@${depVer}`);
      lines.push(`${nodeId} --> ${depNode};`);
    }
  }
}

// output plain mermaid graph (no fenced code block)

fs.writeFileSync(outPath, lines.join('\n'));
console.log('Wrote dependency graph to', outPath);
