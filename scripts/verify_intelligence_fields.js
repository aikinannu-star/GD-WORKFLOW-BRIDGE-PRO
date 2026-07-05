#!/usr/bin/env node
const http = require('http');

http.get('http://127.0.0.1:8006/api/v1/intelligence-health', (res) => {
    let data = '';
    res.on('data', chunk => { data += chunk; });
    res.on('end', () => {
        try {
            const obj = JSON.parse(data);
            const hasStatus = 'status' in obj;
            const hasFindings = 'findings' in obj && Array.isArray(obj.findings);
            const hasRecs = 'recommendations' in obj && Array.isArray(obj.recommendations);
            
            if (hasStatus && hasFindings && hasRecs) {
                console.log('✓ OK: All fields present');
                console.log('  Status:', obj.status);
                console.log('  Findings:', obj.findings.length, 'items');
                console.log('  Recommendations:', obj.recommendations.length, 'items');
                process.exit(0);
            } else {
                console.log('✗ FAIL: Missing fields');
                console.log('  status:', hasStatus, '| findings:', hasFindings, '| recommendations:', hasRecs);
                process.exit(1);
            }
        } catch (e) {
            console.log('✗ FAIL: Invalid JSON:', e.message);
            process.exit(2);
        }
    });
}).on('error', (e) => {
    console.log('✗ FAIL: HTTP error:', e.message);
    process.exit(2);
});
