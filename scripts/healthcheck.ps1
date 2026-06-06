param(
    [string] $Url = $env:VERTOAD_HEALTH_URL,
    [int] $TimeoutSec = 10
)

if ([string]::IsNullOrWhiteSpace($Url)) {
    $Url = "http://127.0.0.1:8080/api/v1/health"
}

try {
    $response = Invoke-WebRequest -Uri $Url -Method Get -TimeoutSec $TimeoutSec -UseBasicParsing

    if ($response.StatusCode -lt 200 -or $response.StatusCode -ge 300) {
        Write-Error "Healthcheck failed for $Url with HTTP $($response.StatusCode)."
        exit 1
    }

    Write-Output "Healthcheck passed for $Url with HTTP $($response.StatusCode)."
    exit 0
} catch {
    Write-Error "Healthcheck failed for $Url. $($_.Exception.Message)"
    exit 1
}
