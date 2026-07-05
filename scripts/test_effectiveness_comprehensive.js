#!/usr/bin/env node
const http = require('http');

http.get('http://127.0.0.1:8006/api/v1/intelligence-effectiveness', (res) => {
    let data = '';
    res.on('data', chunk => { data += chunk; });
    res.on('end', () => {
        try {
            const obj = JSON.parse(data);
            console.log('✓ Comprehensive Effectiveness Endpoint\n');
            console.log('Sections:', Object.keys(obj).filter(k => k !== 'computed_at').join(', '));
            console.log('\nSample metrics:');
            console.log('  MTTD avg:', obj.mttd.mttd_hours_avg, 'hours');
            console.log('  MTTR avg:', obj.mttr.mttr_hours_avg, 'hours');
            console.log('  Acceptance rate:', (obj.acceptance_rate.overall_acceptance_rate * 100).toFixed(1) + '%');
            console.log('  Accuracy (precision):', (obj.accuracy.precision * 100).toFixed(1) + '%');
            console.log('  Recommendation types tracked:', obj.recommendations.length);
            process.exit(0);
        } catch (e) {
            console.log('✗ Parse error:', e.message);
            process.exit(1);
        }
    });
}).on('error', (e) => {
    console.log('✗ HTTP error:', e.message);
    process.exit(1);
});
