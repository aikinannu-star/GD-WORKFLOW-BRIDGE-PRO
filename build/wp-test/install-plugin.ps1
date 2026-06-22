$ComposeFile = 'build/wp-test/docker-compose.yml'
Write-Output "Starting WordPress test stack..."
docker compose -f $ComposeFile up -d

Write-Output "Waiting for WordPress at http://localhost:8080 ..."
for ($i = 0; $i -lt 60; $i++) {
    try {
        $r = Invoke-WebRequest -Uri http://localhost:8080 -UseBasicParsing -TimeoutSec 2 -ErrorAction Stop
        Write-Output "WordPress is up"
        break
    } catch {
        Start-Sleep -Seconds 2
    }
}

Write-Output "Installing plugin..."
docker compose -f $ComposeFile exec -T wordpress bash -lc "wp plugin install /tmp/release/gd-workflow-bridge-pro.zip --allow-root --activate --path=/var/www/html --url=http://localhost:8080"
Write-Output "Done. Visit http://localhost:8080 to view the site."
