#!/usr/bin/env node
const http = require('http');

http.get('http://127.0.0.1:8006/api/v1/intelligence-health', (res) => {
    let data = '';
    res.on('data', chunk => { data += chunk; });
    res.on('end', () => {
        try {
            const obj = JSON.parse(data);
            console.log('✓ Intelligence Health API');
            console.log('  Status:', obj.status);
            console.log('  Findings:', obj.findings.length);
            console.log('  Recommendations:', obj.recommendations.length);
        } catch (e) {
            console.log('✗ Parse error:', e.message);
        }
    });
});

setTimeout(() => {
    http.get('http://127.0.0.1:8006/api/v1/intelligence-effectiveness', (res) => {
        let data = '';
        res.on('data', chunk => { data += chunk; });
        res.on('end', () => {
            try {
                const obj = JSON.parse(data);
                console.log('\n✓ Effectiveness Metrics API');
                console.log('  MTTD:', obj.mttd.mttd_hours_avg, 'hours');
                console.log('  MTTR:', obj.mttr.mttr_hours_avg, 'hours');
                console.log('  Acceptance:', (obj.acceptance_rate.overall_acceptance_rate * 100).toFixed(0) + '%');
                console.log('  Accuracy:', (obj.accuracy.precision * 100).toFixed(0) + '%');
                process.exit(0);
            } catch (e) {
                console.log('✗ Parse error:', e.message);
                process.exit(1);
            }
        });
    });
}, 500);
