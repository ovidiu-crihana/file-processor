Write-Host ""
Write-Host "FILE-PROCESSOR ENVIRONMENT CHECK"
Write-Host "-----------------------------------"

$projectPath = Split-Path -Parent $PSCommandPath
$envFile = Join-Path $projectPath ".env.local"
$phpPath = "php"

if (-not (Test-Path $envFile)) {
    Write-Host "❌ File .env.local non trovato in $projectPath"
    exit 1
}

Write-Host "`nAnalisi file .env.local"
$bytes = Get-Content -Encoding Byte $envFile -TotalCount 3
if ($bytes.Length -ge 3 -and $bytes[0] -eq 239 -and $bytes[1] -eq 187 -and $bytes[2] -eq 191) {
    Write-Host "⚠️  Il file contiene BOM UTF-8. Rimuovilo salvandolo come UTF-8 (senza BOM)."
} else {
    Write-Host "OK: Nessun BOM UTF-8 rilevato."
}

Write-Host "`nVariabili chiave dal file:"
Get-Content $envFile | Select-String -Pattern "SOURCE_BASE_PATH|OUTPUT_BASE_PATH|TAVOLE_BASE_PATH"

Write-Host "`nTest accesso share:"
$envVars = @{}
foreach ($line in Get-Content $envFile) {
    if ($line -match '^(SOURCE_BASE_PATH|OUTPUT_BASE_PATH|TAVOLE_BASE_PATH)=(.+)$') {
        $envVars[$matches[1]] = $matches[2].Trim('"').Replace('\\\\','\')
    }
}

foreach ($key in $envVars.Keys) {
    $p = $envVars[$key]
    if (Test-Path $p) {
        Write-Host "✅ $key accessibile → $p"
    } else {
        Write-Host "❌ $key non accessibile → $p"
    }
}

Write-Host "`nTest lettura da PHP (.env.local)"
$phpCode = @"
require 'vendor/autoload.php';
use Symfony\Component\Dotenv\Dotenv;
(new Dotenv())->loadEnv(__DIR__.'/.env.local');
foreach (['SOURCE_BASE_PATH','OUTPUT_BASE_PATH','TAVOLE_BASE_PATH'] as \$v) {
    echo \$v.'='.getenv(\$v).PHP_EOL;
}
"@
$tmpFile = Join-Path $projectPath "check-env-tmp.php"
Set-Content -Path $tmpFile -Value $phpCode -Encoding UTF8
& $phpPath $tmpFile
Remove-Item $tmpFile -ErrorAction SilentlyContinue

Write-Host "`nCheck completo terminato."
